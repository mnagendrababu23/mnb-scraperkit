<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Dataset;

/**
 * Creates normalized dataset folders from crawl, pipeline, source, URL list, or generic JSON data.
 *
 * The dataset layer is intentionally deterministic and dependency-free. It is useful for
 * dataset snapshots, QA review, annotations, version diffs, and later ML training exports.
 */
final class DatasetBuilder
{
    public const VERSION = '1.0.2';

    /** @return array<string,mixed> */
    public function createFromFile(string $inputFile, string $outputDir, ?string $datasetId = null, string $type = 'auto'): array
    {
        if (!is_file($inputFile)) {
            throw new \RuntimeException('Dataset input file not found: ' . $inputFile);
        }

        $raw = (string) file_get_contents($inputFile);
        $data = $this->readInput($inputFile, $raw);
        $detectedType = $type === 'auto' ? $this->detectType($data, $inputFile) : $type;
        $records = $this->recordsFromData($data, $detectedType, $inputFile);
        $datasetId = $datasetId ?: $this->makeDatasetId($inputFile, $records);
        $datasetDir = rtrim($outputDir, '/\\') . '/' . $datasetId;
        $this->ensureDir($datasetDir);

        $summary = $this->summary($records, $detectedType);
        $manifest = [
            'dataset_version' => self::VERSION,
            'dataset_id' => $datasetId,
            'created_at' => date(DATE_ATOM),
            'source_file' => realpath($inputFile) ?: $inputFile,
            'source_type' => $detectedType,
            'records_file' => 'records.json',
            'records_jsonl_file' => 'records.jsonl',
            'quality_file' => 'quality-summary.json',
            'annotations_file' => 'annotations.json',
            'summary' => $summary,
        ];

        $this->writeJson($datasetDir . '/dataset-manifest.json', $manifest);
        $this->writeJson($datasetDir . '/records.json', ['dataset_id' => $datasetId, 'records_total' => count($records), 'records' => $records]);
        file_put_contents($datasetDir . '/records.jsonl', implode("\n", array_map(static fn(array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $records)) . (count($records) > 0 ? "\n" : ''));
        $this->writeJson($datasetDir . '/quality-summary.json', $this->qualitySummary($records, $summary));
        if (!is_file($datasetDir . '/annotations.json')) {
            $this->writeJson($datasetDir . '/annotations.json', [
                'annotation_version' => self::VERSION,
                'dataset_id' => $datasetId,
                'created_at' => date(DATE_ATOM),
                'annotations' => [],
            ]);
        }

        return ['dataset_dir' => $datasetDir, 'manifest' => $manifest, 'records' => $records];
    }

    /** @return array<string,mixed>|array<int,mixed> */
    private function readInput(string $inputFile, string $raw): array
    {
        $ext = strtolower(pathinfo($inputFile, PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'list'], true)) {
            $urls = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
            return ['urls' => $urls];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Dataset input must be JSON or TXT URL list. Invalid JSON: ' . $inputFile);
        }
        return $data;
    }

    /** @param array<mixed> $data */
    private function detectType(array $data, string $inputFile): string
    {
        if (isset($data['page_features']) || isset($data['record_features'])) {
            return 'intelligence';
        }
        if (isset($data['records']) && is_array($data['records'])) {
            $first = $data['records'][0] ?? [];
            if (is_array($first) && (isset($first['record_id']) || isset($first['record_type']) || isset($first['fields']) || isset($first['validation']))) {
                return 'pipeline';
            }
            return 'source';
        }
        if (isset($data['pages']) && is_array($data['pages'])) {
            return 'crawl';
        }
        if (isset($data['urls']) && is_array($data['urls'])) {
            return 'url-list';
        }
        if (array_is_list($data)) {
            return 'generic-list';
        }
        return 'generic-json';
    }

    /** @param array<mixed> $data @return array<int,array<string,mixed>> */
    private function recordsFromData(array $data, string $type, string $inputFile): array
    {
        $rows = match ($type) {
            'crawl' => is_array($data['pages'] ?? null) ? $data['pages'] : [],
            'pipeline', 'source' => is_array($data['records'] ?? null) ? $data['records'] : [],
            'intelligence' => is_array($data['page_features'] ?? null) ? $data['page_features'] : (is_array($data['record_features'] ?? null) ? $data['record_features'] : []),
            'url-list' => array_map(static fn($url): array => ['url' => $url], (array) ($data['urls'] ?? [])),
            'generic-list' => $data,
            default => [$data],
        };

        $records = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $row = ['value' => $row];
            }
            $records[] = $this->normalizeRecord($row, $type, $inputFile, $i);
        }
        return $records;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRecord(array $row, string $type, string $inputFile, int $index): array
    {
        $fields = is_array($row['fields'] ?? null) ? (array) $row['fields'] : $row;
        $sourceUrl = (string) ($row['source_url'] ?? $row['url'] ?? $row['final_url'] ?? $row['canonical_url'] ?? '');
        $recordType = (string) ($row['record_type'] ?? match ($type) {
            'crawl' => 'page',
            'source', 'url-list' => 'url',
            'intelligence' => 'feature-row',
            default => 'record',
        });
        $recordId = (string) ($row['record_id'] ?? 'dsrec_' . substr(hash('sha256', $type . '|' . $inputFile . '|' . $index . '|' . json_encode($row, JSON_UNESCAPED_SLASHES)), 0, 16));
        $dedupeKey = (string) ($row['dedupe_key'] ?? $row['record_key'] ?? $row['content_hash'] ?? $sourceUrl ?? $recordId);
        $quality = $row['quality_score'] ?? $row['quality'] ?? null;
        if (is_array($quality)) {
            $quality = $quality['score'] ?? null;
        }
        $qualityScore = is_numeric($quality) ? (float) $quality : $this->estimateQuality($row, $fields);
        $validation = is_array($row['validation'] ?? null) ? (array) $row['validation'] : ['status' => $qualityScore >= 50 ? 'valid' : 'warning'];

        return [
            'dataset_record_id' => $recordId,
            'record_type' => $recordType,
            'source_type' => $type,
            'source_url' => $sourceUrl,
            'dedupe_key' => $dedupeKey,
            'quality_score' => round($qualityScore, 2),
            'validation_status' => (string) ($validation['status'] ?? 'unknown'),
            'field_count' => count(array_filter($fields, static fn($v): bool => $v !== null && $v !== '' && $v !== [])),
            'fields' => $fields,
            'metadata' => [
                'input_index' => $index,
                'input_file' => basename($inputFile),
                'original_record_id' => $row['record_id'] ?? null,
            ],
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $fields */
    private function estimateQuality(array $row, array $fields): float
    {
        $score = 40.0;
        if (($row['status_code'] ?? 0) >= 200 && ($row['status_code'] ?? 0) < 400) {
            $score += 20;
        }
        if (($row['title'] ?? $fields['title'] ?? '') !== '') {
            $score += 15;
        }
        if (($row['url'] ?? $row['source_url'] ?? $row['final_url'] ?? '') !== '') {
            $score += 10;
        }
        $nonEmpty = count(array_filter($fields, static fn($v): bool => $v !== null && $v !== '' && $v !== []));
        $score += min(15, $nonEmpty * 2);
        if (isset($row['error']) || isset($row['failure_type'])) {
            $score -= 25;
        }
        return max(0, min(100, $score));
    }

    /** @param array<int,array<string,mixed>> $records @return array<string,mixed> */
    private function summary(array $records, string $type): array
    {
        $byType = [];
        $validation = [];
        $qualityTotal = 0.0;
        $uniqueKeys = [];
        foreach ($records as $record) {
            $byType[(string) $record['record_type']] = ($byType[(string) $record['record_type']] ?? 0) + 1;
            $validation[(string) $record['validation_status']] = ($validation[(string) $record['validation_status']] ?? 0) + 1;
            $qualityTotal += (float) ($record['quality_score'] ?? 0);
            $key = (string) ($record['dedupe_key'] ?? '');
            if ($key !== '') {
                $uniqueKeys[$key] = true;
            }
        }
        $total = count($records);
        return [
            'source_type' => $type,
            'records_total' => $total,
            'unique_dedupe_keys' => count($uniqueKeys),
            'duplicate_estimate' => max(0, $total - count($uniqueKeys)),
            'record_type_counts' => $byType,
            'validation_status_counts' => $validation,
            'quality_score_avg' => $total > 0 ? round($qualityTotal / $total, 2) : 0,
        ];
    }

    /** @param array<int,array<string,mixed>> $records @param array<string,mixed> $summary @return array<string,mixed> */
    private function qualitySummary(array $records, array $summary): array
    {
        $low = [];
        foreach ($records as $record) {
            if ((float) ($record['quality_score'] ?? 0) < 50) {
                $low[] = [
                    'dataset_record_id' => $record['dataset_record_id'],
                    'source_url' => $record['source_url'],
                    'quality_score' => $record['quality_score'],
                    'validation_status' => $record['validation_status'],
                ];
            }
        }
        return [
            'quality_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'summary' => $summary,
            'low_quality_records' => $low,
            'recommended_actions' => [
                'Review low quality records before training or client delivery.',
                'Use annotation:init and annotation:add to label records for future ML workflows.',
                'Use dataset:diff before replacing an older dataset snapshot.',
            ],
        ];
    }

    /** @param array<int,array<string,mixed>> $records */
    private function makeDatasetId(string $inputFile, array $records): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', '-', pathinfo($inputFile, PATHINFO_FILENAME)) ?: 'dataset';
        $base = trim(strtolower($base), '-');
        return 'dataset_' . $base . '_' . date('Ymd_His') . '_' . substr(hash('sha256', $inputFile . count($records)), 0, 6);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /** @param array<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
