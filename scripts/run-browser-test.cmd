@echo off
REM MNB ScraperKit V1.6.0 browser fallback diagnostic example
php "%~dp0..\bin\mnb-scraper" browser:test %* --browser=auto
