<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Distributed;

/**
 * Configuration for optional distributed queue execution.
 *
 * Redis is optional. When Redis/extension is not available, ScraperKit can use
 * a distributed-compatible file adapter for local testing and single-server
 * deployments.
 */
final class DistributedQueueConfig
{
    public const VERSION = '4.2.0';

    public function __construct(
        public readonly string $adapter = 'auto',
        public readonly ?string $redisUrl = null,
        public readonly string $namespace = 'mnb_scraperkit',
        public readonly string $queueName = 'default',
        public readonly string $workerGroup = 'default',
        public readonly int $visibilityTimeoutSeconds = 300,
        public readonly int $heartbeatTtlSeconds = 120,
        public readonly string $fileQueueDir = ''
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $rootDir): self
    {
        $adapter = strtolower((string) ($data['adapter'] ?? $data['distributed-adapter'] ?? 'auto'));
        if (!in_array($adapter, ['auto', 'file', 'redis'], true)) {
            $adapter = 'auto';
        }

        $namespace = self::slug((string) ($data['namespace'] ?? $data['prefix'] ?? 'mnb_scraperkit'));
        $queueName = self::slug((string) ($data['queue'] ?? $data['queue-name'] ?? 'default'));
        $workerGroup = self::slug((string) ($data['worker_group'] ?? $data['worker-group'] ?? $data['group'] ?? 'default'));
        $rootDir = rtrim($rootDir, '/\\');

        return new self(
            adapter: $adapter,
            redisUrl: self::blankToNull($data['redis_url'] ?? $data['redis-url'] ?? getenv('MNB_SCRAPERKIT_REDIS_URL') ?: null),
            namespace: $namespace ?: 'mnb_scraperkit',
            queueName: $queueName ?: 'default',
            workerGroup: $workerGroup ?: 'default',
            visibilityTimeoutSeconds: max(10, (int) ($data['visibility_timeout_seconds'] ?? $data['visibility-timeout'] ?? $data['lease-seconds'] ?? 300)),
            heartbeatTtlSeconds: max(10, (int) ($data['heartbeat_ttl_seconds'] ?? $data['heartbeat-ttl'] ?? 120)),
            fileQueueDir: (string) ($data['file_queue_dir'] ?? $data['distributed-dir'] ?? ($rootDir . '/storage/distributed-queue'))
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'distributed_queue_version' => self::VERSION,
            'adapter' => $this->adapter,
            'redis_url_configured' => $this->redisUrl !== null,
            'namespace' => $this->namespace,
            'queue_name' => $this->queueName,
            'worker_group' => $this->workerGroup,
            'visibility_timeout_seconds' => $this->visibilityTimeoutSeconds,
            'heartbeat_ttl_seconds' => $this->heartbeatTtlSeconds,
            'file_queue_dir' => $this->fileQueueDir,
        ];
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === true) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '-', $value) ?? '';
        return trim($value, '-_.');
    }
}
