@echo off
REM MNB ScraperKit V3.7.0 - export ML-ready features from crawl/pipeline JSON
php "%~dp0..\bin\mnb-scraper" intelligence:analyze %*
