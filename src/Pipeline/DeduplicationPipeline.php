<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class DeduplicationPipeline
{
    /**
     * @param array<string,mixed> $record
     * @param array<string,bool> $seen
     * @return array{duplicate:bool,key:string,raw:string}
     */
    public function check(array $record, PipelineOptions $options, array &$seen): array
    {
        $parts = [];
        foreach ($options->dedupeKeys as $field) {
            $value = $record[$field] ?? ($record['fields'][$field] ?? null);
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $parts[] = trim((string) $value);
        }

        $raw = implode('|', array_filter($parts, static fn (string $v): bool => $v !== ''));
        if ($raw === '') {
            $raw = (string) ($record['record_key'] ?? ($record['final_url'] ?? ($record['source_url'] ?? spl_object_id((object) $record))));
        }

        $key = hash('sha256', strtolower($raw));
        if (isset($seen[$key])) {
            return ['duplicate' => true, 'key' => $key, 'raw' => $raw];
        }
        $seen[$key] = true;
        return ['duplicate' => false, 'key' => $key, 'raw' => $raw];
    }
}
