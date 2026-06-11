<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Discovery;

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;
use Mnb\ScraperKit\Core\ProtectionPageDetector;

final class FallbackSourceDiscovery
{
    public function __construct(private HttpClient $client, private ProtectionPageDetector $detector = new ProtectionPageDetector())
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function discover(string $url, CrawlOptions $options, int $maxCandidates = 20): array
    {
        $candidates = array_slice($this->candidateUrls($url), 0, max(1, $maxCandidates));
        $results = [];

        foreach ($candidates as $candidate) {
            $response = $this->client->get($candidate, $options);
            $contentType = $response->header('content-type');
            $title = $this->extractTitle($response->body);
            $links = $this->extractLinks($response->body, $response->finalUrl ?: $candidate, 40);
            $meta = ['robots' => $this->extractRobotsMeta($response->body)];
            $protection = $this->detector->detect(
                $candidate,
                $response->finalUrl,
                $response->statusCode,
                $response->headers,
                $response->body,
                $title,
                trim(strip_tags($response->body)),
                $links,
                $meta,
                $response->error
            );

            $results[] = [
                'url' => $candidate,
                'final_url' => $response->finalUrl,
                'status_code' => $response->statusCode,
                'content_type' => $contentType,
                'title' => $title,
                'is_challenge' => (bool) ($protection['is_challenge'] ?? false),
                'failure_type' => $protection['failure_type'] ?? null,
                'error' => $response->error,
                'links_found' => count($links),
                'links_sample' => array_slice($links, 0, 10),
                'sitemap_locs_sample' => array_slice($this->extractSitemapLocs($response->body), 0, 20),
                'recommendation' => $this->recommendation($response->statusCode, (bool) ($protection['is_challenge'] ?? false), $contentType, $response->body),
            ];
        }

        return [
            'start_url' => $url,
            'origin' => $this->origin($url),
            'note' => 'Fallback discovery does not bypass protection pages. It checks safer alternate sources such as sitemap, feed, robots, and well-known metadata endpoints.',
            'source_connectors' => $this->sourceConnectorRecommendations($url),
            'candidates_checked' => count($results),
            'results' => $results,
        ];
    }

    /** @return array<int,string> */
    public function candidateUrls(string $url): array
    {
        $origin = $this->origin($url);
        if ($origin === '') {
            return [];
        }

        $base = [
            $origin . '/robots.txt',
            $origin . '/sitemap.xml',
            $origin . '/sitemap_index.xml',
            $origin . '/sitemap-index.xml',
            $origin . '/wp-sitemap.xml',
            $origin . '/feed/',
            $origin . '/feed.xml',
            $origin . '/rss.xml',
            $origin . '/atom.xml',
            $origin . '/index.xml',
            $origin . '/our-journals/feed/',
            $origin . '/our-journals/?feed=rss2',
        ];

        if ($this->looksLikePlos($url)) {
            $base[] = 'https://api.plos.org/search?q=*:*&rows=1&wt=json';
            $base[] = 'https://journals.plos.org/plosone/feed/atom';
            $base[] = 'https://feeds.plos.org/plosbiology/NewArticles';
            $base[] = 'https://feeds.plos.org/plosmedicine/NewArticles';
            $base[] = 'https://feeds.plos.org/ploscompbiol/NewArticles';
        }

        if ($this->looksLikeScienceDirect($url)) {
            $base[] = 'https://api.elsevier.com/content/search/sciencedirect?query=all(science)&count=1&httpAccept=application/json';
            $base[] = 'https://www.sciencedirect.com/sitemap.xml';
            $base[] = 'https://www.sciencedirect.com/sitemap_index.xml';
        }

        return array_values(array_unique($base));
    }


    /** @return array<int,array<string,string>> */
    private function sourceConnectorRecommendations(string $url): array
    {
        if ($this->looksLikeScienceDirect($url)) {
            return [
                [
                    'name' => 'Elsevier / ScienceDirect Search API connector',
                    'command' => 'php bin/mnb-scraper elsevier:search "machine learning" --rows=25 --json',
                    'purpose' => 'Search ScienceDirect metadata/content through the official Elsevier API instead of scraping protected ScienceDirect browse pages.',
                ],
                [
                    'name' => 'Elsevier article metadata connector',
                    'command' => 'php bin/mnb-scraper elsevier:doi "10.xxxx/example" --json',
                    'purpose' => 'Fetch article metadata/full-text fields by DOI when API access and entitlements are available.',
                ],
                [
                    'name' => 'Elsevier URL export connector',
                    'command' => 'php bin/mnb-scraper elsevier:urls "all(science)" --rows=100 --output=storage/elsevier-urls.txt',
                    'purpose' => 'Create a bulk URL list from API metadata for downstream allowed workflows.',
                ],
            ];
        }

        if (!$this->looksLikePlos($url)) {
            return [];
        }
        return [
            [
                'name' => 'PLOS Search API connector',
                'command' => 'php bin/mnb-scraper plos:search "*:*" --journal=plosone --rows=25 --json',
                'purpose' => 'Fetch PLOS article metadata without scraping protected marketing pages.',
            ],
            [
                'name' => 'PLOS journal RSS connector',
                'command' => 'php bin/mnb-scraper plos:feed plosone --rows=25 --json',
                'purpose' => 'Fetch latest article feed records where a public feed is available.',
            ],
            [
                'name' => 'PLOS URL export connector',
                'command' => 'php bin/mnb-scraper plos:urls "*:*" --journal=plosone --type=article --rows=100 --output=storage/plos-urls.txt',
                'purpose' => 'Create a bulk URL list from API metadata for downstream jobs.',
            ],
        ];
    }

    private function looksLikePlos(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        return $host === 'plos.org' || str_ends_with($host, '.plos.org') || $host === 'api.plos.org';
    }

    private function looksLikeScienceDirect(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        return $host === 'sciencedirect.com' || $host === 'www.sciencedirect.com' || $host === 'api.elsevier.com' || str_ends_with($host, '.elsevier.com');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . $port;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) !== 1) {
            return null;
        }
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractRobotsMeta(string $html): ?string
    {
        if (preg_match('~<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']robots["\']~i', $html, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    /** @return array<int,string> */
    private function extractLinks(string $html, string $baseUrl, int $limit): array
    {
        $out = [];
        if (preg_match_all('~href=["\']([^"\']+)["\']~i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $absolute = $this->absoluteUrl((string) $href, $baseUrl);
                if ($absolute !== null) {
                    $out[] = $absolute;
                }
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,string> */
    private function extractSitemapLocs(string $body): array
    {
        $out = [];
        if (preg_match_all('~<loc>\s*([^<]+)\s*</loc>~i', $body, $matches)) {
            foreach ($matches[1] as $loc) {
                $loc = trim(html_entity_decode((string) $loc, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($loc !== '') {
                    $out[] = $loc;
                }
            }
        }
        return array_values(array_unique($out));
    }

    private function absoluteUrl(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('~^(mailto|tel|javascript):~i', $href)) {
            return null;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $base = parse_url($baseUrl);
        if (!$base || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }
        $origin = strtolower((string) $base['scheme']) . '://' . strtolower((string) $base['host']) . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $path = (string) ($base['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $origin . ($dir === '' ? '' : $dir) . '/' . $href;
    }

    private function recommendation(int $statusCode, bool $challenge, ?string $contentType, string $body): string
    {
        if ($challenge) {
            return 'Protection/challenge detected. Prefer official source/API, sitemap/RSS, authorized browser mode, or manual/bulk URL list.';
        }
        if ($statusCode >= 200 && $statusCode < 300 && $this->extractSitemapLocs($body) !== []) {
            return 'Looks usable as a sitemap/feed source. Use bulk:crawl with exported <loc> URLs or build a source connector.';
        }
        if ($statusCode >= 200 && $statusCode < 300) {
            return 'Accessible candidate. Inspect links_sample or content type for use as alternate seed source.';
        }
        if ($statusCode === 403) {
            return '403 received. Do not force crawling; try another public source endpoint or authorized browser/API workflow.';
        }
        return 'Candidate checked. Use only if allowed and useful.';
    }
}
