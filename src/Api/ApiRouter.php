<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Api;

use Mnb\ScraperKit\Console\CommandRegistry;
use Mnb\ScraperKit\Dashboard\DashboardDataCollector;
use Mnb\ScraperKit\Dataset\DatasetStore;
use Mnb\ScraperKit\Monitoring\MonitoringSnapshot;
use Mnb\ScraperKit\Plugin\PluginManager;
use Mnb\ScraperKit\Profile\ProfileSchemaLoader;
use Mnb\ScraperKit\Queue\LocalJobQueue;

/**
 * Lightweight read-mostly JSON API router used by the optional api:serve command.
 *
 * It intentionally avoids framework dependencies. The API is suitable for local
 * dashboards, internal automation, and health checks. It should be protected by
 * an API token when exposed outside localhost.
 */
final class ApiRouter
{
    public const VERSION = '3.1.0';

    public function __construct(
        private readonly string $rootDir,
        private readonly ?string $apiToken = null
    ) {
    }

    /** @return array<int,array<string,string>> */
    public static function routes(): array
    {
        return [
            ['method' => 'GET', 'path' => '/api/v1/health', 'description' => 'Health check and version information.'],
            ['method' => 'GET', 'path' => '/api/v1/version', 'description' => 'ScraperKit API and library version.'],
            ['method' => 'GET', 'path' => '/api/v1/commands', 'description' => 'Registered CLI command metadata.'],
            ['method' => 'GET', 'path' => '/api/v1/queue/status', 'description' => 'Local queue counts and lock totals.'],
            ['method' => 'GET', 'path' => '/api/v1/jobs', 'description' => 'List queued jobs. Optional query: state.'],
            ['method' => 'GET', 'path' => '/api/v1/jobs/{job_id}', 'description' => 'Read one queued job manifest.'],
            ['method' => 'POST', 'path' => '/api/v1/jobs', 'description' => 'Create one local queue job from JSON body.'],
            ['method' => 'GET', 'path' => '/api/v1/monitor/summary', 'description' => 'Monitoring snapshot for queue, schedules, and locks.'],
            ['method' => 'GET', 'path' => '/api/v1/plugins', 'description' => 'List discovered config-only plugins.'],
            ['method' => 'GET', 'path' => '/api/v1/profiles', 'description' => 'List built-in and plugin profile schemas.'],
            ['method' => 'GET', 'path' => '/api/v1/dashboard', 'description' => 'Read consolidated dashboard data for local admin screens.'],
            ['method' => 'GET', 'path' => '/api/v1/datasets', 'description' => 'List local dataset snapshots.'],
            ['method' => 'GET', 'path' => '/api/v1/datasets/{dataset_id}', 'description' => 'Read one dataset manifest.'],
        ];
    }

    /** @param array<string,string> $headers @param array<string,mixed>|null $body */
    public function handle(string $method, string $path, array $headers = [], ?array $body = null): ApiResponse
    {
        $method = strtoupper($method);
        $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');

        if (!ApiToken::verify(ApiToken::bearerFromHeaders($headers), $this->apiToken)) {
            return new ApiResponse(401, [
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Missing or invalid Bearer token.',
            ]);
        }

        try {
            return $this->route($method, $path, $body ?? []);
        } catch (\Throwable $e) {
            return new ApiResponse(500, [
                'ok' => false,
                'error' => 'server_error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string,mixed> $body */
    private function route(string $method, string $path, array $body): ApiResponse
    {
        if ($method === 'GET' && $path === '/api/v1/health') {
            return new ApiResponse(200, [
                'ok' => true,
                'api_version' => self::VERSION,
                'library' => 'MNB ScraperKit',
                'generated_at' => date(DATE_ATOM),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/version') {
            return new ApiResponse(200, [
                'ok' => true,
                'api_version' => self::VERSION,
                'library_version' => self::VERSION,
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/commands') {
            return new ApiResponse(200, [
                'ok' => true,
                'commands' => CommandRegistry::commands(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/queue/status') {
            $queue = new LocalJobQueue($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'queue_dir' => $queue->queueDir(),
                'counts' => $queue->counts(),
                'locks' => $queue->locks(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/jobs') {
            $queue = new LocalJobQueue($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'jobs' => $queue->list(),
            ]);
        }

        if ($method === 'POST' && $path === '/api/v1/jobs') {
            $queue = new LocalJobQueue($this->rootDir);
            $job = $queue->create([
                'job_id' => (string) ($body['job_id'] ?? ''),
                'command' => (string) ($body['command'] ?? 'crawl'),
                'args' => array_values(array_filter((array) ($body['args'] ?? []), static fn(mixed $v): bool => is_scalar($v))),
                'options' => (array) ($body['options'] ?? []),
                'source' => 'api',
            ]);
            return new ApiResponse(201, ['ok' => true, 'job' => $job]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/jobs/([^/]+)$#', $path, $m)) {
            $queue = new LocalJobQueue($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'job' => $queue->load(rawurldecode($m[1])),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/monitor/summary') {
            return new ApiResponse(200, [
                'ok' => true,
                'summary' => (new MonitoringSnapshot($this->rootDir))->collect(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/plugins') {
            $plugins = array_map(static fn($plugin): array => $plugin->toArray(), (new PluginManager($this->rootDir))->list(false));
            return new ApiResponse(200, ['ok' => true, 'plugins' => $plugins]);
        }

        if ($method === 'GET' && $path === '/api/v1/profiles') {
            $manager = new PluginManager($this->rootDir);
            $loader = new ProfileSchemaLoader($this->rootDir . '/config/profiles', $manager->profileFiles(true));
            return new ApiResponse(200, ['ok' => true, 'profiles' => $loader->list()]);
        }

        if ($method === 'GET' && $path === '/api/v1/dashboard') {
            return new ApiResponse(200, [
                'ok' => true,
                'dashboard' => (new DashboardDataCollector($this->rootDir))->collect(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/datasets') {
            return new ApiResponse(200, [
                'ok' => true,
                'datasets' => (new DatasetStore($this->rootDir))->list(),
            ]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/datasets/([^/]+)$#', $path, $m)) {
            return new ApiResponse(200, [
                'ok' => true,
                'dataset' => (new DatasetStore($this->rootDir))->show(rawurldecode($m[1])),
            ]);
        }

        return new ApiResponse(404, [
            'ok' => false,
            'error' => 'not_found',
            'message' => 'No API route matched this request.',
            'routes' => self::routes(),
        ]);
    }
}
