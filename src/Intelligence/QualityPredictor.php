<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Intelligence;

final class QualityPredictor
{
    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    public function predict(array $analysis): array
    {
        $pageRows = [];
        foreach (($analysis['page_features'] ?? []) as $feature) {
            if (is_array($feature)) {
                $pageRows[] = $this->pageQuality($feature);
            }
        }
        $recordRows = [];
        foreach (($analysis['record_features'] ?? []) as $feature) {
            if (is_array($feature)) {
                $recordRows[] = $this->recordQuality($feature);
            }
        }

        return [
            'intelligence_version' => '4.3.1',
            'generated_at' => date(DATE_ATOM),
            'summary' => [
                'page_quality_avg' => $this->average($pageRows, 'quality_score'),
                'record_quality_avg' => $this->average($recordRows, 'quality_score'),
                'low_quality_pages' => count(array_filter($pageRows, static fn (array $r): bool => (float) $r['quality_score'] < 0.5)),
                'low_quality_records' => count(array_filter($recordRows, static fn (array $r): bool => (float) $r['quality_score'] < 0.5)),
            ],
            'page_quality' => $pageRows,
            'record_quality' => $recordRows,
        ];
    }

    /** @param array<string,mixed> $feature @return array<string,mixed> */
    public function pageQuality(array $feature): array
    {
        $score = 0.2;
        $reasons = [];
        $statusCode = (int) ($feature['http_status'] ?? 0);
        if ($statusCode >= 200 && $statusCode < 400) { $score += 0.25; } else { $reasons[] = 'non_success_http_status'; }
        if ((int) ($feature['text_length'] ?? 0) >= 500) { $score += 0.2; } else { $reasons[] = 'low_text_length'; }
        if ((int) ($feature['title_length'] ?? 0) > 10) { $score += 0.1; } else { $reasons[] = 'missing_or_short_title'; }
        if ((int) ($feature['link_count'] ?? 0) > 0) { $score += 0.05; }
        if (!empty($feature['has_schema_hint'])) { $score += 0.05; }
        if (!empty($feature['failure_type'])) { $score -= 0.25; $reasons[] = 'failure_type_present'; }
        if (!empty($feature['has_browser_required_marker'])) { $score -= 0.15; $reasons[] = 'browser_or_challenge_marker'; }
        if (!empty($feature['is_asset_url'])) { $score -= 0.1; $reasons[] = 'asset_url'; }

        return [
            'url' => (string) ($feature['url'] ?? $feature['final_url'] ?? ''),
            'quality_score' => round(max(0.0, min(1.0, $score)), 3),
            'label' => $this->label($score),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /** @param array<string,mixed> $feature @return array<string,mixed> */
    public function recordQuality(array $feature): array
    {
        $fields = max(1, (int) ($feature['field_count'] ?? 0));
        $nonEmpty = (int) ($feature['non_empty_field_count'] ?? 0);
        $score = 0.15 + min(0.45, ($nonEmpty / $fields) * 0.45);
        $reasons = [];
        if (!empty($feature['has_dedupe_key'])) { $score += 0.1; } else { $reasons[] = 'missing_dedupe_key'; }
        if (!empty($feature['has_source_url'])) { $score += 0.1; } else { $reasons[] = 'missing_source_url'; }
        if ((int) ($feature['missing_field_count'] ?? 0) === 0) { $score += 0.1; } else { $reasons[] = 'missing_required_fields'; }
        if ((int) ($feature['error_count'] ?? 0) > 0) { $score -= 0.2; $reasons[] = 'validation_errors'; }
        if ((int) ($feature['warning_count'] ?? 0) > 0) { $score -= 0.05; $reasons[] = 'validation_warnings'; }
        $existing = (float) ($feature['quality_score'] ?? 0);
        if ($existing > 0) { $score = ($score + $existing) / 2; }

        return [
            'record_id' => (string) ($feature['record_id'] ?? ''),
            'record_type' => (string) ($feature['record_type'] ?? 'record'),
            'quality_score' => round(max(0.0, min(1.0, $score)), 3),
            'label' => $this->label($score),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function label(float $score): string
    {
        return $score >= 0.8 ? 'high' : ($score >= 0.5 ? 'medium' : 'low');
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function average(array $rows, string $field): float
    {
        if ($rows === []) { return 0.0; }
        $sum = 0.0;
        foreach ($rows as $row) { $sum += (float) ($row[$field] ?? 0); }
        return round($sum / count($rows), 3);
    }
}
