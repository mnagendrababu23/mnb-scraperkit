<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class PipelineResult
{
    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<int,array<string,mixed>> $duplicates
     * @param array<int,array<string,mixed>> $dropped
     * @param array<int,array<string,mixed>> $validationIssues
     */
    public function __construct(
        public array $records = [],
        public array $duplicates = [],
        public array $dropped = [],
        public array $validationIssues = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $qualityTotal = 0;
        $qualityCount = 0;
        $statusCounts = [];
        foreach ($this->records as $record) {
            $score = $record['quality_score'] ?? $record['_quality_score'] ?? null;
            if ($score !== null) {
                $qualityTotal += (int) $score;
                $qualityCount++;
            }
            $status = (string) ($record['validation']['status'] ?? $record['_validation_status'] ?? 'unknown');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        return [
            'records_total' => count($this->records),
            'duplicates_removed' => count($this->duplicates),
            'records_dropped' => count($this->dropped),
            'validation_issues' => count($this->validationIssues),
            'validation_status_counts' => $statusCounts,
            'average_quality_score' => $qualityCount > 0 ? round($qualityTotal / $qualityCount, 2) : null,
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary(),
            'records' => $this->records,
            'duplicates' => $this->duplicates,
            'dropped' => $this->dropped,
            'validation_issues' => $this->validationIssues,
        ];
    }
}
