<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\Scraper;

$config = require __DIR__ . '/../config/scraper.php';

$result = (new Scraper($config))->crawl(
    'https://example.com',
    new CrawlOptions(maxPages: 5, maxDepth: 1, delayMs: 500)
);

print_r($result->summary());
