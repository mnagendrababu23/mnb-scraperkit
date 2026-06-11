param(
    [string]$HostName = "127.0.0.1",
    [int]$Port = 8788
)
php bin/mnb-scraper dashboard:serve --host=$HostName --port=$Port
