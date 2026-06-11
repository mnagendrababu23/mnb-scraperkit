$Root = Split-Path -Parent $PSScriptRoot
php (Join-Path $Root "bin/mnb-scraper") rule:test @args
