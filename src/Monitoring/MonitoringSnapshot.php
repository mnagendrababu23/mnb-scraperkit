<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Monitoring;

use Mnb\ScraperKit\Queue\LocalJobQueue;
use Mnb\ScraperKit\Scheduler\LocalScheduleStore;

/**
 * Small local monitoring snapshot for CLI/server automation.
 */
final class MonitoringSnapshot
{
    public function __construct(private readonly string $rootDir)
    {
    }

    /** @return array<string,mixed> */
    public function collect(int $staleLockTtlSeconds = 900): array
    {
        $queue = new LocalJobQueue($this->rootDir);
        $schedules = new LocalScheduleStore($this->rootDir);
        $locks = $queue->locks();
        $now = time();
        $staleLocks = [];
        foreach ($locks as $lock) {
            $heartbeat = strtotime((string) ($lock['heartbeat_at'] ?? $lock['started_at'] ?? '')) ?: 0;
            $age = $heartbeat > 0 ? $now - $heartbeat : null;
            if ($age === null || $age >= $staleLockTtlSeconds) {
                $lock['age_seconds'] = $age;
                $staleLocks[] = $lock;
            }
        }

        $queueCounts = $queue->counts();
        $scheduleList = $schedules->list();
        $dueSchedules = $schedules->due();
        $health = 'ok';
        if (($queueCounts['failed'] ?? 0) > 0 || count($staleLocks) > 0) {
            $health = 'attention';
        }
        if (($queueCounts['running'] ?? 0) > 0 && count($locks) === 0) {
            $health = 'warning';
        }

        return [
            'monitor_version' => '1.0.2',
            'generated_at' => date(DATE_ATOM),
            'health' => $health,
            'queue_dir' => $queue->queueDir(),
            'queue_counts' => $queueCounts,
            'schedule_dir' => $schedules->scheduleDir(),
            'schedule_counts' => [
                'total' => count($scheduleList),
                'enabled' => count(array_filter($scheduleList, static fn(array $s): bool => (bool) ($s['enabled'] ?? true))),
                'due' => count($dueSchedules),
            ],
            'locks_total' => count($locks),
            'stale_locks_total' => count($staleLocks),
            'stale_locks' => $staleLocks,
        ];
    }
}
