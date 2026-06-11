<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Profile;

/**
 * Immutable profile schema definition used by the extraction and pipeline layers.
 */
final class ProfileSchema
{
    /**
     * @param array<int,string> $requiredFields
     * @param array<int,string> $optionalFields
     * @param array<int,string> $dedupeKeys
     * @param array<string,string> $validators
     * @param array<string,array<int,string>> $transformations
     * @param array<string,string> $fieldMap
     * @param array<int,string> $exportColumns
     * @param array<string,mixed> $extractionRules
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $profile,
        public readonly string $recordType,
        public readonly array $requiredFields = [],
        public readonly array $optionalFields = [],
        public readonly array $dedupeKeys = ['record_key'],
        public readonly array $validators = [],
        public readonly array $transformations = [],
        public readonly array $fieldMap = [],
        public readonly array $exportColumns = [],
        public readonly array $extractionRules = [],
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $profile = self::string($data['profile'] ?? $data['name'] ?? 'page') ?: 'page';
        return new self(
            profile: $profile,
            recordType: self::string($data['record_type'] ?? $profile) ?: $profile,
            requiredFields: self::list($data['required_fields'] ?? []),
            optionalFields: self::list($data['optional_fields'] ?? []),
            dedupeKeys: self::list($data['dedupe_keys'] ?? $data['dedupe_key'] ?? ['record_key']) ?: ['record_key'],
            validators: self::stringMap($data['validators'] ?? []),
            transformations: self::transformations($data['transformations'] ?? []),
            fieldMap: self::stringMap($data['field_map'] ?? $data['field_mapping'] ?? []),
            exportColumns: self::list($data['export_columns'] ?? []),
            extractionRules: is_array($data['extraction_rules'] ?? null) ? $data['extraction_rules'] : (is_array($data['rules'] ?? null) ? $data['rules'] : []),
            raw: $data,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'record_type' => $this->recordType,
            'required_fields' => $this->requiredFields,
            'optional_fields' => $this->optionalFields,
            'dedupe_keys' => $this->dedupeKeys,
            'validators' => $this->validators,
            'transformations' => $this->transformations,
            'field_map' => $this->fieldMap,
            'export_columns' => $this->exportColumns,
            'extraction_rules' => $this->extractionRules,
        ];
    }

    /** @return array<string,mixed> */
    public function toPipelineArray(): array
    {
        return [
            'profile' => $this->profile,
            'record_type' => $this->recordType,
            'required_fields' => $this->requiredFields,
            'dedupe_keys' => $this->dedupeKeys,
            'validators' => $this->validators,
            'transformations' => $this->transformations,
            'field_map' => $this->fieldMap,
            'export_columns' => $this->exportColumns,
        ];
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** @return array<int,string> */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,|]/', $value) ?: [];
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

    /** @return array<string,string> */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $k = trim((string) $k);
            if ($k === '' || !is_scalar($v)) {
                continue;
            }
            $out[$k] = trim((string) $v);
        }
        return $out;
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
}
