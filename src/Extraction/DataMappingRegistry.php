<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

final class DataMappingRegistry
{
    /** @var array<string,array<string,list<string>>> */
    private array $mappings = [];

    public function __construct(?string $path = null)
    {
        $path = $path ?: dirname(__DIR__, 2) . '/config/extraction/default-mappings.json';
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data['mappings'] ?? null)) {
                foreach ($data['mappings'] as $name => $map) {
                    if (!is_array($map)) {
                        continue;
                    }
                    foreach ($map as $target => $sources) {
                        $this->mappings[(string) $name][(string) $target] = array_values(array_map('strval', (array) $sources));
                    }
                }
            }
        }
    }

    /** @return array<string,array<string,list<string>>> */
    public function all(): array
    {
        return $this->mappings;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function mapRecord(array $record, string $mappingName): array
    {
        $map = $this->mappings[$mappingName] ?? [];
        $out = [];
        foreach ($map as $target => $sources) {
            foreach ($sources as $source) {
                if (array_key_exists($source, $record) && $record[$source] !== null && $record[$source] !== '') {
                    $out[$target] = $record[$source];
                    break;
                }
            }
        }
        return $out + $record;
    }
}
