@echo off
REM MNB ScraperKit V4.3.1 - run local queue worker loop
php "%~dp0..\bin\mnb-scraper" worker:run --sleep=5 --max-jobs=10 --max-runtime=3600 %*
