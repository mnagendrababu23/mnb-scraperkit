<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Webhook;

use Mnb\ScraperKit\Safety\UrlSafetyGuard;

/**
 * Minimal webhook dispatcher for job/report/plugin automation events.
 */
final class WebhookDispatcher
{
    public const VERSION = '3.0.0';

    public function __construct(private readonly ?UrlSafetyGuard $guard = null)
    {
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    public function send(string $url, string $event, array $payload = [], array $headers = [], int $timeoutSeconds = 10): array
    {
        $this->assertEndpoint($url);
        $body = json_encode([
            'event' => $event,
            'webhook_version' => self::VERSION,
            'sent_at' => date(DATE_ATOM),
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Unable to encode webhook payload.');
        }

        $headerLines = [
            'Content-Type: application/json',
            'User-Agent: MNB-ScraperKit-Webhook/' . self::VERSION,
        ];
        foreach ($headers as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9\-]+/', '', $name) ?: '';
            if ($name !== '') {
                $headerLines[] = $name . ': ' . str_replace(["\r", "\n"], '', $value);
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => max(1, $timeoutSeconds),
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $line, $m)) {
                $status = (int) $m[1];
                break;
            }
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'url' => $url,
            'event' => $event,
            'status_code' => $status,
            'response_bytes' => is_string($responseBody) ? strlen($responseBody) : 0,
            'response_preview' => is_string($responseBody) ? mb_substr($responseBody, 0, 500) : null,
        ];
    }

    /** @param array<string,mixed> $payload */
    public function writeLocalEvent(string $path, string $event, array $payload = []): array
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $entry = [
            'ok' => true,
            'webhook_version' => self::VERSION,
            'event' => $event,
            'sent_at' => date(DATE_ATOM),
            'payload' => $payload,
        ];
        file_put_contents($path, json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $entry + ['output' => $path];
    }

    private function assertEndpoint(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Webhook endpoint must use http or https.');
        }
        ($this->guard ?? new UrlSafetyGuard())->assertAllowed($url);
    }
}
