<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

final class ExtractionRecipe
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data, private ?string $path = null)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Extraction recipe not found: ' . $path);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Extraction recipe JSON is invalid: ' . $path);
        }
        return new self($data, $path);
    }

    /** @return list<array<string,mixed>> */
    public static function catalog(?string $dir = null): array
    {
        $dir = $dir ?: dirname(__DIR__, 2) . '/config/extraction/recipes';
        $recipes = [];
        foreach (glob(rtrim($dir, '/\\') . '/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            $recipes[] = [
                'id' => (string) ($data['id'] ?? basename($path, '.json')),
                'name' => (string) ($data['name'] ?? basename($path, '.json')),
                'source_type' => (string) ($data['source_type'] ?? 'page'),
                'version' => (string) ($data['recipe_version'] ?? '4.2.1'),
                'fields_total' => count((array) ($data['fields'] ?? [])),
                'path' => $path,
            ];
        }
        usort($recipes, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
        return $recipes;
    }

    public function id(): string
    {
        return (string) ($this->data['id'] ?? ($this->path ? basename($this->path, '.json') : 'custom'));
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? $this->id());
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string,array<string,mixed>> */
    public function fields(): array
    {
        $fields = [];
        foreach ((array) ($this->data['fields'] ?? []) as $name => $definition) {
            if (is_string($definition)) {
                $fields[(string) $name] = ['selectors' => [$definition]];
                continue;
            }
            if (is_array($definition)) {
                if (array_is_list($definition)) {
                    $fields[(string) $name] = ['selectors' => array_values(array_map('strval', $definition))];
                } else {
                    $fields[(string) $name] = $definition;
                }
            }
        }
        return $fields;
    }

    /** @return list<string> */
    public function requiredFields(): array
    {
        $required = [];
        foreach ($this->fields() as $field => $definition) {
            if (($definition['required'] ?? false) === true) {
                $required[] = $field;
            }
        }
        return $required ?: array_values(array_map('strval', (array) ($this->data['required_fields'] ?? [])));
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        return is_array($this->data['options'] ?? null) ? $this->data['options'] : [];
    }

    /** @return array<string,mixed> */
    public function components(): array
    {
        return is_array($this->data['components'] ?? null) ? $this->data['components'] : [];
    }
}
