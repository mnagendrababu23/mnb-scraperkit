<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extractor;

use DOMElement;
use Mnb\ScraperKit\Parser\HtmlDocument;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Parser\JsonLdExtractor;
use Mnb\ScraperKit\Support\UrlNormalizer;

/**
 * V1.3 rule extractor supporting CSS-ish selectors, XPath, meta/OpenGraph,
 * JSON-LD paths, regex post-processing, attributes, fallbacks, and multi values.
 */
final class RuleBasedExtractor
{
    public function __construct(private readonly HtmlParser $parser, private readonly UrlNormalizer $normalizer)
    {
    }

    /**
     * @param array<string,mixed> $rules
     * @return array<string,mixed>
     */
    public function extract(HtmlDocument $doc, array $rules, string $baseUrl): array
    {
        $output = [];
        foreach ($rules as $field => $rule) {
            $field = trim((string) $field);
            if ($field === '') {
                continue;
            }
            $value = $this->extractRule($doc, $rule, $baseUrl);
            if ($value !== null && $value !== [] && $value !== '') {
                $output[$field] = $value;
            }
        }
        return $output;
    }

    private function extractRule(HtmlDocument $doc, mixed $rule, string $baseUrl): mixed
    {
        if (is_string($rule)) {
            return $this->extractStringRule($doc, $rule, $baseUrl);
        }
        if (!is_array($rule)) {
            return null;
        }

        if (isset($rule['fallback']) && is_array($rule['fallback'])) {
            foreach ($rule['fallback'] as $fallbackRule) {
                $value = $this->extractRule($doc, $fallbackRule, $baseUrl);
                if ($value !== null && $value !== [] && $value !== '') {
                    return $value;
                }
            }
        }

        $many = (bool) ($rule['many'] ?? str_ends_with((string) ($rule['css'] ?? $rule['xpath'] ?? ''), '[]'));
        $attr = isset($rule['attr']) ? trim((string) $rule['attr']) : (isset($rule['attribute']) ? trim((string) $rule['attribute']) : null);
        $values = [];

        if (isset($rule['meta'])) {
            $values = $this->meta($doc, (string) $rule['meta']);
        } elseif (isset($rule['og'])) {
            $values = $this->openGraph($doc, (string) $rule['og']);
        } elseif (isset($rule['json_ld']) || isset($rule['jsonld'])) {
            $values = $this->jsonLd($doc, (string) ($rule['json_ld'] ?? $rule['jsonld']));
        } elseif (isset($rule['xpath'])) {
            $values = $this->xpath($doc, (string) $rule['xpath'], $attr);
        } elseif (isset($rule['css'])) {
            $selector = (string) $rule['css'];
            if (str_ends_with($selector, '[]')) {
                $selector = substr($selector, 0, -2);
            }
            $values = $this->parser->select($doc, $selector, $attr);
        } elseif (isset($rule['text'])) {
            $values = [$this->parser->text($doc)];
        }

        if (($rule['regex'] ?? null) && is_string($rule['regex'])) {
            $values = $this->applyRegex($values, $rule['regex']);
        }
        if (($rule['url'] ?? false) || in_array($attr, ['href', 'src'], true)) {
            $values = array_values(array_filter(array_map(fn (string $v): ?string => $this->normalizer->normalize($v, $baseUrl), $values)));
        }
        $values = $this->cleanValues($values);
        return $many ? $values : ($values[0] ?? null);
    }

    private function extractStringRule(HtmlDocument $doc, string $rule, string $baseUrl): mixed
    {
        $many = false;
        if (str_ends_with($rule, '[]')) {
            $many = true;
            $rule = substr($rule, 0, -2);
        }
        $attr = null;
        if (preg_match('/::attr\(([^)]+)\)$/', $rule, $m)) {
            $attr = trim($m[1]);
            $rule = trim(substr($rule, 0, -strlen($m[0])));
        }
        if (str_starts_with($rule, 'meta:')) {
            $values = $this->meta($doc, substr($rule, 5));
        } elseif (str_starts_with($rule, 'og:')) {
            $values = $this->openGraph($doc, substr($rule, 3));
        } elseif (str_starts_with($rule, 'jsonld:')) {
            $values = $this->jsonLd($doc, substr($rule, 7));
        } elseif (str_starts_with($rule, '//')) {
            $values = $this->xpath($doc, $rule, $attr);
        } else {
            $values = $this->parser->select($doc, $rule, $attr);
        }
        if ($attr && in_array($attr, ['href', 'src'], true)) {
            $values = array_values(array_filter(array_map(fn (string $v): ?string => $this->normalizer->normalize($v, $baseUrl), $values)));
        }
        $values = $this->cleanValues($values);
        return $many ? $values : ($values[0] ?? null);
    }

    /** @return array<int,string> */
    private function xpath(HtmlDocument $doc, string $xpath, ?string $attr): array
    {
        $out = [];
        foreach ($doc->xpath->query($xpath) ?: [] as $node) {
            if ($attr && $node instanceof DOMElement) {
                $out[] = $node->getAttribute($attr);
            } else {
                $out[] = $node->textContent;
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    private function meta(HtmlDocument $doc, string $name): array
    {
        $name = strtolower(trim($name));
        return $this->xpath($doc, sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $name), null);
    }

    /** @return array<int,string> */
    private function openGraph(HtmlDocument $doc, string $property): array
    {
        $property = strtolower(trim($property));
        if (!str_starts_with($property, 'og:')) {
            $property = 'og:' . $property;
        }
        return $this->xpath($doc, sprintf('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $property), null);
    }

    /** @return array<int,string> */
    private function jsonLd(HtmlDocument $doc, string $path): array
    {
        $items = (new JsonLdExtractor())->extract($doc);
        $out = [];
        foreach ($items as $item) {
            foreach ($this->pathValues($item, $path) as $value) {
                if (is_scalar($value)) {
                    $out[] = (string) $value;
                }
            }
        }
        return $out;
    }

    /** @return array<int,mixed> */
    private function pathValues(mixed $data, string $path): array
    {
        $parts = array_values(array_filter(explode('.', trim($path)), static fn (string $p): bool => $p !== ''));
        $current = [$data];
        foreach ($parts as $part) {
            $next = [];
            foreach ($current as $item) {
                if ($part === '*') {
                    if (is_array($item)) {
                        foreach ($item as $child) {
                            $next[] = $child;
                        }
                    }
                } elseif (is_array($item) && array_key_exists($part, $item)) {
                    $next[] = $item[$part];
                }
            }
            $current = $next;
        }
        return $current;
    }

    /** @param array<int,string> $values @return array<int,string> */
    private function applyRegex(array $values, string $regex): array
    {
        $out = [];
        foreach ($values as $value) {
            if (@preg_match_all('/' . str_replace('/', '\\/', $regex) . '/u', $value, $matches) && isset($matches[1])) {
                foreach ($matches[1] as $match) {
                    $out[] = $match;
                }
            } elseif (@preg_match_all('/' . str_replace('/', '\\/', $regex) . '/u', $value, $matches) && isset($matches[0])) {
                foreach ($matches[0] as $match) {
                    $out[] = $match;
                }
            }
        }
        return $out;
    }

    /** @param array<int,string> $values @return array<int,string> */
    private function cleanValues(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($value !== '') {
                $out[$value] = $value;
            }
        }
        return array_values($out);
    }
}
