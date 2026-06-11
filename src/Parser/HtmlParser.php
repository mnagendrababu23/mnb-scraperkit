<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Parser;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Mnb\ScraperKit\Support\UrlNormalizer;

final class HtmlParser
{
    public function load(string $html, string $baseUrl = ''): HtmlDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        return new HtmlDocument($dom, new DOMXPath($dom), $baseUrl);
    }

    public function title(HtmlDocument $doc): ?string
    {
        return $this->firstText($doc, '//title');
    }

    public function meta(HtmlDocument $doc, string $name): ?string
    {
        $name = strtolower($name);
        $query = sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $name);
        return $this->firstAttributeValue($doc, $query);
    }

    public function canonical(HtmlDocument $doc, string $baseUrl): ?string
    {
        $href = $this->firstAttributeValue($doc, '//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]/@href');
        return $href ? (new UrlNormalizer())->normalize($href, $baseUrl) : null;
    }

    /** @return array<int,string> */
    public function links(HtmlDocument $doc, string $baseUrl): array
    {
        $links = [];
        $normalizer = new UrlNormalizer();
        foreach ($doc->xpath->query('//a[@href]/@href') ?: [] as $node) {
            $url = $normalizer->normalize($node->nodeValue, $baseUrl);
            if ($url) {
                $links[$url] = $url;
            }
        }
        return array_values($links);
    }

    /** @return array<int,string> */
    public function images(HtmlDocument $doc, string $baseUrl): array
    {
        $images = [];
        $normalizer = new UrlNormalizer();
        foreach ($doc->xpath->query('//img[@src]/@src') ?: [] as $node) {
            $url = $normalizer->normalize($node->nodeValue, $baseUrl);
            if ($url) {
                $images[$url] = $url;
            }
        }
        return array_values($images);
    }

    public function text(HtmlDocument $doc): string
    {
        foreach ($doc->xpath->query('//script|//style|//noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        $text = $doc->dom->textContent;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R{2,}/', "\n", $text) ?? $text;
        return trim($text);
    }

    /** @return array<int,string> */
    public function select(HtmlDocument $doc, string $selector, ?string $attribute = null): array
    {
        $xpath = $this->selectorToXPath($selector);
        $items = [];
        foreach ($doc->xpath->query($xpath) ?: [] as $node) {
            if ($attribute && $node instanceof DOMElement) {
                $items[] = trim($node->getAttribute($attribute));
            } else {
                $items[] = trim($node->textContent);
            }
        }
        return array_values(array_filter($items, static fn (string $value): bool => $value !== ''));
    }

    public function firstText(HtmlDocument $doc, string $xpath): ?string
    {
        $nodes = $doc->xpath->query($xpath);
        if (!$nodes || $nodes->length === 0) {
            return null;
        }
        return trim($nodes->item(0)?->textContent ?? '') ?: null;
    }

    private function firstAttributeValue(HtmlDocument $doc, string $xpath): ?string
    {
        $nodes = $doc->xpath->query($xpath);
        if (!$nodes || $nodes->length === 0) {
            return null;
        }
        return trim($nodes->item(0)?->nodeValue ?? '') ?: null;
    }

    private function selectorToXPath(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return '//*';
        }

        if (str_starts_with($selector, '//')) {
            return $selector;
        }

        if (preg_match('/^#([a-zA-Z0-9_-]+)$/', $selector, $m)) {
            return '//*[@id="' . $m[1] . '"]';
        }

        if (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $selector, $m)) {
            return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $m[1] . ' ")]';
        }

        if (preg_match('/^([a-zA-Z0-9_-]+)\.([a-zA-Z0-9_-]+)$/', $selector, $m)) {
            return '//' . $m[1] . '[contains(concat(" ", normalize-space(@class), " "), " ' . $m[2] . ' ")]';
        }

        if (preg_match('/^([a-zA-Z0-9_-]+)#([a-zA-Z0-9_-]+)$/', $selector, $m)) {
            return '//' . $m[1] . '[@id="' . $m[2] . '"]';
        }

        if (preg_match('/^\[([^\]=]+)([*$^]?=)["\']?([^"\']+)["\']?\]$/', $selector, $m)) {
            $attr = $m[1];
            $op = $m[2];
            $value = $m[3];
            if ($op === '*=') {
                return '//*[contains(@' . $attr . ', "' . $value . '")]';
            }
            if ($op === '^=') {
                return '//*[starts-with(@' . $attr . ', "' . $value . '")]';
            }
            if ($op === '$=') {
                return '//*[substring(@' . $attr . ', string-length(@' . $attr . ') - string-length("' . $value . '") + 1) = "' . $value . '"]';
            }
            return '//*[@' . $attr . '="' . $value . '"]';
        }

        if (preg_match('/^([a-zA-Z0-9_-]+)\[([^\]=]+)([*$^]?=)["\']?([^"\']+)["\']?\]$/', $selector, $m)) {
            $tag = $m[1];
            $attr = $m[2];
            $op = $m[3];
            $value = $m[4];
            if ($op === '*=') {
                return '//' . $tag . '[contains(@' . $attr . ', "' . $value . '")]';
            }
            if ($op === '^=') {
                return '//' . $tag . '[starts-with(@' . $attr . ', "' . $value . '")]';
            }
            if ($op === '$=') {
                return '//' . $tag . '[substring(@' . $attr . ', string-length(@' . $attr . ') - string-length("' . $value . '") + 1) = "' . $value . '"]';
            }
            return '//' . $tag . '[@' . $attr . '="' . $value . '"]';
        }

        if (preg_match('/^[a-zA-Z0-9_-]+$/', $selector)) {
            return '//' . $selector;
        }

        return '//*';
    }
}
