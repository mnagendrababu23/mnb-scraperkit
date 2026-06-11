<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Json;

final class JsonUrlSourceReader
{
    /** @return array<string,mixed> */
    public function read(string $file, ?string $path = null, int $limit = 10000): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('JSON source file not found: ' . $file);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON source file: ' . $file);
        }
        return $this->readData($data, $path, $limit, 'json', $file);
    }

    /** @param array<mixed> $data @return array<string,mixed> */
    public function readData(array $data, ?string $path = null, int $limit = 10000, string $sourceType = 'json', ?string $source = null): array
    {
        $values = $path ? $this->valuesByPath($data, $path) : $this->recursiveUrlValues($data);
        $records = [];
        $errors = [];
        foreach ($values as $idx => $value) {
            if (count($records) >= $limit) {
                break;
            }
            $url = is_array($value) ? (string) ($value['url'] ?? $value['link'] ?? $value['href'] ?? '') : (string) $value;
            $url = trim($url);
            if (!$this->looksLikeUrl($url)) {
                $errors[] = ['index' => $idx, 'message' => 'URL is missing or invalid.', 'value' => is_scalar($value) ? (string) $value : gettype($value)];
                continue;
            }
            $records[] = [
                'source_type' => $sourceType,
                'record_type' => 'url_source',
                'record_key' => $url,
                'url' => $url,
                'path' => $path,
                'metadata' => is_array($value) ? $value : [],
            ];
        }

        return [
            'source_type' => $sourceType,
            'source' => $source,
            'path' => $path,
            'records_returned' => count($records),
            'errors' => $errors,
            'records' => $records,
        ];
    }

    /** @param array<mixed> $data @return array<int,mixed> */
    private function valuesByPath(array $data, string $path): array
    {
        $segments = array_values(array_filter(explode('.', trim($path)), static fn (string $s): bool => $s !== ''));
        $current = [$data];
        foreach ($segments as $segment) {
            $next = [];
            foreach ($current as $item) {
                if ($segment === '*') {
                    if (is_array($item)) {
                        foreach ($item as $child) {
                            $next[] = $child;
                        }
                    }
                    continue;
                }
                if (is_array($item) && array_key_exists($segment, $item)) {
                    $next[] = $item[$segment];
                }
            }
            $current = $next;
        }
        return $current;
    }

    /** @param mixed $value @return array<int,string> */
    private function recursiveUrlValues(mixed $value): array
    {
        $out = [];
        if (is_string($value) && $this->looksLikeUrl($value)) {
            return [$value];
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                foreach ($this->recursiveUrlValues($child) as $url) {
                    $out[] = $url;
                }
            }
        }
        return $out;
    }

    private function looksLikeUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $value) === 1;
    }
}
