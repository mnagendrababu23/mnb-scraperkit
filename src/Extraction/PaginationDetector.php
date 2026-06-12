<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Mnb\ScraperKit\Support\UrlNormalizer;

/**
 * Detects UI and API pagination controls from static HTML.
 *
 * The detector is intentionally heuristic-based and dependency-light so it can run
 * in source-zip, Composer, CLI, and web contexts without browser automation. It
 * reports evidence, confidence, and normalized links instead of pretending that
 * every pattern is a crawlable URL.
 */
final class PaginationDetector
{
    private UrlNormalizer $normalizer;

    public function __construct(?UrlNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new UrlNormalizer();
    }

    /** @return array<string,mixed> */
    public function detect(string $html, string $baseUrl = ''): array
    {
        if (!class_exists(\DOMDocument::class)) {
            return $this->detectFallback($html, $baseUrl);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $text = $this->cleanText($dom->textContent ?: '');
        $links = $this->links($xpath, $baseUrl);

        $patterns = [];
        $numbered = $this->numberedPagination($xpath, $baseUrl);
        if ($numbered !== null) {
            $patterns[] = $numbered;
        }
        foreach ($this->prevNextPatterns($xpath, $baseUrl, $links) as $pattern) {
            $patterns[] = $pattern;
        }
        $alphabetical = $this->alphabeticalPagination($xpath, $baseUrl);
        if ($alphabetical !== null) {
            $patterns[] = $alphabetical;
        }
        foreach ($this->formControls($xpath) as $pattern) {
            $patterns[] = $pattern;
        }
        foreach ($this->actionControls($xpath, $text, $html) as $pattern) {
            $patterns[] = $pattern;
        }
        foreach ($this->apiPagination($links, $html) as $pattern) {
            $patterns[] = $pattern;
        }
        foreach ($this->stepTabTimelinePatterns($xpath, $baseUrl) as $pattern) {
            $patterns[] = $pattern;
        }

        $patterns = $this->dedupePatterns($patterns);
        usort($patterns, static fn (array $a, array $b): int => ((float) ($b['confidence'] ?? 0.0) <=> (float) ($a['confidence'] ?? 0.0)) ?: strcmp((string) ($a['type'] ?? ''), (string) ($b['type'] ?? '')));

        $summary = $this->summary($patterns, $links);

        return [
            'pagination_detector_version' => '1.0.8',
            'base_url' => $baseUrl,
            'has_pagination' => $summary['has_pagination'],
            'primary_pattern' => $summary['primary_pattern'],
            'detected_types' => $summary['detected_types'],
            'summary' => $summary,
            'patterns' => $patterns,
            'links' => $this->paginationLinks($links),
            'api_styles' => $this->apiStyles($links, $html),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function links(DOMXPath $xpath, string $baseUrl): array
    {
        $rows = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            $url = $this->normalizePaginationHref($href, $baseUrl);
            if (!$url) {
                continue;
            }
            $text = $this->cleanText($node->textContent ?: '');
            $row = [
                'url' => $url,
                'href' => $href,
                'text' => $text,
                'rel' => strtolower(trim($node->getAttribute('rel'))),
                'aria_label' => $this->cleanText($node->getAttribute('aria-label')),
                'class' => trim($node->getAttribute('class')),
                'id' => trim($node->getAttribute('id')),
                'role' => strtolower(trim($node->getAttribute('role'))),
                'aria_current' => strtolower(trim($node->getAttribute('aria-current'))),
            ];
            $row['kind'] = $this->linkKind($row);
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function linkKind(array $row): string
    {
        $text = trim((string) ($row['text'] ?? ''));
        $rel = strtolower(trim((string) ($row['rel'] ?? '')));
        $aria = strtolower(trim((string) ($row['aria_label'] ?? '')));
        $classId = strtolower(trim((string) (($row['class'] ?? '') . ' ' . ($row['id'] ?? ''))));
        $label = strtolower(trim($text . ' ' . $aria . ' ' . $rel . ' ' . $classId));
        $url = strtolower((string) ($row['url'] ?? ''));
        $textLower = strtolower($text);

        // Strong UI navigation signals win over generic API/query patterns.
        if ($rel === 'first' || preg_match('~^(first|start|«|<<)$~iu', $text) || preg_match('~\b(first|start)\b~u', $aria . ' ' . $classId)) {
            return 'first';
        }
        if ($rel === 'last' || preg_match('~^(last|end|»|>>)$~iu', $text) || preg_match('~\b(last|end)\b~u', $aria . ' ' . $classId)) {
            return 'last';
        }
        if ($rel === 'prev' || $rel === 'previous' || preg_match('~^(prev|previous|older|back|‹|<|←)$~iu', $text) || preg_match('~\b(prev|previous|older|back)\b~u', $aria . ' ' . $classId)) {
            return 'previous';
        }
        if ($rel === 'next' || preg_match('~^(next|newer|forward|more|›|>|→)$~iu', $text) || preg_match('~\b(next|newer|forward|more)\b~u', $aria . ' ' . $classId)) {
            return 'next';
        }

        if (preg_match('~^(\d{1,6})$~', $text)) {
            return 'number';
        }
        if (preg_match('~^[A-Z]$~', $text)) {
            return 'alphabet';
        }

        // API pagination links often include words like "Cursor Next". Keep those
        // classified as API styles so summary.next_url stays focused on UI next links.
        if (preg_match('~[?&](cursor|after|before|nextcursor|next_cursor)=~i', $url)) {
            return 'cursor';
        }
        if (preg_match('~[?&](offset|start)=\d+\b~i', $url)) {
            return 'offset';
        }
        if (preg_match('~[?&](lastid|last_id|since_id|after_id)=~i', $url)) {
            return 'keyset';
        }
        if (preg_match('~[?&](token|nexttoken|next_token|page_token)=~i', $url)) {
            return 'token';
        }
        if (preg_match('~[?&](page|p)=\d+\b|/(page|p)/\d+\b|/\d+/?$~i', $url)) {
            return 'page_url';
        }

        // Fallback for compact controls where the symbol/label is mixed with other text.
        if (preg_match('~\b(prev|previous|older|back)\b|‹|<|←~u', $label)) {
            return 'previous';
        }
        if (preg_match('~\b(next|newer|forward|more)\b|›|>|→~u', $label)) {
            return 'next';
        }
        return 'link';
    }

    /** @return array<string,mixed>|null */
    private function numberedPagination(DOMXPath $xpath, string $baseUrl): ?array
    {
        $best = null;
        $containers = $xpath->query('//nav|//ul|//ol|//div|//section') ?: [];
        foreach ($containers as $container) {
            if (!$container instanceof DOMElement) {
                continue;
            }
            $local = new DOMXPath($container->ownerDocument);
            $numberLinks = [];
            $ellipsis = false;
            $currentPage = null;
            foreach ($local->query('.//a[@href]|.//span|.//button', $container) ?: [] as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $text = $this->cleanText($node->textContent ?: '');
                if ($text === '...' || $text === '…') {
                    $ellipsis = true;
                    continue;
                }
                if (!preg_match('~^\d{1,6}$~', $text)) {
                    continue;
                }
                $url = $node->hasAttribute('href') ? (string) ($this->normalizePaginationHref($node->getAttribute('href'), $baseUrl) ?? '') : '';
                $row = [
                    'page' => (int) $text,
                    'text' => $text,
                    'url' => $url,
                    'current' => $this->isCurrent($node),
                ];
                if ($row['current']) {
                    $currentPage = (int) $text;
                }
                $numberLinks[] = $row;
            }
            if (count($numberLinks) < 2) {
                continue;
            }
            $pages = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['page'], $numberLinks)));
            sort($pages);
            // Ellipsis + a page gap means the control is both numbered-ellipsis pagination
            // and a sliding-window pagination pattern. Keep the primary type as
            // numbered_ellipsis so callers looking for that exact technique do not miss it,
            // then expose sliding_window as a related detected type.
            $isSlidingWindow = $ellipsis && $this->isSlidingWindow($pages);
            $type = $ellipsis ? 'numbered_ellipsis' : 'numbered';
            $candidate = [
                'type' => $type,
                'confidence' => $ellipsis ? 0.93 : 0.9,
                'selector_hint' => $this->nodeSelectorHint($container),
                'current_page' => $currentPage,
                'pages' => $pages,
                'links' => array_slice($numberLinks, 0, 30),
                'has_ellipsis' => $ellipsis,
                'sliding_window' => $isSlidingWindow,
                'related_types' => $isSlidingWindow ? ['sliding_window'] : [],
                'evidence' => $isSlidingWindow
                    ? 'Detected numeric page controls with ellipsis and a page-number gap.'
                    : 'Detected multiple numeric page controls in one container.',
            ];
            if ($best === null || count($pages) > count((array) ($best['pages'] ?? []))) {
                $best = $candidate;
            }
        }
        return $best;
    }

    /** @param list<int> $pages */
    private function isSlidingWindow(array $pages): bool
    {
        if (count($pages) < 4) {
            return false;
        }
        $diffs = [];
        for ($i = 1; $i < count($pages); $i++) {
            $diffs[] = $pages[$i] - $pages[$i - 1];
        }
        return max($diffs) > 1;
    }

    /** @param list<array<string,mixed>> $links @return list<array<string,mixed>> */
    private function prevNextPatterns(DOMXPath $xpath, string $baseUrl, array $links): array
    {
        $byKind = ['previous' => [], 'next' => [], 'first' => [], 'last' => []];
        foreach ($links as $link) {
            $kind = (string) ($link['kind'] ?? '');
            if (isset($byKind[$kind])) {
                $byKind[$kind][] = $this->publicLink($link);
            }
        }

        $patterns = [];
        if ($byKind['previous'] !== [] || $byKind['next'] !== []) {
            $patterns[] = [
                'type' => 'previous_next',
                'confidence' => ($byKind['previous'] !== [] && $byKind['next'] !== []) ? 0.92 : 0.78,
                'previous' => $byKind['previous'][0] ?? null,
                'next' => $byKind['next'][0] ?? null,
                'evidence' => 'Detected previous/next links by rel, text, aria-label, or symbols.',
            ];
        }
        if ($byKind['first'] !== [] || $byKind['last'] !== []) {
            $patterns[] = [
                'type' => 'first_previous_next_last',
                'confidence' => 0.91,
                'first' => $byKind['first'][0] ?? null,
                'previous' => $byKind['previous'][0] ?? null,
                'next' => $byKind['next'][0] ?? null,
                'last' => $byKind['last'][0] ?? null,
                'evidence' => 'Detected first/last controls with optional previous/next controls.',
            ];
        }

        foreach ($xpath->query('//*[contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "page ") and contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " of ")]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $text = $this->cleanText($node->textContent ?: '');
            if (preg_match('~\bpage\s+(\d+)\s+of\s+(\d+)\b~i', $text, $m)) {
                $patterns[] = [
                    'type' => 'compact_mobile',
                    'confidence' => 0.86,
                    'current_page' => (int) $m[1],
                    'total_pages' => (int) $m[2],
                    'evidence' => $text,
                ];
                break;
            }
        }

        return $patterns;
    }

    /** @return array<string,mixed>|null */
    private function alphabeticalPagination(DOMXPath $xpath, string $baseUrl): ?array
    {
        $best = null;
        foreach ($xpath->query('//nav|//ul|//ol|//div|//section') ?: [] as $container) {
            if (!$container instanceof DOMElement) {
                continue;
            }
            $local = new DOMXPath($container->ownerDocument);
            $letters = [];
            foreach ($local->query('.//a[@href]|.//span', $container) ?: [] as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $text = $this->cleanText($node->textContent ?: '');
                if (!preg_match('~^[A-Z]$|^#$~', $text)) {
                    continue;
                }
                $url = $node->hasAttribute('href') ? (string) ($this->normalizePaginationHref($node->getAttribute('href'), $baseUrl) ?? '') : '';
                $letters[$text] = ['label' => $text, 'url' => $url, 'current' => $this->isCurrent($node) || !$node->hasAttribute('href')];
            }
            if (count($letters) < 5) {
                continue;
            }
            $candidate = [
                'type' => 'alphabetical',
                'confidence' => count($letters) >= 20 ? 0.96 : 0.88,
                'selector_hint' => $this->nodeSelectorHint($container),
                'letters_total' => count($letters),
                'letters' => array_values($letters),
                'evidence' => 'Detected A-Z/# directory pagination controls.',
            ];
            if ($best === null || count($letters) > (int) ($best['letters_total'] ?? 0)) {
                $best = $candidate;
            }
        }
        return $best;
    }

    /** @return list<array<string,mixed>> */
    private function formControls(DOMXPath $xpath): array
    {
        $patterns = [];
        foreach ($xpath->query('//select') ?: [] as $select) {
            if (!$select instanceof DOMElement) {
                continue;
            }
            $attrs = strtolower($select->getAttribute('name') . ' ' . $select->getAttribute('id') . ' ' . $select->getAttribute('class') . ' ' . $select->getAttribute('aria-label'));
            $options = [];
            foreach ((new DOMXPath($select->ownerDocument))->query('.//option', $select) ?: [] as $option) {
                if ($option instanceof DOMElement) {
                    $options[] = ['value' => $option->getAttribute('value'), 'text' => $this->cleanText($option->textContent ?: '')];
                }
            }
            if (preg_match('~\b(page|pager|pagination)\b~', $attrs)) {
                $patterns[] = ['type' => 'dropdown_page_selector', 'confidence' => 0.88, 'selector_hint' => $this->nodeSelectorHint($select), 'options' => array_slice($options, 0, 50), 'evidence' => 'Detected select control with page-related attributes.'];
            }
            if (preg_match('~\b(per[_-]?page|page[_-]?size|rows|limit|size)\b~', $attrs)) {
                $patterns[] = ['type' => 'page_size', 'confidence' => 0.87, 'selector_hint' => $this->nodeSelectorHint($select), 'options' => array_slice($options, 0, 50), 'evidence' => 'Detected rows/page-size selector.'];
            }
        }

        foreach ($xpath->query('//input|//button') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $attrs = strtolower($node->getAttribute('name') . ' ' . $node->getAttribute('id') . ' ' . $node->getAttribute('class') . ' ' . $node->getAttribute('placeholder') . ' ' . $node->getAttribute('aria-label') . ' ' . $this->cleanText($node->textContent ?: ''));
            if (preg_match('~\b(go\s*to\s*page|page\s*number|page)\b~', $attrs) && preg_match('~input|page|go~', $attrs . ' ' . strtolower($node->tagName))) {
                $patterns[] = ['type' => 'go_to_page_input', 'confidence' => 0.82, 'selector_hint' => $this->nodeSelectorHint($node), 'evidence' => 'Detected page number/go-to-page input control.'];
                break;
            }
        }

        return $patterns;
    }

    /** @return list<array<string,mixed>> */
    private function actionControls(DOMXPath $xpath, string $text, string $html): array
    {
        $patterns = [];
        foreach ($xpath->query('//button|//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $label = strtolower($this->cleanText(($node->textContent ?: '') . ' ' . $node->getAttribute('aria-label') . ' ' . $node->getAttribute('class') . ' ' . $node->getAttribute('id')));
            if (preg_match('~\b(load\s+more|show\s+more|view\s+more|more\s+results)\b~', $label)) {
                $patterns[] = ['type' => 'load_more', 'confidence' => 0.9, 'selector_hint' => $this->nodeSelectorHint($node), 'text' => $this->cleanText($node->textContent ?: $node->getAttribute('aria-label')), 'evidence' => 'Detected load-more style control.'];
                break;
            }
        }

        $lower = strtolower($text . "\n" . $html);
        if (preg_match('~\b(infinite\s+scroll|intersectionobserver|onscroll|data-next-cursor|nextcursor|load\s+more\s+on\s+scroll)\b~', $lower)) {
            $patterns[] = ['type' => 'infinite_scroll', 'confidence' => 0.78, 'evidence' => 'Detected infinite-scroll related text or script signals.'];
        }
        return $patterns;
    }

    /** @param list<array<string,mixed>> $links @return list<array<string,mixed>> */
    private function apiPagination(array $links, string $html): array
    {
        $styles = $this->apiStyles($links, $html);
        $patterns = [];
        foreach ($styles as $style => $evidence) {
            if ($evidence === []) {
                continue;
            }
            $patterns[] = [
                'type' => 'api_' . $style,
                'confidence' => 0.86,
                'evidence' => array_slice($evidence, 0, 5),
            ];
        }
        return $patterns;
    }

    /** @return list<array<string,mixed>> */
    private function stepTabTimelinePatterns(DOMXPath $xpath, string $baseUrl): array
    {
        $patterns = [];
        foreach ($xpath->query('//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "step") or contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "step") or @role="progressbar"]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $text = $this->cleanText($node->textContent ?: '');
            if (preg_match('~\bstep\s*\d+\b|personal info|address|payment|confirmation~i', $text . ' ' . $node->getAttribute('class') . ' ' . $node->getAttribute('id'))) {
                $patterns[] = ['type' => 'step_wizard', 'confidence' => 0.78, 'selector_hint' => $this->nodeSelectorHint($node), 'evidence' => substr($text, 0, 160)];
                break;
            }
        }
        foreach ($xpath->query('//*[@role="tablist"]|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "tabs")]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $items = [];
                foreach ((new DOMXPath($node->ownerDocument))->query('.//*[@role="tab"]|.//a[@href]|.//button', $node) ?: [] as $item) {
                    if ($item instanceof DOMElement) {
                        $url = $item->hasAttribute('href') ? (string) ($this->normalizePaginationHref($item->getAttribute('href'), $baseUrl) ?? '') : '';
                        $label = $this->cleanText($item->textContent ?: '');
                        if ($label !== '') {
                            $items[] = ['label' => $label, 'url' => $url];
                        }
                    }
                }
                if (count($items) >= 2) {
                    $patterns[] = ['type' => 'tab_pagination', 'confidence' => 0.8, 'selector_hint' => $this->nodeSelectorHint($node), 'tabs' => array_slice($items, 0, 20), 'evidence' => 'Detected tab list that can switch paged datasets.'];
                    break;
                }
            }
        }
        foreach ($xpath->query('//a[@href]|//button') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $label = strtolower($this->cleanText($node->textContent ?: $node->getAttribute('aria-label')));
            if (preg_match('~\b(today|yesterday|last week|older|previous month|next month|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b~', $label)) {
                $patterns[] = ['type' => preg_match('~month|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec~', $label) ? 'date_based' : 'timeline', 'confidence' => 0.72, 'selector_hint' => $this->nodeSelectorHint($node), 'text' => $this->cleanText($node->textContent ?: ''), 'evidence' => 'Detected date/timeline pagination label.'];
                break;
            }
        }
        return $patterns;
    }

    /** @param list<array<string,mixed>> $links @return array<string,list<string>> */
    private function apiStyles(array $links, string $html): array
    {
        $styles = [
            'page_number' => [],
            'offset' => [],
            'cursor' => [],
            'keyset' => [],
            'token' => [],
        ];
        foreach ($links as $link) {
            $url = (string) ($link['url'] ?? '');
            if (preg_match('~[?&](page|p)=\d+\b~i', $url)) {
                $styles['page_number'][] = $url;
            }
            if (preg_match('~[?&](offset|start)=\d+\b|[?&]limit=\d+\b~i', $url)) {
                $styles['offset'][] = $url;
            }
            if (preg_match('~[?&](cursor|after|before|nextcursor|next_cursor)=~i', $url)) {
                $styles['cursor'][] = $url;
            }
            if (preg_match('~[?&](lastid|last_id|since_id|after_id)=~i', $url)) {
                $styles['keyset'][] = $url;
            }
            if (preg_match('~[?&](token|nexttoken|next_token|page_token)=~i', $url)) {
                $styles['token'][] = $url;
            }
        }
        if (preg_match_all('~\b(?:page|offset|cursor|lastId|last_id|nextToken|next_token|page_token)\s*[:=]\s*["\']?([A-Za-z0-9._:-]+)~i', $html, $m)) {
            foreach ($m[0] ?? [] as $signal) {
                $lower = strtolower((string) $signal);
                if (str_contains($lower, 'cursor')) {
                    $styles['cursor'][] = (string) $signal;
                } elseif (str_contains($lower, 'token')) {
                    $styles['token'][] = (string) $signal;
                } elseif (str_contains($lower, 'lastid') || str_contains($lower, 'last_id')) {
                    $styles['keyset'][] = (string) $signal;
                } elseif (str_contains($lower, 'offset')) {
                    $styles['offset'][] = (string) $signal;
                } else {
                    $styles['page_number'][] = (string) $signal;
                }
            }
        }
        return array_map(static fn (array $rows): array => array_values(array_unique(array_slice($rows, 0, 20))), $styles);
    }

    /** @param list<array<string,mixed>> $links @return list<array<string,mixed>> */
    private function paginationLinks(array $links): array
    {
        $paginationKinds = ['number', 'page_url', 'alphabet', 'previous', 'next', 'first', 'last', 'cursor', 'offset', 'keyset', 'token'];
        $rows = [];
        foreach ($links as $link) {
            if (in_array((string) ($link['kind'] ?? ''), $paginationKinds, true)) {
                $rows[] = $this->publicLink($link);
            }
        }
        return array_values($rows);
    }

    /** @param array<string,mixed> $link @return array<string,mixed> */
    private function publicLink(array $link): array
    {
        return [
            'type' => $link['kind'] ?? 'link',
            'text' => $link['text'] ?? '',
            'url' => $link['url'] ?? '',
            'rel' => $link['rel'] ?? '',
            'aria_label' => $link['aria_label'] ?? '',
            'current' => (($link['aria_current'] ?? '') === 'page') || preg_match('~\b(active|current|selected)\b~i', (string) (($link['class'] ?? '') . ' ' . ($link['id'] ?? ''))) === 1,
        ];
    }

    private function isCurrent(DOMElement $node): bool
    {
        $attrs = strtolower($node->getAttribute('aria-current') . ' ' . $node->getAttribute('class') . ' ' . $node->getAttribute('id'));
        return str_contains($attrs, 'page') || preg_match('~\b(active|current|selected)\b~', $attrs) === 1;
    }

    /** @param list<array<string,mixed>> $patterns @return list<array<string,mixed>> */
    private function dedupePatterns(array $patterns): array
    {
        $out = [];
        foreach ($patterns as $pattern) {
            $key = (string) ($pattern['type'] ?? 'unknown') . '|' . md5(json_encode($pattern['evidence'] ?? $pattern['selector_hint'] ?? '') ?: '');
            $out[$key] = $pattern;
        }
        return array_values($out);
    }

    /** @param list<array<string,mixed>> $patterns @param list<array<string,mixed>> $links @return array<string,mixed> */
    private function summary(array $patterns, array $links): array
    {
        $types = [];
        foreach ($patterns as $pattern) {
            $types[] = (string) ($pattern['type'] ?? 'unknown');
            foreach ((array) ($pattern['related_types'] ?? []) as $relatedType) {
                if (is_string($relatedType) && $relatedType !== '') {
                    $types[] = $relatedType;
                }
            }
        }
        $types = array_values(array_unique($types));
        $primary = $patterns[0]['type'] ?? null;
        $numbers = [];
        foreach ($links as $link) {
            if (($link['kind'] ?? '') === 'number' && preg_match('~^\d+$~', (string) ($link['text'] ?? ''))) {
                $numbers[] = (int) $link['text'];
            }
        }
        $numbers = array_values(array_unique($numbers));
        sort($numbers);
        return [
            'has_pagination' => $patterns !== [],
            'primary_pattern' => $primary,
            'detected_types' => $types,
            'patterns_total' => count($patterns),
            'pagination_links_total' => count($this->paginationLinks($links)),
            'page_numbers' => $numbers,
            'page_numbers_total' => count($numbers),
            'next_url' => $this->firstUrlByKind($links, 'next'),
            'previous_url' => $this->firstUrlByKind($links, 'previous'),
            'first_url' => $this->firstUrlByKind($links, 'first'),
            'last_url' => $this->firstUrlByKind($links, 'last'),
            'note' => 'Detection is static-HTML based. Infinite scroll and JS-only pagination are reported as signals, not executed.',
        ];
    }

    /** @param list<array<string,mixed>> $links */
    private function firstUrlByKind(array $links, string $kind): ?string
    {
        foreach ($links as $link) {
            if (($link['kind'] ?? '') === $kind && !empty($link['url'])) {
                return (string) $link['url'];
            }
        }
        return null;
    }

    private function nodeSelectorHint(DOMElement $node): string
    {
        $tag = strtolower($node->tagName);
        $id = trim($node->getAttribute('id'));
        if ($id !== '') {
            return $tag . '#' . $id;
        }
        $class = trim($node->getAttribute('class'));
        if ($class !== '') {
            $first = preg_split('/\s+/', $class)[0] ?? '';
            if ($first !== '') {
                return $tag . '.' . $first;
            }
        }
        $role = trim($node->getAttribute('role'));
        if ($role !== '') {
            return $tag . '[role="' . $role . '"]';
        }
        return $tag;
    }

    private function normalizePaginationHref(string $href, string $baseUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') {
            return null;
        }

        // Keep query-only pagination links deterministic for CLI/web tests and reports.
        // UrlNormalizer resolves ?page=4 relative to the base directory. For pagination
        // summaries users usually expect a canonical origin-level URL from a base page
        // such as https://example.com/list => https://example.com/?page=4.
        if ($baseUrl !== '' && str_starts_with($href, '?')) {
            $parts = parse_url($baseUrl);
            if (isset($parts['scheme'], $parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return $this->normalizer->normalize($parts['scheme'] . '://' . $parts['host'] . $port . '/' . $href, null);
            }
        }

        return $this->normalizer->normalize($href, $baseUrl);
    }

    /** @return array<string,mixed> */
    private function detectFallback(string $html, string $baseUrl): array
    {
        preg_match_all('~<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER);
        $links = [];
        foreach ($matches as $match) {
            $url = $this->normalizePaginationHref((string) $match[1], $baseUrl);
            if (!$url) {
                continue;
            }
            $text = $this->cleanText(strip_tags((string) $match[2]));
            $row = ['url' => $url, 'href' => (string) $match[1], 'text' => $text, 'rel' => '', 'aria_label' => '', 'class' => '', 'id' => '', 'role' => '', 'aria_current' => ''];
            $row['kind'] = $this->linkKind($row);
            $links[] = $row;
        }
        $patterns = [];
        $numberCount = 0;
        $letterCount = 0;
        foreach ($links as $link) {
            $numberCount += (($link['kind'] ?? '') === 'number' || ($link['kind'] ?? '') === 'page_url') ? 1 : 0;
            $letterCount += (($link['kind'] ?? '') === 'alphabet') ? 1 : 0;
        }
        if ($numberCount >= 2) {
            $patterns[] = ['type' => str_contains($html, '...') || str_contains($html, '…') ? 'numbered_ellipsis' : 'numbered', 'confidence' => 0.72, 'evidence' => 'Regex fallback detected numeric page links.'];
        }
        $hasPrevious = false;
        $hasNext = false;
        foreach ($links as $link) {
            $hasPrevious = $hasPrevious || (($link['kind'] ?? '') === 'previous');
            $hasNext = $hasNext || (($link['kind'] ?? '') === 'next');
        }
        if ($hasPrevious || $hasNext) {
            $patterns[] = ['type' => 'previous_next', 'confidence' => ($hasPrevious && $hasNext) ? 0.78 : 0.7, 'evidence' => 'Regex fallback detected previous/next link text or symbols.'];
        }
        if ($letterCount >= 5) {
            $patterns[] = ['type' => 'alphabetical', 'confidence' => 0.72, 'evidence' => 'Regex fallback detected A-Z links.'];
        }
        if (preg_match('~<(select)[^>]+(?:name|id|class|aria-label)=["\'][^"\']*(?:page|pager|pagination)[^"\']*["\'][^>]*>~i', $html)) {
            $patterns[] = ['type' => 'dropdown_page_selector', 'confidence' => 0.7, 'evidence' => 'Regex fallback detected page select control.'];
        }
        if (preg_match('~<(select)[^>]+(?:name|id|class|aria-label)=["\'][^"\']*(?:per[_-]?page|page[_-]?size|rows|limit)[^"\']*["\'][^>]*>~i', $html)) {
            $patterns[] = ['type' => 'page_size', 'confidence' => 0.7, 'evidence' => 'Regex fallback detected page-size select control.'];
        }
        if (preg_match('~<input[^>]+(?:name|id|class|placeholder|aria-label)=["\'][^"\']*(?:go\s*to\s*page|page[_ -]?number|page)[^"\']*["\'][^>]*>~i', $html)) {
            $patterns[] = ['type' => 'go_to_page_input', 'confidence' => 0.68, 'evidence' => 'Regex fallback detected go-to-page input.'];
        }
        if (preg_match('~load\s+more|show\s+more|view\s+more~i', $html)) {
            $patterns[] = ['type' => 'load_more', 'confidence' => 0.7, 'evidence' => 'Regex fallback detected load-more text.'];
        }
        if (preg_match('~infinite\s+scroll|intersectionobserver|onscroll|nextcursor|data-next-cursor~i', $html)) {
            $patterns[] = ['type' => 'infinite_scroll', 'confidence' => 0.66, 'evidence' => 'Regex fallback detected infinite-scroll signal.'];
        }
        foreach ($this->apiStyles($links, $html) as $style => $evidence) {
            if ($evidence !== []) {
                $patterns[] = ['type' => 'api_' . $style, 'confidence' => 0.7, 'evidence' => array_slice($evidence, 0, 5)];
            }
        }
        $summary = $this->summary($patterns, $links);
        return [
            'pagination_detector_version' => '1.0.8',
            'base_url' => $baseUrl,
            'fallback' => 'regex_dom_extension_missing',
            'has_pagination' => $summary['has_pagination'],
            'primary_pattern' => $summary['primary_pattern'],
            'detected_types' => $summary['detected_types'],
            'summary' => $summary,
            'patterns' => $patterns,
            'links' => $this->paginationLinks($links),
            'api_styles' => $this->apiStyles($links, $html),
        ];
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\R+/u', ' ', $value) ?? $value;
        return trim($value);
    }
}
