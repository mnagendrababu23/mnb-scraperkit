@echo off
REM MNB ScraperKit V3.8.0 - optional lightweight JSON API server
set HOST=%1
if "%HOST%"=="" set HOST=127.0.0.1
set PORT=%2
if "%PORT%"=="" set PORT=8787
php "%~dp0..\bin\mnb-scraper" api:serve --host=%HOST% --port=%PORT%
