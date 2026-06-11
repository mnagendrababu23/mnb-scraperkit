<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Distributed;

/**
 * Optional Redis adapter. It is intentionally defensive: if ext-redis is not
 * installed, status/doctor explain the gap instead of crashing the library.
 */
final class RedisDistributedQueueAdapter implements DistributedQueueAdapterInterface
{
    private ?\Redis $redis = null;

    public function __construct(private readonly DistributedQueueConfig $config)
    {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function available(): bool
    {
        return class_exists('Redis');
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function enqueue(array $payload): array
    {
        $redis = $this->redis();
        $id = $this->sanitize((string) ($payload['job_id'] ?? $payload['id'] ?? ''));
        if ($id === '') {
            $id = 'dist_' . date('Ymd_His') . '_' . strtolower(bin2hex(random_bytes(4)));
        }
        $envelope = [
            'distributed_queue_version' => DistributedQueueConfig::VERSION,
            'id' => $id,
            'state' => 'pending',
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
            'attempts' => 0,
            'payload' => $payload,
            'metadata' => ['adapter' => 'redis', 'namespace' => $this->config->namespace, 'queue_name' => $this->config->queueName],
        ];
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $redis->hSet($this->key('jobs'), $id, $json ?: '{}');
        $redis->lPush($this->key('pending'), $id);
        return $envelope;
    }

    public function reserve(string $workerId, int $leaseSeconds): ?DistributedJob
    {
        $redis = $this->redis();
        $id = $redis->rPop($this->key('pending'));
        if (!is_string($id) || $id === '') {
            return null;
        }
        $raw = $redis->hGet($this->key('jobs'), $id);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $leaseId = 'lease_' . strtolower(bin2hex(random_bytes(8)));
        $data['state'] = 'leased';
        $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
        $data['worker_id'] = $workerId;
        $data['lease_id'] = $leaseId;
        $data['leased_at'] = date(DATE_ATOM);
        $data['lease_expires_at'] = date(DATE_ATOM, time() + max(10, $leaseSeconds));
        $data['updated_at'] = date(DATE_ATOM);
        $redis->hSet($this->key('jobs'), $id, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $redis->zAdd($this->key('leased'), time() + max(10, $leaseSeconds), $id);
        return new DistributedJob($id, (array) ($data['payload'] ?? []), ['lease_id' => $leaseId, 'worker_id' => $workerId, 'adapter' => 'redis']);
    }

    /** @return array<string,mixed> */
    public function ack(string $jobId, ?string $leaseId = null): array
    {
        return $this->moveFinal($jobId, 'completed', $leaseId);
    }

    /** @return array<string,mixed> */
    public function fail(string $jobId, string $message = '', ?string $leaseId = null): array
    {
        $data = $this->moveFinal($jobId, 'failed', $leaseId);
        if ($message !== '') {
            $data['error'] = $message;
            $this->redis()->hSet($this->key('jobs'), $jobId, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public function heartbeat(string $jobId, string $workerId, ?string $leaseId = null): array
    {
        $redis = $this->redis();
        $data = $this->load($jobId);
        $this->assertLease($data, $leaseId);
        $data['worker_id'] = $workerId;
        $data['heartbeat_at'] = date(DATE_ATOM);
        $data['lease_expires_at'] = date(DATE_ATOM, time() + $this->config->heartbeatTtlSeconds);
        $data['updated_at'] = date(DATE_ATOM);
        $redis->hSet($this->key('jobs'), $jobId, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $redis->zAdd($this->key('leased'), time() + $this->config->heartbeatTtlSeconds, $jobId);
        return $data;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        if (!$this->available()) {
            return [
                'adapter' => 'redis',
                'available' => false,
                'message' => 'PHP Redis extension is not installed. Use --distributed-adapter=file or install ext-redis.',
            ];
        }
        try {
            $redis = $this->redis();
            return [
                'adapter' => 'redis',
                'available' => true,
                'namespace' => $this->config->namespace,
                'queue_name' => $this->config->queueName,
                'worker_group' => $this->config->workerGroup,
                'counts' => [
                    'pending' => (int) $redis->lLen($this->key('pending')),
                    'leased' => (int) $redis->zCard($this->key('leased')),
                    'completed' => (int) $redis->zCard($this->key('completed')),
                    'failed' => (int) $redis->zCard($this->key('failed')),
                    'jobs' => (int) $redis->hLen($this->key('jobs')),
                ],
            ];
        } catch (\Throwable $e) {
            return ['adapter' => 'redis', 'available' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    public function purge(string $state = 'all'): array
    {
        $redis = $this->redis();
        $keys = $state === 'all'
            ? [$this->key('pending'), $this->key('leased'), $this->key('completed'), $this->key('failed'), $this->key('jobs')]
            : [$this->key($state)];
        $deleted = 0;
        foreach ($keys as $key) {
            $deleted += (int) $redis->del($key);
        }
        return ['adapter' => 'redis', 'state' => $state, 'deleted_keys' => $deleted];
    }

    /** @return array<string,mixed> */
    private function moveFinal(string $jobId, string $state, ?string $leaseId): array
    {
        $redis = $this->redis();
        $data = $this->load($jobId);
        $this->assertLease($data, $leaseId);
        $data['state'] = $state;
        $data[$state . '_at'] = date(DATE_ATOM);
        $data['updated_at'] = date(DATE_ATOM);
        unset($data['lease_id'], $data['lease_expires_at']);
        $redis->zRem($this->key('leased'), $jobId);
        $redis->zAdd($this->key($state), time(), $jobId);
        $redis->hSet($this->key('jobs'), $jobId, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        return $data;
    }

    /** @return array<string,mixed> */
    private function load(string $jobId): array
    {
        $raw = $this->redis()->hGet($this->key('jobs'), $jobId);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException('Distributed job not found: ' . $jobId);
        }
        return $data;
    }

    private function redis(): \Redis
    {
        if (!$this->available()) {
            throw new \RuntimeException('PHP Redis extension is not installed.');
        }
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }
        $url = $this->config->redisUrl ?: 'redis://127.0.0.1:6379';
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('Invalid Redis URL.');
        }
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 6379);
        $timeout = 2.5;
        $redis = new \Redis();
        $redis->connect($host, $port, $timeout);
        if (isset($parts['pass'])) {
            $redis->auth((string) $parts['pass']);
        }
        if (isset($parts['path']) && trim((string) $parts['path'], '/') !== '') {
            $db = (int) trim((string) $parts['path'], '/');
            if ($db >= 0) {
                $redis->select($db);
            }
        }
        $this->redis = $redis;
        return $redis;
    }

    private function key(string $name): string
    {
        return $this->config->namespace . ':' . $this->config->queueName . ':' . $name;
    }

    /** @param array<string,mixed> $data */
    private function assertLease(array $data, ?string $leaseId): void
    {
        if ($leaseId !== null && $leaseId !== '' && (string) ($data['lease_id'] ?? '') !== $leaseId) {
            throw new \RuntimeException('Lease mismatch for distributed job.');
        }
    }

    private function sanitize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '-', $value) ?? '';
        return trim($value, '-_.');
    }
}
