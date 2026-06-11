<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Distributed;

final class DistributedJob
{
    /** @param array<string,mixed> $payload @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly array $payload,
        public readonly array $metadata = []
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
        ];
    }
}
