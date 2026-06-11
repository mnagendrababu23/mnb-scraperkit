@echo off
REM MNB ScraperKit V1.2.0 - generate retry URL file from failed URLs
set CRAWL_JSON=%1
if "%CRAWL_JSON%"=="" set CRAWL_JSON=storage\crawl.json
php bin\mnb-scraper retry:failed "%CRAWL_JSON%" --output="storage\retry-urls.txt" --json-report="storage\failed-report.json"
