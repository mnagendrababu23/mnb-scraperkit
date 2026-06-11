<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

/**
 * Safe browser session profile for authorized workflows.
 *
 * The profile stores only workflow metadata and cookie/session file locations.
 * It intentionally does not store passwords or secrets by default.
 */
final class BrowserSessionProfile
{
    /** @param list<string> $allowedDomains @param array<string,mixed> $metadata */
    public function __construct(
        public string $name,
        public array $allowedDomains = [],
        public ?string $loginUrl = null,
        public ?string $cookieFile = null,
        public ?string $waitSelector = null,
        public string $browserMode = 'auto',
        public int $timeoutMs = 30000,
        public bool $headless = true,
        public bool $blockAssets = true,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public array $metadata = [],
    ) {
        $this->name = self::normalizeName($this->name);
        $this->allowedDomains = self::normalizeDomains($this->allowedDomains);
        $this->browserMode = BrowserOptions::normalizeMode($this->browserMode);
        $this->timeoutMs = max(1000, $this->timeoutMs);
        $this->createdAt ??= date(DATE_ATOM);
        $this->updatedAt ??= date(DATE_ATOM);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? $data['session'] ?? 'default'),
            allowedDomains: self::normalizeDomains($data['allowed_domains'] ?? $data['domains'] ?? []),
            loginUrl: self::nullableString($data['login_url'] ?? null),
            cookieFile: self::nullableString($data['cookie_file'] ?? null),
            waitSelector: self::nullableString($data['wait_selector'] ?? null),
            browserMode: (string) ($data['browser_mode'] ?? $data['mode'] ?? 'auto'),
            timeoutMs: (int) ($data['timeout_ms'] ?? 30000),
            headless: self::bool($data['headless'] ?? true),
            blockAssets: self::bool($data['block_assets'] ?? true),
            createdAt: self::nullableString($data['created_at'] ?? null),
            updatedAt: self::nullableString($data['updated_at'] ?? null),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'allowed_domains' => $this->allowedDomains,
            'login_url' => $this->loginUrl,
            'cookie_file' => $this->cookieFile,
            'wait_selector' => $this->waitSelector,
            'browser_mode' => $this->browserMode,
            'timeout_ms' => $this->timeoutMs,
            'headless' => $this->headless,
            'block_assets' => $this->blockAssets,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'metadata' => $this->metadata,
            'security' => [
                'password_storage' => 'disabled_by_default',
                'authorized_workflows_only' => true,
                'domain_allowlist_required' => $this->allowedDomains !== [],
            ],
        ];
    }

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('~[^a-z0-9._-]+~', '-', $name) ?: 'default';
        return trim($name, '-_.') ?: 'default';
    }

    /** @param mixed $value @return list<string> */
    public static function normalizeDomains(mixed $value): array
    {
        if ($value === null || $value === false || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = str_contains($value, ',') ? explode(',', $value) : [$value];
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $out = [];
        foreach ($value as $domain) {
            $domain = strtolower(trim((string) $domain));
            $domain = preg_replace('~^https?://~i', '', $domain) ?? $domain;
            $domain = explode('/', $domain)[0] ?? $domain;
            $domain = preg_replace('~:\d+$~', '', $domain) ?? $domain;
            $domain = ltrim($domain, '.');
            if ($domain !== '') {
                $out[] = $domain;
            }
        }
        return array_values(array_unique($out));
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
