<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Dashboard;

use Mnb\ScraperKit\Api\ApiRouter;
use Mnb\ScraperKit\Console\CommandRegistry;
use Mnb\ScraperKit\Monitoring\MonitoringSnapshot;
use Mnb\ScraperKit\Plugin\PluginManager;
use Mnb\ScraperKit\Profile\ProfileSchemaLoader;
use Mnb\ScraperKit\Queue\LocalJobQueue;
use Mnb\ScraperKit\Scheduler\LocalScheduleStore;

/**
 * Collects read-only dashboard data from the local ScraperKit workspace.
 *
 * The dashboard intentionally avoids Laravel/Symfony HTTP Kernel and database
 * requirements. It reads the same local queue, schedule, plugin, profile, and
 * monitoring state used by CLI/API commands.
 */
final class DashboardDataCollector
{
    public const VERSION = '3.0.0';

    public function __construct(private readonly string $rootDir)
    {
    }

    /** @return array<string,mixed> */
    public function collect(int $recentJobs = 20, int $staleLockTtlSeconds = 900): array
    {
        $queue = new LocalJobQueue($this->rootDir);
        $scheduleStore = new LocalScheduleStore($this->rootDir);
        $plugins = new PluginManager($this->rootDir);
        $profileLoader = new ProfileSchemaLoader($this->rootDir . '/config/profiles', $plugins->profileFiles(true));

        $jobs = array_slice($queue->list(), 0, max(1, $recentJobs));
        $schedules = array_slice($scheduleStore->list(), 0, max(1, $recentJobs));
        $monitor = (new MonitoringSnapshot($this->rootDir))->collect($staleLockTtlSeconds);
        $pluginItems = array_map(static fn($plugin): array => $plugin->toArray(), $plugins->list(false));
        $profiles = $profileLoader->list();

        return [
            'dashboard_version' => self::VERSION,
            'library' => 'MNB ScraperKit',
            'library_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'root_dir' => $this->rootDir,
            'health' => (string) ($monitor['health'] ?? 'unknown'),
            'monitor' => $monitor,
            'queue' => [
                'dir' => $queue->queueDir(),
                'counts' => $queue->counts(),
                'recent_jobs' => $jobs,
            ],
            'schedules' => [
                'dir' => $scheduleStore->scheduleDir(),
                'total' => count($scheduleStore->list()),
                'due_total' => count($scheduleStore->due()),
                'recent' => $schedules,
            ],
            'plugins' => [
                'total' => count($pluginItems),
                'items' => $pluginItems,
            ],
            'profiles' => [
                'total' => count($profiles),
                'items' => $profiles,
            ],
            'api' => [
                'routes' => ApiRouter::routes(),
                'routes_total' => count(ApiRouter::routes()),
            ],
            'commands' => [
                'total' => count(CommandRegistry::commands()),
                'items' => CommandRegistry::commands(),
            ],
        ];
    }
}
