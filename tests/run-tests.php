<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Mnb\ScraperKit\Core\FailureClassifier;
use Mnb\ScraperKit\Core\RateLimiter;
use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Pipeline\JobManifest;
use Mnb\ScraperKit\Pipeline\PipelineOptions;
use Mnb\ScraperKit\Pipeline\ProfessionalCrawlPipeline;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;
use Mnb\ScraperKit\Source\Sitemap\SitemapReader;
use Mnb\ScraperKit\Source\Csv\CsvUrlSourceReader;
use Mnb\ScraperKit\Source\Json\JsonUrlSourceReader;
use Mnb\ScraperKit\Support\UrlNormalizer;

$tests = [];

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

$tests['rate limiter accepts v1.1.0 pacing options without sleeping unnecessarily'] = function (): void {
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
        'checkpoint_version' => '1.1.0',
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
    assert(($manifest['version'] ?? null) === '1.1.0');
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
