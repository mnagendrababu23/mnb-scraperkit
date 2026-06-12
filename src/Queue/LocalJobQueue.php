<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Queue;

/**
 * Lightweight file-based job queue for local CLI/server automation.
 *
 * This intentionally avoids Redis/database dependencies. Jobs are JSON files
 * moved between state folders so CMD, PowerShell, cron, Task Scheduler,
 * systemd, and Supervisor can all run the same queue safely.
 */
final class LocalJobQueue
{
    public const VERSION = '4.1.0';

    /** @var list<string> */
    public const STATES = ['pending', 'running', 'completed', 'failed', 'paused', 'cancelled', 'retry'];

    private string $queueDir;

    public function __construct(string $rootDirOrQueueDir)
    {
        $rootDirOrQueueDir = rtrim($rootDirOrQueueDir, '/\\');
        $this->queueDir = str_ends_with(str_replace('\\', '/', $rootDirOrQueueDir), '/storage/queue')
            ? $rootDirOrQueueDir
            : $rootDirOrQueueDir . '/storage/queue';
        $this->ensureStructure();
    }

    public function queueDir(): string
    {
        return $this->queueDir;
    }

    public function ensureStructure(): void
    {
        foreach (array_merge(self::STATES, ['locks']) as $dir) {
            if (!is_dir($this->queueDir . '/' . $dir)) {
                mkdir($this->queueDir . '/' . $dir, 0775, true);
            }
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(array $payload): array
    {
        $jobId = $this->sanitizeJobId((string) ($payload['job_id'] ?? ''));
        if ($jobId === '') {
            $jobId = 'job_' . date('Ymd_His') . '_' . strtolower(bin2hex(random_bytes(4)));
        }

        $job = array_replace([
            'queue_version' => self::VERSION,
            'job_id' => $jobId,
            'state' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
            'command' => 'crawl',
            'args' => [],
            'options' => [],
            'history' => [],
        ], $payload);

        $job['job_id'] = $jobId;
        $job['state'] = 'pending';
        $job['history'][] = $this->history('created', ['state' => 'pending']);
        $this->writeJob('pending', $jobId, $job);
        return $job;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(?string $state = null): array
    {
        $states = $state ? [$state] : self::STATES;
        $jobs = [];
        foreach ($states as $st) {
            if (!in_array($st, self::STATES, true)) {
                continue;
            }
            foreach (glob($this->queueDir . '/' . $st . '/*.json') ?: [] as $file) {
                $job = $this->readFile($file);
                if ($job !== null) {
                    $jobs[] = $job;
                }
            }
        }
        usort($jobs, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $jobs;
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $counts = [];
        foreach (self::STATES as $state) {
            $counts[$state] = count(glob($this->queueDir . '/' . $state . '/*.json') ?: []);
        }
        $counts['locks'] = count(glob($this->queueDir . '/locks/*.lock') ?: []);
        return $counts;
    }

    /** @return array<string,mixed> */
    public function load(string $jobIdOrPath): array
    {
        $path = $this->resolvePath($jobIdOrPath);
        if ($path === null) {
            throw new \RuntimeException('Queue job not found: ' . $jobIdOrPath);
        }
        $job = $this->readFile($path);
        if ($job === null) {
            throw new \RuntimeException('Invalid queue job JSON: ' . $path);
        }
        return $job;
    }

    public function findState(string $jobIdOrPath): ?string
    {
        $path = $this->resolvePath($jobIdOrPath);
        if ($path === null) {
            return null;
        }
        return basename(dirname($path));
    }

    public function pause(string $jobId): array
    {
        return $this->move($jobId, 'paused', 'paused');
    }

    public function resume(string $jobId): array
    {
        return $this->move($jobId, 'pending', 'resumed');
    }

    public function cancel(string $jobId): array
    {
        return $this->move($jobId, 'cancelled', 'cancelled');
    }

    public function retry(string $jobId): array
    {
        $job = $this->load($jobId);
        $job['attempts'] = (int) ($job['attempts'] ?? 0);
        $job['last_error'] = null;
        $job['last_exit_code'] = null;
        return $this->moveJob($job, 'pending', 'retry_scheduled');
    }

    /** @return array<int,array<string,mixed>> */
    public function retryAllFailed(): array
    {
        $retried = [];
        foreach ($this->list('failed') as $job) {
            $retried[] = $this->retry((string) ($job['job_id'] ?? ''));
        }
        return $retried;
    }

    public function clearFailed(): int
    {
        $count = 0;
        foreach (glob($this->queueDir . '/failed/*.json') ?: [] as $file) {
            if (is_file($file) && unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    public function markRunning(string $jobId, string $workerId): array
    {
        $job = $this->load($jobId);
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        $job['worker_id'] = $workerId;
        $job['started_at'] = date(DATE_ATOM);
        return $this->moveJob($job, 'running', 'started');
    }

    public function markCompleted(string $jobId, int $exitCode = 0): array
    {
        $job = $this->load($jobId);
        $job['finished_at'] = date(DATE_ATOM);
        $job['last_exit_code'] = $exitCode;
        return $this->moveJob($job, 'completed', 'completed');
    }

    public function markFailed(string $jobId, int $exitCode, string $message = ''): array
    {
        $job = $this->load($jobId);
        $job['finished_at'] = date(DATE_ATOM);
        $job['last_exit_code'] = $exitCode;
        $job['last_error'] = $message;
        return $this->moveJob($job, 'failed', 'failed');
    }

    public function nextPending(): ?array
    {
        $jobs = array_merge($this->list('pending'), $this->list('retry'));
        usort($jobs, static fn(array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));
        return $jobs[0] ?? null;
    }

    public function acquireLock(string $jobId, string $workerId): bool
    {
        $this->ensureStructure();
        $path = $this->lockPath($jobId);
        $payload = [
            'queue_version' => self::VERSION,
            'job_id' => $jobId,
            'worker_id' => $workerId,
            'worker_pid' => getmypid(),
            'started_at' => date(DATE_ATOM),
            'heartbeat_at' => date(DATE_ATOM),
        ];
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }
        fwrite($handle, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fclose($handle);
        return true;
    }

    public function heartbeat(string $jobId, string $workerId): void
    {
        $path = $this->lockPath($jobId);
        if (!is_file($path)) {
            return;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            $data = [];
        }
        $data['worker_id'] = $workerId;
        $data['heartbeat_at'] = date(DATE_ATOM);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function releaseLock(string $jobId): void
    {
        $path = $this->lockPath($jobId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function locks(): array
    {
        $locks = [];
        foreach (glob($this->queueDir . '/locks/*.lock') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $locks[] = $data;
            }
        }
        return $locks;
    }

    private function move(string $jobId, string $toState, string $event): array
    {
        return $this->moveJob($this->load($jobId), $toState, $event);
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function moveJob(array $job, string $toState, string $event): array
    {
        if (!in_array($toState, self::STATES, true)) {
            throw new \InvalidArgumentException('Invalid queue state: ' . $toState);
        }
        $jobId = (string) ($job['job_id'] ?? '');
        if ($jobId === '') {
            throw new \RuntimeException('Job has no job_id.');
        }
        $oldPath = $this->resolvePath($jobId);
        $job['state'] = $toState;
        $job['updated_at'] = date(DATE_ATOM);
        $job['history'] = is_array($job['history'] ?? null) ? $job['history'] : [];
        $job['history'][] = $this->history($event, ['state' => $toState]);
        $this->writeJob($toState, $jobId, $job);
        if ($oldPath && is_file($oldPath) && realpath($oldPath) !== realpath($this->path($toState, $jobId))) {
            @unlink($oldPath);
        }
        return $job;
    }

    /** @param array<string,mixed> $job */
    private function writeJob(string $state, string $jobId, array $job): void
    {
        $this->ensureStructure();
        file_put_contents($this->path($state, $jobId), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function path(string $state, string $jobId): string
    {
        return $this->queueDir . '/' . $state . '/' . $this->sanitizeJobId($jobId) . '.json';
    }

    private function lockPath(string $jobId): string
    {
        return $this->queueDir . '/locks/' . $this->sanitizeJobId($jobId) . '.lock';
    }

    private function resolvePath(string $jobIdOrPath): ?string
    {
        if (is_file($jobIdOrPath)) {
            return $jobIdOrPath;
        }
        $jobId = $this->sanitizeJobId($jobIdOrPath);
        foreach (self::STATES as $state) {
            $path = $this->path($state, $jobId);
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function readFile(string $path): ?array
    {
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @return array<string,mixed> */
    private function history(string $event, array $extra = []): array
    {
        return array_replace(['event' => $event, 'at' => date(DATE_ATOM)], $extra);
    }

    private function sanitizeJobId(string $jobId): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $jobId), '-');
    }
}
