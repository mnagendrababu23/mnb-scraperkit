<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class RobotsDecision
{
    public function __construct(
        public bool $allowed,
        public string $url,
        public string $robotsUrl,
        public string $userAgent,
        public ?string $matchedDirective = null,
        public ?string $matchedRule = null,
        public ?int $matchedLine = null,
        public string $reason = '',
        public bool $robotsFetched = false,
        public ?int $robotsStatusCode = null,
        public ?string $error = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'url' => $this->url,
            'robots_url' => $this->robotsUrl,
            'user_agent' => $this->userAgent,
            'matched_directive' => $this->matchedDirective,
            'matched_rule' => $this->matchedRule,
            'matched_line' => $this->matchedLine,
            'reason' => $this->reason,
            'robots_fetched' => $this->robotsFetched,
            'robots_status_code' => $this->robotsStatusCode,
            'error' => $this->error,
        ];
    }
}
