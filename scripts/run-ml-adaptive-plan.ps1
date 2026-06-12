param(
    [string]$Urls = "examples/ml/candidate-urls.txt",
    [string]$Positive = "examples/ml/positive-urls.txt",
    [string]$Negative = "examples/ml/negative-urls.txt",
    [string]$Model = "storage/ml/crawl-model.json",
    [string]$Output = "storage/ml/adaptive-plan.json",
    [int]$CrawlBudget = 10,
    [double]$ExploreRatio = 0.15,
    [string]$Profile = "auto",
    [switch]$SkipTrain
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

if (-not $SkipTrain) {
    php bin/mnb-scraper ml:train --positive=$Positive --negative=$Negative --output=$Model
}

php bin/mnb-scraper ml:adaptive-plan $Urls --model=$Model --crawl-budget=$CrawlBudget --explore-ratio=$ExploreRatio --profile=$Profile --output=$Output
Write-Host "Adaptive ML crawl plan written to $Output"
