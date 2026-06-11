<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Processing;

final class UrlProcessOptions
{
    /**
     * @param array<int,string> $methods Fetch methods attempted in order.
     * @param array<int,int> $successStatuses Exact success codes.
     * @param array<int,array{from:int,to:int}> $successRanges Success status ranges.
     * @param array<int,int> $retryStatuses HTTP statuses retried across attempts.
     */
    public function __construct(
        public array $methods = ['auto', 'curl', 'stream'],
        public int $maxAttempts = 3,
        public bool $untilSuccess = false,
        public int $maxRuntimeSeconds = 0,
        public int $gapMs = 0,
        public int $retryDelaySeconds = 5,
        public float $backoffMultiplier = 1.5,
        public int $maxDelaySeconds = 300,
        public bool $retryChallenge = false,
        public bool $stopOnChallenge = true,
        public bool $saveBody = false,
        public bool $includeHeaders = false,
        public array $successStatuses = [],
        public array $successRanges = [['from' => 200, 'to' => 399]],
        public array $retryStatuses = [0, 408, 425, 429, 500, 502, 503, 504],
        public ?string $stopFile = null,
    ) {
        $this->methods = array_values(array_filter(array_unique(array_map(static fn ($m): string => strtolower(trim((string) $m)), $this->methods))));
        if ($this->methods === []) {
            $this->methods = ['auto'];
        }
        $this->maxAttempts = max(0, $this->maxAttempts);
        $this->gapMs = max(0, $this->gapMs);
        $this->retryDelaySeconds = max(0, $this->retryDelaySeconds);
        $this->maxDelaySeconds = max(1, $this->maxDelaySeconds);
        $this->backoffMultiplier = max(1.0, $this->backoffMultiplier);
        $this->maxRuntimeSeconds = max(0, $this->maxRuntimeSeconds);
    }

    public function effectiveMaxAttempts(): int
    {
        if ($this->untilSuccess && $this->maxAttempts === 0) {
            return 1000000000;
        }
        if ($this->untilSuccess) {
            return max($this->maxAttempts, 1);
        }
        return max($this->maxAttempts, 1);
    }

    public function isSuccessStatus(int $statusCode): bool
    {
        if (in_array($statusCode, $this->successStatuses, true)) {
            return true;
        }
        foreach ($this->successRanges as $range) {
            $from = (int) ($range['from'] ?? 0);
            $to = (int) ($range['to'] ?? 0);
            if ($statusCode >= $from && $statusCode <= $to) {
                return true;
            }
        }
        return false;
    }

    public function shouldRetryStatus(int $statusCode): bool
    {
        return in_array($statusCode, $this->retryStatuses, true);
    }
}
