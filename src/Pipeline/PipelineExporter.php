<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class PipelineExporter
{
    public function export(PipelineResult $result, string $outputDir, string $format = 'json'): void
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }
        $format = strtolower($format);
        if ($format === 'both' || $format === 'json') {
            $this->exportJson($result, $outputDir . '/records.json');
            $this->exportJsonArray($result->duplicates, $outputDir . '/duplicates.json');
            $this->exportJsonArray($result->dropped, $outputDir . '/dropped.json');
            $this->exportJsonArray($result->validationIssues, $outputDir . '/validation-issues.json');
            $this->exportJsonArray($result->summary(), $outputDir . '/pipeline-summary.json');
        }
        if ($format === 'both' || $format === 'csv') {
            $this->exportCsv($result->records, $outputDir . '/records.csv');
            $this->exportCsv($result->duplicates, $outputDir . '/duplicates.csv');
            $this->exportCsv($result->dropped, $outputDir . '/dropped.csv');
        }
    }

    public function exportJson(PipelineResult $result, string $path): void
    {
        $this->writeJson($path, $result->toArray());
    }

    /** @param array<mixed> $data */
    private function exportJsonArray(array $data, string $path): void
    {
        $this->writeJson($path, $data);
    }

    /** @param array<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<int,array<string,mixed>> $records */
    public function exportCsv(array $records, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fp = fopen($path, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to open CSV output path: ' . $path);
        }
        fwrite($fp, "\xEF\xBB\xBF");
        $headers = $this->headers($records);
        fputcsv($fp, $headers);
        foreach ($records as $record) {
            $row = [];
            foreach ($headers as $header) {
                $value = $record[$header] ?? null;
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $row[] = $value;
            }
            fputcsv($fp, $row);
        }
        fclose($fp);
    }

    /** @param array<int,array<string,mixed>> $records @return array<int,string> */
    private function headers(array $records): array
    {
        $headers = [];
        foreach ($records as $record) {
            foreach (array_keys($record) as $key) {
                if (!in_array((string) $key, $headers, true)) {
                    $headers[] = (string) $key;
                }
            }
        }
        return $headers ?: ['empty'];
    }
}
