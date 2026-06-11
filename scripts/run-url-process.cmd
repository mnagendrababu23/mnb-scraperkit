@echo off
php bin\mnb-scraper url:process examples\url-process-test-urls.txt --methods=auto,curl,stream,cmd-curl --max-attempts=3 --gap-ms=1000 --resume
