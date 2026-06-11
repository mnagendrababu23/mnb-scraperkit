<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Elsevier;

final class ElsevierRecordNormalizer
{
    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    public function normalizeSearchMany(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->normalizeSearchRecord($row);
        }
        return $out;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function normalizeSearchRecord(array $row): array
    {
        $doi = $this->scalar($row['prism:doi'] ?? $row['doi'] ?? null);
        $pii = $this->scalar($row['pii'] ?? $row['prism:pii'] ?? null);
        $title = $this->scalar($row['dc:title'] ?? $row['title'] ?? $row['title_display'] ?? null);
        $journal = $this->scalar($row['prism:publicationName'] ?? $row['publicationName'] ?? $row['journal'] ?? null);
        $coverDate = $this->scalar($row['prism:coverDate'] ?? $row['coverDate'] ?? $row['publication_date'] ?? null);
        $url = $this->bestLink($row) ?: $this->scalar($row['prism:url'] ?? $row['dc:identifier'] ?? null);
        $authors = $this->authors($row['authors'] ?? $row['author'] ?? $row['dc:creator'] ?? null);

        return [
            'source' => 'elsevier_sciencedirect',
            'title' => $title,
            'journal' => $journal,
            'publication_date' => $coverDate,
            'cover_date' => $coverDate,
            'doi' => $doi,
            'pii' => $pii,
            'authors' => $authors,
            'article_type' => $this->scalar($row['prism:aggregationType'] ?? $row['article_type'] ?? null),
            'volume' => $this->scalar($row['prism:volume'] ?? $row['volume'] ?? null),
            'issue' => $this->scalar($row['prism:issueIdentifier'] ?? $row['issue'] ?? null),
            'page_range' => $this->scalar($row['prism:pageRange'] ?? $row['pageRange'] ?? null),
            'open_access' => $this->boolish($row['openaccess'] ?? $row['open_access'] ?? null),
            'article_url' => $this->articleUrl($doi, $pii, $url),
            'doi_url' => $doi ? 'https://doi.org/' . ltrim($doi, '/') : null,
            'api_article_url' => $doi ? 'https://api.elsevier.com/content/article/doi/' . rawurlencode($doi) : ($pii ? 'https://api.elsevier.com/content/article/pii/' . rawurlencode($pii) : null),
            'raw_id' => $this->scalar($row['dc:identifier'] ?? $row['identifier'] ?? null),
        ];
    }

    /** @param array<string,mixed> $json @return array<string,mixed> */
    public function normalizeArticleResponse(array $json, string $identifier, string $idType): array
    {
        $root = $json['full-text-retrieval-response'] ?? $json['article-response'] ?? $json['abstracts-retrieval-response'] ?? $json;
        if (!is_array($root)) {
            $root = $json;
        }
        $core = $root['coredata'] ?? $root['core-data'] ?? $root;
        if (!is_array($core)) {
            $core = [];
        }

        $doi = $this->scalar($core['prism:doi'] ?? $core['dc:identifier'] ?? null);
        if ($doi && str_starts_with(strtolower($doi), 'doi:')) {
            $doi = substr($doi, 4);
        }
        $pii = $this->scalar($core['pii'] ?? $core['prism:pii'] ?? null);

        return [
            'source' => 'elsevier_article_api',
            'identifier' => $identifier,
            'identifier_type' => $idType,
            'title' => $this->scalar($core['dc:title'] ?? $core['title'] ?? null),
            'journal' => $this->scalar($core['prism:publicationName'] ?? $core['publicationName'] ?? null),
            'publication_date' => $this->scalar($core['prism:coverDate'] ?? $core['prism:coverDisplayDate'] ?? null),
            'doi' => $doi,
            'pii' => $pii,
            'authors' => $this->authors($core['authors'] ?? $core['dc:creator'] ?? null),
            'publisher' => $this->scalar($core['dc:publisher'] ?? null),
            'volume' => $this->scalar($core['prism:volume'] ?? null),
            'issue' => $this->scalar($core['prism:issueIdentifier'] ?? null),
            'page_range' => $this->scalar($core['prism:pageRange'] ?? null),
            'open_access' => $this->boolish($core['openaccess'] ?? null),
            'article_url' => $this->scalar($core['prism:url'] ?? null) ?: ($doi ? 'https://doi.org/' . ltrim($doi, '/') : null),
            'doi_url' => $doi ? 'https://doi.org/' . ltrim($doi, '/') : null,
            'api_article_url' => $doi ? 'https://api.elsevier.com/content/article/doi/' . rawurlencode($doi) : ($pii ? 'https://api.elsevier.com/content/article/pii/' . rawurlencode($pii) : null),
            'abstract' => $this->scalar($core['dc:description'] ?? $core['description'] ?? null),
        ];
    }

    /** @param array<string,mixed> $json @return array<string,mixed> */
    public function normalizeSerialResponse(array $json, string $issn): array
    {
        $root = $json['serial-metadata-response'] ?? $json;
        $entries = is_array($root) && is_array($root['entry'] ?? null) ? $root['entry'] : [];
        $entry = is_array($entries[0] ?? null) ? $entries[0] : (is_array($entries) ? $entries : []);

        return [
            'source' => 'elsevier_serial_title_api',
            'issn' => $issn,
            'title' => $this->scalar($entry['dc:title'] ?? $entry['title'] ?? null),
            'publisher' => $this->scalar($entry['dc:publisher'] ?? $entry['publisher'] ?? null),
            'prism_issn' => $this->scalar($entry['prism:issn'] ?? null),
            'eissn' => $this->scalar($entry['prism:eIssn'] ?? $entry['prism:eissn'] ?? null),
            'source_id' => $this->scalar($entry['source-id'] ?? $entry['source_id'] ?? null),
            'homepage' => $this->linkByRef($root['link'] ?? $entry['link'] ?? null, 'homepage'),
            'cover_image' => $this->linkByRef($root['link'] ?? $entry['link'] ?? null, 'coverimage'),
            'api_url' => 'https://api.elsevier.com/content/serial/title/issn/' . rawurlencode($issn),
        ];
    }

    /** @param array<string,mixed> $row */
    private function bestLink(array $row): ?string
    {
        $links = $row['link'] ?? null;
        if (!is_array($links)) {
            return null;
        }
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $ref = strtolower((string) ($link['@ref'] ?? $link['ref'] ?? ''));
            $href = $this->scalar($link['@href'] ?? $link['href'] ?? null);
            if ($href && in_array($ref, ['scidir', 'scopus', 'self'], true)) {
                return $href;
            }
        }
        return null;
    }

    /** @param mixed $links */
    private function linkByRef(mixed $links, string $wanted): ?string
    {
        if (!is_array($links)) {
            return null;
        }
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $ref = strtolower((string) ($link['@ref'] ?? $link['ref'] ?? ''));
            if ($ref === strtolower($wanted)) {
                return $this->scalar($link['@href'] ?? $link['href'] ?? null);
            }
        }
        return null;
    }

    private function articleUrl(?string $doi, ?string $pii, ?string $url): ?string
    {
        if ($url && preg_match('~^https?://~i', $url)) {
            return $url;
        }
        if ($doi) {
            return 'https://doi.org/' . ltrim($doi, '/');
        }
        if ($pii) {
            return 'https://www.sciencedirect.com/science/article/pii/' . rawurlencode($pii);
        }
        return null;
    }

    /** @return array<int,string> */
    private function authors(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            } elseif (is_array($item)) {
                $name = $this->scalar($item['$'] ?? $item['name'] ?? $item['ce:indexed-name'] ?? $item['preferred-name']['ce:indexed-name'] ?? null);
                if ($name) {
                    $out[] = $name;
                }
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $out))));
    }

    private function scalar(mixed $value): ?string
    {
        if (is_array($value)) {
            if (isset($value[0])) {
                return $this->scalar($value[0]);
            }
            foreach (['$', '_', '#text'] as $key) {
                if (isset($value[$key])) {
                    return $this->scalar($value[$key]);
                }
            }
            return null;
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function boolish(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim((string) $value));
        if (in_array($value, ['1','true','yes','y','openaccess'], true)) {
            return true;
        }
        if (in_array($value, ['0','false','no','n'], true)) {
            return false;
        }
        return null;
    }
}
