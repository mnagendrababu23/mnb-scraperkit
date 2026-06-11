@echo off
REM MNB ScraperKit V1.7.0 browser fallback diagnostic example
php "%~dp0..\bin\mnb-scraper" browser:test %* --browser=auto
