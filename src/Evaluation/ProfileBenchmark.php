<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Evaluation;

final class ProfileBenchmark
{
    public const VERSION = '3.4.0';

    /** @param array<int,array<string,mixed>> $records @param array<string,mixed> $profileSchema @return array<string,mixed> */
    public function benchmark(array $records, array $profileSchema, string $profileName = ''): array
    {
        $required = array_values(array_map('strval', is_array($profileSchema['required_fields'] ?? null) ? $profileSchema['required_fields'] : []));
        $optional = array_values(array_map('strval', is_array($profileSchema['optional_fields'] ?? null) ? $profileSchema['optional_fields'] : []));
        $exportColumns = array_values(array_map('strval', is_array($profileSchema['export_columns'] ?? null) ? $profileSchema['export_columns'] : []));
        $fieldSet = array_values(array_unique(array_filter(array_merge($required, $optional, $exportColumns))));
        if ($fieldSet === []) {
            foreach ($records as $record) {
                foreach (array_keys(is_array($record['fields'] ?? null) ? $record['fields'] : $record) as $field) {
                    $fieldSet[] = (string) $field;
                }
            }
            $fieldSet = array_values(array_unique($fieldSet));
        }

        $matrix = [];
        foreach ($fieldSet as $field) {
            $present = 0;
            $examples = [];
            foreach ($records as $record) {
                $fields = is_array($record['fields'] ?? null) ? $record['fields'] : $record;
                $filled = isset($fields[$field]) && $fields[$field] !== '' && $fields[$field] !== null && $fields[$field] !== [];
                if ($filled) {
                    $present++;
                } elseif (count($examples) < 5) {
                    $examples[] = [
                        'record_id' => (string) ($record['dataset_record_id'] ?? $record['record_id'] ?? ''),
                        'source_url' => (string) ($record['source_url'] ?? $record['url'] ?? ''),
                    ];
                }
            }
            $total = count($records);
            $matrix[] = [
                'field' => $field,
                'required' => in_array($field, $required, true),
                'present' => $present,
                'missing' => max(0, $total - $present),
                'success_rate_percent' => $total > 0 ? round(($present / $total) * 100, 2) : 0.0,
                'example_missing_records' => $examples,
            ];
        }

        $requiredRates = array_values(array_map(static fn(array $r): float => $r['required'] ? (float) $r['success_rate_percent'] : -1.0, $matrix));
        $requiredRates = array_values(array_filter($requiredRates, static fn(float $v): bool => $v >= 0));
        $requiredAvg = $requiredRates === [] ? 100.0 : round(array_sum($requiredRates) / count($requiredRates), 2);

        return [
            'benchmark_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'profile' => $profileName !== '' ? $profileName : (string) ($profileSchema['profile'] ?? ''),
            'record_type' => (string) ($profileSchema['record_type'] ?? ''),
            'records_total' => count($records),
            'required_fields' => $required,
            'optional_fields' => $optional,
            'required_field_success_avg' => $requiredAvg,
            'field_success_matrix' => $matrix,
            'profile_grade' => $requiredAvg >= 90 ? 'excellent' : ($requiredAvg >= 75 ? 'good' : ($requiredAvg >= 50 ? 'needs_work' : 'poor')),
        ];
    }

    /** @param array<string,mixed> $oldEval @param array<string,mixed> $newEval @return array<string,mixed> */
    public function compareEvaluations(array $oldEval, array $newEval): array
    {
        $oldSummary = (array) ($oldEval['summary'] ?? []);
        $newSummary = (array) ($newEval['summary'] ?? []);
        $metrics = ['records_total', 'valid_records', 'invalid_records', 'duplicate_records', 'average_quality_score', 'training_readiness_score'];
        $changes = [];
        foreach ($metrics as $metric) {
            $old = (float) ($oldSummary[$metric] ?? 0);
            $new = (float) ($newSummary[$metric] ?? 0);
            $changes[$metric] = ['old' => $old, 'new' => $new, 'delta' => round($new - $old, 2)];
        }
        return [
            'benchmark_compare_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'changes' => $changes,
            'summary' => [
                'quality_delta' => $changes['average_quality_score']['delta'],
                'training_readiness_delta' => $changes['training_readiness_score']['delta'],
                'record_count_delta' => $changes['records_total']['delta'],
            ],
        ];
    }
}
