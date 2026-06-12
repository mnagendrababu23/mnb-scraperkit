<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Publisher;

/**
 * Normalizes public scholarly article metadata from source connector output.
 */
final class ArticleMetadataNormalizer
{
    /** @return list<array<string,mixed>> */
    public static function schemaFields(): array
    {
        return [
            ['field' => 'record_id', 'required' => false, 'description' => 'Stable local record identifier or generated hash.'],
            ['field' => 'content_kind', 'required' => false, 'description' => 'journal_article, book_chapter, book, journal_issue, or metadata landing page.'],
            ['field' => 'source_url', 'required' => false, 'description' => 'Original crawled/source URL before canonicalization.'],
            ['field' => 'title', 'required' => true, 'description' => 'Article/chapter/paper title.'],
            ['field' => 'subtitle', 'required' => false, 'description' => 'Subtitle where available.'],
            ['field' => 'authors', 'required' => false, 'description' => 'Author list as an array.'],
            ['field' => 'author_details', 'required' => false, 'description' => 'Structured authors with public affiliation/contact metadata when exposed.'],
            ['field' => 'authors_text', 'required' => false, 'description' => 'Human-readable author string.'],
            ['field' => 'doi', 'required' => false, 'description' => 'Original DOI value.'],
            ['field' => 'normalized_doi', 'required' => false, 'description' => 'Lowercase DOI without URL prefix.'],
            ['field' => 'issn', 'required' => false, 'description' => 'Print ISSN where available.'],
            ['field' => 'eissn', 'required' => false, 'description' => 'Electronic ISSN where available.'],
            ['field' => 'journal', 'required' => false, 'description' => 'Journal/proceedings/book series title.'],
            ['field' => 'book_title', 'required' => false, 'description' => 'Book title for book/chapter workflows.'],
            ['field' => 'book_doi', 'required' => false, 'description' => 'Book-level DOI when available.'],
            ['field' => 'chapter_title', 'required' => false, 'description' => 'Chapter title when content kind is book_chapter.'],
            ['field' => 'publisher', 'required' => false, 'description' => 'Publisher name.'],
            ['field' => 'volume', 'required' => false, 'description' => 'Volume number.'],
            ['field' => 'issue', 'required' => false, 'description' => 'Issue number.'],
            ['field' => 'page_range', 'required' => false, 'description' => 'Page range or article number.'],
            ['field' => 'published_at', 'required' => false, 'description' => 'Publication date in source format.'],
            ['field' => 'article_type', 'required' => false, 'description' => 'Article/review/editorial/chapter/etc.'],
            ['field' => 'abstract', 'required' => false, 'description' => 'Public abstract or summary.'],
            ['field' => 'references', 'required' => false, 'description' => 'Public reference strings/DOIs when exposed on the landing page.'],
            ['field' => 'keywords', 'required' => false, 'description' => 'Keyword/subject tags.'],
            ['field' => 'contacts', 'required' => false, 'description' => 'Public corresponding-author/contact metadata when exposed.'],
            ['field' => 'url', 'required' => true, 'description' => 'Canonical landing URL.'],
            ['field' => 'html_url', 'required' => false, 'description' => 'HTML landing URL if distinct.'],
            ['field' => 'pdf_url', 'required' => false, 'description' => 'Public PDF URL only when exposed.'],
            ['field' => 'license', 'required' => false, 'description' => 'License text/URL where available.'],
            ['field' => 'open_access', 'required' => false, 'description' => 'Boolean or source OA marker.'],
            ['field' => 'source', 'required' => false, 'description' => 'Source connector, feed, sitemap, API, or publisher id.'],
            ['field' => 'source_type', 'required' => false, 'description' => 'rss, sitemap, api, crossref, publisher_catalog, etc.'],
            ['field' => 'quality_score', 'required' => false, 'description' => 'Pipeline quality score when available.'],
        ];
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function normalize(array $record, ?string $publisher = null): array
    {
        $title = $this->firstString($record, ['title', 'dc:title', 'citation_title', 'name']);
        $url = $this->firstString($record, ['url', 'canonical_url', 'html_url', 'landing_url', 'link', 'loc']);
        $doi = $this->firstString($record, ['doi', 'dc:identifier', 'prism:doi', 'normalized_doi']);
        $normalizedDoi = $this->normalizeDoi($doi ?? $this->doiFromText(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
        $authors = $this->authors($record);

        $normalized = [
            'record_id' => $this->firstString($record, ['record_id', 'record_key', 'id']) ?: sha1(strtolower((string) ($normalizedDoi ?: $url ?: $title ?: json_encode($record)))) ,
            'content_kind' => $this->contentKind($record, $url),
            'source_url' => $this->firstString($record, ['source_url', 'input_url', 'crawled_url']) ?: $url,
            'title' => $title,
            'subtitle' => $this->firstString($record, ['subtitle']),
            'authors' => $authors,
            'author_details' => $this->authorDetails($record),
            'authors_text' => $this->firstString($record, ['authors_text', 'author_text']) ?: implode(' | ', $authors),
            'doi' => $doi,
            'normalized_doi' => $normalizedDoi,
            'issn' => $this->normalizeIssn($this->firstString($record, ['issn', 'print_issn', 'prism:issn'])),
            'eissn' => $this->normalizeIssn($this->firstString($record, ['eissn', 'electronic_issn'])),
            'journal' => $this->firstString($record, ['journal', 'journal_title', 'publicationName', 'container-title', 'source_title']),
            'book_title' => $this->firstString($record, ['book_title', 'book', 'bookTitle']),
            'book_doi' => $this->normalizeDoi($this->firstString($record, ['book_doi', 'bookDoi'])),
            'chapter_title' => $this->firstString($record, ['chapter_title', 'chapterTitle']),
            'publisher' => $publisher ?: $this->firstString($record, ['publisher']),
            'volume' => $this->firstString($record, ['volume']),
            'issue' => $this->firstString($record, ['issue']),
            'page_range' => $this->firstString($record, ['page_range', 'pages', 'page', 'article_number']),
            'published_at' => $this->firstString($record, ['published_at', 'published_date', 'publication_date', 'date', 'coverDate']),
            'article_type' => $this->firstString($record, ['article_type', 'type', 'subtypeDescription']),
            'abstract' => $this->firstString($record, ['abstract', 'summary', 'description']),
            'references' => $this->stringList($record, ['references', 'reference', 'bibliography']),
            'keywords' => $this->stringList($record, ['keywords', 'keyword', 'subjects', 'subject_terms']),
            'contacts' => $this->contacts($record),
            'url' => $url,
            'html_url' => $this->firstString($record, ['html_url']) ?: $url,
            'pdf_url' => $this->firstString($record, ['pdf_url', 'pdf']),
            'license' => $this->firstString($record, ['license']),
            'open_access' => $record['open_access'] ?? $record['oa'] ?? null,
            'source' => $this->firstString($record, ['source']) ?: $publisher,
            'source_type' => $this->firstString($record, ['source_type']),
            'quality_score' => $record['quality_score'] ?? null,
        ];

        return array_filter($normalized, static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** @param list<array<string,mixed>> $records @return list<array<string,mixed>> */
    public function normalizeMany(array $records, ?string $publisher = null): array
    {
        return array_values(array_map(fn (array $record): array => $this->normalize($record, $publisher), $records));
    }

    public function normalizeDoi(?string $doi): ?string
    {
        if (!$doi) {
            return null;
        }
        $doi = trim(html_entity_decode($doi, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $doi = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $doi) ?? $doi;
        $doi = preg_replace('~^doi:\s*~i', '', $doi) ?? $doi;
        if (preg_match('~(10\.[0-9]{4,9}/[-._;()/:A-Z0-9]+)~i', $doi, $m) !== 1) {
            return null;
        }
        return strtolower(rtrim($m[1], '.,;:) ]}'));
    }

    public function normalizeIssn(?string $issn): ?string
    {
        if (!$issn) {
            return null;
        }
        $clean = strtoupper(preg_replace('~[^0-9X]~i', '', $issn) ?? '');
        if (strlen($clean) !== 8) {
            return null;
        }
        return substr($clean, 0, 4) . '-' . substr($clean, 4);
    }

    private function doiFromText(string $text): ?string
    {
        if (preg_match('~10\.[0-9]{4,9}/[-._;()/:A-Z0-9]+~i', $text, $m) === 1) {
            return $m[0];
        }
        return null;
    }

    /** @param array<string,mixed> $record @param list<string> $keys */
    private function firstString(array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $record)) {
                continue;
            }
            $value = $record[$key];
            if (is_array($value)) {
                $value = implode(' | ', array_values(array_filter(array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $value))));
            }
            if (is_scalar($value)) {
                $text = preg_replace('/\s+/', ' ', trim((string) $value)) ?? trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return null;
    }


    /** @param array<string,mixed> $record @param list<string> $keys @return list<string> */
    private function stringList(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $record)) {
                continue;
            }
            $value = $record[$key];
            if (is_string($value)) {
                return array_values(array_filter(array_map('trim', preg_split('/\s*(?:\||;|\n)\s*/', $value) ?: [])));
            }
            if (is_array($value)) {
                $out = [];
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $text = preg_replace('/\s+/', ' ', trim((string) $item)) ?? trim((string) $item);
                        if ($text !== '') {
                            $out[] = $text;
                        }
                    } elseif (is_array($item)) {
                        $text = $this->firstString($item, ['text', 'title', 'citation', 'doi', 'name']);
                        if ($text) {
                            $out[] = $text;
                        }
                    }
                }
                return array_values(array_unique($out));
            }
        }
        return [];
    }

    /** @param array<string,mixed> $record @return list<array<string,mixed>> */
    private function authorDetails(array $record): array
    {
        foreach (['author_details', 'authors_detailed', 'creator_details'] as $key) {
            if (isset($record[$key]) && is_array($record[$key])) {
                return array_values(array_filter($record[$key], 'is_array'));
            }
        }
        return [];
    }

    /** @param array<string,mixed> $record @return list<array<string,string>> */
    private function contacts(array $record): array
    {
        foreach (['contacts', 'corresponding_authors', 'emails'] as $key) {
            if (!isset($record[$key])) {
                continue;
            }
            $value = $record[$key];
            if (is_string($value)) {
                return [['email' => trim($value)]];
            }
            if (is_array($value)) {
                $rows = [];
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $rows[] = array_filter($item, static fn ($v): bool => is_scalar($v) && trim((string) $v) !== '');
                    } elseif (is_scalar($item)) {
                        $rows[] = ['email' => trim((string) $item)];
                    }
                }
                return array_values(array_filter($rows));
            }
        }
        return [];
    }

    /** @param array<string,mixed> $record */
    private function contentKind(array $record, ?string $url): ?string
    {
        $kind = $this->firstString($record, ['content_kind', 'record_type']);
        if ($kind) {
            return strtolower(str_replace(' ', '_', $kind));
        }
        $text = strtolower((string) ($url ?: $this->firstString($record, ['url', 'link']) ?: ''));
        if (str_contains($text, '/chapter/')) {
            return 'book_chapter';
        }
        if (str_contains($text, '/book/')) {
            return 'book';
        }
        if (str_contains($text, '/journal/') && str_contains($text, 'volumes-and-issues')) {
            return 'journal_issue';
        }
        if (str_contains($text, '/article/')) {
            return 'journal_article';
        }
        return null;
    }

    /** @param array<string,mixed> $record @return list<string> */
    private function authors(array $record): array
    {
        foreach (['authors', 'author', 'author_display', 'creators'] as $key) {
            if (!array_key_exists($key, $record)) {
                continue;
            }
            $value = $record[$key];
            if (is_string($value)) {
                return array_values(array_filter(array_map('trim', preg_split('/\s*(?:;|\||,)\s*/', $value) ?: [])));
            }
            if (is_array($value)) {
                $authors = [];
                foreach ($value as $author) {
                    if (is_string($author) && trim($author) !== '') {
                        $authors[] = trim($author);
                    } elseif (is_array($author)) {
                        $name = $this->firstString($author, ['name', 'full_name', 'given', 'family']);
                        if ($name) {
                            $authors[] = $name;
                        }
                    }
                }
                return array_values(array_unique($authors));
            }
        }
        return [];
    }
}
