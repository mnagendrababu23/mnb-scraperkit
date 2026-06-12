<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

final class DataMappingRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $mappings = [];

    public function __construct(?string $path = null)
    {
        $path = $path ?: dirname(__DIR__, 2) . '/config/extraction/default-mappings.json';
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data['mappings'] ?? null)) {
                foreach ($data['mappings'] as $name => $map) {
                    if (is_array($map)) {
                        $this->mappings[(string) $name] = $map;
                    }
                }
            }
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return $this->mappings;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function mapRecord(array $record, string $mappingName): array
    {
        $map = $this->mappings[$mappingName] ?? [];
        $out = [];
        foreach ($map as $target => $definition) {
            $sources = [];
            $transforms = [];
            if (is_array($definition) && isset($definition['sources'])) {
                $sources = array_values(array_map('strval', (array) $definition['sources']));
                $transforms = array_values(array_map('strval', (array) ($definition['transform'] ?? $definition['transforms'] ?? [])));
            } else {
                $sources = array_values(array_map('strval', (array) $definition));
            }
            foreach ($sources as $source) {
                if (array_key_exists($source, $record) && $record[$source] !== null && $record[$source] !== '') {
                    $out[(string) $target] = self::applyTransforms($record[$source], $transforms);
                    break;
                }
            }
        }
        return $out + $record;
    }

    /** @param mixed $value @param array<int,string> $transforms @return mixed */
    public static function applyTransforms(mixed $value, array $transforms): mixed
    {
        foreach ($transforms as $transform) {
            $transform = strtolower(trim($transform));
            if (is_array($value)) {
                $value = array_map(static fn (mixed $item): mixed => self::applyTransforms($item, [$transform]), $value);
                if ($transform === 'dedupe_array') {
                    $value = array_values(array_unique(array_filter($value), SORT_REGULAR));
                }
                continue;
            }
            $text = (string) $value;
            $value = match ($transform) {
                'trim' => trim($text),
                'lowercase' => strtolower(trim($text)),
                'uppercase' => strtoupper(trim($text)),
                'normalize_space' => preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text),
                'strip_html' => trim(strip_tags($text)),
                'clean_url', 'remove_tracking_params' => self::cleanUrl($text),
                'normalize_doi' => self::normalizeDoi($text),
                'parse_date' => self::parseDate($text),
                default => $value,
            };
        }
        return $value;
    }

    private static function normalizeDoi(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $value) ?? $value;
        $value = preg_replace('~^doi:\s*~i', '', $value) ?? $value;
        return strtolower(trim($value));
    }

    private static function cleanUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $value;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (preg_match('/^(utm_|fbclid$|gclid$|mc_)/i', (string) $key) === 1) {
                    unset($query[$key]);
                }
            }
        }
        $url = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    private static function parseDate(string $value): string
    {
        $time = strtotime($value);
        return $time === false ? trim($value) : date('Y-m-d', $time);
    }
}
