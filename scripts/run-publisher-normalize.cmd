@echo off
REM MNB ScraperKit V4.3.1 - normalize source records into article metadata
php "%~dp0..\bin\mnb-scraper" publisher:normalize %*
