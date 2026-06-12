<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Ml;

use Mnb\ScraperKit\Intelligence\FeatureExtractor;

final class TrainingDataExporter
{
    public const VERSION = '1.0.3';

    /** @param array<int,string> $positiveUrls @param array<int,string> $negativeUrls @return array<string,mixed> */
    public function build(array $positiveUrls, array $negativeUrls): array
    {
        $extractor = new FeatureExtractor();
        $rows = [];
        foreach (array_values(array_unique($positiveUrls)) as $url) {
            $url = trim((string) $url);
            if ($url === '') { continue; }
            $rows[] = ['label' => 'relevant', 'url' => $url, 'features' => $extractor->urlFeatures($url)];
        }
        foreach (array_values(array_unique($negativeUrls)) as $url) {
            $url = trim((string) $url);
            if ($url === '') { continue; }
            $rows[] = ['label' => 'irrelevant', 'url' => $url, 'features' => $extractor->urlFeatures($url)];
        }
        return [
            'training_data_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'records_total' => count($rows),
            'labels' => [
                'relevant' => count(array_filter($rows, static fn (array $r): bool => ($r['label'] ?? '') === 'relevant')),
                'irrelevant' => count(array_filter($rows, static fn (array $r): bool => ($r['label'] ?? '') === 'irrelevant')),
            ],
            'rows' => $rows,
        ];
    }

    /** @param array<string,mixed> $data */
    public function writeJsonl(array $data, string $output): void
    {
        if (!is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }
        $fh = fopen($output, 'wb');
        if (!$fh) {
            throw new \RuntimeException('Unable to write training data: ' . $output);
        }
        foreach (($data['rows'] ?? []) as $row) {
            fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        }
        fclose($fh);
    }
}
