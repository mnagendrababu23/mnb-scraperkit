@echo off
setlocal

echo MNB ScraperKit native source-zip smoke test
php bin\mnb-scraper list >NUL || exit /b 1
php bin\mnb-scraper compat:commands --json >NUL || exit /b 1
php bin\mnb-scraper release:check . --strict || exit /b 1
php -d zend.assertions=1 -d assert.exception=1 tests\run-tests.php || exit /b 1
echo Native smoke test completed.
