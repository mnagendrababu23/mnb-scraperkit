<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

final class PatternRegistry
{
    /** @var array<string,string> */
    private array $patterns = [];

    public function __construct(?string $path = null)
    {
        $path = $path ?: dirname(__DIR__, 2) . '/config/extraction/default-patterns.json';
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            $source = is_array($data['patterns'] ?? null) ? $data['patterns'] : $data;
            if (is_array($source)) {
                foreach ($source as $name => $pattern) {
                    if (is_string($pattern) && $pattern !== '') {
                        $this->patterns[(string) $name] = $pattern;
                    }
                }
            }
        }
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->patterns;
    }

    /** @return array<string,list<string>> */
    public function match(string $text): array
    {
        $out = [];
        foreach ($this->patterns as $name => $pattern) {
            $regex = '~' . str_replace('~', '\\~', $pattern) . '~iu';
            if (@preg_match_all($regex, $text, $m) === false) {
                continue;
            }
            $values = array_values(array_unique(array_filter(array_map('trim', $m[0] ?? []))));
            if ($values !== []) {
                $out[$name] = $values;
            }
        }
        return $out;
    }
}
