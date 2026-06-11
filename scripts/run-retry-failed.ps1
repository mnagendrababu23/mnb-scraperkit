param(
    [string]$CrawlJson = "storage/crawl.json",
    [string]$Output = "storage/retry-urls.txt"
)
php bin/mnb-scraper retry:failed $CrawlJson --output=$Output --json-report="storage/failed-report.json"
