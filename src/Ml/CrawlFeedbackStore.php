<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Ml;

final class CrawlFeedbackStore
{
    public const VERSION = '1.0.3';

    public function __construct(private readonly string $rootDir)
    {
    }

    /** @param array<string,mixed> $feedback @return array<string,mixed> */
    public function add(array $feedback, ?string $file = null): array
    {
        $file = $file ?: $this->rootDir . '/storage/ml/crawl-feedback.jsonl';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $row = [
            'feedback_version' => self::VERSION,
            'recorded_at' => date(DATE_ATOM),
            'url' => trim((string) ($feedback['url'] ?? '')),
            'label' => trim((string) ($feedback['label'] ?? 'relevant')),
            'reason' => trim((string) ($feedback['reason'] ?? $feedback['note'] ?? '')),
            'source' => trim((string) ($feedback['source'] ?? 'manual')),
            'profile' => trim((string) ($feedback['profile'] ?? '')),
        ];
        if ($row['url'] === '' || preg_match('~^https?://~i', $row['url']) !== 1) {
            throw new \InvalidArgumentException('Feedback URL must be an http(s) URL.');
        }
        if (!in_array($row['label'], ['relevant', 'irrelevant', 'review', 'positive', 'negative'], true)) {
            throw new \InvalidArgumentException('Feedback label must be relevant, irrelevant, review, positive, or negative.');
        }
        file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        return ['ok' => true, 'file' => $file, 'feedback' => $row];
    }

    /** @return array<string,mixed> */
    public function summary(?string $file = null): array
    {
        $file = $file ?: $this->rootDir . '/storage/ml/crawl-feedback.jsonl';
        $rows = $this->read($file);
        $labels = [];
        foreach ($rows as $row) {
            $label = (string) ($row['label'] ?? 'unknown');
            $labels[$label] = ($labels[$label] ?? 0) + 1;
        }
        ksort($labels);
        return [
            'feedback_version' => self::VERSION,
            'file' => $file,
            'records_total' => count($rows),
            'label_counts' => $labels,
            'recent' => array_slice($rows, -10),
        ];
    }

    /** @return array<string,array<int,string>> */
    public function positivesNegatives(?string $file = null): array
    {
        $rows = $this->read($file ?: $this->rootDir . '/storage/ml/crawl-feedback.jsonl');
        $positive = [];
        $negative = [];
        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? '');
            $label = (string) ($row['label'] ?? '');
            if (in_array($label, ['relevant', 'positive'], true)) {
                $positive[] = $url;
            } elseif (in_array($label, ['irrelevant', 'negative'], true)) {
                $negative[] = $url;
            }
        }
        return ['positive' => array_values(array_unique($positive)), 'negative' => array_values(array_unique($negative))];
    }

    /** @return array<int,array<string,mixed>> */
    private function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $row = json_decode($line, true);
            if (is_array($row)) { $rows[] = $row; }
        }
        return $rows;
    }
}
