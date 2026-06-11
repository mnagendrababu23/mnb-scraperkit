<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Parser;

use DOMElement;
use Mnb\ScraperKit\Support\UrlNormalizer;

final class PresetExtractor
{
    public function __construct(private UrlNormalizer $normalizer)
    {
    }

    /** @return array<string,mixed> */
    public function extract(HtmlDocument $doc, ?string $preset, string $baseUrl): array
    {
        $preset = strtolower(trim((string) $preset));

        return match ($preset) {
            'springer-journals', 'springer_journals' => $this->springerJournals($doc, $baseUrl),
            'links-with-text', 'links_with_text' => $this->linksWithText($doc, $baseUrl),
            'seo-basic', 'seo_basic' => $this->seoBasic($doc, $baseUrl),
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private function springerJournals(HtmlDocument $doc, string $baseUrl): array
    {
        $items = [];
        $seen = [];

        $queries = [
            '//a[contains(@href, "/journal/")]',
        ];

        foreach ($queries as $query) {
            foreach ($doc->xpath->query($query) ?: [] as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $href = trim($node->getAttribute('href'));
                $url = $this->normalizer->normalize($href, $baseUrl);
                $name = $this->cleanText($node->textContent);

                if (!$url || $name === '' || isset($seen[$url])) {
                    continue;
                }

                if (!preg_match('~/journal/[a-z0-9_-]+~i', (string) parse_url($url, PHP_URL_PATH))) {
                    continue;
                }

                $seen[$url] = true;
                $items[] = [
                    'name' => $name,
                    'url' => $url,
                    'slug_or_id' => basename((string) parse_url($url, PHP_URL_PATH)),
                ];
            }
        }

        return [
            'preset' => 'springer-journals',
            'journal_count' => count($items),
            'journals' => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function linksWithText(HtmlDocument $doc, string $baseUrl): array
    {
        $items = [];
        $seen = [];
        foreach ($doc->xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $url = $this->normalizer->normalize($node->getAttribute('href'), $baseUrl);
            $text = $this->cleanText($node->textContent);
            if (!$url || $text === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $items[] = [
                'text' => $text,
                'url' => $url,
            ];
        }

        return [
            'preset' => 'links-with-text',
            'link_count' => count($items),
            'links' => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function seoBasic(HtmlDocument $doc, string $baseUrl): array
    {
        $parser = new HtmlParser();
        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            $headings[$tag] = $parser->select($doc, $tag);
        }

        return [
            'preset' => 'seo-basic',
            'title' => $parser->title($doc),
            'description' => $parser->meta($doc, 'description'),
            'canonical' => $parser->canonical($doc, $baseUrl),
            'headings' => $headings,
            'h1_count' => count($headings['h1']),
            'h2_count' => count($headings['h2']),
            'h3_count' => count($headings['h3']),
        ];
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
