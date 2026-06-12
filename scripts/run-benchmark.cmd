@echo off
REM MNB ScraperKit V1.0.0 - local benchmark smoke test
php "%~dp0..\bin\mnb-scraper" benchmark:run %*
