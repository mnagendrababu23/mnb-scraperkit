<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Api;

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;
use Mnb\ScraperKit\Source\Json\JsonUrlSourceReader;

final class ApiSourceReader
{
    public function __construct(private HttpClient $client)
    {
    }

    /** @param array<string,string> $headers @return array<string,mixed> */
    public function read(string $endpoint, CrawlOptions $options, ?string $path = null, int $limit = 10000, array $headers = []): array
    {
        $response = $this->client->get($endpoint, $options, array_merge(['Accept' => 'application/json, */*;q=0.5'], $headers));
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return [
                'source_type' => 'api',
                'source' => $endpoint,
                'final_url' => $response->finalUrl,
                'status_code' => $response->statusCode,
                'content_type' => $response->header('content-type'),
                'response_time_ms' => $response->responseTimeMs,
                'error' => $response->error ?: 'API response is not valid JSON.',
                'path' => $path,
                'records_returned' => 0,
                'records' => [],
            ];
        }

        $data = (new JsonUrlSourceReader())->readData($decoded, $path, $limit, 'api', $endpoint);
        $data['final_url'] = $response->finalUrl;
        $data['status_code'] = $response->statusCode;
        $data['content_type'] = $response->header('content-type');
        $data['response_time_ms'] = $response->responseTimeMs;
        $data['error'] = $response->error;
        return $data;
    }
}
