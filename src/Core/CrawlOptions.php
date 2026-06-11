<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class CrawlOptions
{
    /**
     * @param array<int,string> $skipUrlPatterns Case-insensitive regex/text patterns for URLs that should not be enqueued/fetched.
     * @param array<int,string> $allowPathPatterns URL path patterns that are allowed to be fetched, for example /journals/*.
     * @param array<int,string> $finalUrlStripParams Query parameter names to remove from displayed/final URLs.
     * @param array<int,string> $identityProviderHostPatterns Host text/regex patterns treated as auth/cookie redirect endpoints.
     * @param array<int,string> $skipFinalHostPatterns Final destination host patterns that should be skipped/reported.
     * @param array<int,string> $commonDataTypes Common structured data extractors to run when commonData is enabled.
     * @param ?string $commonDataProfile Optional common data profile, for example academic, journal, conference, education, ecommerce, government, tender, jobs, seo, contact_directory or all.
     */
    public function __construct(
        public int $maxPages = 100,
        public int $maxDepth = 3,
        public int $delayMs = 500,
        public int $delayJitterMs = 0,
        public int $pauseAfterUrls = 0,
        public int $pauseSeconds = 0,
        public int $cooldownAfterFailures = 0,
        public int $cooldownSeconds = 0,
        public int $timeoutSeconds = 30,
        public string $httpEngine = 'auto',
        /** @var array<string,string> */
        public array $requestHeaders = [],
        public bool $verifySsl = true,
        public bool $sameDomain = true,
        public bool $respectRobots = true,
        public string $userAgent = 'MNB-ScraperKit/3.8.0',
        public int $maxResponseBytes = 5242880,
        public ?string $networkProfile = null,
        public ?string $browserProfile = null,
        public array $skipUrlPatterns = [],
        public bool $skipAuthLinks = true,
        public bool $avoidDuplicateFinalUrls = true,
        public bool $stayUnderStartPath = false,
        public ?string $extractPreset = null,
        public array $allowPathPatterns = [],
        public bool $useCookieJar = true,
        public ?string $cookieJarPath = null,
        public int $maxRedirects = 5,
        public bool $stripFinalUrlQueryParams = true,
        public array $finalUrlStripParams = ['error', 'code', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'],
        public bool $skipIdentityProviderFinalUrls = true,
        public array $identityProviderHostPatterns = ['idp.*', '*.idp.*', 'login.*', 'auth.*', 'sso.*'],
        public bool $sameFinalHost = false,
        public array $skipFinalHostPatterns = ['idp.springer.com'],
        public bool $commonData = false,
        public array $commonDataTypes = ['all'],
        public ?string $commonDataProfile = null,
        public bool $skipChallengePages = true,
        public bool $failOnChallengePages = false,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            maxPages: (int) ($data['max_pages'] ?? 100),
            maxDepth: (int) ($data['max_depth'] ?? 3),
            delayMs: (int) ($data['delay_ms'] ?? 500),
            timeoutSeconds: (int) ($data['timeout_seconds'] ?? 30),
            httpEngine: self::httpEngine((string) ($data['http_engine'] ?? 'auto')),
            requestHeaders: self::stringMap($data['request_headers'] ?? []),
            verifySsl: (bool) ($data['verify_ssl'] ?? true),
            sameDomain: (bool) ($data['same_domain'] ?? true),
            respectRobots: (bool) ($data['respect_robots'] ?? true),
            userAgent: (string) ($data['user_agent'] ?? 'MNB-ScraperKit/3.8.0'),
            delayJitterMs: max(0, (int) ($data['delay_jitter_ms'] ?? $data['jitter_ms'] ?? 0)),
            pauseAfterUrls: max(0, (int) ($data['pause_after_urls'] ?? 0)),
            pauseSeconds: max(0, (int) ($data['pause_seconds'] ?? 0)),
            cooldownAfterFailures: max(0, (int) ($data['cooldown_after_failures'] ?? 0)),
            cooldownSeconds: max(0, (int) ($data['cooldown_seconds'] ?? 0)),
            maxResponseBytes: (int) ($data['max_response_bytes'] ?? 5242880),
            networkProfile: isset($data['network_profile']) ? (string) $data['network_profile'] : null,
            browserProfile: isset($data['browser_profile']) ? (string) $data['browser_profile'] : null,
            skipUrlPatterns: self::stringList($data['skip_url_patterns'] ?? []),
            skipAuthLinks: (bool) ($data['skip_auth_links'] ?? true),
            avoidDuplicateFinalUrls: (bool) ($data['avoid_duplicate_final_urls'] ?? true),
            stayUnderStartPath: (bool) ($data['stay_under_start_path'] ?? false),
            extractPreset: isset($data['extract_preset']) && $data['extract_preset'] !== '' ? (string) $data['extract_preset'] : null,
            allowPathPatterns: self::stringList($data['allow_path_patterns'] ?? []),
            useCookieJar: (bool) ($data['use_cookie_jar'] ?? true),
            cookieJarPath: isset($data['cookie_jar_path']) && $data['cookie_jar_path'] !== '' ? (string) $data['cookie_jar_path'] : null,
            maxRedirects: max(0, (int) ($data['max_redirects'] ?? 5)),
            stripFinalUrlQueryParams: (bool) ($data['strip_final_url_query_params'] ?? true),
            finalUrlStripParams: self::stringList($data['final_url_strip_params'] ?? ['error', 'code', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid']),
            skipIdentityProviderFinalUrls: (bool) ($data['skip_identity_provider_final_urls'] ?? true),
            identityProviderHostPatterns: self::stringList($data['identity_provider_host_patterns'] ?? ['idp.*', '*.idp.*', 'login.*', 'auth.*', 'sso.*']),
            sameFinalHost: (bool) ($data['same_final_host'] ?? false),
            skipFinalHostPatterns: self::stringList($data['skip_final_host_patterns'] ?? ['idp.springer.com']),
            commonData: (bool) ($data['common_data'] ?? false),
            commonDataTypes: self::stringList($data['common_data_types'] ?? ['all']),
            commonDataProfile: isset($data['common_data_profile']) && trim((string) $data['common_data_profile']) !== '' ? trim((string) $data['common_data_profile']) : null,
            skipChallengePages: (bool) ($data['skip_challenge_pages'] ?? true),
            failOnChallengePages: (bool) ($data['fail_on_challenge_pages'] ?? false),
        );
    }


    private static function httpEngine(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['auto', 'curl', 'stream'], true) ? $value : 'auto';
    }

    /** @return array<string,string> */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $name = trim((string) $k);
            $headerValue = trim((string) $v);
            if ($name !== '' && $headerValue !== '') {
                $out[$name] = $headerValue;
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)));
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values($out);
    }
}
