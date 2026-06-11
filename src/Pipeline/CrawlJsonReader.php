<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class CrawlJsonReader
{
    /** @return array<string,mixed> */
    public function read(string $path): array
    {
        if (is_dir($path)) {
            $candidate = rtrim($path, '/\\') . '/crawl.json';
            if (is_file($candidate)) {
                $path = $candidate;
            }
        }
        if (!is_file($path)) {
            throw new \RuntimeException('Crawl JSON not found: ' . $path);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid crawl JSON: ' . $path);
        }
        return $data;
    }
}
