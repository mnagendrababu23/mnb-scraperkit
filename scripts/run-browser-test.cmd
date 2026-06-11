@echo off
REM MNB ScraperKit V1.9.0 browser fallback diagnostic example
php "%~dp0..\bin\mnb-scraper" browser:test %* --browser=auto
