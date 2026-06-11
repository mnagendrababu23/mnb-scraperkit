<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Database;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $dsn,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        /** @var array<int,string> */
        public readonly array $options = []
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $rootDir): self
    {
        $dsn = (string) ($data['database_url'] ?? $data['database-url'] ?? $data['dsn'] ?? '');
        if ($dsn === '') {
            $sqlite = (string) ($data['sqlite'] ?? $data['sqlite_path'] ?? $data['sqlite-path'] ?? '');
            if ($sqlite === '') {
                $sqlite = rtrim($rootDir, '/\\') . '/storage/database/mnb-scraperkit.sqlite';
            }
            if (!str_starts_with($sqlite, '/') && !preg_match('/^[A-Za-z]:[\\\/]/', $sqlite)) {
                $sqlite = rtrim($rootDir, '/\\') . '/' . ltrim($sqlite, '/\\');
            }
            $dsn = 'sqlite:' . $sqlite;
        }

        return new self(
            dsn: $dsn,
            username: isset($data['db_user']) ? (string) $data['db_user'] : (isset($data['db-user']) ? (string) $data['db-user'] : null),
            password: isset($data['db_pass']) ? (string) $data['db_pass'] : (isset($data['db-pass']) ? (string) $data['db-pass'] : null),
            options: []
        );
    }

    public function driver(): string
    {
        $pos = strpos($this->dsn, ':');
        return strtolower($pos === false ? $this->dsn : substr($this->dsn, 0, $pos));
    }

    public function sqlitePath(): ?string
    {
        if ($this->driver() !== 'sqlite') {
            return null;
        }
        return substr($this->dsn, strlen('sqlite:')) ?: null;
    }
}
