<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

interface BrowserClientInterface
{
    public function render(string $url, BrowserProfile $profile, BrowserOptions $options): BrowserPageResult;
    public function isAvailable(): bool;
    public function availabilityMessage(): string;
}
