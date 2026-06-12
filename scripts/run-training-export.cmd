@echo off
REM MNB ScraperKit V1.0.2 - export training-ready dataset rows
php "%~dp0..\bin\mnb-scraper" dataset:export %* --training-ready
