param(
    [string]$UrlFile = "examples/springer-journal-list-urls.txt",
    [int]$MaxPages = 1,
    [int]$Depth = 0,
    [int]$GapMs = 2000,
    [int]$BatchSize = 5,
    [int]$BatchPause = 30
)

php bin/mnb-scraper bulk:crawl $UrlFile --max-pages=$MaxPages --depth=$Depth --gap-ms=$GapMs --batch-size=$BatchSize --batch-pause=$BatchPause --resume
