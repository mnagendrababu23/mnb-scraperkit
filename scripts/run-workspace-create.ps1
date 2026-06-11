param([Parameter(ValueFromRemainingArguments=$true)][string[]]$Args)
php "$PSScriptRoot/../bin/mnb-scraper" workspace:create @Args
