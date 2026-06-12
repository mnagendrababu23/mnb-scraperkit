<?php

declare(strict_types=1);

/**
 * Copy this file to your Composer project root after running:
 *
 *     composer require mnb/scraperkit
 *
 * Then run:
 *
 *     php index-after-composer-install.php
 *
 * In a web project, rename it to index.php and open it through your local server.
 */

require __DIR__ . '/vendor/autoload.php';

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\Scraper;

$scraper = new Scraper();

$result = $scraper->crawl(
    'https://example.com',
    new CrawlOptions(
        maxPages: 1,
        maxDepth: 0,
        delayMs: 500,
    )
);

header('Content-Type: application/json');

echo json_encode(
    $result->toArray(false),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
