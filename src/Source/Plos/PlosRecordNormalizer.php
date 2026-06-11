<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Plos;

final class PlosRecordNormalizer
{
    private PlosJournalCatalog $catalog;

    public function __construct(?PlosJournalCatalog $catalog = null)
    {
        $this->catalog = $catalog ?: new PlosJournalCatalog();
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public function normalize(array $doc): array
    {
        $doi = $this->firstString($doc['id'] ?? null);
        $journal = $this->firstString($doc['journal'] ?? null);
        $journalMeta = $this->journalForDoc($doi, $journal);
        $journalKey = $journalMeta['key'] ?? null;
        $articleUrl = $doi ? $this->articleUrl($doi, $journalKey) : null;

        return [
            'source_type' => 'plos_api',
            'record_type' => 'article',
            'record_key' => $doi ?: sha1(json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'doi' => $doi,
            'title' => $this->firstString($doc['title_display'] ?? ($doc['title'] ?? null)),
            'journal' => $journal,
            'journal_key' => $journalKey,
            'publication_date' => $this->firstString($doc['publication_date'] ?? null),
            'received_date' => $this->firstString($doc['received_date'] ?? null),
            'accepted_date' => $this->firstString($doc['accepted_date'] ?? null),
            'article_type' => $this->firstString($doc['article_type'] ?? null),
            'volume' => $this->firstString($doc['volume'] ?? null),
            'issue' => $this->firstString($doc['issue'] ?? null),
            'authors' => $this->stringArray($doc['author_display'] ?? ($doc['author'] ?? null)),
            'authors_text' => implode(' | ', $this->stringArray($doc['author_display'] ?? ($doc['author'] ?? null))),
            'abstract' => $this->firstString($doc['abstract'] ?? ($doc['abstract_primary_display'] ?? null)),
            'article_url' => $articleUrl,
            'doi_url' => $doi ? 'https://doi.org/' . rawurlencode($doi) : null,
            'xml_url' => ($doi && $journalKey) ? $this->fileUrl($doi, $journalKey, 'manuscript') : null,
            'pdf_url' => ($doi && $journalKey) ? $this->fileUrl($doi, $journalKey, 'printable') : null,
            'raw' => $doc,
        ];
    }

    /** @param array<int,array<string,mixed>> $docs @return array<int,array<string,mixed>> */
    public function normalizeMany(array $docs): array
    {
        $records = [];
        foreach ($docs as $doc) {
            if (is_array($doc)) {
                $records[] = $this->normalize($doc);
            }
        }
        return $records;
    }

    /** @return array<string,mixed>|null */
    private function journalForDoc(?string $doi, ?string $journal): ?array
    {
        if ($journal) {
            $found = $this->catalog->find($journal);
            if ($found) {
                return $found;
            }
        }
        if ($doi) {
            foreach ($this->catalog->all() as $meta) {
                foreach ((array) ($meta['doi_prefixes'] ?? []) as $prefix) {
                    if (str_contains(strtolower($doi), strtolower((string) $prefix))) {
                        return $meta;
                    }
                }
            }
        }
        return null;
    }

    private function articleUrl(string $doi, ?string $journalKey): ?string
    {
        if (!$journalKey) {
            return null;
        }
        return 'https://journals.plos.org/' . rawurlencode($journalKey) . '/article?id=' . rawurlencode($doi);
    }

    private function fileUrl(string $doi, string $journalKey, string $type): string
    {
        return 'https://journals.plos.org/' . rawurlencode($journalKey) . '/article/file?id=' . rawurlencode($doi) . '&type=' . rawurlencode($type);
    }

    private function firstString(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $s = $this->firstString($item);
                if ($s !== null) {
                    return $s;
                }
            }
            return null;
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        $value = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $value === '' ? null : preg_replace('/\s+/', ' ', $value);
    }

    /** @return array<int,string> */
    private function stringArray(mixed $value): array
    {
        if ($value === null || $value === false) {
            return [];
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $out = [];
        foreach ($value as $item) {
            $s = $this->firstString($item);
            if ($s !== null) {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }
}
