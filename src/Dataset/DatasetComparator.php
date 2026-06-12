<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Dataset;

final class DatasetComparator
{
    /** @return array<string,mixed> */
    public function compare(array $oldRecords, array $newRecords): array
    {
        $old = $this->index($oldRecords);
        $new = $this->index($newRecords);
        $oldKeys = array_keys($old);
        $newKeys = array_keys($new);
        $added = array_values(array_diff($newKeys, $oldKeys));
        $removed = array_values(array_diff($oldKeys, $newKeys));
        $common = array_values(array_intersect($oldKeys, $newKeys));
        $changed = [];
        foreach ($common as $key) {
            if ($this->hashRecord($old[$key]) !== $this->hashRecord($new[$key])) {
                $changed[] = $key;
            }
        }
        return [
            'diff_version' => '4.2.1',
            'generated_at' => date(DATE_ATOM),
            'old_total' => count($oldRecords),
            'new_total' => count($newRecords),
            'added_total' => count($added),
            'removed_total' => count($removed),
            'common_total' => count($common),
            'changed_total' => count($changed),
            'added_keys' => $added,
            'removed_keys' => $removed,
            'changed_keys' => $changed,
        ];
    }

    /** @param array<int,array<string,mixed>> $records @return array<string,array<string,mixed>> */
    private function index(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $key = (string) ($record['dedupe_key'] ?? $record['dataset_record_id'] ?? $record['source_url'] ?? '');
            if ($key === '') {
                $key = 'row_' . count($out);
            }
            $out[$key] = $record;
        }
        return $out;
    }

    /** @param array<string,mixed> $record */
    private function hashRecord(array $record): string
    {
        unset($record['metadata']['input_index'], $record['metadata']['input_file']);
        ksort($record);
        return hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
