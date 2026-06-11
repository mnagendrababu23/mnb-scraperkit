<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class TransformationPipeline
{
    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function apply(array $record, PipelineOptions $options): array
    {
        $defaultTextFields = ['title', 'page_title', 'page_description', 'journal_name'];
        foreach ($defaultTextFields as $field) {
            if (isset($record[$field]) && is_string($record[$field])) {
                $record[$field] = $this->normalizeSpace($record[$field]);
            }
        }

        foreach ($record as $field => $value) {
            if (is_string($value)) {
                if ($this->looksLikeUrlField($field)) {
                    $record[$field] = $this->cleanUrl($value);
                } elseif ($this->looksLikeDateField($field)) {
                    $record[$field] = $this->normalizeDate($value) ?? $value;
                } elseif ($this->looksLikePriceField($field)) {
                    $record[$field] = $this->normalizePrice($value) ?? $value;
                }
            }
        }

        foreach ($options->transformations as $field => $ops) {
            if (!array_key_exists($field, $record)) {
                continue;
            }
            foreach ($ops as $op) {
                $record[$field] = $this->applyOp($record[$field], $op);
            }
        }

        foreach ($options->fieldMap as $from => $to) {
            if (array_key_exists($from, $record) && !array_key_exists($to, $record)) {
                $record[$to] = $record[$from];
            }
        }

        $record['fields'] = $this->fieldsFromRecord($record);
        return $record;
    }

    private function applyOp(mixed $value, string $op): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        return match (strtolower($op)) {
            'trim' => trim($value),
            'normalize_space', 'normalize-spaces', 'space' => $this->normalizeSpace($value),
            'lowercase', 'lower' => function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value),
            'uppercase', 'upper' => function_exists('mb_strtoupper') ? mb_strtoupper($value) : strtoupper($value),
            'strip_tags' => strip_tags($value),
            'clean_url', 'url' => $this->cleanUrl($value),
            'date_iso', 'date' => $this->normalizeDate($value) ?? $value,
            'price_number', 'price' => $this->normalizePrice($value) ?? $value,
            'identifier_upper' => strtoupper(str_replace(' ', '', $value)),
            default => $value,
        };
    }

    private function normalizeSpace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function cleanUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }
        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (str_starts_with(strtolower((string) $key), 'utm_') || in_array(strtolower((string) $key), ['fbclid', 'gclid', 'error', 'code'], true)) {
                    unset($query[$key]);
                }
            }
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) . '://' : 'https://';
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $newQuery = $query !== [] ? '?' . http_build_query($query) : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $scheme . $host . $port . $path . $newQuery . $fragment;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = $this->normalizeSpace($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return gmdate('Y-m-d', $timestamp);
    }

    private function normalizePrice(string $value): ?string
    {
        $value = str_replace([',', ' '], '', trim($value));
        if (preg_match('/[-+]?\d+(?:\.\d+)?/', $value, $m) !== 1) {
            return null;
        }
        return $m[0];
    }

    private function looksLikeUrlField(string $field): bool
    {
        return str_ends_with($field, '_url') || str_ends_with($field, '_link') || in_array($field, ['url', 'canonical'], true);
    }

    private function looksLikeDateField(string $field): bool
    {
        return str_contains($field, 'date') || str_contains($field, 'deadline');
    }

    private function looksLikePriceField(string $field): bool
    {
        return str_contains($field, 'price') || str_contains($field, 'fee') || str_contains($field, 'amount');
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function fieldsFromRecord(array $record): array
    {
        $skip = [
            'record_id', 'record_type', 'profile', 'preset', 'source_url', 'source_page_url', 'final_url', 'raw_final_url',
            'status_code', 'content_hash', 'failure_type', 'error', 'skipped', 'skip_reason', 'depth', 'response_time_ms',
            'redirect_count', 'detected_encoding', 'record_key', 'fields', 'source_trace', 'validation', 'quality_score', 'dedupe_key',
            '_quality_score', '_validation_status', '_validation_issues', '_dedupe_key', '_drop_reason',
        ];
        $fields = [];
        foreach ($record as $key => $value) {
            if (!in_array((string) $key, $skip, true) && $value !== null && $value !== '' && $value !== []) {
                $fields[(string) $key] = $value;
            }
        }
        return $fields;
    }
}
