<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Intelligence;

final class PageClassifier
{
    /** @param array<int,array<string,mixed>> $pageFeatures @return array<string,mixed> */
    public function classifyFeatureSet(array $pageFeatures): array
    {
        $rows = [];
        foreach ($pageFeatures as $feature) {
            $rows[] = $this->classify($feature);
        }
        return [
            'intelligence_version' => '3.2.0',
            'generated_at' => date(DATE_ATOM),
            'classifications_total' => count($rows),
            'class_counts' => $this->counts($rows, 'class'),
            'rows' => $rows,
        ];
    }

    /** @param array<string,mixed> $feature @return array<string,mixed> */
    public function classify(array $feature): array
    {
        $scores = [
            'article' => 0.0,
            'ecommerce' => 0.0,
            'job' => 0.0,
            'tender' => 0.0,
            'contact' => 0.0,
            'document' => 0.0,
            'seo_page' => 0.0,
            'js_app' => 0.0,
            'error_or_blocked' => 0.0,
        ];

        if (!empty($feature['looks_article'])) { $scores['article'] += 0.35; }
        if (!empty($feature['looks_product']) || !empty($feature['has_price_hint'])) { $scores['ecommerce'] += 0.4; }
        if (!empty($feature['looks_job'])) { $scores['job'] += 0.45; }
        if (!empty($feature['looks_tender'])) { $scores['tender'] += 0.45; }
        if (!empty($feature['has_email']) || !empty($feature['has_phone_hint'])) { $scores['contact'] += 0.25; }
        if (!empty($feature['is_document_url'])) { $scores['document'] += 0.8; }
        if (!empty($feature['has_schema_hint']) || (int) ($feature['heading_count'] ?? 0) > 0) { $scores['seo_page'] += 0.2; }
        if (!empty($feature['has_js_app_marker']) || !empty($feature['has_browser_required_marker']) || ((int) ($feature['text_length'] ?? 0) < 250 && (int) ($feature['script_count'] ?? 0) > 5)) { $scores['js_app'] += 0.6; }
        if ((int) ($feature['http_status'] ?? 0) >= 400 || trim((string) ($feature['failure_type'] ?? '')) !== '') { $scores['error_or_blocked'] += 0.75; }

        if ((int) ($feature['word_count'] ?? 0) > 500) { $scores['article'] += 0.2; }
        if ((int) ($feature['link_count'] ?? 0) > 20) { $scores['seo_page'] += 0.15; }

        arsort($scores);
        $class = (string) array_key_first($scores);
        $confidence = round((float) current($scores), 3);
        if ($confidence <= 0.0) {
            $class = 'unknown';
        }

        return [
            'url' => (string) ($feature['url'] ?? $feature['final_url'] ?? ''),
            'final_url' => (string) ($feature['final_url'] ?? ''),
            'class' => $class,
            'confidence' => min(1.0, $confidence),
            'scores' => $scores,
            'recommended_profile' => $this->recommendedProfile($class),
            'browser_recommended' => $class === 'js_app',
        ];
    }

    private function recommendedProfile(string $class): string
    {
        return match ($class) {
            'article' => 'academic',
            'ecommerce' => 'ecommerce',
            'job' => 'jobs',
            'tender' => 'tender',
            'contact' => 'contact',
            'seo_page', 'js_app' => 'seo',
            default => 'default',
        };
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,int> */
    private function counts(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$field] ?? 'unknown')) ?: 'unknown';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }
}
