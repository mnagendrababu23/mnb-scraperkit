<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Mnb\ScraperKit\Browser\BrowserCrawlService;
use Mnb\ScraperKit\Api\ApiRouter;
use Mnb\ScraperKit\Api\ApiToken;
use Mnb\ScraperKit\Browser\BrowserFallbackDetector;
use Mnb\ScraperKit\Browser\BrowserOptions;
use Mnb\ScraperKit\Browser\BrowserPageResult;
use Mnb\ScraperKit\Browser\BrowserSessionStore;
use Mnb\ScraperKit\Console\CommandRegistry;
use Mnb\ScraperKit\Cli\NativeCliApplication;
use Mnb\ScraperKit\Database\DatabaseConfig;
use Mnb\ScraperKit\Database\DatabaseSchema;
use Mnb\ScraperKit\Dashboard\DashboardDataCollector;
use Mnb\ScraperKit\Dashboard\DashboardRenderer;
use Mnb\ScraperKit\Dataset\AnnotationStore;
use Mnb\ScraperKit\Dataset\DatasetComparator;
use Mnb\ScraperKit\Dataset\DatasetExporter;
use Mnb\ScraperKit\Dataset\DatasetStore;
use Mnb\ScraperKit\Evaluation\AnnotationQuality;
use Mnb\ScraperKit\Evaluation\DatasetEvaluator;
use Mnb\ScraperKit\Evaluation\ProfileBenchmark;
use Mnb\ScraperKit\Evaluation\SelectorPerformanceEvaluator;
use Mnb\ScraperKit\Export\ExportConnectorStore;
use Mnb\ScraperKit\Export\ExportDeliveryService;
use Mnb\ScraperKit\Export\ExportManifestBuilder;
use Mnb\ScraperKit\Template\TemplateCatalog;
use Mnb\ScraperKit\Template\TemplateInstaller;
use Mnb\ScraperKit\Distributed\DistributedQueueConfig;
use Mnb\ScraperKit\Distributed\DistributedQueueManager;
use Mnb\ScraperKit\Intelligence\FeatureExtractor;
use Mnb\ScraperKit\Intelligence\PageClassifier;
use Mnb\ScraperKit\Intelligence\QualityPredictor;
use Mnb\ScraperKit\Intelligence\SelectorSuggester;
use Mnb\ScraperKit\Intelligence\UrlPrioritizer;
use Mnb\ScraperKit\Core\FailureClassifier;
use Mnb\ScraperKit\Core\PageResult;
use Mnb\ScraperKit\Core\RateLimiter;
use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Pipeline\JobManifest;
use Mnb\ScraperKit\Pipeline\PipelineOptions;
use Mnb\ScraperKit\Pipeline\ProfessionalCrawlPipeline;
use Mnb\ScraperKit\Profile\ProfileSchemaLoader;
use Mnb\ScraperKit\Profile\ProfileSchemaValidator;
use Mnb\ScraperKit\Plugin\PluginManager;
use Mnb\ScraperKit\Plugin\PluginValidator;
use Mnb\ScraperKit\Queue\LocalJobQueue;
use Mnb\ScraperKit\Retry\RetryPolicy;
use Mnb\ScraperKit\Scheduler\LocalScheduleStore;
use Mnb\ScraperKit\Monitoring\MonitoringSnapshot;
use Mnb\ScraperKit\Extractor\RuleBasedExtractor;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Report\ProjectBundleExporter;
use Mnb\ScraperKit\Report\RecordExportService;
use Mnb\ScraperKit\Report\ReportDataCollector;
use Mnb\ScraperKit\Report\ReportExporter;
use Mnb\ScraperKit\RuleBuilder\RuleBuilderService;
use Mnb\ScraperKit\RuleBuilder\HtmlSignalAnalyzer;
use Mnb\ScraperKit\RuleBuilder\AutoProfileAssistant;
use Mnb\ScraperKit\Security\CompliancePolicy;
use Mnb\ScraperKit\Security\ComplianceReportBuilder;
use Mnb\ScraperKit\Security\SecretScanner;
use Mnb\ScraperKit\Security\SecurityAuditScanner;
use Mnb\ScraperKit\Enterprise\AccessPolicy;
use Mnb\ScraperKit\Enterprise\AuditLog;
use Mnb\ScraperKit\Enterprise\UserStore;
use Mnb\ScraperKit\Enterprise\WorkspaceStore;
use Mnb\ScraperKit\Hardening\BenchmarkRunner;
use Mnb\ScraperKit\Hardening\ProductionReadinessInspector;
use Mnb\ScraperKit\Hardening\PublicCommandContract;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;
use Mnb\ScraperKit\Source\Sitemap\SitemapReader;
use Mnb\ScraperKit\Source\Csv\CsvUrlSourceReader;
use Mnb\ScraperKit\Source\Json\JsonUrlSourceReader;
use Mnb\ScraperKit\Support\UrlNormalizer;
use Mnb\ScraperKit\Webhook\WebhookDispatcher;
use Mnb\ScraperKit\Webhook\WebhookEndpointStore;

$tests = [];


$tests['v4.0.1 enterprise stores create users workspaces memberships and audit events'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_enterprise_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $users = new UserStore($root);
    $workspaces = new WorkspaceStore($root);
    $user = $users->create(['user_id' => 'admin@example.com', 'display_name' => 'Admin', 'role' => 'owner']);
    assert(($user['role'] ?? null) === 'owner', 'enterprise user role mismatch');
    $workspace = $workspaces->create(['name' => 'SEO Team', 'workspace_id' => 'seo-team', 'owner' => 'admin@example.com']);
    assert(($workspace['workspace_id'] ?? null) === 'seo-team', 'workspace id mismatch');
    $updated = $workspaces->assignUser('seo-team', 'analyst@example.com', 'analyst', 'admin@example.com');
    assert(count((array) ($updated['members'] ?? [])) === 2, 'workspace member count mismatch');
    $summary = $workspaces->summary();
    assert(($summary['workspaces_total'] ?? 0) === 1, 'workspace summary mismatch');
    $events = (new AuditLog($root))->list(10);
    assert(count($events) >= 3, 'enterprise audit events missing');
    assert(isset(AccessPolicy::capabilities()['operator']), 'operator role missing');
};

$tests['native enterprise commands create user workspace and show audit events'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_enterprise_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $config = is_file(dirname(__DIR__) . '/config/scraper.php') ? require dirname(__DIR__) . '/config/scraper.php' : [];
    $app = new NativeCliApplication($config, $root);
    ob_start();
    $doctor = $app->run(['mnb-scraper', 'enterprise:doctor', '--json']);
    $roles = $app->run(['mnb-scraper', 'enterprise:roles']);
    $user = $app->run(['mnb-scraper', 'user:create', 'admin@example.com', '--display-name=Admin', '--role=owner']);
    $workspace = $app->run(['mnb-scraper', 'workspace:create', 'seo-team', '--owner=admin@example.com']);
    $assign = $app->run(['mnb-scraper', 'workspace:assign-user', 'seo-team', 'analyst@example.com', '--role=analyst']);
    $list = $app->run(['mnb-scraper', 'workspace:list', '--json']);
    $show = $app->run(['mnb-scraper', 'workspace:show', 'seo-team']);
    $users = $app->run(['mnb-scraper', 'user:list', '--json']);
    $audit = $app->run(['mnb-scraper', 'audit:events', '--limit=5']);
    ob_end_clean();
    foreach (['doctor' => $doctor, 'roles' => $roles, 'user' => $user, 'workspace' => $workspace, 'assign' => $assign, 'list' => $list, 'show' => $show, 'users' => $users, 'audit' => $audit] as $name => $code) {
        assert($code === 0, 'native enterprise command failed: ' . $name);
    }
};

$tests['API router exposes enterprise summary workspace users and audit routes'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_enterprise_api_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    (new UserStore($root))->create(['user_id' => 'owner@example.com', 'role' => 'owner']);
    (new WorkspaceStore($root))->create(['name' => 'Research', 'workspace_id' => 'research', 'owner' => 'owner@example.com']);
    $token = ApiToken::generate('enterprise');
    $router = new ApiRouter($root, $token);
    $headers = ['Authorization' => 'Bearer ' . $token];
    $summary = $router->handle('GET', '/api/v1/enterprise/summary', $headers);
    $workspaces = $router->handle('GET', '/api/v1/enterprise/workspaces', $headers);
    $workspace = $router->handle('GET', '/api/v1/enterprise/workspaces/research', $headers);
    $users = $router->handle('GET', '/api/v1/enterprise/users', $headers);
    $audit = $router->handle('GET', '/api/v1/enterprise/audit', $headers);
    assert($summary->status === 200 && (($summary->body['workspaces']['workspaces_total'] ?? 0) === 1), 'enterprise API summary failed');
    assert($workspaces->status === 200 && count((array) ($workspaces->body['workspaces'] ?? [])) === 1, 'enterprise API workspaces failed');
    assert($workspace->status === 200 && (($workspace->body['workspace']['workspace_id'] ?? null) === 'research'), 'enterprise API workspace failed');
    assert($users->status === 200 && count((array) ($users->body['users'] ?? [])) === 1, 'enterprise API users failed');
    assert($audit->status === 200 && count((array) ($audit->body['events'] ?? [])) >= 2, 'enterprise API audit failed');
};

$tests['v4.0.1 command registry exposes enterprise commands without duplicate options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['enterprise:doctor', 'enterprise:roles', 'workspace:create', 'workspace:list', 'workspace:show', 'workspace:assign-user', 'user:create', 'user:list', 'user:disable', 'audit:events'] as $command) {
        assert(isset($commands[$command]), 'missing v4.0.1 enterprise command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['workspace', 'workspace-id', 'owner', 'actor', 'role', 'display-name', 'retention-days', 'active-only', 'action'] as $option) {
        assert(in_array($option, $options, true), 'missing v4.0.1 enterprise option: ' . $option);
    }
    assert(count($options) === count(array_unique($options)), 'Symfony option registry contains duplicate option names');
};


$tests['v4.0.1 command registry exposes distributed worker commands without duplicate options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['distributed:doctor', 'distributed:status', 'distributed:enqueue', 'distributed:reserve', 'distributed:ack', 'distributed:fail', 'distributed:heartbeat', 'distributed:purge', 'worker:distributed'] as $command) {
        assert(isset($commands[$command]), 'missing v4.0.1 distributed command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['distributed-adapter', 'redis-url', 'namespace', 'queue-name', 'worker-group', 'visibility-timeout', 'lease-id', 'distributed-dir', 'payload-file', 'once'] as $option) {
        assert(in_array($option, $options, true), 'missing v4.0.1 distributed option: ' . $option);
    }
    assert(count($options) === count(array_unique($options)), 'Symfony option registry contains duplicate option names');
};

$tests['distributed file adapter enqueues reserves heartbeats acks and reports status'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_dist_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $config = DistributedQueueConfig::fromArray(['distributed-adapter' => 'file', 'queue-name' => 'tests', 'namespace' => 'mnb_test'], $root);
    $manager = new DistributedQueueManager($config);
    $job = $manager->enqueue(['job_id' => 'dist_test', 'command' => 'common:types', 'args' => [], 'options' => ['json' => true]]);
    assert(($job['id'] ?? null) === 'dist_test', 'distributed enqueue id mismatch');
    $reserved = $manager->reserve('worker-a');
    assert($reserved !== null && $reserved->id === 'dist_test', 'distributed reserve failed');
    $heartbeat = $manager->heartbeat($reserved->id, 'worker-a', (string) ($reserved->metadata['lease_id'] ?? ''));
    assert(($heartbeat['worker_id'] ?? null) === 'worker-a', 'distributed heartbeat failed');
    $ack = $manager->ack($reserved->id, (string) ($reserved->metadata['lease_id'] ?? ''));
    assert(($ack['state'] ?? null) === 'completed', 'distributed ack failed');
    $status = $manager->status();
    assert(($status['counts']['completed'] ?? 0) === 1, 'distributed completed count mismatch');
};

$tests['native distributed commands run with file adapter'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_dist_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $config = is_file(dirname(__DIR__) . '/config/scraper.php') ? require dirname(__DIR__) . '/config/scraper.php' : [];
    $app = new NativeCliApplication($config, $root);
    ob_start();
    $doctor = $app->run(['mnb-scraper', 'distributed:doctor', '--distributed-adapter=file']);
    $enqueue = $app->run(['mnb-scraper', 'distributed:enqueue', '--distributed-adapter=file', '--command=common:types', '--json']);
    $status = $app->run(['mnb-scraper', 'distributed:status', '--distributed-adapter=file']);
    $worker = $app->run(['mnb-scraper', 'worker:distributed', '--distributed-adapter=file', '--stop-when-empty', '--max-jobs=1', '--dry-run']);
    ob_end_clean();
    foreach (['doctor' => $doctor, 'enqueue' => $enqueue, 'status' => $status, 'worker' => $worker] as $name => $code) {
        assert($code === 0, 'native distributed command failed: ' . $name);
    }
};

$tests['API router exposes distributed queue status and doctor routes'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_api_dist_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $token = ApiToken::generate('dist');
    $router = new ApiRouter($root, $token);
    $status = $router->handle('GET', '/api/v1/distributed/status', ['Authorization' => 'Bearer ' . $token]);
    assert($status->status === 200 && isset($status->body['distributed']), 'distributed API status failed');
    $doctor = $router->handle('GET', '/api/v1/distributed/doctor', ['Authorization' => 'Bearer ' . $token]);
    assert($doctor->status === 200 && isset($doctor->body['distributed']['selected_adapter']), 'distributed API doctor failed');
};


$tests['v4.0.1 dataset command registry exposes dataset and annotation commands'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['dataset:create', 'dataset:list', 'dataset:show', 'dataset:diff', 'dataset:export', 'annotation:init', 'annotation:add'] as $command) {
        assert(isset($commands[$command]), 'missing v4.0.1 dataset command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['datasets-dir', 'dataset-dir', 'dataset-id', 'record-id', 'label', 'note', 'old', 'new', 'annotations'] as $option) {
        assert(in_array($option, $options, true), 'missing v4.0.1 dataset option: ' . $option);
    }
};

$tests['dataset store creates snapshots exports annotations and diffs'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_dataset_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $inputA = $root . '/records-a.json';
    file_put_contents($inputA, json_encode(['records' => [[
        'record_id' => 'rec-a',
        'record_type' => 'product',
        'source_url' => 'https://example.com/a',
        'dedupe_key' => 'sku-a',
        'fields' => ['title' => 'A', 'price' => '10'],
        'validation' => ['status' => 'valid'],
        'quality_score' => 92,
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $inputB = $root . '/records-b.json';
    file_put_contents($inputB, json_encode(['records' => [[
        'record_id' => 'rec-a',
        'record_type' => 'product',
        'source_url' => 'https://example.com/a',
        'dedupe_key' => 'sku-a',
        'fields' => ['title' => 'A changed', 'price' => '12'],
        'validation' => ['status' => 'valid'],
        'quality_score' => 88,
    ], [
        'record_id' => 'rec-b',
        'record_type' => 'product',
        'source_url' => 'https://example.com/b',
        'dedupe_key' => 'sku-b',
        'fields' => ['title' => 'B'],
        'validation' => ['status' => 'warning'],
        'quality_score' => 45,
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $store = new DatasetStore($root);
    $createdA = $store->create($inputA, 'dataset_a');
    $createdB = $store->create($inputB, 'dataset_b');
    assert(is_file($createdA['dataset_dir'] . '/dataset-manifest.json'), 'dataset manifest missing');
    assert(is_file($createdA['dataset_dir'] . '/records.jsonl'), 'dataset JSONL missing');
    assert(($createdA['manifest']['summary']['records_total'] ?? null) === 1, 'dataset record count mismatch');
    assert(count($store->list()) === 2, 'dataset list count mismatch');

    $csv = $createdA['dataset_dir'] . '/export.csv';
    $export = (new DatasetExporter())->export($store->records('dataset_a'), $csv, 'csv');
    assert(is_file($csv) && ($export['records_exported'] ?? 0) === 1, 'dataset CSV export missing');

    $diff = (new DatasetComparator())->compare($store->records('dataset_a'), $store->records('dataset_b'));
    assert(($diff['added_total'] ?? null) === 1, 'dataset diff added mismatch');
    assert(($diff['changed_total'] ?? null) === 1, 'dataset diff changed mismatch');

    $annotations = (new AnnotationStore())->init($createdA['dataset_dir']);
    $add = (new AnnotationStore())->add($annotations['output'], 'rec-a', 'good', 'Ready for training');
    assert(($add['annotations_total'] ?? 0) === 1, 'annotation add mismatch');
};

$tests['native dataset commands create list show export diff and annotate'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_dataset_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $inputA = $root . '/crawl-a.json';
    file_put_contents($inputA, json_encode(['pages' => [[
        'url' => 'https://example.com/a',
        'final_url' => 'https://example.com/a',
        'status_code' => 200,
        'title' => 'A',
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $inputB = $root . '/crawl-b.json';
    file_put_contents($inputB, json_encode(['pages' => [[
        'url' => 'https://example.com/a',
        'final_url' => 'https://example.com/a',
        'status_code' => 200,
        'title' => 'A changed',
    ], [
        'url' => 'https://example.com/b',
        'status_code' => 200,
        'title' => 'B',
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $config = is_file(dirname(__DIR__) . '/config/scraper.php') ? require dirname(__DIR__) . '/config/scraper.php' : [];
    $app = new NativeCliApplication($config, $root);
    ob_start();
    $createA = $app->run(['mnb-scraper', 'dataset:create', $inputA, '--id=cli_a']);
    $createB = $app->run(['mnb-scraper', 'dataset:create', $inputB, '--id=cli_b']);
    $list = $app->run(['mnb-scraper', 'dataset:list', '--json']);
    $show = $app->run(['mnb-scraper', 'dataset:show', 'cli_a', '--json']);
    $export = $app->run(['mnb-scraper', 'dataset:export', 'cli_a', '--format=jsonl']);
    $diff = $app->run(['mnb-scraper', 'dataset:diff', 'cli_a', 'cli_b', '--json']);
    $init = $app->run(['mnb-scraper', 'annotation:init', $root . '/storage/datasets/cli_a']);
    $add = $app->run(['mnb-scraper', 'annotation:add', $root . '/storage/datasets/cli_a/annotations.json', '--record-id=rec-test', '--label=review']);
    ob_end_clean();
    foreach (['createA' => $createA, 'createB' => $createB, 'list' => $list, 'show' => $show, 'export' => $export, 'diff' => $diff, 'init' => $init, 'add' => $add] as $name => $code) {
        assert($code === 0, 'native dataset command failed: ' . $name);
    }
};

$tests['API router exposes dataset list and show routes'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_api_dataset_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $input = $root . '/records.json';
    file_put_contents($input, json_encode(['records' => [['record_id' => 'r1', 'fields' => ['title' => 'One']]]], JSON_UNESCAPED_SLASHES));
    (new DatasetStore($root))->create($input, 'api_dataset');
    $token = ApiToken::generate('test');
    $router = new ApiRouter($root, $token);
    $list = $router->handle('GET', '/api/v1/datasets', ['Authorization' => 'Bearer ' . $token]);
    assert($list->status === 200 && count($list->body['datasets'] ?? []) === 1, 'dataset API list failed');
    $show = $router->handle('GET', '/api/v1/datasets/api_dataset', ['Authorization' => 'Bearer ' . $token]);
    assert($show->status === 200 && ($show->body['dataset']['dataset_id'] ?? null) === 'api_dataset', 'dataset API show failed');
};


$tests['database config defaults to local SQLite and schema exposes storage tables'] = function (): void {
    $root = dirname(__DIR__);
    $config = DatabaseConfig::fromArray([], $root);
    assert($config->driver() === 'sqlite', 'default database driver should be sqlite');
    assert(str_contains($config->dsn, '/storage/database/mnb-scraperkit.sqlite'), 'default SQLite path mismatch');
    $tables = DatabaseSchema::tableNames();
    foreach (['mnb_storage_jobs', 'mnb_storage_pages', 'mnb_storage_records', 'mnb_storage_failed_urls', 'mnb_storage_validation_issues', 'mnb_storage_exports'] as $table) {
        assert(in_array($table, $tables, true), 'missing storage table: ' . $table);
    }
    assert(count(DatabaseSchema::statements('sqlite')) >= 6, 'SQLite schema statements missing');
    assert(count(DatabaseSchema::statements('mysql')) >= 6, 'MySQL schema statements missing');
};

$tests['database command registry exposes v4.0.1 database commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['db:init', 'db:test', 'db:status', 'db:save-crawl', 'db:save-pipeline', 'db:export'] as $command) {
        assert(isset($commands[$command]), 'missing database command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['database-url', 'sqlite', 'db-user', 'db-pass', 'table', 'limit'] as $option) {
        assert(in_array($option, $options, true), 'missing database option: ' . $option);
    }
};

$tests['v4.0.1 command registry exposes retry scheduling and monitoring commands'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['retry:plan', 'queue:retry-safe', 'schedule:create', 'schedule:list', 'schedule:show', 'schedule:run-due', 'schedule:enable', 'schedule:disable', 'monitor:summary', 'monitor:stale-locks'] as $command) {
        assert(isset($commands[$command]), 'missing v4.0.1 command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['command', 'arg', 'schedule-id', 'every-minutes', 'every-hours', 'dry-run', 'failed-jobs', 'ttl-seconds'] as $option) {
        assert(in_array($option, $options, true), 'missing v4.0.1 option: ' . $option);
    }
};


$tests['v4.0.1 plugin command registry exposes plugin commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['plugin:list', 'plugin:show', 'plugin:validate', 'plugin:install', 'plugin:enable', 'plugin:disable', 'plugin:doctor'] as $command) {
        assert(isset($commands[$command]), 'missing v4.0.1 plugin command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['plugin-dir', 'plugin-id', 'plugins-dir', 'all', 'force'] as $option) {
        assert(in_array($option, $options, true), 'missing plugin option: ' . $option);
    }
};

$tests['plugin manager discovers validates and exposes plugin profile schemas'] = function (): void {
    $root = dirname(__DIR__);
    $manager = new PluginManager($root);
    $plugin = $manager->get('mnb.example.profile-addon');
    assert($plugin !== null, 'example plugin should be discoverable');
    assert($plugin->enabled === true, 'example plugin should be enabled');
    assert(count($plugin->resolvedProfiles()) === 1, 'example plugin profile should resolve');
    $validation = (new PluginValidator())->validateFile($root . '/plugins/example-profile-addon/mnb-plugin.json');
    assert(($validation['valid'] ?? false) === true, 'example plugin manifest should validate');
    assert(count($manager->profileFiles(true)) >= 1, 'plugin manager should expose profile files');

    $loader = new ProfileSchemaLoader($root . '/config/profiles', $manager->profileFiles(true));
    $schema = $loader->load('research-paper');
    assert($schema->profile === 'research-paper', 'plugin profile not loadable');
    assert($schema->recordType === 'article', 'plugin record type mismatch');
};

$tests['native plugin commands list show validate and doctor bundled plugin'] = function (): void {
    $root = dirname(__DIR__);
    $config = is_file($root . '/config/scraper.php') ? require $root . '/config/scraper.php' : [];
    $app = new NativeCliApplication($config, $root);
    ob_start();
    $listCode = $app->run(['mnb-scraper', 'plugin:list']);
    $showCode = $app->run(['mnb-scraper', 'plugin:show', 'mnb.example.profile-addon']);
    $validateCode = $app->run(['mnb-scraper', 'plugin:validate', $root . '/plugins/example-profile-addon']);
    $doctorCode = $app->run(['mnb-scraper', 'plugin:doctor']);
    $profileCode = $app->run(['mnb-scraper', 'profile:show', 'research-paper', '--json']);
    ob_end_clean();
    assert($listCode === 0, 'plugin:list failed');
    assert($showCode === 0, 'plugin:show failed');
    assert($validateCode === 0, 'plugin:validate failed');
    assert($doctorCode === 0, 'plugin:doctor failed');
    assert($profileCode === 0, 'plugin profile should be usable through profile:show');
};

$tests['retry policy allows temporary failures and blocks policy failures'] = function (): void {
    $policy = new RetryPolicy(maxAttempts: 3, baseDelaySeconds: 10, multiplier: 2.0, maxDelaySeconds: 100);
    $timeout = $policy->decision(['url' => 'https://example.com/a', 'failure_type' => 'timeout', 'attempts' => 1]);
    assert($timeout['retry_eligible'] === true, 'timeout should be retryable');
    assert($timeout['retry_delay_seconds'] === 20, 'backoff delay mismatch');
    $robots = $policy->decision(['url' => 'https://example.com/b', 'failure_type' => 'robots_blocked', 'attempts' => 0]);
    assert($robots['retry_eligible'] === false, 'robots blocked should not be retried');
    $plan = $policy->plan([['failure_type' => 'timeout'], ['failure_type' => 'robots_blocked']]);
    assert($plan['total'] === 2 && $plan['eligible'] === 1, 'retry plan counts mismatch');
};

$tests['local schedule store creates due schedules and monitoring snapshot reports health'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_schedule_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $store = new LocalScheduleStore($root);
    $schedule = $store->create([
        'schedule_id' => 'test_schedule',
        'command' => 'crawl',
        'args' => ['https://example.com'],
        'interval_seconds' => 60,
        'next_run_at' => date(DATE_ATOM, time() - 60),
    ]);
    assert($schedule['schedule_id'] === 'test_schedule', 'schedule id mismatch');
    assert(count($store->due()) === 1, 'schedule should be due');
    $store->markRun($schedule, 'job_1');
    assert(count($store->due()) === 0, 'schedule should move to future after run');
    $snapshot = (new MonitoringSnapshot($root))->collect(900);
    assert(isset($snapshot['queue_counts'], $snapshot['schedule_counts']), 'monitoring snapshot missing counts');
    assert(($snapshot['schedule_counts']['total'] ?? 0) === 1, 'monitoring schedule count mismatch');
};




$tests['v4.0.1 API and webhook command registry exposes automation commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['api:routes', 'api:token', 'api:serve', 'webhook:list', 'webhook:test', 'webhook:send'] as $command) {
        assert(isset($commands[$command]), 'missing v4.0.1 command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['host', 'port', 'prefix', 'print-command', 'webhook-url', 'webhook-header', 'webhook-secret', 'config', 'payload'] as $option) {
        assert(in_array($option, $options, true), 'missing v4.0.1 option: ' . $option);
    }
};

$tests['lightweight API router exposes health routes and queue job creation'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_api_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $token = ApiToken::generate('test');
    $router = new ApiRouter($root, $token);
    $unauthorized = $router->handle('GET', '/api/v1/health', []);
    assert($unauthorized->status === 401, 'API should require token when configured');
    $health = $router->handle('GET', '/api/v1/health', ['Authorization' => 'Bearer ' . $token]);
    assert($health->status === 200 && ($health->body['ok'] ?? false) === true, 'health route failed');
    $created = $router->handle('POST', '/api/v1/jobs', ['Authorization' => 'Bearer ' . $token], [
        'job_id' => 'api-job',
        'command' => 'source:csv',
        'args' => ['urls.csv'],
        'options' => ['url-column' => 'url'],
    ]);
    assert($created->status === 201, 'API job creation failed');
    $queue = $router->handle('GET', '/api/v1/queue/status', ['Authorization' => 'Bearer ' . $token]);
    assert(($queue->body['counts']['pending'] ?? 0) === 1, 'API queue count mismatch');
};

$tests['webhook dispatcher writes local events and endpoint store reads config'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_webhook_' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0775, true);
    $config = $root . '/config/webhooks.json';
    file_put_contents($config, json_encode(['endpoints' => [[
        'name' => 'ops',
        'url' => 'https://example.com/webhook',
        'events' => ['job.completed'],
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $endpoints = (new WebhookEndpointStore($root))->list();
    assert(count($endpoints) === 1 && ($endpoints[0]['name'] ?? null) === 'ops', 'webhook endpoint config mismatch');
    $out = $root . '/storage/webhooks/test.json';
    $result = (new WebhookDispatcher())->writeLocalEvent($out, 'job.completed', ['job_id' => 'abc']);
    assert(is_file($out), 'local webhook event missing');
    assert(($result['event'] ?? null) === 'job.completed', 'webhook event mismatch');
};

$tests['native API and webhook commands run without network by default'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_api_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $config = is_file(dirname(__DIR__) . '/config/scraper.php') ? require dirname(__DIR__) . '/config/scraper.php' : [];
    $app = new NativeCliApplication($config, $root);
    ob_start();
    $routesCode = $app->run(['mnb-scraper', 'api:routes', '--json']);
    $tokenCode = $app->run(['mnb-scraper', 'api:token', '--prefix=test']);
    $serveCode = $app->run(['mnb-scraper', 'api:serve', '--dry-run', '--json']);
    $webhookCode = $app->run(['mnb-scraper', 'webhook:test', '--event=test.event']);
    ob_end_clean();
    assert($routesCode === 0, 'api:routes failed');
    assert($tokenCode === 0, 'api:token failed');
    assert($serveCode === 0, 'api:serve dry-run failed');
    assert($webhookCode === 0, 'webhook:test failed');
};


$tests['v4.0.1 dashboard command registry exposes dashboard commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['dashboard:status', 'dashboard:build', 'dashboard:serve'] as $command) {
        assert(isset($commands[$command]), 'missing dashboard command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['dashboard-token', 'recent', 'refresh', 'host', 'port', 'print-command'] as $option) {
        assert(in_array($option, $options, true), 'missing dashboard option: ' . $option);
    }
};

$tests['dashboard collector and renderer expose local operations state'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_dashboard_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $queue = new LocalJobQueue($root);
    $queue->create(['job_id' => 'dashboard-job', 'command' => 'crawl', 'args' => ['https://example.com']]);
    $scheduleStore = new LocalScheduleStore($root);
    $scheduleStore->create(['schedule_id' => 'dashboard-schedule', 'command' => 'crawl', 'args' => ['https://example.com'], 'interval_seconds' => 60]);
    $data = (new DashboardDataCollector($root))->collect();
    assert(($data['dashboard_version'] ?? null) === '4.0.1', 'dashboard version mismatch');
    assert(($data['queue']['counts']['pending'] ?? 0) === 1, 'dashboard queue count mismatch');
    assert(($data['schedules']['total'] ?? 0) === 1, 'dashboard schedule count mismatch');
    $html = (new DashboardRenderer())->render($data);
    assert(str_contains($html, 'MNB ScraperKit Dashboard'), 'dashboard HTML title missing');
    assert(str_contains($html, 'dashboard-job'), 'dashboard recent job missing');
};

$tests['API router exposes dashboard summary route'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_api_dashboard_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $token = ApiToken::generate('test');
    $router = new ApiRouter($root, $token);
    $response = $router->handle('GET', '/api/v1/dashboard', ['Authorization' => 'Bearer ' . $token]);
    assert($response->status === 200, 'dashboard API route failed');
    assert(($response->body['dashboard']['dashboard_version'] ?? null) === '4.0.1', 'dashboard API version mismatch');
};

$tests['native dashboard commands run without starting server'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_dashboard_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $config = is_file(dirname(__DIR__) . '/config/scraper.php') ? require dirname(__DIR__) . '/config/scraper.php' : [];
    $app = new NativeCliApplication($config, $root);
    $output = $root . '/storage/dashboard/index.html';
    ob_start();
    $statusCode = $app->run(['mnb-scraper', 'dashboard:status', '--json']);
    $buildCode = $app->run(['mnb-scraper', 'dashboard:build', '--output=' . $output, '--json']);
    $serveCode = $app->run(['mnb-scraper', 'dashboard:serve', '--dry-run', '--json']);
    ob_end_clean();
    assert($statusCode === 0, 'dashboard:status failed');
    assert($buildCode === 0 && is_file($output), 'dashboard:build failed');
    assert($serveCode === 0, 'dashboard:serve dry-run failed');
};

$tests['Symfony command option registry has no duplicate option names'] = function (): void {
    $names = CommandRegistry::optionNames();
    $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1));
    assert($duplicates === [], 'duplicate Symfony option names: ' . implode(', ', $duplicates));
};


$tests['browser options normalize auto always and off modes'] = function (): void {
    assert(BrowserOptions::fromArray(['browser' => true])->mode === 'auto');
    assert(BrowserOptions::fromArray(['browser' => 'always'])->mode === 'always');
    assert(BrowserOptions::fromArray(['browser' => 'off'])->mode === 'off');
    $options = BrowserOptions::fromArray([
        'browser' => 'auto',
        'wait-selector' => '.product-title',
        'viewport-width' => 1440,
        'viewport-height' => 900,
        'rendered-html' => true,
        'screenshot' => true,
        'fallback-required-field' => ['title', 'price'],
    ]);
    assert($options->enabled(), 'browser auto should be enabled');
    assert($options->waitSelector === '.product-title', 'wait selector mismatch');
    assert($options->viewportWidth === 1440 && $options->viewportHeight === 900, 'viewport mismatch');
    assert($options->saveRenderedHtml === true && $options->screenshot === true, 'artifact flags mismatch');
    assert($options->requiredFields === ['title', 'price'], 'required fields mismatch');
};

$tests['browser fallback detector identifies JavaScript app and low text pages'] = function (): void {
    $page = new PageResult(
        url: 'https://example.com/app',
        statusCode: 200,
        title: 'App',
        html: '<!doctype html><div id="app"></div><script src="/bundle.js"></script>',
        text: 'Loading...',
        extracted: []
    );
    $assessment = (new BrowserFallbackDetector())->assessPage($page, BrowserOptions::fromArray([
        'browser' => 'auto',
        'fallback-min-text' => 50,
        'fallback-required-field' => 'title,price',
    ]));
    assert(($assessment['should_use_browser'] ?? false) === true, 'browser fallback should be recommended');
    assert(in_array('javascript_app_markers', $assessment['reasons'], true), 'JS app marker reason missing');
};

$tests['browser service stays optional when Panther is not installed'] = function (): void {
    $service = new BrowserCrawlService([]);
    $options = BrowserOptions::fromArray(['browser' => 'auto']);
    assert(is_bool($service->isAvailable($options)), 'availability should be boolean');
    assert($service->availability($options) !== '', 'availability message should be available');
    $result = new BrowserPageResult('https://example.com', 'https://example.com', 'Title', '<html></html>', 'Title', null, null, 1, 'test');
    assert(($result->toArray(false)['engine'] ?? null) === 'test', 'browser result array mismatch');
};

$tests['url normalizer removes tracking params and resolves relative URLs'] = function (): void {
    $normalizer = new UrlNormalizer();
    $url = $normalizer->normalize('/path/page?utm_source=x&a=1', 'https://Example.com/base/index.html');
    assert($url === 'https://example.com/path/page?a=1', 'normalized URL mismatch: ' . (string) $url);
};

$tests['safety guard blocks localhost, private IP, userinfo and unsafe schemes'] = function (): void {
    $guard = new UrlSafetyGuard();
    foreach ([
        'http://localhost/',
        'http://127.0.0.1/',
        'http://10.0.0.1/',
        'http://2130706433/',
        'http://0x7f000001/',
        'http://user:pass@example.com/',
        'ftp://example.com/file',
    ] as $url) {
        $blocked = false;
        try {
            $guard->assertAllowed($url);
        } catch (RuntimeException) {
            $blocked = true;
        }
        assert($blocked, 'expected blocked URL: ' . $url);
    }
};

$tests['failure classifier maps common crawl failures'] = function (): void {
    assert(FailureClassifier::fromHttp('Operation timed out', 0, 28) === 'timeout');
    assert(FailureClassifier::fromHttp('Could not resolve host: example.invalid', 0, 6) === 'dns_error');
    assert(FailureClassifier::fromHttp('SSL certificate problem', 0, 60) === 'ssl_error');
    assert(FailureClassifier::fromHttp('Maximum (5) redirects followed', 302) === 'redirect_loop');
    assert(FailureClassifier::fromHttp(null, 404) === 'http_4xx');
    assert(FailureClassifier::fromHttp(null, 503) === 'http_5xx');
    assert(FailureClassifier::fromSafetyMessage('URL safety check failed: private/reserved IP targets are blocked.') === 'private_ip_blocked');
};

$tests['rate limiter accepts v4.0.1 pacing options without sleeping unnecessarily'] = function (): void {
    $limiter = new RateLimiter();
    $options = CrawlOptions::fromArray([
        'delay_ms' => 0,
        'delay_jitter_ms' => 0,
        'pause_after_urls' => 0,
        'cooldown_after_failures' => 0,
    ]);
    $limiter->waitFor('https://example.com/a', $options);
    $limiter->registerOutcome('http_5xx', $options);
    assert(true);
};

$tests['job manifest reads checkpoint queue metadata'] = function (): void {
    $dir = sys_get_temp_dir() . '/mnb_manifest_' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    $checkpoint = $dir . '/checkpoint.json';
    file_put_contents($checkpoint, json_encode([
        'checkpoint_version' => '4.0.1',
        'updated_at' => '2026-01-01T00:00:00+00:00',
        'queues' => [
            'pending' => ['https://example.com/pending'],
            'completed' => ['https://example.com/done'],
            'failed' => ['https://example.com/fail'],
            'skipped' => ['https://example.com/skip'],
        ],
        'counts' => ['pending' => 1, 'completed' => 1, 'failed' => 1, 'skipped' => 1],
        'results' => [['url' => 'https://example.com/done', 'status' => 'completed']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $manifestPath = JobManifest::write($dir, 'bulk-crawl', [], ['checkpoint' => $checkpoint], []);
    $manifest = JobManifest::read($manifestPath);
    assert(($manifest['version'] ?? null) === '4.0.1');
    assert(($manifest['resume']['counts']['pending'] ?? null) === 1);
    assert(($manifest['resume']['last_processed_url'] ?? null) === 'https://example.com/done');
};

$tests['pipeline builds normalized records with validation, quality and dedupe'] = function (): void {
    $crawl = [
        'pages' => [
            [
                'url' => 'https://example.com/a',
                'final_url' => 'https://example.com/a?utm_source=x',
                'status_code' => 200,
                'title' => '  First   Page  ',
                'content_hash' => 'hash-a',
                'extracted' => [],
            ],
            [
                'url' => 'https://example.com/a-copy',
                'final_url' => 'https://example.com/a?utm_source=y',
                'status_code' => 200,
                'title' => 'First Page Copy',
                'content_hash' => 'hash-a',
                'extracted' => [],
            ],
        ],
    ];

    $result = (new ProfessionalCrawlPipeline())->runFromCrawlArray($crawl, PipelineOptions::fromArray([
        'required_fields' => ['page_title'],
        'dedupe_keys' => ['content_hash'],
        'profile' => 'page',
    ]));

    assert(count($result->records) === 1, 'expected one unique record');
    assert(count($result->duplicates) === 1, 'expected one duplicate');
    $record = $result->records[0];
    assert(isset($record['record_id'], $record['fields'], $record['validation'], $record['quality_score'], $record['dedupe_key']), 'normalized pipeline fields missing');
    assert(($record['validation']['status'] ?? null) === 'valid', 'expected valid record');
};

$tests['pipeline validation catches invalid DOI, ISSN, ISBN, URL and email'] = function (): void {
    $crawl = [
        'pages' => [[
            'url' => 'https://example.com/b',
            'final_url' => 'https://example.com/b',
            'status_code' => 200,
            'title' => 'Bad metadata',
            'content_hash' => 'hash-b',
            'extracted' => ['_common_data' => [
                'doi' => ['bad-doi'],
                'issns' => ['1234-5678'],
                'isbns' => ['123'],
                'emails' => ['bad-email'],
                'pdf_links' => ['not-a-url'],
            ]],
        ]],
    ];

    $result = (new ProfessionalCrawlPipeline())->runFromCrawlArray($crawl, PipelineOptions::fromArray([]));
    assert(count($result->validationIssues) >= 1, 'expected validation issues');
};


$tests['source connectors parse sitemap, CSV and JSON URL sources'] = function (): void {
    $dir = sys_get_temp_dir() . '/mnb_sources_' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);

    $sitemapFile = $dir . '/sitemap.xml';
    file_put_contents($sitemapFile, '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://example.com/b</loc></url></urlset>');
    $sitemap = (new SitemapReader())->read($sitemapFile, CrawlOptions::fromArray([]), 10);
    assert(($sitemap['records_returned'] ?? 0) === 2, 'expected sitemap URLs');
    assert(($sitemap['records'][0]['url'] ?? null) === 'https://example.com/a', 'sitemap URL mismatch');

    $csvFile = $dir . '/urls.csv';
    file_put_contents($csvFile, "url,label\nhttps://example.com/c,one\nhttps://example.com/d,two\n");
    $csv = (new CsvUrlSourceReader())->read($csvFile, 'url', 10);
    assert(($csv['records_returned'] ?? 0) === 2, 'expected CSV URLs');
    assert(($csv['records'][1]['metadata']['label'] ?? null) === 'two', 'CSV metadata mismatch');

    $jsonFile = $dir . '/urls.json';
    file_put_contents($jsonFile, json_encode(['items' => [['url' => 'https://example.com/e'], ['url' => 'https://example.com/f']]], JSON_UNESCAPED_SLASHES));
    $json = (new JsonUrlSourceReader())->read($jsonFile, 'items.*.url', 10);
    assert(($json['records_returned'] ?? 0) === 2, 'expected JSON URLs');
    assert(($json['records'][0]['url'] ?? null) === 'https://example.com/e', 'JSON URL mismatch');
};





$tests['local queue creates, moves, retries and counts jobs'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_queue_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $queue = new LocalJobQueue($root);
    $job = $queue->create([
        'job_id' => 'test-job',
        'command' => 'source:csv',
        'args' => ['urls.csv'],
        'options' => ['url-column' => 'url'],
    ]);

    assert(($job['state'] ?? null) === 'pending', 'created job should be pending');
    assert(($queue->counts()['pending'] ?? 0) === 1, 'pending count mismatch');

    $queue->pause('test-job');
    assert(($queue->counts()['paused'] ?? 0) === 1, 'paused count mismatch');

    $queue->resume('test-job');
    $running = $queue->markRunning('test-job', 'worker-test');
    assert(($running['attempts'] ?? 0) === 1, 'attempt count mismatch');

    $queue->markFailed('test-job', 1, 'expected failure');
    assert(($queue->counts()['failed'] ?? 0) === 1, 'failed count mismatch');

    $queue->retry('test-job');
    assert(($queue->counts()['pending'] ?? 0) === 1, 'retry should move job to pending');

    assert($queue->acquireLock('test-job', 'worker-test') === true, 'lock should be acquired');
    assert($queue->acquireLock('test-job', 'worker-two') === false, 'duplicate lock should fail');
    $queue->releaseLock('test-job');
};



$tests['native queue worker runs a CSV source job to completion'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_queue_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $csv = $root . '/urls.csv';
    file_put_contents($csv, "url,label\nhttps://example.com/a,one\n");
    $config = is_file(dirname(__DIR__) . '/config/scraper.php') ? require dirname(__DIR__) . '/config/scraper.php' : [];
    $app = new NativeCliApplication($config, $root);
    ob_start();
    $createCode = $app->run(['mnb-scraper', 'job:create', '--job-id=cli-test', '--source=csv', $csv, '--url-column=url', '--format=json']);
    $workerCode = $app->run(['mnb-scraper', 'worker:once']);
    ob_end_clean();
    assert($createCode === 0, 'job:create failed');
    assert($workerCode === 0, 'worker:once failed');
    $queue = new LocalJobQueue($root);
    assert(($queue->counts()['completed'] ?? 0) === 1, 'worker should complete queued job');
};

$tests['profile schemas validate and load extraction defaults'] = function (): void {
    $root = dirname(__DIR__);
    $loader = new ProfileSchemaLoader($root . '/config/profiles');
    $profiles = $loader->list();
    assert(count($profiles) >= 5, 'expected built-in profile schemas');

    $schema = $loader->load('ecommerce');
    assert($schema->profile === 'ecommerce', 'profile name mismatch');
    assert($schema->recordType === 'product', 'record type mismatch');
    assert(in_array('price', $schema->requiredFields, true), 'required field missing');
    assert(($schema->validators['price'] ?? null) === 'price', 'validator missing');
    assert(isset($schema->extractionRules['title']), 'title rule missing');

    $validation = (new ProfileSchemaValidator())->validateFile($root . '/config/profiles/ecommerce.json');
    assert(($validation['valid'] ?? false) === true, 'ecommerce schema should validate');
};

$tests['rule based extractor supports fallback CSS meta OpenGraph and JSON-LD'] = function (): void {
    if (!class_exists('DOMDocument')) {
        assert(true);
        return;
    }
    $html = '<!doctype html><html><head><title>Fallback Title</title><meta name="description" content="Meta description"><meta property="og:title" content="OG Product"><script type="application/ld+json">{"@type":"Product","name":"JSON Product","offers":{"price":"1299.50"}}</script></head><body><h1> Page H1 </h1><span class="price">₹ 1,299.50</span><a class="apply" href="/apply">Apply</a></body></html>';
    $parser = new HtmlParser();
    $doc = $parser->load($html, 'https://example.com/product');
    $extractor = new RuleBasedExtractor($parser, new UrlNormalizer());
    $data = $extractor->extract($doc, [
        'title' => ['fallback' => [['css' => '.missing'], ['og' => 'title'], 'h1']],
        'description' => ['meta' => 'description'],
        'price' => ['css' => '.price', 'regex' => '([0-9][0-9,]*(?:\\.[0-9]{1,2})?)'],
        'apply_url' => ['css' => 'a.apply', 'attr' => 'href', 'url' => true],
        'json_price' => ['json_ld' => 'offers.price'],
    ], 'https://example.com/product');

    assert(($data['title'] ?? null) === 'OG Product', 'fallback OG extraction failed');
    assert(($data['description'] ?? null) === 'Meta description', 'meta extraction failed');
    assert(($data['price'] ?? null) === '1,299.50', 'regex extraction failed');
    assert(($data['apply_url'] ?? null) === 'https://example.com/apply', 'URL attr normalization failed');
    assert(($data['json_price'] ?? null) === '1299.50', 'JSON-LD path extraction failed');
};

$tests['export and report upgrade creates XML, HTML summary and ZIP bundle'] = function (): void {
    $dir = sys_get_temp_dir() . '/mnb_reports_' . bin2hex(random_bytes(4));
    mkdir($dir . '/pipeline', 0775, true);
    mkdir($dir . '/logs', 0775, true);

    file_put_contents($dir . '/job-manifest.json', json_encode([
        'version' => '4.0.1',
        'job_id' => 'test-job',
        'type' => 'crawl',
        'resume' => ['counts' => ['completed' => 1, 'failed' => 1]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . '/crawl.json', json_encode([
        'pages' => [
            ['url' => 'https://example.com/a', 'final_url' => 'https://example.com/a', 'status_code' => 200, 'title' => 'A'],
            ['url' => 'https://example.com/b', 'final_url' => 'https://example.com/b', 'status_code' => 503, 'failure_type' => 'http_5xx', 'error' => 'Service unavailable'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . '/pipeline/records.json', json_encode([
        'records' => [[
            'record_id' => 'rec_1',
            'record_type' => 'page',
            'record_key' => 'https://example.com/a',
            'quality_score' => 90,
            'validation' => ['status' => 'valid'],
        ]],
        'validation_issues' => [],
        'duplicates' => [],
        'dropped' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . '/logs/crawl.log', "ok\n");

    $recordsXml = $dir . '/records.xml';
    (new RecordExportService())->export([['title' => 'A', 'url' => 'https://example.com/a']], $recordsXml, 'xml');
    assert(is_file($recordsXml) && str_contains((string) file_get_contents($recordsXml), '<records>'), 'records XML export missing');

    $report = (new ReportDataCollector())->collectFromJobDir($dir);
    assert(($report['counts']['pages_total'] ?? null) === 2, 'report page count mismatch');
    assert(($report['failure_type_counts']['http_5xx'] ?? null) === 1, 'failure count mismatch');

    $html = $dir . '/crawl-summary.html';
    (new ReportExporter())->export($report, $html, 'html');
    assert(is_file($html) && str_contains((string) file_get_contents($html), 'MNB ScraperKit Crawl Summary'), 'HTML report missing');

    $zip = $dir . '/bundle.zip';
    $bundle = (new ProjectBundleExporter())->create($dir, $zip);
    assert(is_file($zip) && filesize($zip) > 0, 'ZIP bundle missing');
    assert(($bundle['files_total'] ?? 0) >= 3, 'bundle file count mismatch');
};


$tests['v4.0.1 intelligence features classify quality priority and selector suggestions'] = function (): void {
    $dir = sys_get_temp_dir() . '/mnb_intel_' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    $crawlFile = $dir . '/crawl.json';
    file_put_contents($crawlFile, json_encode([
        'pages' => [[
            'url' => 'https://example.com/product/sku-1',
            'final_url' => 'https://example.com/product/sku-1',
            'status_code' => 200,
            'status' => 'completed',
            'title' => 'Sample Product',
            'text' => 'Buy now for ₹1299 with product details and stock availability.',
            'html' => '<html><head><meta property="og:title" content="Sample Product"><script type="application/ld+json">{"@type":"Product"}</script></head><body><h1 class="product-title">Sample Product</h1><span class="price">₹1299</span><a href="/x">x</a></body></html>',
        ]],
        'records' => [[
            'record_id' => 'rec1',
            'record_type' => 'product',
            'source_url' => 'https://example.com/product/sku-1',
            'dedupe_key' => 'sku-1',
            'fields' => ['title' => 'Sample Product', 'price' => '1299'],
            'validation' => ['warnings' => []],
            'quality_score' => 0.9,
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $analysis = (new FeatureExtractor())->analyzeFile($crawlFile);
    assert(($analysis['summary']['pages_total'] ?? null) === 1, 'intelligence page count mismatch');
    assert(($analysis['page_features'][0]['has_price_hint'] ?? false) === true, 'price hint feature missing');

    $classes = (new PageClassifier())->classifyFeatureSet($analysis['page_features']);
    assert(($classes['rows'][0]['class'] ?? null) === 'ecommerce', 'page should classify as ecommerce');

    $quality = (new QualityPredictor())->predict($analysis);
    assert(($quality['summary']['page_quality_avg'] ?? 0) > 0, 'page quality avg missing');
    assert(($quality['record_quality'][0]['label'] ?? '') !== '', 'record quality label missing');

    $priority = (new UrlPrioritizer())->prioritize(['https://example.com/a.jpg', 'https://example.com/product/sku-1']);
    assert(($priority['urls'][0] ?? '') === 'https://example.com/product/sku-1', 'URL priority should prefer product page over asset');

    $suggestions = (new SelectorSuggester())->suggestFromHtml((string) json_decode((string) file_get_contents($crawlFile), true)['pages'][0]['html'], 'ecommerce');
    assert(isset($suggestions['suggestions']['price']), 'selector suggestions missing price group');
};

$tests['v4.0.1 command registry exposes intelligence commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['intelligence:doctor', 'intelligence:analyze', 'intelligence:classify', 'intelligence:quality', 'intelligence:priority', 'intelligence:selectors'] as $command) {
        assert(isset($commands[$command]), 'missing intelligence command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['input', 'output', 'profile', 'format', 'model', 'features-file'] as $option) {
        assert(in_array($option, $options, true), 'missing intelligence option: ' . $option);
    }
};


$tests['v4.0.1 evaluation command registry exposes benchmarking and training data commands'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['eval:dataset', 'eval:pipeline', 'eval:profile', 'eval:selectors', 'benchmark:profile', 'benchmark:compare', 'annotation:stats', 'annotation:coverage', 'annotation:export'] as $command) {
        assert(isset($commands[$command]), 'missing v4.0.1 evaluation command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['training-ready', 'training-type', 'evaluation-file', 'annotations-file', 'dataset', 'compare-with'] as $option) {
        assert(in_array($option, $options, true), 'missing v4.0.1 evaluation option: ' . $option);
    }
};

$tests['v4.0.1 dataset evaluator reports field matrix annotation coverage and readiness'] = function (): void {
    $records = [[
        'dataset_record_id' => 'r1',
        'record_type' => 'product',
        'source_url' => 'https://example.com/a',
        'dedupe_key' => 'sku-a',
        'quality_score' => 90,
        'validation_status' => 'valid',
        'fields' => ['title' => 'A', 'price' => '10', 'url' => 'https://example.com/a'],
    ], [
        'dataset_record_id' => 'r2',
        'record_type' => 'product',
        'source_url' => 'https://example.com/b',
        'dedupe_key' => 'sku-b',
        'quality_score' => 45,
        'validation_status' => 'warning',
        'fields' => ['title' => 'B', 'price' => '', 'url' => 'https://example.com/b'],
    ]];
    $annotations = ['annotations' => [['record_id' => 'r1', 'label' => 'good', 'field' => null]]];
    $profile = ['profile' => 'ecommerce', 'required_fields' => ['title', 'price', 'url']];
    $report = (new DatasetEvaluator())->evaluate($records, ['dataset_id' => 'eval_test'], $annotations, $profile);
    assert(($report['summary']['records_total'] ?? null) === 2, 'evaluation record count mismatch');
    assert(($report['summary']['training_readiness_score'] ?? 0) > 0, 'training readiness missing');
    assert(($report['annotation_stats']['coverage_percent'] ?? 0) === 50.0, 'annotation coverage mismatch');
    $fields = array_column((array) $report['field_quality_matrix'], 'field');
    assert(in_array('price', $fields, true), 'field quality matrix missing price');
};

$tests['v4.0.1 benchmark selector and annotation quality helpers work'] = function (): void {
    $records = [[
        'dataset_record_id' => 'r1',
        'record_type' => 'article',
        'source_url' => 'https://example.com/paper',
        'quality_score' => 82,
        'validation_status' => 'valid',
        'fields' => ['title' => 'Paper title', 'url' => 'https://example.com/paper', 'doi' => '10.1234/example'],
    ]];
    $profile = [
        'profile' => 'research-paper',
        'record_type' => 'article',
        'required_fields' => ['title', 'url'],
        'optional_fields' => ['doi'],
        'extraction_rules' => ['title' => ['css' => 'h1'], 'doi' => ['regex' => '10\\.']],
    ];
    $benchmark = (new ProfileBenchmark())->benchmark($records, $profile, 'research-paper');
    assert(($benchmark['profile_grade'] ?? '') === 'excellent', 'profile benchmark grade mismatch');
    $selectors = (new SelectorPerformanceEvaluator())->evaluate($records, $profile, 'research-paper');
    assert(($selectors['selectors_total'] ?? 0) >= 2, 'selector performance rows missing');
    $stats = (new AnnotationQuality())->stats($records, ['annotations' => [['record_id' => 'r1', 'label' => 'accepted']]]);
    assert(($stats['coverage_percent'] ?? 0) === 100.0, 'annotation quality coverage mismatch');
};

$tests['native v4.0.1 evaluation commands run on dataset snapshots'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_eval_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $input = $root . '/records.json';
    file_put_contents($input, json_encode(['records' => [[
        'record_id' => 'rec-a',
        'record_type' => 'product',
        'source_url' => 'https://example.com/a',
        'dedupe_key' => 'sku-a',
        'fields' => ['title' => 'A', 'price' => '10', 'url' => 'https://example.com/a'],
        'validation' => ['status' => 'valid'],
        'quality_score' => 91,
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $app = new NativeCliApplication([], $root);
    assert($app->run(['mnb-scraper', 'dataset:create', $input, '--id=eval_cli']) === 0, 'dataset create failed for eval cli');
    $datasetDir = $root . '/storage/datasets/eval_cli';
    assert($app->run(['mnb-scraper', 'annotation:init', $datasetDir]) === 0, 'annotation init failed for eval cli');
    assert($app->run(['mnb-scraper', 'annotation:add', $datasetDir . '/annotations.json', '--record-id=rec-a', '--label=good']) === 0, 'annotation add failed for eval cli');
    assert($app->run(['mnb-scraper', 'eval:dataset', 'eval_cli', '--datasets-dir=' . $root . '/storage/datasets', '--json']) === 0, 'eval dataset command failed');
    assert($app->run(['mnb-scraper', 'benchmark:profile', __DIR__ . '/../config/profiles/ecommerce.json', '--dataset=eval_cli', '--datasets-dir=' . $root . '/storage/datasets', '--json']) === 0, 'benchmark profile command failed');
    assert($app->run(['mnb-scraper', 'annotation:stats', 'eval_cli', '--datasets-dir=' . $root . '/storage/datasets', '--json']) === 0, 'annotation stats command failed');
    assert($app->run(['mnb-scraper', 'dataset:export', 'eval_cli', '--datasets-dir=' . $root . '/storage/datasets', '--format=jsonl', '--training-ready']) === 0, 'training-ready export command failed');
    assert(is_file($datasetDir . '/training-ready.jsonl'), 'training-ready JSONL export missing');
};


$tests['v4.0.1 rule builder analyzes HTML and generates auto profile schema'] = function (): void {
    if (!class_exists('DOMDocument')) {
        assert(true);
        return;
    }
    $html = '<html><head><title>Phone X</title><meta property="og:title" content="Phone X"><script type="application/ld+json">{"@type":"Product","name":"Phone X"}</script></head><body><h1 class="product-title">Phone X</h1><span class="price">₹12,999</span><span class="sku">SKU: PX-1</span><div class="brand">MNB</div></body></html>';
    $signals = (new HtmlSignalAnalyzer())->analyze($html, 'https://example.com/product');
    assert(($signals['assistant'] ?? null) === null, 'raw analyzer should not attach assistant');
    $suggestion = (new AutoProfileAssistant())->suggest($signals);
    assert(($suggestion['suggested_profile'] ?? '') === 'ecommerce', 'auto assistant should suggest ecommerce');
    $schema = (new RuleBuilderService())->generateSchema($html, 'https://example.com/product', 'auto', 'auto-product');
    assert(($schema['profile'] ?? '') === 'auto-product', 'generated profile name mismatch');
    assert(($schema['record_type'] ?? '') === 'product', 'generated record type mismatch');
    assert(isset($schema['extraction_rules']['price']), 'generated price rule missing');
};

$tests['v4.0.1 rule builder tests rules and doctor reports profile quality'] = function (): void {
    if (!class_exists('DOMDocument')) {
        assert(true);
        return;
    }
    $html = '<html><body><h1 class="product-title">Phone X</h1><span class="price">₹12,999</span><span itemprop="sku">PX-1</span></body></html>';
    $service = new RuleBuilderService();
    $schema = $service->generateSchema($html, 'https://example.com/product', 'ecommerce', 'auto-product');
    $test = $service->testRules($html, (array) $schema['extraction_rules'], 'https://example.com/product');
    assert(($test['fields']['title'] ?? '') === 'Phone X', 'rule test should extract title');
    assert(isset($test['fields']['price']), 'rule test should extract price');
    $doctor = $service->doctor(\Mnb\ScraperKit\Profile\ProfileSchema::fromArray($schema), $html, 'https://example.com/product');
    assert(($doctor['valid_schema'] ?? false) === true, 'doctor should accept generated profile schema');
    assert(($doctor['rules_total'] ?? 0) >= 3, 'doctor rule count too low');
};

$tests['native v4.0.1 rule builder commands run on saved HTML'] = function (): void {
    if (!class_exists('DOMDocument')) {
        assert(true);
        return;
    }
    $root = sys_get_temp_dir() . '/mnb_rule_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    mkdir($root . '/config/profiles', 0775, true);
    $htmlFile = $root . '/sample.html';
    file_put_contents($htmlFile, '<html><head><title>Phone X</title></head><body><h1 class="product-title">Phone X</h1><span class="price">₹12,999</span><span itemprop="sku">PX-1</span></body></html>');
    $app = new NativeCliApplication([], $root);
    ob_start();
    $analyze = $app->run(['mnb-scraper', 'rule:analyze', $htmlFile, '--json']);
    $generate = $app->run(['mnb-scraper', 'rule:generate', $htmlFile, '--profile=ecommerce', '--name=cli-product', '--output=config/profiles/cli-product.json']);
    $test = $app->run(['mnb-scraper', 'rule:test', $htmlFile, '--profile-file=' . $root . '/config/profiles/cli-product.json']);
    $doctor = $app->run(['mnb-scraper', 'rule:doctor', $root . '/config/profiles/cli-product.json', '--input=' . $htmlFile, '--json']);
    $scaffold = $app->run(['mnb-scraper', 'profile:scaffold', 'cli-seo', '--profile=seo']);
    ob_end_clean();
    foreach (['analyze' => $analyze, 'generate' => $generate, 'test' => $test, 'doctor' => $doctor, 'scaffold' => $scaffold] as $name => $code) {
        assert($code === 0, 'native rule builder command failed: ' . $name);
    }
    assert(is_file($root . '/config/profiles/cli-product.json'), 'generated CLI profile missing');
    assert(is_file($root . '/config/profiles/cli-seo.json'), 'scaffolded profile missing');
};

$tests['v4.0.1 command registry exposes rule builder commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['rule:analyze', 'rule:generate', 'rule:test', 'rule:doctor', 'profile:scaffold'] as $command) {
        assert(isset($commands[$command]), 'missing rule builder command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['base-url', 'name', 'profile-name', 'profile-file', 'rules-file', 'output'] as $option) {
        assert(in_array($option, $options, true), 'missing rule builder option: ' . $option);
    }
};

$tests['v4.0.1 API exposes rule builder templates route'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_rule_api_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $token = ApiToken::generate('rule');
    $router = new ApiRouter($root, $token);
    $response = $router->handle('GET', '/api/v1/rule-builder/templates', ['Authorization' => 'Bearer ' . $token]);
    assert($response->status === 200, 'rule builder API route status mismatch');
    assert(in_array('ecommerce', (array) ($response->body['templates'] ?? []), true), 'rule builder templates missing ecommerce');
};



$tests['v4.0.1 browser session store creates allowed-domain profiles and blocks outside domains'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_browser_session_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $store = new BrowserSessionStore($root);
    $profile = $store->create('Client Portal', ['example.com'], 'https://example.com/login', ['wait_selector' => '.dashboard']);
    assert($profile->name === 'client-portal', 'session name normalization mismatch');
    assert(is_file($store->profilePath('client-portal')), 'session profile JSON missing');
    assert(is_file((string) $profile->cookieFile), 'session cookie file placeholder missing');
    $store->assertUrlAllowed($profile, 'https://app.example.com/dashboard');
    $blocked = false;
    try {
        $store->assertUrlAllowed($profile, 'https://evil.test/dashboard');
    } catch (RuntimeException) {
        $blocked = true;
    }
    assert($blocked, 'session domain guard should block outside domains');
    $logoutBlocked = false;
    try {
        $store->assertUrlAllowed($profile, 'https://example.com/logout');
    } catch (RuntimeException) {
        $logoutBlocked = true;
    }
    assert($logoutBlocked, 'session guard should block logout URLs');
    $instructions = $store->writeLoginInstructions($profile, 'https://example.com/login');
    assert(is_file($instructions), 'login instructions missing');
    assert(count($store->list()) === 1, 'session list count mismatch');
};

$tests['native v4.0.1 browser session commands create list show clear and login assist'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_browser_session_cli_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $app = new NativeCliApplication([], $root);
    ob_start();
    $create = $app->run(['mnb-scraper', 'browser:session-create', 'client_portal', '--domain=example.com', '--login-url=https://example.com/login']);
    $list = $app->run(['mnb-scraper', 'browser:session-list', '--json']);
    $show = $app->run(['mnb-scraper', 'browser:session-show', 'client_portal']);
    $login = $app->run(['mnb-scraper', 'browser:login', 'client_portal', '--url=https://example.com/login']);
    $test = $app->run(['mnb-scraper', 'browser:session-test', 'client_portal', 'https://example.com/dashboard', '--json']);
    $clear = $app->run(['mnb-scraper', 'browser:session-clear', 'client_portal']);
    ob_end_clean();
    foreach (['create' => $create, 'list' => $list, 'show' => $show, 'login' => $login, 'test' => $test, 'clear' => $clear] as $name => $code) {
        assert($code === 0, 'native browser session command failed: ' . $name);
    }
    assert(is_file($root . '/config/browser-profiles/client_portal.json') || is_file($root . '/config/browser-profiles/client-portal.json'), 'session profile should still exist after clear without --remove-profile');
};

$tests['v4.0.1 command registry exposes browser session commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['browser:session-create', 'browser:session-list', 'browser:session-show', 'browser:session-clear', 'browser:session-test', 'browser:login'] as $command) {
        assert(isset($commands[$command]), 'missing browser session command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['session', 'browser-session', 'domain', 'domains', 'login-url', 'remove-profile', 'no-headless', 'allow-assets'] as $option) {
        assert(in_array($option, $options, true), 'missing browser session option: ' . $option);
    }
    foreach (array_count_values($options) as $option => $count) {
        assert($count === 1, 'duplicate Symfony option registered: ' . $option);
    }
};

$tests['v4.0.1 API exposes browser session routes'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_browser_session_api_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    (new BrowserSessionStore($root))->create('client', ['example.com'], 'https://example.com/login');
    $token = ApiToken::generate('browser');
    $router = new ApiRouter($root, $token);
    $list = $router->handle('GET', '/api/v1/browser/sessions', ['Authorization' => 'Bearer ' . $token]);
    assert($list->status === 200 && count((array) ($list->body['sessions'] ?? [])) === 1, 'browser session API list failed');
    $show = $router->handle('GET', '/api/v1/browser/sessions/client', ['Authorization' => 'Bearer ' . $token]);
    assert($show->status === 200 && ($show->body['session']['name'] ?? null) === 'client', 'browser session API show failed');
};


$tests['v4.0.1 export connector registry exposes commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['export:connector-list', 'export:connector-show', 'export:connector-validate', 'export:connector-test', 'export:deliver', 'export:manifest'] as $command) {
        assert(isset($commands[$command]), 'missing v4.0.1 export connector command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['connector', 'config', 'file', 'dir', 'input', 'extension', 'allowed-extension', 'send'] as $option) {
        assert(in_array($option, $options, true), 'missing v4.0.1 export connector option: ' . $option);
    }
    foreach (array_count_values($options) as $option => $count) {
        assert($count === 1, 'duplicate Symfony option registered: ' . $option);
    }
};

$tests['export connector store validates and local delivery copies artifacts'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_export_connector_' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0775, true);
    mkdir($root . '/out', 0775, true);
    $config = [
        'connectors' => [[
            'id' => 'local_test',
            'type' => 'local',
            'enabled' => true,
            'target_dir' => 'out/deliveries',
            'allowed_extensions' => ['json', 'csv'],
        ]],
    ];
    file_put_contents($root . '/config/export-connectors.json', json_encode($config, JSON_PRETTY_PRINT));
    $artifact = $root . '/records.json';
    file_put_contents($artifact, json_encode([['title' => 'A']], JSON_PRETTY_PRINT));
    $store = new ExportConnectorStore($root);
    $validation = $store->validate();
    assert(($validation['ok'] ?? false) === true, 'export connector validation failed');
    $connector = $store->show('local_test');
    $manifest = (new ExportManifestBuilder())->build([$artifact], ['json']);
    assert(($manifest['files_total'] ?? 0) === 1, 'export manifest file count mismatch');
    assert(strlen((string) ($manifest['files'][0]['sha256'] ?? '')) === 64, 'export manifest sha256 missing');
    $result = (new ExportDeliveryService($root))->deliver($connector, [$artifact]);
    assert(($result['files_copied'] ?? 0) === 1, 'local export delivery copy count mismatch');
    assert(is_file((string) ($result['target_dir'] ?? '') . '/delivery-manifest.json'), 'delivery manifest missing');
};

$tests['native v4.0.1 export connector commands run'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_export_connector_cli_' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0775, true);
    $config = [
        'connectors' => [[
            'id' => 'local_cli',
            'type' => 'local',
            'enabled' => true,
            'target_dir' => 'storage/export-deliveries/local_cli',
            'allowed_extensions' => ['json'],
        ]],
    ];
    file_put_contents($root . '/config/export-connectors.json', json_encode($config, JSON_PRETTY_PRINT));
    $artifact = $root . '/records.json';
    file_put_contents($artifact, json_encode(['records' => [['title' => 'CLI']]], JSON_PRETTY_PRINT));
    $app = new NativeCliApplication([], $root);
    ob_start();
    $list = $app->run(['mnb-scraper', 'export:connector-list', '--json']);
    $show = $app->run(['mnb-scraper', 'export:connector-show', 'local_cli']);
    $validate = $app->run(['mnb-scraper', 'export:connector-validate']);
    $manifest = $app->run(['mnb-scraper', 'export:manifest', $artifact, '--json']);
    $deliver = $app->run(['mnb-scraper', 'export:deliver', 'local_cli', '--file=' . $artifact, '--json']);
    ob_end_clean();
    foreach (['list' => $list, 'show' => $show, 'validate' => $validate, 'manifest' => $manifest, 'deliver' => $deliver] as $name => $code) {
        assert($code === 0, 'native export connector command failed: ' . $name);
    }
};

$tests['v4.0.1 API exposes export connector routes'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_export_connector_api_' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0775, true);
    file_put_contents($root . '/config/export-connectors.json', json_encode(['connectors' => [[
        'id' => 'local_api',
        'type' => 'local',
        'enabled' => true,
        'target_dir' => 'storage/export-deliveries/local_api',
    ]]], JSON_PRETTY_PRINT));
    $token = ApiToken::generate('export');
    $router = new ApiRouter($root, $token);
    $list = $router->handle('GET', '/api/v1/export-connectors', ['Authorization' => 'Bearer ' . $token]);
    assert($list->status === 200 && count((array) ($list->body['connectors'] ?? [])) === 1, 'export connector API list failed');
    $show = $router->handle('GET', '/api/v1/export-connectors/local_api', ['Authorization' => 'Bearer ' . $token]);
    assert($show->status === 200 && ($show->body['connector']['id'] ?? null) === 'local_api', 'export connector API show failed');
};


$tests['v4.0.1 template catalog lists validates creates and installs presets'] = function (): void {
    $root = dirname(__DIR__);
    $catalog = new TemplateCatalog($root);
    $templates = $catalog->templates();
    assert(count($templates) >= 4, 'expected bundled project templates');
    $validation = $catalog->validateTemplate('seo-audit');
    assert(($validation['ok'] ?? false) === true, 'seo-audit template validation failed');
    $packValidation = $catalog->validatePresetPack('commerce-gov-pack');
    assert(($packValidation['ok'] ?? false) === true, 'commerce-gov-pack validation failed');

    $tmp = sys_get_temp_dir() . '/mnb_template_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $installer = new TemplateInstaller($root);
    $created = $installer->createProject($catalog->template('seo-audit'), $tmp . '/seo-project', ['project_name' => 'seo-project'], true);
    assert(is_file($created['project_dir'] . '/mnb-project.json'), 'project manifest missing');
    assert(is_file($created['project_dir'] . '/jobs/seo-audit-job.json'), 'job template missing');
    $installed = $installer->installPresetPack($catalog->presetPack('commerce-gov-pack'), $tmp . '/preset', true);
    assert(is_file($installed['output_dir'] . '/mnb-preset-pack.json'), 'preset manifest missing');
    assert(is_file($installed['output_dir'] . '/profiles/ecommerce.json'), 'preset profile copy missing');
};

$tests['native v4.0.1 template and preset commands run'] = function (): void {
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/mnb_template_cli_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $app = new NativeCliApplication([], $root);
    ob_start();
    $templateList = $app->run(['mnb-scraper', 'template:list', '--json']);
    $templateShow = $app->run(['mnb-scraper', 'template:show', 'seo-audit']);
    $templateValidate = $app->run(['mnb-scraper', 'template:validate', 'seo-audit']);
    $templateCreate = $app->run(['mnb-scraper', 'template:create', 'seo-audit', '--output-dir=' . $tmp . '/seo', '--name=seo', '--force', '--json']);
    $presetList = $app->run(['mnb-scraper', 'preset:list', '--json']);
    $presetShow = $app->run(['mnb-scraper', 'preset:show', 'commerce-gov-pack']);
    $presetValidate = $app->run(['mnb-scraper', 'preset:validate', 'commerce-gov-pack']);
    $presetInstall = $app->run(['mnb-scraper', 'preset:install', 'commerce-gov-pack', '--output-dir=' . $tmp . '/pack', '--force', '--json']);
    ob_end_clean();
    foreach (['templateList' => $templateList, 'templateShow' => $templateShow, 'templateValidate' => $templateValidate, 'templateCreate' => $templateCreate, 'presetList' => $presetList, 'presetShow' => $presetShow, 'presetValidate' => $presetValidate, 'presetInstall' => $presetInstall] as $name => $code) {
        assert($code === 0, 'native template/preset command failed: ' . $name);
    }
    assert(is_file($tmp . '/seo/mnb-project.json'), 'native template project missing');
    assert(is_file($tmp . '/pack/mnb-preset-pack.json'), 'native preset pack missing');
};

$tests['v4.0.1 API exposes project template and preset pack routes'] = function (): void {
    $root = dirname(__DIR__);
    $token = ApiToken::generate('templates');
    $router = new ApiRouter($root, $token);
    $list = $router->handle('GET', '/api/v1/project-templates', ['Authorization' => 'Bearer ' . $token]);
    assert($list->status === 200 && count((array) ($list->body['templates'] ?? [])) >= 4, 'template API list failed');
    $show = $router->handle('GET', '/api/v1/project-templates/seo-audit', ['Authorization' => 'Bearer ' . $token]);
    assert($show->status === 200 && ($show->body['template']['id'] ?? null) === 'seo-audit', 'template API show failed');
    $packs = $router->handle('GET', '/api/v1/preset-packs', ['Authorization' => 'Bearer ' . $token]);
    assert($packs->status === 200 && count((array) ($packs->body['preset_packs'] ?? [])) >= 2, 'preset API list failed');
};


$tests['v4.0.1 security audit and compliance report pass on clean package'] = function (): void {
    $root = dirname(__DIR__);
    $audit = (new SecurityAuditScanner($root))->audit();
    assert(isset($audit['summary']) && ($audit['summary']['high'] ?? 0) === 0, 'expected no high findings on clean package');
    assert(($audit['score'] ?? 0) >= 90, 'security score should be good for clean package');
    $report = (new ComplianceReportBuilder($root))->build();
    assert(isset($report['attestations']['secrets_scan_completed']), 'compliance attestations missing');
    $html = (new ComplianceReportBuilder($root))->renderHtml($report);
    assert(str_contains($html, 'MNB ScraperKit Compliance Report'), 'compliance HTML missing title');
};

$tests['v4.0.1 secrets scanner detects obvious local secret patterns'] = function (): void {
    $root = sys_get_temp_dir() . '/mnb_secret_scan_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    file_put_contents($root . '/sample.txt', 'api_' . "key='" . 'abcdefghijklmnopqrstuvwxyz123456' . "'\n");
    $scan = (new SecretScanner())->scan($root);
    assert(($scan['findings_total'] ?? 0) >= 1, 'secret scanner did not detect sample secret');
};

$tests['native v4.0.1 security and compliance commands run'] = function (): void {
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/mnb_security_cli_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $app = new NativeCliApplication([], $root);
    ob_start();
    $doctor = $app->run(['mnb-scraper', 'security:doctor', '--json']);
    $audit = $app->run(['mnb-scraper', 'security:audit', '--output=' . $tmp . '/audit.json']);
    $policy = $app->run(['mnb-scraper', 'security:policy', '--output=' . $tmp . '/policy.json']);
    $compliance = $app->run(['mnb-scraper', 'compliance:report', '--format=html', '--output=' . $tmp . '/compliance.html']);
    $secrets = $app->run(['mnb-scraper', 'security:secrets-scan', $root, '--output=' . $tmp . '/secrets.json']);
    ob_end_clean();
    foreach (['doctor' => $doctor, 'audit' => $audit, 'policy' => $policy, 'compliance' => $compliance, 'secrets' => $secrets] as $name => $code) {
        assert($code === 0, 'native security command failed: ' . $name);
    }
    assert(is_file($tmp . '/audit.json'), 'audit output missing');
    assert(is_file($tmp . '/policy.json'), 'policy output missing');
    assert(is_file($tmp . '/compliance.html'), 'compliance HTML missing');
};

$tests['v4.0.1 API exposes security and compliance routes'] = function (): void {
    $root = dirname(__DIR__);
    $token = ApiToken::generate('security');
    $router = new ApiRouter($root, $token);
    $audit = $router->handle('GET', '/api/v1/security/audit', ['Authorization' => 'Bearer ' . $token]);
    assert($audit->status === 200 && isset($audit->body['security']['score']), 'security audit API route failed');
    $report = $router->handle('GET', '/api/v1/compliance/report', ['Authorization' => 'Bearer ' . $token]);
    assert($report->status === 200 && isset($report->body['compliance']['attestations']), 'compliance report API route failed');
};


$tests['v4.0.1 production readiness inspector passes clean package and detects command contract'] = function (): void {
    $root = dirname(__DIR__);
    $report = (new ProductionReadinessInspector())->inspect($root);
    assert(($report['status'] ?? null) === 'pass', 'production readiness should pass on clean package');
    assert(($report['score'] ?? 0) >= 90, 'production readiness score should be high');
    assert(($report['command_contract']['ok'] ?? false) === true, 'command contract should validate');
    assert(isset($report['runtime_matrix']['browser']), 'runtime matrix missing browser status');
};

$tests['v4.0.1 benchmark runner returns deterministic local benchmark rows'] = function (): void {
    $report = (new BenchmarkRunner())->run(25);
    assert(($report['benchmark_version'] ?? null) === '4.0.1', 'benchmark version mismatch');
    assert(count((array) ($report['benchmarks'] ?? [])) >= 4, 'benchmark rows missing');
    foreach ((array) ($report['benchmarks'] ?? []) as $row) {
        assert(($row['iterations'] ?? 0) >= 2, 'benchmark iteration count missing');
        assert(isset($row['elapsed_ms']), 'benchmark elapsed time missing');
    }
};

$tests['v4.0.1 public command contract has no duplicate options and includes hardening commands'] = function (): void {
    $contract = new PublicCommandContract();
    $validation = $contract->validate();
    assert(($validation['ok'] ?? false) === true, 'public command contract validation failed');
    $snapshot = $contract->snapshot();
    foreach (['hardening:doctor', 'ci:check', 'benchmark:run', 'compat:commands'] as $command) {
        assert(isset($snapshot['commands'][$command]), 'missing hardening command in public contract: ' . $command);
    }
};

$tests['native v4.0.1 hardening benchmark and compatibility commands run'] = function (): void {
    $root = dirname(__DIR__);
    $app = new NativeCliApplication([], $root);
    ob_start();
    $doctor = $app->run(['mnb-scraper', 'hardening:doctor', '--json']);
    $ci = $app->run(['mnb-scraper', 'ci:check', '--strict']);
    $bench = $app->run(['mnb-scraper', 'benchmark:run', '--iterations=25', '--json']);
    $compat = $app->run(['mnb-scraper', 'compat:commands', '--validate', '--json']);
    $unknown = $app->run(['mnb-scraper', 'dashbord:status']);
    ob_end_clean();
    foreach (['doctor' => $doctor, 'ci' => $ci, 'bench' => $bench, 'compat' => $compat] as $name => $code) {
        assert($code === 0, 'native hardening command failed: ' . $name);
    }
    assert($unknown === 1, 'unknown command should fail with suggestions');
};

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
        $passed++;
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL: {$name}\n  " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Tests passed: {$passed}/" . count($tests) . "\n");
