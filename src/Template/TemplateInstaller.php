<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Template;

/**
 * Materializes project templates and preset packs into user folders.
 */
final class TemplateInstaller
{
    public function __construct(private readonly string $rootDir)
    {
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function createProject(ProjectTemplate $template, string $outputDir, array $variables = [], bool $force = false): array
    {
        $dir = $this->absolutePath($outputDir);
        $createdAt = date(DATE_ATOM);
        $vars = array_merge([
            'template_id' => $template->id,
            'template_title' => $template->title,
            'profile' => $template->profile,
            'project_name' => (string) ($variables['project_name'] ?? $variables['name'] ?? $template->id . '-project'),
            'created_at' => $createdAt,
        ], $variables);

        $this->ensureDir($dir);
        $created = [];
        foreach ((array) ($template->data['files'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $relative = $this->safeRelativePath((string) ($entry['path'] ?? ''));
            $path = $dir . '/' . $relative;
            if (is_file($path) && !$force) {
                throw new \RuntimeException('Refusing to overwrite existing file without --force: ' . $path);
            }
            $this->ensureDir(dirname($path));
            $type = strtolower((string) ($entry['type'] ?? 'text'));
            if ($type === 'json') {
                $content = $this->replaceInData($entry['content'] ?? [], $vars);
                file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $content = $this->replaceVars((string) ($entry['content'] ?? ''), $vars);
                file_put_contents($path, $content);
            }
            $created[] = $path;
        }

        $manifest = [
            'project_template_version' => '4.2.0',
            'template' => $template->summary(),
            'project_name' => $vars['project_name'],
            'created_at' => $createdAt,
            'created_files' => array_map(static fn(string $path): string => basename($path), $created),
            'variables' => $vars,
        ];
        $manifestPath = $dir . '/mnb-project.json';
        if (!is_file($manifestPath) || $force) {
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $created[] = $manifestPath;
        }

        return [
            'ok' => true,
            'template_id' => $template->id,
            'project_dir' => $dir,
            'files_created' => $created,
            'next_commands' => (array) ($template->data['next_commands'] ?? []),
        ];
    }

    /** @return array<string,mixed> */
    public function installPresetPack(PresetPack $pack, string $outputDir, bool $force = false): array
    {
        $dir = $this->absolutePath($outputDir);
        $this->ensureDir($dir);
        $created = [];

        foreach ((array) ($pack->data['profiles'] ?? []) as $profile) {
            $src = $this->rootDir . '/config/profiles/' . basename((string) $profile) . '.json';
            if (is_file($src)) {
                $dst = $dir . '/profiles/' . basename($src);
                $this->copyFile($src, $dst, $force);
                $created[] = $dst;
            }
        }

        foreach ((array) ($pack->data['files'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $relative = $this->safeRelativePath((string) ($entry['path'] ?? ''));
            $path = $dir . '/' . $relative;
            if (is_file($path) && !$force) {
                throw new \RuntimeException('Refusing to overwrite existing file without --force: ' . $path);
            }
            $this->ensureDir(dirname($path));
            $type = strtolower((string) ($entry['type'] ?? 'text'));
            if ($type === 'json') {
                file_put_contents($path, json_encode($entry['content'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                file_put_contents($path, (string) ($entry['content'] ?? ''));
            }
            $created[] = $path;
        }

        $manifest = $pack->toArray();
        $manifest['installed_at'] = date(DATE_ATOM);
        $manifest['preset_pack_version'] = '4.2.0';
        $manifestPath = $dir . '/mnb-preset-pack.json';
        if (!is_file($manifestPath) || $force) {
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $created[] = $manifestPath;
        }

        return [
            'ok' => true,
            'preset_pack_id' => $pack->id,
            'output_dir' => $dir,
            'files_created' => $created,
            'profiles' => (array) ($pack->data['profiles'] ?? []),
            'templates' => (array) ($pack->data['templates'] ?? []),
        ];
    }

    private function copyFile(string $src, string $dst, bool $force): void
    {
        if (is_file($dst) && !$force) {
            throw new \RuntimeException('Refusing to overwrite existing file without --force: ' . $dst);
        }
        $this->ensureDir(dirname($dst));
        copy($src, $dst);
    }

    /** @param array<string,mixed> $vars */
    private function replaceVars(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $text = str_replace('{{' . $key . '}}', (string) $value, $text);
            }
        }
        return $text;
    }

    /** @param array<string,mixed> $vars */
    private function replaceInData(mixed $data, array $vars): mixed
    {
        if (is_string($data)) {
            return $this->replaceVars($data, $vars);
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                $out[$key] = $this->replaceInData($value, $vars);
            }
            return $out;
        }
        return $data;
    }

    private function safeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Unsafe template file path: ' . $path);
        }
        return $path;
    }

    private function absolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($this->isAbsolutePath($normalized)) {
            return rtrim($normalized, '/');
        }
        return rtrim($this->rootDir . '/' . ltrim($normalized, '/'), '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
