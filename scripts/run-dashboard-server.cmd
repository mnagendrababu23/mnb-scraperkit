@echo off
setlocal
set HOST=%1
if "%HOST%"=="" set HOST=127.0.0.1
set PORT=%2
if "%PORT%"=="" set PORT=8788
php bin\mnb-scraper dashboard:serve --host=%HOST% --port=%PORT%
