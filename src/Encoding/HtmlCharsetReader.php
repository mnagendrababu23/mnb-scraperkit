<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Encoding;

final class HtmlCharsetReader
{
    public function detectFromHtml(string $html): ?string
    {
        if (preg_match('/<meta\s+charset=["\']?([^"\'>\s]+)/i', $html, $match)) {
            return $this->normalizeEncoding($match[1]);
        }

        if (preg_match('/<meta[^>]+http-equiv=["\']content-type["\'][^>]+content=["\'][^"\']*charset=([^"\'>\s;]+)/i', $html, $match)) {
            return $this->normalizeEncoding($match[1]);
        }

        if (preg_match('/<\?xml[^>]+encoding=["\']([^"\']+)/i', $html, $match)) {
            return $this->normalizeEncoding($match[1]);
        }

        return null;
    }

    private function normalizeEncoding(string $encoding): string
    {
        $encoding = trim($encoding, " \t\n\r\0\x0B\"'");
        return str_replace('_', '-', $encoding);
    }
}
