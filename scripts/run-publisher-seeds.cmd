@echo off
REM MNB ScraperKit V4.3.0 - export academic publisher seed URLs
php "%~dp0..\bin\mnb-scraper" publisher:seeds --format=txt --output=storage\publisher-seeds.txt %*
