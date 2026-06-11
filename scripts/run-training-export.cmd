@echo off
REM MNB ScraperKit V3.5.0 - export training-ready dataset rows
php "%~dp0..\bin\mnb-scraper" dataset:export %* --training-ready
