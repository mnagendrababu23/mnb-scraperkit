<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Distributed;

final class DistributedQueueManager
{
    private ?DistributedQueueAdapterInterface $adapter = null;

    public function __construct(private readonly DistributedQueueConfig $config)
    {
    }

    public function adapter(): DistributedQueueAdapterInterface
    {
        if ($this->adapter instanceof DistributedQueueAdapterInterface) {
            return $this->adapter;
        }
        if ($this->config->adapter === 'redis') {
            return $this->adapter = new RedisDistributedQueueAdapter($this->config);
        }
        if ($this->config->adapter === 'file') {
            return $this->adapter = new FileDistributedQueueAdapter($this->config);
        }
        $redis = new RedisDistributedQueueAdapter($this->config);
        if ($redis->available()) {
            $status = $redis->status();
            if (($status['available'] ?? false) === true) {
                return $this->adapter = $redis;
            }
        }
        return $this->adapter = new FileDistributedQueueAdapter($this->config);
    }

    /** @return array<string,mixed> */
    public function doctor(): array
    {
        $redis = new RedisDistributedQueueAdapter($this->config);
        $selected = $this->adapter();
        return [
            'distributed_queue_version' => DistributedQueueConfig::VERSION,
            'selected_adapter' => $selected->name(),
            'redis_extension_loaded' => class_exists('Redis'),
            'redis_configured' => $this->config->redisUrl !== null,
            'config' => $this->config->toArray(),
            'selected_status' => $selected->status(),
            'redis_status' => $redis->status(),
            'notes' => [
                'Redis is optional. File adapter is used for local and single-server workflows.',
                'Use --distributed-adapter=redis and MNB_SCRAPERKIT_REDIS_URL for multi-server workers.',
                'Distributed workers use leases and heartbeats so jobs can recover from worker crashes.',
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function enqueue(array $payload): array
    {
        return $this->adapter()->enqueue($payload);
    }

    public function reserve(string $workerId): ?DistributedJob
    {
        return $this->adapter()->reserve($workerId, $this->config->visibilityTimeoutSeconds);
    }

    /** @return array<string,mixed> */
    public function ack(string $jobId, ?string $leaseId = null): array
    {
        return $this->adapter()->ack($jobId, $leaseId);
    }

    /** @return array<string,mixed> */
    public function fail(string $jobId, string $message = '', ?string $leaseId = null): array
    {
        return $this->adapter()->fail($jobId, $message, $leaseId);
    }

    /** @return array<string,mixed> */
    public function heartbeat(string $jobId, string $workerId, ?string $leaseId = null): array
    {
        return $this->adapter()->heartbeat($jobId, $workerId, $leaseId);
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        return $this->adapter()->status() + ['distributed_queue_version' => DistributedQueueConfig::VERSION];
    }

    /** @return array<string,mixed> */
    public function purge(string $state = 'all'): array
    {
        return $this->adapter()->purge($state);
    }
}
