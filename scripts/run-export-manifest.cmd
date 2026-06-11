@echo off
setlocal
set TARGET=%1
if "%TARGET%"=="" set TARGET=storage\jobs
php "%~dp0..\bin\mnb-scraper" export:manifest "%TARGET%"
endlocal
