@echo off
REM MNB ScraperKit V1.0.0 - run distributed worker loop
php "%~dp0..\bin\mnb-scraper" worker:distributed --distributed-adapter=auto --stop-when-empty %*
