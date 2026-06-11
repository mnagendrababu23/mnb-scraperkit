@echo off
REM MNB ScraperKit V4.0.1 - export training-ready dataset rows
php "%~dp0..\bin\mnb-scraper" dataset:export %* --training-ready
