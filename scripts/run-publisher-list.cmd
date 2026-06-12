@echo off
REM MNB ScraperKit V1.0.3 - list academic publisher metadata targets
php "%~dp0..\bin\mnb-scraper" publisher:list --json %*
