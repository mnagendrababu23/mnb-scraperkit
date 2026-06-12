@echo off
REM MNB ScraperKit V4.2.1 browser fallback diagnostic example
php "%~dp0..\bin\mnb-scraper" browser:test %* --browser=auto
