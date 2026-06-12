<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Distributed;

/**
 * Distributed-compatible queue adapter using files.
 *
 * This is useful for tests, local development, and users who want the v1.0.2
 * distributed worker contract without running Redis yet.
 */
final class FileDistributedQueueAdapter implements DistributedQueueAdapterInterface
{
    /** @var list<string> */
    private const STATES = ['pending', 'leased', 'completed', 'failed'];

    private string $baseDir;

    public function __construct(private readonly DistributedQueueConfig $config)
    {
        $this->baseDir = rtrim($this->config->fileQueueDir, '/\\') . '/' . $this->config->namespace . '/' . $this->config->queueName;
        $this->ensureStructure();
    }

    public function name(): string
    {
        return 'file';
    }

    public function available(): bool
    {
        return true;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function enqueue(array $payload): array
    {
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
            'metadata' => [
                'adapter' => 'file',
                'namespace' => $this->config->namespace,
                'queue_name' => $this->config->queueName,
            ],
        ];
        $this->write('pending', $id, $envelope);
        return $envelope;
    }

    public function reserve(string $workerId, int $leaseSeconds): ?DistributedJob
    {
        $this->requeueExpiredLeases();
        $files = glob($this->baseDir . '/pending/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $handle = @fopen($file, 'r+');
            if ($handle === false) {
                continue;
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                continue;
            }
            $data = json_decode((string) stream_get_contents($handle), true);
            if (!is_array($data)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                continue;
            }
            $id = (string) ($data['id'] ?? pathinfo($file, PATHINFO_FILENAME));
            $leaseId = 'lease_' . strtolower(bin2hex(random_bytes(8)));
            $data['state'] = 'leased';
            $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
            $data['worker_id'] = $workerId;
            $data['lease_id'] = $leaseId;
            $data['leased_at'] = date(DATE_ATOM);
            $data['lease_expires_at'] = date(DATE_ATOM, time() + max(10, $leaseSeconds));
            $data['updated_at'] = date(DATE_ATOM);
            $target = $this->path('leased', $id);
            $this->writeRaw($target, $data);
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($file);
            return new DistributedJob($id, (array) ($data['payload'] ?? []), ['lease_id' => $leaseId, 'worker_id' => $workerId, 'adapter' => 'file']);
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function ack(string $jobId, ?string $leaseId = null): array
    {
        $data = $this->loadFromAny($jobId);
        $this->assertLease($data, $leaseId);
        $data['state'] = 'completed';
        $data['completed_at'] = date(DATE_ATOM);
        $data['updated_at'] = date(DATE_ATOM);
        unset($data['lease_id'], $data['lease_expires_at']);
        $this->deleteFromStates($jobId);
        $this->write('completed', $jobId, $data);
        return $data;
    }

    /** @return array<string,mixed> */
    public function fail(string $jobId, string $message = '', ?string $leaseId = null): array
    {
        $data = $this->loadFromAny($jobId);
        $this->assertLease($data, $leaseId);
        $data['state'] = 'failed';
        $data['failed_at'] = date(DATE_ATOM);
        $data['updated_at'] = date(DATE_ATOM);
        $data['error'] = $message;
        unset($data['lease_id'], $data['lease_expires_at']);
        $this->deleteFromStates($jobId);
        $this->write('failed', $jobId, $data);
        return $data;
    }

    /** @return array<string,mixed> */
    public function heartbeat(string $jobId, string $workerId, ?string $leaseId = null): array
    {
        $data = $this->load('leased', $jobId);
        $this->assertLease($data, $leaseId);
        $data['worker_id'] = $workerId;
        $data['heartbeat_at'] = date(DATE_ATOM);
        $data['lease_expires_at'] = date(DATE_ATOM, time() + $this->config->heartbeatTtlSeconds);
        $data['updated_at'] = date(DATE_ATOM);
        $this->write('leased', $jobId, $data);
        return $data;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $this->requeueExpiredLeases();
        $counts = [];
        foreach (self::STATES as $state) {
            $counts[$state] = count(glob($this->baseDir . '/' . $state . '/*.json') ?: []);
        }
        return [
            'adapter' => 'file',
            'available' => true,
            'base_dir' => $this->baseDir,
            'namespace' => $this->config->namespace,
            'queue_name' => $this->config->queueName,
            'worker_group' => $this->config->workerGroup,
            'counts' => $counts,
        ];
    }

    /** @return array<string,mixed> */
    public function purge(string $state = 'all'): array
    {
        $states = $state === 'all' ? self::STATES : [$state];
        $deleted = 0;
        foreach ($states as $st) {
            if (!in_array($st, self::STATES, true)) {
                continue;
            }
            foreach (glob($this->baseDir . '/' . $st . '/*.json') ?: [] as $file) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        return ['adapter' => 'file', 'state' => $state, 'deleted' => $deleted];
    }

    private function ensureStructure(): void
    {
        foreach (self::STATES as $state) {
            if (!is_dir($this->baseDir . '/' . $state)) {
                mkdir($this->baseDir . '/' . $state, 0775, true);
            }
        }
    }

    private function requeueExpiredLeases(): void
    {
        foreach (glob($this->baseDir . '/leased/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $expires = strtotime((string) ($data['lease_expires_at'] ?? '')) ?: 0;
            if ($expires > 0 && $expires < time()) {
                $id = (string) ($data['id'] ?? pathinfo($file, PATHINFO_FILENAME));
                $data['state'] = 'pending';
                $data['requeued_at'] = date(DATE_ATOM);
                $data['updated_at'] = date(DATE_ATOM);
                unset($data['lease_id'], $data['lease_expires_at']);
                $this->write('pending', $id, $data);
                @unlink($file);
            }
        }
    }

    /** @return array<string,mixed> */
    private function loadFromAny(string $jobId): array
    {
        foreach (self::STATES as $state) {
            $path = $this->path($state, $jobId);
            if (is_file($path)) {
                return $this->load($state, $jobId);
            }
        }
        throw new \RuntimeException('Distributed job not found: ' . $jobId);
    }

    /** @return array<string,mixed> */
    private function load(string $state, string $jobId): array
    {
        $path = $this->path($state, $jobId);
        if (!is_file($path)) {
            throw new \RuntimeException('Distributed job not found in ' . $state . ': ' . $jobId);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid distributed job JSON: ' . $path);
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function write(string $state, string $jobId, array $data): void
    {
        $this->writeRaw($this->path($state, $jobId), $data);
    }

    /** @param array<string,mixed> $data */
    private function writeRaw(string $path, array $data): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function path(string $state, string $jobId): string
    {
        return $this->baseDir . '/' . $state . '/' . $this->sanitize($jobId) . '.json';
    }

    private function deleteFromStates(string $jobId): void
    {
        foreach (self::STATES as $state) {
            $path = $this->path($state, $jobId);
            if (is_file($path)) {
                @unlink($path);
            }
        }
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
