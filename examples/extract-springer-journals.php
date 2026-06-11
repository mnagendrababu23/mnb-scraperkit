<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\Scraper;

$config = require __DIR__ . '/../config/scraper.php';

$result = (new Scraper($config))->crawl(
    'https://link.springer.com/journals/a/1',
    CrawlOptions::fromArray([
        'max_pages' => 1,
        'max_depth' => 0,
        'delay_ms' => 500,
        'extract_preset' => 'springer-journals',
    ])
);

print json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
