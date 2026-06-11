<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Rss;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;
use Mnb\ScraperKit\Core\ProtectionPageDetector;

final class RssFeedReader
{
    public function __construct(private HttpClient $client, private ProtectionPageDetector $detector = new ProtectionPageDetector())
    {
    }

    /** @return array<string,mixed> */
    public function read(string $feedUrl, CrawlOptions $options, int $limit = 25): array
    {
        $response = $this->client->get($feedUrl, $options, ['Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.5']);
        $title = $this->htmlTitle($response->body);
        $protection = $this->detector->detect($feedUrl, $response->finalUrl, $response->statusCode, $response->headers, $response->body, $title, trim(strip_tags($response->body)), [], [], $response->error);
        if (($protection['is_challenge'] ?? false) === true) {
            return [
                'source_type' => 'rss_feed',
                'feed_url' => $feedUrl,
                'final_url' => $response->finalUrl,
                'status_code' => $response->statusCode,
                'is_challenge' => true,
                'protection' => $protection,
                'records' => [],
                'records_returned' => 0,
            ];
        }

        $records = $this->parseXml($response->body, max(1, $limit));
        return [
            'source_type' => 'rss_feed',
            'feed_url' => $feedUrl,
            'final_url' => $response->finalUrl,
            'status_code' => $response->statusCode,
            'content_type' => $response->header('content-type'),
            'response_time_ms' => $response->responseTimeMs,
            'error' => $response->error,
            'records_returned' => count($records),
            'records' => $records,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function parseXml(string $xml, int $limit): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            return [];
        }
        $xp = new DOMXPath($dom);
        $nodes = $xp->query('//*[local-name()="item" or local-name()="entry"]');
        $records = [];
        if (!$nodes) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $records[] = $this->record($node, $xp);
            if (count($records) >= $limit) {
                break;
            }
        }
        return $records;
    }

    /** @return array<string,mixed> */
    private function record(DOMElement $node, DOMXPath $xp): array
    {
        $link = $this->firstText($xp, './*[local-name()="link"]', $node);
        $linkNode = $xp->query('./*[local-name()="link"]', $node)?->item(0);
        if ($linkNode instanceof DOMElement && $linkNode->hasAttribute('href')) {
            $link = $linkNode->getAttribute('href');
        }
        $title = $this->firstText($xp, './*[local-name()="title"]', $node);
        $summary = $this->firstText($xp, './*[local-name()="description" or local-name()="summary" or local-name()="content"]', $node);
        $published = $this->firstText($xp, './*[local-name()="pubDate" or local-name()="published" or local-name()="updated"]', $node);
        $guid = $this->firstText($xp, './*[local-name()="guid" or local-name()="id"]', $node);
        $authors = [];
        $authorNodes = $xp->query('.//*[local-name()="author" or local-name()="creator"]', $node);
        if ($authorNodes) {
            foreach ($authorNodes as $authorNode) {
                $v = trim(preg_replace('/\s+/', ' ', $authorNode->textContent));
                if ($v !== '') {
                    $authors[] = $v;
                }
            }
        }
        $doi = $this->doiFromText(implode(' ', array_filter([$guid, $link, $title, $summary])));
        return [
            'source_type' => 'rss_feed',
            'record_type' => 'article',
            'record_key' => $doi ?: ($guid ?: ($link ?: sha1(($title ?? '') . ($published ?? '')))),
            'title' => $title,
            'url' => $link,
            'doi' => $doi,
            'published_date' => $published,
            'authors' => array_values(array_unique($authors)),
            'authors_text' => implode(' | ', array_values(array_unique($authors))),
            'summary' => $summary,
            'guid' => $guid,
        ];
    }

    private function firstText(DOMXPath $xp, string $query, DOMElement $context): ?string
    {
        $nodes = $xp->query($query, $context);
        if (!$nodes || $nodes->length === 0) {
            return null;
        }
        $text = trim(html_entity_decode(strip_tags($nodes->item(0)?->textContent ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', $text);
        return $text === '' ? null : $text;
    }

    private function doiFromText(string $text): ?string
    {
        if (preg_match('~10\.1371/journal\.[a-z0-9.]+~i', $text, $m) === 1) {
            return rtrim($m[0], '.,;:)');
        }
        return null;
    }

    private function htmlTitle(string $body): ?string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $body, $m) !== 1) {
            return null;
        }
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
