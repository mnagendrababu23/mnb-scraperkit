<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Report;

use Mnb\ScraperKit\Pipeline\PipelineExporter;

final class RecordExportService
{
    /** @param array<int,array<string,mixed>> $records */
    public function export(array $records, string $path, string $format): void
    {
        $format = strtolower($format);
        match ($format) {
            'csv' => (new PipelineExporter())->exportCsv($records, $path),
            'xml' => $this->writeXml($records, $path),
            'html' => $this->writeHtml($records, $path),
            default => $this->writeJson($records, $path),
        };
    }

    /** @param array<int,array<string,mixed>> $records */
    private function writeJson(array $records, string $path): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<int,array<string,mixed>> $records */
    private function writeXml(array $records, string $path): void
    {
        $this->ensureDir(dirname($path));
        $reporter = new ReportExporter();
        file_put_contents($path, $reporter->arrayToXml('records', ['record' => $records]));
    }

    /** @param array<int,array<string,mixed>> $records */
    private function writeHtml(array $records, string $path): void
    {
        $this->ensureDir(dirname($path));
        $headers = [];
        foreach ($records as $record) {
            foreach (array_keys($record) as $key) {
                if (!in_array((string) $key, $headers, true)) {
                    $headers[] = (string) $key;
                }
            }
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>MNB ScraperKit Records</title><style>body{font-family:Arial,sans-serif;margin:32px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;vertical-align:top}th{background:#f5f5f5}</style></head><body><h1>MNB ScraperKit Records</h1><p>Total records: ' . count($records) . '</p><table><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . $this->e($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($records as $record) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $value = $record[$header] ?? '';
                $html .= '<td>' . $this->e(is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        file_put_contents($path, $html);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
