@echo off
REM MNB ScraperKit V3.5.0 - run local queue worker loop
php "%~dp0..\bin\mnb-scraper" worker:run --sleep=5 --max-jobs=10 --max-runtime=3600 %*
