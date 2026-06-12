@echo off
REM MNB ScraperKit V4.1.1 - list academic publisher metadata targets
php "%~dp0..\bin\mnb-scraper" publisher:list --json %*
