@echo off
REM MNB ScraperKit V4.2.0 - create a local webhook test event
php "%~dp0..\bin\mnb-scraper" webhook:test --event=scraperkit.test
