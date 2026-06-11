@echo off
set URL=%1
set MAXPAGES=%2
set DEPTH=%3

if "%URL%"=="" (
  echo Usage: scripts\run-crawl.cmd https://example.com 100 3
  exit /b 1
)

if "%MAXPAGES%"=="" set MAXPAGES=100
if "%DEPTH%"=="" set DEPTH=3

php bin\mnb-scraper crawl "%URL%" --max-pages=%MAXPAGES% --depth=%DEPTH% --format=json
