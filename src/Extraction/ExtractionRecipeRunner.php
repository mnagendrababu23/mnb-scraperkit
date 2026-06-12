<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Mnb\ScraperKit\Support\UrlNormalizer;

final class ExtractionRecipeRunner
{
    private UrlNormalizer $normalizer;

    public function __construct(?UrlNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new UrlNormalizer();
    }

    /** @return array<string,mixed> */
    public function run(string $html, string $baseUrl, ExtractionRecipe $recipe): array
    {
        if (!class_exists(\DOMDocument::class)) {
            return $this->runFallback($html, $baseUrl, $recipe);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        $record = [];
        $provenance = [];
        foreach ($recipe->fields() as $field => $definition) {
            $selectors = array_values(array_map('strval', (array) ($definition['selectors'] ?? $definition['selector'] ?? [])));
            $multiple = (bool) ($definition['multiple'] ?? in_array($field, ['authors', 'keywords', 'references', 'links', 'images'], true));
            $required = (bool) ($definition['required'] ?? false);
            [$value, $source] = $this->extractBySelectors($xpath, $selectors, $baseUrl, $multiple, (array) ($definition['transforms'] ?? $definition['transform'] ?? []));
            if ($value !== null && $value !== [] && $value !== '') {
                $record[$field] = $value;
            }
            $provenance[$field] = [
                'field' => $field,
                'required' => $required,
                'status' => ($value !== null && $value !== [] && $value !== '') ? 'found' : 'missing',
                'selector' => $source['selector'] ?? null,
                'method' => $source['method'] ?? 'selector_fallback',
                'confidence' => ($value !== null && $value !== [] && $value !== '') ? ($required ? 0.95 : 0.86) : 0.0,
                'candidates_tried' => $selectors,
            ];
        }

        $componentTypes = array_keys($recipe->components());
        if ($componentTypes !== []) {
            $types = implode(',', array_unique(array_merge(['components'], $componentTypes)));
        } else {
            $types = (string) ($recipe->options()['types'] ?? 'links,text,headings,components');
        }
        $componentOptions = new ExtractionOptions(
            ExtractionOptions::normalizeTypes($types),
            null,
            (int) ($recipe->options()['min_repeats'] ?? 2),
            (bool) ($recipe->options()['include_images'] ?? true),
            (bool) ($recipe->options()['include_html'] ?? false),
            (bool) ($recipe->options()['save_whole_html'] ?? false),
            isset($recipe->options()['dictionary_file']) ? (string) $recipe->options()['dictionary_file'] : null,
            isset($recipe->options()['patterns_file']) ? (string) $recipe->options()['patterns_file'] : null,
            isset($recipe->options()['mappings_file']) ? (string) $recipe->options()['mappings_file'] : null,
        );
        $components = (new PageComponentExtractor())->extract($html, $baseUrl, $componentOptions);

        $quality = (new ExtractionQualityReporter())->report([
            'record' => $record,
            '_provenance' => $provenance,
            'components' => $components,
        ], $recipe->requiredFields());

        return [
            'recipe_result_version' => '4.2.1',
            'recipe' => [
                'id' => $recipe->id(),
                'name' => $recipe->name(),
                'source_type' => (string) ($recipe->data()['source_type'] ?? 'page'),
            ],
            'base_url' => $baseUrl,
            'record' => $record,
            '_provenance' => $provenance,
            'components' => $components,
            'quality' => $quality,
        ];
    }


    /** @return array<string,mixed> */
    private function runFallback(string $html, string $baseUrl, ExtractionRecipe $recipe): array
    {
        $record = [];
        $provenance = [];
        foreach ($recipe->fields() as $field => $definition) {
            $selectors = array_values(array_map('strval', (array) ($definition['selectors'] ?? $definition['selector'] ?? [])));
            $multiple = (bool) ($definition['multiple'] ?? in_array($field, ['authors', 'keywords', 'references', 'links', 'images'], true));
            $values = [];
            $used = null;
            foreach ($selectors as $selector) {
                $values = $this->extractFallbackSelector($html, $selector, $baseUrl);
                if ($values !== []) {
                    $used = $selector;
                    break;
                }
            }
            $transforms = array_values(array_map('strval', (array) ($definition['transforms'] ?? $definition['transform'] ?? [])));
            $values = array_map(static fn (mixed $v): mixed => DataMappingRegistry::applyTransforms($v, $transforms), $values);
            $values = array_values(array_unique(array_filter($values, static fn (mixed $v): bool => $v !== null && $v !== ''), SORT_REGULAR));
            if ($values !== []) {
                $record[$field] = $multiple ? $values : $values[0];
            }
            $provenance[$field] = [
                'field' => $field,
                'required' => (bool) ($definition['required'] ?? false),
                'status' => $values !== [] ? 'found' : 'missing',
                'selector' => $used,
                'method' => 'regex_fallback_selector',
                'confidence' => $values !== [] ? 0.72 : 0.0,
                'candidates_tried' => $selectors,
            ];
        }
        $components = (new PageComponentExtractor())->extract($html, $baseUrl, new ExtractionOptions(ExtractionOptions::normalizeTypes((string) ($recipe->options()['types'] ?? 'links,text,headings,components'))));
        $quality = (new ExtractionQualityReporter())->report(['record' => $record, '_provenance' => $provenance, 'components' => $components], $recipe->requiredFields());
        return [
            'recipe_result_version' => '4.2.1',
            'recipe' => ['id' => $recipe->id(), 'name' => $recipe->name(), 'source_type' => (string) ($recipe->data()['source_type'] ?? 'page')],
            'base_url' => $baseUrl,
            'record' => $record,
            '_provenance' => $provenance,
            'components' => $components,
            'quality' => $quality,
            'fallback' => 'regex_dom_extension_missing',
        ];
    }

    /** @return list<string> */
    private function extractFallbackSelector(string $html, string $selector, string $baseUrl): array
    {
        $selector = trim($selector);
        if (preg_match('/^meta\[(name|property)=["\']([^"\']+)["\']\]@content$/i', $selector, $m) === 1) {
            return $this->matchAll('~<meta[^>]+'.preg_quote($m[1], '~').'=["\']'.preg_quote($m[2], '~').'["\'][^>]+content=["\']([^"\']+)["\'][^>]*>~i', $html);
        }
        if (preg_match('/^a\[href\*=["\']([^"\']+)["\']\]@href$/i', $selector, $m) === 1) {
            return array_map(fn (string $href): string => (string) ($this->normalizer->normalize($href, $baseUrl) ?? $href), $this->matchAll('~<a[^>]+href=["\']([^"\']*'.preg_quote($m[1], '~').'[^"\']*)["\'][^>]*>~i', $html));
        }
        if (preg_match('/^a\[href\$=["\']([^"\']+)["\']\]@href$/i', $selector, $m) === 1) {
            return array_values(array_filter(array_map(fn (string $href): string => (string) ($this->normalizer->normalize($href, $baseUrl) ?? $href), $this->matchAll('~<a[^>]+href=["\']([^"\']+)["\'][^>]*>~i', $html)), static fn (string $href): bool => str_ends_with(parse_url($href, PHP_URL_PATH) ?: $href, $m[1])));
        }
        if (preg_match('/^#([A-Za-z0-9_-]+)$/', $selector, $m) === 1) {
            return array_map(fn (string $v): string => $this->cleanText(strip_tags($v)), $this->matchAll('~<([a-z0-9]+)[^>]+id=["\']'.preg_quote($m[1], '~').'["\'][^>]*>(.*?)</\\1>~is', $html, 2));
        }
        if (preg_match('/^\.([A-Za-z0-9_-]+)\s+([a-z0-9]+)$/i', $selector, $m) === 1) {
            $blocks = $this->matchAll('~<([a-z0-9]+)[^>]+class=["\'][^"\']*'.preg_quote($m[1], '~').'[^"\']*["\'][^>]*>(.*?)</\\1>~is', $html, 2);
            $items = [];
            foreach ($blocks as $block) {
                $items = array_merge($items, array_map(fn (string $v): string => $this->cleanText(strip_tags($v)), $this->matchAll('~<'.preg_quote($m[2], '~').'[^>]*>(.*?)</'.preg_quote($m[2], '~').'>~is', $block)));
            }
            return $items;
        }
        if ($selector === 'h1' || $selector === 'title') {
            return array_map(fn (string $v): string => $this->cleanText(strip_tags($v)), $this->matchAll('~<'.preg_quote($selector, '~').'[^>]*>(.*?)</'.preg_quote($selector, '~').'>~is', $html));
        }
        return [];
    }

    /** @return list<string> */
    private function matchAll(string $pattern, string $html, int $group = 1): array
    {
        preg_match_all($pattern, $html, $m);
        return array_values(array_filter(array_map('strval', $m[$group] ?? [])));
    }

    /** @param list<string> $selectors @param array<int,string> $transforms @return array{0:mixed,1:array<string,mixed>} */
    private function extractBySelectors(DOMXPath $xpath, array $selectors, string $baseUrl, bool $multiple, array $transforms): array
    {
        foreach ($selectors as $selector) {
            $values = [];
            foreach ($this->querySelector($xpath, $selector) as $item) {
                $value = $item['value'];
                if (($item['is_url'] ?? false) === true) {
                    $value = $this->normalizer->normalize((string) $value, $baseUrl) ?: $value;
                }
                $value = DataMappingRegistry::applyTransforms($value, $transforms);
                if (is_string($value)) {
                    $value = $this->cleanText($value);
                }
                if ($value !== '' && $value !== null) {
                    $values[] = $value;
                }
            }
            $values = array_values(array_unique($values, SORT_REGULAR));
            if ($values !== []) {
                return [$multiple ? $values : $values[0], ['selector' => $selector, 'method' => 'css_or_meta_selector']];
            }
        }
        return [$multiple ? [] : null, []];
    }

    /** @return list<array{value:string,is_url?:bool}> */
    private function querySelector(DOMXPath $xpath, string $selector): array
    {
        $selector = trim($selector);
        if ($selector === '') {
            return [];
        }
        $attr = null;
        if (str_contains($selector, '@')) {
            [$selector, $attr] = explode('@', $selector, 2);
            $selector = trim($selector);
            $attr = trim($attr);
        }
        $query = $this->selectorToXpath($selector);
        $rows = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $value = $attr ? $node->getAttribute($attr) : ($node->textContent ?: '');
            if ($value !== '') {
                $rows[] = ['value' => $value, 'is_url' => in_array($attr, ['href', 'src', 'content'], true) && preg_match('~^(href|src)$~', (string) $attr) === 1];
            }
        }
        return $rows;
    }

    private function selectorToXpath(string $selector): string
    {
        $selector = trim($selector);
        if (str_starts_with($selector, '//')) {
            return $selector;
        }
        if (preg_match('/^meta\[(name|property)=["\']([^"\']+)["\']\]$/i', $selector, $m) === 1) {
            return '//meta[@' . strtolower($m[1]) . '=' . $this->xpathLiteral($m[2]) . ']';
        }
        if (preg_match('/^a\[href\*=["\']([^"\']+)["\']\]$/i', $selector, $m) === 1) {
            return '//a[contains(@href, ' . $this->xpathLiteral($m[1]) . ')]';
        }
        if (preg_match('/^a\[href\$=["\']([^"\']+)["\']\]$/i', $selector, $m) === 1) {
            return '//a[substring(@href, string-length(@href) - string-length(' . $this->xpathLiteral($m[1]) . ') + 1) = ' . $this->xpathLiteral($m[1]) . ']';
        }
        if (preg_match('/^link\[rel=["\']([^"\']+)["\']\]$/i', $selector, $m) === 1) {
            return '//link[@rel=' . $this->xpathLiteral($m[1]) . ']';
        }
        if (preg_match('/^#([A-Za-z0-9_-]+)$/', $selector, $m) === 1) {
            return '//*[@id=' . $this->xpathLiteral($m[1]) . ']';
        }
        if (preg_match('/^\.([A-Za-z0-9_-]+)$/', $selector, $m) === 1) {
            return '//*[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[1] . ' ') . ')]';
        }
        if (preg_match('/^\.([A-Za-z0-9_-]+)\s+([a-z0-9]+)$/i', $selector, $m) === 1) {
            return '//*[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[1] . ' ') . ')]//' . strtolower($m[2]);
        }
        if (preg_match('/^([a-z0-9]+)\.([A-Za-z0-9_-]+)$/i', $selector, $m) === 1) {
            return '//' . strtolower($m[1]) . '[contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathLiteral(' ' . $m[2] . ' ') . ')]';
        }
        if (preg_match('/^[a-z0-9]+$/i', $selector) === 1) {
            return '//' . strtolower($selector);
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

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\R+/u', ' ', $value) ?? $value;
        return trim($value);
    }
}
