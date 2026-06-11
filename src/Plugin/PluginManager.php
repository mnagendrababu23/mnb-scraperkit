<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Plugin;

final class PluginManager
{
    /** @var list<string> */
    private array $pluginDirs;

    /** @param list<string>|null $pluginDirs */
    public function __construct(private readonly string $rootDir, ?array $pluginDirs = null)
    {
        $this->pluginDirs = $pluginDirs ?: [
            rtrim($rootDir, '/\\') . '/plugins',
            rtrim($rootDir, '/\\') . '/storage/plugins',
        ];
    }

    /** @return list<string> */
    public function pluginDirs(): array
    {
        return $this->pluginDirs;
    }

    /** @return list<PluginManifest> */
    public function list(bool $enabledOnly = false): array
    {
        $items = [];
        foreach ($this->manifestFiles() as $file) {
            try {
                $manifest = PluginManifest::fromFile($file);
                if (!$enabledOnly || $manifest->enabled) {
                    $items[$manifest->pluginId] = $manifest;
                }
            } catch (\Throwable) {
                // Bad plugin manifests are shown by plugin:validate / plugin:doctor.
            }
        }
        ksort($items);
        return array_values($items);
    }

    public function get(string $pluginId): ?PluginManifest
    {
        foreach ($this->list(false) as $manifest) {
            if ($manifest->pluginId === $pluginId || $manifest->name === $pluginId) {
                return $manifest;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function profileFiles(bool $enabledOnly = true): array
    {
        $files = [];
        foreach ($this->list($enabledOnly) as $manifest) {
            foreach ($manifest->resolvedProfiles() as $file) {
                $files[] = $file;
            }
        }
        return array_values(array_unique($files));
    }

    /** @return array<int,array<string,mixed>> */
    public function commandAliases(bool $enabledOnly = true): array
    {
        $commands = [];
        foreach ($this->list($enabledOnly) as $manifest) {
            foreach ($manifest->commands as $command) {
                $command['plugin_id'] = $manifest->pluginId;
                $commands[] = $command;
            }
        }
        return $commands;
    }

    /** @return array{installed:bool,plugin_id:string,destination:string,manifest_file:string} */
    public function install(string $path, ?string $pluginId = null, bool $overwrite = false): array
    {
        $manifestFile = $this->resolveManifestFile($path);
        $validation = (new PluginValidator())->validateFile($manifestFile);
        if (!$validation['valid']) {
            $messages = array_map(static fn (array $issue): string => $issue['field'] . ': ' . $issue['message'], $validation['issues']);
            throw new \RuntimeException('Plugin manifest is invalid: ' . implode('; ', $messages));
        }
        $manifest = PluginManifest::fromFile($manifestFile);
        $targetId = $pluginId ?: $manifest->pluginId;
        $targetId = preg_replace('/[^a-z0-9_.-]+/i', '-', $targetId) ?: $manifest->pluginId;
        $destination = rtrim($this->rootDir, '/\\') . '/storage/plugins/' . $targetId;
        if (is_dir($destination) && !$overwrite) {
            throw new \RuntimeException('Plugin already installed. Use --force to overwrite: ' . $targetId);
        }
        if (is_dir($destination)) {
            $this->removeDir($destination);
        }
        $this->copyDir(dirname($manifestFile), $destination);
        return [
            'installed' => true,
            'plugin_id' => $targetId,
            'destination' => $destination,
            'manifest_file' => $destination . '/mnb-plugin.json',
        ];
    }

    /** @return array{plugin_id:string,enabled:bool,manifest_file:string} */
    public function setEnabled(string $pluginId, bool $enabled): array
    {
        $manifest = $this->get($pluginId);
        if (!$manifest instanceof PluginManifest || $manifest->manifestFile === '') {
            throw new \RuntimeException('Plugin not found: ' . $pluginId);
        }
        $data = $manifest->raw;
        $data['enabled'] = $enabled;
        file_put_contents($manifest->manifestFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['plugin_id' => $manifest->pluginId, 'enabled' => $enabled, 'manifest_file' => $manifest->manifestFile];
    }

    /** @return array{checked:int,valid:int,invalid:int,plugins:array<int,array<string,mixed>>} */
    public function doctor(): array
    {
        $plugins = [];
        $valid = 0;
        $invalid = 0;
        $validator = new PluginValidator();
        foreach ($this->manifestFiles() as $file) {
            $result = $validator->validateFile($file);
            $result['manifest_file'] = $file;
            $plugins[] = $result;
            if ($result['valid']) {
                $valid++;
            } else {
                $invalid++;
            }
        }
        return ['checked' => count($plugins), 'valid' => $valid, 'invalid' => $invalid, 'plugins' => $plugins];
    }

    public function resolveManifestFile(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }
        $path = rtrim($path, '/\\');
        foreach (['mnb-plugin.json', 'plugin.json'] as $name) {
            if (is_file($path . '/' . $name)) {
                return $path . '/' . $name;
            }
        }
        throw new \RuntimeException('Plugin manifest not found in path: ' . $path);
    }

    /** @return list<string> */
    private function manifestFiles(): array
    {
        $files = [];
        foreach ($this->pluginDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (['/*/mnb-plugin.json', '/*/plugin.json', '/*.mnb-plugin.json'] as $pattern) {
                foreach (glob(rtrim($dir, '/\\') . $pattern) ?: [] as $file) {
                    $files[] = $file;
                }
            }
        }
        return array_values(array_unique($files));
    }

    private function copyDir(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException('Plugin source directory not found: ' . $source);
        }
        mkdir($destination, 0775, true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
