<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Mnb\ScraperKit\Support\UrlNormalizer;

final class PageComponentExtractor
{
    private UrlNormalizer $normalizer;

    public function __construct(?UrlNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new UrlNormalizer();
    }

    /** @return array<string,mixed> */
    public function extract(string $html, string $baseUrl = '', ?ExtractionOptions $options = null): array
    {
        $options = $options ?? new ExtractionOptions();
        if (!class_exists(\DOMDocument::class)) {
            return $this->extractFallback($html, $baseUrl, $options);
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $text = $this->cleanText($dom->textContent ?: '');

        $result = [
            'extractor_version' => '1.0.0',
            'base_url' => $baseUrl,
            'options' => $options->toArray(),
        ];

        if (in_array('whole_html', $options->types, true) || $options->saveWholeHtml) {
            $result['whole_html'] = $html;
        }
        if (in_array('text', $options->types, true)) {
            $result['plain_text'] = $text;
        }
        if ($options->selector !== null && $options->selector !== '') {
            $result['selected'] = $this->extractSelected($xpath, $options->selector, $options->includeHtml);
        }
        if (in_array('links', $options->types, true)) {
            $result['links'] = $this->links($xpath, $baseUrl, $options->includeImages);
        }
        if (in_array('images', $options->types, true) && $options->includeImages) {
            $result['images'] = $this->images($xpath, $baseUrl);
        }
        if (in_array('pdfs', $options->types, true)) {
            $result['pdf_files'] = $this->fileLinks($xpath, $baseUrl, ['pdf']);
        }
        if (in_array('tables', $options->types, true) || in_array('components', $options->types, true)) {
            $result['tables'] = $this->tables($xpath);
        }
        if (in_array('lists', $options->types, true) || in_array('components', $options->types, true)) {
            $result['lists'] = $this->lists($xpath);
        }
        if (in_array('headings', $options->types, true) || in_array('components', $options->types, true)) {
            $result['headings'] = $this->headings($xpath);
        }
        if (in_array('navigation_links', $options->types, true) || in_array('components', $options->types, true)) {
            $result['navigation_links'] = $this->navigationLinks($xpath, $baseUrl);
        }
        if (in_array('breadcrumbs', $options->types, true) || in_array('components', $options->types, true)) {
            $result['breadcrumbs'] = $this->breadcrumbs($xpath, $baseUrl);
        }
        if (in_array('social_links', $options->types, true) || in_array('components', $options->types, true)) {
            $result['social_links'] = $this->socialLinks($xpath, $baseUrl);
        }
        if (in_array('download_links', $options->types, true) || in_array('components', $options->types, true)) {
            $result['download_links'] = $this->downloadLinks($xpath, $baseUrl);
        }
        if (in_array('bio', $options->types, true) || in_array('components', $options->types, true)) {
            $result['bio_blocks'] = $this->bioBlocks($xpath);
        }
        if (in_array('cards', $options->types, true) || in_array('components', $options->types, true)) {
            $result['cards'] = $this->cards($xpath, $baseUrl);
        }
        if (in_array('components', $options->types, true)) {
            $result['repeated_components'] = $this->repeatedComponents($xpath, $options->minRepeats);
        }
        if (in_array('patterns', $options->types, true)) {
            $result['patterns'] = (new PatternRegistry($options->patternsFile))->match($text . "\n" . $html);
        }
        if (in_array('dictionary', $options->types, true)) {
            $dictionary = new WordDictionary($options->dictionaryFile);
            $learned = $dictionary->learn($text);
            $dictionary->save();
            $result['dictionary'] = [
                'new_total' => $learned['new_total'],
                'total' => $learned['total'],
                'new_words' => array_slice($learned['new_words'], 0, 100),
                'dictionary_file' => $options->dictionaryFile,
            ];
        }

        $result['counts'] = $this->counts($result);
        $result['_provenance'] = $this->provenance($result);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function extractSelected(DOMXPath $xpath, string $selector, bool $includeHtml): array
    {
        $rows = [];
        foreach ($this->query($xpath, $selector) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $row = ['tag' => strtolower($node->tagName), 'text' => $this->cleanText($node->textContent ?: '')];
            if ($includeHtml) {
                $row['inner_html'] = $this->innerHtml($node);
                $row['outer_html'] = $this->outerHtml($node);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,string>> */
    private function links(DOMXPath $xpath, string $baseUrl, bool $includeImages): array
    {
        $rows = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $url = $this->normalizer->normalize($node->getAttribute('href'), $baseUrl);
            if (!$url) {
                continue;
            }
            if (!$includeImages && preg_match('~\.(png|jpe?g|gif|webp|svg)(\?|$)~i', $url)) {
                continue;
            }
            $rows[$url] = ['url' => $url, 'text' => $this->cleanText($node->textContent ?: ''), 'rel' => $node->getAttribute('rel')];
        }
        return array_values($rows);
    }

    /** @return list<array<string,string>> */
    private function images(DOMXPath $xpath, string $baseUrl): array
    {
        $rows = [];
        foreach ($xpath->query('//img[@src]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $url = $this->normalizer->normalize($node->getAttribute('src'), $baseUrl);
            if ($url) {
                $rows[$url] = ['url' => $url, 'alt' => $this->cleanText($node->getAttribute('alt')), 'title' => $this->cleanText($node->getAttribute('title'))];
            }
        }
        return array_values($rows);
    }

    /** @param list<string> $extensions @return list<array<string,string>> */
    private function fileLinks(DOMXPath $xpath, string $baseUrl, array $extensions): array
    {
        $rows = [];
        $ext = implode('|', array_map('preg_quote', $extensions));
        foreach ($this->links($xpath, $baseUrl, true) as $link) {
            if (preg_match('~\.(' . $ext . ')(\?|$)~i', $link['url'] ?? '')) {
                $rows[$link['url']] = $link;
            }
        }
        return array_values($rows);
    }

    /** @return list<array<string,mixed>> */
    private function tables(DOMXPath $xpath): array
    {
        $tables = [];
        foreach ($xpath->query('//table') ?: [] as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }
            $rows = [];
            foreach ((new DOMXPath($table->ownerDocument))->query('.//tr', $table) ?: [] as $tr) {
                $cells = [];
                foreach ((new DOMXPath($table->ownerDocument))->query('./th|./td', $tr) ?: [] as $cell) {
                    $cells[] = $this->cleanText($cell->textContent ?: '');
                }
                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }
            $tables[] = ['rows_total' => count($rows), 'rows' => $rows];
        }
        return $tables;
    }

    /** @return list<array<string,mixed>> */
    private function lists(DOMXPath $xpath): array
    {
        $lists = [];
        foreach ($xpath->query('//ul|//ol') ?: [] as $list) {
            if (!$list instanceof DOMElement) {
                continue;
            }
            $items = [];
            foreach ((new DOMXPath($list->ownerDocument))->query('./li', $list) ?: [] as $li) {
                $items[] = $this->cleanText($li->textContent ?: '');
            }
            if ($items !== []) {
                $lists[] = ['tag' => strtolower($list->tagName), 'class' => $list->getAttribute('class'), 'items_total' => count($items), 'items' => $items];
            }
        }
        return $lists;
    }

    /** @return array<string,list<string>> */
    private function headings(DOMXPath $xpath): array
    {
        $headings = [];
        for ($i = 1; $i <= 6; $i++) {
            $key = 'h' . $i;
            $headings[$key] = [];
            foreach ($xpath->query('//' . $key) ?: [] as $node) {
                $text = $this->cleanText($node->textContent ?: '');
                if ($text !== '') {
                    $headings[$key][] = $text;
                }
            }
        }
        return $headings;
    }

    /** @return list<array<string,string>> */
    private function navigationLinks(DOMXPath $xpath, string $baseUrl): array
    {
        $rows = [];
        foreach ($xpath->query('//nav//a[@href]|//*[contains(concat(" ", normalize-space(@class), " "), " nav ")]//a[@href]|//*[contains(concat(" ", normalize-space(@role), " "), " navigation ")]//a[@href]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $url = $this->normalizer->normalize($node->getAttribute('href'), $baseUrl);
                if ($url) {
                    $rows[$url] = ['url' => $url, 'text' => $this->cleanText($node->textContent ?: '')];
                }
            }
        }
        return array_values($rows);
    }

    /** @return list<array<string,string>> */
    private function breadcrumbs(DOMXPath $xpath, string $baseUrl): array
    {
        $rows = [];
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " breadcrumb ")]//*[self::a or self::li or self::span]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $text = $this->cleanText($node->textContent ?: '');
            if ($text === '') {
                continue;
            }
            $href = $node->getAttribute('href');
            $rows[] = ['text' => $text, 'url' => $href !== '' ? (string) ($this->normalizer->normalize($href, $baseUrl) ?? '') : ''];
        }
        return $rows;
    }

    /** @return list<array<string,string>> */
    private function socialLinks(DOMXPath $xpath, string $baseUrl): array
    {
        $domains = ['facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'instagram.com', 'youtube.com', 'github.com'];
        $rows = [];
        foreach ($this->links($xpath, $baseUrl, true) as $link) {
            foreach ($domains as $domain) {
                if (str_contains(strtolower($link['url']), $domain)) {
                    $rows[$link['url']] = ['url' => $link['url'], 'text' => $link['text'], 'platform' => explode('.', $domain)[0]];
                }
            }
        }
        return array_values($rows);
    }

    /** @return list<array<string,string>> */
    private function downloadLinks(DOMXPath $xpath, string $baseUrl): array
    {
        $rows = [];
        foreach ($this->links($xpath, $baseUrl, true) as $link) {
            $url = $link['url'];
            if (preg_match('~\.(pdf|docx?|xlsx?|pptx?|zip|csv|json|xml)(\?|$)~i', $url, $m) || preg_match('~download|attachment~i', $url . ' ' . ($link['text'] ?? ''))) {
                $rows[$url] = ['url' => $url, 'text' => $link['text'], 'extension' => strtolower($m[1] ?? '')];
            }
        }
        return array_values($rows);
    }

    /** @return list<array<string,string>> */
    private function bioBlocks(DOMXPath $xpath): array
    {
        $rows = [];
        foreach ($xpath->query('//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "bio") or contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "bio") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "author")]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $text = $this->cleanText($node->textContent ?: '');
                if ($text !== '') {
                    $rows[] = ['tag' => strtolower($node->tagName), 'class' => $node->getAttribute('class'), 'id' => $node->getAttribute('id'), 'text' => $text];
                }
            }
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function cards(DOMXPath $xpath, string $baseUrl): array
    {
        $rows = [];
        foreach ($xpath->query('//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "card") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "item") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "result")]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $local = new DOMXPath($node->ownerDocument);
            $links = [];
            foreach ($local->query('.//a[@href]', $node) ?: [] as $a) {
                if ($a instanceof DOMElement) {
                    $url = $this->normalizer->normalize($a->getAttribute('href'), $baseUrl);
                    if ($url) {
                        $links[] = ['url' => $url, 'text' => $this->cleanText($a->textContent ?: '')];
                    }
                }
            }
            $rows[] = [
                'tag' => strtolower($node->tagName),
                'class' => $node->getAttribute('class'),
                'id' => $node->getAttribute('id'),
                'text' => $this->cleanText($node->textContent ?: ''),
                'links' => $links,
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function repeatedComponents(DOMXPath $xpath, int $minRepeats): array
    {
        $groups = [];
        foreach ($xpath->query('//*[not(self::script) and not(self::style) and not(self::html) and not(self::body)]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $keys = ['tag:' . strtolower($node->tagName)];
            $id = trim($node->getAttribute('id'));
            if ($id !== '') {
                $keys[] = 'id:' . $id;
            }
            foreach (preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [] as $class) {
                if ($class !== '') {
                    $keys[] = 'class:' . $class;
                }
            }
            $linkCount = (new DOMXPath($node->ownerDocument))->query('.//a[@href]', $node)?->length ?? 0;
            $wordCount = str_word_count($this->cleanText($node->textContent ?: ''));
            foreach ($keys as $key) {
                $groups[$key]['key'] = $key;
                $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
                $groups[$key]['tags'][strtolower($node->tagName)] = true;
                $groups[$key]['sample_texts'][] = substr($this->cleanText($node->textContent ?: ''), 0, 160);
                $groups[$key]['links_total'] = ($groups[$key]['links_total'] ?? 0) + $linkCount;
                $groups[$key]['words_total'] = ($groups[$key]['words_total'] ?? 0) + $wordCount;
            }
        }
        $rows = [];
        foreach ($groups as $group) {
            if (($group['count'] ?? 0) < $minRepeats) {
                continue;
            }
            $samples = array_values(array_filter(array_unique($group['sample_texts'] ?? [])));
            $rows[] = [
                'key' => $group['key'],
                'count' => (int) $group['count'],
                'tags' => array_keys($group['tags'] ?? []),
                'links_total' => (int) ($group['links_total'] ?? 0),
                'words_total' => (int) ($group['words_total'] ?? 0),
                'sample_texts' => array_slice($samples, 0, 3),
                'component_type' => $this->componentType((string) $group['key']),
                'confidence' => min(0.99, round(0.55 + (((int) $group['count']) * 0.05), 2)),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp((string) $a['key'], (string) $b['key']));
        return $rows;
    }


    /** @return array<string,mixed> */
    private function extractFallback(string $html, string $baseUrl, ExtractionOptions $options): array
    {
        $text = $this->cleanText(strip_tags($html));
        $result = [
            'extractor_version' => '1.0.0',
            'base_url' => $baseUrl,
            'options' => $options->toArray(),
            'fallback' => 'regex_dom_extension_missing',
        ];
        if (in_array('whole_html', $options->types, true) || $options->saveWholeHtml) {
            $result['whole_html'] = $html;
        }
        if (in_array('text', $options->types, true)) {
            $result['plain_text'] = $text;
        }
        preg_match_all('~<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $links);
        $linkRows = [];
        foreach ($links[1] ?? [] as $i => $href) {
            $url = $this->normalizer->normalize((string) $href, $baseUrl);
            if ($url) {
                $linkRows[$url] = ['url' => $url, 'text' => $this->cleanText(strip_tags((string) ($links[2][$i] ?? ''))), 'rel' => ''];
            }
        }
        if (in_array('links', $options->types, true)) {
            $result['links'] = array_values($linkRows);
        }
        preg_match_all('~<img[^>]+src=["\']([^"\']+)["\'][^>]*>~is', $html, $imgs);
        $imageRows = [];
        foreach ($imgs[1] ?? [] as $src) {
            $url = $this->normalizer->normalize((string) $src, $baseUrl);
            if ($url) {
                $imageRows[$url] = ['url' => $url, 'alt' => '', 'title' => ''];
            }
        }
        if (in_array('images', $options->types, true) && $options->includeImages) {
            $result['images'] = array_values($imageRows);
        }
        if (in_array('pdfs', $options->types, true)) {
            $result['pdf_files'] = array_values(array_filter(array_values($linkRows), static fn (array $row): bool => preg_match('~\.pdf(\?|$)~i', (string) ($row['url'] ?? '')) === 1));
        }
        if (in_array('headings', $options->types, true) || in_array('components', $options->types, true)) {
            $headings = [];
            for ($i = 1; $i <= 6; $i++) {
                preg_match_all('~<h' . $i . '[^>]*>(.*?)</h' . $i . '>~is', $html, $m);
                $headings['h' . $i] = array_values(array_filter(array_map(fn (string $v): string => $this->cleanText(strip_tags($v)), $m[1] ?? [])));
            }
            $result['headings'] = $headings;
        }
        if (in_array('tables', $options->types, true) || in_array('components', $options->types, true)) {
            preg_match_all('~<table[^>]*>(.*?)</table>~is', $html, $tables);
            $result['tables'] = array_map(fn (string $table): array => ['rows_total' => substr_count(strtolower($table), '<tr'), 'text' => $this->cleanText(strip_tags($table))], $tables[1] ?? []);
        }
        if (in_array('lists', $options->types, true) || in_array('components', $options->types, true)) {
            preg_match_all('~<(ul|ol)[^>]*>(.*?)</\1>~is', $html, $lists, PREG_SET_ORDER);
            $result['lists'] = array_map(fn (array $m): array => ['tag' => strtolower($m[1]), 'items_total' => substr_count(strtolower($m[2]), '<li'), 'items' => $this->listItemsFromHtml($m[2])], $lists);
        }
        if (in_array('navigation_links', $options->types, true) || in_array('components', $options->types, true)) {
            preg_match('~<nav[^>]*>(.*?)</nav>~is', $html, $nav);
            $result['navigation_links'] = isset($nav[1]) ? $this->linkRowsFromHtml($nav[1], $baseUrl) : [];
        }
        if (in_array('breadcrumbs', $options->types, true) || in_array('components', $options->types, true)) {
            preg_match('~<[^>]+class=["\'][^"\']*breadcrumb[^"\']*["\'][^>]*>(.*?)</[^>]+>~is', $html, $bc);
            $result['breadcrumbs'] = isset($bc[1]) ? array_map(static fn (string $v): array => ['text' => trim($v), 'url' => ''], $this->listItemsFromHtml($bc[1])) : [];
        }
        if (in_array('social_links', $options->types, true) || in_array('components', $options->types, true)) {
            $result['social_links'] = array_values(array_filter(array_values($linkRows), static fn (array $row): bool => preg_match('~(twitter|x|facebook|linkedin|instagram|youtube|github)\.com~i', (string) ($row['url'] ?? '')) === 1));
        }
        if (in_array('download_links', $options->types, true) || in_array('components', $options->types, true)) {
            $result['download_links'] = array_values(array_filter(array_values($linkRows), static fn (array $row): bool => preg_match('~\.(pdf|docx?|xlsx?|pptx?|zip|csv|json|xml)(\?|$)|download~i', (string) ($row['url'] ?? '') . ' ' . (string) ($row['text'] ?? '')) === 1));
        }
        if (in_array('bio', $options->types, true) || in_array('components', $options->types, true)) {
            preg_match_all('~<([a-z0-9]+)[^>]+(?:class|id)=["\'][^"\']*bio[^"\']*["\'][^>]*>(.*?)</\1>~is', $html, $bio, PREG_SET_ORDER);
            $result['bio_blocks'] = array_map(fn (array $m): array => ['tag' => strtolower($m[1]), 'text' => $this->cleanText(strip_tags($m[2]))], $bio);
        }
        if (in_array('cards', $options->types, true) || in_array('components', $options->types, true)) {
            preg_match_all('~<([a-z0-9]+)[^>]+class=["\'][^"\']*(?:card|item|result)[^"\']*["\'][^>]*>(.*?)</\1>~is', $html, $cards, PREG_SET_ORDER);
            $result['cards'] = array_map(fn (array $m): array => ['tag' => strtolower($m[1]), 'text' => $this->cleanText(strip_tags($m[2])), 'links' => $this->linkRowsFromHtml($m[2], $baseUrl)], $cards);
        }
        if (in_array('components', $options->types, true)) {
            $result['repeated_components'] = $this->repeatedComponentsFromHtml($html, $options->minRepeats);
        }
        if (in_array('patterns', $options->types, true)) {
            $result['patterns'] = (new PatternRegistry($options->patternsFile))->match($text . "\n" . $html);
        }
        if (in_array('dictionary', $options->types, true)) {
            $dictionary = new WordDictionary($options->dictionaryFile);
            $learned = $dictionary->learn($text);
            $dictionary->save();
            $result['dictionary'] = ['new_total' => $learned['new_total'], 'total' => $learned['total'], 'new_words' => array_slice($learned['new_words'], 0, 100), 'dictionary_file' => $options->dictionaryFile];
        }
        $result['counts'] = $this->counts($result);
        $result['_provenance'] = $this->provenance($result);
        return $result;
    }

    /** @return list<string> */
    private function listItemsFromHtml(string $html): array
    {
        preg_match_all('~<li[^>]*>(.*?)</li>~is', $html, $m);
        return array_values(array_filter(array_map(fn (string $v): string => $this->cleanText(strip_tags($v)), $m[1] ?? [])));
    }

    /** @return list<array<string,string>> */
    private function linkRowsFromHtml(string $html, string $baseUrl): array
    {
        preg_match_all('~<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $m);
        $rows = [];
        foreach ($m[1] ?? [] as $i => $href) {
            $url = $this->normalizer->normalize((string) $href, $baseUrl);
            if ($url) {
                $rows[$url] = ['url' => $url, 'text' => $this->cleanText(strip_tags((string) ($m[2][$i] ?? '')))];
            }
        }
        return array_values($rows);
    }

    /** @return list<array<string,mixed>> */
    private function repeatedComponentsFromHtml(string $html, int $minRepeats): array
    {
        $groups = [];
        preg_match_all('~<([a-z0-9]+)([^>]*)>~i', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            $name = strtolower($tag[1]);
            if (in_array($name, ['html', 'body', 'script', 'style'], true)) {
                continue;
            }
            $keys = ['tag:' . $name];
            if (preg_match('~id=["\']([^"\']+)["\']~i', $tag[2], $id)) {
                $keys[] = 'id:' . $id[1];
            }
            if (preg_match('~class=["\']([^"\']+)["\']~i', $tag[2], $class)) {
                foreach (preg_split('/\s+/', trim($class[1])) ?: [] as $c) {
                    if ($c !== '') {
                        $keys[] = 'class:' . $c;
                    }
                }
            }
            foreach ($keys as $key) {
                $groups[$key]['key'] = $key;
                $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
                $groups[$key]['tags'][$name] = true;
            }
        }
        $rows = [];
        foreach ($groups as $group) {
            if (($group['count'] ?? 0) >= $minRepeats) {
                $rows[] = ['key' => $group['key'], 'count' => (int) $group['count'], 'tags' => array_keys($group['tags'] ?? []), 'links_total' => 0, 'words_total' => 0, 'sample_texts' => [], 'component_type' => $this->componentType((string) $group['key']), 'confidence' => min(0.99, round(0.55 + (((int) $group['count']) * 0.05), 2))];
            }
        }
        usort($rows, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp((string) $a['key'], (string) $b['key']));
        return $rows;
    }

    /** @return list<DOMNode> */
    private function query(DOMXPath $xpath, string $selector): array
    {
        $selector = trim($selector);
        if ($selector === '') {
            return [];
        }
        if (str_starts_with($selector, '//')) {
            return iterator_to_array($xpath->query($selector) ?: new \ArrayIterator([]));
        }
        $query = match (true) {
            preg_match('/^#([A-Za-z0-9_-]+)$/', $selector, $m) === 1 => '//*[@id=' . $this->xpathLiteral($m[1]) . ']',
            preg_match('/^\.([A-Za-z0-9_-]+)$/', $selector, $m) === 1 => '//*[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[1] . ' ') . ')]',
            preg_match('/^([a-z0-9]+)\.([A-Za-z0-9_-]+)$/i', $selector, $m) === 1 => '//' . strtolower($m[1]) . '[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[2] . ' ') . ')]',
            preg_match('/^[a-z0-9]+$/i', $selector) === 1 => '//' . strtolower($selector),
            default => '//*',
        };
        return iterator_to_array($xpath->query($query) ?: new \ArrayIterator([]));
    }

    private function innerHtml(DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }
        return $html;
    }

    private function outerHtml(DOMElement $node): string
    {
        return $node->ownerDocument?->saveHTML($node) ?: '';
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

    /** @param array<string,mixed> $result @return array<string,array<string,mixed>> */
    private function provenance(array $result): array
    {
        $map = [
            'plain_text' => ['method' => 'dom_text_content', 'selector' => 'document text'],
            'whole_html' => ['method' => 'source_html', 'selector' => 'document'],
            'selected' => ['method' => 'selector', 'selector' => (string) (($result['options']['selector'] ?? null) ?: '')],
            'links' => ['method' => 'xpath', 'selector' => '//a[@href]'],
            'images' => ['method' => 'xpath', 'selector' => '//img[@src]'],
            'pdf_files' => ['method' => 'link_filter', 'selector' => '//a[contains(@href, ".pdf")]'],
            'tables' => ['method' => 'xpath', 'selector' => '//table'],
            'lists' => ['method' => 'xpath', 'selector' => '//ul|//ol'],
            'headings' => ['method' => 'xpath', 'selector' => '//h1..//h6'],
            'navigation_links' => ['method' => 'xpath', 'selector' => '//nav//a'],
            'breadcrumbs' => ['method' => 'class_heuristic', 'selector' => '*[class*=breadcrumb]'],
            'social_links' => ['method' => 'domain_filter', 'selector' => 'social domains'],
            'download_links' => ['method' => 'extension_filter', 'selector' => 'download/document extensions'],
            'bio_blocks' => ['method' => 'class_id_heuristic', 'selector' => '*[class/id*=bio|author]'],
            'cards' => ['method' => 'class_heuristic', 'selector' => '*[class*=card|item|result]'],
            'repeated_components' => ['method' => 'repetition_heuristic', 'selector' => 'tag/id/class frequency'],
            'patterns' => ['method' => 'regex_registry', 'selector' => 'registered patterns'],
            'dictionary' => ['method' => 'word_dictionary_learning', 'selector' => 'plain text tokens'],
        ];
        $out = [];
        foreach ($map as $field => $meta) {
            if (!array_key_exists($field, $result)) {
                continue;
            }
            $value = $result[$field];
            $count = is_array($value) ? count($value) : (($value === '' || $value === null) ? 0 : 1);
            $out[$field] = [
                'field' => $field,
                'status' => $count > 0 ? 'found' : 'empty',
                'count' => $count,
                'method' => $meta['method'],
                'selector' => $meta['selector'],
                'confidence' => $count > 0 ? 0.84 : 0.0,
            ];
        }
        return $out;
    }

    private function componentType(string $key): string
    {
        $lower = strtolower($key);
        return match (true) {
            str_contains($lower, 'card'), str_contains($lower, 'result'), str_contains($lower, 'item') => 'card_or_result_list',
            str_contains($lower, 'nav'), str_contains($lower, 'menu') => 'navigation',
            str_contains($lower, 'breadcrumb') => 'breadcrumb',
            str_contains($lower, 'author'), str_contains($lower, 'bio') => 'bio_or_author_block',
            str_contains($lower, 'table'), str_contains($lower, 'row') => 'table_or_rows',
            default => str_starts_with($lower, 'tag:a') ? 'link_group' : 'repeated_dom_group',
        };
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\R+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /** @param array<string,mixed> $result @return array<string,int> */
    private function counts(array $result): array
    {
        $counts = [];
        foreach ($result as $key => $value) {
            if (is_array($value)) {
                $counts[$key] = count($value);
            }
        }
        return $counts;
    }
}
