<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Storage;

use Mnb\ScraperKit\Core\CrawlResult;

final class JsonExporter
{
    public function export(CrawlResult $result, string $path, bool $includeHtml = false): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($result->toArray($includeHtml), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
