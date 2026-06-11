<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class RecordBuilder
{
    /**
     * @param array<string,mixed> $page
     * @return array<int,array<string,mixed>>
     */
    public function build(array $page, PipelineOptions $options): array
    {
        $protection = is_array($page['protection'] ?? null) ? $page['protection'] : [];
        if (($protection['is_challenge'] ?? false) === true) {
            return [];
        }
        if (($page['skipped'] ?? false) && !$options->includeSkippedPages) {
            return [];
        }
        if (($page['error'] ?? null) && !$options->includeFailedPages) {
            return [];
        }

        $records = [];
        $extracted = is_array($page['extracted'] ?? null) ? $page['extracted'] : [];
        $preset = is_array($extracted['_preset'] ?? null) ? $extracted['_preset'] : [];

        if ($options->preferPresetRecords && isset($preset['journals']) && is_array($preset['journals'])) {
            foreach ($preset['journals'] as $journal) {
                if (!is_array($journal)) {
                    continue;
                }
                $record = $this->baseRecord($page, 'journal', $options);
                $record['journal_name'] = $this->string($journal['name'] ?? null);
                $record['journal_url'] = $this->string($journal['url'] ?? null);
                $record['journal_id'] = $this->string($journal['slug_or_id'] ?? ($journal['id'] ?? null));
                $record['record_key'] = $record['journal_url'] ?: ($record['journal_name'] . '|' . $record['source_url']);
                $records[] = $this->finalizeRecord($this->withRuleData($this->withCommonData($record, $extracted), $extracted));
            }
            if ($records !== []) {
                return $records;
            }
        }

        $record = $this->baseRecord($page, $options->recordType ?: ($options->profile !== 'page' ? $options->profile : 'page'), $options);
        $record['record_key'] = $record['final_url'] ?: $record['source_url'];
        $record['page_title'] = $record['title'];
        $record['page_description'] = $this->string($page['meta']['description'] ?? null);
        $record['canonical_url'] = $this->string($page['meta']['canonical'] ?? null);
        $records[] = $this->finalizeRecord($this->withRuleData($this->withCommonData($record, $extracted), $extracted));

        return $records;
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    private function baseRecord(array $page, string $type, PipelineOptions $options): array
    {
        $sourceUrl = $this->string($page['url'] ?? null);
        return [
            'record_id' => null,
            'record_type' => $type,
            'profile' => $options->profile,
            'preset' => $options->preset,
            'source_url' => $sourceUrl,
            'source_page_url' => $sourceUrl, // Backward-compatible alias.
            'final_url' => $this->string($page['final_url'] ?? null),
            'raw_final_url' => $this->string($page['raw_final_url'] ?? null),
            'status_code' => isset($page['status_code']) ? (int) $page['status_code'] : null,
            'title' => $this->string($page['title'] ?? null),
            'content_hash' => $this->string($page['content_hash'] ?? null),
            'failure_type' => $this->string($page['failure_type'] ?? null),
            'error' => $this->string($page['error'] ?? null),
            'skipped' => (bool) ($page['skipped'] ?? false),
            'skip_reason' => $this->string($page['skip_reason'] ?? null),
            'depth' => isset($page['depth']) ? (int) $page['depth'] : null,
            'response_time_ms' => isset($page['response_time_ms']) ? (int) $page['response_time_ms'] : null,
            'redirect_count' => isset($page['redirect_count']) ? (int) $page['redirect_count'] : null,
            'detected_encoding' => $this->string($page['detected_encoding'] ?? null),
            'fields' => [],
            'source_trace' => [
                'source_url' => $sourceUrl,
                'final_url' => $this->string($page['final_url'] ?? null),
                'depth' => isset($page['depth']) ? (int) $page['depth'] : null,
                'content_hash' => $this->string($page['content_hash'] ?? null),
            ],
        ];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $extracted @return array<string,mixed> */
    private function withCommonData(array $record, array $extracted): array
    {
        $common = is_array($extracted['_common_data'] ?? null) ? $extracted['_common_data'] : [];
        if ($common === []) {
            return $record;
        }

        $counts = is_array($common['counts'] ?? null) ? $common['counts'] : [];
        foreach ($counts as $name => $count) {
            $record['common_' . preg_replace('/[^a-z0-9_]+/i', '_', (string) $name) . '_count'] = (int) $count;
        }

        foreach (['emails', 'phones', 'fax_numbers', 'dates', 'deadlines', 'doi', 'issns', 'isbns', 'orcids', 'pdf_links', 'document_links', 'social_links', 'submission_links', 'organizations', 'affiliations'] as $key) {
            if (isset($common[$key]) && is_array($common[$key])) {
                $record[$key] = $common[$key];
                $record[$key . '_text'] = implode(' | ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $common[$key]));
            }
        }

        return $record;
    }


    /** @param array<string,mixed> $record @param array<string,mixed> $extracted @return array<string,mixed> */
    private function withRuleData(array $record, array $extracted): array
    {
        foreach ($extracted as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || str_starts_with($key, '_')) {
                continue;
            }
            $record[$key] = $value;
            if (is_array($value)) {
                $record[$key . '_text'] = implode(' | ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $value));
            }
        }
        return $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function finalizeRecord(array $record): array
    {
        $fields = [];
        foreach ($record as $key => $value) {
            if (in_array($key, [
                'record_id', 'record_type', 'profile', 'preset', 'source_url', 'source_page_url', 'final_url', 'raw_final_url',
                'status_code', 'content_hash', 'failure_type', 'error', 'skipped', 'skip_reason', 'depth', 'response_time_ms',
                'redirect_count', 'detected_encoding', 'record_key', 'fields', 'source_trace',
            ], true)) {
                continue;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $fields[$key] = $value;
            }
        }
        $record['fields'] = $fields;
        $record['record_id'] = $this->recordId($record);
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function recordId(array $record): string
    {
        $base = implode('|', [
            (string) ($record['record_type'] ?? ''),
            (string) ($record['record_key'] ?? ''),
            (string) ($record['source_url'] ?? ''),
            (string) ($record['final_url'] ?? ''),
        ]);
        return 'rec_' . substr(hash('sha256', strtolower($base)), 0, 24);
    }

    private function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
