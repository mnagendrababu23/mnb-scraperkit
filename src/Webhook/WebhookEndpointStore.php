<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Webhook;

final class WebhookEndpointStore
{
    public function __construct(private readonly string $rootDir)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(?string $path = null): array
    {
        $file = $path ?: $this->rootDir . '/config/webhooks.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid webhook config JSON: ' . $file);
        }
        $items = $data['endpoints'] ?? $data;
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
