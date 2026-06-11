@echo off
REM MNB ScraperKit V3.4.0 - create authorized browser session profile
php "%~dp0..\bin\mnb-scraper" browser:session-create client_portal --domain=example.com --login-url=https://example.com/login
