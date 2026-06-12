$ErrorActionPreference = "Stop"
New-Item -ItemType Directory -Force storage/qa | Out-Null
php bin/mnb-scraper ai:providers --json
php bin/mnb-scraper search:discover "Springer journal 777" --input=examples/search/multi-provider-offline-results.json --filter-domain=link.springer.com --output=storage/qa/search-discovered.json
php bin/mnb-scraper search:to-seeds storage/qa/search-discovered.json --filter-domain=link.springer.com --output=storage/qa/search-seeds.txt
php bin/mnb-scraper mail:extract examples/mail/sample-webmail-export.json --extract=links,pdfs,text,attachments --query=Springer --output=storage/qa/mail-extracted.json
php bin/mnb-scraper mail:to-seeds storage/qa/mail-extracted.json --filter-domain=link.springer.com --output=storage/qa/mail-seeds.txt
php bin/mnb-scraper extract:components examples/extraction/qa-components.html --min-repeats=2 --output=storage/qa/components.json
php bin/mnb-scraper extract:recipe examples/extraction/qa-components.html --recipe=config/extraction/recipes/generic-page.json --output=storage/qa/recipe.json
php bin/mnb-scraper extract:quality storage/qa/recipe.json --required-field=title --output=storage/qa/quality.json
Write-Host "v1.0.3 QA smoke examples completed. Outputs written to storage/qa"
