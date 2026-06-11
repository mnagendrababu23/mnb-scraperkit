<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class PipelineOptions
{
    /**
     * @param array<int,string> $requiredFields
     * @param array<int,string> $dedupeKeys
     * @param array<string,array<int,string>> $transformations
     * @param array<string,string> $fieldMap
     * @param array<string,string> $validators
     * @param array<int,string> $exportColumns
     */
    public function __construct(
        public array $requiredFields = [],
        public array $dedupeKeys = ['record_key'],
        public int $minQuality = 0,
        public bool $includeFailedPages = false,
        public bool $includeSkippedPages = false,
        public bool $preferPresetRecords = true,
        public array $transformations = [],
        public string $profile = 'page',
        public ?string $preset = null,
        public array $fieldMap = [],
        public array $validators = [],
        public array $exportColumns = [],
        public ?string $recordType = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            requiredFields: self::list($data['required_fields'] ?? []),
            dedupeKeys: self::list($data['dedupe_keys'] ?? ($data['dedupe_key'] ?? ['record_key'])) ?: ['record_key'],
            minQuality: max(0, min(100, (int) ($data['min_quality'] ?? 0))),
            includeFailedPages: (bool) ($data['include_failed_pages'] ?? false),
            includeSkippedPages: (bool) ($data['include_skipped_pages'] ?? false),
            preferPresetRecords: (bool) ($data['prefer_preset_records'] ?? true),
            transformations: self::transformations($data['transformations'] ?? []),
            profile: trim((string) ($data['profile'] ?? $data['common_data_profile'] ?? 'page')) ?: 'page',
            preset: isset($data['preset']) && trim((string) $data['preset']) !== '' ? trim((string) $data['preset']) : null,
            fieldMap: self::fieldMap($data['field_map'] ?? $data['field_mapping'] ?? []),
            validators: self::validators($data['validators'] ?? []),
            exportColumns: self::list($data['export_columns'] ?? []),
            recordType: isset($data['record_type']) && trim((string) $data['record_type']) !== '' ? trim((string) $data['record_type']) : null,
        );
    }

    /** @return array<int,string> */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', preg_split('/[,|]/', $value) ?: []));
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<string,array<int,string>> */
    private static function transformations(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $field => $ops) {
            $field = trim((string) $field);
            if ($field === '') {
                continue;
            }
            $out[$field] = self::list($ops);
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function fieldMap(mixed $value): array
    {
        if (is_string($value)) {
            $items = array_filter(array_map('trim', explode(',', $value)));
            $value = [];
            foreach ($items as $item) {
                if (str_contains($item, ':')) {
                    [$from, $to] = explode(':', $item, 2);
                    $value[trim($from)] = trim($to);
                }
            }
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $from => $to) {
            $from = trim((string) $from);
            $to = trim((string) $to);
            if ($from !== '' && $to !== '') {
                $out[$from] = $to;
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function validators(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $field => $rule) {
            $field = trim((string) $field);
            $rule = strtolower(trim((string) $rule));
            if ($field !== '' && $rule !== '') {
                $out[$field] = $rule;
            }
        }
        return $out;
    }
}
