@echo off
set URLFILE=%1
if "%URLFILE%"=="" set URLFILE=examples\springer-journal-list-urls.txt
php bin\mnb-scraper bulk:crawl "%URLFILE%" --max-pages=1 --depth=0 --gap-ms=2000 --batch-size=5 --batch-pause=30 --resume
