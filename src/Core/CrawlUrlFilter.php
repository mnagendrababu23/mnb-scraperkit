<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

use Mnb\ScraperKit\Support\UrlNormalizer;

final class CrawlUrlFilter
{
    /** @var array<int,string> */
    private array $authPatterns = [
        '~/(login|log-in|signin|sign-in|signup|sign-up|register|registration|account|my-account|auth|authenticate|logout)([/?#]|$)~i',
        '~[?&](login|signin|signup|register|auth|logout)=~i',
        '~/(cart|checkout|basket)([/?#]|$)~i',
    ];

    public function __construct(private UrlNormalizer $normalizer)
    {
    }

    public function skipReason(string $url, string $startUrl, CrawlOptions $options): ?string
    {
        if ($options->skipAuthLinks) {
            foreach ($this->authPatterns as $pattern) {
                if (preg_match($pattern, $url) === 1) {
                    return 'Skipped account/auth/cart-style URL by safe default filter.';
                }
            }
        }

        foreach ($options->skipUrlPatterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $regex = $this->patternToRegex($pattern);
            if (@preg_match($regex, $url) === 1) {
                return 'Skipped by custom URL pattern: ' . $pattern;
            }
        }

        if ($options->allowPathPatterns !== [] && !$this->matchesAnyPath($url, $options->allowPathPatterns)) {
            return 'Skipped because URL path does not match allow-path filter.';
        }

        if ($options->stayUnderStartPath && !$this->isUnderStartPath($startUrl, $url)) {
            return 'Skipped because URL is outside the start path scope.';
        }

        return null;
    }

    public function finalUrlSkipReason(string $requestedUrl, ?string $finalUrl, string $startUrl, CrawlOptions $options): ?string
    {
        if (!$finalUrl) {
            return null;
        }

        $finalHost = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));
        $startHost = strtolower((string) parse_url($startUrl, PHP_URL_HOST));

        if ($finalHost === '') {
            return null;
        }

        if ($options->skipIdentityProviderFinalUrls && $this->hostMatchesAny($finalHost, $options->identityProviderHostPatterns)) {
            return 'Skipped because final URL landed on identity-provider/auth host: ' . $finalHost;
        }

        if ($options->sameFinalHost && $startHost !== '' && $finalHost !== $startHost) {
            return 'Skipped because final host differs from start host: ' . $finalHost;
        }

        if ($this->hostMatchesAny($finalHost, $options->skipFinalHostPatterns)) {
            return 'Skipped because final host matches skip-final-host filter: ' . $finalHost;
        }

        if ($options->allowPathPatterns !== [] && $finalHost === $startHost && !$this->matchesAnyPath($finalUrl, $options->allowPathPatterns)) {
            return 'Skipped because final URL path does not match allow-path filter.';
        }

        return null;
    }

    public function classifyFailure(?string $finalUrl, ?string $error, CrawlOptions $options, int $statusCode = 0, int $curlErrno = 0): ?string
    {
        if ($finalUrl) {
            $finalHost = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));
            if ($finalHost !== '' && $this->hostMatchesAny($finalHost, $options->identityProviderHostPatterns)) {
                return 'auth_or_cookie_redirect';
            }
            if ($finalHost !== '' && $this->hostMatchesAny($finalHost, $options->skipFinalHostPatterns)) {
                return 'final_domain_guard';
            }
        }

        return FailureClassifier::fromHttp($error, $statusCode, $curlErrno);
    }

    private function matchesAnyPath(string $url, array $patterns): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            $regex = $this->pathPatternToRegex($pattern);
            if (@preg_match($regex, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    private function hostMatchesAny(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }

            if ($pattern[0] === '~') {
                if (@preg_match($pattern, $host) === 1) {
                    return true;
                }
                continue;
            }

            $regex = '~^' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . '$~i';
            if (preg_match($regex, $host) === 1) {
                return true;
            }
        }
        return false;
    }

    private function patternToRegex(string $pattern): string
    {
        if (strlen($pattern) > 2 && $pattern[0] === '~' && str_ends_with($pattern, '~')) {
            return $pattern . 'i';
        }

        // Treat /.../ or /.../i as a real regex. Treat /path/* as a simple glob-like text pattern.
        if (strlen($pattern) > 2 && $pattern[0] === '/' && preg_match('~^/.+/[a-zA-Z]*$~', $pattern) === 1) {
            return $pattern;
        }

        return '~' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . '~i';
    }

    private function pathPatternToRegex(string $pattern): string
    {
        if ($pattern[0] === '~') {
            return $pattern;
        }
        return '~^' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . '$~i';
    }

    private function isUnderStartPath(string $startUrl, string $candidateUrl): bool
    {
        $start = parse_url($startUrl);
        $candidate = parse_url($candidateUrl);
        if (!$start || !$candidate) {
            return false;
        }

        $startHost = strtolower((string) ($start['host'] ?? ''));
        $candidateHost = strtolower((string) ($candidate['host'] ?? ''));
        if ($startHost === '' || $candidateHost === '' || $startHost !== $candidateHost) {
            return false;
        }

        $startPath = (string) ($start['path'] ?? '/');
        $candidatePath = (string) ($candidate['path'] ?? '/');

        $scope = rtrim(dirname($startPath), '/\\');
        if ($scope === '' || $scope === '.') {
            $scope = '/';
        }
        if (!str_ends_with($scope, '/')) {
            $scope .= '/';
        }

        return $scope === '/' || str_starts_with($candidatePath, $scope);
    }
}
