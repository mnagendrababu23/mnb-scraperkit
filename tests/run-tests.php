<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Mnb\ScraperKit\Pipeline\PipelineOptions;
use Mnb\ScraperKit\Pipeline\ProfessionalCrawlPipeline;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;
use Mnb\ScraperKit\Support\UrlNormalizer;

$tests = [];

$tests['url normalizer removes tracking params and resolves relative URLs'] = function (): void {
    $normalizer = new UrlNormalizer();
    $url = $normalizer->normalize('/path/page?utm_source=x&a=1', 'https://Example.com/base/index.html');
    assert($url === 'https://example.com/path/page?a=1', 'normalized URL mismatch: ' . (string) $url);
};

$tests['safety guard blocks localhost and private IP targets'] = function (): void {
    $guard = new UrlSafetyGuard();
    foreach (['http://localhost/', 'http://127.0.0.1/', 'http://10.0.0.1/', 'ftp://example.com/file'] as $url) {
        $blocked = false;
        try {
            $guard->assertAllowed($url);
        } catch (RuntimeException) {
            $blocked = true;
        }
        assert($blocked, 'expected blocked URL: ' . $url);
    }
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
