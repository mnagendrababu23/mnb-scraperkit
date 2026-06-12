<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Intelligence;

final class UrlPrioritizer
{
    /** @param array<int,string> $urls @return array<string,mixed> */
    public function prioritize(array $urls): array
    {
        $extractor = new FeatureExtractor();
        $rows = [];
        foreach (array_values(array_unique(array_filter(array_map('strval', $urls)))) as $url) {
            $features = $extractor->urlFeatures($url);
            $rows[] = array_merge(['url' => $url], $features, ['priority_score' => $this->score($features)]);
        }
        usort($rows, static fn (array $a, array $b): int => ((float) $b['priority_score']) <=> ((float) $a['priority_score']));
        return [
            'intelligence_version' => '1.0.1',
            'generated_at' => date(DATE_ATOM),
            'urls_total' => count($rows),
            'rows' => $rows,
            'urls' => array_values(array_map(static fn (array $row): string => (string) $row['url'], $rows)),
        ];
    }

    /** @param array<string,mixed> $features */
    private function score(array $features): float
    {
        $score = 0.5;
        if (!empty($features['is_asset_url'])) { $score -= 0.4; }
        if (!empty($features['is_document_url'])) { $score += 0.1; }
        if (!empty($features['looks_article'])) { $score += 0.15; }
        if (!empty($features['looks_product'])) { $score += 0.15; }
        if (!empty($features['looks_job'])) { $score += 0.1; }
        if (!empty($features['looks_tender'])) { $score += 0.1; }
        $depth = (int) ($features['path_depth'] ?? 0);
        if ($depth >= 1 && $depth <= 4) { $score += 0.1; }
        if ($depth > 8) { $score -= 0.1; }
        if (!empty($features['has_query'])) { $score -= min(0.2, ((int) ($features['query_length'] ?? 0)) / 500); }
        return round(max(0.0, min(1.0, $score)), 3);
    }
}
