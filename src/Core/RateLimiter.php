<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class RateLimiter
{
    private float $lastRequestAt = 0.0;

    public function wait(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        $now = microtime(true);
        $target = $this->lastRequestAt + ($delayMs / 1000);
        if ($this->lastRequestAt > 0 && $now < $target) {
            usleep((int) (($target - $now) * 1000000));
        }
        $this->lastRequestAt = microtime(true);
    }
}
