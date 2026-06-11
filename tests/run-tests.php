<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Mnb\ScraperKit\Browser\BrowserCrawlService;
use Mnb\ScraperKit\Browser\BrowserFallbackDetector;
use Mnb\ScraperKit\Browser\BrowserOptions;
use Mnb\ScraperKit\Browser\BrowserPageResult;
use Mnb\ScraperKit\Console\CommandRegistry;
use Mnb\ScraperKit\Cli\NativeCliApplication;
use Mnb\ScraperKit\Database\DatabaseConfig;
use Mnb\ScraperKit\Database\DatabaseSchema;
use Mnb\ScraperKit\Core\FailureClassifier;
use Mnb\ScraperKit\Core\PageResult;
use Mnb\ScraperKit\Core\RateLimiter;
use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Pipeline\JobManifest;
use Mnb\ScraperKit\Pipeline\PipelineOptions;
use Mnb\ScraperKit\Pipeline\ProfessionalCrawlPipeline;
use Mnb\ScraperKit\Profile\ProfileSchemaLoader;
use Mnb\ScraperKit\Profile\ProfileSchemaValidator;
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
use Mnb\ScraperKit\Safety\UrlSafetyGuard;
use Mnb\ScraperKit\Source\Sitemap\SitemapReader;
use Mnb\ScraperKit\Source\Csv\CsvUrlSourceReader;
use Mnb\ScraperKit\Source\Json\JsonUrlSourceReader;
use Mnb\ScraperKit\Support\UrlNormalizer;

$tests = [];


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

$tests['database command registry exposes v1.7.0 database commands and options'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['db:init', 'db:test', 'db:status', 'db:save-crawl', 'db:save-pipeline', 'db:export'] as $command) {
        assert(isset($commands[$command]), 'missing database command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['database-url', 'sqlite', 'db-user', 'db-pass', 'table', 'limit'] as $option) {
        assert(in_array($option, $options, true), 'missing database option: ' . $option);
    }
};

$tests['v1.7.0 command registry exposes retry scheduling and monitoring commands'] = function (): void {
    $commands = CommandRegistry::commands();
    foreach (['retry:plan', 'queue:retry-safe', 'schedule:create', 'schedule:list', 'schedule:show', 'schedule:run-due', 'schedule:enable', 'schedule:disable', 'monitor:summary', 'monitor:stale-locks'] as $command) {
        assert(isset($commands[$command]), 'missing v1.7.0 command: ' . $command);
    }
    $options = CommandRegistry::optionNames();
    foreach (['command', 'arg', 'schedule-id', 'every-minutes', 'every-hours', 'dry-run', 'failed-jobs', 'ttl-seconds'] as $option) {
        assert(in_array($option, $options, true), 'missing v1.7.0 option: ' . $option);
    }
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

$tests['rate limiter accepts v1.7.0 pacing options without sleeping unnecessarily'] = function (): void {
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
        'checkpoint_version' => '1.7.0',
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
    assert(($manifest['version'] ?? null) === '1.7.0');
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
        'version' => '1.7.0',
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
