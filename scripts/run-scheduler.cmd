@echo off
REM MNB ScraperKit V4.3.0 - enqueue due schedules and run queued jobs once
php "%~dp0..\bin\mnb-scraper" schedule:run-due
php "%~dp0..\bin\mnb-scraper" worker:run --stop-when-empty
