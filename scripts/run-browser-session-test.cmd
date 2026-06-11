@echo off
REM MNB ScraperKit V3.8.0 - test authorized browser session
php "%~dp0..\bin\mnb-scraper" browser:session-test client_portal https://example.com/dashboard --browser=auto --json
