<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Parser;

use DOMDocument;
use DOMXPath;

final class HtmlDocument
{
    public function __construct(
        public readonly DOMDocument $dom,
        public readonly DOMXPath $xpath,
        public readonly string $baseUrl,
    ) {
    }
}
