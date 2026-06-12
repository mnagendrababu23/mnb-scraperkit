<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Evaluation;

/**
 * Evaluates normalized dataset records for quality, completeness, duplicates,
 * annotation coverage, and training readiness.
 */
final class DatasetEvaluator
{
    public const VERSION = '1.0.1';

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $manifest
     * @param array<string,mixed>|null $annotations
     * @return array<string,mixed>
     */
    public function evaluate(array $records, array $manifest = [], ?array $annotations = null, ?array $profileSchema = null): array
    {
        $total = count($records);
        $valid = 0;
        $invalid = 0;
        $warnings = 0;
        $partial = 0;
        $qualityTotal = 0.0;
        $lowQuality = [];
        $dedupe = [];
        $duplicates = [];
        $fieldStats = [];
        $requiredFields = $this->requiredFields($profileSchema);
        $missingRequired = [];

        foreach ($records as $record) {
            $status = strtolower((string) ($record['validation_status'] ?? ($record['validation']['status'] ?? 'unknown')));
            if (in_array($status, ['valid', 'ok', 'passed'], true)) {
                $valid++;
            } elseif (in_array($status, ['invalid', 'failed', 'error'], true)) {
                $invalid++;
            } elseif (in_array($status, ['partial'], true)) {
                $partial++;
            } else {
                $warnings++;
            }

            $quality = (float) ($record['quality_score'] ?? 0);
            $qualityTotal += $quality;
            if ($quality < 50) {
                $lowQuality[] = $this->recordRef($record) + ['quality_score' => round($quality, 2), 'validation_status' => $status];
            }

            $key = (string) ($record['dedupe_key'] ?? $record['dataset_record_id'] ?? '');
            if ($key !== '') {
                if (isset($dedupe[$key])) {
                    $duplicates[] = $this->recordRef($record) + ['dedupe_key' => $key, 'duplicate_of' => $dedupe[$key]];
                } else {
                    $dedupe[$key] = (string) ($record['dataset_record_id'] ?? $key);
                }
            }

            $fields = $this->fields($record);
            foreach ($fields as $field => $value) {
                if (!isset($fieldStats[$field])) {
                    $fieldStats[$field] = ['field' => $field, 'present' => 0, 'empty' => 0, 'invalid' => 0, 'warnings' => 0, 'examples_missing' => []];
                }
                if ($this->isFilled($value)) {
                    $fieldStats[$field]['present']++;
                } else {
                    $fieldStats[$field]['empty']++;
                    if (count($fieldStats[$field]['examples_missing']) < 5) {
                        $fieldStats[$field]['examples_missing'][] = $this->recordRef($record);
                    }
                }
            }

            foreach ($requiredFields as $field) {
                if (!isset($fieldStats[$field])) {
                    $fieldStats[$field] = ['field' => $field, 'present' => 0, 'empty' => 0, 'invalid' => 0, 'warnings' => 0, 'examples_missing' => []];
                }
                if (!$this->isFilled($fields[$field] ?? null)) {
                    if (!array_key_exists($field, $fields)) {
                        $fieldStats[$field]['empty']++;
                    }
                    $missingRequired[$field] = ($missingRequired[$field] ?? 0) + 1;
                    if (count($fieldStats[$field]['examples_missing']) < 5) {
                        $fieldStats[$field]['examples_missing'][] = $this->recordRef($record);
                    }
                }
            }
        }

        $fieldMatrix = array_values(array_map(static function (array $row) use ($total): array {
            $present = (int) $row['present'];
            $empty = (int) $row['empty'];
            $denominator = max(1, $total);
            $row['completeness_percent'] = round(($present / $denominator) * 100, 2);
            $row['missing_percent'] = round(($empty / $denominator) * 100, 2);
            return $row;
        }, $fieldStats));
        usort($fieldMatrix, static fn(array $a, array $b): int => strcmp((string) $a['field'], (string) $b['field']));

        $annotationStats = $this->annotationStats($annotations, $records);
        $avgQuality = $total > 0 ? round($qualityTotal / $total, 2) : 0.0;
        $requiredCompleteness = $this->requiredCompleteness($fieldMatrix, $requiredFields);
        $trainingScore = $this->trainingReadinessScore($total, $avgQuality, $valid, count($duplicates), $requiredCompleteness, (float) ($annotationStats['coverage_percent'] ?? 0));

        return [
            'evaluation_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'dataset_id' => (string) ($manifest['dataset_id'] ?? ''),
            'source_type' => (string) ($manifest['source_type'] ?? ''),
            'summary' => [
                'records_total' => $total,
                'valid_records' => $valid,
                'warning_records' => $warnings,
                'partial_records' => $partial,
                'invalid_records' => $invalid,
                'duplicate_records' => count($duplicates),
                'unique_dedupe_keys' => count($dedupe),
                'low_quality_records' => count($lowQuality),
                'average_quality_score' => $avgQuality,
                'required_field_completeness_percent' => $requiredCompleteness,
                'annotation_coverage_percent' => (float) ($annotationStats['coverage_percent'] ?? 0),
                'training_readiness_score' => $trainingScore,
                'training_readiness_label' => $this->readinessLabel($trainingScore),
            ],
            'field_quality_matrix' => $fieldMatrix,
            'missing_required_fields' => $missingRequired,
            'duplicates' => array_slice($duplicates, 0, 200),
            'low_quality_examples' => array_slice($lowQuality, 0, 200),
            'annotation_stats' => $annotationStats,
            'recommended_actions' => $this->recommendedActions($total, $avgQuality, count($duplicates), $requiredFields, $requiredCompleteness, (float) ($annotationStats['coverage_percent'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function fields(array $record): array
    {
        return is_array($record['fields'] ?? null) ? (array) $record['fields'] : $record;
    }

    private function isFilled(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_array($value)) {
            return count(array_filter($value, fn(mixed $v): bool => $this->isFilled($v))) > 0;
        }
        return true;
    }

    /** @param array<string,mixed>|null $profileSchema @return list<string> */
    private function requiredFields(?array $profileSchema): array
    {
        $fields = $profileSchema['required_fields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }
        return array_values(array_unique(array_map('strval', $fields)));
    }

    /** @param array<int,array<string,mixed>> $fieldMatrix @param list<string> $requiredFields */
    private function requiredCompleteness(array $fieldMatrix, array $requiredFields): float
    {
        if ($requiredFields === []) {
            return 100.0;
        }
        $byField = [];
        foreach ($fieldMatrix as $row) {
            $byField[(string) $row['field']] = (float) ($row['completeness_percent'] ?? 0);
        }
        $total = 0.0;
        foreach ($requiredFields as $field) {
            $total += $byField[$field] ?? 0.0;
        }
        return round($total / count($requiredFields), 2);
    }

    /** @param array<string,mixed>|null $annotations @param array<int,array<string,mixed>> $records @return array<string,mixed> */
    private function annotationStats(?array $annotations, array $records): array
    {
        $items = is_array($annotations['annotations'] ?? null) ? (array) $annotations['annotations'] : [];
        $recordIds = [];
        foreach ($records as $record) {
            $id = (string) ($record['dataset_record_id'] ?? $record['record_id'] ?? '');
            if ($id !== '') {
                $recordIds[$id] = true;
            }
        }
        $labels = [];
        $annotated = [];
        $fieldAnnotations = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = (string) ($item['label'] ?? 'unlabeled');
            $labels[$label] = ($labels[$label] ?? 0) + 1;
            $rid = (string) ($item['record_id'] ?? '');
            if ($rid !== '') {
                $annotated[$rid] = true;
            }
            if ((string) ($item['field'] ?? '') !== '') {
                $fieldAnnotations++;
            }
        }
        $totalRecords = count($recordIds) ?: count($records);
        return [
            'annotations_total' => count($items),
            'annotated_records' => count($annotated),
            'coverage_percent' => $totalRecords > 0 ? round((count($annotated) / $totalRecords) * 100, 2) : 0.0,
            'label_counts' => $labels,
            'field_annotations' => $fieldAnnotations,
        ];
    }

    private function trainingReadinessScore(int $total, float $avgQuality, int $valid, int $duplicates, float $requiredCompleteness, float $annotationCoverage): int
    {
        if ($total === 0) {
            return 0;
        }
        $validRate = ($valid / max(1, $total)) * 100;
        $dupPenalty = min(20, ($duplicates / max(1, $total)) * 100);
        $score = ($avgQuality * 0.35) + ($validRate * 0.2) + ($requiredCompleteness * 0.25) + (min(100, $annotationCoverage) * 0.1) + 10 - $dupPenalty;
        return (int) max(0, min(100, round($score)));
    }

    private function readinessLabel(int $score): string
    {
        return $score >= 85 ? 'ready' : ($score >= 70 ? 'mostly_ready' : ($score >= 50 ? 'needs_review' : 'not_ready'));
    }

    /** @return array<string,string> */
    private function recordRef(array $record): array
    {
        return [
            'record_id' => (string) ($record['dataset_record_id'] ?? $record['record_id'] ?? ''),
            'source_url' => (string) ($record['source_url'] ?? $record['url'] ?? ''),
        ];
    }

    /** @param list<string> $requiredFields @return list<string> */
    private function recommendedActions(int $total, float $avgQuality, int $duplicates, array $requiredFields, float $requiredCompleteness, float $annotationCoverage): array
    {
        $actions = [];
        if ($total === 0) {
            $actions[] = 'Add records before evaluating dataset quality.';
        }
        if ($avgQuality < 70) {
            $actions[] = 'Review low-quality records and improve extraction rules or validation settings.';
        }
        if ($duplicates > 0) {
            $actions[] = 'Review duplicate records and strengthen dedupe keys.';
        }
        if ($requiredFields !== [] && $requiredCompleteness < 90) {
            $actions[] = 'Improve selectors or source coverage for required fields: ' . implode(', ', $requiredFields);
        }
        if ($annotationCoverage < 20) {
            $actions[] = 'Add more annotations before using this dataset for supervised ML workflows.';
        }
        if ($actions === []) {
            $actions[] = 'Dataset quality looks healthy for review, export, or training-preparation workflows.';
        }
        return $actions;
    }
}
