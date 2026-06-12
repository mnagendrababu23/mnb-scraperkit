<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

final class ExtractionQualityReporter
{
    /** @param array<string,mixed> $result @param list<string> $requiredFields @return array<string,mixed> */
    public function report(array $result, array $requiredFields = []): array
    {
        $record = $this->recordFromResult($result);
        $provenance = is_array($result['_provenance'] ?? null) ? $result['_provenance'] : [];
        $components = is_array($result['components'] ?? null) ? $result['components'] : $result;
        $missingRequired = [];
        foreach ($requiredFields as $field) {
            $value = $record[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missingRequired[] = $field;
            }
        }
        $fieldsPresent = 0;
        $emptyFields = [];
        foreach ($record as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                $emptyFields[] = $field;
            } else {
                $fieldsPresent++;
            }
        }
        $requiredTotal = count($requiredFields);
        $requiredFound = max(0, $requiredTotal - count($missingRequired));
        $requiredCompleteness = $requiredTotal > 0 ? (int) round(($requiredFound / $requiredTotal) * 100) : 100;
        $componentCounts = [];
        foreach (['links', 'images', 'pdf_files', 'tables', 'lists', 'headings', 'cards', 'repeated_components', 'download_links', 'social_links'] as $key) {
            if (isset($components[$key])) {
                $componentCounts[$key] = is_array($components[$key]) ? count($components[$key]) : 1;
            }
        }
        $provenanceFound = 0;
        foreach ($provenance as $row) {
            if (is_array($row) && ($row['status'] ?? null) === 'found') {
                $provenanceFound++;
            }
        }
        $score = 60;
        $score += (int) round($requiredCompleteness * 0.30);
        $score += min(10, $fieldsPresent * 2);
        $score += min(10, $provenanceFound * 2);
        if (($componentCounts['repeated_components'] ?? 0) > 0) {
            $score += 5;
        }
        if (($componentCounts['links'] ?? 0) > 0) {
            $score += 3;
        }
        $score = max(0, min(100, $score - (count($missingRequired) * 12)));
        return [
            'quality_version' => '4.3.1',
            'generated_at' => date(DATE_ATOM),
            'score' => $score,
            'label' => $score >= 90 ? 'excellent' : ($score >= 75 ? 'good' : ($score >= 60 ? 'needs_review' : 'weak')),
            'fields_total' => count($record),
            'fields_present' => $fieldsPresent,
            'empty_fields' => $emptyFields,
            'required_fields' => $requiredFields,
            'missing_required_fields' => $missingRequired,
            'required_completeness_percent' => $requiredCompleteness,
            'component_counts' => $componentCounts,
            'provenance_fields_total' => count($provenance),
            'provenance_found_total' => $provenanceFound,
            'recommendations' => $this->recommendations($missingRequired, $componentCounts, $provenanceFound),
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function recordFromResult(array $result): array
    {
        if (is_array($result['record'] ?? null)) {
            return $result['record'];
        }
        if (is_array($result['records'] ?? null) && isset($result['records'][0]) && is_array($result['records'][0])) {
            return $result['records'][0];
        }
        $skip = ['options', 'counts', '_provenance', 'components', 'quality', 'whole_html', 'plain_text'];
        $record = [];
        foreach ($result as $key => $value) {
            if (!in_array((string) $key, $skip, true) && !str_starts_with((string) $key, '_')) {
                $record[(string) $key] = $value;
            }
        }
        return $record;
    }

    /** @param list<string> $missingRequired @param array<string,int> $componentCounts @return list<string> */
    private function recommendations(array $missingRequired, array $componentCounts, int $provenanceFound): array
    {
        $out = [];
        if ($missingRequired !== []) {
            $out[] = 'Add or adjust recipe selectors for missing required fields: ' . implode(', ', $missingRequired) . '.';
        }
        if ($provenanceFound === 0) {
            $out[] = 'Enable recipe/provenance extraction so each field records its selector and confidence.';
        }
        if (($componentCounts['repeated_components'] ?? 0) === 0) {
            $out[] = 'For list/card pages, lower --min-repeats or add a specific --selector/recipe component selector.';
        }
        if (($componentCounts['links'] ?? 0) === 0) {
            $out[] = 'No links were extracted; check base URL, parser support, or blocked/JavaScript-heavy HTML.';
        }
        return $out ?: ['Extraction looks healthy. Review provenance selectors before using results in automation or training workflows.'];
    }
}
