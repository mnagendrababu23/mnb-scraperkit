<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Api;

use Mnb\ScraperKit\Browser\BrowserSessionStore;
use Mnb\ScraperKit\Console\CommandRegistry;
use Mnb\ScraperKit\Dashboard\DashboardDataCollector;
use Mnb\ScraperKit\Dataset\DatasetStore;
use Mnb\ScraperKit\Distributed\DistributedQueueConfig;
use Mnb\ScraperKit\Distributed\DistributedQueueManager;
use Mnb\ScraperKit\Evaluation\DatasetEvaluator;
use Mnb\ScraperKit\Export\ExportConnectorStore;
use Mnb\ScraperKit\Monitoring\MonitoringSnapshot;
use Mnb\ScraperKit\Plugin\PluginManager;
use Mnb\ScraperKit\Profile\ProfileSchemaLoader;
use Mnb\ScraperKit\Queue\LocalJobQueue;
use Mnb\ScraperKit\RuleBuilder\AutoProfileAssistant;
use Mnb\ScraperKit\Template\TemplateCatalog;
use Mnb\ScraperKit\Security\SecurityAuditScanner;
use Mnb\ScraperKit\Security\ComplianceReportBuilder;
use Mnb\ScraperKit\Enterprise\AccessPolicy;
use Mnb\ScraperKit\Enterprise\AuditLog;
use Mnb\ScraperKit\Enterprise\UserStore;
use Mnb\ScraperKit\Enterprise\WorkspaceStore;

/**
 * Lightweight read-mostly JSON API router used by the optional api:serve command.
 *
 * It intentionally avoids framework dependencies. The API is suitable for local
 * dashboards, internal automation, and health checks. It should be protected by
 * an API token when exposed outside localhost.
 */
final class ApiRouter
{
    public const VERSION = '4.0.0';

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
            ['method' => 'GET', 'path' => '/api/v1/distributed/status', 'description' => 'Distributed queue counts, adapter, namespace, and worker group.'],
            ['method' => 'GET', 'path' => '/api/v1/distributed/doctor', 'description' => 'Distributed queue adapter and Redis capability check.'],
            ['method' => 'GET', 'path' => '/api/v1/jobs', 'description' => 'List queued jobs. Optional query: state.'],
            ['method' => 'GET', 'path' => '/api/v1/jobs/{job_id}', 'description' => 'Read one queued job manifest.'],
            ['method' => 'POST', 'path' => '/api/v1/jobs', 'description' => 'Create one local queue job from JSON body.'],
            ['method' => 'GET', 'path' => '/api/v1/monitor/summary', 'description' => 'Monitoring snapshot for queue, schedules, and locks.'],
            ['method' => 'GET', 'path' => '/api/v1/plugins', 'description' => 'List discovered config-only plugins.'],
            ['method' => 'GET', 'path' => '/api/v1/profiles', 'description' => 'List built-in and plugin profile schemas.'],
            ['method' => 'GET', 'path' => '/api/v1/dashboard', 'description' => 'Read consolidated dashboard data for local admin screens.'],
            ['method' => 'GET', 'path' => '/api/v1/datasets', 'description' => 'List local dataset snapshots.'],
            ['method' => 'GET', 'path' => '/api/v1/datasets/{dataset_id}', 'description' => 'Read one dataset manifest.'],
            ['method' => 'GET', 'path' => '/api/v1/datasets/{dataset_id}/evaluation', 'description' => 'Evaluate one dataset for quality, completeness, and training readiness.'],
            ['method' => 'GET', 'path' => '/api/v1/rule-builder/templates', 'description' => 'List auto-profile rule builder template names and assistant version.'],
            ['method' => 'GET', 'path' => '/api/v1/browser/sessions', 'description' => 'List authorized browser session profiles.'],
            ['method' => 'GET', 'path' => '/api/v1/export-connectors', 'description' => 'List configured export delivery connectors.'],
            ['method' => 'GET', 'path' => '/api/v1/export-connectors/{connector_id}', 'description' => 'Read one export delivery connector.'],
            ['method' => 'GET', 'path' => '/api/v1/project-templates', 'description' => 'List bundled project templates.'],
            ['method' => 'GET', 'path' => '/api/v1/project-templates/{template_id}', 'description' => 'Read one project template manifest.'],
            ['method' => 'GET', 'path' => '/api/v1/preset-packs', 'description' => 'List bundled preset packs.'],
            ['method' => 'GET', 'path' => '/api/v1/preset-packs/{pack_id}', 'description' => 'Read one preset pack manifest.'],
            ['method' => 'GET', 'path' => '/api/v1/security/audit', 'description' => 'Run read-only security audit summary for local admin/API consumers.'],
            ['method' => 'GET', 'path' => '/api/v1/compliance/report', 'description' => 'Run read-only responsible crawling/compliance report.'],
            ['method' => 'GET', 'path' => '/api/v1/enterprise/summary', 'description' => 'Read enterprise workspace, user, role, and audit summary.'],
            ['method' => 'GET', 'path' => '/api/v1/enterprise/workspaces', 'description' => 'List enterprise workspaces.'],
            ['method' => 'GET', 'path' => '/api/v1/enterprise/workspaces/{workspace_id}', 'description' => 'Read one enterprise workspace.'],
            ['method' => 'GET', 'path' => '/api/v1/enterprise/users', 'description' => 'List enterprise users.'],
            ['method' => 'GET', 'path' => '/api/v1/enterprise/audit', 'description' => 'List recent enterprise audit events.'],
            ['method' => 'GET', 'path' => '/api/v1/browser/sessions/{name}', 'description' => 'Read one authorized browser session profile.'],
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

        if ($method === 'GET' && $path === '/api/v1/distributed/status') {
            $manager = new DistributedQueueManager(DistributedQueueConfig::fromArray([], $this->rootDir));
            return new ApiResponse(200, ['ok' => true, 'distributed' => $manager->status()]);
        }

        if ($method === 'GET' && $path === '/api/v1/distributed/doctor') {
            $manager = new DistributedQueueManager(DistributedQueueConfig::fromArray([], $this->rootDir));
            return new ApiResponse(200, ['ok' => true, 'distributed' => $manager->doctor()]);
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


        if ($method === 'GET' && $path === '/api/v1/rule-builder/templates') {
            return new ApiResponse(200, [
                'ok' => true,
                'rule_builder_version' => self::VERSION,
                'templates' => ['auto', 'seo', 'ecommerce', 'jobs', 'tender', 'academic'],
                'commands' => ['rule:analyze', 'rule:generate', 'rule:test', 'rule:doctor', 'profile:scaffold'],
            ]);
        }



        if ($method === 'GET' && $path === '/api/v1/export-connectors') {
            $store = new ExportConnectorStore($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'export_connector_version' => self::VERSION,
                'connectors' => $store->list(),
            ]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/export-connectors/([^/]+)$#', $path, $m)) {
            $store = new ExportConnectorStore($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'connector' => $store->show(rawurldecode($m[1])),
            ]);
        }


        if ($method === 'GET' && $path === '/api/v1/project-templates') {
            $catalog = new TemplateCatalog($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'template_version' => self::VERSION,
                'templates' => array_map(static fn($template): array => $template->summary(), $catalog->templates()),
            ]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/project-templates/([^/]+)$#', $path, $m)) {
            $catalog = new TemplateCatalog($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'template' => $catalog->template(rawurldecode($m[1]))->toArray(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/preset-packs') {
            $catalog = new TemplateCatalog($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'preset_pack_version' => self::VERSION,
                'preset_packs' => array_map(static fn($pack): array => $pack->summary(), $catalog->presetPacks()),
            ]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/preset-packs/([^/]+)$#', $path, $m)) {
            $catalog = new TemplateCatalog($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'preset_pack' => $catalog->presetPack(rawurldecode($m[1]))->toArray(),
            ]);
        }


        if ($method === 'GET' && $path === '/api/v1/security/audit') {
            return new ApiResponse(200, [
                'ok' => true,
                'security' => (new SecurityAuditScanner($this->rootDir))->audit(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/compliance/report') {
            return new ApiResponse(200, [
                'ok' => true,
                'compliance' => (new ComplianceReportBuilder($this->rootDir))->build(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/enterprise/summary') {
            return new ApiResponse(200, [
                'ok' => true,
                'enterprise_version' => self::VERSION,
                'workspaces' => (new WorkspaceStore($this->rootDir))->summary(),
                'users' => (new UserStore($this->rootDir))->summary(),
                'roles' => AccessPolicy::describe(),
                'recent_audit_events' => (new AuditLog($this->rootDir))->list(10),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/enterprise/workspaces') {
            return new ApiResponse(200, [
                'ok' => true,
                'workspaces' => (new WorkspaceStore($this->rootDir))->list(),
            ]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/enterprise/workspaces/([^/]+)$#', $path, $m)) {
            return new ApiResponse(200, [
                'ok' => true,
                'workspace' => (new WorkspaceStore($this->rootDir))->show(rawurldecode($m[1])),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/enterprise/users') {
            return new ApiResponse(200, [
                'ok' => true,
                'users' => (new UserStore($this->rootDir))->list(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/enterprise/audit') {
            return new ApiResponse(200, [
                'ok' => true,
                'events' => (new AuditLog($this->rootDir))->list(50),
            ]);
        }

        if ($method === 'GET' && $path === '/api/v1/browser/sessions') {
            $store = new BrowserSessionStore($this->rootDir);
            return new ApiResponse(200, [
                'ok' => true,
                'profiles_dir' => $store->profilesDir(),
                'sessions_dir' => $store->sessionsDir(),
                'sessions' => $store->list(),
            ]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/browser/sessions/([^/]+)$#', $path, $m)) {
            $store = new BrowserSessionStore($this->rootDir);
            $profile = $store->load(rawurldecode($m[1]));
            $data = $profile->toArray();
            $data['cookie_file_exists'] = $profile->cookieFile ? is_file($profile->cookieFile) : false;
            return new ApiResponse(200, ['ok' => true, 'session' => $data]);
        }

        if ($method === 'GET' && $path === '/api/v1/datasets') {
            return new ApiResponse(200, [
                'ok' => true,
                'datasets' => (new DatasetStore($this->rootDir))->list(),
            ]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/datasets/([^/]+)/evaluation$#', $path, $m)) {
            $store = new DatasetStore($this->rootDir);
            $id = rawurldecode($m[1]);
            $manifest = $store->show($id);
            $annotationsFile = rtrim((string) ($manifest['_dataset_dir'] ?? ''), '/\\') . '/' . (string) ($manifest['annotations_file'] ?? 'annotations.json');
            $annotations = is_file($annotationsFile) ? json_decode((string) file_get_contents($annotationsFile), true) : null;
            return new ApiResponse(200, [
                'ok' => true,
                'evaluation' => (new DatasetEvaluator())->evaluate($store->records($id), $manifest, is_array($annotations) ? $annotations : null),
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
