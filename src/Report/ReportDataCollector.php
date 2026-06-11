<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Report;

final class ReportDataCollector
{
    /** @return array<string,mixed> */
    public function collectFromJobDir(string $jobDir): array
    {
        $jobDir = rtrim($jobDir, '/\\');
        if (!is_dir($jobDir)) {
            throw new \RuntimeException('Job directory not found: ' . $jobDir);
        }

        $manifest = $this->readJsonIfExists($jobDir . '/job-manifest.json');
        $crawl = $this->readJsonIfExists($jobDir . '/crawl.json');
        $bulkSummary = $this->readJsonIfExists($jobDir . '/bulk-summary.json');
        $pipeline = $this->readPipeline($jobDir);
        $checkpoint = $this->readJsonIfExists($jobDir . '/checkpoint.json');

        $pages = $this->listFrom($crawl, 'pages');
        $records = $this->recordsFromPipeline($pipeline);
        $validationIssues = $this->listFrom($pipeline, 'validation_issues');
        $duplicates = $this->listFrom($pipeline, 'duplicates');
        $dropped = $this->listFrom($pipeline, 'dropped');

        $failedPages = [];
        $skippedPages = [];
        $statusCounts = [];
        $failureCounts = [];
        foreach ($pages as $page) {
            $status = isset($page['status_code']) ? (int) $page['status_code'] : 0;
            $statusGroup = $this->statusGroup($status, $page['error'] ?? null);
            $statusCounts[$statusGroup] = ($statusCounts[$statusGroup] ?? 0) + 1;
            $failureType = (string) ($page['failure_type'] ?? '');
            $isSkipped = (bool) ($page['skipped'] ?? false);
            $isFailed = ($page['error'] ?? null) !== null || $status >= 400 || $failureType !== '';
            if ($isSkipped) {
                $skippedPages[] = $page;
            }
            if ($isFailed) {
                $failedPages[] = $page;
                $key = $failureType !== '' ? $failureType : $statusGroup;
                $failureCounts[$key] = ($failureCounts[$key] ?? 0) + 1;
            }
        }

        $validationStatusCounts = [];
        $qualityScores = [];
        foreach ($records as $record) {
            $status = (string) ($record['validation']['status'] ?? $record['_validation_status'] ?? 'unknown');
            $validationStatusCounts[$status] = ($validationStatusCounts[$status] ?? 0) + 1;
            $score = $record['quality_score'] ?? $record['_quality_score'] ?? null;
            if (is_numeric($score)) {
                $qualityScores[] = (float) $score;
            }
        }

        return [
            'report_version' => '3.1.0',
            'generated_at' => date(DATE_ATOM),
            'job_dir' => $jobDir,
            'job' => [
                'id' => $manifest['job_id'] ?? basename($jobDir),
                'type' => $manifest['type'] ?? $manifest['job_type'] ?? null,
                'status' => $manifest['status'] ?? null,
                'started_at' => $manifest['started_at'] ?? $bulkSummary['started_at'] ?? null,
                'ended_at' => $manifest['ended_at'] ?? $bulkSummary['ended_at'] ?? null,
            ],
            'counts' => [
                'pages_total' => count($pages),
                'pages_failed' => count($failedPages),
                'pages_skipped' => count($skippedPages),
                'records_total' => count($records),
                'records_valid' => (int) ($validationStatusCounts['valid'] ?? 0),
                'records_invalid' => (int) ($validationStatusCounts['invalid'] ?? 0),
                'records_warning' => (int) ($validationStatusCounts['warning'] ?? 0),
                'records_partial' => (int) ($validationStatusCounts['partial'] ?? 0),
                'duplicates' => count($duplicates),
                'dropped' => count($dropped),
                'validation_issues' => count($validationIssues),
            ],
            'status_counts' => $statusCounts,
            'failure_type_counts' => $failureCounts,
            'validation_status_counts' => $validationStatusCounts,
            'quality_summary' => $this->qualitySummary($qualityScores),
            'resume' => [
                'checkpoint_found' => $checkpoint !== [],
                'counts' => $checkpoint['counts'] ?? $manifest['resume']['counts'] ?? [],
                'last_processed_url' => $checkpoint['last_processed_url'] ?? $manifest['resume']['last_processed_url'] ?? null,
            ],
            'exports' => $this->discoverExportFiles($jobDir),
            'manifest' => $manifest,
        ];
    }

    /** @return array<string,mixed> */
    private function readPipeline(string $jobDir): array
    {
        foreach ([
            $jobDir . '/pipeline/records.json',
            $jobDir . '/pipeline/pipeline-summary.json',
            $jobDir . '/records.json',
        ] as $path) {
            $data = $this->readJsonIfExists($path);
            if ($data !== []) {
                return $data;
            }
        }
        return [];
    }

    /** @return array<string,mixed> */
    private function readJsonIfExists(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $data @return array<int,array<string,mixed>> */
    private function listFrom(array $data, string $key): array
    {
        $items = $data[$key] ?? [];
        if (!is_array($items)) {
            return [];
        }
        return array_values(array_filter($items, 'is_array'));
    }

    /** @param array<string,mixed> $pipeline @return array<int,array<string,mixed>> */
    private function recordsFromPipeline(array $pipeline): array
    {
        if (isset($pipeline['records']) && is_array($pipeline['records'])) {
            return array_values(array_filter($pipeline['records'], 'is_array'));
        }
        if (array_is_list($pipeline)) {
            return array_values(array_filter($pipeline, 'is_array'));
        }
        return [];
    }

    private function statusGroup(int $status, mixed $error): string
    {
        if ($error !== null && $error !== '') {
            return 'error';
        }
        return match (true) {
            $status >= 200 && $status < 300 => '2xx',
            $status >= 300 && $status < 400 => '3xx',
            $status >= 400 && $status < 500 => '4xx',
            $status >= 500 => '5xx',
            default => 'unknown',
        };
    }

    /** @param array<int,float> $scores @return array<string,mixed> */
    private function qualitySummary(array $scores): array
    {
        if ($scores === []) {
            return ['count' => 0, 'min' => null, 'max' => null, 'average' => null];
        }
        return [
            'count' => count($scores),
            'min' => min($scores),
            'max' => max($scores),
            'average' => round(array_sum($scores) / count($scores), 2),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function discoverExportFiles(string $jobDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jobDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['json', 'csv', 'xml', 'html', 'txt', 'log'], true)) {
                continue;
            }
            $path = $file->getPathname();
            $files[] = [
                'path' => $path,
                'relative_path' => ltrim(str_replace('\\', '/', substr($path, strlen($jobDir))), '/'),
                'size_bytes' => $file->getSize(),
                'modified_at' => date(DATE_ATOM, $file->getMTime()),
            ];
        }
        usort($files, static fn (array $a, array $b): int => strcmp((string) $a['relative_path'], (string) $b['relative_path']));
        return $files;
    }
}
