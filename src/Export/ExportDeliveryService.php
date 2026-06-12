<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Export;

use Mnb\ScraperKit\Safety\UrlSafetyGuard;

final class ExportDeliveryService
{
    public const VERSION = '1.0.1';

    public function __construct(private readonly string $rootDir, private readonly ?UrlSafetyGuard $safetyGuard = null)
    {
    }

    /** @param array<string,mixed> $connector @param list<string> $paths @param array<string,mixed> $options @return array<string,mixed> */
    public function deliver(array $connector, array $paths, array $options = []): array
    {
        if (empty($connector['enabled'])) {
            throw new \RuntimeException('Export connector is disabled: ' . (string) ($connector['id'] ?? ''));
        }
        $allowed = array_values(array_map('strtolower', (array) ($connector['allowed_extensions'] ?? [])));
        $manifest = (new ExportManifestBuilder())->build($paths, $allowed);
        $id = $this->deliveryId((string) ($connector['id'] ?? 'export'));
        $baseDir = $this->rootDir . '/storage/export-deliveries/' . $id;
        $this->ensureDir($baseDir);

        $result = match ((string) ($connector['type'] ?? 'local')) {
            'webhook' => $this->deliverWebhook($connector, $manifest, $baseDir, (bool) ($options['send'] ?? false)),
            default => $this->deliverLocal($connector, $manifest, $baseDir),
        };

        $result['export_delivery_version'] = self::VERSION;
        $result['delivery_id'] = $id;
        $result['connector_id'] = (string) ($connector['id'] ?? '');
        $result['connector_type'] = (string) ($connector['type'] ?? 'local');
        $result['created_at'] = date(DATE_ATOM);
        $result['manifest'] = $manifest;
        file_put_contents($baseDir . '/delivery-result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $result;
    }

    /** @param array<string,mixed> $connector @param array<string,mixed> $manifest @return array<string,mixed> */
    private function deliverLocal(array $connector, array $manifest, string $baseDir): array
    {
        $target = (string) ($connector['target_dir'] ?? 'storage/export-deliveries/local_exports');
        if (!$this->isAbsolutePath($target)) {
            $target = $this->rootDir . '/' . ltrim($target, '/\\');
        }
        $target = rtrim($target, '/\\') . '/' . basename($baseDir);
        $this->ensureDir($target);
        $copied = [];
        foreach ((array) ($manifest['files'] ?? []) as $file) {
            if (!is_array($file) || !is_file((string) ($file['path'] ?? ''))) {
                continue;
            }
            $dest = $target . '/' . basename((string) $file['path']);
            copy((string) $file['path'], $dest);
            $copied[] = [
                'source' => (string) $file['path'],
                'destination' => $dest,
                'size_bytes' => filesize($dest) ?: 0,
                'sha256' => hash_file('sha256', $dest) ?: '',
            ];
        }
        file_put_contents($target . '/delivery-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return [
            'ok' => true,
            'sent' => false,
            'target_dir' => $target,
            'files_copied' => count($copied),
            'copied' => $copied,
        ];
    }

    /** @param array<string,mixed> $connector @param array<string,mixed> $manifest @return array<string,mixed> */
    private function deliverWebhook(array $connector, array $manifest, string $baseDir, bool $send): array
    {
        $endpoint = (string) ($connector['endpoint'] ?? '');
        if ($endpoint === '' || !preg_match('#^https?://#i', $endpoint)) {
            throw new \RuntimeException('Webhook connector endpoint must be http or https.');
        }
        if ($this->safetyGuard) {
            $this->safetyGuard->assertSafe($endpoint);
        }
        $payload = [
            'event' => 'mnb.export.delivery',
            'export_delivery_version' => self::VERSION,
            'connector_id' => (string) ($connector['id'] ?? ''),
            'generated_at' => date(DATE_ATOM),
            'manifest' => $manifest,
        ];
        $payloadFile = $baseDir . '/webhook-payload.json';
        file_put_contents($payloadFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $response = null;
        if ($send) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'timeout' => 10,
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($endpoint, false, $context);
            $response = [
                'body_preview' => is_string($raw) ? substr($raw, 0, 500) : null,
                'http_response_header' => isset($http_response_header) ? $http_response_header : [],
            ];
        }
        return [
            'ok' => true,
            'sent' => $send,
            'endpoint' => $endpoint,
            'payload_file' => $payloadFile,
            'response' => $response,
        ];
    }

    private function deliveryId(string $connectorId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $connectorId) ?: 'export';
        return 'delivery_' . date('Ymd_His') . '_' . $safe . '_' . bin2hex(random_bytes(3));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
