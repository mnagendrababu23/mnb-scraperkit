<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class FailureClassifier
{
    public static function fromSafetyMessage(string $message): string
    {
        $m = strtolower($message);
        return match (true) {
            str_contains($m, 'only http and https'), str_contains($m, 'unsupported scheme') => 'unsupported_scheme',
            str_contains($m, 'localhost') => 'localhost_blocked',
            str_contains($m, 'private'), str_contains($m, 'reserved'), str_contains($m, 'metadata') => 'private_ip_blocked',
            str_contains($m, 'userinfo') => 'userinfo_blocked',
            str_contains($m, 'blocked by safety policy') => 'host_policy_blocked',
            str_contains($m, 'too long') => 'url_too_long',
            default => 'url_safety_blocked',
        };
    }

    public static function fromHttp(?string $error, int $statusCode = 0, int $curlErrno = 0): ?string
    {
        if ($error !== null && trim($error) !== '') {
            $m = strtolower($error);
            if (str_contains($m, 'maximum') && str_contains($m, 'redirect')) {
                return 'redirect_loop';
            }
            if (str_contains($m, 'response exceeded') || str_contains($m, 'max_response_bytes') || str_contains($m, 'body too large')) {
                return 'body_too_large';
            }
            if ($curlErrno === 28 || str_contains($m, 'timed out') || str_contains($m, 'timeout')) {
                return 'timeout';
            }
            if ($curlErrno === 6 || str_contains($m, 'could not resolve') || str_contains($m, 'name or service not known') || str_contains($m, 'dns')) {
                return 'dns_error';
            }
            if ($curlErrno === 35 || $curlErrno === 51 || $curlErrno === 60 || str_contains($m, 'ssl') || str_contains($m, 'tls') || str_contains($m, 'certificate')) {
                return 'ssl_error';
            }
            if (str_contains($m, 'only http and https') || str_contains($m, 'unsupported protocol') || str_contains($m, 'unsupported scheme')) {
                return 'unsupported_scheme';
            }
            if (str_contains($m, 'private') || str_contains($m, 'localhost') || str_contains($m, 'safety check failed')) {
                return self::fromSafetyMessage($error);
            }
            if ($statusCode === 0) {
                return 'network_error';
            }
        }

        return self::fromStatus($statusCode);
    }

    public static function fromStatus(int $statusCode): ?string
    {
        return match (true) {
            $statusCode === 0 => 'http_no_response',
            $statusCode === 401 || $statusCode === 403 => 'http_4xx',
            $statusCode === 404 => 'http_4xx',
            $statusCode === 408 => 'timeout',
            $statusCode === 429 => 'http_429_rate_limited',
            $statusCode >= 500 => 'http_5xx',
            $statusCode >= 400 => 'http_4xx',
            default => null,
        };
    }

    public static function isRetryable(?string $failureType): bool
    {
        return in_array($failureType, [
            'timeout',
            'dns_error',
            'network_error',
            'http_no_response',
            'http_429_rate_limited',
            'http_5xx',
        ], true);
    }
}
