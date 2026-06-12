<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Evaluation;

final class SelectorPerformanceEvaluator
{
    public const VERSION = '4.2.1';

    /** @param array<int,array<string,mixed>> $records @param array<string,mixed> $profileSchema @return array<string,mixed> */
    public function evaluate(array $records, array $profileSchema, string $profileName = ''): array
    {
        $rules = is_array($profileSchema['extraction_rules'] ?? null) ? (array) $profileSchema['extraction_rules'] : [];
        if ($rules === [] && is_array($profileSchema['rules'] ?? null)) {
            $rules = (array) $profileSchema['rules'];
        }
        $fields = array_keys($rules);
        if ($fields === []) {
            $fields = array_values(array_unique(array_merge(
                array_map('strval', is_array($profileSchema['required_fields'] ?? null) ? $profileSchema['required_fields'] : []),
                array_map('strval', is_array($profileSchema['optional_fields'] ?? null) ? $profileSchema['optional_fields'] : [])
            )));
        }

        $rows = [];
        foreach ($fields as $field) {
            $success = 0;
            $empty = 0;
            $examples = [];
            foreach ($records as $record) {
                $recordFields = is_array($record['fields'] ?? null) ? $record['fields'] : $record;
                $has = isset($recordFields[$field]) && $recordFields[$field] !== '' && $recordFields[$field] !== null && $recordFields[$field] !== [];
                if ($has) {
                    $success++;
                } else {
                    $empty++;
                    if (count($examples) < 5) {
                        $examples[] = [
                            'record_id' => (string) ($record['dataset_record_id'] ?? $record['record_id'] ?? ''),
                            'source_url' => (string) ($record['source_url'] ?? $record['url'] ?? ''),
                        ];
                    }
                }
            }
            $rule = is_array($rules[$field] ?? null) ? (array) $rules[$field] : [];
            $rows[] = [
                'field' => (string) $field,
                'selector' => $this->selectorSummary($rule),
                'success_count' => $success,
                'empty_result_count' => $empty,
                'failure_count' => $empty,
                'fallback_used_count' => 0,
                'success_rate_percent' => count($records) > 0 ? round(($success / count($records)) * 100, 2) : 0.0,
                'example_failed_records' => $examples,
            ];
        }
        return [
            'selector_evaluation_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'profile' => $profileName !== '' ? $profileName : (string) ($profileSchema['profile'] ?? ''),
            'records_total' => count($records),
            'selectors_total' => count($rows),
            'selectors' => $rows,
        ];
    }

    /** @param array<string,mixed> $rule */
    private function selectorSummary(array $rule): string
    {
        foreach (['css', 'xpath', 'meta', 'opengraph', 'json_ld', 'attribute'] as $key) {
            if (isset($rule[$key])) {
                return $key . ':' . (is_scalar($rule[$key]) ? (string) $rule[$key] : json_encode($rule[$key]));
            }
        }
        if (isset($rule['fallback']) && is_array($rule['fallback'])) {
            return 'fallback:' . implode('|', array_map('strval', $rule['fallback']));
        }
        return $rule === [] ? 'field-presence' : (json_encode($rule, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'rule');
    }
}
