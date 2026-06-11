<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Elsevier;

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;

final class ElsevierApiClient
{
    private ElsevierRecordNormalizer $normalizer;

    public function __construct(
        private HttpClient $client,
        private ?string $apiKey = null,
        private ?string $instToken = null,
        private string $searchBaseUrl = 'https://api.elsevier.com/content/search/sciencedirect',
        private string $articleBaseUrl = 'https://api.elsevier.com/content/article',
        private string $serialBaseUrl = 'https://api.elsevier.com/content/serial/title/issn',
        ?ElsevierRecordNormalizer $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?: new ElsevierRecordNormalizer();
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function search(string $query, CrawlOptions $options, array $params = []): array
    {
        $count = max(0, min(200, (int) ($params['rows'] ?? $params['count'] ?? 25)));
        $queryParams = [
            'query' => $query,
            'start' => max(0, (int) ($params['start'] ?? 0)),
            'count' => $count,
            'view' => (string) ($params['view'] ?? 'STANDARD'),
            'httpAccept' => 'application/json',
        ];
        foreach (['content', 'date', 'pub', 'tak', 'title', 'author', 'volume', 'issue'] as $optional) {
            if (!empty($params[$optional])) {
                $queryParams[$optional] = (string) $params[$optional];
            }
        }
        if (!empty($params['sort'])) {
            $queryParams['sort'] = (string) $params['sort'];
        }
        if (!empty($params['api_key_in_query']) && $this->apiKey) {
            $queryParams['apiKey'] = $this->apiKey;
        }

        $requestUrl = $this->searchBaseUrl . '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $response = $this->client->get($requestUrl, $options, $this->headers('application/json'));
        $json = $this->decodeJson($response->body, 'ScienceDirect Search API', $response->statusCode, $response->error);
        $results = is_array($json['search-results'] ?? null) ? $json['search-results'] : [];
        $entries = is_array($results['entry'] ?? null) ? $results['entry'] : [];

        return [
            'source_type' => 'elsevier_sciencedirect_search_api',
            'api_base_url' => $this->searchBaseUrl,
            'request_url' => $this->maskUrl($requestUrl),
            'query' => $query,
            'status_code' => $response->statusCode,
            'response_time_ms' => $response->responseTimeMs,
            'error' => $response->error,
            'num_found' => (int) ($results['opensearch:totalResults'] ?? 0),
            'start' => (int) ($results['opensearch:startIndex'] ?? ($params['start'] ?? 0)),
            'rows_requested' => $count,
            'records_returned' => count($entries),
            'records' => $this->normalizer->normalizeSearchMany(array_values(array_filter($entries, 'is_array'))),
            'raw_links' => $results['link'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    public function article(string $identifier, string $type, CrawlOptions $options, array $params = []): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['doi', 'pii'], true)) {
            throw new \InvalidArgumentException('Elsevier article identifier type must be doi or pii.');
        }
        $queryParams = [
            'httpAccept' => 'application/json',
        ];
        if (!empty($params['view'])) {
            $queryParams['view'] = (string) $params['view'];
        }
        if (!empty($params['api_key_in_query']) && $this->apiKey) {
            $queryParams['apiKey'] = $this->apiKey;
        }
        $requestUrl = rtrim($this->articleBaseUrl, '/') . '/' . $type . '/' . rawurlencode($identifier) . '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $response = $this->client->get($requestUrl, $options, $this->headers('application/json'));
        $json = $this->decodeJson($response->body, 'ScienceDirect Article API', $response->statusCode, $response->error);
        $record = $this->normalizer->normalizeArticleResponse($json, $identifier, $type);

        return [
            'source_type' => 'elsevier_article_api',
            'request_url' => $this->maskUrl($requestUrl),
            'identifier' => $identifier,
            'identifier_type' => $type,
            'status_code' => $response->statusCode,
            'response_time_ms' => $response->responseTimeMs,
            'error' => $response->error,
            'records_returned' => $record['title'] ? 1 : 0,
            'records' => [$record],
            'raw_response_keys' => array_keys($json),
        ];
    }

    /** @return array<string,mixed> */
    public function serialByIssn(string $issn, CrawlOptions $options, array $params = []): array
    {
        $clean = preg_replace('~[^0-9Xx]~', '', $issn) ?: $issn;
        $queryParams = ['httpAccept' => 'application/json'];
        if (!empty($params['api_key_in_query']) && $this->apiKey) {
            $queryParams['apiKey'] = $this->apiKey;
        }
        $requestUrl = rtrim($this->serialBaseUrl, '/') . '/' . rawurlencode($clean) . '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $response = $this->client->get($requestUrl, $options, $this->headers('application/json'));
        $json = $this->decodeJson($response->body, 'Elsevier Serial Title API', $response->statusCode, $response->error);
        $record = $this->normalizer->normalizeSerialResponse($json, $issn);

        return [
            'source_type' => 'elsevier_serial_title_api',
            'request_url' => $this->maskUrl($requestUrl),
            'issn' => $issn,
            'status_code' => $response->statusCode,
            'response_time_ms' => $response->responseTimeMs,
            'error' => $response->error,
            'records_returned' => $record['title'] ? 1 : 0,
            'records' => [$record],
            'raw_response_keys' => array_keys($json),
        ];
    }

    /** @return array<string,string> */
    private function headers(string $accept): array
    {
        $headers = [
            'Accept' => $accept,
        ];
        if ($this->apiKey) {
            $headers['X-ELS-APIKey'] = $this->apiKey;
        }
        if ($this->instToken) {
            $headers['X-ELS-Insttoken'] = $this->instToken;
        }
        return $headers;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $body, string $label, int $statusCode, ?string $error): array
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $small = trim(substr(strip_tags($body), 0, 300));
            throw new \RuntimeException($label . ' did not return valid JSON. HTTP ' . $statusCode . ($error ? ' ' . $error : '') . ($small !== '' ? ' Body: ' . $small : ''));
        }
        return $json;
    }

    private function maskUrl(string $url): string
    {
        return preg_replace('~(apiKey=)[^&]+~i', '$1***', $url) ?? $url;
    }
}
