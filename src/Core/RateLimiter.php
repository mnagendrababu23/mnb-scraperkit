<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class RateLimiter
{
    private float $lastRequestAt = 0.0;
    /** @var array<string,float> */
    private array $lastRequestByHost = [];
    private int $requestCount = 0;
    private int $consecutiveFailures = 0;
    private float $cooldownUntil = 0.0;

    public function wait(int $delayMs): void
    {
        $this->sleepUntilDelay($delayMs, $this->lastRequestAt);
        $this->lastRequestAt = microtime(true);
    }

    public function waitFor(string $url, CrawlOptions $options): void
    {
        $now = microtime(true);
        if ($this->cooldownUntil > $now) {
            usleep((int) (($this->cooldownUntil - $now) * 1000000));
        }

        $delayMs = max(0, $options->delayMs);
        if ($options->delayJitterMs > 0) {
            $delayMs += random_int(0, $options->delayJitterMs);
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '') {
            $lastHostAt = $this->lastRequestByHost[$host] ?? 0.0;
            $this->sleepUntilDelay($delayMs, $lastHostAt);
            $this->lastRequestByHost[$host] = microtime(true);
        } else {
            $this->sleepUntilDelay($delayMs, $this->lastRequestAt);
        }

        $this->lastRequestAt = microtime(true);
        $this->requestCount++;

        if ($options->pauseAfterUrls > 0 && $options->pauseSeconds > 0 && $this->requestCount % $options->pauseAfterUrls === 0) {
            sleep($options->pauseSeconds);
            $this->lastRequestAt = microtime(true);
            if ($host !== '') {
                $this->lastRequestByHost[$host] = $this->lastRequestAt;
            }
        }
    }

    public function registerOutcome(?string $failureType, CrawlOptions $options): void
    {
        if (FailureClassifier::isRetryable($failureType)) {
            $this->consecutiveFailures++;
        } else {
            $this->consecutiveFailures = 0;
        }

        if ($options->cooldownAfterFailures > 0
            && $options->cooldownSeconds > 0
            && $this->consecutiveFailures >= $options->cooldownAfterFailures
        ) {
            $this->cooldownUntil = max($this->cooldownUntil, microtime(true) + $options->cooldownSeconds);
            $this->consecutiveFailures = 0;
        }
    }

    private function sleepUntilDelay(int $delayMs, float $lastAt): void
    {
        if ($delayMs <= 0 || $lastAt <= 0) {
            return;
        }

        $now = microtime(true);
        $target = $lastAt + ($delayMs / 1000);
        if ($now < $target) {
            usleep((int) (($target - $now) * 1000000));
        }
    }
}
