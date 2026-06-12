<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

final class WordDictionary
{
    /** @var array<string,int> */
    private array $words = [];

    public function __construct(private ?string $path = null)
    {
        if ($path && is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                $source = is_array($data['words'] ?? null) ? $data['words'] : $data;
                foreach ($source as $word => $count) {
                    if (is_int($word)) {
                        $this->words[$this->normalize((string) $count)] = 1;
                    } else {
                        $this->words[$this->normalize((string) $word)] = max(1, (int) $count);
                    }
                }
                unset($this->words['']);
            }
        }
    }

    /** @return array{new_words:list<string>,words:array<string,int>,new_total:int,total:int} */
    public function learn(string $text, int $minLength = 3): array
    {
        $tokens = preg_split('/[^\pL\pN\-]+/u', $text) ?: [];
        $new = [];
        foreach ($tokens as $token) {
            $word = $this->normalize($token);
            if ($word === '' || strlen($word) < $minLength || is_numeric($word)) {
                continue;
            }
            if (!isset($this->words[$word])) {
                $new[$word] = $word;
                $this->words[$word] = 0;
            }
            $this->words[$word]++;
        }
        ksort($this->words);
        return [
            'new_words' => array_values($new),
            'words' => $this->words,
            'new_total' => count($new),
            'total' => count($this->words),
        ];
    }

    public function save(?string $path = null): ?string
    {
        $path = $path ?: $this->path;
        if (!$path) {
            return null;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode([
            'dictionary_version' => '4.2.0',
            'updated_at' => date(DATE_ATOM),
            'words_total' => count($this->words),
            'words' => $this->words,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $path;
    }

    /** @return array<string,int> */
    public function words(): array
    {
        return $this->words;
    }

    private function normalize(string $word): string
    {
        $word = html_entity_decode($word, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $word = strtolower(trim($word));
        return trim($word, " \t\n\r\0\x0B.,:;!?()[]{}<>\"'");
    }
}
