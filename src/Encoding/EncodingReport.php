<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Encoding;

final class EncodingReport
{
    public function __construct(
        public string $url,
        public ?string $httpCharset,
        public ?string $htmlCharset,
        public string $detectedCharset,
        public string $finalCharset = 'UTF-8',
        public int $invalidCharactersRemoved = 0,
        public bool $mojibakeFixed = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
