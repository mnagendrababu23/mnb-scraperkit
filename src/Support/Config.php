<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Support;

final class Config
{
    /** @param array<string,mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
