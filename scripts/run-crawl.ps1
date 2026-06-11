param(
    [Parameter(Mandatory=$true)][string]$Url,
    [int]$MaxPages = 100,
    [int]$Depth = 3,
    [string]$Network = "direct"
)

php bin/mnb-scraper crawl $Url --max-pages=$MaxPages --depth=$Depth --network=$Network --format=json
