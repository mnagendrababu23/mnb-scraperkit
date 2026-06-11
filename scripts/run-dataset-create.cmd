@echo off
REM Create a MNB ScraperKit dataset snapshot from crawl/pipeline/source/intelligence JSON.
php "%~dp0..\bin\mnb-scraper" dataset:create %*
