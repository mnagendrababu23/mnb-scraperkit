@echo off
REM MNB ScraperKit V1.9.0 - create a local webhook test event
php "%~dp0..\bin\mnb-scraper" webhook:test --event=scraperkit.test
