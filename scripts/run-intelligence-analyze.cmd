@echo off
REM MNB ScraperKit V1.0.3 - export ML-ready features from crawl/pipeline JSON
php "%~dp0..\bin\mnb-scraper" intelligence:analyze %*
