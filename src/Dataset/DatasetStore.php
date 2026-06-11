<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Dataset;

final class DatasetStore
{
    public const VERSION = '4.0.1';

    public function __construct(private readonly string $rootDir, private readonly ?string $datasetsDir = null)
    {
    }

    public function datasetsDir(): string
    {
        return $this->datasetsDir ?: rtrim($this->rootDir, '/\\') . '/storage/datasets';
    }

    /** @return array<string,mixed> */
    public function create(string $inputFile, ?string $datasetId = null, string $type = 'auto', ?string $outputDir = null): array
    {
        return (new DatasetBuilder())->createFromFile($inputFile, $outputDir ?: $this->datasetsDir(), $datasetId, $type);
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        $dir = $this->datasetsDir();
        if (!is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach (glob($dir . '/*/dataset-manifest.json') ?: [] as $manifestFile) {
            $manifest = $this->readJson($manifestFile);
            if ($manifest !== null) {
                $manifest['_manifest_file'] = $manifestFile;
                $manifest['_dataset_dir'] = dirname($manifestFile);
                $items[] = $manifest;
            }
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $items;
    }

    /** @return array<string,mixed> */
    public function show(string $idOrPath): array
    {
        $path = $this->resolveManifest($idOrPath);
        $manifest = $this->readJson($path);
        if ($manifest === null) {
            throw new \RuntimeException('Dataset manifest not found: ' . $idOrPath);
        }
        $manifest['_manifest_file'] = $path;
        $manifest['_dataset_dir'] = dirname($path);
        return $manifest;
    }

    public function resolveManifest(string $idOrPath): string
    {
        if (is_file($idOrPath)) {
            return $idOrPath;
        }
        if (is_dir($idOrPath) && is_file(rtrim($idOrPath, '/\\') . '/dataset-manifest.json')) {
            return rtrim($idOrPath, '/\\') . '/dataset-manifest.json';
        }
        $path = $this->datasetsDir() . '/' . $idOrPath . '/dataset-manifest.json';
        if (is_file($path)) {
            return $path;
        }
        throw new \RuntimeException('Dataset not found: ' . $idOrPath);
    }

    /** @return array<int,array<string,mixed>> */
    public function records(string $idOrPath): array
    {
        $manifest = $this->show($idOrPath);
        $recordsFile = dirname((string) $manifest['_manifest_file']) . '/' . (string) ($manifest['records_file'] ?? 'records.json');
        $data = $this->readJson($recordsFile);
        if (isset($data['records']) && is_array($data['records'])) {
            return array_values(array_filter($data['records'], 'is_array'));
        }
        return [];
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
