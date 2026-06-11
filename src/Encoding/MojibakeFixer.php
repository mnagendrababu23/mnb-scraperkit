<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Encoding;

final class MojibakeFixer
{
    /** @var array<string,string> */
    private array $replacements = [
        'â€™' => '’', 'â€˜' => '‘', 'â€œ' => '“', 'â€' => '”',
        'â€“' => '–', 'â€”' => '—', 'â€¦' => '…', 'Â©' => '©',
        'Â®' => '®', 'Â£' => '£', 'Â¥' => '¥', 'Â€' => '€',
        'Â₹' => '₹', 'Â ' => ' ', 'Â' => '', 'Ã©' => 'é',
        'Ã¨' => 'è', 'Ã¡' => 'á', 'Ã³' => 'ó', 'Ã±' => 'ñ',
    ];

    public function fix(string $text): string
    {
        return strtr($text, $this->replacements);
    }
}
