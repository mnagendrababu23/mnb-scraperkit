<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Scheduler;

/**
 * File-based scheduler for local CLI automation.
 *
 * Schedules do not execute in the background by themselves. Users call
 * schedule:run-due from cron, Windows Task Scheduler, Supervisor, systemd,
 * or a worker loop. Due schedules enqueue normal ScraperKit queue jobs.
 */
final class LocalScheduleStore
{
    public const VERSION = '3.3.0';

    private string $scheduleDir;

    public function __construct(string $rootDirOrScheduleDir)
    {
        $rootDirOrScheduleDir = rtrim($rootDirOrScheduleDir, '/\\');
        $this->scheduleDir = str_ends_with(str_replace('\\', '/', $rootDirOrScheduleDir), '/storage/schedules')
            ? $rootDirOrScheduleDir
            : $rootDirOrScheduleDir . '/storage/schedules';
        $this->ensureStructure();
    }

    public function scheduleDir(): string
    {
        return $this->scheduleDir;
    }

    public function ensureStructure(): void
    {
        if (!is_dir($this->scheduleDir)) {
            mkdir($this->scheduleDir, 0775, true);
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(array $payload): array
    {
        $id = $this->sanitizeId((string) ($payload['schedule_id'] ?? ''));
        if ($id === '') {
            $id = 'schedule_' . date('Ymd_His') . '_' . strtolower(bin2hex(random_bytes(4)));
        }
        $now = time();
        $interval = max(0, (int) ($payload['interval_seconds'] ?? 0));
        $nextRunAt = (string) ($payload['next_run_at'] ?? '');
        if ($nextRunAt === '') {
            $nextRunAt = date(DATE_ATOM, $now + max(0, (int) ($payload['delay_seconds'] ?? 0)));
        }
        unset($payload['delay_seconds']);
        if (($payload['next_run_at'] ?? '') === '') {
            unset($payload['next_run_at']);
        }
        $schedule = array_replace([
            'schedule_version' => self::VERSION,
            'schedule_id' => $id,
            'title' => 'Scheduled ScraperKit job',
            'enabled' => true,
            'command' => 'crawl',
            'args' => [],
            'options' => [],
            'interval_seconds' => $interval,
            'next_run_at' => $nextRunAt,
            'last_run_at' => null,
            'run_count' => 0,
            'max_runs' => 0,
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
            'history' => [],
        ], $payload);
        $schedule['schedule_id'] = $id;
        $schedule['history'][] = $this->history('created');
        $this->write($schedule);
        return $schedule;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(?bool $enabled = null): array
    {
        $items = [];
        foreach (glob($this->scheduleDir . '/*.json') ?: [] as $file) {
            $item = $this->readFile($file);
            if ($item === null) {
                continue;
            }
            if ($enabled !== null && (bool) ($item['enabled'] ?? true) !== $enabled) {
                continue;
            }
            $items[] = $item;
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string) ($a['next_run_at'] ?? ''), (string) ($b['next_run_at'] ?? '')));
        return $items;
    }

    /** @return array<string,mixed> */
    public function load(string $id): array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            throw new \RuntimeException('Schedule not found: ' . $id);
        }
        $item = $this->readFile($path);
        if ($item === null) {
            throw new \RuntimeException('Invalid schedule JSON: ' . $path);
        }
        return $item;
    }

    /** @return array<int,array<string,mixed>> */
    public function due(int $now = 0): array
    {
        $now = $now > 0 ? $now : time();
        $due = [];
        foreach ($this->list(true) as $schedule) {
            $next = strtotime((string) ($schedule['next_run_at'] ?? '')) ?: 0;
            $maxRuns = (int) ($schedule['max_runs'] ?? 0);
            $runCount = (int) ($schedule['run_count'] ?? 0);
            if ($next <= $now && ($maxRuns <= 0 || $runCount < $maxRuns)) {
                $due[] = $schedule;
            }
        }
        return $due;
    }

    /** @param array<string,mixed> $schedule @return array<string,mixed> */
    public function markRun(array $schedule, string $queuedJobId = ''): array
    {
        $interval = max(0, (int) ($schedule['interval_seconds'] ?? 0));
        $schedule['run_count'] = (int) ($schedule['run_count'] ?? 0) + 1;
        $schedule['last_run_at'] = date(DATE_ATOM);
        $schedule['updated_at'] = date(DATE_ATOM);
        if ($interval > 0) {
            $schedule['next_run_at'] = date(DATE_ATOM, time() + $interval);
        } else {
            $schedule['enabled'] = false;
        }
        $extra = $queuedJobId !== '' ? ['queued_job_id' => $queuedJobId] : [];
        $schedule['history'] = is_array($schedule['history'] ?? null) ? $schedule['history'] : [];
        $schedule['history'][] = $this->history('run_due_enqueued', $extra);
        $this->write($schedule);
        return $schedule;
    }

    /** @return array<string,mixed> */
    public function setEnabled(string $id, bool $enabled): array
    {
        $schedule = $this->load($id);
        $schedule['enabled'] = $enabled;
        $schedule['updated_at'] = date(DATE_ATOM);
        $schedule['history'] = is_array($schedule['history'] ?? null) ? $schedule['history'] : [];
        $schedule['history'][] = $this->history($enabled ? 'enabled' : 'disabled');
        $this->write($schedule);
        return $schedule;
    }

    /** @param array<string,mixed> $schedule */
    private function write(array $schedule): void
    {
        $this->ensureStructure();
        file_put_contents($this->path((string) $schedule['schedule_id']), json_encode($schedule, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function path(string $id): string
    {
        return $this->scheduleDir . '/' . $this->sanitizeId($id) . '.json';
    }

    /** @return array<string,mixed>|null */
    private function readFile(string $path): ?array
    {
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function history(string $event, array $extra = []): array
    {
        return array_replace(['event' => $event, 'at' => date(DATE_ATOM)], $extra);
    }

    private function sanitizeId(string $id): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $id), '-');
    }
}
