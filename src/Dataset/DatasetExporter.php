<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Dataset;

final class DatasetExporter
{
    /** @param array<int,array<string,mixed>> $records @return array<string,mixed> */
    public function export(array $records, string $output, string $format = 'json'): array
    {
        $format = strtolower($format);
        $this->ensureDir(dirname($output));
        if ($format === 'csv') {
            $this->exportCsv($records, $output);
        } elseif ($format === 'jsonl') {
            file_put_contents($output, implode("\n", array_map(static fn(array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $records)) . (count($records) > 0 ? "\n" : ''));
        } else {
            file_put_contents($output, json_encode(['dataset_export_version' => '3.1.0', 'records_total' => count($records), 'records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $format = 'json';
        }
        return ['ok' => true, 'format' => $format, 'records_exported' => count($records), 'output' => $output];
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

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
