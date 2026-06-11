@echo off
REM MNB ScraperKit V3.4.0 - export training-ready dataset rows
php "%~dp0..\bin\mnb-scraper" dataset:export %* --training-ready
