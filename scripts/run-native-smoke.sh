#!/usr/bin/env bash
set -euo pipefail

echo "MNB ScraperKit native source-zip smoke test"
php bin/mnb-scraper list >/dev/null
php bin/mnb-scraper compat:commands --json >/dev/null
php bin/mnb-scraper release:check . --strict
php -d zend.assertions=1 -d assert.exception=1 tests/run-tests.php
echo "Native smoke test completed."
