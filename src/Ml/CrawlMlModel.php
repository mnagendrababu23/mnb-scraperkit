<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Ml;

use Mnb\ScraperKit\Intelligence\FeatureExtractor;

/**
 * Lightweight deterministic URL relevance model.
 *
 * It intentionally avoids heavy ML dependencies. The model learns token and
 * feature weights from positive/negative URL examples, then can be used for
 * crawl prioritization, adaptive planning, and JSONL training export.
 */
final class CrawlMlModel
{
    public const VERSION = '1.0.3';

    /** @param array<int,string> $positiveUrls @param array<int,string> $negativeUrls @return array<string,mixed> */
    public function train(array $positiveUrls, array $negativeUrls, array $options = []): array
    {
        $positiveUrls = $this->cleanUrls($positiveUrls);
        $negativeUrls = $this->cleanUrls($negativeUrls);
        $minTokenLength = max(2, (int) ($options['min_token_length'] ?? 3));
        $extractor = new FeatureExtractor();

        $positiveTokenCounts = [];
        $negativeTokenCounts = [];
        $positiveFeatureSums = [];
        $negativeFeatureSums = [];

        foreach ($positiveUrls as $url) {
            $this->countTokens($this->tokens($url, $minTokenLength), $positiveTokenCounts);
            $this->sumFeatures($extractor->urlFeatures($url), $positiveFeatureSums);
        }
        foreach ($negativeUrls as $url) {
            $this->countTokens($this->tokens($url, $minTokenLength), $negativeTokenCounts);
            $this->sumFeatures($extractor->urlFeatures($url), $negativeFeatureSums);
        }

        $positiveTotal = max(1, array_sum($positiveTokenCounts));
        $negativeTotal = max(1, array_sum($negativeTokenCounts));
        $vocabulary = array_values(array_unique(array_merge(array_keys($positiveTokenCounts), array_keys($negativeTokenCounts))));
        sort($vocabulary);

        $tokenWeights = [];
        foreach ($vocabulary as $token) {
            $posRate = (($positiveTokenCounts[$token] ?? 0) + 1) / ($positiveTotal + count($vocabulary));
            $negRate = (($negativeTokenCounts[$token] ?? 0) + 1) / ($negativeTotal + count($vocabulary));
            $weight = log($posRate / $negRate);
            if (abs($weight) >= 0.15) {
                $tokenWeights[$token] = round($weight, 4);
            }
        }
        arsort($tokenWeights);

        $featureWeights = $this->featureWeights($positiveFeatureSums, count($positiveUrls), $negativeFeatureSums, count($negativeUrls));

        return [
            'ml_model_version' => self::VERSION,
            'model_type' => 'deterministic_url_relevance',
            'generated_at' => date(DATE_ATOM),
            'training_summary' => [
                'positive_examples' => count($positiveUrls),
                'negative_examples' => count($negativeUrls),
                'vocabulary_total' => count($vocabulary),
                'weighted_tokens_total' => count($tokenWeights),
                'feature_weights_total' => count($featureWeights),
            ],
            'options' => [
                'min_token_length' => $minTokenLength,
                'score_threshold' => (float) ($options['score_threshold'] ?? 0.58),
            ],
            'priors' => [
                'positive' => round((count($positiveUrls) + 1) / max(2, count($positiveUrls) + count($negativeUrls) + 2), 4),
                'negative' => round((count($negativeUrls) + 1) / max(2, count($positiveUrls) + count($negativeUrls) + 2), 4),
            ],
            'token_weights' => array_slice($tokenWeights, 0, 250, true),
            'feature_weights' => $featureWeights,
            'positive_examples' => array_slice($positiveUrls, 0, 25),
            'negative_examples' => array_slice($negativeUrls, 0, 25),
            'policy' => [
                'deterministic' => true,
                'no_external_ai_call' => true,
                'safe_for_ci' => true,
                'recommended_use' => 'crawl_prioritization_not_access_control_bypass',
            ],
        ];
    }

    /** @param array<string,mixed> $model @param array<int,string> $urls @return array<string,mixed> */
    public function scoreUrls(array $model, array $urls): array
    {
        $rows = [];
        foreach ($this->cleanUrls($urls) as $url) {
            $rows[] = $this->scoreUrl($model, $url);
        }
        usort($rows, static fn (array $a, array $b): int => ((float) $b['ml_score']) <=> ((float) $a['ml_score']));

        return [
            'ml_score_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'urls_total' => count($rows),
            'score_threshold' => (float) ($model['options']['score_threshold'] ?? 0.58),
            'rows' => $rows,
            'urls' => array_values(array_map(static fn (array $row): string => (string) $row['url'], $rows)),
        ];
    }

    /** @param array<string,mixed> $model @return array<string,mixed> */
    public function scoreUrl(array $model, string $url): array
    {
        $extractor = new FeatureExtractor();
        $features = $extractor->urlFeatures($url);
        $tokens = $this->tokens($url, (int) ($model['options']['min_token_length'] ?? 3));
        $tokenWeights = is_array($model['token_weights'] ?? null) ? $model['token_weights'] : [];
        $featureWeights = is_array($model['feature_weights'] ?? null) ? $model['feature_weights'] : [];
        $raw = 0.0;
        $reasons = [];

        foreach (array_unique($tokens) as $token) {
            if (isset($tokenWeights[$token])) {
                $weight = (float) $tokenWeights[$token];
                $raw += $weight;
                if ($weight > 0) {
                    $reasons[] = 'positive_token:' . $token;
                } elseif ($weight < 0) {
                    $reasons[] = 'negative_token:' . $token;
                }
            }
        }

        foreach ($featureWeights as $name => $weight) {
            $value = (float) ($features[$name] ?? 0);
            if ($value !== 0.0) {
                $raw += (float) $weight * $value;
                if ((float) $weight > 0) {
                    $reasons[] = 'positive_feature:' . $name;
                } elseif ((float) $weight < 0) {
                    $reasons[] = 'negative_feature:' . $name;
                }
            }
        }

        $priorPositive = max(0.001, (float) ($model['priors']['positive'] ?? 0.5));
        $priorNegative = max(0.001, (float) ($model['priors']['negative'] ?? 0.5));
        $raw += log($priorPositive / $priorNegative);
        $score = 1.0 / (1.0 + exp(-$raw));
        $score = round(max(0.0, min(1.0, $score)), 3);
        $threshold = (float) ($model['options']['score_threshold'] ?? 0.58);

        return [
            'url' => $url,
            'ml_score' => $score,
            'label' => $score >= $threshold ? 'likely_relevant' : 'review_or_skip',
            'confidence_band' => $this->band($score),
            'raw_score' => round($raw, 4),
            'top_reasons' => array_slice(array_values(array_unique($reasons)), 0, 8),
            'features' => $features,
        ];
    }

    /** @param array<string,mixed> $model */
    public function explain(array $model): array
    {
        $tokens = is_array($model['token_weights'] ?? null) ? $model['token_weights'] : [];
        arsort($tokens);
        $positive = array_slice($tokens, 0, 15, true);
        asort($tokens);
        $negative = array_slice($tokens, 0, 15, true);

        return [
            'ml_model_version' => self::VERSION,
            'model_type' => (string) ($model['model_type'] ?? 'unknown'),
            'training_summary' => $model['training_summary'] ?? [],
            'score_threshold' => $model['options']['score_threshold'] ?? 0.58,
            'strong_positive_tokens' => $positive,
            'strong_negative_tokens' => $negative,
            'feature_weights' => $model['feature_weights'] ?? [],
            'policy' => $model['policy'] ?? [],
        ];
    }

    /** @param array<int,string> $urls @return array<int,string> */
    public function cleanUrls(array $urls): array
    {
        $clean = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url !== '' && preg_match('~^https?://~i', $url) === 1) {
                $clean[] = $url;
            }
        }
        return array_values(array_unique($clean));
    }

    /** @return array<int,string> */
    private function tokens(string $url, int $minLength): array
    {
        $parts = parse_url($url) ?: [];
        $text = strtolower((string) ($parts['host'] ?? '') . ' ' . (string) ($parts['path'] ?? '') . ' ' . (string) ($parts['query'] ?? ''));
        preg_match_all('/[a-z0-9][a-z0-9_-]{1,}/', $text, $matches);
        $stop = ['http', 'https', 'www', 'com', 'org', 'net', 'html', 'php', 'aspx', 'index'];
        return array_values(array_filter($matches[0] ?? [], static fn (string $t): bool => strlen($t) >= $minLength && !in_array($t, $stop, true)));
    }

    /** @param array<int,string> $tokens @param array<string,int> $counts */
    private function countTokens(array $tokens, array &$counts): void
    {
        foreach ($tokens as $token) {
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }
    }

    /** @param array<string,mixed> $features @param array<string,float> $sums */
    private function sumFeatures(array $features, array &$sums): void
    {
        foreach ($features as $name => $value) {
            if (is_bool($value)) {
                $sums[$name] = ($sums[$name] ?? 0.0) + ($value ? 1.0 : 0.0);
            } elseif (is_int($value) || is_float($value)) {
                $sums[$name] = ($sums[$name] ?? 0.0) + min(10.0, max(0.0, (float) $value));
            }
        }
    }

    /** @param array<string,float> $positive @param array<string,float> $negative @return array<string,float> */
    private function featureWeights(array $positive, int $positiveCount, array $negative, int $negativeCount): array
    {
        $weights = [];
        $keys = array_values(array_unique(array_merge(array_keys($positive), array_keys($negative))));
        sort($keys);
        foreach ($keys as $key) {
            if (in_array($key, ['query_length', 'path_depth'], true)) {
                $pos = ($positive[$key] ?? 0.0) / max(1, $positiveCount) / 10;
                $neg = ($negative[$key] ?? 0.0) / max(1, $negativeCount) / 10;
            } else {
                $pos = ($positive[$key] ?? 0.0) / max(1, $positiveCount);
                $neg = ($negative[$key] ?? 0.0) / max(1, $negativeCount);
            }
            $delta = $pos - $neg;
            if (abs($delta) >= 0.2) {
                $weights[$key] = round(max(-0.6, min(0.6, $delta)), 4);
            }
        }
        ksort($weights);
        return $weights;
    }

    private function band(float $score): string
    {
        return $score >= 0.85 ? 'very_high' : ($score >= 0.68 ? 'high' : ($score >= 0.45 ? 'medium' : 'low'));
    }
}
