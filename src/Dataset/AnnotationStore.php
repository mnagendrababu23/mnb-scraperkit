<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Dataset;

final class AnnotationStore
{
    public const VERSION = '4.2.1';

    /** @return array<string,mixed> */
    public function init(string $datasetDir, ?string $output = null): array
    {
        $manifestFile = rtrim($datasetDir, '/\\') . '/dataset-manifest.json';
        if (!is_file($manifestFile)) {
            throw new \RuntimeException('Dataset manifest not found in: ' . $datasetDir);
        }
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Invalid dataset manifest: ' . $manifestFile);
        }
        $output = $output ?: rtrim($datasetDir, '/\\') . '/annotations.json';
        $data = [
            'annotation_version' => self::VERSION,
            'dataset_id' => (string) ($manifest['dataset_id'] ?? basename($datasetDir)),
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
            'annotations' => [],
        ];
        $this->writeJson($output, $data);
        return ['ok' => true, 'output' => $output, 'annotations_total' => 0, 'data' => $data];
    }

    /** @return array<string,mixed> */
    public function add(string $annotationsFile, string $recordId, string $label, ?string $note = null, ?string $field = null, ?string $user = null): array
    {
        $data = $this->readOrCreate($annotationsFile);
        $annotation = [
            'record_id' => $recordId,
            'label' => $label,
            'field' => $field,
            'note' => $note,
            'user' => $user,
            'updated_at' => date(DATE_ATOM),
        ];
        $items = is_array($data['annotations'] ?? null) ? (array) $data['annotations'] : [];
        $items[] = $annotation;
        $data['annotations'] = $items;
        $data['updated_at'] = date(DATE_ATOM);
        $this->writeJson($annotationsFile, $data);
        return ['ok' => true, 'output' => $annotationsFile, 'annotations_total' => count($items), 'annotation' => $annotation];
    }

    /** @return array<string,mixed> */
    private function readOrCreate(string $path): array
    {
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return ['annotation_version' => self::VERSION, 'created_at' => date(DATE_ATOM), 'annotations' => []];
    }

    /** @param array<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
