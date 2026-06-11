<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\Scraper;

$config = require __DIR__ . '/../config/scraper.php';

$rules = [
    'h1' => 'h1',
    'all_links' => 'a::attr(href)[]',
    'meta_description' => 'meta[name="description"]::attr(content)',
];

$result = (new Scraper($config))->crawl(
    'https://example.com',
    new CrawlOptions(maxPages: 1, maxDepth: 0),
    $rules
);

print_r($result->toArray(false));
