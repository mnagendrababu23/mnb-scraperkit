<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class QualityScorer
{
    /** @param array<string,mixed> $record @param array<int,array<string,string>> $issues */
    public function score(array $record, array $issues, PipelineOptions $options): int
    {
        $score = 100;

        if (($record['error'] ?? null) !== null) {
            $score -= 35;
        }
        if (($record['skipped'] ?? false) === true) {
            $score -= 25;
        }
        if (($record['status_code'] ?? 200) >= 400) {
            $score -= 30;
        }
        if (($record['title'] ?? null) === null && ($record['journal_name'] ?? null) === null && (($record['fields'] ?? []) === [])) {
            $score -= 15;
        }

        foreach ($issues as $issue) {
            $score -= (($issue['rule'] ?? '') === 'required') ? 15 : 7;
        }

        foreach ($options->requiredFields as $field) {
            $value = $record[$field] ?? ($record['fields'][$field] ?? null);
            if ($value === null || $value === '' || $value === []) {
                $score -= 10;
            }
        }

        $fieldCount = is_array($record['fields'] ?? null) ? count($record['fields']) : 0;
        if ($fieldCount >= 5) {
            $score += 5;
        }
        if (!empty($record['content_hash']) || !empty($record['dedupe_key'])) {
            $score += 3;
        }

        return max(0, min(100, $score));
    }
}
