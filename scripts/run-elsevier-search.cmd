@echo off
php "%~dp0..\bin\mnb-scraper" elsevier:search "machine learning" --rows=25 --json
