@echo off
REM MNB ScraperKit V1.0.1 - local benchmark smoke test
php "%~dp0..\bin\mnb-scraper" benchmark:run %*
