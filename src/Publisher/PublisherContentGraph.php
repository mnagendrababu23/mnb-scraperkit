<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Publisher;

/**
 * Describes publisher-specific navigation graphs for enterprise metadata crawls.
 *
 * The graph is intentionally metadata-first: it models discovery paths without
 * downloading paywalled full text or bypassing access controls.
 */
final class PublisherContentGraph
{
    /** @param array<string,mixed> $publisher */
    public function __construct(private array $publisher)
    {
    }

    /** @return array<string,mixed> */
    public function graph(): array
    {
        $graph = $this->publisher['content_graph'] ?? null;
        if (is_array($graph)) {
            return $graph;
        }

        return self::defaultGraph();
    }

    /** @return list<array<string,mixed>> */
    public function levels(): array
    {
        return array_values(array_filter((array) ($this->graph()['levels'] ?? []), 'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function extractionTargets(): array
    {
        return array_values(array_filter((array) ($this->graph()['extraction_targets'] ?? []), 'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function enterprisePlan(int $maxJournals = 10, int $maxBooks = 10, int $maxIssues = 10, int $maxArticles = 25): array
    {
        $publisherId = (string) ($this->publisher['id'] ?? 'publisher');
        $publisherName = (string) ($this->publisher['publisher'] ?? $publisherId);
        $delay = (int) ($this->publisher['default_delay_ms'] ?? 3500);
        $steps = [];

        foreach ($this->levels() as $level) {
            $type = (string) ($level['type'] ?? 'unknown');
            $limit = match ($type) {
                'journal_index', 'journal' => $maxJournals,
                'book_index', 'book' => $maxBooks,
                'volume_issue', 'issue' => $maxIssues,
                'article', 'chapter' => $maxArticles,
                default => min($maxArticles, 25),
            };
            $steps[] = [
                'step' => count($steps) + 1,
                'type' => $type,
                'label' => (string) ($level['label'] ?? $type),
                'url_patterns' => array_values(array_map('strval', (array) ($level['url_patterns'] ?? []))),
                'extract_links_matching' => array_values(array_map('strval', (array) ($level['extract_links_matching'] ?? []))),
                'max_items' => max(1, $limit),
                'delay_ms' => $delay,
                'output_bucket' => 'storage/publishers/' . $publisherId . '/' . $type,
            ];
        }

        return [[
            'plan_version' => '4.3.0',
            'publisher_id' => $publisherId,
            'publisher' => $publisherName,
            'workflow' => 'enterprise_publisher_metadata_graph',
            'policy' => [
                'metadata_only' => true,
                'respect_robots' => true,
                'no_paywall_bypass' => true,
                'no_captcha_bypass' => true,
                'no_full_text_harvesting_without_authorization' => true,
                'prefer_doi_crossref_api_when_available' => true,
            ],
            'steps' => $steps,
            'record_schema' => ArticleMetadataNormalizer::schemaFields(),
        ]];
    }

    /** @return array<string,mixed> */
    public static function defaultGraph(): array
    {
        return [
            'graph_version' => '4.3.0',
            'strategy' => 'publisher_to_collections_to_article_metadata',
            'levels' => [
                [
                    'type' => 'publisher_home',
                    'label' => 'Publisher about/home page',
                    'url_patterns' => ['/'],
                    'extract_links_matching' => ['/journals', '/books', '/articles', '/content'],
                ],
                [
                    'type' => 'journal_index',
                    'label' => 'Journal A-Z / subject listing pages',
                    'url_patterns' => ['/journals', '/journals/{letter}/{page}'],
                    'extract_links_matching' => ['/journal/'],
                ],
                [
                    'type' => 'book_index',
                    'label' => 'Book A-Z / subject listing pages',
                    'url_patterns' => ['/books', '/books/{letter}/{page}'],
                    'extract_links_matching' => ['/book/'],
                ],
                [
                    'type' => 'journal',
                    'label' => 'Journal landing page',
                    'url_patterns' => ['/journal/{journal_id}'],
                    'extract_links_matching' => ['/volumes-and-issues', '/article/'],
                ],
                [
                    'type' => 'volume_issue',
                    'label' => 'Journal volume/issue table of contents',
                    'url_patterns' => ['/journal/{journal_id}/volumes-and-issues', '/journal/{journal_id}/volumes-and-issues/{volume}-{issue}'],
                    'extract_links_matching' => ['/article/'],
                ],
                [
                    'type' => 'book',
                    'label' => 'Book landing page',
                    'url_patterns' => ['/book/{doi_or_book_id}'],
                    'extract_links_matching' => ['/chapter/', '/article/'],
                ],
                [
                    'type' => 'article',
                    'label' => 'Article/chapter metadata page',
                    'url_patterns' => ['/article/{doi}', '/chapter/{doi}'],
                    'extract_links_matching' => [],
                ],
            ],
            'extraction_targets' => [
                ['field' => 'title', 'required' => true, 'source' => 'citation_title, h1, title'],
                ['field' => 'article_type', 'required' => false, 'source' => 'article type badge/details'],
                ['field' => 'published_at', 'required' => false, 'source' => 'citation_publication_date, published labels'],
                ['field' => 'authors', 'required' => false, 'source' => 'citation_author, author list'],
                ['field' => 'author_details', 'required' => false, 'source' => 'author affiliation/email blocks when public'],
                ['field' => 'abstract', 'required' => false, 'source' => 'public abstract section'],
                ['field' => 'references', 'required' => false, 'source' => 'public reference list when exposed'],
                ['field' => 'doi', 'required' => false, 'source' => 'citation_doi, DOI link'],
                ['field' => 'keywords', 'required' => false, 'source' => 'keyword/subject list'],
            ],
        ];
    }
}
