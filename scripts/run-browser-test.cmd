@echo off
REM MNB ScraperKit V1.0.2 browser fallback diagnostic example
php "%~dp0..\bin\mnb-scraper" browser:test %* --browser=auto
