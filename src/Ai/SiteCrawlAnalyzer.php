<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Ai;

use Mnb\ScraperKit\Browser\BrowserFallbackDetector;
use Mnb\ScraperKit\Extraction\ExtractionRecipe;
use Mnb\ScraperKit\Extraction\PageComponentExtractor;
use Mnb\ScraperKit\Extraction\ExtractionOptions;
use Mnb\ScraperKit\RuleBuilder\HtmlSignalAnalyzer;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;

final class SiteCrawlAnalyzer
{
    public const VERSION = '4.3.1';

    public function __construct(private readonly string $rootDir)
    {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function analyze(string $url, ?string $html = null, array $options = []): array
    {
        $goal = (string) ($options['goal'] ?? 'general_metadata');
        $provider = (string) ($options['provider'] ?? 'rule_based');
        $safe = $this->inspectSafety($url);
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $signals = $this->urlSignals($url, $goal);

        $htmlReport = null;
        if (is_string($html) && trim($html) !== '') {
            $htmlReport = $this->htmlSignals($html, $url);
            $signals = $this->mergeSignals($signals, $htmlReport);
        }

        $score = $this->score($signals, $safe);
        $risk = $this->risk($signals, $safe, $host);
        $flexibility = $score >= 78 ? 'high' : ($score >= 48 ? 'medium' : 'low');
        $requiresBrowser = (bool) ($signals['requires_browser'] ?? false);
        $recipes = $this->suggestRecipes($goal, $host, $path, $signals);
        $mode = $this->recommendedMode($goal, $risk, $signals);

        return [
            'ok' => true,
            'ai_crawl_analysis_version' => self::VERSION,
            'provider' => $provider,
            'provider_status' => AiProviderRegistry::providers()[$provider] ?? AiProviderRegistry::providers()['rule_based'],
            'external_ai_invoked' => false,
            'external_ai_note' => 'v4.3.1 ships deterministic analysis by default. Wire an enterprise-approved adapter before sending page content to external AI services.',
            'url' => $url,
            'domain' => $host,
            'path' => $path,
            'goal' => $goal,
            'crawl_flexibility' => $flexibility,
            'flexibility_score' => $score,
            'risk' => $risk,
            'recommended_mode' => $mode,
            'requires_browser' => $requiresBrowser,
            'detected_page_types' => array_values(array_unique((array) ($signals['page_types'] ?? []))),
            'detected_sources' => array_values(array_unique((array) ($signals['sources'] ?? []))),
            'suggested_recipes' => $recipes,
            'safe_limits' => [
                'delay_ms' => $risk === 'high' ? 3500 : ($risk === 'medium' ? 2500 : 1200),
                'max_pages' => $risk === 'high' ? 10 : ($risk === 'medium' ? 25 : 50),
                'respect_robots' => true,
                'metadata_only' => str_contains($goal, 'metadata') || $risk !== 'low',
            ],
            'extractable_fields' => $this->extractableFields($goal, $signals),
            'capability_notes' => $this->capabilityNotes($signals, $risk, $mode),
            'safety' => $safe,
            'html_signals' => $htmlReport,
            'audit' => [
                'generated_at' => date(DATE_ATOM),
                'decision_basis' => ['url_patterns', 'public_html_signals', 'known_safe_defaults', 'recipe_catalog'],
                'blocked_behaviors' => ['paywall_bypass', 'captcha_bypass', 'credential_harvesting', 'search_result_page_scraping'],
            ],
        ];
    }


    /** @return array<string,mixed> */
    private function inspectSafety(string $url): array
    {
        try {
            (new UrlSafetyGuard())->assertAllowed($url);
            return ['ok' => true, 'url' => $url, 'message' => 'URL passed default public-network safety checks.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'url' => $url, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function urlSignals(string $url, string $goal): array
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $types = [];
        $sources = [];
        if (str_contains($path, 'sitemap')) {
            $sources[] = 'sitemap';
        }
        if (str_contains($path, 'rss') || str_contains($path, 'feed') || str_contains($path, 'atom')) {
            $sources[] = 'feed';
        }
        if (str_contains($path, '/journal/')) {
            $types[] = 'journal';
        }
        if (str_contains($path, 'volumes-and-issues')) {
            $types[] = 'volume_issue';
        }
        if (str_contains($path, '/article/') || preg_match('#/doi/(abs|full|10\.)#', $path) === 1) {
            $types[] = 'article';
        }
        if (str_contains($path, '/book/')) {
            $types[] = 'book';
        }
        if (str_contains($path, '/chapter/')) {
            $types[] = 'chapter';
        }
        if (str_contains($path, 'search')) {
            $types[] = 'search_results';
        }
        if ($types === []) {
            $types[] = str_contains($goal, 'article') ? 'candidate_article_or_collection' : 'website';
        }
        if (str_contains($host, 'springer') || str_contains($host, 'nature') || str_contains($host, 'science') || str_contains($host, 'elsevier')) {
            $sources[] = 'doi';
            $sources[] = 'metadata_page';
        }
        return [
            'page_types' => $types,
            'sources' => array_values(array_unique($sources)),
            'known_publisher_domain' => str_contains($host, 'springer') || str_contains($host, 'nature') || str_contains($host, 'science') || str_contains($host, 'elsevier') || str_contains($host, 'wiley'),
            'requires_browser' => false,
            'structured_metadata' => false,
            'repeated_components' => false,
            'links_total' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function htmlSignals(string $html, string $baseUrl): array
    {
        if (!class_exists('DOMDocument')) {
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $html, $links);
            preg_match_all('/<h[1-3]\b/i', $html, $headings);
            preg_match_all('/class=["\'][^"\']*(card|result|item|entry|article)[^"\']*["\']/i', $html, $components);
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $title);
            return [
                'title' => isset($title[1]) ? trim(strip_tags($title[1])) : null,
                'text_length' => strlen($text),
                'links_total' => count($links[1] ?? []),
                'headings_total' => count($headings[0] ?? []),
                'tables_total' => substr_count(strtolower($html), '<table'),
                'lists_total' => substr_count(strtolower($html), '<ul') + substr_count(strtolower($html), '<ol'),
                'components_total' => count($components[0] ?? []),
                'repeated_components_total' => count($components[0] ?? []) >= 2 ? 1 : 0,
                'json_ld_types' => [],
                'keywords' => [],
                'requires_browser' => strlen($text) < 80 && count($links[1] ?? []) === 0,
                'browser_reasons' => [],
            ];
        }
        $signal = (new HtmlSignalAnalyzer())->analyze($html, $baseUrl);
        $components = (new PageComponentExtractor())->extract($html, $baseUrl, new ExtractionOptions(['components', 'links', 'headings', 'tables', 'lists']));
        $fallback = (new BrowserFallbackDetector())->analyze($html, [], []);
        return [
            'title' => $signal['title'] ?? null,
            'text_length' => $signal['text_length'] ?? 0,
            'links_total' => count((array) ($components['links'] ?? [])),
            'headings_total' => count((array) ($components['headings']['h1'] ?? [])) + count((array) ($components['headings']['h2'] ?? [])) + count((array) ($components['headings']['h3'] ?? [])),
            'tables_total' => count((array) ($components['tables'] ?? [])),
            'lists_total' => count((array) ($components['lists'] ?? [])),
            'components_total' => count((array) ($components['components'] ?? [])),
            'repeated_components_total' => count((array) ($components['repeated_components'] ?? [])),
            'json_ld_types' => $signal['json_ld_types'] ?? [],
            'keywords' => $signal['keywords'] ?? [],
            'requires_browser' => (bool) ($fallback['requires_browser'] ?? false),
            'browser_reasons' => $fallback['reasons'] ?? [],
        ];
    }

    /** @param array<string,mixed> $signals @param array<string,mixed> $htmlReport @return array<string,mixed> */
    private function mergeSignals(array $signals, array $htmlReport): array
    {
        $signals['requires_browser'] = (bool) ($htmlReport['requires_browser'] ?? false);
        $signals['structured_metadata'] = count((array) ($htmlReport['json_ld_types'] ?? [])) > 0;
        $signals['repeated_components'] = (int) ($htmlReport['repeated_components_total'] ?? 0) > 0 || (int) ($htmlReport['components_total'] ?? 0) > 0;
        $signals['links_total'] = (int) ($htmlReport['links_total'] ?? 0);
        if ((int) ($htmlReport['tables_total'] ?? 0) > 0) {
            $signals['page_types'][] = 'table_data';
        }
        if (count((array) ($htmlReport['json_ld_types'] ?? [])) > 0) {
            $signals['sources'][] = 'json_ld';
        }
        if (preg_match('/citation_(title|doi|author|journal)/i', json_encode($htmlReport) ?: '') === 1) {
            $signals['sources'][] = 'citation_meta';
        }
        return $signals;
    }

    /** @param array<string,mixed> $signals @param array<string,mixed> $safe */
    private function score(array $signals, array $safe): int
    {
        if (($safe['ok'] ?? false) !== true) {
            return 10;
        }
        $score = 35;
        $score += count((array) ($signals['sources'] ?? [])) * 9;
        $score += count((array) ($signals['page_types'] ?? [])) * 5;
        if ((bool) ($signals['structured_metadata'] ?? false)) {
            $score += 15;
        }
        if ((bool) ($signals['repeated_components'] ?? false)) {
            $score += 10;
        }
        if ((bool) ($signals['known_publisher_domain'] ?? false)) {
            $score += 7;
        }
        if ((bool) ($signals['requires_browser'] ?? false)) {
            $score -= 12;
        }
        return max(0, min(100, $score));
    }

    /** @param array<string,mixed> $signals @param array<string,mixed> $safe */
    private function risk(array $signals, array $safe, string $host): string
    {
        if (($safe['ok'] ?? false) !== true) {
            return 'blocked';
        }
        $highDomains = ['springer', 'nature', 'science.org', 'elsevier', 'wiley', 'ieee', 'acm', 'acs.org', 'cell.com'];
        foreach ($highDomains as $needle) {
            if (str_contains(strtolower($host), $needle)) {
                return 'high';
            }
        }
        if (in_array('search_results', (array) ($signals['page_types'] ?? []), true)) {
            return 'medium';
        }
        return (bool) ($signals['requires_browser'] ?? false) ? 'medium' : 'low';
    }

    /** @param array<string,mixed> $signals @return list<string> */
    private function suggestRecipes(string $goal, string $host, string $path, array $signals): array
    {
        $recipes = [];
        if (str_contains($goal, 'article') || str_contains($goal, 'doi') || str_contains($path, '/article/') || str_contains($path, '/chapter/')) {
            $recipes[] = str_contains($host, 'springer') ? 'springer-article' : 'generic-page';
        }
        if ((bool) ($signals['repeated_components'] ?? false) || str_contains($goal, 'component')) {
            $recipes[] = 'generic-page';
        }
        if ($recipes === []) {
            $recipes[] = 'generic-page';
        }
        $catalog = ExtractionRecipe::catalog($this->rootDir . '/config/extraction/recipes');
        $available = array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $catalog);
        return array_values(array_filter(array_unique($recipes), static fn(string $id): bool => $available === [] || in_array($id, $available, true)));
    }

    /** @param array<string,mixed> $signals */
    private function recommendedMode(string $goal, string $risk, array $signals): string
    {
        if ($risk === 'blocked') {
            return 'blocked';
        }
        if (str_contains($goal, 'article') || str_contains($goal, 'metadata') || (bool) ($signals['known_publisher_domain'] ?? false)) {
            return 'metadata_only';
        }
        return (bool) ($signals['requires_browser'] ?? false) ? 'browser_assisted_public_pages' : 'public_html_extraction';
    }

    /** @param array<string,mixed> $signals @return list<string> */
    private function extractableFields(string $goal, array $signals): array
    {
        $fields = ['title', 'canonical_url', 'links', 'headings', 'plain_text'];
        if (str_contains($goal, 'article') || str_contains($goal, 'metadata') || in_array('article', (array) ($signals['page_types'] ?? []), true)) {
            array_push($fields, 'doi', 'authors', 'published_at', 'abstract', 'keywords', 'references', 'journal', 'pdf_url');
        }
        if ((bool) ($signals['repeated_components'] ?? false)) {
            array_push($fields, 'cards', 'tables', 'lists', 'download_links');
        }
        return array_values(array_unique($fields));
    }

    /** @param array<string,mixed> $signals @return list<string> */
    private function capabilityNotes(array $signals, string $risk, string $mode): array
    {
        $notes = [];
        $notes[] = 'Use robots-aware, low-rate crawling and store the generated analysis with each job.';
        if ($mode === 'metadata_only') {
            $notes[] = 'Prefer API, sitemap, RSS/TOC, DOI, and public article metadata pages before HTML crawling.';
        }
        if ((bool) ($signals['requires_browser'] ?? false)) {
            $notes[] = 'Browser rendering may be required, but only for public or authorized workflows.';
        }
        if ($risk === 'high') {
            $notes[] = 'High-risk publisher/platform target: do not bypass paywalls, login walls, CAPTCHA, rate limits, or access controls.';
        }
        return $notes;
    }
}
