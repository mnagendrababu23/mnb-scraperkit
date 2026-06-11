<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Api;

final class ApiToken
{
    public static function generate(string $prefix = 'mnb_sk'): string
    {
        $prefix = preg_replace('/[^a-z0-9_\-]+/i', '', $prefix) ?: 'mnb_sk';
        return $prefix . '_' . bin2hex(random_bytes(24));
    }

    public static function verify(?string $provided, ?string $expected): bool
    {
        if ($expected === null || trim($expected) === '') {
            return true;
        }
        if ($provided === null || trim($provided) === '') {
            return false;
        }
        return hash_equals(trim($expected), trim($provided));
    }

    /** @param array<string,string> $headers */
    public static function bearerFromHeaders(array $headers): ?string
    {
        $authorization = $headers['authorization'] ?? $headers['Authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
