@echo off
REM MNB ScraperKit V4.1.1 - export academic publisher seed URLs
php "%~dp0..\bin\mnb-scraper" publisher:seeds --format=txt --output=storage\publisher-seeds.txt %*
