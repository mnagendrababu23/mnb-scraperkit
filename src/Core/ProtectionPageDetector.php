<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

/**
 * Detects browser/cookie/challenge/protection pages without trying to bypass them.
 * The goal is to classify them clearly, skip extraction, and recommend safer fallbacks.
 */
final class ProtectionPageDetector
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function detect(
        string $url,
        ?string $finalUrl,
        int $statusCode,
        array $headers,
        string $html,
        ?string $title,
        ?string $text,
        array $links,
        array $meta = [],
        ?string $clientError = null,
    ): array {
        $lowerHtml = strtolower(substr($html, 0, 200000));
        $lowerText = strtolower(substr((string) $text, 0, 50000));
        $lowerTitle = strtolower(trim((string) $title));
        $robotsMeta = strtolower((string) ($meta['robots'] ?? ''));
        $server = strtolower((string) ($headers['server'] ?? ''));
        $cfRay = (string) ($headers['cf-ray'] ?? '');

        $signals = [];

        if (in_array($statusCode, [401, 403, 429], true)) {
            $signals[] = 'http_status_' . $statusCode;
        }

        $titlePatterns = [
            'just a moment',
            'access denied',
            'forbidden',
            'attention required',
            'checking your browser',
            'checking if the site connection is secure',
            'please wait',
            'security check',
            'bot verification',
        ];
        foreach ($titlePatterns as $pattern) {
            if ($lowerTitle !== '' && str_contains($lowerTitle, $pattern)) {
                $signals[] = 'challenge_title:' . $pattern;
                break;
            }
        }

        $bodyPatterns = [
            'cf-browser-verification',
            'cf-chl-',
            'cf-ray',
            'cloudflare',
            'checking if the site connection is secure',
            'checking your browser before accessing',
            'enable javascript and cookies to continue',
            'please enable cookies',
            'bot verification',
            'captcha',
            'access denied',
            'request blocked',
            'blocked by security',
        ];
        foreach ($bodyPatterns as $pattern) {
            if (str_contains($lowerHtml, $pattern) || str_contains($lowerText, $pattern)) {
                $signals[] = 'challenge_body:' . $pattern;
            }
        }

        if (str_contains($robotsMeta, 'noindex') && str_contains($robotsMeta, 'nofollow')) {
            $signals[] = 'robots_meta_noindex_nofollow';
        }

        if ($this->textLength((string) $text) <= 120 && count($links) === 0 && $statusCode >= 400) {
            $signals[] = 'tiny_text_zero_links_error_status';
        }

        if ($server && str_contains($server, 'cloudflare')) {
            $signals[] = 'server_cloudflare';
        }
        if ($cfRay !== '') {
            $signals[] = 'cf_ray_header';
        }

        if ($clientError && stripos($clientError, 'maximum') !== false && stripos($clientError, 'redirect') !== false) {
            $signals[] = 'redirect_limit_possible_auth_or_cookie_flow';
        }

        $isChallenge = false;
        if ($signals !== []) {
            $strong = array_filter($signals, static fn (string $s): bool =>
                str_starts_with($s, 'challenge_title:')
                || str_starts_with($s, 'challenge_body:')
                || $s === 'tiny_text_zero_links_error_status'
                || $s === 'cf_ray_header'
                || $s === 'redirect_limit_possible_auth_or_cookie_flow'
            );
            $isChallenge = count($strong) > 0 && ($statusCode >= 400 || str_contains($robotsMeta, 'noindex'));
        }

        $failureType = null;
        if ($isChallenge) {
            $failureType = match ($statusCode) {
                401 => 'http_401_auth_or_protection',
                403 => 'http_403_challenge_or_protection',
                429 => 'http_429_rate_limited_or_challenge',
                default => 'challenge_or_protection_page',
            };
        }

        return [
            'is_challenge' => $isChallenge,
            'failure_type' => $failureType,
            'signals' => array_values(array_unique($signals)),
            'recommendation' => $isChallenge
                ? 'Static PHP HTTP crawling received a protection/challenge page. Do not run normal extraction. Try an official API, sitemap/RSS/source discovery, authorized browser mode, or a manual/bulk URL list.'
                : null,
            'browser_required' => $isChallenge,
            'source_discovery_command' => $isChallenge ? 'php bin/mnb-scraper source:discover "' . $url . '" --json' : null,
        ];
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
