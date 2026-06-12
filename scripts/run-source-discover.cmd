@echo off
setlocal EnableExtensions
rem Usage: scripts\run-source-discover.cmd https://example.com [output]
set "ROOT=%~dp0.."
set "BIN=%ROOT%\bin\mnb-scraper"
set "URL=%~1"
set "OUTPUT=%~2"
if "%URL%"=="" (
  echo Usage: scripts\run-source-discover.cmd https://example.com [output]
  exit /b 1
)
if "%OUTPUT%"=="" set "OUTPUT=storage\source-discovery\discovery.json"
for %%I in ("%OUTPUT%") do if not exist "%%~dpI" mkdir "%%~dpI" >nul 2>nul
php "%BIN%" source:discover "%URL%" --output="%OUTPUT%" --json
exit /b %ERRORLEVEL%
