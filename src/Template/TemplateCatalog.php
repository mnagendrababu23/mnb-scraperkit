<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Template;

/**
 * Reads bundled project templates and preset packs from config directories.
 */
final class TemplateCatalog
{
    public function __construct(private readonly string $rootDir)
    {
    }

    /** @return list<ProjectTemplate> */
    public function templates(): array
    {
        $items = [];
        foreach ($this->jsonFiles($this->rootDir . '/config/project-templates') as $file) {
            $data = $this->readJson($file);
            $items[] = ProjectTemplate::fromArray($data, $file);
        }
        usort($items, static fn(ProjectTemplate $a, ProjectTemplate $b): int => strcmp($a->id, $b->id));
        return $items;
    }

    public function template(string $idOrPath): ProjectTemplate
    {
        $path = $this->resolveJson($idOrPath, $this->rootDir . '/config/project-templates');
        if ($path !== null) {
            return ProjectTemplate::fromArray($this->readJson($path), $path);
        }
        foreach ($this->templates() as $template) {
            if ($template->id === $idOrPath) {
                return $template;
            }
        }
        throw new \RuntimeException('Project template not found: ' . $idOrPath);
    }

    /** @return list<PresetPack> */
    public function presetPacks(): array
    {
        $items = [];
        foreach ($this->jsonFiles($this->rootDir . '/config/preset-packs') as $file) {
            $items[] = PresetPack::fromArray($this->readJson($file), $file);
        }
        usort($items, static fn(PresetPack $a, PresetPack $b): int => strcmp($a->id, $b->id));
        return $items;
    }

    public function presetPack(string $idOrPath): PresetPack
    {
        $path = $this->resolveJson($idOrPath, $this->rootDir . '/config/preset-packs');
        if ($path !== null) {
            return PresetPack::fromArray($this->readJson($path), $path);
        }
        foreach ($this->presetPacks() as $pack) {
            if ($pack->id === $idOrPath) {
                return $pack;
            }
        }
        throw new \RuntimeException('Preset pack not found: ' . $idOrPath);
    }

    /** @return array<string,mixed> */
    public function validateTemplate(string $idOrPath): array
    {
        $template = $this->template($idOrPath);
        $issues = [];
        if ($template->title === '') {
            $issues[] = 'Template title is empty.';
        }
        $files = (array) ($template->data['files'] ?? []);
        if ($files === []) {
            $issues[] = 'Template has no files.';
        }
        foreach ($files as $index => $file) {
            if (!is_array($file)) {
                $issues[] = 'File entry #' . ($index + 1) . ' must be an object.';
                continue;
            }
            $path = trim((string) ($file['path'] ?? ''));
            if ($path === '') {
                $issues[] = 'File entry #' . ($index + 1) . ' missing path.';
            }
            if (str_contains($path, '..')) {
                $issues[] = 'File entry #' . ($index + 1) . ' path must not contain ..';
            }
        }
        return ['ok' => $issues === [], 'template' => $template->summary(), 'issues' => $issues];
    }

    /** @return array<string,mixed> */
    public function validatePresetPack(string $idOrPath): array
    {
        $pack = $this->presetPack($idOrPath);
        $issues = [];
        if ($pack->title === '') {
            $issues[] = 'Preset pack title is empty.';
        }
        foreach ((array) ($pack->data['profiles'] ?? []) as $profile) {
            $profilePath = $this->rootDir . '/config/profiles/' . basename((string) $profile) . '.json';
            if (!is_file($profilePath)) {
                $issues[] = 'Referenced profile not found: ' . (string) $profile;
            }
        }
        foreach ((array) ($pack->data['templates'] ?? []) as $template) {
            try {
                $this->template((string) $template);
            } catch (\Throwable $e) {
                $issues[] = 'Referenced template not found: ' . (string) $template;
            }
        }
        return ['ok' => $issues === [], 'preset_pack' => $pack->summary(), 'issues' => $issues];
    }

    /** @return list<string> */
    private function jsonFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob(rtrim($dir, '/\\') . '/*.json') ?: [];
        sort($files);
        return array_values($files);
    }

    private function resolveJson(string $idOrPath, string $dir): ?string
    {
        if (is_file($idOrPath)) {
            return $idOrPath;
        }
        $relative = $this->rootDir . '/' . ltrim($idOrPath, '/\\');
        if (is_file($relative)) {
            return $relative;
        }
        $candidate = rtrim($dir, '/\\') . '/' . basename($idOrPath, '.json') . '.json';
        return is_file($candidate) ? $candidate : null;
    }

    /** @return array<string,mixed> */
    private function readJson(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON file: ' . $file);
        }
        return $data;
    }
}
