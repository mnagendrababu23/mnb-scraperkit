@echo off
setlocal EnableExtensions
rem Windows CMD URL processing with retry/backoff.
rem Usage: scripts\run-url-process.cmd [url-file] [output-dir]
set "ROOT=%~dp0.."
set "BIN=%ROOT%\bin\mnb-scraper"
set "URLFILE=%~1"
set "OUTDIR=%~2"
if "%URLFILE%"=="" set "URLFILE=examples\crawl\sample-urls.txt"
if "%OUTDIR%"=="" set "OUTDIR=storage\url-process\cmd-process"
if not exist "%URLFILE%" (
  echo URL file not found: %URLFILE%
  exit /b 1
)
if not exist "%OUTDIR%" mkdir "%OUTDIR%" >nul 2>nul
if "%MNB_CRAWL_DRY_RUN%"=="1" (
  echo php "%BIN%" url:process "%URLFILE%" --methods=auto,curl,stream,cmd-curl,powershell --max-attempts=3 --gap-ms=1000 --retry-delay-seconds=5 --backoff=1.5 --output-dir="%OUTDIR%" --resume
  exit /b 0
)
php "%BIN%" url:process "%URLFILE%" --methods=auto,curl,stream,cmd-curl,powershell --max-attempts=3 --gap-ms=1000 --retry-delay-seconds=5 --backoff=1.5 --output-dir="%OUTDIR%" --resume
exit /b %ERRORLEVEL%
