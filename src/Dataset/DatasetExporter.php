<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Dataset;

final class DatasetExporter
{
    /** @param array<int,array<string,mixed>> $records @return array<string,mixed> */
    public function export(array $records, string $output, string $format = 'json', bool $trainingReady = false, string $trainingType = 'classification'): array
    {
        $format = strtolower($format);
        $this->ensureDir(dirname($output));
        $rows = $trainingReady ? $this->trainingRows($records, $trainingType) : $records;
        if ($format === 'csv') {
            $trainingReady ? $this->exportTrainingCsv($rows, $output) : $this->exportCsv($records, $output);
        } elseif ($format === 'jsonl') {
            file_put_contents($output, implode("\n", array_map(static fn(array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $rows)) . (count($rows) > 0 ? "\n" : ''));
        } else {
            file_put_contents($output, json_encode([
                'dataset_export_version' => '1.0.0',
                'training_ready' => $trainingReady,
                'training_type' => $trainingReady ? $trainingType : null,
                'records_total' => count($rows),
                'records' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $format = 'json';
        }
        return ['ok' => true, 'format' => $format, 'training_ready' => $trainingReady, 'training_type' => $trainingReady ? $trainingType : null, 'records_exported' => count($rows), 'output' => $output];
    }

    /** @param array<int,array<string,mixed>> $records */
    private function exportCsv(array $records, string $output): void
    {
        $fp = fopen($output, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to write CSV: ' . $output);
        }
        $headers = ['dataset_record_id', 'record_type', 'source_type', 'source_url', 'dedupe_key', 'quality_score', 'validation_status', 'field_count'];
        fputcsv($fp, $headers);
        foreach ($records as $record) {
            fputcsv($fp, array_map(static fn(string $key): mixed => $record[$key] ?? '', $headers));
        }
        fclose($fp);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function exportTrainingCsv(array $rows, string $output): void
    {
        $fp = fopen($output, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to write CSV: ' . $output);
        }
        fputcsv($fp, ['id', 'text', 'label', 'quality_score', 'source_url', 'fields_json', 'metadata_json']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['id'] ?? '',
                $row['text'] ?? '',
                $row['label'] ?? '',
                $row['quality_score'] ?? '',
                $row['source_url'] ?? '',
                json_encode($row['fields'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($row['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
        fclose($fp);
    }

    /** @param array<int,array<string,mixed>> $records @return array<int,array<string,mixed>> */
    private function trainingRows(array $records, string $trainingType): array
    {
        $rows = [];
        foreach ($records as $record) {
            $fields = is_array($record['fields'] ?? null) ? (array) $record['fields'] : $record;
            $rows[] = [
                'id' => (string) ($record['dataset_record_id'] ?? $record['record_id'] ?? ''),
                'text' => $this->recordText($fields),
                'label' => $this->labelFor($record, $trainingType),
                'training_type' => $trainingType,
                'fields' => $fields,
                'quality_score' => $record['quality_score'] ?? null,
                'source_url' => (string) ($record['source_url'] ?? $fields['url'] ?? ''),
                'metadata' => [
                    'record_type' => (string) ($record['record_type'] ?? ''),
                    'validation_status' => (string) ($record['validation_status'] ?? ''),
                    'dedupe_key' => (string) ($record['dedupe_key'] ?? ''),
                ],
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $fields */
    private function recordText(array $fields): string
    {
        foreach (['text', 'title', 'description', 'content', 'summary', 'name'] as $key) {
            if (isset($fields[$key]) && is_scalar($fields[$key]) && trim((string) $fields[$key]) !== '') {
                return trim((string) $fields[$key]);
            }
        }
        $parts = [];
        foreach ($fields as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = $key . ': ' . trim((string) $value);
            }
        }
        return implode(' | ', array_slice($parts, 0, 12));
    }

    /** @param array<string,mixed> $record */
    private function labelFor(array $record, string $trainingType): string
    {
        if ($trainingType === 'quality') {
            $score = (float) ($record['quality_score'] ?? 0);
            return $score >= 80 ? 'high_quality' : ($score >= 50 ? 'needs_review' : 'low_quality');
        }
        if ($trainingType === 'validation') {
            return (string) ($record['validation_status'] ?? 'unknown');
        }
        return (string) ($record['record_type'] ?? $record['source_type'] ?? 'record');
    }

    private function ensureDir(string $dir): void
    {
        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
