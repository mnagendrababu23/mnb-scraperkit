<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Api;

final class ApiResponse
{
    /** @param array<string,mixed> $body @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = ['Content-Type' => 'application/json; charset=utf-8']
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
        ];
    }
}
