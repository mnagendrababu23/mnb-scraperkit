<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Publisher;

/**
 * Extracts public article/chapter metadata from saved HTML using publisher rules.
 * This class is deliberately offline-friendly for QA: pass saved HTML, not a URL.
 */
final class PublisherArticleExtractor
{
    /** @param array<string,mixed> $publisher */
    public function __construct(private array $publisher)
    {
    }

    /** @return array<string,mixed> */
    public function extractFromHtml(string $html, ?string $url = null): array
    {
        if (!class_exists('DOMDocument')) {
            return $this->extractFromHtmlFallback($html, $url);
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $rules = $this->rules();
        $record = [
            'publisher' => (string) ($this->publisher['publisher'] ?? ''),
            'source' => (string) ($this->publisher['id'] ?? 'publisher'),
            'source_type' => 'publisher_article_html',
            'url' => $url,
        ];

        foreach ($rules as $field => $selectors) {
            $selectors = array_values(array_map('strval', (array) $selectors));
            if (in_array($field, ['authors', 'keywords', 'references'], true)) {
                $values = $this->extractList($xpath, $selectors);
                if ($values !== []) {
                    $record[$field] = $values;
                }
                continue;
            }
            if ($field === 'author_details') {
                $details = $this->extractAuthorDetails($xpath);
                if ($details !== []) {
                    $record[$field] = $details;
                }
                continue;
            }
            $value = $this->extractFirst($xpath, $selectors);
            if ($value !== null) {
                $record[$field] = $value;
            }
        }

        // Publisher-specific selector maps often omit an explicit `author_details`
        // field because author details are assembled from several common sources:
        // citation_author, citation_author_institution, mailto links, and visible
        // affiliation/email blocks. Run this enrichment unconditionally so configs
        // that only define normal article selectors still produce enterprise-ready
        // author detail rows.
        if (empty($record['author_details'])) {
            $details = $this->extractAuthorDetails($xpath);
            if ($details !== []) {
                $record['author_details'] = $details;
            }
        }

        if (empty($record['doi'])) {
            $text = $dom->textContent ?: '';
            if (preg_match('~10\.[0-9]{4,9}/[-._;()/:A-Z0-9]+~i', $text, $m) === 1) {
                $record['doi'] = $m[0];
            }
        }

        return (new ArticleMetadataNormalizer())->normalize($record, (string) ($this->publisher['publisher'] ?? '')) + array_intersect_key($record, ['author_details' => true, 'references' => true, 'keywords' => true]);
    }


    /** @return array<string,mixed> */
    private function extractFromHtmlFallback(string $html, ?string $url = null): array
    {
        $record = [
            'publisher' => (string) ($this->publisher['publisher'] ?? ''),
            'source' => (string) ($this->publisher['id'] ?? 'publisher'),
            'source_type' => 'publisher_article_html_fallback',
            'url' => $url,
            'title' => $this->meta($html, 'citation_title') ?: $this->tag($html, 'h1') ?: $this->tag($html, 'title'),
            'doi' => $this->meta($html, 'citation_doi') ?: $this->firstRegex($html, '~10\.[0-9]{4,9}/[-._;()/:A-Z0-9]+~i'),
            'published_at' => $this->meta($html, 'citation_publication_date'),
            'journal' => $this->meta($html, 'citation_journal_title'),
            'volume' => $this->meta($html, 'citation_volume'),
            'issue' => $this->meta($html, 'citation_issue'),
            'authors' => $this->allMeta($html, 'citation_author'),
            'abstract' => $this->byId($html, 'Abs1') ?: $this->byClass($html, 'abstract'),
            'keywords' => $this->listByContainerClass($html, 'keywords'),
            'references' => $this->listByContainerClass($html, 'references'),
        ];
        $affiliations = $this->allMeta($html, 'citation_author_institution');
        $emails = $this->mailto($html);
        if (($record['authors'] ?? []) !== [] || $affiliations !== [] || $emails !== []) {
            $details = [];
            $max = max(count((array) $record['authors']), count($affiliations), count($emails));
            for ($i = 0; $i < $max; $i++) {
                $details[] = array_filter([
                    'author_name' => ((array) $record['authors'])[$i] ?? null,
                    'affiliation' => $affiliations[$i] ?? ($affiliations[0] ?? null),
                    'email' => $emails[$i] ?? null,
                ], static fn ($v): bool => is_string($v) && trim($v) !== '');
            }
            $record['author_details'] = array_values(array_filter($details));
        }
        return (new ArticleMetadataNormalizer())->normalize($record, (string) ($this->publisher['publisher'] ?? '')) + array_intersect_key($record, ['author_details' => true, 'references' => true, 'keywords' => true]);
    }

    private function meta(string $html, string $name): ?string
    {
        $pattern = "~<meta[^>]+name=[\"']" . preg_quote($name, '~') . "[\"'][^>]+content=[\"']([^\"']+)[\"'][^>]*>~i";
        if (preg_match($pattern, $html, $m) === 1) {
            return $this->clean($m[1]);
        }
        return null;
    }

    /** @return list<string> */
    private function allMeta(string $html, string $name): array
    {
        $pattern = "~<meta[^>]+name=[\"']" . preg_quote($name, '~') . "[\"'][^>]+content=[\"']([^\"']+)[\"'][^>]*>~i";
        preg_match_all($pattern, $html, $m);
        return array_values(array_filter(array_map(fn (string $v): string => $this->clean($v), $m[1] ?? [])));
    }

    private function tag(string $html, string $tag): ?string
    {
        if (preg_match('~<' . preg_quote($tag, '~') . '[^>]*>(.*?)</' . preg_quote($tag, '~') . '>~is', $html, $m) === 1) {
            return $this->clean(strip_tags($m[1]));
        }
        return null;
    }

    private function byId(string $html, string $id): ?string
    {
        $pattern = "~<([a-z0-9]+)[^>]+id=[\"']" . preg_quote($id, '~') . "[\"'][^>]*>(.*?)</\\1>~is";
        if (preg_match($pattern, $html, $m) === 1) {
            return $this->clean(strip_tags($m[2]));
        }
        return null;
    }

    private function byClass(string $html, string $class): ?string
    {
        $pattern = "~<([a-z0-9]+)[^>]+class=[\"'][^\"']*" . preg_quote($class, '~') . "[^\"']*[\"'][^>]*>(.*?)</\\1>~is";
        if (preg_match($pattern, $html, $m) === 1) {
            return $this->clean(strip_tags($m[2]));
        }
        return null;
    }

    /** @return list<string> */
    private function listByContainerClass(string $html, string $class): array
    {
        $pattern = "~<([a-z0-9]+)[^>]+class=[\"'][^\"']*" . preg_quote($class, '~') . "[^\"']*[\"'][^>]*>(.*?)</\\1>~is";
        if (preg_match($pattern, $html, $m) !== 1) {
            return [];
        }
        preg_match_all('~<li[^>]*>(.*?)</li>~is', $m[2], $items);
        return array_values(array_filter(array_map(fn (string $v): string => $this->clean(strip_tags($v)), $items[1] ?? [])));
    }

    /** @return list<string> */
    private function mailto(string $html): array
    {
        preg_match_all("~href=[\"']mailto:([^\"']+)[\"']~i", $html, $m);
        return array_values(array_filter(array_map(fn (string $v): string => $this->clean($v), $m[1] ?? [])));
    }

    private function firstRegex(string $text, string $regex): ?string
    {
        return preg_match($regex, $text, $m) === 1 ? $this->clean($m[0]) : null;
    }

    /** @return array<string,list<string>> */
    private function rules(): array
    {
        $rules = $this->publisher['article_selectors'] ?? null;
        if (is_array($rules)) {
            /** @var array<string,list<string>> $rules */
            return $rules;
        }

        return [
            'title' => ['meta[name="citation_title"]@content', 'meta[property="og:title"]@content', 'h1', 'title'],
            'article_type' => ['meta[name="dc.type"]@content', '.c-article-info-details', '.article-type'],
            'published_at' => ['meta[name="citation_publication_date"]@content', 'time@datetime', '.c-article-identifiers__item time', '.published'],
            'authors' => ['meta[name="citation_author"]@content', '.c-article-author-list__item', '.authors a', '.author'],
            'abstract' => ['#Abs1', 'section[data-title="Abstract"]', '.c-article-section__content', '.abstract'],
            'references' => ['.c-article-references li', '.BibliographyWrapper li', '#Bib1 li', '.references li'],
            'doi' => ['meta[name="citation_doi"]@content', 'a[href*="doi.org/10."]@href', '.doi'],
            'keywords' => ['.c-article-subject-list li', '.KeywordGroup span', '.keywords li', '.keywords a'],
            'journal' => ['meta[name="citation_journal_title"]@content'],
            'volume' => ['meta[name="citation_volume"]@content'],
            'issue' => ['meta[name="citation_issue"]@content'],
            'page_range' => ['meta[name="citation_firstpage"]@content'],
            'pdf_url' => ['meta[name="citation_pdf_url"]@content', 'a[href$=".pdf"]@href'],
        ];
    }

    /** @param list<string> $selectors */
    private function extractFirst(\DOMXPath $xpath, array $selectors): ?string
    {
        foreach ($selectors as $selector) {
            foreach ($this->querySelector($xpath, $selector) as $value) {
                $value = $this->clean($value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @param list<string> $selectors @return list<string> */
    private function extractList(\DOMXPath $xpath, array $selectors): array
    {
        $out = [];
        foreach ($selectors as $selector) {
            foreach ($this->querySelector($xpath, $selector) as $value) {
                $value = $this->clean($value);
                if ($value !== '') {
                    $out[] = $value;
                }
            }
            if ($out !== []) {
                break;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return list<array<string,string>> */
    private function extractAuthorDetails(\DOMXPath $xpath): array
    {
        $names = $this->extractList($xpath, ['meta[name="citation_author"]@content', '.c-article-author-list__item', '.author']);
        $affiliations = $this->extractList($xpath, ['meta[name="citation_author_institution"]@content', '.c-article-author-affiliation', '.affiliation']);
        $emails = $this->extractList($xpath, ['a[href^="mailto:"]@href', '.email']);
        $rows = [];
        $max = max(count($names), count($affiliations), count($emails));
        for ($i = 0; $i < $max; $i++) {
            $email = $emails[$i] ?? null;
            if ($email !== null) {
                $email = preg_replace('~^mailto:~i', '', $email) ?? $email;
            }
            $row = array_filter([
                'author_name' => $names[$i] ?? null,
                'affiliation' => $affiliations[$i] ?? ($affiliations[0] ?? null),
                'email' => $email,
            ], static fn ($v): bool => is_string($v) && trim($v) !== '');
            if ($row !== []) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return list<string> */
    private function querySelector(\DOMXPath $xpath, string $selector): array
    {
        $attr = null;
        if (str_contains($selector, '@')) {
            [$selector, $attr] = explode('@', $selector, 2);
        }
        $query = $this->selectorToXpath(trim($selector));
        $nodes = $xpath->query($query);
        if (!$nodes) {
            return [];
        }
        $values = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $values[] = $attr ? $node->getAttribute($attr) : ($node->textContent ?: '');
        }
        return $values;
    }

    private function selectorToXpath(string $selector): string
    {
        $selector = trim($selector);

        // Support the common publisher metadata pattern ".container li".
        // The previous implementation treated this as "li.keywords", which
        // missed Springer-style <ul class="keywords"><li>...</li></ul> blocks.
        if (preg_match('~^\.([A-Za-z0-9_-]+)\s+([a-z0-9]+)$~i', $selector, $m) === 1) {
            return '//*[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[1] . ' ') . ')]//' . strtolower($m[2]);
        }
        if (preg_match('~^#([A-Za-z0-9_-]+)\s+([a-z0-9]+)$~i', $selector, $m) === 1) {
            return '//*[@id=' . $this->xpathLiteral($m[1]) . ']//' . strtolower($m[2]);
        }
        if (preg_match('~^([a-z0-9]+)\.([A-Za-z0-9_-]+)\s+([a-z0-9]+)$~i', $selector, $m) === 1) {
            return '//' . strtolower($m[1]) . '[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[2] . ' ') . ')]//' . strtolower($m[3]);
        }

        if ($selector === 'title') {
            return '//title';
        }
        if (preg_match('~^meta\[(name|property)="([^"]+)"\]$~', $selector, $m) === 1) {
            return '//meta[@' . $m[1] . '=' . $this->xpathLiteral($m[2]) . ']';
        }
        if (preg_match('~^([a-z0-9]+)\[([^=]+)="([^"]+)"\]$~i', $selector, $m) === 1) {
            return '//' . strtolower($m[1]) . '[@' . $m[2] . '=' . $this->xpathLiteral($m[3]) . ']';
        }
        if (preg_match('~^([a-z0-9]+)\[([^\^\$\*]+)([\^\$\*])="([^"]+)"\]$~i', $selector, $m) === 1) {
            $tag = strtolower($m[1]);
            $attr = $m[2];
            $op = $m[3];
            $value = $m[4];
            if ($op === '^') {
                return '//' . $tag . '[starts-with(@' . $attr . ', ' . $this->xpathLiteral($value) . ')]';
            }
            if ($op === '*') {
                return '//' . $tag . '[contains(@' . $attr . ', ' . $this->xpathLiteral($value) . ')]';
            }
            return '//' . $tag . '[substring(@' . $attr . ', string-length(@' . $attr . ') - string-length(' . $this->xpathLiteral($value) . ') + 1) = ' . $this->xpathLiteral($value) . ']';
        }
        if (preg_match('~^#([A-Za-z0-9_-]+)$~', $selector, $m) === 1) {
            return '//*[@id=' . $this->xpathLiteral($m[1]) . ']';
        }
        if (preg_match('~^([a-z0-9]+)\.([A-Za-z0-9_-]+)$~i', $selector, $m) === 1) {
            return '//' . strtolower($m[1]) . '[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[2] . ' ') . ')]';
        }
        if (preg_match('~^\.([A-Za-z0-9_-]+)(?:\s+([a-z0-9]+))?$~i', $selector, $m) === 1) {
            $tag = isset($m[2]) && $m[2] !== '' ? strtolower($m[2]) : '*';
            return '//' . $tag . '[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[1] . ' ') . ')]';
        }
        if (preg_match('~^([a-z0-9]+)$~i', $selector) === 1) {
            return '//' . strtolower($selector);
        }
        if (str_starts_with($selector, '.')) {
            $parts = explode(' ', $selector, 2);
            return $this->selectorToXpath($parts[0]);
        }
        return '//*';
    }

    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        return "concat('" . str_replace("'", "', \"'\", '", $value) . "')";
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    }
}
