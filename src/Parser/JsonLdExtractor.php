<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Parser;

final class JsonLdExtractor
{
    /** @return array<int,mixed> */
    public function extract(HtmlDocument $doc): array
    {
        $items = [];
        foreach ($doc->xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $items[] = $decoded;
            }
        }
        return $items;
    }
}
