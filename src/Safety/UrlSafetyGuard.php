<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Safety;

final class UrlSafetyGuard
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function assertAllowed(string $url): void
    {
        $maxLength = (int) ($this->config['max_url_length'] ?? 4096);
        if ($maxLength > 0 && strlen($url) > $maxLength) {
            throw new \RuntimeException('URL safety check failed: URL is too long.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('URL safety check failed: invalid URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('URL safety check failed: only http and https URLs are allowed.');
        }

        if (($this->config['block_userinfo'] ?? true) && (isset($parts['user']) || isset($parts['pass']))) {
            throw new \RuntimeException('URL safety check failed: userinfo credentials in URLs are blocked.');
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('URL safety check failed: missing host.');
        }

        $host = trim($host, "[] \t\n\r\0\x0B");
        $hostLower = strtolower($host);

        if (($this->config['block_localhost'] ?? true) && $this->isLocalhostName($hostLower)) {
            throw new \RuntimeException('URL safety check failed: localhost targets are blocked.');
        }

        foreach ($this->stringList($this->config['blocked_host_patterns'] ?? []) as $pattern) {
            if ($this->matchesPattern($hostLower, strtolower($pattern))) {
                throw new \RuntimeException('URL safety check failed: host is blocked by safety policy.');
            }
        }

        if (($this->config['block_private_ips'] ?? true) === false) {
            return;
        }

        foreach ($this->candidateIps($host) as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new \RuntimeException('URL safety check failed: private/reserved IP targets are blocked.');
            }
        }
    }

    private function isLocalhostName(string $host): bool
    {
        return $host === 'localhost'
            || $host === 'localhost.localdomain'
            || str_ends_with($host, '.localhost')
            || $host === '127.0.0.1'
            || str_starts_with($host, '127.')
            || $host === '::1'
            || $host === '0.0.0.0';
    }

    /** @return array<int,string> */
    private function candidateIps(string $host): array
    {
        $ips = [];
        $literal = $this->normalizeIpLiteral($host);
        if ($literal !== null) {
            $ips[] = $literal;
        }

        foreach ($this->resolveHostIps($host) as $ip) {
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }

    private function normalizeIpLiteral(string $host): ?string
    {
        $clean = trim($host, '[]');
        if (filter_var($clean, FILTER_VALIDATE_IP)) {
            return $clean;
        }

        // Decimal dword IPv4 form, for example http://2130706433/ for 127.0.0.1.
        if (preg_match('/^\d+$/', $clean) === 1) {
            $num = (int) $clean;
            if ($num >= 0 && $num <= 0xFFFFFFFF) {
                return long2ip($num) ?: null;
            }
        }

        // Hex dword IPv4 form, for example http://0x7f000001/.
        if (preg_match('/^0x[0-9a-f]+$/i', $clean) === 1) {
            $num = intval($clean, 16);
            if ($num >= 0 && $num <= 0xFFFFFFFF) {
                return long2ip($num) ?: null;
            }
        }

        return null;
    }

    /** @return array<int,string> */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    if (!empty($record[$key]) && filter_var((string) $record[$key], FILTER_VALIDATE_IP)) {
                        $ips[] = (string) $record[$key];
                    }
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbyname($host);
            if (is_string($fallback) && $fallback !== $host && filter_var($fallback, FILTER_VALIDATE_IP)) {
                $ips[] = $fallback;
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @return array<int,string> */
    private function stringList(mixed $value): array
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
        return $out;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }
        if (@preg_match($pattern, '') !== false) {
            return preg_match($pattern, $value) === 1;
        }
        $regex = '~^' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . '$~i';
        return preg_match($regex, $value) === 1;
    }
}
