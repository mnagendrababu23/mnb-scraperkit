<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Search;

final class SearchResultClassifier
{
    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function classify(array $result, string $goal = ''): array
    {
        $url = strtolower((string) ($result['url'] ?? $result['link'] ?? ''));
        $title = strtolower((string) ($result['title'] ?? ''));
        $snippet = strtolower((string) ($result['snippet'] ?? ''));
        $text = $url . ' ' . $title . ' ' . $snippet . ' ' . strtolower($goal);
        $classification = 'general_seed';
        $score = 0.35;

        if (str_contains($url, '/journal/') || str_contains($text, 'journal')) {
            $classification = 'journal_seed';
            $score = 0.72;
        }
        if (str_contains($url, 'volumes-and-issues') || str_contains($text, 'volume') || str_contains($text, 'issue')) {
            $classification = 'volume_issue_seed';
            $score = 0.86;
        }
        if (str_contains($url, '/article/') || preg_match('#10\.\d{4,9}/#', $url . ' ' . $text) === 1) {
            $classification = 'article_metadata_seed';
            $score = 0.91;
        }
        if (str_contains($url, '/book/') || str_contains($text, 'book')) {
            $classification = 'book_seed';
            $score = max($score, 0.74);
        }
        if (str_contains($url, '/chapter/')) {
            $classification = 'chapter_metadata_seed';
            $score = 0.9;
        }
        if (str_contains($url, 'sitemap')) {
            $classification = 'sitemap_seed';
            $score = 0.88;
        }
        if (str_contains($url, 'rss') || str_contains($url, 'feed') || str_contains($url, 'atom')) {
            $classification = 'feed_seed';
            $score = 0.84;
        }

        $result['domain'] = parse_url((string) ($result['url'] ?? $result['link'] ?? ''), PHP_URL_HOST) ?: '';
        $result['classification'] = $classification;
        $result['score'] = round($score, 2);
        return $result;
    }
}
