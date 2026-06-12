@echo off
REM MNB ScraperKit V1.0.3 - run professional pipeline on existing crawl JSON
set CRAWL_JSON=%1
set OUTPUT_DIR=%2
if "%CRAWL_JSON%"=="" set CRAWL_JSON=storage\crawl.json
if "%OUTPUT_DIR%"=="" set OUTPUT_DIR=storage\pipeline-output
php bin\mnb-scraper pipeline:run "%CRAWL_JSON%" --output="%OUTPUT_DIR%" --format=both
