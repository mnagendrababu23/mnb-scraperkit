@echo off
REM MNB ScraperKit V1.0.2 - create a local webhook test event
php "%~dp0..\bin\mnb-scraper" webhook:test --event=scraperkit.test
