<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Report;

final class ReportExporter
{
    /** @param array<string,mixed> $report */
    public function export(array $report, string $path, string $format): void
    {
        $format = strtolower($format);
        match ($format) {
            'html' => $this->writeHtml($report, $path),
            'csv' => $this->writeCsv($report, $path),
            'xml' => $this->writeXml($report, $path),
            default => $this->writeJson($report, $path),
        };
    }

    /** @param array<string,mixed> $report */
    public function writeHtml(array $report, string $path): void
    {
        $this->ensureDir(dirname($path));
        $title = 'MNB ScraperKit Crawl Summary';
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . $this->e($title) . '</title>';
        $html .= '<style>body{font-family:Arial,sans-serif;margin:32px;line-height:1.45;color:#222}h1{margin-bottom:0}.muted{color:#666}table{border-collapse:collapse;width:100%;margin:16px 0}th,td{border:1px solid #ddd;padding:8px;text-align:left;vertical-align:top}th{background:#f5f5f5}.cards{display:flex;flex-wrap:wrap;gap:12px}.card{border:1px solid #ddd;border-radius:8px;padding:12px;min-width:150px}.num{font-size:26px;font-weight:bold}</style>';
        $html .= '</head><body><h1>' . $this->e($title) . '</h1>';
        $html .= '<p class="muted">Generated at ' . $this->e((string) ($report['generated_at'] ?? '')) . '</p>';
        $html .= '<h2>Job</h2>' . $this->table((array) ($report['job'] ?? []));
        $html .= '<h2>Key counts</h2><div class="cards">';
        foreach ((array) ($report['counts'] ?? []) as $k => $v) {
            $html .= '<div class="card"><div class="num">' . $this->e((string) $v) . '</div><div>' . $this->e((string) $k) . '</div></div>';
        }
        $html .= '</div>';
        $html .= '<h2>Failure type counts</h2>' . $this->table((array) ($report['failure_type_counts'] ?? []));
        $html .= '<h2>Validation status counts</h2>' . $this->table((array) ($report['validation_status_counts'] ?? []));
        $html .= '<h2>Quality summary</h2>' . $this->table((array) ($report['quality_summary'] ?? []));
        $html .= '<h2>Resume state</h2>' . $this->table((array) ($report['resume']['counts'] ?? []));
        $html .= '<h2>Export files</h2>' . $this->exportFilesTable((array) ($report['exports'] ?? []));
        $html .= '</body></html>';
        file_put_contents($path, $html);
    }

    /** @param array<string,mixed> $report */
    public function writeJson(array $report, string $path): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string,mixed> $report */
    public function writeCsv(array $report, string $path): void
    {
        $this->ensureDir(dirname($path));
        $fp = fopen($path, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to open CSV path: ' . $path);
        }
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['section', 'key', 'value']);
        foreach (['job', 'counts', 'status_counts', 'failure_type_counts', 'validation_status_counts', 'quality_summary'] as $section) {
            foreach ((array) ($report[$section] ?? []) as $key => $value) {
                fputcsv($fp, [$section, $key, is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value]);
            }
        }
        fclose($fp);
    }

    /** @param array<string,mixed> $report */
    public function writeXml(array $report, string $path): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, $this->arrayToXml('report', $report));
    }

    /** @param array<mixed> $data */
    public function arrayToXml(string $root, array $data): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $this->nodeToXml($root, $data, 0);
    }

    private function nodeToXml(string $name, mixed $value, int $level): string
    {
        $name = preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $name) === 1 ? $name : 'item';
        $indent = str_repeat('  ', $level);
        if (is_array($value)) {
            $xml = $indent . '<' . $name . '>' . "\n";
            foreach ($value as $key => $child) {
                $childName = is_string($key) && !is_numeric($key) ? $key : 'item';
                $xml .= $this->nodeToXml($childName, $child, $level + 1);
            }
            return $xml . $indent . '</' . $name . '>' . "\n";
        }
        return $indent . '<' . $name . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</' . $name . '>' . "\n";
    }

    /** @param array<string,mixed> $items */
    private function table(array $items): string
    {
        if ($items === []) {
            return '<p class="muted">No data.</p>';
        }
        $html = '<table><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
        foreach ($items as $k => $v) {
            $html .= '<tr><td>' . $this->e((string) $k) . '</td><td>' . $this->e(is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $v) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** @param array<int,mixed> $files */
    private function exportFilesTable(array $files): string
    {
        if ($files === []) {
            return '<p class="muted">No export files found.</p>';
        }
        $html = '<table><thead><tr><th>File</th><th>Size</th><th>Modified</th></tr></thead><tbody>';
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $html .= '<tr><td>' . $this->e((string) ($file['relative_path'] ?? '')) . '</td><td>' . $this->e((string) ($file['size_bytes'] ?? '')) . '</td><td>' . $this->e((string) ($file['modified_at'] ?? '')) . '</td></tr>';
        }
        return $html . '</tbody></table>';
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
