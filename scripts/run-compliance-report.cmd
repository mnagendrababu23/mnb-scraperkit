@echo off
php "%~dp0..\bin\mnb-scraper" compliance:report --format=html --output=storage/compliance-report.html
