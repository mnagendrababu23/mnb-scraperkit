<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Encoding;

final class EncodingConverter
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function toUtf8(string $content, ?string $sourceEncoding = null): string
    {
        $sourceEncoding ??= (new EncodingDetector($this->config))->detect($content);
        $sourceEncoding = $this->normalizeEncodingName($sourceEncoding) ?: 'UTF-8';

        $content = $this->removeBom($content);

        if (strtoupper($sourceEncoding) !== 'UTF-8' && $content !== '') {
            $converted = null;

            try {
                $converted = @mb_convert_encoding($content, 'UTF-8', $sourceEncoding);
            } catch (\ValueError) {
                $converted = null;
            }

            if (!is_string($converted) && function_exists('iconv')) {
                $iconvEncoding = $this->iconvEncodingName($sourceEncoding);
                $iconvConverted = @iconv($iconvEncoding, 'UTF-8//IGNORE', $content);
                if (is_string($iconvConverted)) {
                    $converted = $iconvConverted;
                }
            }

            if (is_string($converted)) {
                $content = $converted;
            }
        }

        return (new TextNormalizer($this->config))->normalize($content);
    }

    private function removeBom(string $content): string
    {
        foreach (["\xEF\xBB\xBF", "\xFF\xFE\x00\x00", "\x00\x00\xFE\xFF", "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($content, $bom)) {
                return substr($content, strlen($bom));
            }
        }
        return $content;
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

    private function iconvEncodingName(string $encoding): string
    {
        return match (strtoupper($encoding)) {
            'WINDOWS-1252' => 'CP1252',
            'WINDOWS-1251' => 'CP1251',
            'SJIS' => 'SHIFT_JIS',
            'MACINTOSH' => 'MACINTOSH',
            default => $encoding,
        };
    }
}
