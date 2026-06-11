param(
    [Alias("Input")][string]$CrawlJson = "storage/crawl.json",
    [Alias("Output")][string]$OutputDir = "storage/pipeline-output"
)
php bin/mnb-scraper pipeline:run $CrawlJson --output=$OutputDir --format=both
