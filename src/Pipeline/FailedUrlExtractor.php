<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class FailedUrlExtractor
{
    /**
     * @param array<string,mixed> $crawl
     * @return array<int,array<string,mixed>>
     */
    public function extract(array $crawl, ?string $onlyType = null, bool $includeSkipped = false): array
    {
        $pages = is_array($crawl['pages'] ?? null) ? $crawl['pages'] : [];
        $out = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $isSkipped = (bool) ($page['skipped'] ?? false);
            $error = $page['error'] ?? null;
            $status = isset($page['status_code']) ? (int) $page['status_code'] : null;
            $type = (string) ($page['failure_type'] ?? '');
            $failed = $error !== null || ($status !== null && $status >= 400) || ($includeSkipped && $isSkipped);
            if (!$failed) {
                continue;
            }
            if ($onlyType !== null && $onlyType !== '' && $type !== $onlyType) {
                continue;
            }
            $out[] = [
                'url' => $page['url'] ?? null,
                'final_url' => $page['final_url'] ?? null,
                'raw_final_url' => $page['raw_final_url'] ?? null,
                'status_code' => $status,
                'failure_type' => $type ?: null,
                'error' => $error,
                'skipped' => $isSkipped,
                'skip_reason' => $page['skip_reason'] ?? null,
                'depth' => $page['depth'] ?? null,
                'response_time_ms' => $page['response_time_ms'] ?? null,
                'protection' => $page['protection'] ?? [],
                'recommendation' => $this->recommendation($type, $status, $page),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $page */
    private function recommendation(string $type, ?int $status, array $page): string
    {
        if (str_contains($type, 'challenge') || str_contains($type, 'protection')) {
            return 'Do not run normal extraction. Try source:discover, official API/source, sitemap/RSS, authorized browser mode, or manual bulk URL list.';
        }
        if ($type === 'auth_or_cookie_redirect') {
            return 'Auth/cookie redirect flow. Use authorized session/browser workflow or skip identity-provider final URLs.';
        }
        if ($type === 'redirect_limit') {
            return 'Redirect limit reached. Check final URL, cookie/session behavior, and skip/allow filters.';
        }
        if ($status !== null && $status >= 400) {
            return 'HTTP error page. Check robots, source availability, headers, and safer alternate source endpoints.';
        }
        if (($page['skipped'] ?? false) === true) {
            return 'Skipped by crawl policy. Review skip_reason and adjust filters only for authorized sources.';
        }
        return 'Review failed page diagnostics.';
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function writeRetryUrlFile(array $rows, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $lines = [];
        foreach ($rows as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url !== '' && preg_match('~^https?://~i', $url)) {
                $lines[] = $url;
            }
        }
        file_put_contents($path, implode(PHP_EOL, array_values(array_unique($lines))) . PHP_EOL);
    }
}
