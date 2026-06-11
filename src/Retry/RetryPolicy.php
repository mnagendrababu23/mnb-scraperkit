<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Retry;

/**
 * Conservative retry planner for crawl failures and queue jobs.
 *
 * The policy retries only temporary/network-like failures by default. Policy,
 * safety, authorization, validation, and scope failures are kept out of the
 * retry queue unless a caller explicitly builds a custom workflow around them.
 */
final class RetryPolicy
{
    /** @var list<string> */
    public const SAFE_FAILURE_TYPES = [
        'timeout',
        'dns_error',
        'ssl_error',
        'http_5xx',
        'server_error',
        'rate_limited',
        'connection_reset',
        'temporary_network_error',
        'http_client_error',
        'no_response',
    ];

    /** @var list<string> */
    public const UNSAFE_FAILURE_TYPES = [
        'robots_blocked',
        'private_ip_blocked',
        'unsupported_scheme',
        'auth_or_cookie_redirect',
        'final_domain_guard',
        'validation_failed',
        'redirect_loop',
        'client_error',
        'forbidden',
        'unauthorized',
        'not_found',
        'body_too_large',
        'challenge_or_protection',
    ];

    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelaySeconds = 60,
        private readonly float $multiplier = 2.0,
        private readonly int $maxDelaySeconds = 3600
    ) {
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function decision(array $row): array
    {
        $attempts = max(0, (int) ($row['attempts'] ?? $row['retry_attempts'] ?? 0));
        $failureType = $this->failureType($row);
        $statusCode = (int) ($row['status_code'] ?? $row['http_status'] ?? $row['last_status_code'] ?? 0);
        $safe = in_array($failureType, self::SAFE_FAILURE_TYPES, true) || ($statusCode >= 500 && $statusCode < 600) || $statusCode === 429;
        $unsafe = in_array($failureType, self::UNSAFE_FAILURE_TYPES, true) || ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429);
        $eligible = $safe && !$unsafe && $attempts < $this->maxAttempts;
        $reason = $eligible ? 'temporary_failure_retry_allowed' : 'not_retryable_by_default';
        if ($attempts >= $this->maxAttempts) {
            $reason = 'max_attempts_reached';
        } elseif ($unsafe) {
            $reason = 'policy_or_permanent_failure';
        }

        return [
            'url' => (string) ($row['url'] ?? $row['source_url'] ?? $row['final_url'] ?? $row['job_id'] ?? ''),
            'job_id' => (string) ($row['job_id'] ?? ''),
            'failure_type' => $failureType,
            'status_code' => $statusCode,
            'attempts' => $attempts,
            'max_attempts' => $this->maxAttempts,
            'retry_eligible' => $eligible,
            'retry_delay_seconds' => $eligible ? $this->delaySeconds($attempts + 1) : 0,
            'next_attempt_at' => $eligible ? date(DATE_ATOM, time() + $this->delaySeconds($attempts + 1)) : null,
            'reason' => $reason,
            'recommended_action' => $this->recommendedAction($failureType, $statusCode, $eligible),
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    public function plan(array $rows): array
    {
        $decisions = [];
        $eligible = 0;
        $counts = [];
        foreach ($rows as $row) {
            $decision = $this->decision($row);
            $decisions[] = $decision;
            if (!empty($decision['retry_eligible'])) {
                $eligible++;
            }
            $type = (string) $decision['failure_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);
        return [
            'retry_policy_version' => '2.0.0',
            'generated_at' => date(DATE_ATOM),
            'total' => count($decisions),
            'eligible' => $eligible,
            'not_eligible' => count($decisions) - $eligible,
            'failure_type_counts' => $counts,
            'decisions' => $decisions,
        ];
    }

    /** @param array<string,mixed> $row */
    public function isRetryEligible(array $row): bool
    {
        return (bool) $this->decision($row)['retry_eligible'];
    }

    private function delaySeconds(int $attempt): int
    {
        $delay = (int) round($this->baseDelaySeconds * ($this->multiplier ** max(0, $attempt - 1)));
        return max(0, min($this->maxDelaySeconds, $delay));
    }

    /** @param array<string,mixed> $row */
    private function failureType(array $row): string
    {
        foreach (['failure_type', 'error_type', 'status_category', 'last_failure_type'] as $key) {
            $value = strtolower(trim((string) ($row[$key] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }
        $message = strtolower((string) ($row['error'] ?? $row['last_error'] ?? $row['message'] ?? ''));
        if (str_contains($message, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($message, 'dns') || str_contains($message, 'resolve')) {
            return 'dns_error';
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'tls')) {
            return 'ssl_error';
        }
        if (str_contains($message, 'private ip') || str_contains($message, 'localhost')) {
            return 'private_ip_blocked';
        }
        if (str_contains($message, 'robot')) {
            return 'robots_blocked';
        }
        $statusCode = (int) ($row['status_code'] ?? $row['http_status'] ?? $row['last_status_code'] ?? 0);
        if ($statusCode === 429) {
            return 'rate_limited';
        }
        if ($statusCode >= 500) {
            return 'http_5xx';
        }
        if ($statusCode >= 400) {
            return 'http_4xx';
        }
        return 'unknown';
    }

    private function recommendedAction(string $failureType, int $statusCode, bool $eligible): string
    {
        if ($eligible) {
            return $statusCode === 429 ? 'retry_after_cooldown_or_slower_pacing' : 'retry_with_backoff';
        }
        return match ($failureType) {
            'robots_blocked' => 'respect_robots_or_use_allowed_official_source',
            'private_ip_blocked' => 'check_input_urls_and_keep_ssrf_protection_enabled',
            'unsupported_scheme' => 'use_http_or_https_urls_only',
            'auth_or_cookie_redirect' => 'configure_authorized_session_workflow_if_permitted',
            'final_domain_guard' => 'review_allowed_domains_and_final_url_scope',
            'validation_failed' => 'review_extraction_rules_or_required_fields',
            'redirect_loop' => 'inspect_redirect_chain_before_retrying',
            default => 'review_failure_before_retrying',
        };
    }
}
