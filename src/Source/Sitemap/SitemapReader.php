<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Sitemap;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;

final class SitemapReader
{
    /** @var array<string,bool> */
    private array $visited = [];

    public function __construct(private ?HttpClient $client = null)
    {
    }

    /** @return array<string,mixed> */
    public function read(string $source, CrawlOptions $options, int $limit = 1000, int $maxSitemaps = 50): array
    {
        $this->visited = [];
        $records = [];
        $sitemaps = [];
        $errors = [];
        $this->readInto($source, $options, max(1, $limit), max(1, $maxSitemaps), $records, $sitemaps, $errors, null);

        return [
            'source_type' => 'sitemap',
            'source' => $source,
            'records_returned' => count($records),
            'sitemaps_checked' => count($sitemaps),
            'sitemaps' => $sitemaps,
            'errors' => $errors,
            'records' => array_values($records),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<int,array<string,mixed>> $sitemaps
     * @param array<int,array<string,mixed>> $errors
     */
    private function readInto(string $source, CrawlOptions $options, int $limit, int $maxSitemaps, array &$records, array &$sitemaps, array &$errors, ?string $parent): void
    {
        if (count($records) >= $limit || count($sitemaps) >= $maxSitemaps) {
            return;
        }

        $key = $this->normalizeSourceKey($source);
        if (isset($this->visited[$key])) {
            return;
        }
        $this->visited[$key] = true;

        [$xml, $meta] = $this->load($source, $options);
        $sitemaps[] = array_merge($meta, ['parent' => $parent]);
        if ($xml === '') {
            $errors[] = ['source' => $source, 'message' => $meta['error'] ?? 'Empty sitemap response.'];
            return;
        }

        if (!class_exists(DOMDocument::class)) {
            $this->readWithRegexFallback($xml, $source, $options, $limit, $maxSitemaps, $records, $sitemaps, $errors);
            return;
        }

        $dom = $this->parseXml($xml);
        if (!$dom) {
            $errors[] = ['source' => $source, 'message' => 'Invalid sitemap XML.'];
            return;
        }

        $xp = new DOMXPath($dom);
        $urlNodes = $xp->query('//*[local-name()="url"]');
        if ($urlNodes && $urlNodes->length > 0) {
            foreach ($urlNodes as $node) {
                if (!$node instanceof DOMElement || count($records) >= $limit) {
                    continue;
                }
                $loc = $this->firstText($xp, './*[local-name()="loc"]', $node);
                if (!$loc) {
                    continue;
                }
                $records[] = [
                    'source_type' => 'sitemap',
                    'record_type' => 'url_source',
                    'record_key' => $loc,
                    'url' => $loc,
                    'loc' => $loc,
                    'lastmod' => $this->firstText($xp, './*[local-name()="lastmod"]', $node),
                    'changefreq' => $this->firstText($xp, './*[local-name()="changefreq"]', $node),
                    'priority' => $this->firstText($xp, './*[local-name()="priority"]', $node),
                    'source_url' => $source,
                ];
            }
            return;
        }

        $sitemapNodes = $xp->query('//*[local-name()="sitemap"]');
        if ($sitemapNodes && $sitemapNodes->length > 0) {
            foreach ($sitemapNodes as $node) {
                if (!$node instanceof DOMElement || count($records) >= $limit || count($sitemaps) >= $maxSitemaps) {
                    continue;
                }
                $loc = $this->firstText($xp, './*[local-name()="loc"]', $node);
                if ($loc) {
                    $this->readInto($loc, $options, $limit, $maxSitemaps, $records, $sitemaps, $errors, $source);
                }
            }
            return;
        }

        $errors[] = ['source' => $source, 'message' => 'XML does not contain urlset or sitemapindex entries.'];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function load(string $source, CrawlOptions $options): array
    {
        if ($this->isHttpUrl($source)) {
            if (!$this->client) {
                return ['', ['source' => $source, 'error' => 'No HTTP client is available for remote sitemap source.']];
            }
            $response = $this->client->get($source, $options, ['Accept' => 'application/xml, text/xml, application/rss+xml;q=0.9, */*;q=0.5']);
            return [$response->body, [
                'source' => $source,
                'final_url' => $response->finalUrl,
                'status_code' => $response->statusCode,
                'content_type' => $response->header('content-type'),
                'response_time_ms' => $response->responseTimeMs,
                'error' => $response->error,
            ]];
        }

        if (!is_file($source)) {
            return ['', ['source' => $source, 'error' => 'Local sitemap file not found.']];
        }

        return [(string) file_get_contents($source), [
            'source' => $source,
            'final_url' => null,
            'status_code' => null,
            'content_type' => 'local/xml',
            'response_time_ms' => 0,
            'error' => null,
        ]];
    }


    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<int,array<string,mixed>> $sitemaps
     * @param array<int,array<string,mixed>> $errors
     */
    private function readWithRegexFallback(string $xml, string $source, CrawlOptions $options, int $limit, int $maxSitemaps, array &$records, array &$sitemaps, array &$errors): void
    {
        if (preg_match_all('~<url\b[^>]*>(.*?)</url>~is', $xml, $urlBlocks) > 0) {
            foreach ($urlBlocks[1] as $block) {
                if (count($records) >= $limit) {
                    break;
                }
                $loc = $this->tagText($block, 'loc');
                if (!$loc) {
                    continue;
                }
                $records[] = [
                    'source_type' => 'sitemap',
                    'record_type' => 'url_source',
                    'record_key' => $loc,
                    'url' => $loc,
                    'loc' => $loc,
                    'lastmod' => $this->tagText($block, 'lastmod'),
                    'changefreq' => $this->tagText($block, 'changefreq'),
                    'priority' => $this->tagText($block, 'priority'),
                    'source_url' => $source,
                ];
            }
            return;
        }

        if (preg_match_all('~<sitemap\b[^>]*>(.*?)</sitemap>~is', $xml, $sitemapBlocks) > 0) {
            foreach ($sitemapBlocks[1] as $block) {
                if (count($records) >= $limit || count($sitemaps) >= $maxSitemaps) {
                    break;
                }
                $loc = $this->tagText($block, 'loc');
                if ($loc) {
                    $this->readInto($loc, $options, $limit, $maxSitemaps, $records, $sitemaps, $errors, $source);
                }
            }
            return;
        }

        $errors[] = ['source' => $source, 'message' => 'Unable to parse sitemap XML without ext-dom.'];
    }

    private function tagText(string $xml, string $tag): ?string
    {
        if (preg_match('~<' . preg_quote($tag, '~') . '\b[^>]*>(.*?)</' . preg_quote($tag, '~') . '>~is', $xml, $m) !== 1) {
            return null;
        }
        $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', $text);
        return $text === '' ? null : $text;
    }

    private function parseXml(string $xml): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadXML(trim($xml), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private function firstText(DOMXPath $xp, string $query, DOMElement $context): ?string
    {
        $nodes = $xp->query($query, $context);
        if (!$nodes || $nodes->length === 0) {
            return null;
        }
        $text = trim(html_entity_decode($nodes->item(0)?->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', $text);
        return $text === '' ? null : $text;
    }

    private function normalizeSourceKey(string $source): string
    {
        return strtolower(trim($source));
    }

    private function isHttpUrl(string $source): bool
    {
        return preg_match('~^https?://~i', $source) === 1;
    }
}
