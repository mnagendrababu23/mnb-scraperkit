@echo off
setlocal
set ROOT=%~dp0..
cd /d "%ROOT%"

if "%MNB_ML_URLS%"=="" set MNB_ML_URLS=examples\ml\candidate-urls.txt
if "%MNB_ML_POSITIVE%"=="" set MNB_ML_POSITIVE=examples\ml\positive-urls.txt
if "%MNB_ML_NEGATIVE%"=="" set MNB_ML_NEGATIVE=examples\ml\negative-urls.txt
if "%MNB_ML_MODEL%"=="" set MNB_ML_MODEL=storage\ml\crawl-model.json
if "%MNB_ML_OUTPUT%"=="" set MNB_ML_OUTPUT=storage\ml\adaptive-plan.json
if "%MNB_ML_BUDGET%"=="" set MNB_ML_BUDGET=10
if "%MNB_ML_EXPLORE%"=="" set MNB_ML_EXPLORE=0.15
if "%MNB_ML_PROFILE%"=="" set MNB_ML_PROFILE=auto

if not "%MNB_ML_SKIP_TRAIN%"=="1" (
  php bin\mnb-scraper ml:train --positive="%MNB_ML_POSITIVE%" --negative="%MNB_ML_NEGATIVE%" --output="%MNB_ML_MODEL%"
)

php bin\mnb-scraper ml:adaptive-plan "%MNB_ML_URLS%" --model="%MNB_ML_MODEL%" --crawl-budget="%MNB_ML_BUDGET%" --explore-ratio="%MNB_ML_EXPLORE%" --profile="%MNB_ML_PROFILE%" --output="%MNB_ML_OUTPUT%"
echo Adaptive ML crawl plan written to %MNB_ML_OUTPUT%
