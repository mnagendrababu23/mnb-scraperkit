<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Csv;

final class CsvUrlSourceReader
{
    /** @return array<string,mixed> */
    public function read(string $file, string $urlColumn = 'url', int $limit = 10000, string $delimiter = ','): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('CSV source file not found: ' . $file);
        }

        $handle = fopen($file, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to open CSV source file: ' . $file);
        }

        $records = [];
        $errors = [];
        $headers = null;
        $rowNumber = 0;
        $urlIndex = null;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if ($row === [null] || $row === []) {
                continue;
            }
            $row = array_map(static fn ($v): string => trim((string) $v), $row);

            if ($headers === null) {
                $headers = $row;
                $lower = array_map(static fn (string $h): string => strtolower(trim($h)), $headers);
                $urlIndex = array_search(strtolower($urlColumn), $lower, true);
                if ($urlIndex === false && isset($headers[0]) && $this->looksLikeUrl($headers[0])) {
                    $urlIndex = 0;
                    $headers = array_map(static fn (int $i): string => 'column_' . ($i + 1), array_keys($row));
                } elseif ($urlIndex === false) {
                    fclose($handle);
                    throw new \RuntimeException('URL column not found in CSV header: ' . $urlColumn);
                } else {
                    continue;
                }
            }

            if (count($records) >= $limit) {
                break;
            }

            $url = trim((string) ($row[(int) $urlIndex] ?? ''));
            if (!$this->looksLikeUrl($url)) {
                $errors[] = ['row' => $rowNumber, 'message' => 'URL is missing or invalid.', 'value' => $url];
                continue;
            }

            $metadata = [];
            foreach ($headers as $idx => $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    $name = 'column_' . ((int) $idx + 1);
                }
                $metadata[$name] = $row[(int) $idx] ?? null;
            }

            $records[] = [
                'source_type' => 'csv',
                'record_type' => 'url_source',
                'record_key' => $url,
                'url' => $url,
                'row_number' => $rowNumber,
                'metadata' => $metadata,
            ];
        }

        fclose($handle);

        return [
            'source_type' => 'csv',
            'source' => $file,
            'url_column' => $urlColumn,
            'records_returned' => count($records),
            'errors' => $errors,
            'records' => $records,
        ];
    }

    private function looksLikeUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $value) === 1;
    }
}
