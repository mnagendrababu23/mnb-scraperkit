param([Parameter(ValueFromRemainingArguments=$true)][string[]]$Args)
php "$PSScriptRoot/../bin/mnb-scraper" enterprise:doctor @Args
