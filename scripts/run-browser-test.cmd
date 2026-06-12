@echo off
REM MNB ScraperKit V1.0.3 browser fallback diagnostic example
php "%~dp0..\bin\mnb-scraper" browser:test %* --browser=auto
