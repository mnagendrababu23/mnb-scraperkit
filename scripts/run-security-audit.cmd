@echo off
php "%~dp0..\bin\mnb-scraper" security:audit --format=html --output=storage/security-audit.html
