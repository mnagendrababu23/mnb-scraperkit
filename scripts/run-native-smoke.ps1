$ErrorActionPreference = "Stop"

Write-Host "MNB ScraperKit native source-zip smoke test"
php bin/mnb-scraper list | Out-Null
php bin/mnb-scraper compat:commands --json | Out-Null
php bin/mnb-scraper release:check . --strict
php -d zend.assertions=1 -d assert.exception=1 tests/run-tests.php
Write-Host "Native smoke test completed."
