<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class JobManifest
{
    public const VERSION = '1.1.0';

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $outputs
     * @param array<string,mixed> $summary
     */
    public static function write(string $jobDir, string $type, array $settings = [], array $outputs = [], array $summary = []): string
    {
        if (!is_dir($jobDir)) {
            mkdir($jobDir, 0775, true);
        }

        $options = is_array($settings['options'] ?? null) ? $settings['options'] : [];
        $manifest = [
            'library' => 'MNB ScraperKit',
            'version' => self::VERSION,
            'job_id' => basename(rtrim($jobDir, '/\\')) ?: ('job_' . date('Ymd_His')),
            'job_type' => $type,
            'job_dir' => $jobDir,
            'status' => 'completed',
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
            'input' => self::inputSection($settings),
            'scope' => self::scopeSection($options),
            'request_profile' => self::requestProfileSection($options),
            'pacing' => self::pacingSection($options),
            'extraction' => self::extractionSection($options),
            'output' => self::outputSection($outputs),
            'resume' => self::resumeSection($outputs),
            'settings' => $settings,
            'outputs' => $outputs,
            'summary' => $summary,
        ];

        $path = rtrim($jobDir, '/\\') . '/job-manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $path;
    }

    /** @return array<string,mixed> */
    public static function read(string $pathOrDir): array
    {
        $path = is_dir($pathOrDir) ? rtrim($pathOrDir, '/\\') . '/job-manifest.json' : $pathOrDir;
        if (!is_file($path)) {
            throw new \RuntimeException('Job manifest not found: ' . $path);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid job manifest JSON: ' . $path);
        }
        return $data;
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private static function inputSection(array $settings): array
    {
        return [
            'start_url' => $settings['url'] ?? null,
            'source_file' => $settings['source_file'] ?? null,
            'start_urls' => isset($settings['url']) ? [(string) $settings['url']] : [],
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private static function scopeSection(array $options): array
    {
        return [
            'same_domain' => self::boolOption($options, 'same-domain', true),
            'max_pages' => self::intOption($options, ['max-pages'], null),
            'max_depth' => self::intOption($options, ['depth', 'max-depth'], null),
            'allowed_paths' => self::listOption($options, ['allow-path']),
            'denied_patterns' => self::listOption($options, ['skip-url']),
            'stay_under_start_path' => self::boolOption($options, 'stay-under-start-path', false),
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private static function requestProfileSection(array $options): array
    {
        return [
            'user_agent' => $options['user-agent'] ?? null,
            'timeout_seconds' => self::intOption($options, ['timeout', 'timeout-seconds'], null),
            'http_engine' => $options['http-engine'] ?? $options['engine'] ?? 'auto',
            'network_profile' => $options['network'] ?? $options['network-profile'] ?? null,
            'respect_robots' => !isset($options['ignore-robots']) && !isset($options['no-robots']),
            'verify_ssl' => !isset($options['no-verify-ssl']),
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private static function pacingSection(array $options): array
    {
        return [
            'delay_ms' => self::intOption($options, ['delay-ms', 'delay'], null),
            'gap_ms' => self::intOption($options, ['gap-ms', 'gap'], null),
            'batch_size' => self::intOption($options, ['batch-size'], null),
            'batch_pause_seconds' => self::intOption($options, ['batch-pause'], null),
            'pause_every_seconds' => self::intOption($options, ['pause-every-seconds'], null),
            'pause_seconds' => self::intOption($options, ['pause-seconds'], null),
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private static function extractionSection(array $options): array
    {
        return [
            'preset' => $options['preset'] ?? $options['extract-preset'] ?? null,
            'profile' => $options['pipeline-profile'] ?? $options['common-profile'] ?? null,
            'common_data' => self::boolOption($options, 'common-data', false),
            'common_data_types' => self::listOption($options, ['common-type']),
            'required_fields' => self::listOption($options, ['pipeline-required-field', 'required-field']),
            'dedupe_keys' => self::listOption($options, ['pipeline-dedupe-key', 'dedupe-key']),
            'min_quality' => self::intOption($options, ['pipeline-min-quality', 'min-quality'], 0),
        ];
    }

    /** @param array<string,mixed> $outputs @return array<string,mixed> */
    private static function outputSection(array $outputs): array
    {
        return [
            'crawl' => $outputs['crawl'] ?? null,
            'pipeline' => $outputs['pipeline'] ?? null,
            'summary' => $outputs['summary'] ?? null,
            'checkpoint' => $outputs['checkpoint'] ?? null,
        ];
    }

    /** @param array<string,mixed> $outputs @return array<string,mixed> */
    private static function resumeSection(array $outputs): array
    {
        $checkpoint = isset($outputs['checkpoint']) ? (string) $outputs['checkpoint'] : null;
        $queues = [
            'pending' => [],
            'completed' => [],
            'failed' => [],
            'skipped' => [],
            'challenge' => [],
            'retry' => [],
        ];
        $counts = [
            'pending' => 0,
            'completed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'challenge' => 0,
            'retry' => 0,
        ];
        $lastProcessedUrl = null;
        $lastCheckpointAt = null;

        if ($checkpoint && is_file($checkpoint)) {
            $data = json_decode((string) file_get_contents($checkpoint), true);
            if (is_array($data)) {
                $checkpointQueues = is_array($data['queues'] ?? null) ? $data['queues'] : [];
                foreach ($queues as $name => $_) {
                    if (isset($checkpointQueues[$name]) && is_array($checkpointQueues[$name])) {
                        $queues[$name] = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $checkpointQueues[$name])));
                    }
                }
                $checkpointCounts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
                foreach ($counts as $name => $_) {
                    $counts[$name] = isset($checkpointCounts[$name]) ? (int) $checkpointCounts[$name] : count($queues[$name]);
                }
                $lastCheckpointAt = isset($data['updated_at']) ? (string) $data['updated_at'] : null;
                $results = is_array($data['results'] ?? null) ? $data['results'] : [];
                if ($results !== []) {
                    $last = end($results);
                    if (is_array($last) && isset($last['url'])) {
                        $lastProcessedUrl = (string) $last['url'];
                    }
                }
            }
        }

        return [
            'checkpoint_file' => $checkpoint,
            'last_checkpoint_at' => $lastCheckpointAt,
            'last_processed_url' => $lastProcessedUrl,
            'queues' => $queues,
            'counts' => $counts,
        ];
    }

    /** @param array<string,mixed> $options @param array<int,string> $keys */
    private static function intOption(array $options, array $keys, ?int $default): ?int
    {
        foreach ($keys as $key) {
            if (isset($options[$key]) && is_scalar($options[$key])) {
                return (int) $options[$key];
            }
        }
        return $default;
    }

    /** @param array<string,mixed> $options */
    private static function boolOption(array $options, string $key, bool $default): bool
    {
        if (!isset($options[$key])) {
            return $default;
        }
        $value = $options[$key];
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<string,mixed> $options @param array<int,string> $keys @return array<int,string> */
    private static function listOption(array $options, array $keys): array
    {
        foreach ($keys as $key) {
            if (!isset($options[$key])) {
                continue;
            }
            $value = $options[$key];
            if (is_string($value)) {
                $value = explode(',', $value);
            }
            if (!is_array($value)) {
                $value = [$value];
            }
            return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $value), static fn (string $v): bool => $v !== ''));
        }
        return [];
    }
}
