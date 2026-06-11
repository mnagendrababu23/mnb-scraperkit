<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Cli;

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\CrawlResult;
use Mnb\ScraperKit\Core\HttpClient;
use Mnb\ScraperKit\Core\RobotsPolicy;
use Mnb\ScraperKit\Core\Scraper;
use Mnb\ScraperKit\Core\ProtectionPageDetector;
use Mnb\ScraperKit\Discovery\FallbackSourceDiscovery;
use Mnb\ScraperKit\Encoding\EncodingConverter;
use Mnb\ScraperKit\Encoding\EncodingDetector;
use Mnb\ScraperKit\Extractor\CommonDataExtractor;
use Mnb\ScraperKit\Network\ExitPointManager;
use Mnb\ScraperKit\Processing\SequentialUrlProcessor;
use Mnb\ScraperKit\Processing\UrlProcessOptions;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Pipeline\CrawlJsonReader;
use Mnb\ScraperKit\Pipeline\FailedUrlExtractor;
use Mnb\ScraperKit\Pipeline\JobManifest;
use Mnb\ScraperKit\Pipeline\PipelineExporter;
use Mnb\ScraperKit\Pipeline\PipelineOptions;
use Mnb\ScraperKit\Pipeline\ProfessionalCrawlPipeline;
use Mnb\ScraperKit\Profile\ProfileSchemaLoader;
use Mnb\ScraperKit\Profile\ProfileSchemaValidator;
use Mnb\ScraperKit\Queue\LocalJobQueue;
use Mnb\ScraperKit\Report\ProjectBundleExporter;
use Mnb\ScraperKit\Report\RecordExportService;
use Mnb\ScraperKit\Report\ReportDataCollector;
use Mnb\ScraperKit\Report\ReportExporter;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;
use Mnb\ScraperKit\Storage\CsvExporter;
use Mnb\ScraperKit\Storage\JsonExporter;
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
                'http:test' => $this->httpTest($args, $opts),
                'bulk:crawl' => $this->bulkCrawl($args, $opts),
                'url:process', 'urls:process' => $this->urlProcess($args, $opts),
                'robots:test' => $this->robotsTest($args, $opts),
                'encoding:test' => $this->encodingTest($args, $opts),
                'common:extract' => $this->commonExtract($args, $opts),
                'common:types' => $this->commonTypes($opts),
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
        $this->out('MNB ScraperKit 1.4.0 - Professional Symfony Console CLI');
        $this->out('Symfony Console front-end with framework-independent native PHP crawler and pipeline core.');
        $this->out('');
        return $this->listCommands();
    }

    private function listCommands(): int
    {
        $commands = [
            'crawl <url>' => 'Crawl one URL/site with rules, presets, common data, and optional pipeline.',
            'http:test <url>' => 'Test native PHP HTTP engines: auto, curl, or stream/file_get_contents.',
            'bulk:crawl <urls.txt>' => 'Crawl many URLs with gaps, pauses, checkpoint, and resume.',
            'url:process <urls.txt>' => 'Process URLs one by one with retry, method ladder, checkpoint, and resume.',
            'robots:test <url>' => 'Inspect robots.txt decision for one URL.',
            'encoding:test <url>' => 'Fetch one URL and report detected encoding.',
            'common:extract <url>' => 'Extract common data patterns from one URL.',
            'common:types' => 'List supported common data types and profiles.',
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

        $this->out('Starting crawl...');
        $result = (new Scraper($this->config, $logger))->crawl($url, $options, $rules);

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


    /** @param array<string,mixed> $opts */
    private function profileList(array $opts): int
    {
        $items = $this->profileLoader()->list();
        if ($this->bool($opts, 'json')) {
            $this->outJson(['profiles' => $items]);
            return 0;
        }
        $this->out('Available profile schemas:');
        foreach ($items as $item) {
            $this->out(sprintf('  %-16s record=%-12s required=%d rules=%d', $item['name'], (string) ($item['record_type'] ?? ''), $item['required_fields'], $item['extraction_rules']));
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

    private function profileLoader(): ProfileSchemaLoader
    {
        return new ProfileSchemaLoader($this->rootDir . '/config/profiles');
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

        $job = $queue->create([
            'job_id' => $this->optString($opts, 'job-id'),
            'title' => $this->optString($opts, 'title', ucfirst(str_replace(':', ' ', $command)) . ' job'),
            'command' => $command,
            'args' => [(string) $target],
            'options' => $jobOpts,
            'source' => ['type' => $source ?: $type, 'target' => (string) $target],
            'profile' => $this->optString($opts, 'profile'),
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
            'checkpoint_version' => '1.4.0',
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
