<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\RuleBuilder;

use DOMElement;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Parser\JsonLdExtractor;
use Mnb\ScraperKit\Support\UrlNormalizer;

/**
 * Extracts page signals used by the rule builder and auto-profile assistant.
 */
final class HtmlSignalAnalyzer
{
    /** @return array<string,mixed> */
    public function analyze(string $html, string $baseUrl = ''): array
    {
        $parser = new HtmlParser();
        $doc = $parser->load($html, $baseUrl);
        $text = $parser->text($doc);
        $lowerText = mb_strtolower($text);

        $meta = [];
        foreach ($doc->xpath->query('//meta[@name or @property]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $key = strtolower(trim($node->getAttribute('name') ?: $node->getAttribute('property')));
            $content = trim($node->getAttribute('content'));
            if ($key !== '') {
                $meta[$key] = $content;
            }
        }

        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            $values = [];
            foreach ($doc->xpath->query('//' . $tag) ?: [] as $node) {
                $value = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
                if ($value !== '') {
                    $values[] = mb_substr($value, 0, 160);
                }
            }
            $headings[$tag] = array_values(array_slice(array_unique($values), 0, 8));
        }

        $jsonLdTypes = [];
        foreach ((new JsonLdExtractor())->extract($doc) as $item) {
            foreach ($this->collectJsonLdTypes($item) as $type) {
                $jsonLdTypes[$type] = $type;
            }
        }

        $selectors = $this->candidateSelectors($doc);
        $keywords = $this->detectKeywords($lowerText, $meta, array_values($jsonLdTypes), $selectors);

        return [
            'signal_version' => '3.5.0',
            'base_url' => $baseUrl,
            'title' => $parser->title($doc),
            'canonical_url' => $parser->canonical($doc, $baseUrl),
            'text_length' => mb_strlen($text),
            'links_total' => count($parser->links($doc, $baseUrl)),
            'images_total' => count($parser->images($doc, $baseUrl)),
            'meta' => $meta,
            'headings' => $headings,
            'json_ld_types' => array_values($jsonLdTypes),
            'keywords' => $keywords,
            'candidate_selectors' => $selectors,
        ];
    }

    /** @return array<int,string> */
    private function collectJsonLdTypes(mixed $item): array
    {
        $out = [];
        if (is_array($item)) {
            if (isset($item['@type'])) {
                $types = is_array($item['@type']) ? $item['@type'] : [$item['@type']];
                foreach ($types as $type) {
                    $type = trim((string) $type);
                    if ($type !== '') {
                        $out[] = $type;
                    }
                }
            }
            foreach ($item as $value) {
                foreach ($this->collectJsonLdTypes($value) as $type) {
                    $out[] = $type;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function candidateSelectors($doc): array
    {
        $fields = [
            'title' => ['title', 'name', 'heading', 'product-title', 'job-title', 'tender-title', 'article-title'],
            'price' => ['price', 'amount', 'fee', 'cost', 'salary'],
            'sku' => ['sku', 'product-id', 'code', 'item-number'],
            'brand' => ['brand', 'manufacturer'],
            'availability' => ['availability', 'stock', 'in-stock'],
            'company' => ['company', 'employer', 'organization'],
            'location' => ['location', 'city', 'place'],
            'deadline' => ['deadline', 'last-date', 'due-date', 'closing-date'],
            'tender_number' => ['tender', 'notice', 'bid', 'reference'],
            'doi' => ['doi'],
            'authors' => ['author', 'authors', 'byline'],
            'journal' => ['journal', 'publication'],
            'description' => ['description', 'summary', 'abstract'],
        ];

        $out = [];
        foreach ($fields as $field => $needles) {
            $out[$field] = [];
        }

        foreach ($doc->xpath->query('//*[@class or @id or @itemprop or @name or @property]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $attrs = [
                'class' => $node->getAttribute('class'),
                'id' => $node->getAttribute('id'),
                'itemprop' => $node->getAttribute('itemprop'),
                'name' => $node->getAttribute('name'),
                'property' => $node->getAttribute('property'),
            ];
            $haystack = strtolower(implode(' ', $attrs));
            foreach ($fields as $field => $needles) {
                foreach ($needles as $needle) {
                    if ($needle !== '' && str_contains($haystack, $needle)) {
                        $selector = $this->selectorForNode($node, $attrs);
                        if ($selector !== null) {
                            $out[$field][] = [
                                'selector' => $selector,
                                'tag' => strtolower($node->tagName),
                                'sample' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $node->textContent)), 0, 140),
                            ];
                        }
                        break;
                    }
                }
            }
        }

        foreach ($out as $field => $items) {
            $seen = [];
            $clean = [];
            foreach ($items as $item) {
                $key = (string) ($item['selector'] ?? '');
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $clean[] = $item;
                if (count($clean) >= 8) {
                    break;
                }
            }
            $out[$field] = $clean;
        }
        return $out;
    }

    /** @param array<string,string> $attrs */
    private function selectorForNode(DOMElement $node, array $attrs): ?string
    {
        $tag = strtolower($node->tagName);
        if (($attrs['id'] ?? '') !== '') {
            return '#' . $this->safeSelectorPart($attrs['id']);
        }
        if (($attrs['itemprop'] ?? '') !== '') {
            return $tag . '[itemprop=' . $this->safeSelectorPart($attrs['itemprop']) . ']';
        }
        if (($attrs['class'] ?? '') !== '') {
            $classes = preg_split('/\s+/', trim($attrs['class'])) ?: [];
            foreach ($classes as $class) {
                $class = $this->safeSelectorPart($class);
                if ($class !== '') {
                    return $tag . '.' . $class;
                }
            }
        }
        if (($attrs['name'] ?? '') !== '') {
            return $tag . '[name=' . $this->safeSelectorPart($attrs['name']) . ']';
        }
        if (($attrs['property'] ?? '') !== '') {
            return $tag . '[property=' . $this->safeSelectorPart($attrs['property']) . ']';
        }
        return null;
    }

    private function safeSelectorPart(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_:-]+/', '-', trim($value)) ?? '';
    }

    /** @param array<string,string> $meta @param array<int,string> $types @param array<string,array<int,array<string,mixed>>> $selectors @return array<string,int> */
    private function detectKeywords(string $text, array $meta, array $types, array $selectors): array
    {
        $joined = $text . ' ' . strtolower(json_encode($meta + ['types' => $types, 'selectors' => $selectors], JSON_UNESCAPED_SLASHES));
        $groups = [
            'ecommerce' => ['product', 'price', 'sku', 'cart', 'checkout', 'availability', 'brand'],
            'jobs' => ['job', 'salary', 'apply', 'experience', 'skills', 'hiring', 'jobposting'],
            'tender' => ['tender', 'notice', 'bid', 'emd', 'procurement', 'deadline', 'corrigendum'],
            'academic' => ['doi', 'issn', 'citation_', 'journal', 'abstract', 'article', 'scholarlyarticle'],
            'seo' => ['description', 'canonical', 'robots', 'og:', 'twitter:', 'schema'],
        ];
        $scores = [];
        foreach ($groups as $group => $needles) {
            $score = 0;
            foreach ($needles as $needle) {
                $score += substr_count($joined, strtolower($needle));
            }
            $scores[$group] = $score;
        }
        arsort($scores);
        return $scores;
    }
}
