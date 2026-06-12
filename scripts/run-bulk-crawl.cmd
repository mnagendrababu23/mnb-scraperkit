@echo off
setlocal EnableExtensions
rem Friendly Windows CMD wrapper for safe/resumable bulk crawling.
rem Usage: scripts\run-bulk-crawl.cmd [url-file] [job-dir] [maxpages] [depth] [gapms]

set "ROOT=%~dp0.."
set "BIN=%ROOT%\bin\mnb-scraper"
set "URLFILE=%~1"
set "JOBDIR=%~2"
set "MAXPAGES=%~3"
set "DEPTH=%~4"
set "GAPMS=%~5"

if "%URLFILE%"=="" set "URLFILE=examples\crawl\sample-urls.txt"
if "%JOBDIR%"=="" set "JOBDIR=storage\bulk-crawls\cmd-bulk"
if "%MAXPAGES%"=="" set "MAXPAGES=1"
if "%DEPTH%"=="" set "DEPTH=0"
if "%GAPMS%"=="" set "GAPMS=2000"

if not exist "%URLFILE%" (
  echo URL file not found: %URLFILE%
  exit /b 1
)
if not exist "%JOBDIR%" mkdir "%JOBDIR%" >nul 2>nul

set "EXTRA=--resume"
if not "%MNB_CRAWL_PROFILE%"=="" set "EXTRA=%EXTRA% --profile=%MNB_CRAWL_PROFILE% --pipeline-profile=%MNB_CRAWL_PROFILE%"
if "%MNB_CRAWL_PIPELINE%"=="1" set "EXTRA=%EXTRA% --pipeline"
if "%MNB_CRAWL_IGNORE_ROBOTS%"=="1" set "EXTRA=%EXTRA% --ignore-robots"

if "%MNB_CRAWL_DRY_RUN%"=="1" (
  echo php "%BIN%" bulk:crawl "%URLFILE%" --max-pages=%MAXPAGES% --depth=%DEPTH% --gap-ms=%GAPMS% --batch-size=5 --batch-pause=30 --job-dir="%JOBDIR%" %EXTRA%
  exit /b 0
)

php "%BIN%" bulk:crawl "%URLFILE%" --max-pages=%MAXPAGES% --depth=%DEPTH% --gap-ms=%GAPMS% --batch-size=5 --batch-pause=30 --job-dir="%JOBDIR%" %EXTRA%
exit /b %ERRORLEVEL%
