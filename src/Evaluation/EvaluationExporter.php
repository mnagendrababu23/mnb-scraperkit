<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Evaluation;

final class EvaluationExporter
{
    /** @param array<string,mixed> $report @return array<string,mixed> */
    public function export(array $report, string $output, string $format = 'json'): array
    {
        $format = strtolower($format);
        $this->ensureDir(dirname($output));
        if ($format === 'csv') {
            $this->exportCsv($report, $output);
        } elseif ($format === 'html') {
            $this->exportHtml($report, $output);
        } else {
            file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $format = 'json';
        }
        return ['ok' => true, 'format' => $format, 'output' => $output];
    }

    /** @param array<string,mixed> $report */
    private function exportCsv(array $report, string $output): void
    {
        $fp = fopen($output, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to write CSV: ' . $output);
        }
        fputcsv($fp, ['metric', 'value']);
        foreach ((array) ($report['summary'] ?? []) as $metric => $value) {
            fputcsv($fp, [(string) $metric, is_scalar($value) ? (string) $value : json_encode($value)]);
        }
        fputcsv($fp, []);
        fputcsv($fp, ['field', 'completeness_percent', 'missing_percent', 'present', 'empty', 'warnings', 'invalid']);
        foreach ((array) ($report['field_quality_matrix'] ?? []) as $row) {
            if (is_array($row)) {
                fputcsv($fp, [
                    $row['field'] ?? '',
                    $row['completeness_percent'] ?? '',
                    $row['missing_percent'] ?? '',
                    $row['present'] ?? '',
                    $row['empty'] ?? '',
                    $row['warnings'] ?? '',
                    $row['invalid'] ?? '',
                ]);
            }
        }
        fclose($fp);
    }

    /** @param array<string,mixed> $report */
    private function exportHtml(array $report, string $output): void
    {
        $summaryRows = '';
        foreach ((array) ($report['summary'] ?? []) as $k => $v) {
            $summaryRows .= '<tr><th>' . $this->e((string) $k) . '</th><td>' . $this->e(is_scalar($v) ? (string) $v : json_encode($v)) . '</td></tr>';
        }
        $fieldRows = '';
        foreach ((array) ($report['field_quality_matrix'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fieldRows .= '<tr><td>' . $this->e((string) ($row['field'] ?? '')) . '</td><td>' . $this->e((string) ($row['completeness_percent'] ?? '')) . '</td><td>' . $this->e((string) ($row['missing_percent'] ?? '')) . '</td><td>' . $this->e((string) ($row['present'] ?? '')) . '</td><td>' . $this->e((string) ($row['empty'] ?? '')) . '</td></tr>';
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>MNB ScraperKit Evaluation</title><style>body{font-family:Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%;margin:16px 0}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f5f5f5}</style></head><body>'
            . '<h1>MNB ScraperKit Evaluation Report</h1><p>Generated at ' . $this->e((string) ($report['generated_at'] ?? '')) . '</p>'
            . '<h2>Summary</h2><table>' . $summaryRows . '</table>'
            . '<h2>Field Quality Matrix</h2><table><tr><th>Field</th><th>Completeness %</th><th>Missing %</th><th>Present</th><th>Empty</th></tr>' . $fieldRows . '</table>'
            . '</body></html>';
        file_put_contents($output, $html);
    }

    private function ensureDir(string $dir): void
    {
        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
