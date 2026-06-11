<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Distributed;

interface DistributedQueueAdapterInterface
{
    public function name(): string;

    public function available(): bool;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function enqueue(array $payload): array;

    public function reserve(string $workerId, int $leaseSeconds): ?DistributedJob;

    /** @return array<string,mixed> */
    public function ack(string $jobId, ?string $leaseId = null): array;

    /** @return array<string,mixed> */
    public function fail(string $jobId, string $message = '', ?string $leaseId = null): array;

    /** @return array<string,mixed> */
    public function heartbeat(string $jobId, string $workerId, ?string $leaseId = null): array;

    /** @return array<string,mixed> */
    public function status(): array;

    /** @return array<string,mixed> */
    public function purge(string $state = 'all'): array;
}
