<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Profile;

final class ProfileSchemaValidator
{
    /** @return array<int,array{field:string,rule:string,message:string}> */
    public function validateArray(array $data): array
    {
        $issues = [];
        foreach (['profile', 'record_type'] as $field) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                $issues[] = ['field' => $field, 'rule' => 'required', 'message' => $field . ' is required.'];
            }
        }
        foreach (['required_fields', 'optional_fields', 'dedupe_keys', 'export_columns'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                $issues[] = ['field' => $field, 'rule' => 'array', 'message' => $field . ' must be an array.'];
            }
        }
        foreach (['validators', 'transformations', 'field_map', 'extraction_rules'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                $issues[] = ['field' => $field, 'rule' => 'object', 'message' => $field . ' must be an object/map.'];
            }
        }
        if (isset($data['validators']) && is_array($data['validators'])) {
            $valid = ['required', 'url', 'email', 'phone', 'doi', 'issn', 'isbn', 'date', 'price', 'text', 'number'];
            foreach ($data['validators'] as $field => $rule) {
                $rule = strtolower((string) $rule);
                if (!in_array($rule, $valid, true)) {
                    $issues[] = ['field' => 'validators.' . $field, 'rule' => 'known_validator', 'message' => 'Unknown validator: ' . $rule];
                }
            }
        }
        if (isset($data['extraction_rules']) && is_array($data['extraction_rules'])) {
            foreach ($data['extraction_rules'] as $field => $rule) {
                if (!is_string($rule) && !is_array($rule)) {
                    $issues[] = ['field' => 'extraction_rules.' . $field, 'rule' => 'rule_shape', 'message' => 'Extraction rule must be a string or object.'];
                }
            }
        }
        return $issues;
    }

    /** @return array{valid:bool,issues:array<int,array{field:string,rule:string,message:string}>,schema?:array<string,mixed>} */
    public function validateFile(string $file): array
    {
        if (!is_file($file)) {
            return ['valid' => false, 'issues' => [['field' => 'file', 'rule' => 'exists', 'message' => 'File not found.']]];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return ['valid' => false, 'issues' => [['field' => 'json', 'rule' => 'parse', 'message' => 'File must contain a JSON object.']]];
        }
        $issues = $this->validateArray($data);
        return ['valid' => $issues === [], 'issues' => $issues, 'schema' => ProfileSchema::fromArray($data)->toArray()];
    }
}
