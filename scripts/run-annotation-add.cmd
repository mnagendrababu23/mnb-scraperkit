@echo off
REM Add one review annotation to a dataset annotation file.
php "%~dp0..\bin\mnb-scraper" annotation:add %*
