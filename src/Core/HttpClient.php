<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

use Mnb\ScraperKit\Network\NetworkProfile;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;

final class HttpClient
{
    public function __construct(
        private ?NetworkProfile $networkProfile = null,
        private ?UrlSafetyGuard $safetyGuard = null,
    ) {
        $this->safetyGuard ??= new UrlSafetyGuard();
    }

    /** @param array<string,string> $headers */
    public function get(string $url, CrawlOptions $options, array $headers = []): HttpResponse
    {
        $this->assertSafeUrl($url);

        $engine = strtolower(trim($options->httpEngine));
        if (!in_array($engine, ['auto', 'curl', 'stream'], true)) {
            $engine = 'auto';
        }

        if ($engine === 'auto') {
            $engine = function_exists('curl_init') ? 'curl' : 'stream';
        }

        if ($engine === 'curl') {
            if (!function_exists('curl_init')) {
                if (strtolower($options->httpEngine) === 'auto') {
                    return $this->getWithStream($url, $options, $headers);
                }
                return new HttpResponse($url, null, 0, [], '', 0, 'PHP cURL extension is not available.', 0, 0, 'curl');
            }
            return $this->getWithCurl($url, $options, $headers);
        }

        return $this->getWithStream($url, $options, $headers);
    }

    /** @param array<string,string> $headers */
    private function getWithCurl(string $url, CrawlOptions $options, array $headers = []): HttpResponse
    {
        $started = microtime(true);
        $currentUrl = $url;
        $responseHeaders = [];
        $redirectHistory = [];
        $statusCode = 0;
        $body = '';
        $error = null;
        $errno = 0;
        $redirectCount = 0;

        for ($attempt = 0; $attempt <= $options->maxRedirects; $attempt++) {
            $this->assertSafeUrl($currentUrl);
            $responseHeaders = [];

            $ch = curl_init($currentUrl);
            if (!$ch) {
                $error = 'Unable to initialize cURL.';
                break;
            }

            $defaultHeaders = $this->defaultHeaders($options);
            $mergedHeaders = array_merge($defaultHeaders, $options->requestHeaders, $headers);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_AUTOREFERER => true,
                CURLOPT_CONNECTTIMEOUT => min(10, $options->timeoutSeconds),
                CURLOPT_TIMEOUT => $options->timeoutSeconds,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => function ($curl, string $headerLine) use (&$responseHeaders): int {
                    $length = strlen($headerLine);
                    $line = trim($headerLine);
                    if ($line === '') {
                        return $length;
                    }
                    if (preg_match('~^HTTP/\S+\s+(\d{3})(?:\s+(.*))?$~i', $line, $m) === 1) {
                        $responseHeaders = ['_status_code' => (int) $m[1], '_status_line' => $line];
                        return $length;
                    }
                    if (!str_contains($line, ':')) {
                        return $length;
                    }
                    [$name, $value] = explode(':', $line, 2);
                    $key = strtolower(trim($name));
                    $value = trim($value);
                    if (isset($responseHeaders[$key])) {
                        if (!is_array($responseHeaders[$key])) {
                            $responseHeaders[$key] = [$responseHeaders[$key]];
                        }
                        $responseHeaders[$key][] = $value;
                    } else {
                        $responseHeaders[$key] = $value;
                    }
                    return $length;
                },
                CURLOPT_HTTPHEADER => $this->formatHeaders($mergedHeaders),
            ]);

            if ($options->verifySsl === false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }

            if ($options->useCookieJar) {
                $cookieJar = $this->cookieJarPath($options);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            }

            if ($this->networkProfile && $this->networkProfile->isProxy()) {
                curl_setopt($ch, CURLOPT_PROXY, $this->networkProfile->proxyAddress());
                if ($this->networkProfile->type === 'socks5') {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                }
                if ($this->networkProfile->username && $this->networkProfile->password) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->networkProfile->username . ':' . $this->networkProfile->password);
                }
            }

            $bodyResult = curl_exec($ch);
            $error = curl_error($ch) ?: null;
            $errno = curl_errno($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $body = is_string($bodyResult) ? $bodyResult : '';

            $location = $this->headerValue($responseHeaders, 'location');
            if ($location !== null && $statusCode >= 300 && $statusCode < 400) {
                if ($redirectCount >= $options->maxRedirects) {
                    $error = 'Maximum (' . $options->maxRedirects . ') redirects followed';
                    break;
                }
                $nextUrl = $this->resolveUrl($location, $currentUrl);
                $this->assertSafeUrl($nextUrl);
                $redirectHistory[] = [
                    'from' => $currentUrl,
                    'to' => $nextUrl,
                    'status_code' => $statusCode,
                    'location' => $location,
                ];
                $currentUrl = $nextUrl;
                $redirectCount++;
                continue;
            }

            break;
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        [$body, $error] = $this->applyResponseLimit($body, $options, $error);

        return new HttpResponse($url, $currentUrl, $statusCode, $responseHeaders, $body, $elapsedMs, $error, $redirectCount, $errno, 'curl', $redirectHistory);
    }

    /** @param array<string,string> $headers */
    private function getWithStream(string $url, CrawlOptions $options, array $headers = []): HttpResponse
    {
        $started = microtime(true);

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return new HttpResponse($url, null, 0, [], '', 0, 'PHP allow_url_fopen is disabled; stream/file_get_contents HTTP engine cannot run.', 0, 0, 'stream');
        }

        $currentUrl = $url;
        $responseHeaders = [];
        $redirectHistory = [];
        $statusCode = 0;
        $body = '';
        $error = null;
        $redirectCount = 0;
        $cookies = $options->useCookieJar ? $this->readStreamCookies($options) : [];

        for ($attempt = 0; $attempt <= $options->maxRedirects; $attempt++) {
            $requestHeaders = array_merge($this->defaultHeaders($options), $options->requestHeaders, $headers);
            if ($cookies !== []) {
                $requestHeaders['Cookie'] = $this->cookieHeader($cookies);
            }

            $contextOptions = [
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $this->formatHeaders($requestHeaders)),
                    'timeout' => $options->timeoutSeconds,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
                'ssl' => [
                    'verify_peer' => $options->verifySsl,
                    'verify_peer_name' => $options->verifySsl,
                ],
            ];

            if ($this->networkProfile && in_array($this->networkProfile->type, ['http_proxy', 'https_proxy'], true)) {
                $proxy = 'tcp://' . $this->networkProfile->proxyAddress();
                $contextOptions['http']['proxy'] = $proxy;
                $contextOptions['http']['request_fulluri'] = true;
                if ($this->networkProfile->username && $this->networkProfile->password) {
                    $requestHeaders['Proxy-Authorization'] = 'Basic ' . base64_encode($this->networkProfile->username . ':' . $this->networkProfile->password);
                    $contextOptions['http']['header'] = implode("\r\n", $this->formatHeaders($requestHeaders));
                }
            }

            $context = stream_context_create($contextOptions);
            $lastError = null;
            set_error_handler(static function (int $severity, string $message) use (&$lastError): bool {
                $lastError = $message;
                return true;
            });
            unset($http_response_header);
            $bodyResult = file_get_contents($currentUrl, false, $context);
            restore_error_handler();

            /** @var array<int,string> $rawHeaders */
            $rawHeaders = $http_response_header ?? [];
            [$statusCode, $responseHeaders] = $this->parseRawHeaders($rawHeaders);
            $this->collectCookies($responseHeaders, $cookies);

            if ($bodyResult === false) {
                $body = '';
                $error = $lastError ?: 'file_get_contents failed.';
            } else {
                $body = $bodyResult;
                $error = null;
            }

            $location = $this->headerValue($responseHeaders, 'location');
            if ($location !== null && $statusCode >= 300 && $statusCode < 400) {
                if ($redirectCount >= $options->maxRedirects) {
                    $error = 'Maximum (' . $options->maxRedirects . ') redirects followed';
                    break;
                }
                $nextUrl = $this->resolveUrl($location, $currentUrl);
                $this->assertSafeUrl($nextUrl);
                $redirectHistory[] = [
                    'from' => $currentUrl,
                    'to' => $nextUrl,
                    'status_code' => $statusCode,
                    'location' => $location,
                ];
                $currentUrl = $nextUrl;
                $redirectCount++;
                continue;
            }
            break;
        }

        if ($options->useCookieJar) {
            $this->writeStreamCookies($options, $cookies);
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        [$body, $error] = $this->applyResponseLimit($body, $options, $error);

        return new HttpResponse($url, $currentUrl, $statusCode, $responseHeaders, $body, $elapsedMs, $error, $redirectCount, 0, 'stream', $redirectHistory);
    }

    private function assertSafeUrl(string $url): void
    {
        if ($this->safetyGuard) {
            $this->safetyGuard->assertAllowed($url);
        }
    }

    /** @return array<string,string> */
    private function defaultHeaders(CrawlOptions $options): array
    {
        return [
            'User-Agent' => $options->userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ];
    }

    /** @param array<string,string> $headers @return array<int,string> */
    private function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $out[] = $name . ': ' . trim((string) $value);
        }
        return $out;
    }

    /** @param array<int,string> $rawHeaders @return array{0:int,1:array<string,mixed>} */
    private function parseRawHeaders(array $rawHeaders): array
    {
        $statusCode = 0;
        $headers = [];
        foreach ($rawHeaders as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('~^HTTP/\S+\s+(\d{3})(?:\s+(.*))?$~i', $line, $m) === 1) {
                $statusCode = (int) $m[1];
                $headers = ['_status_code' => $statusCode, '_status_line' => $line];
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            $value = trim($value);
            if (isset($headers[$key])) {
                if (!is_array($headers[$key])) {
                    $headers[$key] = [$headers[$key]];
                }
                $headers[$key][] = $value;
            } else {
                $headers[$key] = $value;
            }
        }
        return [$statusCode, $headers];
    }

    /** @param array<string,mixed> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        $key = strtolower($name);
        $value = $headers[$key] ?? null;
        if (is_array($value)) {
            $value = end($value);
        }
        return $value === null ? null : (string) $value;
    }

    private function resolveUrl(string $location, string $baseUrl): string
    {
        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!$base || empty($base['scheme']) || empty($base['host'])) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }
        $root = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($location, '/')) {
            return $root . $location;
        }
        $path = $base['path'] ?? '/';
        $dir = preg_replace('~/[^/]*$~', '/', $path) ?: '/';
        return $root . $dir . $location;
    }

    /** @return array<string,string> */
    private function readStreamCookies(CrawlOptions $options): array
    {
        $path = $this->streamCookiePath($options);
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        $cookies = [];
        foreach ($data as $name => $value) {
            if (is_scalar($value)) {
                $cookies[(string) $name] = (string) $value;
            }
        }
        return $cookies;
    }

    /** @param array<string,string> $cookies */
    private function writeStreamCookies(CrawlOptions $options, array $cookies): void
    {
        $path = $this->streamCookiePath($options);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($cookies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $headers @param array<string,string> $cookies */
    private function collectCookies(array $headers, array &$cookies): void
    {
        $setCookie = $headers['set-cookie'] ?? [];
        if (is_string($setCookie)) {
            $setCookie = [$setCookie];
        }
        if (!is_array($setCookie)) {
            return;
        }
        foreach ($setCookie as $line) {
            $pair = trim(explode(';', (string) $line, 2)[0]);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);
            if ($name !== '') {
                $cookies[$name] = $value;
            }
        }
    }

    /** @param array<string,string> $cookies */
    private function cookieHeader(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('; ', $parts);
    }

    /** @return array{0:string,1:?string} */
    private function applyResponseLimit(string $body, CrawlOptions $options, ?string $error): array
    {
        if (strlen($body) > $options->maxResponseBytes) {
            $body = substr($body, 0, $options->maxResponseBytes);
            $error = trim(($error ? $error . ' ' : '') . 'Response exceeded max_response_bytes and was truncated.');
        }
        return [$body, $error];
    }

    private function cookieJarPath(CrawlOptions $options): string
    {
        $path = $options->cookieJarPath ?: getcwd() . '/storage/sessions/default-cookiejar.txt';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!is_file($path)) {
            @touch($path);
        }
        return $path;
    }

    private function streamCookiePath(CrawlOptions $options): string
    {
        $path = $options->cookieJarPath ?: getcwd() . '/storage/sessions/default-stream-cookies.json';
        if (str_ends_with($path, '.txt')) {
            $path .= '.stream.json';
        }
        return $path;
    }
}
