<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Security;

final class SecurityFinding
{
    public function __construct(
        public readonly string $id,
        public readonly string $severity,
        public readonly string $category,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $path = null,
        public readonly ?int $line = null,
        public readonly string $recommendation = ''
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity,
            'category' => $this->category,
            'title' => $this->title,
            'message' => $this->message,
            'path' => $this->path,
            'line' => $this->line,
            'recommendation' => $this->recommendation,
        ];
    }
}
