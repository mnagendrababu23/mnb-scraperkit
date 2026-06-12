<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

final class WordDictionary
{
    /** @var array<string,array<string,mixed>> */
    private array $entries = [];

    public function __construct(private ?string $path = null)
    {
        if ($path && is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                $source = is_array($data['words'] ?? null) ? $data['words'] : $data;
                foreach ($source as $word => $entry) {
                    if (is_int($word)) {
                        $this->entries[$this->normalize((string) $entry)] = $this->entry(1);
                    } elseif (is_array($entry)) {
                        $this->entries[$this->normalize((string) $word)] = $entry + $this->entry((int) ($entry['count'] ?? 1));
                    } else {
                        $this->entries[$this->normalize((string) $word)] = $this->entry(max(1, (int) $entry));
                    }
                }
                unset($this->entries['']);
            }
        }
    }

    /** @return array{new_words:list<string>,words:array<string,int>,entries:array<string,array<string,mixed>>,new_total:int,total:int} */
    public function learn(string $text, int $minLength = 3, ?string $sourceUrl = null, ?string $category = null): array
    {
        $tokens = preg_split('/[^\pL\pN\-]+/u', $text) ?: [];
        $new = [];
        $now = date(DATE_ATOM);
        foreach ($tokens as $token) {
            $word = $this->normalize($token);
            if ($word === '' || strlen($word) < $minLength || is_numeric($word) || $this->isStopWord($word)) {
                continue;
            }
            if (!isset($this->entries[$word])) {
                $new[$word] = $word;
                $this->entries[$word] = $this->entry(0, $now, $category);
            }
            $this->entries[$word]['count'] = (int) ($this->entries[$word]['count'] ?? 0) + 1;
            $this->entries[$word]['last_seen_at'] = $now;
            if ($sourceUrl) {
                $sources = array_values(array_unique(array_merge((array) ($this->entries[$word]['source_urls'] ?? []), [$sourceUrl])));
                $this->entries[$word]['source_urls'] = array_slice($sources, -10);
            }
            if ($category && empty($this->entries[$word]['category'])) {
                $this->entries[$word]['category'] = $category;
            }
        }
        ksort($this->entries);
        return [
            'new_words' => array_values($new),
            'words' => $this->words(),
            'entries' => $this->entries,
            'new_total' => count($new),
            'total' => count($this->entries),
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
            'dictionary_version' => '4.3.1',
            'updated_at' => date(DATE_ATOM),
            'words_total' => count($this->entries),
            'words' => $this->entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $path;
    }

    /** @return array<string,int> */
    public function words(): array
    {
        $words = [];
        foreach ($this->entries as $word => $entry) {
            $words[$word] = (int) ($entry['count'] ?? 0);
        }
        return $words;
    }

    /** @return array<string,array<string,mixed>> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array<string,mixed> */
    private function entry(int $count, ?string $now = null, ?string $category = null): array
    {
        $now = $now ?: date(DATE_ATOM);
        return [
            'count' => $count,
            'category' => $category ?: 'general',
            'approved' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'source_urls' => [],
        ];
    }

    private function isStopWord(string $word): bool
    {
        static $stop = ['the'=>true,'and'=>true,'for'=>true,'with'=>true,'from'=>true,'that'=>true,'this'=>true,'are'=>true,'was'=>true,'were'=>true,'you'=>true,'your'=>true,'into'=>true,'onto'=>true,'about'=>true,'http'=>true,'https'=>true,'www'=>true,'com'=>true];
        return isset($stop[$word]);
    }

    private function normalize(string $word): string
    {
        $word = html_entity_decode($word, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $word = strtolower(trim($word));
        return trim($word, " \t\n\r\0\x0B.,:;!?()[]{}<>\"'");
    }
}
