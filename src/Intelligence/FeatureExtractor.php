<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Intelligence;

/**
 * Deterministic ML-ready feature extraction for crawl pages and pipeline records.
 *
 * This class does not require a machine-learning dependency. It creates stable
 * numeric/categorical features that can be exported to PHP-ML or another trainer.
 */
final class FeatureExtractor
{
    /** @return array<string,mixed> */
    public function analyzeFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Input file not found: ' . $path);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Input must be a JSON crawl, pipeline, or source connector file.');
        }
        return $this->analyzeArray($data, $path);
    }

    /** @param array<mixed> $data @return array<string,mixed> */
    public function analyzeArray(array $data, ?string $source = null): array
    {
        $pages = $this->pagesFromData($data);
        $records = $this->recordsFromData($data);
        $urls = $this->urlsFromData($data);

        $pageFeatures = array_map(fn (array $page): array => $this->pageFeatures($page), $pages);
        $recordFeatures = array_map(fn (array $record): array => $this->recordFeatures($record), $records);

        return [
            'intelligence_version' => '3.4.0',
            'generated_at' => date(DATE_ATOM),
            'source' => $source,
            'summary' => [
                'pages_total' => count($pages),
                'records_total' => count($records),
                'urls_total' => count($urls),
                'status_counts' => $this->counts($pageFeatures, 'status'),
                'failure_type_counts' => $this->counts($pageFeatures, 'failure_type'),
                'record_type_counts' => $this->counts($recordFeatures, 'record_type'),
                'avg_page_text_length' => $this->average($pageFeatures, 'text_length'),
                'avg_record_field_count' => $this->average($recordFeatures, 'field_count'),
            ],
            'page_features' => $pageFeatures,
            'record_features' => $recordFeatures,
            'url_features' => array_map(fn (string $url): array => $this->urlFeatures($url), $urls),
        ];
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    public function pageFeatures(array $page): array
    {
        $url = (string) ($page['url'] ?? $page['source_url'] ?? '');
        $finalUrl = (string) ($page['final_url'] ?? $page['effective_url'] ?? $url);
        $title = (string) ($page['title'] ?? '');
        $text = (string) ($page['text'] ?? $page['body_text'] ?? $page['content'] ?? '');
        $html = (string) ($page['html'] ?? $page['body'] ?? '');
        $status = (string) ($page['status'] ?? ($page['ok'] ?? false ? 'completed' : 'unknown'));
        $failureType = (string) ($page['failure_type'] ?? $page['error_type'] ?? '');
        $httpStatus = (int) ($page['http_status'] ?? $page['status_code'] ?? 0);
        $combined = strtolower($title . ' ' . $text . ' ' . substr($html, 0, 200000) . ' ' . $url);

        return array_merge($this->urlFeatures($finalUrl ?: $url), [
            'url' => $url,
            'final_url' => $finalUrl,
            'status' => $status,
            'http_status' => $httpStatus,
            'failure_type' => $failureType,
            'title_length' => strlen($title),
            'text_length' => strlen($text),
            'html_length' => strlen($html),
            'word_count' => str_word_count(strip_tags($text ?: $html)),
            'link_count' => $this->countPattern($html, '~<a\s+[^>]*href\s*=~i'),
            'heading_count' => $this->countPattern($html, '~<h[1-6]\b~i'),
            'image_count' => $this->countPattern($html, '~<img\b~i'),
            'script_count' => $this->countPattern($html, '~<script\b~i'),
            'json_ld_count' => $this->countPattern($html, '~application/ld\+json~i'),
            'has_email' => preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $combined) === 1,
            'has_phone_hint' => preg_match('/\+?\d[\d\s().-]{7,}\d/', $combined) === 1,
            'has_price_hint' => preg_match('/(?:₹|rs\.?|\$|€|£|usd|inr|eur|gbp)\s*\d|\d+[,.]?\d*\s*(?:usd|inr|eur|gbp)/i', $combined) === 1,
            'has_date_hint' => preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}\b/', $combined) === 1,
            'has_js_app_marker' => preg_match('/\b(__next|nuxt|webpack|vite|react|angular|vue|ng-app|data-reactroot|app-root)\b/i', $combined) === 1,
            'has_browser_required_marker' => preg_match('/enable javascript|please enable js|checking your browser|cf-browser-verification|captcha/i', $combined) === 1,
            'has_schema_hint' => preg_match('/schema\.org|application\/ld\+json|itemscope/i', $combined) === 1,
        ]);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function recordFeatures(array $record): array
    {
        $fields = $record['fields'] ?? $record;
        if (!is_array($fields)) {
            $fields = [];
        }
        $validation = is_array($record['validation'] ?? null) ? $record['validation'] : [];
        $missing = is_array($validation['missing_fields'] ?? null) ? $validation['missing_fields'] : [];
        $warnings = is_array($validation['warnings'] ?? null) ? $validation['warnings'] : [];
        $errors = is_array($validation['errors'] ?? null) ? $validation['errors'] : [];
        $nonEmpty = 0;
        foreach ($fields as $value) {
            if ($value !== null && trim((string) (is_scalar($value) ? $value : json_encode($value))) !== '') {
                $nonEmpty++;
            }
        }

        return [
            'record_id' => (string) ($record['record_id'] ?? ''),
            'record_type' => (string) ($record['record_type'] ?? $record['type'] ?? 'record'),
            'profile' => (string) ($record['profile'] ?? ''),
            'field_count' => count($fields),
            'non_empty_field_count' => $nonEmpty,
            'missing_field_count' => count($missing),
            'warning_count' => count($warnings),
            'error_count' => count($errors),
            'quality_score' => (float) ($record['quality_score'] ?? $record['quality'] ?? 0),
            'has_dedupe_key' => trim((string) ($record['dedupe_key'] ?? '')) !== '',
            'has_source_url' => trim((string) ($record['source_url'] ?? '')) !== '',
            'has_final_url' => trim((string) ($record['final_url'] ?? '')) !== '',
            'field_names' => array_values(array_map('strval', array_keys($fields))),
        ];
    }

    /** @return array<string,mixed> */
    public function urlFeatures(string $url): array
    {
        $parts = parse_url($url) ?: [];
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
        $lower = strtolower($url);

        return [
            'host' => strtolower((string) ($parts['host'] ?? '')),
            'scheme' => strtolower((string) ($parts['scheme'] ?? '')),
            'path_depth' => count($segments),
            'query_length' => strlen($query),
            'has_query' => $query !== '',
            'is_asset_url' => preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|css|js|woff2?|ttf|mp4|mp3|zip|rar)(?:\?|$)/i', $url) === 1,
            'is_document_url' => preg_match('/\.(?:pdf|docx?|xlsx?|pptx?)(?:\?|$)/i', $url) === 1,
            'looks_article' => preg_match('/article|blog|news|post|paper|doi|journal|publication/i', $lower) === 1,
            'looks_product' => preg_match('/product|item|sku|shop|cart|price|buy/i', $lower) === 1,
            'looks_job' => preg_match('/job|career|vacancy|hiring|apply/i', $lower) === 1,
            'looks_tender' => preg_match('/tender|procurement|notice|bid|rfp|auction/i', $lower) === 1,
        ];
    }

    /** @param array<mixed> $data @return array<int,array<string,mixed>> */
    private function pagesFromData(array $data): array
    {
        foreach (['pages', 'results', 'crawl_results'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, static fn (mixed $row): bool => is_array($row) && (isset($row['url']) || isset($row['final_url']) || isset($row['status_code']))));
        }
        return isset($data['url']) || isset($data['final_url']) ? [$data] : [];
    }

    /** @param array<mixed> $data @return array<int,array<string,mixed>> */
    private function recordsFromData(array $data): array
    {
        if (isset($data['records']) && is_array($data['records'])) {
            return array_values(array_filter($data['records'], 'is_array'));
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, static fn (mixed $row): bool => is_array($row) && (isset($row['record_type']) || isset($row['fields']))));
        }
        return isset($data['record_type']) || isset($data['fields']) ? [$data] : [];
    }

    /** @param array<mixed> $data @return array<int,string> */
    private function urlsFromData(array $data): array
    {
        $urls = [];
        $walk = static function (mixed $value) use (&$walk, &$urls): void {
            if (is_string($value) && preg_match('~^https?://~i', $value) === 1) {
                $urls[] = $value;
                return;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
            }
        };
        $walk($data);
        return array_values(array_unique($urls));
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,int> */
    private function counts(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$field] ?? '')) ?: 'none';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function average(array $rows, string $field): float
    {
        if ($rows === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) ($row[$field] ?? 0);
        }
        return round($sum / max(1, count($rows)), 2);
    }

    private function countPattern(string $text, string $pattern): int
    {
        if ($text === '') {
            return 0;
        }
        return preg_match_all($pattern, $text) ?: 0;
    }
}
