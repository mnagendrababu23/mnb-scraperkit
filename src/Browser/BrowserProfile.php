<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

final class BrowserProfile
{
    public function __construct(
        public string $name,
        public string $engine = 'panther',
        public string $browser = 'chrome',
        public bool $headless = true,
        public int $windowWidth = 1366,
        public int $windowHeight = 768,
        public int $timeoutSeconds = 30,
        public int $waitAfterLoadMs = 1000,
        public bool $blockAssets = true,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            engine: (string) ($data['engine'] ?? 'panther'),
            browser: (string) ($data['browser'] ?? 'chrome'),
            headless: (bool) ($data['headless'] ?? true),
            windowWidth: (int) ($data['window_width'] ?? 1366),
            windowHeight: (int) ($data['window_height'] ?? 768),
            timeoutSeconds: (int) ($data['timeout_seconds'] ?? 30),
            waitAfterLoadMs: (int) ($data['wait_after_load_ms'] ?? 1000),
            blockAssets: (bool) ($data['block_assets'] ?? true),
        );
    }
}
