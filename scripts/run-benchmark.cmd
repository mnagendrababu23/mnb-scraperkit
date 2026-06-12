@echo off
REM MNB ScraperKit V4.3.0 - local benchmark smoke test
php "%~dp0..\bin\mnb-scraper" benchmark:run %*
