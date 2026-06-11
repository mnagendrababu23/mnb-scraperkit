<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Encoding;

final class EncodingDetector
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    /** @param array<string,string> $headers */
    public function detect(string $content, array $headers = [], ?string $forcedEncoding = null): string
    {
        if ($forcedEncoding) {
            return $this->normalizeEncodingName($forcedEncoding) ?: $forcedEncoding;
        }

        $fallback = (string) ($this->config['fallback_encoding'] ?? 'UTF-8');

        if ($content === '') {
            return $fallback;
        }

        $bom = $this->detectBom($content);
        if ($bom) {
            return $bom;
        }

        $headerEncoding = $this->detectFromHeader($headers);
        if ($headerEncoding) {
            return $this->normalizeEncodingName($headerEncoding) ?: $headerEncoding;
        }

        $htmlEncoding = (new HtmlCharsetReader())->detectFromHtml(substr($content, 0, 4096));
        if ($htmlEncoding) {
            return $this->normalizeEncodingName($htmlEncoding) ?: $htmlEncoding;
        }

        $list = (array) ($this->config['supported_encodings'] ?? [
            'UTF-8', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15', 'ASCII', 'Shift-JIS', 'EUC-JP', 'GBK', 'Big5'
        ]);
        $list = $this->safeDetectEncodingList($list);

        if ($list !== []) {
            try {
                $detected = mb_detect_encoding($content, $list, true);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            } catch (\ValueError) {
                // PHP 8+ throws ValueError if a platform-specific mbstring build rejects one encoding name.
                // Fall back safely instead of crashing a long crawl.
            }
        }

        return $fallback;
    }

    /** @param array<string,string> $headers */
    private function detectFromHeader(array $headers): ?string
    {
        $contentType = $headers['content-type'] ?? $headers['Content-Type'] ?? null;
        if (!$contentType || !preg_match('/charset=([^;\s]+)/i', $contentType, $match)) {
            return null;
        }
        return trim($match[1], " \t\n\r\0\x0B\"'");
    }

    private function detectBom(string $content): ?string
    {
        return match (true) {
            str_starts_with($content, "\xEF\xBB\xBF") => 'UTF-8',
            str_starts_with($content, "\xFF\xFE\x00\x00") => 'UTF-32LE',
            str_starts_with($content, "\x00\x00\xFE\xFF") => 'UTF-32BE',
            str_starts_with($content, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($content, "\xFE\xFF") => 'UTF-16BE',
            default => null,
        };
    }

    /**
     * @param array<int|string,mixed> $encodings
     * @return array<int,string>
     */
    private function safeDetectEncodingList(array $encodings): array
    {
        $supported = $this->supportedEncodingLookup();
        $safe = [];

        foreach ($encodings as $encoding) {
            if (!is_scalar($encoding)) {
                continue;
            }

            $name = $this->normalizeEncodingName((string) $encoding);
            if ($name === '') {
                continue;
            }

            if ($supported !== [] && !isset($supported[strtoupper($name)])) {
                continue;
            }

            $safe[strtoupper($name)] = $name;
        }

        if ($safe === []) {
            foreach (['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'] as $encoding) {
                if ($supported === [] || isset($supported[strtoupper($encoding)])) {
                    $safe[strtoupper($encoding)] = $encoding;
                }
            }
        }

        return array_values($safe);
    }

    /** @return array<string,true> */
    private function supportedEncodingLookup(): array
    {
        if (!function_exists('mb_list_encodings')) {
            return [];
        }

        $lookup = [];
        foreach (mb_list_encodings() as $encoding) {
            $name = (string) $encoding;
            $lookup[strtoupper($name)] = true;

            $normalized = $this->normalizeEncodingName($name);
            if ($normalized !== '') {
                $lookup[strtoupper($normalized)] = true;
            }

            if (function_exists('mb_encoding_aliases')) {
                foreach (mb_encoding_aliases($name) as $alias) {
                    $lookup[strtoupper((string) $alias)] = true;
                    $normalizedAlias = $this->normalizeEncodingName((string) $alias);
                    if ($normalizedAlias !== '') {
                        $lookup[strtoupper($normalizedAlias)] = true;
                    }
                }
            }
        }

        // Common platform aliases. Some builds expose cp1252, others accept Windows-1252.
        foreach ([
            'WINDOWS-1252' => 'CP1252',
            'WINDOWS-1251' => 'CP1251',
            'SHIFT-JIS' => 'SJIS',
            'MACROMAN' => 'MACINTOSH',
        ] as $alias => $canonical) {
            if (isset($lookup[$canonical])) {
                $lookup[$alias] = true;
            }
        }

        return $lookup;
    }

    private function normalizeEncodingName(string $encoding): string
    {
        $encoding = trim($encoding, " \t\n\r\0\x0B\"'");
        if ($encoding === '') {
            return '';
        }

        $key = strtoupper(str_replace(['_', ' '], ['-', ''], $encoding));

        return match ($key) {
            'UTF8' => 'UTF-8',
            'UTF16', 'UTF-16' => 'UTF-16',
            'UTF16LE', 'UTF-16LE' => 'UTF-16LE',
            'UTF16BE', 'UTF-16BE' => 'UTF-16BE',
            'UTF32LE', 'UTF-32LE' => 'UTF-32LE',
            'UTF32BE', 'UTF-32BE' => 'UTF-32BE',
            'WIN1252', 'WINDOWS1252', 'CP1252' => 'Windows-1252',
            'WIN1251', 'WINDOWS1251', 'CP1251' => 'Windows-1251',
            'LATIN1', 'ISO88591', 'ISO-8859-1' => 'ISO-8859-1',
            'LATIN9', 'ISO885915', 'ISO-8859-15' => 'ISO-8859-15',
            'SHIFTJIS', 'SHIFT-JIS', 'SJIS' => 'SJIS',
            'EUCJP', 'EUC-JP' => 'EUC-JP',
            'GB18030' => 'GB18030',
            'GBK' => 'GBK',
            'GB2312' => 'GB2312',
            'BIG5' => 'Big5',
            'KOI8R', 'KOI8-R' => 'KOI8-R',
            'MACROMAN', 'MAC-ROMAN', 'MACINTOSH' => 'Macintosh',
            default => $encoding,
        };
    }
}
