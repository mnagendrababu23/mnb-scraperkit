@echo off
REM Compare two MNB ScraperKit dataset snapshots.
php "%~dp0..\bin\mnb-scraper" dataset:diff %*
