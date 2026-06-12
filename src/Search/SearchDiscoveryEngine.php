<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Search;

final class SearchDiscoveryEngine
{
    public const VERSION = '1.0.0';

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function search(string $query, array $options = []): array
    {
        $provider = SearchProviderRegistry::canonical((string) ($options['provider'] ?? 'offline'));
        $limit = max(1, min(100, (int) ($options['limit'] ?? 10)));
        $input = (string) ($options['input'] ?? '');
        $goal = (string) ($options['goal'] ?? '');
        $results = $input !== '' ? $this->readInputResults($input) : [];
        $configured = (bool) ((SearchProviderRegistry::providers()[$provider]['configured'] ?? false));
        $status = $results !== [] ? 'imported' : ($configured && $provider !== 'offline' ? 'provider_configured_not_invoked' : 'not_configured');

        if ($results === [] && $provider === 'offline') {
            $results = $this->offlineSuggestions($query, $limit);
            $status = 'offline_suggestions';
        }

        $classifier = new SearchResultClassifier();
        $classified = [];
        foreach (array_slice($results, 0, $limit) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? $row['link'] ?? '');
            if ($url === '') {
                continue;
            }
            $row['url'] = $url;
            unset($row['link']);
            $classified[] = $classifier->classify($row, $goal);
        }

        return [
            'ok' => true,
            'search_version' => self::VERSION,
            'query' => $query,
            'provider' => $provider,
            'status' => $status,
            'results_total' => count($classified),
            'results' => $classified,
            'provider_meta' => SearchProviderRegistry::providers()[$provider] ?? null,
            'audit' => [
                'generated_at' => date(DATE_ATOM),
                'search_result_page_scraping' => false,
                'approved_api_or_import_required_for_live_search' => true,
            ],
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function discover(string $query, array $options = []): array
    {
        $result = $this->search($query, $options);
        $domain = strtolower((string) ($options['filter_domain'] ?? $options['filter-domain'] ?? ''));
        $seeds = [];
        foreach ((array) ($result['results'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            if ($domain !== '' && !str_contains(strtolower((string) ($row['domain'] ?? parse_url($url, PHP_URL_HOST) ?: '')), $domain)) {
                continue;
            }
            $seeds[] = [
                'url' => $url,
                'title' => (string) ($row['title'] ?? ''),
                'domain' => (string) ($row['domain'] ?? ''),
                'classification' => (string) ($row['classification'] ?? 'general_seed'),
                'score' => (float) ($row['score'] ?? 0),
            ];
        }
        $result['seeds_total'] = count($seeds);
        $result['seeds'] = $seeds;
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public function seedsFromFile(string $file, ?string $domain = null): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid search discovery JSON: ' . $file);
        }
        $rows = [];
        if (isset($data['seeds']) && is_array($data['seeds'])) {
            $rows = $data['seeds'];
        } elseif (isset($data['results']) && is_array($data['results'])) {
            $rows = $data['results'];
        } elseif (array_is_list($data)) {
            $rows = $data;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? $row['link'] ?? '');
            if ($url === '') {
                continue;
            }
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
            if ($domain !== null && $domain !== '' && !str_contains($host, strtolower($domain))) {
                continue;
            }
            $out[] = ['url' => $url, 'domain' => $host, 'title' => (string) ($row['title'] ?? ''), 'classification' => (string) ($row['classification'] ?? 'seed')];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function readInputResults(string $input): array
    {
        if (!is_file($input)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($input), true);
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['items']) && is_array($data['items'])) {
            return $data['items'];
        }
        if (isset($data['results']) && is_array($data['results'])) {
            return $data['results'];
        }
        if (isset($data['organic_results']) && is_array($data['organic_results'])) {
            return $data['organic_results'];
        }
        return array_is_list($data) ? $data : [];
    }

    /** @return array<int,array<string,mixed>> */
    private function offlineSuggestions(string $query, int $limit): array
    {
        $q = strtolower($query);
        $results = [];
        if (str_contains($q, 'springer')) {
            $results[] = ['title' => 'Springer journals A-Z', 'url' => 'https://link.springer.com/journals/a/1', 'snippet' => 'Journal discovery seed.'];
            $results[] = ['title' => 'Springer books A-Z', 'url' => 'https://link.springer.com/books/a/1', 'snippet' => 'Book discovery seed.'];
            $results[] = ['title' => 'Springer journal volumes and issues', 'url' => 'https://link.springer.com/journal/777/volumes-and-issues', 'snippet' => 'Volume and issue discovery seed.'];
        } else {
            $domain = preg_match('#site:([^\s]+)#', $query, $m) === 1 ? $m[1] : 'example.com';
            $domain = preg_replace('#^https?://#', '', (string) $domain);
            $results[] = ['title' => 'Candidate homepage', 'url' => 'https://' . trim((string) $domain, '/') . '/', 'snippet' => 'Offline generated seed.'];
            $results[] = ['title' => 'Candidate sitemap', 'url' => 'https://' . trim((string) $domain, '/') . '/sitemap.xml', 'snippet' => 'Offline generated sitemap candidate.'];
        }
        return array_slice($results, 0, $limit);
    }
}
