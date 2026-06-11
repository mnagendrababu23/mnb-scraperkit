<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Support;

final class UrlNormalizer
{
    public function normalize(string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if ($baseUrl && str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            $url = $scheme . ':' . $url;
        } elseif ($baseUrl && str_starts_with($url, '/')) {
            $parts = parse_url($baseUrl);
            if (!isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            $url = $parts['scheme'] . '://' . $parts['host'] . $url;
        } elseif ($baseUrl && !preg_match('~^https?://~i', $url)) {
            $url = rtrim($this->directoryUrl($baseUrl), '/') . '/' . ltrim($url, '/');
        }

        if (!preg_match('~^https?://~i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $path = preg_replace('~/+~', '/', $path) ?: '/';

        $query = isset($parts['query']) ? $this->cleanQuery($parts['query']) : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port . $path . ($query ? '?' . $query : '');
    }

    public function sameHost(string $a, string $b): bool
    {
        return strtolower((string) parse_url($a, PHP_URL_HOST)) === strtolower((string) parse_url($b, PHP_URL_HOST));
    }

    private function directoryUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        $dir = str_ends_with($path, '/') ? $path : dirname($path) . '/';
        return $parts['scheme'] . '://' . $parts['host'] . $dir;
    }

    private function cleanQuery(string $query): string
    {
        parse_str($query, $params);
        foreach (array_keys($params) as $key) {
            if (preg_match('/^(utm_|fbclid|gclid|mc_cid|mc_eid)/i', (string) $key)) {
                unset($params[$key]);
            }
        }
        return http_build_query($params);
    }
}
