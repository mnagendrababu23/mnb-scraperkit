<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Export;

final class ExportManifestBuilder
{
    public const VERSION = '1.0.0';

    /** @param list<string> $paths @param list<string> $allowedExtensions @return array<string,mixed> */
    public function build(array $paths, array $allowedExtensions = []): array
    {
        $files = [];
        $extensions = array_map('strtolower', $allowedExtensions ?: ['json', 'csv', 'xml', 'html', 'txt', 'jsonl', 'zip']);
        foreach ($this->expandPaths($paths) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extensions !== [] && !in_array($ext, $extensions, true)) {
                continue;
            }
            $files[] = [
                'path' => $file,
                'name' => basename($file),
                'extension' => $ext,
                'size_bytes' => filesize($file) ?: 0,
                'sha256' => hash_file('sha256', $file) ?: '',
                'modified_at' => date(DATE_ATOM, filemtime($file) ?: time()),
            ];
        }
        usort($files, static fn(array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));
        return [
            'export_manifest_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'files_total' => count($files),
            'total_bytes' => array_sum(array_map(static fn(array $f): int => (int) ($f['size_bytes'] ?? 0), $files)),
            'files' => $files,
        ];
    }

    /** @param list<string> $paths @return list<string> */
    private function expandPaths(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if ($item instanceof \SplFileInfo && $item->isFile()) {
                    $files[] = $item->getPathname();
                }
            }
        }
        return array_values(array_unique($files));
    }
}
