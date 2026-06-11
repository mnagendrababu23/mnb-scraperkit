<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

use Mnb\ScraperKit\Core\CrawlResult;

final class ProfessionalCrawlPipeline
{
    private RecordBuilder $builder;
    private TransformationPipeline $transformer;
    private ValidationPipeline $validator;
    private QualityScorer $qualityScorer;
    private DeduplicationPipeline $deduper;

    public function __construct()
    {
        $this->builder = new RecordBuilder();
        $this->transformer = new TransformationPipeline();
        $this->validator = new ValidationPipeline();
        $this->qualityScorer = new QualityScorer();
        $this->deduper = new DeduplicationPipeline();
    }

    public function run(CrawlResult $crawlResult, PipelineOptions $options): PipelineResult
    {
        return $this->runFromCrawlArray($crawlResult->toArray(false), $options);
    }

    /** @param array<string,mixed> $crawl */
    public function runFromCrawlArray(array $crawl, PipelineOptions $options): PipelineResult
    {
        $pages = is_array($crawl['pages'] ?? null) ? $crawl['pages'] : [];
        $result = new PipelineResult();
        $seen = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach ($this->builder->build($page, $options) as $record) {
                $record = $this->transformer->apply($record, $options);
                $issues = $this->validator->validate($record, $options);
                $score = $this->qualityScorer->score($record, $issues, $options);

                $validationStatus = $this->validationStatus($issues, $record);
                $record['quality_score'] = $score;
                $record['validation'] = [
                    'status' => $validationStatus,
                    'issues' => $issues,
                    'missing_fields' => $this->missingFields($issues),
                ];
                $record['_quality_score'] = $score;
                $record['_validation_status'] = $validationStatus;
                $record['_validation_issues'] = $issues;

                $dedupe = $this->deduper->check($record, $options, $seen);
                $record['dedupe_key'] = $dedupe['key'];
                $record['dedupe_raw'] = $dedupe['raw'];
                $record['_dedupe_key'] = $dedupe['key'];

                if ($dedupe['duplicate']) {
                    $record['_drop_reason'] = 'duplicate';
                    $record['validation']['status'] = 'duplicate';
                    $result->duplicates[] = $record;
                    continue;
                }

                if ($score < $options->minQuality) {
                    $record['_drop_reason'] = 'below_min_quality';
                    $result->dropped[] = $record;
                    continue;
                }

                if ($issues !== []) {
                    $result->validationIssues[] = [
                        'record_id' => $record['record_id'] ?? null,
                        'record_key' => $record['record_key'] ?? null,
                        'source_url' => $record['source_url'] ?? null,
                        'issues' => $issues,
                    ];
                }

                $result->records[] = $record;
            }
        }

        return $result;
    }

    /** @param array<int,array<string,string>> $issues @param array<string,mixed> $record */
    private function validationStatus(array $issues, array $record): string
    {
        if (($record['skipped'] ?? false) === true) {
            return 'skipped';
        }
        if (($record['error'] ?? null) !== null) {
            return 'invalid';
        }
        if ($issues === []) {
            return 'valid';
        }
        foreach ($issues as $issue) {
            if (($issue['rule'] ?? '') === 'required') {
                return 'partial';
            }
        }
        return 'warning';
    }

    /** @param array<int,array<string,string>> $issues @return array<int,string> */
    private function missingFields(array $issues): array
    {
        $missing = [];
        foreach ($issues as $issue) {
            if (($issue['rule'] ?? '') === 'required' && isset($issue['field'])) {
                $missing[] = $issue['field'];
            }
        }
        return array_values(array_unique($missing));
    }
}
