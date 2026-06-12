<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Export;

/**
 * Loads safe export-delivery connector definitions.
 *
 * Connectors are configuration-only in v1.0.1. The default local connector
 * writes/copies export artifacts into storage/export-deliveries. Webhook
 * connectors produce a signed delivery payload and only send when explicitly
 * requested by the user.
 */
final class ExportConnectorStore
{
    public const VERSION = '1.0.1';

    public function __construct(private readonly string $rootDir)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(?string $configFile = null): array
    {
        $config = $this->loadConfig($configFile);
        $connectors = [];
        foreach ((array) ($config['connectors'] ?? []) as $connector) {
            if (is_array($connector)) {
                $connectors[] = $this->normalize($connector);
            }
        }
        if ($connectors === []) {
            $connectors[] = $this->defaultLocalConnector();
        }
        return $connectors;
    }

    /** @return array<string,mixed> */
    public function show(string $id, ?string $configFile = null): array
    {
        foreach ($this->list($configFile) as $connector) {
            if (($connector['id'] ?? '') === $id) {
                return $connector;
            }
        }
        throw new \InvalidArgumentException('Export connector not found: ' . $id);
    }

    /** @return array<string,mixed> */
    public function validate(?string $configFile = null): array
    {
        $issues = [];
        $ids = [];
        foreach ($this->list($configFile) as $connector) {
            $id = (string) ($connector['id'] ?? '');
            $type = (string) ($connector['type'] ?? '');
            if ($id === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $id)) {
                $issues[] = ['connector' => $id, 'message' => 'Connector id must use letters, numbers, underscore, dot, or dash.'];
            }
            if (isset($ids[$id])) {
                $issues[] = ['connector' => $id, 'message' => 'Duplicate connector id.'];
            }
            $ids[$id] = true;
            if (!in_array($type, ['local', 'webhook'], true)) {
                $issues[] = ['connector' => $id, 'message' => 'Unsupported connector type: ' . $type];
            }
            if ($type === 'local') {
                $target = (string) ($connector['target_dir'] ?? '');
                if ($target === '') {
                    $issues[] = ['connector' => $id, 'message' => 'Local connector requires target_dir.'];
                }
                if (str_contains($target, '..')) {
                    $issues[] = ['connector' => $id, 'message' => 'target_dir must not contain path traversal.'];
                }
            }
            if ($type === 'webhook') {
                $endpoint = (string) ($connector['endpoint'] ?? '');
                if (!preg_match('#^https?://#i', $endpoint)) {
                    $issues[] = ['connector' => $id, 'message' => 'Webhook endpoint must be http or https.'];
                }
            }
        }
        return [
            'export_connector_version' => self::VERSION,
            'ok' => $issues === [],
            'issues' => $issues,
            'connectors_total' => count($this->list($configFile)),
        ];
    }

    /** @return array<string,mixed> */
    private function loadConfig(?string $configFile): array
    {
        $file = $configFile ?: $this->rootDir . '/config/export-connectors.json';
        if (!is_file($file)) {
            $file = $this->rootDir . '/config/export-connectors.example.json';
        }
        if (!is_file($file)) {
            return ['connectors' => [$this->defaultLocalConnector()]];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid export connector config JSON: ' . $file);
        }
        return $data;
    }

    /** @param array<string,mixed> $connector @return array<string,mixed> */
    private function normalize(array $connector): array
    {
        $connector['id'] = (string) ($connector['id'] ?? '');
        $connector['type'] = strtolower((string) ($connector['type'] ?? 'local'));
        $connector['enabled'] = (bool) ($connector['enabled'] ?? true);
        $connector['description'] = (string) ($connector['description'] ?? '');
        $connector['allowed_extensions'] = array_values(array_map('strtolower', array_filter((array) ($connector['allowed_extensions'] ?? ['json', 'csv', 'xml', 'html', 'txt', 'jsonl', 'zip']))));
        $connector['metadata'] = is_array($connector['metadata'] ?? null) ? $connector['metadata'] : [];
        return $connector;
    }

    /** @return array<string,mixed> */
    private function defaultLocalConnector(): array
    {
        return [
            'id' => 'local_exports',
            'type' => 'local',
            'enabled' => true,
            'description' => 'Default local export connector for copying artifacts into storage/export-deliveries.',
            'target_dir' => 'storage/export-deliveries/local_exports',
            'allowed_extensions' => ['json', 'csv', 'xml', 'html', 'txt', 'jsonl', 'zip'],
            'metadata' => ['built_in' => true],
        ];
    }
}
