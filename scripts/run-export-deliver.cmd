@echo off
setlocal
set CONNECTOR=%1
if "%CONNECTOR%"=="" set CONNECTOR=local_exports
set TARGET=%2
if "%TARGET%"=="" set TARGET=storage\jobs
php "%~dp0..\bin\mnb-scraper" export:deliver %CONNECTOR% --dir="%TARGET%"
endlocal
