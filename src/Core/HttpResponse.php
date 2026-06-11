<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class HttpResponse
{
    /** @param array<string,mixed> $headers @param array<int,array<string,mixed>> $redirectHistory */
    public function __construct(
        public readonly string $url,
        public readonly ?string $finalUrl,
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $responseTimeMs,
        public readonly ?string $error = null,
        public readonly int $redirectCount = 0,
        public readonly int $curlErrno = 0,
        public readonly string $engine = 'unknown',
        public readonly array $redirectHistory = [],
    ) {
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);
        $value = $this->headers[$key] ?? null;
        if (is_array($value)) {
            $value = end($value);
        }
        if ($value === null) {
            return null;
        }
        return (string) $value;
    }
}
