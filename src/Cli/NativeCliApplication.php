<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Cli;

use Mnb\ScraperKit\Browser\BrowserCrawlService;
use Mnb\ScraperKit\Browser\BrowserFallbackDetector;
use Mnb\ScraperKit\Browser\BrowserOptions;
use Mnb\ScraperKit\Browser\BrowserPageResult;
use Mnb\ScraperKit\Browser\BrowserSessionStore;
use Mnb\ScraperKit\Api\ApiRouter;
use Mnb\ScraperKit\Api\ApiToken;
use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Database\DatabaseConfig;
use Mnb\ScraperKit\Database\DatabaseConnectionFactory;
use Mnb\ScraperKit\Database\DatabaseMigrator;
use Mnb\ScraperKit\Database\DatabaseRepository;
use Mnb\ScraperKit\Distributed\DistributedQueueConfig;
use Mnb\ScraperKit\Distributed\DistributedQueueManager;
use Mnb\ScraperKit\Distributed\DistributedJob;
use Mnb\ScraperKit\Database\DatabaseSchema;
use Mnb\ScraperKit\Dashboard\DashboardDataCollector;
use Mnb\ScraperKit\Dashboard\DashboardRenderer;
use Mnb\ScraperKit\Dataset\AnnotationStore;
use Mnb\ScraperKit\Dataset\DatasetComparator;
use Mnb\ScraperKit\Dataset\DatasetExporter;
use Mnb\ScraperKit\Dataset\DatasetStore;
use Mnb\ScraperKit\Evaluation\AnnotationQuality;
use Mnb\ScraperKit\Evaluation\DatasetEvaluator;
use Mnb\ScraperKit\Evaluation\EvaluationExporter;
use Mnb\ScraperKit\Evaluation\ProfileBenchmark;
use Mnb\ScraperKit\Evaluation\SelectorPerformanceEvaluator;
use Mnb\ScraperKit\Intelligence\FeatureExtractor;
use Mnb\ScraperKit\Intelligence\IntelligenceDoctor;
use Mnb\ScraperKit\Intelligence\PageClassifier;
use Mnb\ScraperKit\Intelligence\QualityPredictor;
use Mnb\ScraperKit\Intelligence\SelectorSuggester;
use Mnb\ScraperKit\Intelligence\UrlPrioritizer;
use Mnb\ScraperKit\Core\CrawlResult;
use Mnb\ScraperKit\Core\HttpClient;
use Mnb\ScraperKit\Core\RobotsPolicy;
use Mnb\ScraperKit\Core\Scraper;
use Mnb\ScraperKit\Core\PageResult;
use Mnb\ScraperKit\Core\ProtectionPageDetector;
use Mnb\ScraperKit\Discovery\FallbackSourceDiscovery;
use Mnb\ScraperKit\Encoding\EncodingConverter;
use Mnb\ScraperKit\Encoding\EncodingDetector;
use Mnb\ScraperKit\Extractor\CommonDataExtractor;
use Mnb\ScraperKit\Extractor\RuleBasedExtractor;
use Mnb\ScraperKit\Network\ExitPointManager;
use Mnb\ScraperKit\Processing\SequentialUrlProcessor;
use Mnb\ScraperKit\Processing\UrlProcessOptions;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Parser\PresetExtractor;
use Mnb\ScraperKit\Pipeline\CrawlJsonReader;
use Mnb\ScraperKit\Pipeline\FailedUrlExtractor;
use Mnb\ScraperKit\Pipeline\JobManifest;
use Mnb\ScraperKit\Pipeline\PipelineExporter;
use Mnb\ScraperKit\Pipeline\PipelineOptions;
use Mnb\ScraperKit\Pipeline\ProfessionalCrawlPipeline;
use Mnb\ScraperKit\Profile\ProfileSchemaLoader;
use Mnb\ScraperKit\Profile\ProfileSchemaValidator;
use Mnb\ScraperKit\RuleBuilder\RuleBuilderService;
use Mnb\ScraperKit\Plugin\PluginManager;
use Mnb\ScraperKit\Plugin\PluginManifest;
use Mnb\ScraperKit\Plugin\PluginValidator;
use Mnb\ScraperKit\Queue\LocalJobQueue;
use Mnb\ScraperKit\Retry\RetryPolicy;
use Mnb\ScraperKit\Scheduler\LocalScheduleStore;
use Mnb\ScraperKit\Monitoring\MonitoringSnapshot;
use Mnb\ScraperKit\Report\ProjectBundleExporter;
use Mnb\ScraperKit\Report\RecordExportService;
use Mnb\ScraperKit\Report\ReportDataCollector;
use Mnb\ScraperKit\Report\ReportExporter;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;
use Mnb\ScraperKit\Storage\CsvExporter;
use Mnb\ScraperKit\Storage\JsonExporter;
use Mnb\ScraperKit\Webhook\WebhookDispatcher;
use Mnb\ScraperKit\Webhook\WebhookEndpointStore;
use Mnb\ScraperKit\Source\Plos\PlosApiClient;
use Mnb\ScraperKit\Source\Plos\PlosJournalCatalog;
use Mnb\ScraperKit\Source\Elsevier\ElsevierApiClient;
use Mnb\ScraperKit\Source\Elsevier\ElsevierJournalCatalog;
use Mnb\ScraperKit\Source\Rss\RssFeedReader;
use Mnb\ScraperKit\Source\Sitemap\SitemapReader;
use Mnb\ScraperKit\Source\Csv\CsvUrlSourceReader;
use Mnb\ScraperKit\Source\Json\JsonUrlSourceReader;
use Mnb\ScraperKit\Source\Api\ApiSourceReader;
use Mnb\ScraperKit\Support\Logger;
use Mnb\ScraperKit\Support\UrlNormalizer;

final class NativeCliApplication
{
    /** @var array<string,mixed> */
    private array $config;
    private string $rootDir;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, string $rootDir)
    {
        $this->config = $config;
        $this->rootDir = rtrim($rootDir, '/\\');
    }

    /** @param array<int,string> $argv */
    public function run(array $argv): int
    {
        $parsed = $this->parse($argv);
        $command = $parsed['command'];
        $args = $parsed['args'];
        $opts = $parsed['options'];

        try {
            return match ($command) {
                '', 'help', '--help', '-h' => $this->help(),
                'list' => $this->listCommands(),
                'crawl' => $this->crawl($args, $opts),
                'browser:test' => $this->browserTest($args, $opts),
                'browser:session-create' => $this->browserSessionCreate($args, $opts),
                'browser:session-list' => $this->browserSessionList($args, $opts),
                'browser:session-show' => $this->browserSessionShow($args, $opts),
                'browser:session-clear' => $this->browserSessionClear($args, $opts),
                'browser:session-test' => $this->browserSessionTest($args, $opts),
                'browser:login' => $this->browserLogin($args, $opts),
                'distributed:doctor' => $this->distributedDoctor($args, $opts),
                'distributed:status' => $this->distributedStatus($args, $opts),
                'distributed:enqueue' => $this->distributedEnqueue($args, $opts),
                'distributed:reserve' => $this->distributedReserve($args, $opts),
                'distributed:ack' => $this->distributedAck($args, $opts),
                'distributed:fail' => $this->distributedFail($args, $opts),
                'distributed:heartbeat' => $this->distributedHeartbeat($args, $opts),
                'distributed:purge' => $this->distributedPurge($args, $opts),
                'worker:distributed' => $this->workerDistributed($args, $opts),
                'db:init' => $this->dbInit($args, $opts),
                'db:test' => $this->dbTest($args, $opts),
                'db:status' => $this->dbStatus($args, $opts),
                'db:save-crawl' => $this->dbSaveCrawl($args, $opts),
                'db:save-pipeline' => $this->dbSavePipeline($args, $opts),
                'db:export' => $this->dbExport($args, $opts),
                'retry:plan' => $this->retryPlan($args, $opts),
                'schedule:create' => $this->scheduleCreate($args, $opts),
                'schedule:list' => $this->scheduleList($args, $opts),
                'schedule:show' => $this->scheduleShow($args, $opts),
                'schedule:run-due' => $this->scheduleRunDue($args, $opts),
                'schedule:enable' => $this->scheduleEnable($args, $opts),
                'schedule:disable' => $this->scheduleDisable($args, $opts),
                'monitor:summary' => $this->monitorSummary($args, $opts),
                'monitor:stale-locks' => $this->monitorStaleLocks($args, $opts),
                'plugin:list' => $this->pluginList($args, $opts),
                'plugin:show' => $this->pluginShow($args, $opts),
                'plugin:validate' => $this->pluginValidate($args, $opts),
                'plugin:install' => $this->pluginInstall($args, $opts),
                'plugin:enable' => $this->pluginEnable($args, $opts),
                'plugin:disable' => $this->pluginDisable($args, $opts),
                'plugin:doctor' => $this->pluginDoctor($args, $opts),
                'api:routes' => $this->apiRoutes($args, $opts),
                'api:token' => $this->apiToken($args, $opts),
                'api:serve' => $this->apiServe($args, $opts),
                'dashboard:status' => $this->dashboardStatus($args, $opts),
                'dashboard:build' => $this->dashboardBuild($args, $opts),
                'dashboard:serve' => $this->dashboardServe($args, $opts),
                'dataset:create' => $this->datasetCreate($args, $opts),
                'dataset:list' => $this->datasetList($args, $opts),
                'dataset:show' => $this->datasetShow($args, $opts),
                'dataset:diff' => $this->datasetDiff($args, $opts),
                'dataset:export' => $this->datasetExport($args, $opts),
                'annotation:init' => $this->annotationInit($args, $opts),
                'annotation:add' => $this->annotationAdd($args, $opts),
                'eval:dataset' => $this->evalDataset($args, $opts),
                'eval:pipeline' => $this->evalPipeline($args, $opts),
                'eval:profile' => $this->evalProfile($args, $opts),
                'eval:selectors' => $this->evalSelectors($args, $opts),
                'benchmark:profile' => $this->benchmarkProfile($args, $opts),
                'benchmark:compare' => $this->benchmarkCompare($args, $opts),
                'annotation:stats' => $this->annotationStats($args, $opts),
                'annotation:coverage' => $this->annotationCoverage($args, $opts),
                'annotation:export' => $this->annotationExport($args, $opts),
                'intelligence:doctor' => $this->intelligenceDoctor($args, $opts),
                'intelligence:analyze' => $this->intelligenceAnalyze($args, $opts),
                'intelligence:classify' => $this->intelligenceClassify($args, $opts),
                'intelligence:quality' => $this->intelligenceQuality($args, $opts),
                'intelligence:priority' => $this->intelligencePriority($args, $opts),
                'intelligence:selectors' => $this->intelligenceSelectors($args, $opts),
                'webhook:list' => $this->webhookList($args, $opts),
                'webhook:test' => $this->webhookTest($args, $opts),
                'webhook:send' => $this->webhookSend($args, $opts),
                'http:test' => $this->httpTest($args, $opts),
                'bulk:crawl' => $this->bulkCrawl($args, $opts),
                'url:process', 'urls:process' => $this->urlProcess($args, $opts),
                'robots:test' => $this->robotsTest($args, $opts),
                'encoding:test' => $this->encodingTest($args, $opts),
                'common:extract' => $this->commonExtract($args, $opts),
                'common:types' => $this->commonTypes($opts),
                'rule:analyze' => $this->ruleAnalyze($args, $opts),
                'rule:generate' => $this->ruleGenerate($args, $opts),
                'rule:test' => $this->ruleTest($args, $opts),
                'rule:doctor' => $this->ruleDoctor($args, $opts),
                'profile:scaffold' => $this->profileScaffold($args, $opts),
                'profile:list' => $this->profileList($opts),
                'profile:show' => $this->profileShow($args, $opts),
                'profile:validate' => $this->profileValidate($args, $opts),
                'extract:rules' => $this->extractRules($args, $opts),
                'job:create' => $this->jobCreate($args, $opts),
                'job:list' => $this->jobList($args, $opts),
                'job:show' => $this->jobShow($args, $opts),
                'job:pause' => $this->jobPause($args, $opts),
                'job:resume' => $this->jobResume($args, $opts),
                'job:cancel' => $this->jobCancel($args, $opts),
                'worker:once' => $this->workerOnce($args, $opts),
                'worker:run' => $this->workerRun($args, $opts),
                'worker:status' => $this->workerStatus($args, $opts),
                'queue:failed' => $this->queueFailed($args, $opts),
                'queue:retry' => $this->queueRetry($args, $opts),
                'queue:retry-safe' => $this->queueRetrySafe($args, $opts),
                'queue:retry-all' => $this->queueRetryAll($args, $opts),
                'queue:clear-failed' => $this->queueClearFailed($args, $opts),
                'report:failed' => $this->reportFailed($args, $opts),
                'export:failed' => $this->reportFailed($args, $opts),
                'report:summary' => $this->reportSummary($args, $opts),
                'bundle:create' => $this->bundleCreate($args, $opts),
                'pipeline:run' => $this->pipelineRun($args, $opts),
                'retry:failed' => $this->retryFailed($args, $opts),
                'export:records' => $this->exportRecords($args, $opts),
                'export:validation' => $this->exportValidation($args, $opts),
                'validate:records' => $this->validateRecords($args, $opts),
                'job:summary' => $this->jobSummary($args, $opts),
                'job:run' => $this->jobRun($args, $opts),
                'source:discover' => $this->sourceDiscover($args, $opts),
                'source:sitemap' => $this->sourceSitemap($args, $opts),
                'source:rss' => $this->sourceRss($args, $opts),
                'source:csv' => $this->sourceCsv($args, $opts),
                'source:json' => $this->sourceJson($args, $opts),
                'source:api' => $this->sourceApi($args, $opts),
                'source:urls' => $this->sourceUrls($args, $opts),
                'plos:journals' => $this->plosJournals($opts),
                'plos:search' => $this->plosSearch($args, $opts),
                'plos:feed' => $this->plosFeed($args, $opts),
                'plos:urls' => $this->plosUrls($args, $opts),
                'elsevier:journals' => $this->elsevierJournals($opts),
                'elsevier:search' => $this->elsevierSearch($args, $opts),
                'elsevier:metadata' => $this->elsevierMetadata($args, $opts),
                'elsevier:doi' => $this->elsevierDoi($args, $opts),
                'elsevier:serial' => $this->elsevierSerial($args, $opts),
                'elsevier:urls' => $this->elsevierUrls($args, $opts),
                default => $this->unknown($command),
            };
        } catch (\Throwable $e) {
            $this->err('ERROR: ' . $e->getMessage());
            return 1;
        }
    }

    private function help(): int
    {
        $this->out('MNB ScraperKit 3.5.0 - Professional Symfony Console CLI');
        $this->out('Symfony Console front-end with framework-independent native PHP crawler and pipeline core.');
        $this->out('');
        return $this->listCommands();
    }

    private function listCommands(): int
    {
        $commands = [
            'crawl <url>' => 'Crawl one URL/site with rules, presets, common data, optional browser fallback, and optional pipeline.',
            'browser:test <url>' => 'Diagnose browser fallback need and optionally render one URL with the optional browser adapter.',
            'browser:session-create <name>' => 'Create an allowed-domain browser session profile for authorized login workflows.',
            'browser:session-list' => 'List browser session profiles and cookie/session files.',
            'browser:session-show <name>' => 'Show one browser session profile.',
            'browser:session-clear <name>' => 'Clear session cookies/artifacts, optionally removing the profile.',
            'browser:session-test <name> <url>' => 'Test an authorized browser session against an allowed URL.',
            'browser:login <name>' => 'Create/update login instructions and optionally run a non-headless browser login assist.',
            'distributed:doctor' => 'Inspect distributed queue capability, Redis availability, selected adapter, and worker settings.',
            'distributed:status' => 'Show distributed queue counts, leases, completed jobs, failed jobs, namespace, and worker group.',
            'distributed:enqueue' => 'Enqueue one command payload into the distributed queue.',
            'distributed:reserve' => 'Reserve one distributed job for debugging and worker integration tests.',
            'distributed:ack <job-id>' => 'Acknowledge a reserved distributed job as completed.',
            'distributed:fail <job-id>' => 'Mark a reserved distributed job as failed.',
            'distributed:heartbeat <job-id>' => 'Refresh a distributed job lease heartbeat.',
            'distributed:purge' => 'Purge distributed queue state, usually with --force in test/dev environments.',
            'worker:distributed' => 'Run a distributed worker loop using Redis when available or the file adapter fallback.',
            'db:init' => 'Initialize SQLite/MySQL storage tables for jobs, pages, records, failures, validation issues, and exports.',
            'db:test' => 'Test database connection settings and show the detected PDO driver.',
            'db:status' => 'Show database table row counts.',
            'db:save-crawl <crawl.json>' => 'Save crawl JSON pages to the database storage layer.',
            'db:save-pipeline <records.json>' => 'Save pipeline records and validation issues to database storage.',
            'db:export <table>' => 'Export supported database storage table rows as JSON or CSV.',
            'retry:plan <crawl.json>' => 'Create a safe retry plan with backoff decisions for crawl failures or failed jobs.',
            'schedule:create' => 'Create a local schedule that enqueues a ScraperKit job when due.',
            'schedule:list' => 'List local schedules and due status.',
            'schedule:show <schedule-id>' => 'Show one local schedule JSON file.',
            'schedule:run-due' => 'Enqueue all due schedules into the local job queue.',
            'schedule:enable <schedule-id>' => 'Enable a local schedule.',
            'schedule:disable <schedule-id>' => 'Disable a local schedule.',
            'monitor:summary' => 'Show queue, schedule, lock, and health monitoring summary.',
            'monitor:stale-locks' => 'Report stale worker locks that need attention.',
            'plugin:list' => 'List installed and bundled config-only plugins.',
            'plugin:show <plugin-id>' => 'Show one plugin manifest with resolved assets.',
            'plugin:validate <plugin-dir|mnb-plugin.json>' => 'Validate a plugin manifest and referenced assets.',
            'plugin:install <plugin-dir|mnb-plugin.json>' => 'Install a plugin into storage/plugins.',
            'plugin:enable <plugin-id>' => 'Enable an installed plugin manifest.',
            'plugin:disable <plugin-id>' => 'Disable an installed plugin manifest.',
            'plugin:doctor' => 'Validate all discovered plugins and report issues.',
            'api:routes' => 'List lightweight JSON API routes for local dashboards and automation.',
            'api:token' => 'Generate a local API Bearer token for api:serve.',
            'api:serve' => 'Serve the optional lightweight JSON API using PHP built-in server.',
            'dashboard:status' => 'Show dashboard health, URLs, and read-only data availability.',
            'dashboard:build' => 'Build a static HTML dashboard snapshot from local queue/schedule/plugin/profile data.',
            'dashboard:serve' => 'Serve the optional local HTML admin dashboard using PHP built-in server.',
            'dataset:create <input.json|urls.txt>' => 'Create a versioned dataset snapshot from crawl, pipeline, source, intelligence, or URL-list data.',
            'dataset:list' => 'List local dataset snapshots from storage/datasets.',
            'dataset:show <dataset-id|manifest.json>' => 'Show one dataset manifest and quality summary.',
            'dataset:diff <old> <new>' => 'Compare two dataset snapshots and show added, removed, and changed records.',
            'dataset:export <dataset-id|manifest.json>' => 'Export normalized dataset records as JSON, CSV, or JSONL.',
            'annotation:init <dataset-dir>' => 'Initialize an annotation file for review labels and future ML training.',
            'annotation:add <annotations.json>' => 'Add one review annotation to a dataset annotation file.',
            'eval:dataset <dataset-id|manifest.json>' => 'Evaluate dataset completeness, validation, duplicates, annotations, and training readiness.',
            'eval:pipeline <pipeline.json>' => 'Evaluate pipeline records without first creating a dataset snapshot.',
            'eval:profile <profile>' => 'Evaluate profile schema field success against a dataset.',
            'eval:selectors' => 'Measure selector/field success for a profile against a dataset.',
            'benchmark:profile <profile>' => 'Benchmark a profile against dataset records.',
            'benchmark:compare <old> <new>' => 'Compare evaluation metrics between two datasets.',
            'annotation:stats <dataset-id|manifest.json>' => 'Show label counts, field counts, and annotation coverage.',
            'annotation:coverage <dataset-id|manifest.json>' => 'Show annotation coverage for one dataset.',
            'annotation:export <dataset-id|manifest.json>' => 'Export annotation rows for review or ML workflows.',
            'intelligence:doctor' => 'Show ML/intelligence capability status and optional PHP-ML availability.',
            'intelligence:analyze <input.json>' => 'Extract ML-ready features from crawl, pipeline, source, or URL JSON files.',
            'intelligence:classify <input.json>' => 'Classify pages into article/ecommerce/job/tender/contact/JS/error groups.',
            'intelligence:quality <input.json>' => 'Predict page/record quality labels from extracted features.',
            'intelligence:priority <input>' => 'Score and sort URLs for crawl priority from TXT or JSON input.',
            'intelligence:selectors <html-file>' => 'Suggest profile-aware CSS/meta selectors from saved HTML.',
            'webhook:list' => 'List webhook endpoints from config/webhooks.json or a custom config file.',
            'webhook:test' => 'Create or send a test webhook event.',
            'webhook:send <payload.json>' => 'Send a JSON payload as a webhook event to one endpoint.',
            'http:test <url>' => 'Test native PHP HTTP engines: auto, curl, or stream/file_get_contents.',
            'bulk:crawl <urls.txt>' => 'Crawl many URLs with gaps, pauses, checkpoint, and resume.',
            'url:process <urls.txt>' => 'Process URLs one by one with retry, method ladder, checkpoint, and resume.',
            'robots:test <url>' => 'Inspect robots.txt decision for one URL.',
            'encoding:test <url>' => 'Fetch one URL and report detected encoding.',
            'common:extract <url>' => 'Extract common data patterns from one URL.',
            'common:types' => 'List supported common data types and profiles.',
            'rule:analyze <html-file|url>' => 'Analyze a saved HTML page or URL and show signals for rule/profile generation.',
            'rule:generate <html-file|url>' => 'Generate a starter profile schema from HTML using the auto-profile assistant.',
            'rule:test <html-file|url>' => 'Test profile/rules-file extraction against saved HTML or one URL.',
            'rule:doctor <profile|profile.json>' => 'Check profile schema quality and optional sample extraction results.',
            'profile:scaffold <name>' => 'Create a starter profile schema without editing PHP code.',
            'profile:list' => 'List available profile schema files.',
            'profile:show <profile>' => 'Show one profile schema with fields, validators, transformations, dedupe keys, and rules.',
            'profile:validate <profile.json>' => 'Validate a profile schema JSON file.',
            'extract:rules <url>' => 'Extract fields from one URL using profile schema rules or --rule/--rules-file.',
            'job:create' => 'Create a local queued crawl/source job JSON file.',
            'job:list' => 'List queued jobs across pending/running/completed/failed/paused states.',
            'job:show <job-id>' => 'Show one queued job manifest.',
            'job:pause <job-id>' => 'Move a queued job to paused state.',
            'job:resume <job-id>' => 'Move a paused/failed/cancelled job back to pending state.',
            'job:cancel <job-id>' => 'Cancel a queued job.',
            'worker:once' => 'Run one pending queued job and exit.',
            'worker:run' => 'Run queue worker loop with sleep, max-jobs, max-runtime, and memory guard options.',
            'worker:status' => 'Show queue counts and active worker locks.',
            'queue:failed' => 'List failed queue jobs.',
            'queue:retry <job-id>' => 'Move one failed job back to pending for retry.',
            'queue:retry-safe' => 'Move only retry-eligible failed jobs back to pending.',
            'queue:retry-all' => 'Move all failed jobs back to pending for retry.',
            'queue:clear-failed' => 'Delete failed queue job files.',
            'report:failed <crawl.json>' => 'Create failed/skipped URL report.',
            'export:failed <crawl.json>' => 'Export failed/skipped URL report as JSON, CSV, or XML.',
            'report:summary <job-dir>' => 'Generate professional job summary as HTML, JSON, CSV, or XML.',
            'bundle:create <job-dir>' => 'Create a portable ZIP bundle with exports, reports, manifest, and logs.',
            'pipeline:run <crawl.json>' => 'Run professional record pipeline on crawl JSON.',
            'retry:failed <crawl.json>' => 'Create retry URL list from failed pages.',
            'export:records <records.json>' => 'Export pipeline records to JSON, CSV, XML, or HTML.',
            'validate:records <records.json>' => 'Validate records using required fields.',
            'job:summary <job-dir>' => 'Show job manifest and pipeline/crawl summaries.',
            'job:run <job-id|job.json>' => 'Run one queued job by ID, or run a legacy JSON job config file.',
            'source:discover <url>' => 'Check fallback sources such as sitemap, feeds, robots, and well-known endpoints.',
            'source:sitemap <sitemap>' => 'Read sitemap.xml or sitemap index and export crawlable URLs.',
            'source:rss <feed-url>' => 'Read RSS/Atom feed and export normalized feed records/URLs.',
            'source:csv <file.csv>' => 'Read crawl URLs from a CSV file with a configurable URL column.',
            'source:json <file.json>' => 'Read crawl URLs from JSON using a dot path such as items.*.url.',
            'source:api <endpoint>' => 'Fetch a JSON API endpoint and extract URLs using a dot path.',
            'source:urls <source.json>' => 'Export URL list from a source connector JSON output.',
            'plos:journals' => 'List known PLOS journals, API names, homepages, and feed candidates.',
            'plos:search <query>' => 'Use the official PLOS Search API and return normalized article records.',
            'plos:feed <journal>' => 'Fetch a PLOS journal RSS/Atom feed and normalize article records.',
            'plos:urls <query>' => 'Export article/PDF/XML/DOI URLs from PLOS API results for bulk workflows.',
            'elsevier:journals' => 'Show Elsevier/ScienceDirect source connector catalog and API command hints.',
            'elsevier:search <query>' => 'Search ScienceDirect API using an Elsevier API key.',
            'elsevier:metadata <id>' => 'Fetch ScienceDirect article metadata by DOI or PII.',
            'elsevier:doi <doi>' => 'Shortcut for ScienceDirect article metadata by DOI.',
            'elsevier:serial <issn>' => 'Fetch Elsevier serial title metadata by ISSN.',
            'elsevier:urls <query>' => 'Export article/DOI/API URLs from ScienceDirect search results.',
        ];
        foreach ($commands as $name => $description) {
            $this->out(sprintf('  %-32s %s', $name, $description));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function crawl(array $args, array $opts): int
    {
        $url = $args[0] ?? null;
        if (!$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper crawl <url> [options]');
        }

        $opts['url'] = $url;
        $jobDir = $this->optString($opts, 'job-dir');
        $logger = new Logger($jobDir ? $jobDir . '/logs/crawl.log' : null);
        $options = $this->crawlOptions($opts);
        $rules = $this->rulesFromOptions($opts);
        $browserOptions = $this->browserOptionsFromCli($opts);
        if ($browserOptions->outputDir === null && $jobDir) {
            $browserOptions->outputDir = rtrim($jobDir, '/\\') . '/browser';
        }

        if ($browserOptions->mode === 'always') {
            $this->out('Starting browser-assisted crawl...');
            $result = $this->browserCrawlResult($url, $options, $browserOptions, $rules);
        } else {
            $this->out('Starting crawl...');
            $result = (new Scraper($this->config, $logger))->crawl($url, $options, $rules);
            if ($browserOptions->mode === 'auto') {
                $firstPage = $result->pages()[0] ?? null;
                if ($firstPage instanceof PageResult) {
                    $assessment = (new BrowserFallbackDetector())->assessPage($firstPage, $browserOptions);
                    if (!empty($assessment['should_use_browser'])) {
                        $service = new BrowserCrawlService($this->config);
                        if ($service->isAvailable($browserOptions)) {
                            $this->out('Browser fallback triggered: ' . implode(', ', (array) ($assessment['reasons'] ?? [])));
                            $result = $this->browserCrawlResult($url, $options, $browserOptions, $rules);
                        } else {
                            $this->out('Browser fallback recommended but unavailable: ' . $service->availability($browserOptions));
                        }
                    }
                }
            }
        }

        $format = strtolower($this->optString($opts, 'format', 'json') ?? 'json');
        $output = $this->optString($opts, 'output');
        if ($jobDir) {
            $this->ensureDir($jobDir);
            $output ??= rtrim($jobDir, '/\\') . '/crawl.' . ($format === 'csv' ? 'csv' : 'json');
        }
        $output ??= $this->storagePath('crawl-' . date('Ymd-His') . '.' . ($format === 'csv' ? 'csv' : 'json'));

        if ($format === 'csv') {
            (new CsvExporter())->export($result, $output);
        } else {
            (new JsonExporter())->export($result, $output, $this->bool($opts, 'include-html'));
        }

        $pipelineOutput = null;
        if ($this->bool($opts, 'pipeline')) {
            $pipelineDir = $this->optString($opts, 'pipeline-output') ?: (($jobDir ?: dirname($output)) . '/pipeline');
            $pipelineOptions = $this->pipelineOptionsFromCli($opts);
            $pipelineResult = (new ProfessionalCrawlPipeline())->run($result, $pipelineOptions);
            (new PipelineExporter())->export($pipelineResult, $pipelineDir, $this->optString($opts, 'pipeline-format', 'both') ?? 'both');
            $pipelineOutput = $pipelineDir;
        }

        if ($jobDir) {
            JobManifest::write($jobDir, 'crawl', [
                'url' => $url,
                'options' => $this->publicOptions($opts),
            ], [
                'crawl' => $output,
                'pipeline' => $pipelineOutput,
            ], $result->summary());
        }

        $summary = $result->summary();
        $this->out('Crawl completed.');
        $this->out(sprintf('Pages: %d | OK: %d | Failed: %d | Skipped: %d', $summary['pages_total'], $summary['pages_ok'], $summary['pages_failed'], $summary['pages_skipped']));
        $this->out('Output: ' . $output);
        if ($pipelineOutput) {
            $this->out('Pipeline output: ' . $pipelineOutput);
        }
        if ($this->bool($opts, 'fail-on-challenge') && (int) ($summary['pages_challenge'] ?? 0) > 0) {
            $this->err('Challenge/protection pages detected. See output JSON for protection diagnostics.');
            return 3;
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function bulkCrawl(array $args, array $opts): int
    {
        $file = $args[0] ?? null;
        if (!$file || !is_file($file)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper bulk:crawl <urls.txt> [options]');
        }

        $urls = array_values(array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES) ?: []), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
        if ($urls === []) {
            throw new \RuntimeException('No URLs found in file.');
        }

        $jobDir = $this->optString($opts, 'job-dir') ?: $this->storagePath('bulk-' . date('Ymd-His'));
        $checkpoint = $this->optString($opts, 'checkpoint') ?: rtrim($jobDir, '/\\') . '/checkpoint.json';
        $resume = $this->bool($opts, 'resume');
        $startIndex = 0;
        $results = [];
        if ($resume && is_file($checkpoint)) {
            $state = json_decode((string) file_get_contents($checkpoint), true);
            if (is_array($state)) {
                $startIndex = max(0, (int) ($state['next_index'] ?? 0));
                $results = is_array($state['results'] ?? null) ? $state['results'] : [];
            }
        }

        $this->ensureDir($jobDir . '/crawls');
        $gapMs = max(0, (int) ($this->opt($opts, 'gap-ms', $this->opt($opts, 'gap', 0))));
        $batchSize = max(0, (int) $this->opt($opts, 'batch-size', 0));
        $batchPause = max(0, (int) $this->opt($opts, 'batch-pause', 0));
        $pauseEverySeconds = max(0, (int) $this->opt($opts, 'pause-every-seconds', 0));
        $pauseSeconds = max(0, (int) $this->opt($opts, 'pause-seconds', 0));
        $lastTimePause = time();
        $options = null;
        $rules = $this->rulesFromOptions($opts);
        $logger = new Logger($jobDir . '/logs/bulk-crawl.log');

        $this->out('Starting bulk crawl...');
        $this->out('URLs total: ' . count($urls) . ' | Starting index: ' . $startIndex);

        for ($i = $startIndex; $i < count($urls); $i++) {
            $url = $urls[$i];
            $this->out(sprintf('[%d/%d] %s', $i + 1, count($urls), $url));
            $opts['url'] = $url;
            $options = $this->crawlOptions($opts);
            $itemDir = rtrim($jobDir, '/\\') . '/crawls/' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT);
            $this->ensureDir($itemDir);
            $output = $itemDir . '/crawl.json';
            try {
                $result = (new Scraper($this->config, $logger))->crawl($url, $options, $rules);
                (new JsonExporter())->export($result, $output, false);
                $summary = $result->summary();
                $results[$i] = ['url' => $url, 'output' => $output, 'status' => 'completed', 'summary' => $summary];

                if ($this->bool($opts, 'pipeline')) {
                    $pipelineDir = $itemDir . '/pipeline';
                    $pipelineResult = (new ProfessionalCrawlPipeline())->run($result, $this->pipelineOptionsFromCli($opts));
                    (new PipelineExporter())->export($pipelineResult, $pipelineDir, $this->optString($opts, 'pipeline-format', 'both') ?? 'both');
                    $results[$i]['pipeline'] = $pipelineDir;
                }
            } catch (\Throwable $e) {
                $results[$i] = ['url' => $url, 'output' => null, 'status' => 'error', 'error' => $e->getMessage()];
                $this->err('  Failed: ' . $e->getMessage());
            }

            $this->writeCheckpoint($checkpoint, $i + 1, $urls, $results);

            if ($gapMs > 0 && $i < count($urls) - 1) {
                usleep($gapMs * 1000);
            }
            if ($batchSize > 0 && (($i + 1) % $batchSize) === 0 && $batchPause > 0 && $i < count($urls) - 1) {
                $this->out('Batch pause: ' . $batchPause . ' seconds');
                sleep($batchPause);
            }
            if ($pauseEverySeconds > 0 && $pauseSeconds > 0 && (time() - $lastTimePause) >= $pauseEverySeconds && $i < count($urls) - 1) {
                $this->out('Time pause: ' . $pauseSeconds . ' seconds');
                sleep($pauseSeconds);
                $lastTimePause = time();
            }
        }

        $summary = [
            'urls_total' => count($urls),
            'completed' => count(array_filter($results, static fn ($r): bool => ($r['status'] ?? '') === 'completed')),
            'errors' => count(array_filter($results, static fn ($r): bool => ($r['status'] ?? '') === 'error')),
        ];
        $summaryPath = rtrim($jobDir, '/\\') . '/bulk-summary.json';
        file_put_contents($summaryPath, json_encode(['summary' => $summary, 'results' => array_values($results)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        JobManifest::write($jobDir, 'bulk-crawl', ['source_file' => $file, 'options' => $this->publicOptions($opts)], ['summary' => $summaryPath, 'checkpoint' => $checkpoint], $summary);

        $this->out('Bulk crawl completed.');
        $this->out('Output: ' . $jobDir);
        return 0;
    }


    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function urlProcess(array $args, array $opts): int
    {
        $file = $args[0] ?? null;
        if (!$file || !is_file($file)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper url:process <urls.txt> [--methods=auto,curl,stream,cmd-curl,powershell] [--max-attempts=3] [--resume]');
        }

        $urls = array_values(array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES) ?: []), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
        if ($urls === []) {
            throw new \RuntimeException('No URLs found in file.');
        }

        $outputDir = $this->optString($opts, 'output-dir')
            ?: $this->optString($opts, 'job-dir')
            ?: $this->storagePath('url-process-' . date('Ymd-His'));
        $checkpoint = $this->optString($opts, 'checkpoint') ?: rtrim($outputDir, '/\\') . '/checkpoint.json';
        $processOptions = $this->urlProcessOptionsFromCli($opts);

        if ($processOptions->untilSuccess && $processOptions->maxAttempts === 0) {
            $this->out('Running in --until-success mode with no max-attempts cap. Use Ctrl+C or create the stop file to end safely.');
        }
        if ($processOptions->retryChallenge) {
            $this->out('Warning: --retry-challenge is enabled. For IP blocks/challenges, prefer long cooldowns and allowed source connectors. Do not hammer blocked sites.');
        }

        $this->out('Starting sequential URL processing...');
        $this->out('URLs total: ' . count($urls));
        $this->out('Methods: ' . implode(', ', $processOptions->methods));
        $this->out('Output dir: ' . $outputDir);

        $data = (new SequentialUrlProcessor($this->config, $this->rootDir))->process(
            $urls,
            $processOptions,
            $opts,
            $outputDir,
            $checkpoint,
            $this->bool($opts, 'resume'),
            fn (string $line): null => ($this->out($line) ?: null)
        );

        $summary = (array) ($data['summary'] ?? []);
        $this->out('URL processing completed.');
        $this->out(sprintf('Success: %d | Failed: %d | Challenge: %d', (int) ($summary['success'] ?? 0), (int) ($summary['failed'] ?? 0), (int) ($summary['challenge'] ?? 0)));
        $this->out('Summary: ' . rtrim($outputDir, '/\\') . '/process-summary.json');
        $this->out('Success URLs: ' . rtrim($outputDir, '/\\') . '/success-urls.txt');
        $this->out('Failed URLs: ' . rtrim($outputDir, '/\\') . '/failed-urls.txt');
        $this->out('Challenge URLs: ' . rtrim($outputDir, '/\\') . '/challenge-urls.txt');

        if ($this->bool($opts, 'json')) {
            $this->outJson($summary);
        }
        return ((int) ($summary['failed'] ?? 0) > 0 || (int) ($summary['challenge'] ?? 0) > 0) ? 2 : 0;
    }


    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function browserTest(array $args, array $opts): int
    {
        $url = $args[0] ?? null;
        if (!$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper browser:test <url> [--browser=auto|always] [--render] [--json]');
        }

        $opts['url'] = $url;
        $browserOptions = $this->browserOptionsFromCli($opts);
        if ($browserOptions->mode === 'off') {
            $browserOptions->mode = 'auto';
        }
        $options = $this->crawlOptions($opts);
        $service = new BrowserCrawlService($this->config);
        $data = [
            'url' => $url,
            'browser' => $browserOptions->toArray(),
            'adapter_available' => $service->isAvailable($browserOptions),
            'availability' => $service->availability($browserOptions),
        ];

        $shouldRender = $this->bool($opts, 'render') || $browserOptions->mode === 'always';
        if ($shouldRender) {
            $rendered = $service->render($url, $options, $browserOptions);
            $data['rendered'] = $rendered->toArray(!$this->bool($opts, 'no-html'));
        } else {
            $probeOptions = $options;
            $probeOptions->maxPages = 1;
            $probeOptions->maxDepth = 0;
            $probe = (new Scraper($this->config, new Logger()))->crawl($url, $probeOptions, $this->rulesFromOptions($opts));
            $page = $probe->pages()[0] ?? null;
            if ($page instanceof PageResult) {
                $data['http_probe'] = $page->toArray(false);
                $data['fallback_assessment'] = (new BrowserFallbackDetector())->assessPage($page, $browserOptions);
            }
        }

        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || !$output) {
            $this->outJson($data);
        }
        return empty($data['fallback_assessment']['should_use_browser']) ? 0 : 2;
    }


    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function browserSessionCreate(array $args, array $opts): int
    {
        $name = $args[0] ?? $this->optString($opts, 'name');
        if (!$name) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper browser:session-create <name> --domain=example.com [--login-url=https://example.com/login]');
        }
        $domains = $this->stringList($this->opt($opts, 'domain', $this->opt($opts, 'domains', [])));
        if ($domains === []) {
            throw new \InvalidArgumentException('At least one --domain is required for safe authorized browser sessions.');
        }
        $store = $this->browserSessionStore();
        $profile = $store->create((string) $name, $domains, $this->optString($opts, 'login-url'), [
            'wait_selector' => $this->optString($opts, 'wait-selector'),
            'browser_mode' => $this->optString($opts, 'browser', $this->optString($opts, 'browser-mode', 'auto') ?? 'auto'),
            'timeout_ms' => (int) $this->opt($opts, 'browser-timeout-ms', $this->opt($opts, 'timeout-ms', 30000)),
            'headless' => $this->bool($opts, 'no-headless') ? false : (array_key_exists('headless', $opts) ? $this->bool($opts, 'headless') : true),
            'block_assets' => !$this->bool($opts, 'allow-assets'),
        ]);
        if ($this->bool($opts, 'json')) {
            $this->outJson(['created' => true, 'profile' => $profile->toArray(), 'path' => $store->profilePath($profile->name)]);
            return 0;
        }
        $this->out('Created browser session: ' . $profile->name);
        $this->out('Allowed domains: ' . implode(', ', $profile->allowedDomains));
        $this->out('Profile: ' . $store->profilePath($profile->name));
        $this->out('Cookie file: ' . ($profile->cookieFile ?? ''));
        $this->out('Passwords are not stored. Use browser:login for manual login assist.');
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function browserSessionList(array $args, array $opts): int
    {
        $store = $this->browserSessionStore();
        $rows = $store->list();
        if ($this->bool($opts, 'json')) {
            $this->outJson(['profiles_dir' => $store->profilesDir(), 'sessions_dir' => $store->sessionsDir(), 'sessions' => $rows]);
            return 0;
        }
        $this->out('Browser sessions: ' . count($rows));
        $this->out('Profiles: ' . $store->profilesDir());
        foreach ($rows as $row) {
            $this->out(sprintf('  %-22s %-9s %s', (string) $row['name'], (string) $row['browser_mode'], implode(',', (array) $row['allowed_domains'])));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function browserSessionShow(array $args, array $opts): int
    {
        $name = $args[0] ?? $this->optString($opts, 'session');
        if (!$name) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper browser:session-show <name>');
        }
        $store = $this->browserSessionStore();
        $profile = $store->load((string) $name);
        $data = $profile->toArray();
        $data['profile_path'] = $store->profilePath($profile->name);
        $data['session_dir'] = $store->sessionDir($profile->name);
        $data['cookie_file_exists'] = $profile->cookieFile ? is_file($profile->cookieFile) : false;
        $this->outJson($data);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function browserSessionClear(array $args, array $opts): int
    {
        $name = $args[0] ?? $this->optString($opts, 'session');
        if (!$name) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper browser:session-clear <name> [--remove-profile]');
        }
        $data = $this->browserSessionStore()->clear((string) $name, $this->bool($opts, 'remove-profile'));
        if ($this->bool($opts, 'json')) {
            $this->outJson($data);
        } else {
            $this->out('Cleared browser session: ' . (string) $data['session']);
            $this->out('Removed files: ' . count((array) $data['removed']));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function browserSessionTest(array $args, array $opts): int
    {
        $session = $args[0] ?? $this->optString($opts, 'session');
        $url = $args[1] ?? $this->optString($opts, 'url');
        if (!$session || !$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper browser:session-test <session> <url> [--render] [--json]');
        }
        $opts['session'] = $session;
        $opts['browser'] = $this->optString($opts, 'browser', 'auto') ?? 'auto';
        $opts['url'] = $url;
        $browserOptions = $this->browserOptionsFromCli($opts);
        $crawlOptions = $this->crawlOptions($opts);
        $store = $this->browserSessionStore();
        $profile = $store->load((string) $session);
        $store->assertUrlAllowed($profile, (string) $url);
        $service = new BrowserCrawlService($this->config);
        $data = [
            'session' => $profile->toArray(),
            'url' => $url,
            'browser' => $browserOptions->toArray(),
            'adapter_available' => $service->isAvailable($browserOptions),
            'availability' => $service->availability($browserOptions),
            'authorized_workflow_note' => 'Use only for owned/client-approved/authenticated sources. No CAPTCHA or access-control bypass is provided.',
        ];
        if ($this->bool($opts, 'render') || $browserOptions->mode === 'always') {
            $rendered = $service->render((string) $url, $crawlOptions, $browserOptions);
            $data['rendered'] = $rendered->toArray(!$this->bool($opts, 'no-html'));
        }
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || !$output) {
            $this->outJson($data);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function browserLogin(array $args, array $opts): int
    {
        $session = $args[0] ?? $this->optString($opts, 'session');
        if (!$session) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper browser:login <session> --url=https://example.com/login');
        }
        $store = $this->browserSessionStore();
        $profile = $store->load((string) $session);
        $url = $this->optString($opts, 'url') ?: $profile->loginUrl;
        if (!$url) {
            throw new \InvalidArgumentException('A login URL is required with --url or stored in the session profile.');
        }
        $store->assertUrlAllowed($profile, (string) $url);
        $profile->loginUrl = (string) $url;
        $profile->headless = false;
        $store->save($profile);
        $instructions = $store->writeLoginInstructions($profile, (string) $url);
        $data = [
            'session' => $profile->name,
            'login_url' => $url,
            'instructions' => $instructions,
            'cookie_file' => $profile->cookieFile,
            'password_storage' => 'disabled_by_default',
            'authorized_workflows_only' => true,
        ];
        if ($this->bool($opts, 'render')) {
            $renderOpts = $opts;
            $renderOpts['browser'] = 'always';
            $renderOpts['headless'] = false;
            $renderOpts['session'] = $profile->name;
            $browserOptions = $this->browserOptionsFromCli($renderOpts);
            $service = new BrowserCrawlService($this->config);
            $data['adapter_available'] = $service->isAvailable($browserOptions);
            $data['availability'] = $service->availability($browserOptions);
            if ($service->isAvailable($browserOptions)) {
                $data['rendered'] = $service->render((string) $url, $this->crawlOptions($renderOpts), $browserOptions)->toArray(false);
            }
        }
        if ($this->bool($opts, 'json')) {
            $this->outJson($data);
        } else {
            $this->out('Browser login assist prepared for session: ' . $profile->name);
            $this->out('Login URL: ' . (string) $url);
            $this->out('Instructions: ' . $instructions);
            $this->out('Cookie file: ' . ($profile->cookieFile ?? ''));
            $this->out('Passwords are not stored. Use this only for authorized workflows.');
        }
        return 0;
    }


    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function apiRoutes(array $args, array $opts): int
    {
        $data = [
            'ok' => true,
            'api_version' => ApiRouter::VERSION,
            'routes' => ApiRouter::routes(),
            'token_required_when_configured' => true,
        ];
        if ($this->bool($opts, 'json')) {
            $this->outJson($data);
            return 0;
        }
        $this->out('MNB ScraperKit API routes:');
        foreach (ApiRouter::routes() as $route) {
            $this->out(sprintf('  %-6s %-28s %s', $route['method'], $route['path'], $route['description']));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function apiToken(array $args, array $opts): int
    {
        $prefix = $this->optString($opts, 'prefix', 'mnb_sk') ?? 'mnb_sk';
        $token = ApiToken::generate($prefix);
        $data = [
            'ok' => true,
            'token' => $token,
            'usage' => 'Set MNB_SCRAPERKIT_API_TOKEN and send Authorization: Bearer <token>.',
        ];
        $this->outJson($data);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function apiServe(array $args, array $opts): int
    {
        $host = $this->optString($opts, 'host', '127.0.0.1') ?? '127.0.0.1';
        $port = (int) ($this->optString($opts, 'port', '8787') ?? '8787');
        $router = $this->rootDir . '/public/api-router.php';
        if (!is_file($router)) {
            $router = dirname(__DIR__, 2) . '/public/api-router.php';
        }
        if (!is_file($router)) {
            throw new \RuntimeException('API router file missing: ' . $router);
        }
        $command = PHP_BINARY . ' -S ' . escapeshellarg($host . ':' . $port) . ' ' . escapeshellarg($router);
        $data = [
            'ok' => true,
            'api_version' => ApiRouter::VERSION,
            'url' => 'http://' . $host . ':' . $port . '/api/v1/health',
            'router' => $router,
            'command' => $command,
            'token_env' => 'MNB_SCRAPERKIT_API_TOKEN',
        ];
        if ($this->bool($opts, 'json') || $this->bool($opts, 'dry-run') || $this->bool($opts, 'print-command')) {
            $this->outJson($data);
            return 0;
        }
        $this->out('Starting MNB ScraperKit API server. Press Ctrl+C to stop.');
        $this->out('Health URL: ' . $data['url']);
        $this->out('Use MNB_SCRAPERKIT_API_TOKEN to require Bearer token auth.');
        passthru($command, $exitCode);
        return (int) $exitCode;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dashboardStatus(array $args, array $opts): int
    {
        $collector = new DashboardDataCollector($this->rootDir);
        $data = $collector->collect(
            (int) ($this->optString($opts, 'recent', '20') ?? '20'),
            (int) ($this->optString($opts, 'ttl-seconds', '900') ?? '900')
        );
        $status = [
            'ok' => true,
            'dashboard_version' => DashboardDataCollector::VERSION,
            'health' => $data['health'] ?? 'unknown',
            'queue_counts' => $data['queue']['counts'] ?? [],
            'schedule_counts' => $data['monitor']['schedule_counts'] ?? [],
            'profiles_total' => $data['profiles']['total'] ?? 0,
            'plugins_total' => $data['plugins']['total'] ?? 0,
            'html_path' => $this->rootDir . '/public/dashboard.php',
            'json_path' => '/dashboard.json',
            'token_env' => 'MNB_SCRAPERKIT_DASHBOARD_TOKEN',
        ];
        if ($this->bool($opts, 'json')) {
            $this->outJson($status);
            return 0;
        }
        $this->out('MNB ScraperKit Dashboard ' . DashboardDataCollector::VERSION);
        $this->out('Health: ' . (string) $status['health']);
        $this->out('Queue: ' . json_encode($status['queue_counts'], JSON_UNESCAPED_SLASHES));
        $this->out('Schedules: ' . json_encode($status['schedule_counts'], JSON_UNESCAPED_SLASHES));
        $this->out('Profiles: ' . (string) $status['profiles_total'] . ' | Plugins: ' . (string) $status['plugins_total']);
        $this->out('Serve with: php bin/mnb-scraper dashboard:serve');
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dashboardBuild(array $args, array $opts): int
    {
        $output = $this->optString($opts, 'output') ?: $this->storagePath('dashboard/index.html');
        $refresh = (int) ($this->optString($opts, 'refresh', '0') ?? '0');
        $data = (new DashboardDataCollector($this->rootDir))->collect(
            (int) ($this->optString($opts, 'recent', '20') ?? '20'),
            (int) ($this->optString($opts, 'ttl-seconds', '900') ?? '900')
        );
        $html = (new DashboardRenderer())->render($data, ['refresh_seconds' => $refresh]);
        $this->ensureDir(dirname($output));
        file_put_contents($output, $html);
        $result = [
            'ok' => true,
            'dashboard_version' => DashboardDataCollector::VERSION,
            'output' => $output,
            'bytes' => filesize($output) ?: strlen($html),
        ];
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
        } else {
            $this->out('Dashboard written: ' . $output);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dashboardServe(array $args, array $opts): int
    {
        $host = $this->optString($opts, 'host', '127.0.0.1') ?? '127.0.0.1';
        $port = (int) ($this->optString($opts, 'port', '8788') ?? '8788');
        $router = $this->rootDir . '/public/dashboard.php';
        if (!is_file($router)) {
            $router = dirname(__DIR__, 2) . '/public/dashboard.php';
        }
        if (!is_file($router)) {
            throw new \RuntimeException('Dashboard router file missing: ' . $router);
        }
        $command = PHP_BINARY . ' -S ' . escapeshellarg($host . ':' . $port) . ' ' . escapeshellarg($router);
        $data = [
            'ok' => true,
            'dashboard_version' => DashboardDataCollector::VERSION,
            'url' => 'http://' . $host . ':' . $port . '/dashboard',
            'json_url' => 'http://' . $host . ':' . $port . '/dashboard.json',
            'router' => $router,
            'command' => $command,
            'token_env' => 'MNB_SCRAPERKIT_DASHBOARD_TOKEN',
        ];
        if ($this->bool($opts, 'json') || $this->bool($opts, 'dry-run') || $this->bool($opts, 'print-command')) {
            $this->outJson($data);
            return 0;
        }
        $this->out('Starting MNB ScraperKit Dashboard. Press Ctrl+C to stop.');
        $this->out('Dashboard URL: ' . $data['url']);
        $this->out('Set MNB_SCRAPERKIT_DASHBOARD_TOKEN to require a token.');
        passthru($command, $exitCode);
        return (int) $exitCode;
    }



    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function datasetCreate(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper dataset:create <input.json|urls.txt> [--id=name] [--type=auto|crawl|pipeline|source|intelligence|url-list] [--output-dir=storage/datasets]');
        }
        $store = new DatasetStore($this->rootDir, $this->optString($opts, 'datasets-dir'));
        $result = $store->create(
            $input,
            $this->optString($opts, 'id') ?: $this->optString($opts, 'dataset-id'),
            $this->optString($opts, 'type', 'auto') ?? 'auto',
            $this->optString($opts, 'output-dir') ?: $this->optString($opts, 'datasets-dir')
        );
        $manifest = (array) $result['manifest'];
        if ($this->bool($opts, 'json')) {
            $this->outJson(['ok' => true, 'dataset_dir' => $result['dataset_dir'], 'manifest' => $manifest]);
        } else {
            $this->out('Dataset created: ' . (string) $manifest['dataset_id']);
            $this->out('Records: ' . (string) ($manifest['summary']['records_total'] ?? 0));
            $this->out('Output: ' . (string) $result['dataset_dir']);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function datasetList(array $args, array $opts): int
    {
        $store = new DatasetStore($this->rootDir, $this->optString($opts, 'datasets-dir'));
        $datasets = $store->list();
        if ($this->bool($opts, 'json')) {
            $this->outJson(['ok' => true, 'datasets_total' => count($datasets), 'datasets' => $datasets]);
            return 0;
        }
        $this->out('Datasets: ' . count($datasets));
        foreach ($datasets as $dataset) {
            $this->out(sprintf('  %-40s %8d records  %s', (string) ($dataset['dataset_id'] ?? ''), (int) ($dataset['summary']['records_total'] ?? 0), (string) ($dataset['source_type'] ?? '')));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function datasetShow(array $args, array $opts): int
    {
        $id = $args[0] ?? $this->optString($opts, 'id') ?? $this->optString($opts, 'dataset-id');
        if (!$id) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper dataset:show <dataset-id|manifest.json>');
        }
        $store = new DatasetStore($this->rootDir, $this->optString($opts, 'datasets-dir'));
        $manifest = $store->show($id);
        $qualityFile = rtrim((string) $manifest['_dataset_dir'], '/\\') . '/' . (string) ($manifest['quality_file'] ?? 'quality-summary.json');
        $quality = is_file($qualityFile) ? json_decode((string) file_get_contents($qualityFile), true) : null;
        if ($this->bool($opts, 'json')) {
            $this->outJson(['ok' => true, 'manifest' => $manifest, 'quality' => is_array($quality) ? $quality : null]);
            return 0;
        }
        $this->out('Dataset: ' . (string) ($manifest['dataset_id'] ?? ''));
        $this->out('Source type: ' . (string) ($manifest['source_type'] ?? ''));
        $this->out('Records: ' . (string) ($manifest['summary']['records_total'] ?? 0));
        $this->out('Average quality: ' . (string) ($manifest['summary']['quality_score_avg'] ?? 0));
        $this->out('Path: ' . (string) ($manifest['_dataset_dir'] ?? ''));
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function datasetDiff(array $args, array $opts): int
    {
        $old = $args[0] ?? $this->optString($opts, 'old');
        $new = $args[1] ?? $this->optString($opts, 'new');
        if (!$old || !$new) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper dataset:diff <old-dataset> <new-dataset> [--json]');
        }
        $store = new DatasetStore($this->rootDir, $this->optString($opts, 'datasets-dir'));
        $diff = (new DatasetComparator())->compare($store->records($old), $store->records($new));
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $diff);
        }
        if ($this->bool($opts, 'json')) {
            $this->outJson($diff + ['output' => $output]);
            return 0;
        }
        $this->out('Dataset diff');
        $this->out('Added: ' . $diff['added_total'] . ' | Removed: ' . $diff['removed_total'] . ' | Changed: ' . $diff['changed_total'] . ' | Common: ' . $diff['common_total']);
        if ($output) {
            $this->out('Output: ' . $output);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function datasetExport(array $args, array $opts): int
    {
        $id = $args[0] ?? $this->optString($opts, 'id') ?? $this->optString($opts, 'dataset-id');
        if (!$id) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper dataset:export <dataset-id|manifest.json> [--format=json|csv|jsonl] [--output=file]');
        }
        $format = strtolower($this->optString($opts, 'format', 'json') ?? 'json');
        $store = new DatasetStore($this->rootDir, $this->optString($opts, 'datasets-dir'));
        $manifest = $store->show($id);
        $trainingReady = $this->bool($opts, 'training-ready');
        $trainingType = $this->optString($opts, 'training-type', 'classification') ?? 'classification';
        $suffix = $format === 'jsonl' ? 'jsonl' : ($format === 'csv' ? 'csv' : 'json');
        $output = $this->optString($opts, 'output') ?: rtrim((string) $manifest['_dataset_dir'], '/\\') . ($trainingReady ? '/training-ready.' : '/dataset-export.') . $suffix;
        $result = (new DatasetExporter())->export($store->records($id), $output, $format, $trainingReady, $trainingType);
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
        } else {
            $this->out('Dataset exported: ' . $output);
            $this->out('Records: ' . (string) $result['records_exported']);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function annotationInit(array $args, array $opts): int
    {
        $datasetDir = $args[0] ?? $this->optString($opts, 'dataset-dir');
        if (!$datasetDir) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper annotation:init <dataset-dir> [--output=annotations.json]');
        }
        $result = (new AnnotationStore())->init($datasetDir, $this->optString($opts, 'output'));
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
        } else {
            $this->out('Annotations initialized: ' . (string) $result['output']);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function annotationAdd(array $args, array $opts): int
    {
        $file = $args[0] ?? $this->optString($opts, 'file') ?? $this->optString($opts, 'annotations');
        $recordId = $this->optString($opts, 'record-id');
        $label = $this->optString($opts, 'label');
        if (!$file || !$recordId || !$label) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper annotation:add <annotations.json> --record-id=ID --label=good|bad|review [--note=text] [--field=name]');
        }
        $result = (new AnnotationStore())->add($file, $recordId, $label, $this->optString($opts, 'note'), $this->optString($opts, 'field'), $this->optString($opts, 'user'));
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
        } else {
            $this->out('Annotation added. Total annotations: ' . (string) $result['annotations_total']);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function evalDataset(array $args, array $opts): int
    {
        $id = $args[0] ?? $this->optString($opts, 'dataset') ?? $this->optString($opts, 'dataset-id') ?? $this->optString($opts, 'id');
        if (!$id) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper eval:dataset <dataset-id|manifest.json> [--profile=ecommerce] [--format=json|csv|html] [--output=file]');
        }
        $context = $this->datasetContext((string) $id, $opts);
        $profile = $this->optionalProfileSchema($opts);
        $report = (new DatasetEvaluator())->evaluate($context['records'], $context['manifest'], $context['annotations'], $profile);
        return $this->outputEvaluationReport($report, $opts, 'dataset-evaluation');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function evalPipeline(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input || !is_file((string) $input)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper eval:pipeline <pipeline.json> [--profile=ecommerce] [--output=file]');
        }
        $data = $this->readJsonFile((string) $input);
        $records = $this->recordsFromData($data);
        $profile = $this->optionalProfileSchema($opts);
        $report = (new DatasetEvaluator())->evaluate($records, ['dataset_id' => basename((string) $input), 'source_type' => 'pipeline'], null, $profile);
        return $this->outputEvaluationReport($report, $opts, 'pipeline-evaluation');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function evalProfile(array $args, array $opts): int
    {
        $profileName = $args[0] ?? $this->optString($opts, 'profile');
        $dataset = $this->optString($opts, 'dataset') ?? $this->optString($opts, 'dataset-id') ?? ($args[1] ?? null);
        if (!$profileName || !$dataset) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper eval:profile <profile> --dataset=DATASET_ID');
        }
        $schema = $this->profileLoader($opts)->load((string) $profileName)->toArray();
        $context = $this->datasetContext((string) $dataset, $opts);
        $report = (new DatasetEvaluator())->evaluate($context['records'], $context['manifest'], $context['annotations'], $schema);
        $benchmark = (new ProfileBenchmark())->benchmark($context['records'], $schema, (string) $profileName);
        $report['profile_benchmark'] = $benchmark;
        return $this->outputEvaluationReport($report, $opts, 'profile-evaluation');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function evalSelectors(array $args, array $opts): int
    {
        $profileName = $this->optString($opts, 'profile') ?? ($args[0] ?? null);
        $dataset = $this->optString($opts, 'dataset') ?? $this->optString($opts, 'dataset-id') ?? ($args[1] ?? null);
        if (!$profileName || !$dataset) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper eval:selectors --profile=ecommerce --dataset=DATASET_ID');
        }
        $schema = $this->profileLoader($opts)->load((string) $profileName)->toArray();
        $context = $this->datasetContext((string) $dataset, $opts);
        $report = (new SelectorPerformanceEvaluator())->evaluate($context['records'], $schema, (string) $profileName);
        return $this->outputEvaluationReport($report, $opts, 'selector-evaluation');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function benchmarkProfile(array $args, array $opts): int
    {
        $profileName = $args[0] ?? $this->optString($opts, 'profile');
        $dataset = $this->optString($opts, 'dataset') ?? $this->optString($opts, 'dataset-id') ?? $this->optString($opts, 'input') ?? ($args[1] ?? null);
        if (!$profileName || !$dataset) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper benchmark:profile <profile> --dataset=DATASET_ID');
        }
        $schema = $this->profileLoader($opts)->load((string) $profileName)->toArray();
        $context = $this->datasetContext((string) $dataset, $opts);
        $report = (new ProfileBenchmark())->benchmark($context['records'], $schema, (string) $profileName);
        return $this->outputEvaluationReport($report, $opts, 'profile-benchmark');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function benchmarkCompare(array $args, array $opts): int
    {
        $old = $args[0] ?? $this->optString($opts, 'old');
        $new = $args[1] ?? $this->optString($opts, 'new') ?? $this->optString($opts, 'compare-with');
        if (!$old || !$new) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper benchmark:compare <old-dataset> <new-dataset>');
        }
        $oldContext = $this->datasetContext((string) $old, $opts);
        $newContext = $this->datasetContext((string) $new, $opts);
        $oldEval = (new DatasetEvaluator())->evaluate($oldContext['records'], $oldContext['manifest'], $oldContext['annotations']);
        $newEval = (new DatasetEvaluator())->evaluate($newContext['records'], $newContext['manifest'], $newContext['annotations']);
        $report = (new ProfileBenchmark())->compareEvaluations($oldEval, $newEval);
        $report['old_dataset_id'] = (string) ($oldContext['manifest']['dataset_id'] ?? $old);
        $report['new_dataset_id'] = (string) ($newContext['manifest']['dataset_id'] ?? $new);
        return $this->outputEvaluationReport($report, $opts, 'benchmark-compare');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function annotationStats(array $args, array $opts): int
    {
        $dataset = $args[0] ?? $this->optString($opts, 'dataset') ?? $this->optString($opts, 'dataset-id');
        if (!$dataset) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper annotation:stats <dataset-id|manifest.json>');
        }
        $context = $this->datasetContext((string) $dataset, $opts);
        $report = (new AnnotationQuality())->stats($context['records'], $context['annotations'] ?? ['annotations' => []]);
        if ($this->bool($opts, 'json')) {
            $this->outJson($report);
        } else {
            $this->out('Annotations: ' . (string) ($report['annotations_total'] ?? 0));
            $this->out('Annotated records: ' . (string) ($report['annotated_records'] ?? 0));
            $this->out('Coverage: ' . (string) ($report['coverage_percent'] ?? 0) . '%');
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function annotationCoverage(array $args, array $opts): int
    {
        $opts['json'] = $opts['json'] ?? true;
        return $this->annotationStats($args, $opts);
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function annotationExport(array $args, array $opts): int
    {
        $dataset = $args[0] ?? $this->optString($opts, 'dataset') ?? $this->optString($opts, 'dataset-id');
        if (!$dataset) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper annotation:export <dataset-id|manifest.json> --format=jsonl|json|csv');
        }
        $context = $this->datasetContext((string) $dataset, $opts);
        $rows = (new AnnotationQuality())->exportRows($context['records'], $context['annotations'] ?? ['annotations' => []]);
        $format = strtolower($this->optString($opts, 'format', 'jsonl') ?? 'jsonl');
        $manifest = $context['manifest'];
        $datasetDir = (string) ($manifest['_dataset_dir'] ?? $this->rootDir . '/storage/datasets');
        $output = $this->optString($opts, 'output') ?: rtrim($datasetDir, '/\\') . '/annotations-export.' . ($format === 'csv' ? 'csv' : ($format === 'json' ? 'json' : 'jsonl'));
        $this->exportRows($rows, $output, $format, ['annotation_export_version' => '3.5.0', 'rows_total' => count($rows)]);
        $this->outJson(['ok' => true, 'rows_exported' => count($rows), 'format' => $format, 'output' => $output]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function intelligenceDoctor(array $args, array $opts): int
    {
        $this->outJson((new IntelligenceDoctor())->inspect());
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function intelligenceAnalyze(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input) {
            throw new \InvalidArgumentException('Usage: intelligence:analyze <input.json> [--output=features.json]');
        }
        $data = (new FeatureExtractor())->analyzeFile((string) $input);
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->outJson(['ok' => true, 'output' => $output, 'summary' => $data['summary'] ?? []]);
        } else {
            $this->outJson($data);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function intelligenceClassify(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input) {
            throw new \InvalidArgumentException('Usage: intelligence:classify <input.json> [--output=classes.json]');
        }
        $analysis = (new FeatureExtractor())->analyzeFile((string) $input);
        $data = (new PageClassifier())->classifyFeatureSet(array_values(array_filter($analysis['page_features'] ?? [], 'is_array')));
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->outJson(['ok' => true, 'output' => $output, 'class_counts' => $data['class_counts'] ?? []]);
        } else {
            $this->outJson($data);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function intelligenceQuality(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input) {
            throw new \InvalidArgumentException('Usage: intelligence:quality <input.json> [--output=quality.json]');
        }
        $analysis = (new FeatureExtractor())->analyzeFile((string) $input);
        $data = (new QualityPredictor())->predict($analysis);
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->outJson(['ok' => true, 'output' => $output, 'summary' => $data['summary'] ?? []]);
        } else {
            $this->outJson($data);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function intelligencePriority(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input || !is_file((string) $input)) {
            throw new \InvalidArgumentException('Usage: intelligence:priority <urls.txt|source.json> [--output=priority.json]');
        }
        $urls = $this->readUrlsForIntelligence((string) $input);
        $data = (new UrlPrioritizer())->prioritize($urls);
        $format = strtolower($this->optString($opts, 'format', 'json') ?? 'json');
        $output = $this->optString($opts, 'output');
        if ($output) {
            if ($format === 'txt') {
                $this->writeTextLines($output, array_values(array_map('strval', $data['urls'] ?? [])));
            } else {
                $this->writeJson($output, $data);
            }
            $this->outJson(['ok' => true, 'output' => $output, 'urls_total' => $data['urls_total'] ?? 0]);
        } else {
            $this->outJson($data);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function intelligenceSelectors(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input || !is_file((string) $input)) {
            throw new \InvalidArgumentException('Usage: intelligence:selectors <html-file> [--profile=ecommerce] [--output=selectors.json]');
        }
        $html = (string) file_get_contents((string) $input);
        $profile = $this->optString($opts, 'profile', 'seo') ?? 'seo';
        $data = (new SelectorSuggester())->suggestFromHtml($html, $profile);
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->outJson(['ok' => true, 'output' => $output, 'profile' => $profile]);
        } else {
            $this->outJson($data);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function webhookList(array $args, array $opts): int
    {
        $configPath = $this->optString($opts, 'config');
        $endpoints = (new WebhookEndpointStore($this->rootDir))->list($configPath);
        $data = [
            'ok' => true,
            'webhook_version' => WebhookDispatcher::VERSION,
            'config' => $configPath ?: $this->rootDir . '/config/webhooks.json',
            'endpoints_total' => count($endpoints),
            'endpoints' => $endpoints,
        ];
        $this->outJson($data);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function webhookTest(array $args, array $opts): int
    {
        $event = $this->optString($opts, 'event', 'scraperkit.test') ?? 'scraperkit.test';
        $url = $this->optString($opts, 'url') ?: $this->optString($opts, 'webhook-url');
        $output = $this->optString($opts, 'output') ?: $this->storagePath('webhooks/test-' . date('Ymd-His') . '.json');
        $payload = [
            'message' => 'MNB ScraperKit webhook test event',
            'version' => '3.5.0',
            'generated_at' => date(DATE_ATOM),
        ];
        $dispatcher = new WebhookDispatcher($this->safetyGuard());
        if ($url) {
            $result = $dispatcher->send($url, $event, $payload, $this->webhookHeadersFromOptions($opts), (int) ($this->optString($opts, 'timeout', '10') ?? '10'));
            $this->outJson($result);
            return ($result['ok'] ?? false) ? 0 : 2;
        }
        $result = $dispatcher->writeLocalEvent($output, $event, $payload);
        $this->outJson($result);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function webhookSend(array $args, array $opts): int
    {
        $payloadFile = $args[0] ?? $this->optString($opts, 'payload');
        if (!$payloadFile || !is_file($payloadFile)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper webhook:send <payload.json> --url=https://example.com/webhook [--event=name]');
        }
        $payload = json_decode((string) file_get_contents($payloadFile), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid webhook payload JSON file.');
        }
        $event = $this->optString($opts, 'event', (string) ($payload['event'] ?? 'scraperkit.event')) ?? 'scraperkit.event';
        $url = $this->optString($opts, 'url') ?: $this->optString($opts, 'webhook-url');
        $output = $this->optString($opts, 'output');
        $dispatcher = new WebhookDispatcher($this->safetyGuard());
        if ($url) {
            $result = $dispatcher->send($url, $event, $payload, $this->webhookHeadersFromOptions($opts), (int) ($this->optString($opts, 'timeout', '10') ?? '10'));
        } else {
            $result = $dispatcher->writeLocalEvent($output ?: $this->storagePath('webhooks/event-' . date('Ymd-His') . '.json'), $event, $payload);
        }
        $this->outJson($result);
        return ($result['ok'] ?? false) ? 0 : 2;
    }

    /** @param array<string,mixed> $opts @return array<string,string> */
    private function webhookHeadersFromOptions(array $opts): array
    {
        $headers = [];
        foreach ($this->stringList($opts['webhook-header'] ?? $opts['header'] ?? null) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }
        $secret = $this->optString($opts, 'webhook-secret');
        if ($secret) {
            $headers['X-MNB-ScraperKit-Secret'] = $secret;
        }
        return $headers;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function httpTest(array $args, array $opts): int
    {
        $url = $args[0] ?? null;
        if (!$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper http:test <url> [--engine=auto|curl|stream] [--json]');
        }
        if (isset($opts['engine']) && !isset($opts['http-engine'])) {
            $opts['http-engine'] = $opts['engine'];
        }
        $options = $this->crawlOptions($opts);
        $network = (new ExitPointManager((array) ($this->config['network']['profiles'] ?? [])))->select($options->networkProfile ?? (string) ($this->config['network']['default'] ?? 'direct'));
        $response = (new HttpClient($network, $this->safetyGuard()))->get($url, $options);

        $html = $response->body;
        $parser = new HtmlParser();
        $title = null;
        $textLength = 0;
        $linksCount = 0;
        $meta = [];
        try {
            if ($html !== '') {
                $doc = $parser->load($html, $response->finalUrl ?: $url);
                $title = $parser->title($doc);
                $textLength = mb_strlen($parser->text($doc));
                $linksCount = count($parser->links($doc, $response->finalUrl ?: $url));
                $meta = [
                    'description' => $parser->meta($doc, 'description'),
                    'robots' => $parser->meta($doc, 'robots'),
                    'canonical' => $parser->canonical($doc, $response->finalUrl ?: $url),
                ];
            }
        } catch (\Throwable) {
            // HTTP diagnostics should still work even if body is not HTML.
        }

        $protection = (new ProtectionPageDetector())->detect(
            $url,
            $response->finalUrl,
            $response->statusCode,
            $response->headers,
            $html,
            $title,
            '',
            [],
            $meta,
            $response->error
        );

        $statusCategory = $this->httpStatusCategory($response->statusCode, $response->error, (bool) ($protection['is_challenge'] ?? false));
        $data = [
            'url' => $url,
            'engine_requested' => strtolower((string) ($opts['http-engine'] ?? $opts['engine'] ?? 'auto')),
            'engine_used' => $response->engine,
            'curl_available' => function_exists('curl_init'),
            'stream_available' => (bool) filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
            'status_code' => $response->statusCode,
            'status_category' => $statusCategory,
            'final_url' => $response->finalUrl,
            'redirect_count' => $response->redirectCount,
            'redirect_history' => $response->redirectHistory,
            'response_time_ms' => $response->responseTimeMs,
            'body_bytes' => strlen($response->body),
            'content_type' => $response->header('content-type'),
            'server' => $response->header('server'),
            'title' => $title,
            'text_length' => $textLength,
            'links_count' => $linksCount,
            'meta' => $meta,
            'error' => $response->error,
            'protection' => $protection,
        ];
        if ($this->bool($opts, 'include-headers')) {
            $data['headers'] = $response->headers;
        }
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || !$output) {
            $this->outJson($data);
        } else {
            $this->out(sprintf('%s | HTTP %d | engine=%s | %s', $statusCategory, $response->statusCode, $response->engine, $response->finalUrl ?: $url));
            if ($response->error) {
                $this->out('Error: ' . $response->error);
            }
        }
        return ($response->error || $response->statusCode >= 400 || ($protection['is_challenge'] ?? false)) ? 2 : 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function robotsTest(array $args, array $opts): int
    {
        $url = $args[0] ?? null;
        if (!$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper robots:test <url>');
        }
        $options = $this->crawlOptions($opts);
        $network = (new ExitPointManager((array) ($this->config['network']['profiles'] ?? [])))->select($options->networkProfile ?? (string) ($this->config['network']['default'] ?? 'direct'));
        $decision = (new RobotsPolicy(new HttpClient($network, $this->safetyGuard())))->inspect($url, $options);
        if ($this->bool($opts, 'json')) {
            $this->outJson($decision->toArray());
        } else {
            $this->out(($decision->allowed ? 'ALLOWED' : 'BLOCKED') . ' - ' . $decision->reason);
            foreach ($decision->toArray() as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $this->out($k . ': ' . ($v === null ? 'null' : (string) $v));
                }
            }
        }
        return $decision->allowed ? 0 : 2;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function encodingTest(array $args, array $opts): int
    {
        $url = $args[0] ?? null;
        if (!$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper encoding:test <url>');
        }
        $options = $this->crawlOptions($opts);
        $network = (new ExitPointManager((array) ($this->config['network']['profiles'] ?? [])))->select($options->networkProfile ?? (string) ($this->config['network']['default'] ?? 'direct'));
        $response = (new HttpClient($network, $this->safetyGuard()))->get($url, $options);
        $detected = (new EncodingDetector((array) ($this->config['encoding'] ?? [])))->detect($response->body, $response->headers);
        $converted = (new EncodingConverter((array) ($this->config['encoding'] ?? [])))->toUtf8($response->body, $detected);
        $report = [
            'url' => $url,
            'final_url' => $response->finalUrl,
            'status_code' => $response->statusCode,
            'detected_encoding' => $detected,
            'body_bytes' => strlen($response->body),
            'utf8_text_bytes' => strlen($converted),
            'error' => $response->error,
        ];
        $this->outJson($report);
        return $response->error ? 1 : 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function commonExtract(array $args, array $opts): int
    {
        $url = $args[0] ?? null;
        if (!$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper common:extract <url> --type=emails --profile=journal');
        }
        $opts['url'] = $url;
        $opts['common-data'] = true;
        if (isset($opts['profile'])) {
            $opts['common-profile'] = $opts['profile'];
        }
        if (isset($opts['type'])) {
            $opts['common-type'] = $opts['type'];
        }
        $options = $this->crawlOptions($opts);
        $options->maxPages = 1;
        $options->maxDepth = 0;
        $result = (new Scraper($this->config, new Logger()))->crawl($url, $options);
        $page = $result->pages()[0] ?? null;
        $data = $page ? ($page->extracted['_common_data'] ?? []) : [];
        if ($this->bool($opts, 'include-page') && $page) {
            $data = ['common_data' => $data, 'page' => $page->toArray(false)];
        }
        $this->outJson($data);
        return 0;
    }

    /** @param array<string,mixed> $opts */
    private function commonTypes(array $opts): int
    {
        $data = [
            'types' => CommonDataExtractor::supportedTypes(),
            'profiles' => CommonDataExtractor::supportedProfiles(),
        ];
        if ($this->bool($opts, 'json')) {
            $this->outJson($data);
        } else {
            $this->out('Common data types:');
            foreach ($data['types'] as $type) {
                $this->out('  - ' . $type);
            }
            $this->out('Profiles:');
            foreach ($data['profiles'] as $profile) {
                $this->out('  - ' . $profile);
            }
        }
        return 0;
    }



    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function ruleAnalyze(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper rule:analyze <html-file|url> [--base-url=https://example.com]');
        }
        [$html, $baseUrl, $source] = $this->htmlInput($input, $opts);
        $data = (new RuleBuilderService())->analyze($html, $baseUrl);
        $data['source'] = $source;
        if ($this->bool($opts, 'json')) {
            $this->outJson($data);
            return 0;
        }
        $assistant = (array) ($data['assistant'] ?? []);
        $this->out('Rule analysis source: ' . $source);
        $this->out('Suggested profile: ' . (string) ($assistant['suggested_profile'] ?? 'seo') . ' confidence=' . (string) ($assistant['confidence'] ?? ''));
        $this->out('Title: ' . (string) ($data['title'] ?? ''));
        $this->out('Text length: ' . (string) ($data['text_length'] ?? 0));
        $this->out('Detected JSON-LD types: ' . implode(', ', (array) ($data['json_ld_types'] ?? [])));
        $this->out('Top keyword scores:');
        foreach (array_slice((array) ($data['keywords'] ?? []), 0, 5, true) as $name => $score) {
            $this->out(sprintf('  - %-12s %s', (string) $name, (string) $score));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function ruleGenerate(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper rule:generate <html-file|url> [--profile=auto] [--name=my-profile] [--output=config/profiles/my-profile.json]');
        }
        [$html, $baseUrl, $source] = $this->htmlInput($input, $opts);
        $profile = $this->optString($opts, 'profile', 'auto') ?? 'auto';
        $name = $this->optString($opts, 'name') ?: $this->optString($opts, 'profile-name');
        $schema = (new RuleBuilderService())->generateSchema($html, $baseUrl, $profile, $name);
        $schema['_source'] = $source;
        $output = $this->optString($opts, 'output');
        if ($output) {
            $path = $this->resolveOutputPath($output);
            $this->writeJson($path, $schema);
            $this->out('Generated profile schema: ' . $path);
            return 0;
        }
        $this->outJson($schema);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function ruleTest(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input');
        if (!$input) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper rule:test <html-file|url> --profile=ecommerce|--profile-file=file.json|--rules-file=rules.json');
        }
        [$html, $baseUrl, $source] = $this->htmlInput($input, $opts);
        $rules = $this->rulesFromOptions($opts);
        if ($rules === []) {
            throw new \InvalidArgumentException('No extraction rules found. Use --profile, --profile-file, --rules-file, or --rule=name=selector.');
        }
        $data = (new RuleBuilderService())->testRules($html, $rules, $baseUrl);
        $data['source'] = $source;
        $this->outJson($data);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function ruleDoctor(array $args, array $opts): int
    {
        $profile = $args[0] ?? $this->optString($opts, 'profile') ?? $this->optString($opts, 'profile-file');
        if (!$profile) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper rule:doctor <profile|profile.json> [--input=sample.html]');
        }
        $schema = $this->profileLoader()->load($profile);
        $html = null;
        $baseUrl = $this->optString($opts, 'base-url', 'https://example.com/') ?? 'https://example.com/';
        $input = $this->optString($opts, 'input');
        if ($input) {
            [$html, $baseUrl] = $this->htmlInput($input, $opts);
        }
        $data = (new RuleBuilderService())->doctor($schema, $html, $baseUrl);
        if ($this->bool($opts, 'json')) {
            $this->outJson($data);
            return ($data['valid_schema'] ?? false) ? 0 : 2;
        }
        $this->out('Profile rule doctor: ' . (string) ($data['profile'] ?? $profile));
        $this->out('Status: ' . (string) ($data['status'] ?? 'unknown'));
        $this->out('Rules: ' . (string) ($data['rules_total'] ?? 0));
        $this->out('Issues: ' . count((array) ($data['issues'] ?? [])) . ' Warnings: ' . count((array) ($data['warnings'] ?? [])));
        if (($data['missing_required_on_sample'] ?? []) !== []) {
            $this->out('Missing required on sample: ' . implode(', ', (array) $data['missing_required_on_sample']));
        }
        return ($data['valid_schema'] ?? false) ? 0 : 2;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function profileScaffold(array $args, array $opts): int
    {
        $name = $args[0] ?? $this->optString($opts, 'name');
        if (!$name) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper profile:scaffold <name> [--profile=seo] [--output=config/profiles/name.json]');
        }
        $profile = $this->optString($opts, 'profile', 'seo') ?? 'seo';
        $html = '<html><head><title>Sample</title><meta name="description" content="Sample"></head><body><h1>Sample</h1></body></html>';
        $schema = (new RuleBuilderService())->generateSchema($html, 'https://example.com/', $profile, $name);
        $output = $this->optString($opts, 'output', 'config/profiles/' . preg_replace('/[^a-z0-9_.-]+/i', '-', $name) . '.json');
        if ($output) {
            $path = $this->resolveOutputPath($output);
            $this->writeJson($path, $schema);
            $this->out('Scaffolded profile schema: ' . $path);
            return 0;
        }
        $this->outJson($schema);
        return 0;
    }

    /** @param array<string,mixed> $opts */
    private function profileList(array $opts): int
    {
        $items = $this->profileLoader()->list();
        if ($this->bool($opts, 'json')) {
            $this->outJson(['profiles' => $items, 'plugin_profiles' => count($this->pluginManager($opts)->profileFiles(true))]);
            return 0;
        }
        $this->out('Available profile schemas:');
        foreach ($items as $item) {
            $source = str_contains((string) ($item['path'] ?? ''), '/plugins/') || str_contains((string) ($item['path'] ?? ''), '\\plugins\\') ? 'plugin' : 'built-in';
            $this->out(sprintf('  %-20s record=%-12s required=%d rules=%d source=%s', $item['name'], (string) ($item['record_type'] ?? ''), $item['required_fields'], $item['extraction_rules'], $source));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function profileShow(array $args, array $opts): int
    {
        $profile = $args[0] ?? $this->optString($opts, 'profile');
        if (!$profile) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper profile:show <profile|profile.json>');
        }
        $schema = $this->profileLoader()->load($profile);
        if ($this->bool($opts, 'json')) {
            $this->outJson($schema->toArray());
            return 0;
        }
        $data = $schema->toArray();
        $this->out('Profile: ' . $data['profile']);
        $this->out('Record type: ' . $data['record_type']);
        $this->out('Required fields: ' . implode(', ', $data['required_fields']));
        $this->out('Optional fields: ' . implode(', ', $data['optional_fields']));
        $this->out('Dedupe keys: ' . implode(', ', $data['dedupe_keys']));
        $this->out('Export columns: ' . implode(', ', $data['export_columns']));
        $this->out('Extraction rules: ' . count($data['extraction_rules']));
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function profileValidate(array $args, array $opts): int
    {
        $profile = $args[0] ?? $this->optString($opts, 'profile');
        if (!$profile) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper profile:validate <profile|profile.json>');
        }
        $file = $this->profileLoader()->resolvePath($profile);
        $result = (new ProfileSchemaValidator())->validateFile($file);
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
            return $result['valid'] ? 0 : 2;
        }
        if ($result['valid']) {
            $this->out('Profile schema is valid: ' . $file);
            return 0;
        }
        $this->err('Profile schema has issues: ' . $file);
        foreach ($result['issues'] as $issue) {
            $this->err(sprintf('  - %s [%s] %s', $issue['field'], $issue['rule'], $issue['message']));
        }
        return 2;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function extractRules(array $args, array $opts): int
    {
        $url = $args[0] ?? null;
        if (!$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper extract:rules <url> --profile=ecommerce');
        }
        $rules = $this->rulesFromOptions($opts);
        if ($rules === []) {
            throw new \InvalidArgumentException('No extraction rules found. Use --profile, --profile-file, --rules-file, or --rule=name=selector.');
        }
        $options = $this->crawlOptions($opts);
        $options->maxPages = 1;
        $options->maxDepth = 0;
        $result = (new Scraper($this->config, new Logger()))->crawl($url, $options, $rules);
        $page = $result->pages()[0] ?? null;
        $data = [
            'url' => $url,
            'profile' => $this->optString($opts, 'profile') ?: $this->optString($opts, 'profile-file'),
            'rules_count' => count($rules),
            'fields' => $page ? array_filter((array) ($page->extracted ?? []), static fn ($v, $k): bool => !str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_BOTH) : [],
            'page' => $this->bool($opts, 'include-page') && $page ? $page->toArray(false) : null,
        ];
        if (!$this->bool($opts, 'include-page')) {
            unset($data['page']);
        }
        $this->outJson($data);
        return 0;
    }

    private function profileLoader(array $opts = []): ProfileSchemaLoader
    {
        return new ProfileSchemaLoader($this->rootDir . '/config/profiles', $this->pluginManager($opts)->profileFiles(true));
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function pluginList(array $args, array $opts): int
    {
        $manager = $this->pluginManager($opts);
        $plugins = array_map(static fn (PluginManifest $manifest): array => $manifest->toArray(false), $manager->list(!$this->bool($opts, 'all')));
        if ($this->bool($opts, 'json')) {
            $this->outJson(['plugin_dirs' => $manager->pluginDirs(), 'plugins' => $plugins]);
            return 0;
        }
        $this->out('Plugins:');
        if ($plugins === []) {
            $this->out('  No plugins found. Add plugins under plugins/ or storage/plugins/.');
            return 0;
        }
        foreach ($plugins as $plugin) {
            $this->out(sprintf(
                '  %-34s %-8s enabled=%s profiles=%d commands=%d',
                $plugin['plugin_id'],
                $plugin['version'],
                ($plugin['enabled'] ?? false) ? 'yes' : 'no',
                count((array) ($plugin['profiles'] ?? [])),
                count((array) ($plugin['commands'] ?? []))
            ));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function pluginShow(array $args, array $opts): int
    {
        $pluginId = $args[0] ?? $this->optString($opts, 'plugin-id');
        if (!$pluginId) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper plugin:show <plugin-id>');
        }
        $manifest = $this->pluginManager($opts)->get($pluginId);
        if (!$manifest instanceof PluginManifest) {
            throw new \RuntimeException('Plugin not found: ' . $pluginId);
        }
        if ($this->bool($opts, 'json')) {
            $this->outJson($manifest->toArray(true));
            return 0;
        }
        $data = $manifest->toArray(true);
        $this->out('Plugin: ' . $data['plugin_id']);
        $this->out('Name: ' . $data['name']);
        $this->out('Version: ' . $data['version']);
        $this->out('Enabled: ' . (($data['enabled'] ?? false) ? 'yes' : 'no'));
        $this->out('Description: ' . ($data['description'] ?? ''));
        $this->out('Manifest: ' . ($data['manifest_file'] ?? ''));
        $this->out('Profiles: ' . count((array) ($data['resolved_profiles'] ?? [])));
        foreach ((array) ($data['resolved_profiles'] ?? []) as $profile) {
            $this->out('  - ' . $profile);
        }
        $this->out('Command aliases: ' . count((array) ($data['commands'] ?? [])));
        foreach ((array) ($data['commands'] ?? []) as $command) {
            if (is_array($command)) {
                $this->out('  - ' . (string) ($command['name'] ?? '') . ' -> ' . (string) ($command['target'] ?? ''));
            }
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function pluginValidate(array $args, array $opts): int
    {
        $path = $args[0] ?? $this->optString($opts, 'file');
        if (!$path) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper plugin:validate <plugin-dir|mnb-plugin.json>');
        }
        $manager = $this->pluginManager($opts);
        $manifestFile = $manager->resolveManifestFile($path);
        $result = (new PluginValidator())->validateFile($manifestFile);
        if ($this->bool($opts, 'json')) {
            $this->outJson($result + ['manifest_file' => $manifestFile]);
            return $result['valid'] ? 0 : 2;
        }
        if ($result['valid']) {
            $this->out('Plugin manifest is valid: ' . $manifestFile);
            return 0;
        }
        $this->err('Plugin manifest has issues: ' . $manifestFile);
        foreach ($result['issues'] as $issue) {
            $this->err(sprintf('  - %s [%s] %s', $issue['field'], $issue['rule'], $issue['message']));
        }
        return 2;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function pluginInstall(array $args, array $opts): int
    {
        $path = $args[0] ?? $this->optString($opts, 'file');
        if (!$path) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper plugin:install <plugin-dir|mnb-plugin.json> [--plugin-id=id] [--force]');
        }
        $result = $this->pluginManager($opts)->install($path, $this->optString($opts, 'plugin-id'), $this->bool($opts, 'force'));
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
            return 0;
        }
        $this->out('Plugin installed: ' . $result['plugin_id']);
        $this->out('Destination: ' . $result['destination']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function pluginEnable(array $args, array $opts): int
    {
        $pluginId = $args[0] ?? $this->optString($opts, 'plugin-id');
        if (!$pluginId) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper plugin:enable <plugin-id>');
        }
        $result = $this->pluginManager($opts)->setEnabled($pluginId, true);
        $this->bool($opts, 'json') ? $this->outJson($result) : $this->out('Plugin enabled: ' . $result['plugin_id']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function pluginDisable(array $args, array $opts): int
    {
        $pluginId = $args[0] ?? $this->optString($opts, 'plugin-id');
        if (!$pluginId) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper plugin:disable <plugin-id>');
        }
        $result = $this->pluginManager($opts)->setEnabled($pluginId, false);
        $this->bool($opts, 'json') ? $this->outJson($result) : $this->out('Plugin disabled: ' . $result['plugin_id']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function pluginDoctor(array $args, array $opts): int
    {
        $result = $this->pluginManager($opts)->doctor();
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
            return $result['invalid'] === 0 ? 0 : 2;
        }
        $this->out(sprintf('Plugins checked: %d valid=%d invalid=%d', $result['checked'], $result['valid'], $result['invalid']));
        foreach ($result['plugins'] as $plugin) {
            $this->out('  - ' . ($plugin['manifest_file'] ?? '') . ' valid=' . (($plugin['valid'] ?? false) ? 'yes' : 'no'));
            foreach ((array) ($plugin['issues'] ?? []) as $issue) {
                if (is_array($issue)) {
                    $this->out(sprintf('      %s [%s] %s', $issue['field'] ?? '', $issue['rule'] ?? '', $issue['message'] ?? ''));
                }
            }
        }
        return $result['invalid'] === 0 ? 0 : 2;
    }

    /** @param array<string,mixed> $opts */
    private function pluginManager(array $opts = []): PluginManager
    {
        $dirs = [$this->rootDir . '/plugins', $this->rootDir . '/storage/plugins'];
        foreach ($this->stringList($opts['plugin-dir'] ?? $opts['plugins-dir'] ?? []) as $dir) {
            $dirs[] = $dir;
        }
        return new PluginManager($this->rootDir, array_values(array_unique($dirs)));
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function reportFailed(array $args, array $opts): int
    {
        $input = $args[0] ?? null;
        if (!$input) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper report:failed <crawl.json> --format=csv');
        }
        $crawl = (new CrawlJsonReader())->read($input);
        $rows = (new FailedUrlExtractor())->extract($crawl, $this->optString($opts, 'only-type'), $this->bool($opts, 'include-skipped'));
        $format = strtolower($this->optString($opts, 'format', 'json') ?? 'json');
        $output = $this->optString($opts, 'output') ?: $this->storagePath('failed-report-' . date('Ymd-His') . '.' . $this->extensionForFormat($format));
        (new RecordExportService())->export($rows, $output, $format);
        $this->out('Failed rows: ' . count($rows));
        $this->out('Output: ' . $output);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function reportSummary(array $args, array $opts): int
    {
        $jobDir = $args[0] ?? null;
        if (!$jobDir) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper report:summary <job-dir> --format=html');
        }
        $format = strtolower($this->optString($opts, 'format', 'html') ?? 'html');
        $output = $this->optString($opts, 'output') ?: rtrim((string) $jobDir, '/\\') . '/crawl-summary.' . $this->extensionForFormat($format);
        $report = (new ReportDataCollector())->collectFromJobDir((string) $jobDir);
        (new ReportExporter())->export($report, $output, $format);
        $this->out('Report: ' . $output);
        $this->out('Pages: ' . (string) ($report['counts']['pages_total'] ?? 0));
        $this->out('Records: ' . (string) ($report['counts']['records_total'] ?? 0));
        $this->out('Failures: ' . (string) ($report['counts']['pages_failed'] ?? 0));
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function bundleCreate(array $args, array $opts): int
    {
        $jobDir = $args[0] ?? null;
        if (!$jobDir) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper bundle:create <job-dir>');
        }
        $jobDir = rtrim((string) $jobDir, '/\\');
        $summaryHtml = $jobDir . '/crawl-summary.html';
        if (!is_file($summaryHtml)) {
            $report = (new ReportDataCollector())->collectFromJobDir($jobDir);
            (new ReportExporter())->export($report, $summaryHtml, 'html');
        }
        $output = $this->optString($opts, 'output') ?: $jobDir . '/mnb-scraperkit-bundle-' . date('Ymd-His') . '.zip';
        $result = (new ProjectBundleExporter())->create($jobDir, $output);
        $this->out('Bundle: ' . $output);
        $this->out('Files: ' . (string) ($result['files_total'] ?? 0));
        $this->out('Size: ' . (string) ($result['size_bytes'] ?? 0) . ' bytes');
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function pipelineRun(array $args, array $opts): int
    {
        $input = $args[0] ?? null;
        if (!$input) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper pipeline:run <crawl.json>');
        }
        $crawl = (new CrawlJsonReader())->read($input);
        $result = (new ProfessionalCrawlPipeline())->runFromCrawlArray($crawl, $this->pipelineOptionsFromCli($opts));
        $outDir = $this->optString($opts, 'output') ?: $this->storagePath('pipeline-' . date('Ymd-His'));
        (new PipelineExporter())->export($result, $outDir, $this->optString($opts, 'format', 'both') ?? 'both');
        $this->outJson($result->summary());
        $this->out('Output: ' . $outDir);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function retryFailed(array $args, array $opts): int
    {
        $input = $args[0] ?? null;
        if (!$input) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper retry:failed <crawl.json>');
        }
        $crawl = (new CrawlJsonReader())->read($input);
        $rows = (new FailedUrlExtractor())->extract($crawl, $this->optString($opts, 'only-type'), $this->bool($opts, 'include-skipped'));
        $output = $this->optString($opts, 'output') ?: $this->storagePath('retry-urls-' . date('Ymd-His') . '.txt');
        (new FailedUrlExtractor())->writeRetryUrlFile($rows, $output);
        $this->out('Retry URLs: ' . count($rows));
        $this->out('Output: ' . $output);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function exportRecords(array $args, array $opts): int
    {
        $input = $args[0] ?? null;
        if (!$input || !is_file($input)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper export:records <records.json> --format=csv');
        }
        $data = json_decode((string) file_get_contents($input), true);
        $records = $this->recordsFromData($data);
        $format = strtolower($this->optString($opts, 'format', 'csv') ?? 'csv');
        $output = $this->optString($opts, 'output') ?: preg_replace('/\.json$/i', '.' . $this->extensionForFormat($format), $input);
        if (!$output) {
            $output = $this->storagePath('records.' . $this->extensionForFormat($format));
        }
        (new RecordExportService())->export($records, $output, $format);
        $this->out('Records: ' . count($records));
        $this->out('Output: ' . $output);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function exportValidation(array $args, array $opts): int
    {
        $input = $args[0] ?? null;
        if (!$input || !is_file($input)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper export:validation <pipeline.json> --format=csv');
        }
        $data = json_decode((string) file_get_contents($input), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid pipeline JSON.');
        }
        $issues = [];
        if (isset($data['validation_issues']) && is_array($data['validation_issues'])) {
            $issues = array_values(array_filter($data['validation_issues'], 'is_array'));
        } else {
            foreach ($this->recordsFromData($data) as $index => $record) {
                $validation = is_array($record['validation'] ?? null) ? $record['validation'] : [];
                foreach ((array) ($validation['messages'] ?? $validation['issues'] ?? []) as $message) {
                    $issues[] = [
                        'index' => $index,
                        'record_id' => $record['record_id'] ?? null,
                        'record_key' => $record['record_key'] ?? $record['dedupe_key'] ?? null,
                        'status' => $validation['status'] ?? null,
                        'message' => is_array($message) ? json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $message,
                    ];
                }
            }
        }
        $format = strtolower($this->optString($opts, 'format', 'csv') ?? 'csv');
        $output = $this->optString($opts, 'output') ?: $this->storagePath('validation-report-' . date('Ymd-His') . '.' . $this->extensionForFormat($format));
        (new RecordExportService())->export($issues, $output, $format);
        $this->out('Validation issues: ' . count($issues));
        $this->out('Output: ' . $output);
        return count($issues) > 0 ? 2 : 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function validateRecords(array $args, array $opts): int
    {
        $input = $args[0] ?? null;
        if (!$input || !is_file($input)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper validate:records <records.json> --required-field=title');
        }
        $data = json_decode((string) file_get_contents($input), true);
        $records = $this->recordsFromData($data);
        $required = $this->stringList($this->opt($opts, 'required-field', $this->opt($opts, 'pipeline-required-field', [])));
        $issues = [];
        foreach ($records as $idx => $record) {
            foreach ($required as $field) {
                $value = $record[$field] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
                    $issues[] = ['index' => $idx, 'record_key' => $record['record_key'] ?? null, 'field' => $field, 'message' => 'Required field is missing.'];
                }
            }
        }
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $issues);
        }
        $this->out('Records: ' . count($records));
        $this->out('Issues: ' . count($issues));
        if ($output) {
            $this->out('Output: ' . $output);
        }
        return $issues === [] ? 0 : 2;
    }


    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function jobCreate(array $args, array $opts): int
    {
        $queue = $this->jobQueue();
        $source = strtolower($this->optString($opts, 'source', '') ?? '');
        $type = strtolower($this->optString($opts, 'type', '') ?? '');
        $target = $args[0] ?? $this->optString($opts, 'url') ?? $this->optString($opts, 'source-url') ?? $this->optString($opts, 'source-file') ?? $this->optString($opts, 'input') ?? $this->optString($opts, 'file');

        if ($type === '' && $source !== '') {
            $type = str_starts_with($source, 'source:') ? $source : 'source:' . $source;
        }
        if ($type === '') {
            $type = $this->optString($opts, 'file') || $this->optString($opts, 'source-file') ? 'bulk:crawl' : 'crawl';
        }

        $command = match ($type) {
            'sitemap', 'source:sitemap' => 'source:sitemap',
            'rss', 'atom', 'feed', 'source:rss' => 'source:rss',
            'csv', 'source:csv' => 'source:csv',
            'json', 'source:json' => 'source:json',
            'api', 'source:api' => 'source:api',
            'bulk', 'bulk-crawl', 'bulk:crawl' => 'bulk:crawl',
            'url-process', 'url:process', 'urls:process' => 'url:process',
            default => 'crawl',
        };

        if (!$target) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper job:create --type=crawl --url=https://example.com OR --source=sitemap https://example.com/sitemap.xml');
        }

        $jobOpts = $opts;
        foreach (['source', 'type', 'url', 'source-url', 'source-file', 'input', 'file', 'job-id', 'json'] as $drop) {
            unset($jobOpts[$drop]);
        }
        if ($this->optString($opts, 'profile')) {
            $jobOpts['profile'] = $this->optString($opts, 'profile');
        }
        if (!isset($jobOpts['job-dir'])) {
            $jobOpts['job-dir'] = $this->storagePath('jobs/' . ($this->optString($opts, 'job-id') ?: 'queued-' . date('Ymd-His')));
        }
        $browserJobOptions = $this->browserOptionsFromCli($opts);

        $job = $queue->create([
            'job_id' => $this->optString($opts, 'job-id'),
            'title' => $this->optString($opts, 'title', ucfirst(str_replace(':', ' ', $command)) . ' job'),
            'command' => $command,
            'args' => [(string) $target],
            'options' => $jobOpts,
            'source' => ['type' => $source ?: $type, 'target' => (string) $target],
            'profile' => $this->optString($opts, 'profile'),
            'browser' => $browserJobOptions->toArray(),
            'job_dir' => (string) ($jobOpts['job-dir'] ?? ''),
        ]);

        if ($this->bool($opts, 'json')) {
            $this->outJson($job);
        } else {
            $this->out('Created job: ' . (string) $job['job_id']);
            $this->out('State: ' . (string) $job['state']);
            $this->out('Command: ' . (string) $job['command']);
            $this->out('Queue: ' . $queue->queueDir());
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function jobList(array $args, array $opts): int
    {
        $queue = $this->jobQueue();
        $state = $this->optString($opts, 'state') ?: ($args[0] ?? null);
        $jobs = $queue->list($state);
        if ($this->bool($opts, 'json')) {
            $this->outJson(['queue_dir' => $queue->queueDir(), 'counts' => $queue->counts(), 'jobs' => $jobs]);
            return 0;
        }
        $this->out('Queue: ' . $queue->queueDir());
        foreach ($queue->counts() as $name => $count) {
            $this->out(sprintf('  %-10s %d', $name . ':', $count));
        }
        $this->out('');
        foreach ($jobs as $job) {
            $this->out(sprintf('  %-26s %-10s %-16s %s',
                (string) ($job['job_id'] ?? ''),
                (string) ($job['state'] ?? ''),
                (string) ($job['command'] ?? ''),
                (string) (($job['args'][0] ?? $job['source']['target'] ?? $job['title'] ?? ''))
            ));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function jobShow(array $args, array $opts): int
    {
        $id = $args[0] ?? null;
        if (!$id) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper job:show <job-id>');
        }
        $this->outJson($this->jobQueue()->load($id));
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function jobPause(array $args, array $opts): int
    {
        $id = $args[0] ?? null;
        if (!$id) { throw new \InvalidArgumentException('Usage: php bin/mnb-scraper job:pause <job-id>'); }
        $job = $this->jobQueue()->pause($id);
        $this->out('Paused job: ' . (string) $job['job_id']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function jobResume(array $args, array $opts): int
    {
        $id = $args[0] ?? null;
        if (!$id) { throw new \InvalidArgumentException('Usage: php bin/mnb-scraper job:resume <job-id>'); }
        $job = $this->jobQueue()->resume($id);
        $this->out('Resumed job: ' . (string) $job['job_id']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function jobCancel(array $args, array $opts): int
    {
        $id = $args[0] ?? null;
        if (!$id) { throw new \InvalidArgumentException('Usage: php bin/mnb-scraper job:cancel <job-id>'); }
        $job = $this->jobQueue()->cancel($id);
        $this->out('Cancelled job: ' . (string) $job['job_id']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function workerOnce(array $args, array $opts): int
    {
        $queue = $this->jobQueue();
        $job = $queue->nextPending();
        if ($job === null) {
            $this->out('No pending jobs.');
            return 0;
        }
        return $this->runQueuedJob((string) $job['job_id'], $opts);
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function workerRun(array $args, array $opts): int
    {
        $sleep = max(0, (int) $this->opt($opts, 'sleep', 5));
        $maxJobs = max(0, (int) $this->opt($opts, 'max-jobs', 0));
        $maxRuntime = max(0, (int) $this->opt($opts, 'max-runtime', $this->opt($opts, 'max-runtime-seconds', 0)));
        $memoryLimit = $this->parseBytes($this->optString($opts, 'memory-limit', '0') ?? '0');
        $started = time();
        $ran = 0;
        while (true) {
            if ($maxJobs > 0 && $ran >= $maxJobs) { break; }
            if ($maxRuntime > 0 && (time() - $started) >= $maxRuntime) { break; }
            if ($memoryLimit > 0 && memory_get_usage(true) >= $memoryLimit) {
                $this->err('Worker memory limit reached.');
                break;
            }
            $job = $this->jobQueue()->nextPending();
            if ($job === null) {
                if ($this->bool($opts, 'stop-when-empty')) { break; }
                sleep($sleep);
                continue;
            }
            $code = $this->runQueuedJob((string) $job['job_id'], $opts);
            $ran++;
            if ($code !== 0 && !$this->bool($opts, 'continue-on-error')) {
                return $code;
            }
        }
        $this->out('Worker finished. Jobs processed: ' . $ran);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function workerStatus(array $args, array $opts): int
    {
        $queue = $this->jobQueue();
        $data = ['queue_dir' => $queue->queueDir(), 'counts' => $queue->counts(), 'locks' => $queue->locks()];
        if ($this->bool($opts, 'json')) {
            $this->outJson($data);
        } else {
            $this->out('Queue: ' . $data['queue_dir']);
            foreach ($data['counts'] as $name => $count) {
                $this->out(sprintf('  %-10s %d', $name . ':', $count));
            }
            $this->out('Locks: ' . count($data['locks']));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function queueFailed(array $args, array $opts): int
    {
        $jobs = $this->jobQueue()->list('failed');
        if ($this->bool($opts, 'json')) {
            $this->outJson(['jobs' => $jobs]);
        } else {
            foreach ($jobs as $job) {
                $this->out(sprintf('  %-26s attempts=%s exit=%s %s',
                    (string) ($job['job_id'] ?? ''),
                    (string) ($job['attempts'] ?? 0),
                    (string) ($job['last_exit_code'] ?? ''),
                    (string) ($job['last_error'] ?? '')
                ));
            }
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function queueRetry(array $args, array $opts): int
    {
        $id = $args[0] ?? null;
        if (!$id) { throw new \InvalidArgumentException('Usage: php bin/mnb-scraper queue:retry <job-id>'); }
        $job = $this->jobQueue()->retry($id);
        $this->out('Retry scheduled: ' . (string) $job['job_id']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function queueRetryAll(array $args, array $opts): int
    {
        $jobs = $this->jobQueue()->retryAllFailed();
        $this->out('Retry scheduled jobs: ' . count($jobs));
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function queueClearFailed(array $args, array $opts): int
    {
        $count = $this->jobQueue()->clearFailed();
        $this->out('Failed jobs cleared: ' . $count);
        return 0;
    }


    private function scheduleStore(): LocalScheduleStore
    {
        return new LocalScheduleStore($this->rootDir);
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function retryPlan(array $args, array $opts): int
    {
        $input = $args[0] ?? $this->optString($opts, 'input') ?? $this->optString($opts, 'file');
        $rows = [];
        if ($this->bool($opts, 'failed-jobs')) {
            $rows = $this->jobQueue()->list('failed');
        } elseif ($input && is_file($input)) {
            $data = json_decode((string) file_get_contents($input), true);
            if (!is_array($data)) {
                throw new 
untimeException('Invalid JSON input for retry plan.');
            }
            $rows = $this->retryRowsFromData($data);
        } else {
            throw new 
untimeException('Usage: php bin/mnb-scraper retry:plan <crawl.json|failed-report.json> OR --failed-jobs');
        }
        $policy = $this->retryPolicyFromOptions($opts);
        $plan = $policy->plan($rows);
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $plan);
        }
        if ($this->bool($opts, 'json') || $output === null) {
            $this->outJson($plan);
        } else {
            $this->out('Retry plan: total=' . $plan['total'] . ' eligible=' . $plan['eligible'] . ' output=' . $output);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function queueRetrySafe(array $args, array $opts): int
    {
        $queue = $this->jobQueue();
        $policy = $this->retryPolicyFromOptions($opts);
        $dryRun = $this->bool($opts, 'dry-run');
        $retried = [];
        $skipped = [];
        foreach ($queue->list('failed') as $job) {
            $decision = $policy->decision($job);
            if (!empty($decision['retry_eligible'])) {
                if (!$dryRun) {
                    $retried[] = $queue->retry((string) ($job['job_id'] ?? ''));
                } else {
                    $retried[] = $job;
                }
            } else {
                $skipped[] = ['job_id' => $job['job_id'] ?? null, 'reason' => $decision['reason'], 'failure_type' => $decision['failure_type']];
            }
        }
        $result = ['ok' => true, 'dry_run' => $dryRun, 'retried' => count($retried), 'skipped' => count($skipped), 'skipped_jobs' => $skipped];
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
        } else {
            $this->out('Safe retry jobs: ' . count($retried));
            $this->out('Skipped jobs: ' . count($skipped));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function scheduleCreate(array $args, array $opts): int
    {
        $command = $this->optString($opts, 'command', $this->optString($opts, 'type', 'crawl')) ?? 'crawl';
        $target = $args[0] ?? $this->optString($opts, 'url') ?? $this->optString($opts, 'source-url') ?? $this->optString($opts, 'input') ?? $this->optString($opts, 'file');
        $argList = $this->stringList($this->opt($opts, 'arg', []));
        if ($target) {
            array_unshift($argList, (string) $target);
        }
        if ($argList === [] && !in_array($command, ['job:list', 'worker:status', 'monitor:summary'], true)) {
            throw new 
untimeException('Usage: php bin/mnb-scraper schedule:create --command=crawl https://example.com --every-minutes=60');
        }
        $interval = $this->scheduleIntervalSeconds($opts);
        $delay = max(0, (int) $this->opt($opts, 'delay-seconds', 0));
        $at = $this->optString($opts, 'at');
        $scheduleOpts = $opts;
        foreach (['command','type','url','source-url','input','file','arg','schedule-id','title','every-seconds','every-minutes','every-hours','delay-seconds','at','max-runs','json'] as $drop) {
            unset($scheduleOpts[$drop]);
        }
        $schedule = $this->scheduleStore()->create([
            'schedule_id' => $this->optString($opts, 'schedule-id'),
            'title' => $this->optString($opts, 'title', ucfirst(str_replace(':', ' ', $command)) . ' schedule'),
            'command' => $command,
            'args' => $argList,
            'options' => $scheduleOpts,
            'interval_seconds' => $interval,
            'delay_seconds' => $delay,
            'next_run_at' => $at ? date(DATE_ATOM, strtotime($at) ?: time()) : '',
            'max_runs' => max(0, (int) $this->opt($opts, 'max-runs', 0)),
        ]);
        if ($this->bool($opts, 'json')) {
            $this->outJson($schedule);
        } else {
            $this->out('Created schedule: ' . $schedule['schedule_id']);
            $this->out('Next run: ' . (string) $schedule['next_run_at']);
            $this->out('Schedules: ' . $this->scheduleStore()->scheduleDir());
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function scheduleList(array $args, array $opts): int
    {
        $store = $this->scheduleStore();
        $items = $store->list();
        if ($this->bool($opts, 'json')) {
            $this->outJson(['schedule_dir' => $store->scheduleDir(), 'schedules' => $items, 'due' => $store->due()]);
            return 0;
        }
        $this->out('Schedules: ' . $store->scheduleDir());
        foreach ($items as $item) {
            $this->out(sprintf('  %-28s %-7s next=%-25s %s',
                (string) ($item['schedule_id'] ?? ''),
                !empty($item['enabled']) ? 'enabled' : 'disabled',
                (string) ($item['next_run_at'] ?? ''),
                (string) ($item['command'] ?? '')
            ));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function scheduleShow(array $args, array $opts): int
    {
        $id = $args[0] ?? null;
        if (!$id) { throw new 
untimeException('Usage: php bin/mnb-scraper schedule:show <schedule-id>'); }
        $this->outJson($this->scheduleStore()->load($id));
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function scheduleRunDue(array $args, array $opts): int
    {
        $store = $this->scheduleStore();
        $queue = $this->jobQueue();
        $dryRun = $this->bool($opts, 'dry-run');
        $created = [];
        foreach ($store->due() as $schedule) {
            $jobPayload = [
                'title' => (string) ($schedule['title'] ?? 'Scheduled job'),
                'command' => (string) ($schedule['command'] ?? 'crawl'),
                'args' => is_array($schedule['args'] ?? null) ? array_values($schedule['args']) : [],
                'options' => is_array($schedule['options'] ?? null) ? $schedule['options'] : [],
                'schedule_id' => (string) ($schedule['schedule_id'] ?? ''),
                'source' => ['type' => 'schedule', 'target' => (string) ($schedule['schedule_id'] ?? '')],
            ];
            if ($dryRun) {
                $created[] = $jobPayload;
                continue;
            }
            $job = $queue->create($jobPayload);
            $store->markRun($schedule, (string) ($job['job_id'] ?? ''));
            $created[] = $job;
        }
        $result = ['ok' => true, 'dry_run' => $dryRun, 'due_count' => count($created), 'jobs' => $created];
        if ($this->bool($opts, 'json')) {
            $this->outJson($result);
        } else {
            $this->out('Due schedules enqueued: ' . count($created));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function scheduleEnable(array $args, array $opts): int
    {
        $id = $args[0] ?? null;
        if (!$id) { throw new 
untimeException('Usage: php bin/mnb-scraper schedule:enable <schedule-id>'); }
        $schedule = $this->scheduleStore()->setEnabled($id, true);
        $this->out('Enabled schedule: ' . (string) $schedule['schedule_id']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function scheduleDisable(array $args, array $opts): int
    {
        $id = $args[0] ?? null;
        if (!$id) { throw new 
untimeException('Usage: php bin/mnb-scraper schedule:disable <schedule-id>'); }
        $schedule = $this->scheduleStore()->setEnabled($id, false);
        $this->out('Disabled schedule: ' . (string) $schedule['schedule_id']);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function monitorSummary(array $args, array $opts): int
    {
        $ttl = max(1, (int) $this->opt($opts, 'stale-lock-ttl', $this->opt($opts, 'ttl-seconds', 900)));
        $snapshot = (new MonitoringSnapshot($this->rootDir))->collect($ttl);
        if ($this->bool($opts, 'json')) {
            $this->outJson($snapshot);
        } else {
            $this->out('Health: ' . $snapshot['health']);
            $this->out('Queue: ' . $snapshot['queue_dir']);
            foreach ($snapshot['queue_counts'] as $name => $count) {
                $this->out(sprintf('  %-10s %d', $name . ':', $count));
            }
            $this->out('Schedules: total=' . $snapshot['schedule_counts']['total'] . ' enabled=' . $snapshot['schedule_counts']['enabled'] . ' due=' . $snapshot['schedule_counts']['due']);
            $this->out('Locks: total=' . $snapshot['locks_total'] . ' stale=' . $snapshot['stale_locks_total']);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function monitorStaleLocks(array $args, array $opts): int
    {
        $ttl = max(1, (int) $this->opt($opts, 'ttl-seconds', $this->opt($opts, 'stale-lock-ttl', 900)));
        $snapshot = (new MonitoringSnapshot($this->rootDir))->collect($ttl);
        if ($this->bool($opts, 'json')) {
            $this->outJson(['ttl_seconds' => $ttl, 'stale_locks' => $snapshot['stale_locks']]);
        } else {
            $this->out('Stale locks: ' . count($snapshot['stale_locks']));
            foreach ($snapshot['stale_locks'] as $lock) {
                $this->out('  ' . (string) ($lock['job_id'] ?? '') . ' worker=' . (string) ($lock['worker_id'] ?? '') . ' heartbeat=' . (string) ($lock['heartbeat_at'] ?? ''));
            }
        }
        return count($snapshot['stale_locks']) > 0 ? 2 : 0;
    }

    /** @param array<string,mixed> $opts */
    private function retryPolicyFromOptions(array $opts): RetryPolicy
    {
        return new RetryPolicy(
            maxAttempts: max(1, (int) $this->opt($opts, 'max-attempts', 3)),
            baseDelaySeconds: max(0, (int) $this->opt($opts, 'retry-delay-seconds', $this->opt($opts, 'retry-delay', 60))),
            multiplier: max(1.0, (float) $this->opt($opts, 'backoff-multiplier', $this->opt($opts, 'backoff', 2.0))),
            maxDelaySeconds: max(0, (int) $this->opt($opts, 'max-delay-seconds', 3600))
        );
    }

    /** @param array<string,mixed> $data @return array<int,array<string,mixed>> */
    private function retryRowsFromData(array $data): array
    {
        foreach (['failed', 'failed_urls', 'failed_pages', 'items', 'rows', 'decisions'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        if (isset($data['pages']) && is_array($data['pages'])) {
            return array_values(array_filter($data['pages'], static fn($row): bool => is_array($row) && ((int) ($row['status_code'] ?? 0) >= 400 || !empty($row['error']) || !empty($row['failure_type']))));
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        return [$data];
    }

    /** @param array<string,mixed> $opts */
    private function scheduleIntervalSeconds(array $opts): int
    {
        if (isset($opts['every-seconds'])) {
            return max(0, (int) $this->opt($opts, 'every-seconds', 0));
        }
        if (isset($opts['every-minutes'])) {
            return max(0, (int) $this->opt($opts, 'every-minutes', 0)) * 60;
        }
        if (isset($opts['every-hours'])) {
            return max(0, (int) $this->opt($opts, 'every-hours', 0)) * 3600;
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function jobSummary(array $args, array $opts): int
    {
        $path = $args[0] ?? null;
        if (!$path) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper job:summary <job-dir>');
        }
        $summary = [];
        if (is_file((is_dir($path) ? rtrim($path, '/\\') . '/job-manifest.json' : $path))) {
            $summary['manifest'] = JobManifest::read($path);
        }
        $paths = [
            'crawl' => is_dir($path) ? rtrim($path, '/\\') . '/crawl.json' : null,
            'pipeline_summary' => is_dir($path) ? rtrim($path, '/\\') . '/pipeline/pipeline-summary.json' : null,
            'bulk_summary' => is_dir($path) ? rtrim($path, '/\\') . '/bulk-summary.json' : null,
        ];
        foreach ($paths as $key => $candidate) {
            if ($candidate && is_file($candidate)) {
                $summary[$key] = json_decode((string) file_get_contents($candidate), true);
            }
        }
        if ($summary === []) {
            throw new \RuntimeException('No job summary files found.');
        }
        $this->outJson($summary);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function jobRun(array $args, array $opts): int
    {
        $target = $args[0] ?? null;
        if (!$target) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper job:run <job-id|job.json>');
        }

        if (!is_file($target)) {
            return $this->runQueuedJob($target, $opts);
        }

        $job = json_decode((string) file_get_contents($target), true);
        if (!is_array($job)) {
            throw new \RuntimeException('Invalid job JSON.');
        }
        if (isset($job['queue_version'], $job['command'])) {
            $queue = $this->jobQueue();
            $loaded = $queue->create($job);
            return $this->runQueuedJob((string) $loaded['job_id'], $opts);
        }
        $type = (string) ($job['type'] ?? 'crawl');
        $url = (string) ($job['url'] ?? '');
        $sourceFile = (string) ($job['source_file'] ?? '');
        $jobOpts = is_array($job['options'] ?? null) ? $job['options'] : [];
        $merged = $this->normalizeJobOptions($jobOpts, $opts);
        if (!isset($merged['job-dir']) && isset($job['job_dir'])) {
            $merged['job-dir'] = (string) $job['job_dir'];
        }
        if ($type === 'bulk-crawl' || $type === 'bulk:crawl') {
            return $this->bulkCrawl([$sourceFile], $merged);
        }
        return $this->crawl([$url], $merged);
    }


    /** @param array<string,mixed> $opts */
    private function browserOptionsFromCli(array $opts): BrowserOptions
    {
        $data = [];
        foreach ($opts as $key => $value) {
            $data[str_replace('-', '_', (string) $key)] = $value;
        }
        if (array_key_exists('browser', $opts)) {
            $data['browser'] = $opts['browser'];
        }
        if (array_key_exists('browser-mode', $opts)) {
            $data['browser_mode'] = $opts['browser-mode'];
        }
        if ($this->bool($opts, 'force-browser')) {
            $data['force_browser'] = true;
        }
        if ($this->bool($opts, 'no-browser-fallback')) {
            $data['no_browser_fallback'] = true;
        }
        if ($this->bool($opts, 'browser-auto')) {
            $data['browser'] = 'auto';
        }
        $sessionName = $this->optString($opts, 'session') ?: $this->optString($opts, 'browser-session');
        if ($sessionName) {
            $store = $this->browserSessionStore();
            $session = $store->load($sessionName);
            $data['session'] = $session->name;
            $data['cookie_file'] = $session->cookieFile;
            $data['allowed_domains'] = $session->allowedDomains;
            $data['wait_selector'] ??= $session->waitSelector;
            $data['browser'] = $data['browser'] ?? $session->browserMode;
            $data['browser_mode'] = $data['browser_mode'] ?? $session->browserMode;
            $data['browser_timeout_ms'] = $data['browser_timeout_ms'] ?? $session->timeoutMs;
            $data['headless'] = $data['headless'] ?? $session->headless;
            $data['block_assets'] = $data['block_assets'] ?? $session->blockAssets;
        }
        return BrowserOptions::fromArray($data);
    }

    private function browserSessionStore(): BrowserSessionStore
    {
        return new BrowserSessionStore($this->rootDir);
    }

    /** @param array<string,mixed> $rules */
    private function browserCrawlResult(string $url, CrawlOptions $options, BrowserOptions $browserOptions, array $rules = []): CrawlResult
    {
        $service = new BrowserCrawlService($this->config);
        $rendered = $service->render($url, $options, $browserOptions);
        $crawlResult = new CrawlResult($url);
        $crawlResult->addPage($this->pageFromBrowserResult($rendered, $options, $rules));
        $crawlResult->finish();
        return $crawlResult;
    }

    /** @param array<string,mixed> $rules */
    private function pageFromBrowserResult(BrowserPageResult $rendered, CrawlOptions $options, array $rules = []): PageResult
    {
        $baseUrl = $rendered->finalUrl ?: $rendered->url;
        $parser = new HtmlParser();
        $doc = $parser->load($rendered->html, $baseUrl);
        $links = $parser->links($doc, $baseUrl);
        $text = $parser->text($doc) ?: $rendered->text;
        $title = $parser->title($doc) ?: $rendered->title;
        $meta = [
            'description' => $parser->meta($doc, 'description'),
            'keywords' => $parser->meta($doc, 'keywords'),
            'canonical' => $parser->canonical($doc, $baseUrl),
            'robots' => $parser->meta($doc, 'robots'),
        ];
        $extracted = [];
        if ($rules !== []) {
            $extracted = (new RuleBasedExtractor($parser, new UrlNormalizer()))->extract($doc, $rules, $baseUrl);
        }
        $presetData = (new PresetExtractor(new UrlNormalizer()))->extract($doc, $options->extractPreset, $baseUrl);
        if ($presetData !== []) {
            $extracted['_preset'] = $presetData;
        }
        if ($options->commonData) {
            $extracted['_common_data'] = (new CommonDataExtractor($parser, new UrlNormalizer()))->extract($doc, $baseUrl, $options->commonDataTypes, $options->commonDataProfile);
        }
        return new PageResult(
            url: $rendered->url,
            finalUrl: $rendered->finalUrl,
            rawFinalUrl: $rendered->finalUrl,
            statusCode: 200,
            title: $title,
            html: $rendered->html,
            text: $text,
            links: $links,
            meta: $meta,
            extracted: $extracted,
            contentHash: hash('sha256', $text ?: $rendered->html),
            error: $rendered->error,
            failureType: $rendered->error ? 'browser_render_failed' : null,
            depth: 0,
            responseTimeMs: $rendered->loadTimeMs,
            redirectCount: 0,
            protection: ['browser_assisted' => true, 'browser_metadata' => $rendered->metadata],
            httpEngine: 'browser:' . $rendered->engine,
        );
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function sourceDiscover(array $args, array $opts): int
    {
        $url = $args[0] ?? null;
        if (!$url) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper source:discover <url> [--json] [--output=file.json]');
        }
        $options = $this->crawlOptions($opts);
        $options->maxPages = 1;
        $network = (new ExitPointManager((array) ($this->config['network']['profiles'] ?? [])))->select($options->networkProfile ?? (string) ($this->config['network']['default'] ?? 'direct'));
        $data = (new FallbackSourceDiscovery(new HttpClient($network, $this->safetyGuard()), new ProtectionPageDetector()))->discover($url, $options, (int) $this->opt($opts, 'max', 20));
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || !$output) {
            $this->outJson($data);
        } else {
            $this->out('Candidates checked: ' . ($data['candidates_checked'] ?? 0));
            foreach (($data['results'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $this->out(sprintf('  %s | HTTP %s | challenge=%s | %s',
                    (string) ($row['url'] ?? ''),
                    (string) ($row['status_code'] ?? ''),
                    !empty($row['is_challenge']) ? 'yes' : 'no',
                    (string) ($row['recommendation'] ?? '')
                ));
            }
        }
        return 0;
    }



    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function sourceSitemap(array $args, array $opts): int
    {
        $source = $args[0] ?? null;
        if (!$source) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper source:sitemap <sitemap.xml|url> [--format=json|csv|txt] [--output=file]');
        }
        $data = (new SitemapReader($this->httpClient($opts)))->read(
            $source,
            $this->crawlOptions($opts),
            (int) $this->opt($opts, 'rows', $this->opt($opts, 'max', 1000)),
            (int) $this->opt($opts, 'max-sitemaps', 50)
        );
        return $this->outputSourceData($data, $opts, 'sitemap');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function sourceRss(array $args, array $opts): int
    {
        $feedUrl = $args[0] ?? $this->optString($opts, 'feed-url');
        if (!$feedUrl) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper source:rss <feed-url> [--format=json|csv|txt] [--output=file]');
        }
        $data = (new RssFeedReader($this->httpClient($opts)))->read($feedUrl, $this->crawlOptions($opts), (int) $this->opt($opts, 'rows', 100));
        return $this->outputSourceData($data, $opts, 'rss');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function sourceCsv(array $args, array $opts): int
    {
        $file = $args[0] ?? null;
        if (!$file) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper source:csv <file.csv> [--url-column=url] [--format=json|csv|txt]');
        }
        $data = (new CsvUrlSourceReader())->read(
            $file,
            $this->optString($opts, 'url-column', 'url') ?? 'url',
            (int) $this->opt($opts, 'rows', $this->opt($opts, 'max', 10000)),
            $this->optString($opts, 'delimiter', ',') ?? ','
        );
        return $this->outputSourceData($data, $opts, 'csv-source');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function sourceJson(array $args, array $opts): int
    {
        $file = $args[0] ?? null;
        if (!$file) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper source:json <file.json> [--path=items.*.url] [--format=json|csv|txt]');
        }
        $data = (new JsonUrlSourceReader())->read(
            $file,
            $this->optString($opts, 'path'),
            (int) $this->opt($opts, 'rows', $this->opt($opts, 'max', 10000))
        );
        return $this->outputSourceData($data, $opts, 'json-source');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function sourceApi(array $args, array $opts): int
    {
        $endpoint = $args[0] ?? null;
        if (!$endpoint) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper source:api <endpoint> [--path=data.*.url] [--header="Name: value"]');
        }
        $data = (new ApiSourceReader($this->httpClient($opts)))->read(
            $endpoint,
            $this->crawlOptions($opts),
            $this->optString($opts, 'path'),
            (int) $this->opt($opts, 'rows', $this->opt($opts, 'max', 10000)),
            $this->headersFromCli($opts)
        );
        return $this->outputSourceData($data, $opts, 'api-source');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function sourceUrls(array $args, array $opts): int
    {
        $input = $args[0] ?? null;
        if (!$input || !is_file($input)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper source:urls <source-output.json> [--output=urls.txt]');
        }
        $data = json_decode((string) file_get_contents($input), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid source connector JSON file.');
        }
        $urls = $this->urlsFromSourceData($data);
        $output = $this->optString($opts, 'output') ?: $this->storagePath('source-urls-' . date('Ymd-His') . '.txt');
        $this->writeTextLines($output, $urls);
        $this->out('URLs: ' . count($urls));
        $this->out('Output: ' . $output);
        return 0;
    }

    /** @param array<string,mixed> $opts */
    private function plosJournals(array $opts): int
    {
        $catalog = new PlosJournalCatalog();
        $data = [
            'source_type' => 'plos_journal_catalog',
            'note' => 'Local PLOS journal catalog for source connector workflows. Use plos:search for the official PLOS Search API.',
            'journals' => $catalog->all(),
        ];
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || $output) {
            if ($this->bool($opts, 'json')) {
                $this->outJson($data);
            }
            return 0;
        }
        foreach ($data['journals'] as $journal) {
            $this->out(sprintf('%-28s %-45s %s', (string) $journal['key'], (string) $journal['name'], (string) $journal['homepage']));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function plosSearch(array $args, array $opts): int
    {
        $rawQuery = $args[0] ?? $this->optString($opts, 'query', '*:*');
        $query = $this->buildPlosQuery((string) $rawQuery, $this->optString($opts, 'journal'), $this->bool($opts, 'solr'));
        $data = $this->plosApiClient($opts)->search($query, $this->crawlOptions($opts), [
            'rows' => (int) $this->opt($opts, 'rows', 25),
            'start' => (int) $this->opt($opts, 'start', 0),
            'fields' => $this->stringList($this->opt($opts, 'field', $this->opt($opts, 'fields', ['id','title_display','journal','publication_date','author_display','abstract','article_type','volume','issue']))),
            'sort' => $this->optString($opts, 'sort'),
            'fq' => $this->optString($opts, 'fq'),
        ]);
        $output = $this->optString($opts, 'output');
        $format = strtolower($this->optString($opts, 'format', $output && str_ends_with(strtolower($output), '.csv') ? 'csv' : 'json') ?? 'json');
        if ($format === 'csv') {
            $output ??= $this->storagePath('plos-search-' . date('Ymd-His') . '.csv');
            (new PipelineExporter())->exportCsv($data['records'], $output);
            $this->out('Records: ' . count($data['records']));
            $this->out('Output: ' . $output);
            return 0;
        }
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || !$output) {
            $this->outJson($data);
        } else {
            $this->out(sprintf('PLOS API records: %d / numFound: %d', (int) $data['records_returned'], (int) $data['num_found']));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function plosFeed(array $args, array $opts): int
    {
        $journal = $args[0] ?? $this->optString($opts, 'journal');
        $feedUrl = $this->optString($opts, 'feed-url');
        if (!$feedUrl) {
            if (!$journal) {
                throw new \InvalidArgumentException('Usage: php bin/mnb-scraper plos:feed <journal-key> [--rows=25]');
            }
            $meta = (new PlosJournalCatalog())->find((string) $journal);
            if (!$meta) {
                throw new \InvalidArgumentException('Unknown PLOS journal key/name. Run: php bin/mnb-scraper plos:journals');
            }
            $feedUrl = (string) ((array) ($meta['feed_candidates'] ?? []))[0];
        }
        $reader = new RssFeedReader($this->httpClient($opts));
        $data = $reader->read($feedUrl, $this->crawlOptions($opts), (int) $this->opt($opts, 'rows', 25));
        $data['journal'] = $journal;
        $output = $this->optString($opts, 'output');
        $format = strtolower($this->optString($opts, 'format', $output && str_ends_with(strtolower($output), '.csv') ? 'csv' : 'json') ?? 'json');
        if ($format === 'csv') {
            $output ??= $this->storagePath('plos-feed-' . date('Ymd-His') . '.csv');
            (new PipelineExporter())->exportCsv(is_array($data['records'] ?? null) ? $data['records'] : [], $output);
            $this->out('Records: ' . count(is_array($data['records'] ?? null) ? $data['records'] : []));
            $this->out('Output: ' . $output);
            return 0;
        }
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || !$output) {
            $this->outJson($data);
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function plosUrls(array $args, array $opts): int
    {
        $rawQuery = $args[0] ?? $this->optString($opts, 'query', '*:*');
        $types = $this->stringList($this->opt($opts, 'type', ['article']));
        if ($types === []) {
            $types = ['article'];
        }
        $query = $this->buildPlosQuery((string) $rawQuery, $this->optString($opts, 'journal'), $this->bool($opts, 'solr'));
        $data = $this->plosApiClient($opts)->search($query, $this->crawlOptions($opts), [
            'rows' => (int) $this->opt($opts, 'rows', 100),
            'start' => (int) $this->opt($opts, 'start', 0),
            'fields' => ['id','title_display','journal','publication_date','author_display','article_type'],
            'sort' => $this->optString($opts, 'sort'),
        ]);
        $urls = [];
        foreach ((array) ($data['records'] ?? []) as $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach ($types as $type) {
                $key = match (strtolower($type)) {
                    'article', 'html' => 'article_url',
                    'pdf' => 'pdf_url',
                    'xml', 'manuscript' => 'xml_url',
                    'doi' => 'doi_url',
                    default => null,
                };
                if ($key && !empty($record[$key])) {
                    $urls[] = (string) $record[$key];
                }
            }
        }
        $urls = array_values(array_unique($urls));
        $output = $this->optString($opts, 'output') ?: $this->storagePath('plos-urls-' . date('Ymd-His') . '.txt');
        $this->ensureDir(dirname($output));
        file_put_contents($output, implode(PHP_EOL, $urls) . ($urls ? PHP_EOL : ''));
        $this->out('URLs: ' . count($urls));
        $this->out('Output: ' . $output);
        return 0;
    }



    /** @param array<string,mixed> $opts */
    private function elsevierJournals(array $opts): int
    {
        $data = (new ElsevierJournalCatalog())->connectorInfo();
        $output = $this->optString($opts, 'output');
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || $output) {
            if ($this->bool($opts, 'json')) {
                $this->outJson($data);
            }
            return 0;
        }
        $this->out('Elsevier / ScienceDirect source connector catalog');
        $this->out('API key env: ELSEVIER_API_KEY');
        $this->out('Institution token env: ELSEVIER_INSTTOKEN (optional)');
        $this->out('');
        foreach ((array) ($data['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->out(sprintf('%-18s %-36s %s', (string) ($row['key'] ?? ''), (string) ($row['name'] ?? ''), (string) ($row['connector'] ?? '')));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function elsevierSearch(array $args, array $opts): int
    {
        $query = (string) ($args[0] ?? $this->optString($opts, 'query', 'all(science)'));
        $data = $this->elsevierApiClient($opts)->search($query, $this->crawlOptions($opts), [
            'rows' => (int) $this->opt($opts, 'rows', $this->opt($opts, 'count', 25)),
            'count' => (int) $this->opt($opts, 'count', $this->opt($opts, 'rows', 25)),
            'start' => (int) $this->opt($opts, 'start', 0),
            'view' => $this->optString($opts, 'view', 'STANDARD'),
            'content' => $this->optString($opts, 'content'),
            'sort' => $this->optString($opts, 'sort'),
            'api_key_in_query' => $this->bool($opts, 'api-key-in-query'),
        ]);
        return $this->outputRecordsData($data, $opts, 'elsevier-search');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function elsevierMetadata(array $args, array $opts): int
    {
        $identifier = (string) ($args[0] ?? $this->optString($opts, 'id', ''));
        if ($identifier === '') {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper elsevier:metadata <doi-or-pii> [--type=doi|pii] [--api-key=KEY]');
        }
        $type = strtolower($this->optString($opts, 'type', $this->looksLikeDoi($identifier) ? 'doi' : 'pii') ?? 'doi');
        $data = $this->elsevierApiClient($opts)->article($identifier, $type, $this->crawlOptions($opts), [
            'view' => $this->optString($opts, 'view'),
            'api_key_in_query' => $this->bool($opts, 'api-key-in-query'),
        ]);
        return $this->outputRecordsData($data, $opts, 'elsevier-metadata');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function elsevierDoi(array $args, array $opts): int
    {
        $doi = (string) ($args[0] ?? $this->optString($opts, 'doi', ''));
        if ($doi === '') {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper elsevier:doi <doi> [--api-key=KEY]');
        }
        $opts['type'] = 'doi';
        return $this->elsevierMetadata([$doi], $opts);
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function elsevierSerial(array $args, array $opts): int
    {
        $issn = (string) ($args[0] ?? $this->optString($opts, 'issn', ''));
        if ($issn === '') {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper elsevier:serial <issn> [--api-key=KEY]');
        }
        $data = $this->elsevierApiClient($opts)->serialByIssn($issn, $this->crawlOptions($opts), [
            'api_key_in_query' => $this->bool($opts, 'api-key-in-query'),
        ]);
        return $this->outputRecordsData($data, $opts, 'elsevier-serial');
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function elsevierUrls(array $args, array $opts): int
    {
        $query = (string) ($args[0] ?? $this->optString($opts, 'query', 'all(science)'));
        $types = $this->stringList($this->opt($opts, 'type', ['article']));
        if ($types === []) {
            $types = ['article'];
        }
        $data = $this->elsevierApiClient($opts)->search($query, $this->crawlOptions($opts), [
            'rows' => (int) $this->opt($opts, 'rows', $this->opt($opts, 'count', 100)),
            'count' => (int) $this->opt($opts, 'count', $this->opt($opts, 'rows', 100)),
            'start' => (int) $this->opt($opts, 'start', 0),
            'view' => $this->optString($opts, 'view', 'STANDARD'),
            'content' => $this->optString($opts, 'content'),
            'api_key_in_query' => $this->bool($opts, 'api-key-in-query'),
        ]);
        $urls = [];
        foreach ((array) ($data['records'] ?? []) as $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach ($types as $type) {
                $key = match (strtolower((string) $type)) {
                    'article', 'html' => 'article_url',
                    'doi' => 'doi_url',
                    'api', 'metadata' => 'api_article_url',
                    default => null,
                };
                if ($key && !empty($record[$key])) {
                    $urls[] = (string) $record[$key];
                }
            }
        }
        $urls = array_values(array_unique($urls));
        $output = $this->optString($opts, 'output') ?: $this->storagePath('elsevier-urls-' . date('Ymd-His') . '.txt');
        $this->ensureDir(dirname($output));
        file_put_contents($output, implode(PHP_EOL, $urls) . ($urls ? PHP_EOL : ''));
        $this->out('URLs: ' . count($urls));
        $this->out('Output: ' . $output);
        return 0;
    }

    /** @param array<string,mixed> $opts */
    private function elsevierApiClient(array $opts): ElsevierApiClient
    {
        $apiKey = $this->optString($opts, 'api-key') ?: getenv('ELSEVIER_API_KEY') ?: null;
        $instToken = $this->optString($opts, 'insttoken') ?: $this->optString($opts, 'inst-token') ?: getenv('ELSEVIER_INSTTOKEN') ?: null;
        $searchBase = $this->optString($opts, 'api-base', 'https://api.elsevier.com/content/search/sciencedirect') ?? 'https://api.elsevier.com/content/search/sciencedirect';
        $articleBase = $this->optString($opts, 'article-api-base', 'https://api.elsevier.com/content/article') ?? 'https://api.elsevier.com/content/article';
        $serialBase = $this->optString($opts, 'serial-api-base', 'https://api.elsevier.com/content/serial/title/issn') ?? 'https://api.elsevier.com/content/serial/title/issn';
        if (!$apiKey && !$this->bool($opts, 'no-api-key-required')) {
            throw new \InvalidArgumentException('Elsevier API key required. Pass --api-key=KEY or set ELSEVIER_API_KEY.');
        }
        return new ElsevierApiClient($this->httpClient($opts), $apiKey, $instToken, $searchBase, $articleBase, $serialBase);
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $opts */
    private function outputRecordsData(array $data, array $opts, string $prefix): int
    {
        $output = $this->optString($opts, 'output');
        $format = strtolower($this->optString($opts, 'format', $output && str_ends_with(strtolower($output), '.csv') ? 'csv' : 'json') ?? 'json');
        if ($format === 'csv') {
            $output ??= $this->storagePath($prefix . '-' . date('Ymd-His') . '.csv');
            (new PipelineExporter())->exportCsv(is_array($data['records'] ?? null) ? $data['records'] : [], $output);
            $this->out('Records: ' . count(is_array($data['records'] ?? null) ? $data['records'] : []));
            $this->out('Output: ' . $output);
            return 0;
        }
        if ($output) {
            $this->writeJson($output, $data);
            $this->out('Output: ' . $output);
        }
        if ($this->bool($opts, 'json') || !$output) {
            $this->outJson($data);
        } else {
            $this->out('Records: ' . count(is_array($data['records'] ?? null) ? $data['records'] : []));
            if (isset($data['num_found'])) {
                $this->out('Found: ' . (int) $data['num_found']);
            }
        }
        return 0;
    }

    private function looksLikeDoi(string $value): bool
    {
        return preg_match('~^10\.\d{4,9}/\S+$~i', trim($value)) === 1;
    }



    /** @param array<string,mixed> $data @param array<string,mixed> $opts */
    private function outputSourceData(array $data, array $opts, string $prefix): int
    {
        $format = strtolower($this->optString($opts, 'format', 'json') ?? 'json');
        $records = is_array($data['records'] ?? null) ? array_values(array_filter($data['records'], 'is_array')) : [];
        $output = $this->optString($opts, 'output');

        if ($format === 'txt' || $format === 'urls') {
            $output ??= $this->storagePath($prefix . '-urls-' . date('Ymd-His') . '.txt');
            $this->writeTextLines($output, $this->urlsFromSourceData($data));
            $this->out('URLs: ' . count($this->urlsFromSourceData($data)));
            $this->out('Output: ' . $output);
        } elseif ($format === 'csv') {
            $output ??= $this->storagePath($prefix . '-' . date('Ymd-His') . '.csv');
            (new PipelineExporter())->exportCsv($records, $output);
            $this->out('Records: ' . count($records));
            $this->out('Output: ' . $output);
        } else {
            $output ??= $this->storagePath($prefix . '-' . date('Ymd-His') . '.json');
            $this->writeJson($output, $data);
            if ($this->bool($opts, 'json')) {
                $this->outJson($data);
            } else {
                $this->out('Records: ' . count($records));
                $this->out('Output: ' . $output);
            }
        }

        if ($this->bool($opts, 'crawl')) {
            $urlFile = $this->storagePath($prefix . '-crawl-urls-' . date('Ymd-His') . '.txt');
            $this->writeTextLines($urlFile, $this->urlsFromSourceData($data));
            $this->out('Starting crawl from source URLs: ' . $urlFile);
            $crawlOpts = $opts;
            unset($crawlOpts['crawl'], $crawlOpts['output'], $crawlOpts['format'], $crawlOpts['json']);
            return $this->bulkCrawl([$urlFile], $crawlOpts);
        }

        return 0;
    }

    /** @param array<string,mixed> $data @return array<int,string> */
    private function urlsFromSourceData(array $data): array
    {
        $records = is_array($data['records'] ?? null) ? $data['records'] : [];
        $urls = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $url = (string) ($record['url'] ?? $record['loc'] ?? $record['link'] ?? '');
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $url) === 1) {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }

    /** @param array<int,string> $lines */

    /** @return array<int,string> */
    private function readUrlsForIntelligence(string $input): array
    {
        $content = (string) file_get_contents($input);
        $urls = [];
        $trim = ltrim($content);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $data = json_decode($content, true);
            $walk = static function (mixed $value) use (&$walk, &$urls): void {
                if (is_string($value) && preg_match('~^https?://~i', $value) === 1) {
                    $urls[] = $value;
                    return;
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $walk($item);
                    }
                }
            };
            $walk($data);
        } else {
            foreach (preg_split('/\R+/', $content) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '#')) {
                    $urls[] = $line;
                }
            }
        }
        return array_values(array_unique(array_filter($urls, static fn (string $url): bool => preg_match('~^https?://~i', $url) === 1)));
    }

    private function writeTextLines(string $path, array $lines): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, implode(PHP_EOL, $lines) . ($lines === [] ? '' : PHP_EOL));
    }

    /** @param array<string,mixed> $opts */
    private function httpClient(array $opts): HttpClient
    {
        $options = $this->crawlOptions($opts);
        $network = (new ExitPointManager((array) ($this->config['network']['profiles'] ?? [])))->select($options->networkProfile ?? (string) ($this->config['network']['default'] ?? 'direct'));
        return new HttpClient($network, $this->safetyGuard());
    }


    private function safetyGuard(): UrlSafetyGuard
    {
        return new UrlSafetyGuard((array) ($this->config['safety'] ?? []));
    }

    /** @param array<string,mixed> $opts */
    private function plosApiClient(array $opts): PlosApiClient
    {
        $base = $this->optString($opts, 'api-base', 'https://api.plos.org/search') ?? 'https://api.plos.org/search';
        return new PlosApiClient($this->httpClient($opts), $base);
    }

    private function buildPlosQuery(string $rawQuery, ?string $journal, bool $rawSolr): string
    {
        $rawQuery = trim($rawQuery) === '' ? '*:*' : trim($rawQuery);
        if (!$rawSolr && $rawQuery !== '*:*' && !preg_match('/\b[a-zA-Z_]+\s*:/', $rawQuery)) {
            $rawQuery = 'everything:"' . str_replace('"', '\\"', $rawQuery) . '"';
        }
        $clauses = [$rawQuery];
        if ($journal) {
            $meta = (new PlosJournalCatalog())->find($journal);
            $journalName = $meta ? (string) $meta['api_journal'] : $journal;
            $clauses[] = 'journal:"' . str_replace('"', '\\"', $journalName) . '"';
        }
        return count($clauses) === 1 ? $clauses[0] : '(' . implode(') AND (', $clauses) . ')';
    }


    private function jobQueue(): LocalJobQueue
    {
        return new LocalJobQueue($this->rootDir);
    }

    /** @param array<string,mixed> $workerOpts */
    private function runQueuedJob(string $jobId, array $workerOpts = []): int
    {
        $queue = $this->jobQueue();
        $job = $queue->load($jobId);
        $jobId = (string) ($job['job_id'] ?? $jobId);
        $workerId = $this->optString($workerOpts, 'worker-id', 'worker_' . getmypid()) ?? ('worker_' . getmypid());

        if (!$queue->acquireLock($jobId, $workerId)) {
            $this->err('Job is already locked: ' . $jobId);
            return 3;
        }

        try {
            $queue->markRunning($jobId, $workerId);
            $queue->heartbeat($jobId, $workerId);

            $command = (string) ($job['command'] ?? 'crawl');
            $jobArgs = is_array($job['args'] ?? null) ? array_values(array_map('strval', $job['args'])) : [];
            $jobOpts = is_array($job['options'] ?? null) ? $job['options'] : [];
            $jobOpts = array_merge($jobOpts, $this->workerForwardOptions($workerOpts));
            $argv = array_merge(['mnb-scraper', $command], $jobArgs, $this->optionsToArgv($jobOpts));
            $this->out('Running queued job: ' . $jobId . ' (' . $command . ')');
            $code = $this->run($argv);

            if ($code === 0) {
                $queue->markCompleted($jobId, $code);
                $this->out('Completed job: ' . $jobId);
            } else {
                $queue->markFailed($jobId, $code, 'Command exited with code ' . $code);
                $this->err('Failed job: ' . $jobId . ' exit=' . $code);
            }
            return $code;
        } catch (\Throwable $e) {
            $queue->markFailed($jobId, 1, $e->getMessage());
            $this->err('Failed job: ' . $jobId . ' ' . $e->getMessage());
            return 1;
        } finally {
            $queue->releaseLock($jobId);
        }
    }

    /** @param array<string,mixed> $workerOpts @return array<string,mixed> */
    private function workerForwardOptions(array $workerOpts): array
    {
        $allowed = [
            'browser', 'browser-mode', 'browser-profile', 'browser-engine', 'browser-auto',
            'force-browser', 'no-browser-fallback', 'wait-selector', 'wait-ms', 'wait-until',
            'browser-timeout-ms', 'viewport-width', 'viewport-height', 'screenshot',
            'rendered-html', 'save-rendered-html', 'block-assets', 'browser-output-dir',
            'fallback-min-text', 'fallback-required-field',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $workerOpts)) {
                $out[$key] = $workerOpts[$key];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $opts @return array<int,string> */
    private function optionsToArgv(array $opts): array
    {
        $argv = [];
        foreach ($opts as $key => $value) {
            $key = str_replace('_', '-', (string) $key);
            if ($value === null || $value === false) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    foreach ($this->optionsToArgv([$key => $item]) as $part) {
                        $argv[] = $part;
                    }
                }
                continue;
            }
            if ($value === true) {
                $argv[] = '--' . $key;
            } else {
                $argv[] = '--' . $key . '=' . (string) $value;
            }
        }
        return $argv;
    }

    private function parseBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        if (preg_match('/^(\d+)([KMG])?B?$/i', $value, $m) !== 1) {
            return (int) $value;
        }
        $num = (int) $m[1];
        $unit = strtoupper($m[2] ?? '');
        return match ($unit) {
            'G' => $num * 1024 * 1024 * 1024,
            'M' => $num * 1024 * 1024,
            'K' => $num * 1024,
            default => $num,
        };
    }

    private function unknown(string $command): int
    {
        $this->err('Unknown command: ' . $command);
        $this->err('Run: php bin/mnb-scraper list');
        return 1;
    }

    /** @param array<int,string> $argv @return array{command:string,args:array<int,string>,options:array<string,mixed>} */
    private function parse(array $argv): array
    {
        array_shift($argv);
        $command = (string) array_shift($argv);
        $args = [];
        $options = [];
        for ($i = 0; $i < count($argv); $i++) {
            $token = $argv[$i];
            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
                $value = true;
                if (str_contains($token, '=')) {
                    [$key, $value] = explode('=', $token, 2);
                } else {
                    $key = $token;
                    if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                        $value = $argv[++$i];
                    }
                }
                $key = str_replace('_', '-', trim((string) $key));
                $this->putOption($options, $key, $value);
            } elseif (str_starts_with($token, '-') && strlen($token) > 1) {
                $key = substr($token, 1);
                $this->putOption($options, $key, true);
            } else {
                $args[] = $token;
            }
        }
        return ['command' => $command, 'args' => $args, 'options' => $options];
    }

    /** @param array<string,mixed> $options */
    private function putOption(array &$options, string $key, mixed $value): void
    {
        if (isset($options[$key])) {
            if (!is_array($options[$key]) || array_is_list($options[$key]) === false) {
                $options[$key] = [$options[$key]];
            }
            $options[$key][] = $value;
        } else {
            $options[$key] = $value;
        }
    }

    /** @param array<string,mixed> $opts */
    private function crawlOptions(array $opts): CrawlOptions
    {
        $configDefaults = (array) ($this->config['crawl'] ?? []);
        $data = $configDefaults;
        $map = [
            'max-pages' => 'max_pages',
            'depth' => 'max_depth',
            'max-depth' => 'max_depth',
            'delay-ms' => 'delay_ms',
            'delay-jitter-ms' => 'delay_jitter_ms',
            'jitter-ms' => 'delay_jitter_ms',
            'pause-after-urls' => 'pause_after_urls',
            'pause-seconds' => 'pause_seconds',
            'cooldown-after-failures' => 'cooldown_after_failures',
            'cooldown-seconds' => 'cooldown_seconds',
            'timeout' => 'timeout_seconds',
            'timeout-seconds' => 'timeout_seconds',
            'http-engine' => 'http_engine',
            'engine' => 'http_engine',
            'user-agent' => 'user_agent',
            'network' => 'network_profile',
            'network-profile' => 'network_profile',
            'browser' => 'browser_profile',
            'browser-profile' => 'browser_profile',
            'preset' => 'extract_preset',
            'extract-preset' => 'extract_preset',
            'cookie-jar' => 'cookie_jar_path',
            'max-redirects' => 'max_redirects',
            'common-profile' => 'common_data_profile',
        ];
        foreach ($map as $cli => $cfg) {
            if (isset($opts[$cli])) {
                $data[$cfg] = $opts[$cli];
            }
        }
        if (isset($opts['allow-path'])) {
            $data['allow_path_patterns'] = $this->stringList($opts['allow-path']);
        }
        if (isset($opts['skip-url'])) {
            $data['skip_url_patterns'] = $this->stringList($opts['skip-url']);
        }
        if (isset($opts['skip-final-host'])) {
            $data['skip_final_host_patterns'] = $this->stringList($opts['skip-final-host']);
        }
        if (isset($opts['strip-final-param'])) {
            $data['final_url_strip_params'] = $this->stringList($opts['strip-final-param']);
        }
        if (isset($opts['common-type'])) {
            $data['common_data_types'] = $this->stringList($opts['common-type']);
        }
        $headers = $this->headersFromCli($opts);
        if ($headers !== []) {
            $data['request_headers'] = $headers;
        }
        foreach ([
            'same-domain' => 'same_domain',
            'verify-ssl' => 'verify_ssl',
            'common-data' => 'common_data',
            'stay-under-start-path' => 'stay_under_start_path',
            'same-final-host' => 'same_final_host',
            'skip-identity-provider-final-urls' => 'skip_identity_provider_final_urls',
            'strip-final-url-query-params' => 'strip_final_url_query_params',
            'use-cookie-jar' => 'use_cookie_jar',
            'skip-challenge-pages' => 'skip_challenge_pages',
            'fail-on-challenge' => 'fail_on_challenge_pages',
        ] as $cli => $cfg) {
            if (isset($opts[$cli])) {
                $data[$cfg] = $this->bool($opts, $cli);
            }
        }
        if (isset($opts['ignore-robots']) || isset($opts['no-robots'])) {
            $data['respect_robots'] = false;
        }
        if (isset($opts['no-cookie-jar'])) {
            $data['use_cookie_jar'] = false;
        }
        if (isset($opts['no-verify-ssl'])) {
            $data['verify_ssl'] = false;
        }
        if (isset($opts['do-not-skip-idp'])) {
            $data['skip_identity_provider_final_urls'] = false;
        }
        if (isset($opts['do-not-skip-challenge-pages'])) {
            $data['skip_challenge_pages'] = false;
        }
        if (!empty($data['common_data_profile'])) {
            $data['common_data'] = true;
        }
        if (!empty($data['common_data_types']) && $data['common_data_types'] !== ['all']) {
            $data['common_data'] = true;
        }
        if (empty($data['extract_preset']) && isset($opts['url']) && is_string($opts['url']) && str_contains($opts['url'], 'link.springer.com/journals/')) {
            $data['extract_preset'] = 'springer-journals';
        }
        return CrawlOptions::fromArray($data);
    }

    /** @param array<string,mixed> $opts */
    private function urlProcessOptionsFromCli(array $opts): UrlProcessOptions
    {
        $defaults = (array) ($this->config['url_processor'] ?? []);
        $success = $this->parseStatusSpec($this->optString($opts, 'success-status', (string) ($defaults['success_status'] ?? '200-399')) ?? '200-399');
        $retryDefault = implode(',', array_map('strval', (array) ($defaults['retry_statuses'] ?? [0, 408, 425, 429, 500, 502, 503, 504])));
        $retrySpec = $this->optString($opts, 'retry-status', $retryDefault) ?? $retryDefault;
        $retryStatuses = [];
        foreach (explode(',', $retrySpec) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $retryStatuses[] = (int) $part;
            }
        }
        if ($retryStatuses === []) {
            $retryStatuses = [0, 408, 425, 429, 500, 502, 503, 504];
        }

        $methods = $this->stringList($this->opt($opts, 'methods', $this->opt($opts, 'method', $defaults['methods'] ?? ['auto', 'curl', 'stream'])));
        if ($methods === []) {
            $methods = ['auto', 'curl', 'stream'];
        }

        return new UrlProcessOptions(
            methods: $methods,
            maxAttempts: max(0, (int) $this->opt($opts, 'max-attempts', $this->opt($opts, 'attempts', $defaults['max_attempts'] ?? 3))),
            untilSuccess: $this->bool($opts, 'until-success'),
            maxRuntimeSeconds: max(0, (int) $this->opt($opts, 'max-runtime-seconds', 0)),
            gapMs: max(0, (int) $this->opt($opts, 'gap-ms', $this->opt($opts, 'gap', $defaults['gap_ms'] ?? 0))),
            retryDelaySeconds: max(0, (int) $this->opt($opts, 'retry-delay-seconds', $this->opt($opts, 'retry-delay', $defaults['retry_delay_seconds'] ?? 5))),
            backoffMultiplier: max(1.0, (float) $this->opt($opts, 'backoff', $this->opt($opts, 'backoff-multiplier', $defaults['backoff_multiplier'] ?? 1.5))),
            maxDelaySeconds: max(1, (int) $this->opt($opts, 'max-delay-seconds', $defaults['max_delay_seconds'] ?? 300)),
            retryChallenge: $this->bool($opts, 'retry-challenge') || (bool) ($defaults['retry_challenge'] ?? false),
            stopOnChallenge: $this->bool($opts, 'do-not-stop-on-challenge') ? false : (bool) ($defaults['stop_on_challenge'] ?? true),
            saveBody: $this->bool($opts, 'save-body'),
            includeHeaders: $this->bool($opts, 'include-headers'),
            successStatuses: $success['statuses'],
            successRanges: $success['ranges'],
            retryStatuses: array_values(array_unique($retryStatuses)),
            stopFile: $this->optString($opts, 'stop-file'),
        );
    }

    /** @return array{statuses:array<int,int>,ranges:array<int,array{from:int,to:int}>} */
    private function parseStatusSpec(string $spec): array
    {
        $statuses = [];
        $ranges = [];
        foreach (explode(',', $spec) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('~^(\d{3})\s*-\s*(\d{3})$~', $part, $m) === 1) {
                $from = (int) $m[1];
                $to = (int) $m[2];
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                $ranges[] = ['from' => $from, 'to' => $to];
                continue;
            }
            if (ctype_digit($part)) {
                $statuses[] = (int) $part;
            }
        }
        if ($statuses === [] && $ranges === []) {
            $ranges[] = ['from' => 200, 'to' => 399];
        }
        return ['statuses' => array_values(array_unique($statuses)), 'ranges' => $ranges];
    }


    /** @param array<string,mixed> $opts */
    private function pipelineOptionsFromCli(array $opts): PipelineOptions
    {
        $data = [
            'required_fields' => $this->stringList($this->opt($opts, 'pipeline-required-field', $this->opt($opts, 'required-field', []))),
            'dedupe_keys' => $this->stringList($this->opt($opts, 'pipeline-dedupe-key', $this->opt($opts, 'dedupe-key', ['record_key']))),
            'min_quality' => (int) $this->opt($opts, 'pipeline-min-quality', $this->opt($opts, 'min-quality', 0)),
            'include_failed_pages' => $this->bool($opts, 'pipeline-include-failed') || $this->bool($opts, 'include-failed'),
            'include_skipped_pages' => $this->bool($opts, 'pipeline-include-skipped') || $this->bool($opts, 'include-skipped'),
            'prefer_preset_records' => !$this->bool($opts, 'no-preset-records'),
            'profile' => $this->optString($opts, 'pipeline-profile') ?: $this->optString($opts, 'profile') ?: $this->optString($opts, 'common-profile') ?: 'page',
            'preset' => $this->optString($opts, 'preset') ?: $this->optString($opts, 'extract-preset'),
            'field_map' => $this->fieldMapFromCli($opts),
        ];

        $profile = $this->optString($opts, 'profile-schema') ?: $this->optString($opts, 'profile-file') ?: (string) $data['profile'];
        if ($profile !== '') {
            try {
                $schema = $this->profileLoader()->load($profile);
                $schemaData = $schema->toPipelineArray();
                $data['profile'] = $schemaData['profile'];
                $data['record_type'] = $schemaData['record_type'];
                $data['required_fields'] = array_values(array_unique(array_merge((array) $schemaData['required_fields'], (array) $data['required_fields'])));
                $cliDedupe = (array) $data['dedupe_keys'];
                $data['dedupe_keys'] = $cliDedupe === ['record_key'] ? $schemaData['dedupe_keys'] : array_values(array_unique(array_merge((array) $schemaData['dedupe_keys'], $cliDedupe)));
                $data['transformations'] = array_replace((array) $schemaData['transformations'], (array) ($data['transformations'] ?? []));
                $data['validators'] = (array) $schemaData['validators'];
                $data['field_map'] = array_replace((array) $schemaData['field_map'], (array) $data['field_map']);
                $data['export_columns'] = (array) $schemaData['export_columns'];
            } catch (\Throwable) {
                // Optional: not every --profile value has a config/profiles schema.
            }
        }

        return PipelineOptions::fromArray($data);
    }

    /** @param array<string,mixed> $opts @return array<string,string> */
    private function fieldMapFromCli(array $opts): array
    {
        $items = $this->stringList($this->opt($opts, 'pipeline-field-map', $this->opt($opts, 'field-map', [])));
        $out = [];
        foreach ($items as $item) {
            $separator = str_contains($item, ':') ? ':' : (str_contains($item, '=') ? '=' : null);
            if ($separator === null) {
                continue;
            }
            [$from, $to] = explode($separator, $item, 2);
            $from = trim($from);
            $to = trim($to);
            if ($from !== '' && $to !== '') {
                $out[$from] = $to;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $opts @return array<string,mixed> */
    private function rulesFromOptions(array $opts): array
    {
        $rules = [];

        $profile = $this->optString($opts, 'profile-schema') ?: $this->optString($opts, 'profile-file') ?: $this->optString($opts, 'pipeline-profile') ?: $this->optString($opts, 'profile');
        if ($profile) {
            try {
                $schema = $this->profileLoader()->load($profile);
                $rules = array_replace($rules, $schema->extractionRules);
            } catch (\Throwable) {
                // A profile option may also mean common-data profile; extraction rules stay optional.
            }
        }

        $ruleOpts = $this->stringList($this->opt($opts, 'rule', []));
        foreach ($ruleOpts as $rule) {
            if (str_contains($rule, '=')) {
                [$name, $selector] = explode('=', $rule, 2);
                $name = trim($name);
                if ($name !== '') {
                    $rules[$name] = trim($selector);
                }
            }
        }
        $rulesFile = $this->optString($opts, 'rules-file');
        if ($rulesFile && is_file($rulesFile)) {
            $loaded = json_decode((string) file_get_contents($rulesFile), true);
            if (is_array($loaded)) {
                if (isset($loaded['extraction_rules']) && is_array($loaded['extraction_rules'])) {
                    $loaded = $loaded['extraction_rules'];
                }
                foreach ($loaded as $k => $v) {
                    if (is_scalar($v) || is_array($v)) {
                        $rules[(string) $k] = $v;
                    }
                }
            }
        }
        return $rules;
    }


    /** @param array<string,mixed> $opts @return array<string,string> */
    private function headersFromCli(array $opts): array
    {
        $headers = [];
        foreach ($this->stringList($this->opt($opts, 'header', [])) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name !== '' && $value !== '') {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private function httpStatusCategory(int $statusCode, ?string $error, bool $challenge): string
    {
        if ($challenge) {
            return 'challenge_or_protection';
        }
        if ($error) {
            return 'http_client_error';
        }
        return match (true) {
            $statusCode === 0 => 'no_response',
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode >= 300 && $statusCode < 400 => 'redirect',
            $statusCode === 401 => 'unauthorized',
            $statusCode === 403 => 'forbidden',
            $statusCode === 404 => 'not_found',
            $statusCode === 429 => 'rate_limited',
            $statusCode >= 400 && $statusCode < 500 => 'client_error',
            $statusCode >= 500 => 'server_error',
            default => 'unknown',
        };
    }


    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dbInit(array $args, array $opts): int
    {
        $config = $this->databaseConfig($opts);
        $pdo = (new DatabaseConnectionFactory())->connect($config);
        $result = (new DatabaseMigrator($pdo, $config->driver()))->migrate();
        $this->outJson([
            'ok' => true,
            'version' => '3.5.0',
            'driver' => $result['driver'],
            'statements_executed' => $result['statements'],
            'tables' => $result['tables'],
            'dsn' => $this->safeDsn($config->dsn),
        ]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dbTest(array $args, array $opts): int
    {
        $config = $this->databaseConfig($opts);
        $pdo = (new DatabaseConnectionFactory())->connect($config);
        $this->outJson([
            'ok' => true,
            'version' => '3.5.0',
            'driver' => $config->driver(),
            'pdo_driver' => (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
            'dsn' => $this->safeDsn($config->dsn),
            'available_pdo_drivers' => class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [],
        ]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dbStatus(array $args, array $opts): int
    {
        $config = $this->databaseConfig($opts);
        $pdo = (new DatabaseConnectionFactory())->connect($config);
        $counts = (new DatabaseRepository($pdo))->counts();
        $this->outJson([
            'ok' => true,
            'driver' => $config->driver(),
            'dsn' => $this->safeDsn($config->dsn),
            'counts' => $counts,
        ]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dbSaveCrawl(array $args, array $opts): int
    {
        $path = $args[0] ?? $this->optString($opts, 'input');
        if (!$path || !is_file($path)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper db:save-crawl <crawl.json> [--job-id=JOB] [--sqlite=path]');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid crawl JSON file.');
        }
        $jobUid = $this->optString($opts, 'job-id', 'db_' . date('Ymd_His')) ?? ('db_' . date('Ymd_His'));
        $config = $this->databaseConfig($opts);
        $pdo = (new DatabaseConnectionFactory())->connect($config);
        (new DatabaseMigrator($pdo, $config->driver()))->migrate();
        $repo = new DatabaseRepository($pdo);
        $repo->upsertJob($jobUid, 'crawl', 'stored', ['source_file' => $path], dirname($path));
        $count = $repo->saveCrawlArray($data, $jobUid);
        $this->outJson(['ok' => true, 'job_uid' => $jobUid, 'pages_saved' => $count, 'dsn' => $this->safeDsn($config->dsn)]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dbSavePipeline(array $args, array $opts): int
    {
        $path = $args[0] ?? $this->optString($opts, 'input');
        if (!$path || !is_file($path)) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper db:save-pipeline <records.json> [--job-id=JOB] [--sqlite=path]');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid pipeline JSON file.');
        }
        $jobUid = $this->optString($opts, 'job-id', 'db_' . date('Ymd_His')) ?? ('db_' . date('Ymd_His'));
        $config = $this->databaseConfig($opts);
        $pdo = (new DatabaseConnectionFactory())->connect($config);
        (new DatabaseMigrator($pdo, $config->driver()))->migrate();
        $repo = new DatabaseRepository($pdo);
        $repo->upsertJob($jobUid, 'pipeline', 'stored', ['source_file' => $path], dirname($path));
        $counts = $repo->savePipelineArray($data, $jobUid);
        $this->outJson(['ok' => true, 'job_uid' => $jobUid, 'saved' => $counts, 'dsn' => $this->safeDsn($config->dsn)]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function dbExport(array $args, array $opts): int
    {
        $table = $args[0] ?? $this->optString($opts, 'table', 'mnb_storage_records');
        $format = strtolower($this->optString($opts, 'format', 'json') ?? 'json');
        $limit = max(1, (int) ($this->optString($opts, 'limit', '100') ?? '100'));
        $output = $this->optString($opts, 'output');
        $config = $this->databaseConfig($opts);
        $pdo = (new DatabaseConnectionFactory())->connect($config);
        $rows = (new DatabaseRepository($pdo))->fetchRows((string) $table, $limit);

        if (!$output) {
            $output = $this->storagePath('database/export-' . (string) $table . '-' . date('Ymd-His') . '.' . ($format === 'csv' ? 'csv' : 'json'));
        }
        $this->ensureDir(dirname($output));
        if ($format === 'csv') {
            $fp = fopen($output, 'wb');
            if (!$fp) {
                throw new \RuntimeException('Unable to open output file.');
            }
            $headers = [];
            foreach ($rows as $row) {
                $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
            }
            if ($headers !== []) {
                fputcsv($fp, $headers);
                foreach ($rows as $row) {
                    fputcsv($fp, array_map(static fn (string $h): mixed => $row[$h] ?? null, $headers));
                }
            }
            fclose($fp);
        } else {
            $this->writeJson($output, ['table' => (string) $table, 'rows_total' => count($rows), 'rows' => $rows]);
        }
        $this->outJson(['ok' => true, 'table' => (string) $table, 'rows_exported' => count($rows), 'output' => $output]);
        return 0;
    }

    /** @param array<string,mixed> $opts @return array{manifest:array<string,mixed>,records:array<int,array<string,mixed>>,annotations:array<string,mixed>|null} */
    private function datasetContext(string $idOrPath, array $opts): array
    {
        $store = new DatasetStore($this->rootDir, $this->optString($opts, 'datasets-dir'));
        $manifest = $store->show($idOrPath);
        $records = $store->records($idOrPath);
        $annotationsFile = $this->optString($opts, 'annotations-file') ?: $this->optString($opts, 'annotations');
        if (!$annotationsFile) {
            $annotationsFile = rtrim((string) ($manifest['_dataset_dir'] ?? dirname((string) ($manifest['_manifest_file'] ?? ''))), '/\\') . '/' . (string) ($manifest['annotations_file'] ?? 'annotations.json');
        }
        $annotations = is_file((string) $annotationsFile) ? $this->readJsonFile((string) $annotationsFile) : null;
        return ['manifest' => $manifest, 'records' => $records, 'annotations' => $annotations];
    }

    /** @param array<string,mixed> $opts @return array<string,mixed>|null */
    private function optionalProfileSchema(array $opts): ?array
    {
        $profile = $this->optString($opts, 'profile-schema') ?: $this->optString($opts, 'profile-file') ?: $this->optString($opts, 'profile');
        if (!$profile) {
            return null;
        }
        try {
            return $this->profileLoader($opts)->load($profile)->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $opts */
    private function outputEvaluationReport(array $report, array $opts, string $defaultName): int
    {
        $format = strtolower($this->optString($opts, 'format', 'json') ?? 'json');
        $output = $this->optString($opts, 'output');
        if ($output) {
            (new EvaluationExporter())->export($report, $output, $format);
        }
        if ($this->bool($opts, 'json') || !$output || $format === 'json') {
            if ($output && $format !== 'json') {
                $this->outJson(['ok' => true, 'output' => $output, 'format' => $format, 'summary' => $report['summary'] ?? []]);
            } else {
                $this->outJson($output ? ($report + ['output' => $output]) : $report);
            }
            return 0;
        }
        $summary = (array) ($report['summary'] ?? []);
        $this->out(ucwords(str_replace('-', ' ', $defaultName)) . ': ' . $output);
        foreach (['records_total', 'valid_records', 'duplicate_records', 'average_quality_score', 'training_readiness_score', 'training_readiness_label'] as $key) {
            if (array_key_exists($key, $summary)) {
                $this->out('  ' . $key . ': ' . (string) $summary[$key]);
            }
        }
        return 0;
    }

    /** @return array<string,mixed> */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('JSON file not found: ' . $path);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON file: ' . $path);
        }
        return $data;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $meta */
    private function exportRows(array $rows, string $output, string $format, array $meta = []): void
    {
        $this->ensureDir(dirname($output));
        if ($format === 'csv') {
            $headers = [];
            foreach ($rows as $row) {
                $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
            }
            $fp = fopen($output, 'wb');
            if (!$fp) {
                throw new \RuntimeException('Unable to write CSV: ' . $output);
            }
            if ($headers !== []) {
                fputcsv($fp, $headers);
                foreach ($rows as $row) {
                    fputcsv($fp, array_map(static fn(string $h): mixed => is_array($row[$h] ?? null) ? json_encode($row[$h], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($row[$h] ?? ''), $headers));
                }
            }
            fclose($fp);
            return;
        }
        if ($format === 'jsonl') {
            file_put_contents($output, implode("\n", array_map(static fn(array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $rows)) . (count($rows) > 0 ? "\n" : ''));
            return;
        }
        $this->writeJson($output, $meta + ['rows_total' => count($rows), 'rows' => $rows]);
    }


    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function distributedDoctor(array $args, array $opts): int
    {
        $this->outJson($this->distributedManager($opts)->doctor());
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function distributedStatus(array $args, array $opts): int
    {
        $this->outJson($this->distributedManager($opts)->status());
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function distributedEnqueue(array $args, array $opts): int
    {
        $payload = [];
        $payloadFile = $this->optString($opts, 'payload-file') ?: $this->optString($opts, 'file') ?: $this->optString($opts, 'payload');
        if ($payloadFile && is_file($payloadFile)) {
            $data = json_decode((string) file_get_contents($payloadFile), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid JSON payload file: ' . $payloadFile);
            }
            $payload = $data;
        }
        $command = $this->optString($opts, 'command') ?: ($args[0] ?? null);
        if (!$command && $payload === []) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper distributed:enqueue --command=crawl --arg=https://example.com [--distributed-adapter=file|redis]');
        }
        if ($command) {
            $payload = array_replace($payload, [
                'job_id' => $this->optString($opts, 'job-id') ?: ($payload['job_id'] ?? null),
                'command' => (string) $command,
                'args' => $this->stringList($this->opt($opts, 'arg', array_slice($args, 1))),
                'options' => $this->distributedPayloadOptions($opts),
                'created_by' => 'distributed:enqueue',
            ]);
        }
        $job = $this->distributedManager($opts)->enqueue($payload);
        $this->outJson(['ok' => true, 'job' => $job]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function distributedReserve(array $args, array $opts): int
    {
        $workerId = $this->optString($opts, 'worker-id') ?: ('worker_' . getmypid());
        $job = $this->distributedManager($opts)->reserve($workerId);
        $this->outJson(['ok' => true, 'reserved' => $job?->toArray()]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function distributedAck(array $args, array $opts): int
    {
        $jobId = $args[0] ?? $this->optString($opts, 'job-id');
        if (!$jobId) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper distributed:ack <job-id> [--lease-id=...]');
        }
        $result = $this->distributedManager($opts)->ack((string) $jobId, $this->optString($opts, 'lease-id'));
        $this->outJson(['ok' => true, 'job' => $result]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function distributedFail(array $args, array $opts): int
    {
        $jobId = $args[0] ?? $this->optString($opts, 'job-id');
        if (!$jobId) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper distributed:fail <job-id> [--message=...] [--lease-id=...]');
        }
        $message = $this->optString($opts, 'message', $this->optString($opts, 'note', 'manual failure') ?? 'manual failure') ?? 'manual failure';
        $result = $this->distributedManager($opts)->fail((string) $jobId, $message, $this->optString($opts, 'lease-id'));
        $this->outJson(['ok' => true, 'job' => $result]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function distributedHeartbeat(array $args, array $opts): int
    {
        $jobId = $args[0] ?? $this->optString($opts, 'job-id');
        if (!$jobId) {
            throw new \InvalidArgumentException('Usage: php bin/mnb-scraper distributed:heartbeat <job-id> [--worker-id=...] [--lease-id=...]');
        }
        $workerId = $this->optString($opts, 'worker-id') ?: ('worker_' . getmypid());
        $result = $this->distributedManager($opts)->heartbeat((string) $jobId, $workerId, $this->optString($opts, 'lease-id'));
        $this->outJson(['ok' => true, 'job' => $result]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function distributedPurge(array $args, array $opts): int
    {
        if (!$this->bool($opts, 'force')) {
            throw new \InvalidArgumentException('Refusing to purge distributed queue without --force.');
        }
        $state = $this->optString($opts, 'state', 'all') ?? 'all';
        $this->outJson(['ok' => true, 'result' => $this->distributedManager($opts)->purge($state)]);
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function workerDistributed(array $args, array $opts): int
    {
        $manager = $this->distributedManager($opts);
        $workerId = $this->optString($opts, 'worker-id') ?: ('dist_worker_' . getmypid());
        $maxJobs = max(1, (int) $this->opt($opts, 'max-jobs', $this->bool($opts, 'once') ? 1 : 100));
        $sleep = max(0, (int) $this->opt($opts, 'sleep', 5));
        $stopWhenEmpty = $this->bool($opts, 'stop-when-empty') || $this->bool($opts, 'once');
        $processed = 0;
        $empty = 0;
        $started = time();
        $maxRuntime = max(0, (int) $this->opt($opts, 'max-runtime', $this->opt($opts, 'max-runtime-seconds', 0)));

        while ($processed < $maxJobs) {
            if ($maxRuntime > 0 && (time() - $started) >= $maxRuntime) {
                break;
            }
            $job = $manager->reserve($workerId);
            if (!$job instanceof DistributedJob) {
                $empty++;
                if ($stopWhenEmpty) {
                    break;
                }
                sleep($sleep);
                continue;
            }
            $exitCode = 0;
            try {
                $payload = $job->payload;
                $command = (string) ($payload['command'] ?? '');
                if ($command === '' || $command === 'worker:distributed') {
                    throw new \RuntimeException('Distributed job payload missing safe command.');
                }
                $argv = ['mnb-scraper', $command];
                foreach ((array) ($payload['args'] ?? []) as $arg) {
                    if (is_scalar($arg)) {
                        $argv[] = (string) $arg;
                    }
                }
                foreach ((array) ($payload['options'] ?? []) as $key => $value) {
                    $key = str_replace('_', '-', (string) $key);
                    if ($value === true) {
                        $argv[] = '--' . $key;
                    } elseif ($value === false || $value === null) {
                        continue;
                    } elseif (is_array($value)) {
                        foreach ($value as $item) {
                            if (is_scalar($item)) {
                                $argv[] = '--' . $key . '=' . (string) $item;
                            }
                        }
                    } else {
                        $argv[] = '--' . $key . '=' . (string) $value;
                    }
                }
                $manager->heartbeat($job->id, $workerId, (string) ($job->metadata['lease_id'] ?? ''));
                if ($this->bool($opts, 'dry-run')) {
                    $this->outJson(['dry_run' => true, 'job' => $job->toArray(), 'argv' => $argv]);
                    $manager->ack($job->id, (string) ($job->metadata['lease_id'] ?? ''));
                } else {
                    $exitCode = $this->run($argv);
                    if ($exitCode === 0) {
                        $manager->ack($job->id, (string) ($job->metadata['lease_id'] ?? ''));
                    } else {
                        $manager->fail($job->id, 'Command exited with code ' . $exitCode, (string) ($job->metadata['lease_id'] ?? ''));
                    }
                }
            } catch (\Throwable $e) {
                $exitCode = 1;
                $manager->fail($job->id, $e->getMessage(), (string) ($job->metadata['lease_id'] ?? ''));
            }
            $processed++;
            if ($exitCode !== 0 && $this->bool($opts, 'stop-on-error')) {
                break;
            }
        }
        $this->outJson(['ok' => true, 'worker_id' => $workerId, 'processed' => $processed, 'empty_polls' => $empty, 'status' => $manager->status()]);
        return 0;
    }

    /** @param array<string,mixed> $opts */
    private function distributedManager(array $opts): DistributedQueueManager
    {
        return new DistributedQueueManager(DistributedQueueConfig::fromArray([
            'adapter' => $this->optString($opts, 'distributed-adapter') ?: $this->optString($opts, 'adapter', 'auto'),
            'redis-url' => $this->optString($opts, 'redis-url'),
            'namespace' => $this->optString($opts, 'namespace') ?: $this->optString($opts, 'prefix', 'mnb_scraperkit'),
            'queue-name' => $this->optString($opts, 'queue-name') ?: $this->optString($opts, 'queue', 'default'),
            'worker-group' => $this->optString($opts, 'worker-group') ?: $this->optString($opts, 'group', 'default'),
            'visibility-timeout' => $this->optString($opts, 'visibility-timeout') ?: $this->optString($opts, 'lease-seconds', '300'),
            'heartbeat-ttl' => $this->optString($opts, 'heartbeat-ttl', '120'),
            'distributed-dir' => $this->optString($opts, 'distributed-dir'),
        ], $this->rootDir));
    }

    /** @param array<string,mixed> $opts @return array<string,mixed> */
    private function distributedPayloadOptions(array $opts): array
    {
        $skip = array_flip([
            'distributed-adapter','adapter','redis-url','namespace','prefix','queue-name','queue','worker-group','group','visibility-timeout','lease-seconds','heartbeat-ttl','distributed-dir','command','arg','payload-file','payload','file','json','force','state','worker-id','lease-id','max-jobs','sleep','once','stop-when-empty','dry-run'
        ]);
        $out = [];
        foreach ($opts as $key => $value) {
            if (!isset($skip[(string) $key])) {
                $out[(string) $key] = $value;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $opts */
    private function databaseConfig(array $opts): DatabaseConfig
    {
        return DatabaseConfig::fromArray([
            'database-url' => $this->optString($opts, 'database-url') ?? $this->optString($opts, 'dsn'),
            'sqlite' => $this->optString($opts, 'sqlite'),
            'db-user' => $this->optString($opts, 'db-user'),
            'db-pass' => $this->optString($opts, 'db-pass'),
        ], $this->rootDir);
    }

    private function safeDsn(string $dsn): string
    {
        return preg_replace('/(password|passwd|pwd)=([^;]+)/i', '$1=****', $dsn) ?? $dsn;
    }


    /** @param array<string,mixed> $opts @return array{0:string,1:string,2:string} */
    private function htmlInput(string $input, array $opts): array
    {
        $baseUrl = $this->optString($opts, 'base-url', 'https://example.com/') ?? 'https://example.com/';
        if (preg_match('#^https?://#i', $input)) {
            $options = $this->crawlOptions($opts);
            $options->maxPages = 1;
            $options->maxDepth = 0;
            $result = (new Scraper($this->config, new Logger()))->crawl($input, $options, []);
            $page = $result->pages()[0] ?? null;
            if (!$page instanceof PageResult) {
                throw new \RuntimeException('Could not fetch URL for rule builder: ' . $input);
            }
            $html = (string) ($page->html ?? '');
            if ($html === '') {
                throw new \RuntimeException('Fetched URL did not include HTML. Retry with --include-html or use a saved HTML file.');
            }
            return [$html, (string) ($page->finalUrl ?: $input), $input];
        }
        $path = is_file($input) ? $input : $this->rootDir . '/' . ltrim($input, '/\\');
        if (!is_file($path)) {
            throw new \RuntimeException('HTML input not found: ' . $input);
        }
        return [(string) file_get_contents($path), $baseUrl, $path];
    }

    private function resolveOutputPath(string $output): string
    {
        if (preg_match('#^([A-Za-z]:)?[\\\\/]#', $output)) {
            $path = $output;
        } else {
            $path = $this->rootDir . '/' . ltrim($output, '/\\');
        }
        $this->ensureDir(dirname($path));
        return $path;
    }

    /** @param array<string,mixed> $opts */
    private function opt(array $opts, string $key, mixed $default = null): mixed
    {
        return $opts[$key] ?? $default;
    }


    /** @param array<string,mixed> $opts */
    private function optString(array $opts, string $key, ?string $default = null): ?string
    {
        $v = $opts[$key] ?? $default;
        if (is_array($v)) {
            $v = end($v);
        }
        if ($v === true || $v === false || $v === null) {
            return $default;
        }
        $v = trim((string) $v);
        return $v === '' ? $default : $v;
    }


    /** @param array<string,mixed> $opts */
    private function bool(array $opts, string $key): bool
    {
        if (!array_key_exists($key, $opts)) {
            return false;
        }
        $v = $opts[$key];
        if (is_array($v)) {
            $v = end($v);
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true) || $v === '';
    }

    /** @return array<int,string> */
    private function stringList(mixed $value): array
    {
        if ($value === null || $value === false) {
            return [];
        }
        if (is_string($value)) {
            $value = str_contains($value, ',') ? explode(',', $value) : [$value];
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                foreach ($this->stringList($item) as $nested) {
                    $out[] = $nested;
                }
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    /** @param array<int,string> $urls @param array<int,array<string,mixed>> $results */
    private function writeCheckpoint(string $path, int $nextIndex, array $urls, array $results): void
    {
        $this->ensureDir(dirname($path));
        $completed = [];
        $failed = [];
        $skipped = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if (in_array($status, ['completed', 'success'], true)) {
                $completed[] = $url;
            } elseif (in_array($status, ['skipped'], true)) {
                $skipped[] = $url;
            } elseif ($status !== '') {
                $failed[] = $url;
            }
        }
        $pending = array_values(array_slice($urls, $nextIndex));
        $this->writeJson($path, [
            'checkpoint_version' => '3.5.0',
            'next_index' => $nextIndex,
            'urls_total' => count($urls),
            'updated_at' => date(DATE_ATOM),
            'queues' => [
                'pending' => $pending,
                'completed' => array_values(array_unique($completed)),
                'failed' => array_values(array_unique($failed)),
                'skipped' => array_values(array_unique($skipped)),
            ],
            'counts' => [
                'pending' => count($pending),
                'completed' => count(array_unique($completed)),
                'failed' => count(array_unique($failed)),
                'skipped' => count(array_unique($skipped)),
            ],
            'results' => $results,
        ]);
    }

    private function extensionForFormat(string $format): string
    {
        return match (strtolower($format)) {
            'csv' => 'csv',
            'xml' => 'xml',
            'html', 'htm' => 'html',
            'zip' => 'zip',
            default => 'json',
        };
    }

    private function storagePath(string $file): string
    {
        $path = $this->rootDir . '/storage/' . ltrim($file, '/\\');
        $this->ensureDir(dirname($path));
        return $path;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /** @param array<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<mixed> $data */
    private function outJson(array $data): void
    {
        $this->out(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<mixed>|null $data @return array<int,array<string,mixed>> */
    private function recordsFromData(?array $data): array
    {
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON records file.');
        }
        if (isset($data['records']) && is_array($data['records'])) {
            return array_values(array_filter($data['records'], 'is_array'));
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        return [$data];
    }

    /** @param array<string,mixed> $opts @return array<string,mixed> */
    private function publicOptions(array $opts): array
    {
        return $opts;
    }

    /** @param array<string,mixed> $jobOpts @param array<string,mixed> $cliOpts @return array<string,mixed> */
    private function normalizeJobOptions(array $jobOpts, array $cliOpts): array
    {
        $out = [];
        foreach ($jobOpts as $k => $v) {
            $out[str_replace('_', '-', (string) $k)] = $v;
        }
        return array_merge($out, $cliOpts);
    }

    private function out(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function err(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
