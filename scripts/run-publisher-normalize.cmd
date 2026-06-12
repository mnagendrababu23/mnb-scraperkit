@echo off
REM MNB ScraperKit V4.2.0 - normalize source records into article metadata
php "%~dp0..\bin\mnb-scraper" publisher:normalize %*
