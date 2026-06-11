<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

final class BrowserPageResult
{
    public function __construct(
        public string $url,
        public ?string $finalUrl = null,
        public ?string $title = null,
        public string $html = '',
        public string $text = '',
        public ?string $screenshotPath = null,
        public ?string $error = null,
        public int $loadTimeMs = 0,
    ) {
    }
}
