<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Plugin;

/**
 * Lightweight config-only plugin manifest.
 *
 * V4.0.2 deliberately does not auto-execute arbitrary plugin PHP code.
 * Plugins can contribute metadata, profile schemas, extractor rule files,
 * source templates, export templates, and command aliases that are resolved by
 * ScraperKit commands.
 */
final class PluginManifest
{
    /**
     * @param array<int,string> $profiles
     * @param array<int,string> $rules
     * @param array<int,array<string,mixed>> $commands
     * @param array<string,mixed> $sourceTemplates
     * @param array<string,mixed> $exportTemplates
     * @param array<string,mixed> $hooks
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $pluginId,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description = '',
        public readonly bool $enabled = true,
        public readonly array $profiles = [],
        public readonly array $rules = [],
        public readonly array $commands = [],
        public readonly array $sourceTemplates = [],
        public readonly array $exportTemplates = [],
        public readonly array $hooks = [],
        public readonly string $baseDir = '',
        public readonly string $manifestFile = '',
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $manifestFile = ''): self
    {
        $baseDir = $manifestFile !== '' ? dirname($manifestFile) : '';
        $pluginId = self::string($data['plugin_id'] ?? $data['id'] ?? $data['name'] ?? 'plugin');
        $name = self::string($data['name'] ?? $pluginId);

        return new self(
            pluginId: $pluginId,
            name: $name,
            version: self::string($data['version'] ?? '1.0.0'),
            description: self::string($data['description'] ?? ''),
            enabled: self::bool($data['enabled'] ?? true),
            profiles: self::list($data['profiles'] ?? []),
            rules: self::list($data['rules'] ?? $data['extractor_rules'] ?? []),
            commands: self::arrayList($data['commands'] ?? []),
            sourceTemplates: is_array($data['source_templates'] ?? null) ? $data['source_templates'] : [],
            exportTemplates: is_array($data['export_templates'] ?? null) ? $data['export_templates'] : [],
            hooks: is_array($data['hooks'] ?? null) ? $data['hooks'] : [],
            baseDir: $baseDir,
            manifestFile: $manifestFile,
            raw: $data,
        );
    }

    public static function fromFile(string $manifestFile): self
    {
        if (!is_file($manifestFile)) {
            throw new \RuntimeException('Plugin manifest not found: ' . $manifestFile);
        }
        $data = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Plugin manifest must be a JSON object: ' . $manifestFile);
        }
        return self::fromArray($data, $manifestFile);
    }

    /** @return array<string,mixed> */
    public function toArray(bool $includeResolved = true): array
    {
        $data = [
            'plugin_id' => $this->pluginId,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'profiles' => $this->profiles,
            'rules' => $this->rules,
            'commands' => $this->commands,
            'source_templates' => $this->sourceTemplates,
            'export_templates' => $this->exportTemplates,
            'hooks' => $this->hooks,
            'manifest_file' => $this->manifestFile,
        ];
        if ($includeResolved) {
            $data['resolved_profiles'] = $this->resolvedProfiles();
            $data['resolved_rules'] = $this->resolvedRules();
        }
        return $data;
    }

    /** @return list<string> */
    public function resolvedProfiles(): array
    {
        return $this->resolveExistingFiles($this->profiles);
    }

    /** @return list<string> */
    public function resolvedRules(): array
    {
        return $this->resolveExistingFiles($this->rules);
    }

    /** @param list<string> $paths @return list<string> */
    private function resolveExistingFiles(array $paths): array
    {
        $resolved = [];
        foreach ($paths as $path) {
            $candidate = $this->resolvePath($path);
            if (is_file($candidate)) {
                $resolved[] = $candidate;
            }
        }
        return array_values(array_unique($resolved));
    }

    public function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        return rtrim($this->baseDir, '/\\') . '/' . $path;
    }

    private static function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,|]/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,array<string,mixed>> */
    private static function arrayList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
