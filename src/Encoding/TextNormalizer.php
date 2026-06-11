<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Encoding;

final class TextNormalizer
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function normalize(string $text): string
    {
        $text = str_replace("\0", '', $text);

        if (($this->config['fix_mojibake'] ?? true) === true) {
            $text = (new MojibakeFixer())->fix($text);
        }

        if (($this->config['decode_html_entities'] ?? true) === true) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (($this->config['remove_zero_width_spaces'] ?? true) === true) {
            $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        }

        if (($this->config['remove_control_characters'] ?? true) === true) {
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        }

        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
