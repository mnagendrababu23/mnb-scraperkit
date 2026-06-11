<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Network;

final class NetworkPolicy
{
    public function validate(NetworkProfile $profile): void
    {
        if (!$profile->enabled) {
            throw new \RuntimeException('Selected network profile is disabled.');
        }

        if ($profile->maxRequestsPerMinute > 120) {
            throw new \RuntimeException('Network request limit is too high for safe crawling defaults.');
        }

        if ($profile->isVpn() && PHP_SAPI !== 'cli') {
            throw new \RuntimeException('VPN control is allowed only from CLI workers.');
        }
    }
}
