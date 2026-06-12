@echo off
setlocal EnableExtensions
rem Friendly Windows CMD wrapper for a single safe crawl.
rem Usage: scripts\run-crawl.cmd https://example.com [output] [maxpages] [depth] [delayms]
rem Optional env flags:
rem   set MNB_CRAWL_PROFILE=academic
rem   set MNB_CRAWL_PIPELINE=1
rem   set MNB_CRAWL_COMMON_DATA=1
rem   set MNB_CRAWL_INCLUDE_HTML=1
rem   set MNB_CRAWL_IGNORE_ROBOTS=1
rem   set MNB_CRAWL_BROWSER=auto

set "ROOT=%~dp0.."
set "BIN=%ROOT%\bin\mnb-scraper"
set "URL=%~1"
set "OUTPUT=%~2"
set "MAXPAGES=%~3"
set "DEPTH=%~4"
set "DELAYMS=%~5"

if "%URL%"=="" (
  echo Usage: scripts\run-crawl.cmd https://example.com [output] [maxpages] [depth] [delayms]
  echo Example: scripts\run-crawl.cmd https://example.com storage\crawls\example\crawl.json 10 1 1000
  exit /b 1
)
if "%OUTPUT%"=="" set "OUTPUT=storage\crawls\single\crawl.json"
if "%MAXPAGES%"=="" set "MAXPAGES=25"
if "%DEPTH%"=="" set "DEPTH=1"
if "%DELAYMS%"=="" set "DELAYMS=1000"
if "%MNB_CRAWL_BROWSER%"=="" set "MNB_CRAWL_BROWSER=auto"

for %%I in ("%OUTPUT%") do if not exist "%%~dpI" mkdir "%%~dpI" >nul 2>nul

set "EXTRA="
if not "%MNB_CRAWL_PROFILE%"=="" set "EXTRA=%EXTRA% --profile=%MNB_CRAWL_PROFILE% --pipeline-profile=%MNB_CRAWL_PROFILE%"
if "%MNB_CRAWL_PIPELINE%"=="1" set "EXTRA=%EXTRA% --pipeline"
if "%MNB_CRAWL_COMMON_DATA%"=="1" set "EXTRA=%EXTRA% --common-data"
if "%MNB_CRAWL_INCLUDE_HTML%"=="1" set "EXTRA=%EXTRA% --include-html"
if "%MNB_CRAWL_IGNORE_ROBOTS%"=="1" set "EXTRA=%EXTRA% --ignore-robots"

if "%MNB_CRAWL_DRY_RUN%"=="1" (
  echo php "%BIN%" crawl "%URL%" --max-pages=%MAXPAGES% --depth=%DEPTH% --delay-ms=%DELAYMS% --browser=%MNB_CRAWL_BROWSER% --format=json --output="%OUTPUT%" %EXTRA%
  exit /b 0
)

php "%BIN%" crawl "%URL%" --max-pages=%MAXPAGES% --depth=%DEPTH% --delay-ms=%DELAYMS% --browser=%MNB_CRAWL_BROWSER% --format=json --output="%OUTPUT%" %EXTRA%
exit /b %ERRORLEVEL%
