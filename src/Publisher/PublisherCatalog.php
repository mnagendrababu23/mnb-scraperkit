<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Publisher;

/**
 * Local academic publisher crawl catalog for safe metadata-first workflows.
 */
final class PublisherCatalog
{
    private string $catalogFile;

    public function __construct(private string $rootDir, ?string $catalogFile = null)
    {
        $this->rootDir = rtrim($rootDir, '/\\');
        $this->catalogFile = $catalogFile ?: $this->rootDir . '/config/publishers/academic-publishers.json';
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        if (!is_file($this->catalogFile)) {
            throw new \RuntimeException('Publisher catalog not found: ' . $this->catalogFile);
        }
        $data = json_decode((string) file_get_contents($this->catalogFile), true);
        if (!is_array($data) || !is_array($data['publishers'] ?? null)) {
            throw new \RuntimeException('Invalid publisher catalog JSON: ' . $this->catalogFile);
        }
        return array_values(array_filter($data['publishers'], 'is_array'));
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        $data = json_decode((string) file_get_contents($this->catalogFile), true);
        return is_array($data) ? array_diff_key($data, ['publishers' => true]) : [];
    }

    /** @return array<string,mixed> */
    public function find(string $idOrName): array
    {
        $needle = $this->slug($idOrName);
        foreach ($this->all() as $publisher) {
            $keys = [(string) ($publisher['id'] ?? ''), (string) ($publisher['publisher'] ?? ''), (string) ($publisher['website'] ?? '')];
            foreach ($keys as $key) {
                if ($this->slug($key) === $needle) {
                    return $publisher;
                }
            }
        }
        throw new \InvalidArgumentException('Publisher not found in catalog: ' . $idOrName);
    }

    /** @return list<array<string,mixed>> */
    public function filter(?string $risk = null, ?string $mode = null): array
    {
        $rows = $this->all();
        if ($risk !== null && $risk !== '') {
            $want = strtolower($risk);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => strtolower((string) ($row['risk'] ?? '')) === $want));
        }
        if ($mode !== null && $mode !== '') {
            $want = strtolower($mode);
            $rows = array_values(array_filter($rows, static function (array $row) use ($want): bool {
                $modes = array_map('strtolower', array_map('strval', (array) ($row['recommended_modes'] ?? [])));
                return in_array($want, $modes, true);
            }));
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function seedRows(?string $risk = null, ?string $mode = null): array
    {
        $rows = [];
        foreach ($this->filter($risk, $mode) as $publisher) {
            $id = (string) ($publisher['id'] ?? '');
            foreach ((array) ($publisher['seed_urls'] ?? []) as $seed) {
                if (!is_string($seed) || trim($seed) === '') {
                    continue;
                }
                $rows[] = [
                    'publisher_id' => $id,
                    'publisher' => (string) ($publisher['publisher'] ?? $id),
                    'url' => $seed,
                    'profile' => (string) ($publisher['profile'] ?? 'academic'),
                    'recommended_modes' => implode('|', array_map('strval', (array) ($publisher['recommended_modes'] ?? []))),
                    'delay_ms' => (int) ($publisher['default_delay_ms'] ?? 2500),
                    'max_pages' => (int) ($publisher['default_max_pages'] ?? 25),
                    'risk' => (string) ($publisher['risk'] ?? 'medium'),
                    'notes' => (string) ($publisher['notes'] ?? ''),
                ];
            }
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function planJobs(int $maxPages = 25, int $delayMs = 2500, ?string $risk = null, ?string $mode = null): array
    {
        $jobs = [];
        foreach ($this->filter($risk, $mode) as $publisher) {
            $id = (string) ($publisher['id'] ?? 'publisher');
            $seeds = array_values(array_filter((array) ($publisher['seed_urls'] ?? []), 'is_string'));
            if ($seeds === []) {
                continue;
            }
            $jobs[] = [
                'job_version' => '1.0.3',
                'name' => 'publisher-' . $id . '-metadata',
                'publisher_id' => $id,
                'publisher' => (string) ($publisher['publisher'] ?? $id),
                'command' => 'bulk:crawl',
                'args' => ['publisher-seeds/' . $id . '.txt'],
                'options' => [
                    'profile' => (string) ($publisher['profile'] ?? 'academic'),
                    'pipeline' => true,
                    'gap-ms' => max($delayMs, (int) ($publisher['default_delay_ms'] ?? 0)),
                    'max-pages' => min(max(1, $maxPages), (int) ($publisher['default_max_pages'] ?? $maxPages)),
                    'same-domain' => true,
                    'respect-robots' => true,
                    'job-dir' => 'storage/publishers/' . $id,
                ],
                'safe_crawl_policy' => [
                    'metadata_only' => true,
                    'no_paywall_bypass' => true,
                    'no_captcha_bypass' => true,
                    'robots_respected_by_default' => true,
                    'prefer_api_feeds_sitemaps' => true,
                ],
                'seed_urls' => $seeds,
                'notes' => (string) ($publisher['notes'] ?? ''),
            ];
        }
        return $jobs;
    }

    /** @return array<string,mixed> */
    public function validate(): array
    {
        $issues = [];
        $ids = [];
        foreach ($this->all() as $idx => $publisher) {
            $id = (string) ($publisher['id'] ?? '');
            if ($id === '') {
                $issues[] = ['row' => $idx, 'field' => 'id', 'message' => 'Publisher id is required.'];
            } elseif (isset($ids[$id])) {
                $issues[] = ['row' => $idx, 'field' => 'id', 'message' => 'Duplicate publisher id: ' . $id];
            }
            $ids[$id] = true;
            if (empty($publisher['publisher'])) {
                $issues[] = ['row' => $idx, 'field' => 'publisher', 'message' => 'Publisher name is required.'];
            }
            if (empty($publisher['website']) || filter_var((string) $publisher['website'], FILTER_VALIDATE_URL) === false) {
                $issues[] = ['row' => $idx, 'field' => 'website', 'message' => 'Valid website URL is required.'];
            }
            foreach ((array) ($publisher['seed_urls'] ?? []) as $seed) {
                if (!is_string($seed) || filter_var($seed, FILTER_VALIDATE_URL) === false) {
                    $issues[] = ['row' => $idx, 'field' => 'seed_urls', 'message' => 'Invalid seed URL.'];
                }
            }
        }
        return ['ok' => $issues === [], 'publishers_total' => count($ids), 'issues' => $issues];
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('~^https?://(www\.)?~', '', $value) ?? $value;
        $value = preg_replace('~[^a-z0-9]+~', '-', $value) ?? $value;
        return trim($value, '-');
    }
}
