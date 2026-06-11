<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Template;

/**
 * Immutable config-only preset pack manifest.
 */
final class PresetPack
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly string $category,
        public readonly array $data,
        public readonly string $path
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $path = ''): self
    {
        $id = trim((string) ($data['id'] ?? ''));
        if ($id === '') {
            throw new \InvalidArgumentException('Preset pack missing required id.');
        }
        return new self(
            $id,
            trim((string) ($data['title'] ?? $id)),
            trim((string) ($data['description'] ?? '')),
            trim((string) ($data['category'] ?? 'general')),
            $data,
            $path
        );
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'profiles' => array_values(array_map('strval', (array) ($this->data['profiles'] ?? []))),
            'templates' => array_values(array_map('strval', (array) ($this->data['templates'] ?? []))),
            'files' => count((array) ($this->data['files'] ?? [])),
            'path' => $this->path,
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data + $this->summary();
    }
}
