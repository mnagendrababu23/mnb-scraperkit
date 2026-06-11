<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Network;

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;

final class IpCheckService
{
    public function check(NetworkProfile $profile): ?string
    {
        $client = new HttpClient($profile);
        $response = $client->get('https://api.ipify.org', new CrawlOptions(maxPages: 1, maxDepth: 0, timeoutSeconds: 15));
        $ip = trim($response->body);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
