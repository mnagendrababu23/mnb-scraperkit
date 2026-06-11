<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Plos;

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;

final class PlosApiClient
{
    private PlosRecordNormalizer $normalizer;

    public function __construct(
        private HttpClient $client,
        private string $baseUrl = 'https://api.plos.org/search',
        ?PlosRecordNormalizer $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?: new PlosRecordNormalizer();
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function search(string $query, CrawlOptions $options, array $params = []): array
    {
        $fields = $params['fields'] ?? ['id', 'title_display', 'journal', 'publication_date', 'author_display', 'abstract', 'article_type', 'volume', 'issue'];
        if (is_string($fields)) {
            $fields = array_filter(array_map('trim', explode(',', $fields)));
        }
        if (!is_array($fields) || $fields === []) {
            $fields = ['id', 'title_display', 'journal', 'publication_date'];
        }

        $queryParams = [
            'q' => $query,
            'wt' => 'json',
            'rows' => max(0, (int) ($params['rows'] ?? 25)),
            'start' => max(0, (int) ($params['start'] ?? 0)),
            'fl' => implode(',', array_map('strval', $fields)),
        ];
        if (!empty($params['sort'])) {
            $queryParams['sort'] = (string) $params['sort'];
        }
        if (!empty($params['fq'])) {
            $queryParams['fq'] = (string) $params['fq'];
        }

        $requestUrl = $this->baseUrl . '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $response = $this->client->get($requestUrl, $options, ['Accept' => 'application/json']);
        $json = json_decode($response->body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('PLOS API did not return valid JSON. HTTP ' . $response->statusCode . ' ' . ($response->error ?? ''));
        }

        $docs = $json['response']['docs'] ?? [];
        if (!is_array($docs)) {
            $docs = [];
        }

        return [
            'source_type' => 'plos_api',
            'api_base_url' => $this->baseUrl,
            'request_url' => $requestUrl,
            'query' => $query,
            'status_code' => $response->statusCode,
            'response_time_ms' => $response->responseTimeMs,
            'error' => $response->error,
            'num_found' => (int) ($json['response']['numFound'] ?? 0),
            'start' => (int) ($json['response']['start'] ?? ($params['start'] ?? 0)),
            'rows_requested' => $queryParams['rows'],
            'records_returned' => count($docs),
            'records' => $this->normalizer->normalizeMany(array_values(array_filter($docs, 'is_array'))),
            'raw_response_header' => $json['responseHeader'] ?? null,
        ];
    }
}
