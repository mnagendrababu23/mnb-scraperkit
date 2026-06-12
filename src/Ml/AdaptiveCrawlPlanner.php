<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Ml;

use Mnb\ScraperKit\Intelligence\UrlPrioritizer;

/**
 * Combines deterministic URL priority, optional learned model scores, diversity,
 * exploration ratio, and crawl-budget controls into an operator-ready plan.
 */
final class AdaptiveCrawlPlanner
{
    public const VERSION = '1.0.3';

    /** @param array<int,string> $urls @param array<string,mixed>|null $model @param array<string,mixed> $options @return array<string,mixed> */
    public function plan(array $urls, ?array $model = null, array $options = []): array
    {
        $budget = max(1, (int) ($options['crawl_budget'] ?? $options['max_pages'] ?? 25));
        $exploreRatio = max(0.0, min(0.5, (float) ($options['explore_ratio'] ?? 0.15)));
        $threshold = max(0.0, min(1.0, (float) ($options['score_threshold'] ?? ($model['options']['score_threshold'] ?? 0.55))));
        $profile = (string) ($options['profile'] ?? 'auto');
        $base = (new UrlPrioritizer())->prioritize($urls);
        $modelRunner = new CrawlMlModel();
        $rows = [];

        foreach (($base['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            $ml = $model ? $modelRunner->scoreUrl($model, $url) : ['ml_score' => null, 'confidence_band' => 'none', 'top_reasons' => []];
            $baseScore = (float) ($row['priority_score'] ?? 0.5);
            $mlScore = is_numeric($ml['ml_score'] ?? null) ? (float) $ml['ml_score'] : $baseScore;
            $diversity = $this->diversityScore($url, $rows);
            $combined = round(($baseScore * 0.35) + ($mlScore * 0.5) + ($diversity * 0.15), 3);
            $action = $combined >= $threshold ? 'crawl' : 'review';
            if (!empty($row['is_asset_url'])) {
                $combined = max(0.0, $combined - 0.2);
                $action = 'skip_asset';
            }
            $rows[] = [
                'url' => $url,
                'action' => $action,
                'adaptive_score' => $combined,
                'base_priority_score' => $baseScore,
                'ml_score' => $mlScore,
                'diversity_score' => $diversity,
                'confidence_band' => (string) ($ml['confidence_band'] ?? 'none'),
                'recommended_profile' => $profile === 'auto' ? $this->profileForUrl($url) : $profile,
                'recommended_delay_ms' => $this->delayForScore($combined),
                'reasons' => array_values(array_unique(array_merge((array) ($ml['top_reasons'] ?? []), $this->urlReasons($row)))),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ((float) $b['adaptive_score']) <=> ((float) $a['adaptive_score']));
        $crawl = [];
        $review = [];
        $explore = [];
        $seenHosts = [];
        $exploreBudget = (int) floor($budget * $exploreRatio);
        $crawlBudget = max(1, $budget - $exploreBudget);

        foreach ($rows as $row) {
            $host = (string) (parse_url((string) $row['url'], PHP_URL_HOST) ?: '');
            if (($row['action'] ?? '') === 'crawl' && count($crawl) < $crawlBudget) {
                $crawl[] = $row;
                $seenHosts[$host] = true;
            } else {
                $review[] = $row;
            }
        }
        foreach ($review as $row) {
            if (count($explore) >= $exploreBudget) {
                break;
            }
            $host = (string) (parse_url((string) $row['url'], PHP_URL_HOST) ?: '');
            if (!isset($seenHosts[$host]) && ($row['action'] ?? '') !== 'skip_asset') {
                $row['action'] = 'explore';
                $row['reasons'][] = 'exploration_diversity_slot';
                $explore[] = $row;
                $seenHosts[$host] = true;
            }
        }

        $selected = array_slice(array_merge($crawl, $explore), 0, $budget);
        $selectedUrls = array_values(array_map(static fn (array $row): string => (string) $row['url'], $selected));

        return [
            'adaptive_plan_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'strategy' => $model ? 'hybrid_priority_ml_diversity' : 'priority_diversity_no_model',
            'crawl_budget' => $budget,
            'explore_ratio' => $exploreRatio,
            'score_threshold' => $threshold,
            'urls_total' => count($rows),
            'selected_total' => count($selected),
            'selected_urls' => $selectedUrls,
            'selected' => $selected,
            'review_queue' => array_values(array_slice(array_filter($rows, static fn (array $row): bool => !in_array((string) $row['url'], $selectedUrls, true)), 0, 100)),
            'recommended_command' => 'php bin/mnb-scraper bulk:crawl selected-urls.txt --pipeline --profile=' . ($profile === 'auto' ? 'seo' : $profile) . ' --gap-ms=1500',
            'policy' => [
                'respect_robots_recommended' => true,
                'do_not_bypass_access_controls' => true,
                'human_review_for_low_confidence' => true,
            ],
        ];
    }

    private function diversityScore(string $url, array $existingRows): float
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
        $firstSegment = explode('/', $path)[0] ?? '';
        foreach ($existingRows as $row) {
            $otherHost = (string) (parse_url((string) ($row['url'] ?? ''), PHP_URL_HOST) ?: '');
            $otherPath = trim((string) (parse_url((string) ($row['url'] ?? ''), PHP_URL_PATH) ?: ''), '/');
            $otherFirst = explode('/', $otherPath)[0] ?? '';
            if ($host === $otherHost && $firstSegment === $otherFirst) {
                return 0.25;
            }
        }
        return 1.0;
    }

    private function delayForScore(float $score): int
    {
        if ($score >= 0.85) { return 1200; }
        if ($score >= 0.65) { return 1800; }
        return 2500;
    }

    private function profileForUrl(string $url): string
    {
        $lower = strtolower($url);
        if (preg_match('/article|journal|doi|publication|paper|chapter|book/', $lower)) { return 'academic'; }
        if (preg_match('/product|sku|shop|price|buy/', $lower)) { return 'ecommerce'; }
        if (preg_match('/job|career|hiring|vacancy/', $lower)) { return 'jobs'; }
        if (preg_match('/tender|procurement|rfp|bid/', $lower)) { return 'tender'; }
        return 'seo';
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function urlReasons(array $row): array
    {
        $reasons = [];
        foreach (['looks_article', 'looks_product', 'looks_job', 'looks_tender', 'is_document_url', 'is_asset_url', 'has_query'] as $flag) {
            if (!empty($row[$flag])) {
                $reasons[] = 'url_feature:' . $flag;
            }
        }
        return $reasons;
    }
}
