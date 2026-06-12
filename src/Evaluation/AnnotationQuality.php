<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Evaluation;

final class AnnotationQuality
{
    public const VERSION = '4.3.0';

    /** @param array<int,array<string,mixed>> $records @param array<string,mixed> $annotations @return array<string,mixed> */
    public function stats(array $records, array $annotations): array
    {
        $items = is_array($annotations['annotations'] ?? null) ? (array) $annotations['annotations'] : [];
        $labelCounts = [];
        $fieldCounts = [];
        $annotated = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = (string) ($item['label'] ?? 'unlabeled');
            $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;
            $field = (string) ($item['field'] ?? 'record');
            $fieldCounts[$field] = ($fieldCounts[$field] ?? 0) + 1;
            $rid = (string) ($item['record_id'] ?? '');
            if ($rid !== '') {
                $annotated[$rid] = true;
            }
        }
        $recordIds = [];
        foreach ($records as $record) {
            $id = (string) ($record['dataset_record_id'] ?? $record['record_id'] ?? '');
            if ($id !== '') {
                $recordIds[$id] = true;
            }
        }
        $recordsTotal = count($recordIds) ?: count($records);
        return [
            'annotation_quality_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'records_total' => $recordsTotal,
            'annotations_total' => count($items),
            'annotated_records' => count($annotated),
            'coverage_percent' => $recordsTotal > 0 ? round((count($annotated) / $recordsTotal) * 100, 2) : 0.0,
            'label_counts' => $labelCounts,
            'field_counts' => $fieldCounts,
        ];
    }

    /** @param array<int,array<string,mixed>> $records @param array<string,mixed> $annotations @return array<int,array<string,mixed>> */
    public function exportRows(array $records, array $annotations): array
    {
        $byId = [];
        foreach ($records as $record) {
            $id = (string) ($record['dataset_record_id'] ?? $record['record_id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $record;
            }
        }
        $rows = [];
        foreach ((array) ($annotations['annotations'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['record_id'] ?? '');
            $record = is_array($byId[$id] ?? null) ? $byId[$id] : [];
            $rows[] = [
                'record_id' => $id,
                'label' => (string) ($item['label'] ?? ''),
                'field' => (string) ($item['field'] ?? ''),
                'note' => (string) ($item['note'] ?? ''),
                'source_url' => (string) ($record['source_url'] ?? ''),
                'text' => $this->recordText($record),
                'fields' => is_array($record['fields'] ?? null) ? (array) $record['fields'] : [],
                'quality_score' => $record['quality_score'] ?? null,
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $record */
    private function recordText(array $record): string
    {
        $fields = is_array($record['fields'] ?? null) ? (array) $record['fields'] : $record;
        foreach (['text', 'title', 'description', 'content', 'summary'] as $key) {
            if (isset($fields[$key]) && is_scalar($fields[$key]) && trim((string) $fields[$key]) !== '') {
                return trim((string) $fields[$key]);
            }
        }
        return trim(json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
