<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Database;

use PDO;

final class DatabaseConnectionFactory
{
    public function connect(DatabaseConfig $config): PDO
    {
        if (!class_exists(PDO::class)) {
            throw new \RuntimeException('PDO is not available. Install/enable PHP PDO before using database storage.');
        }

        if ($config->driver() === 'sqlite') {
            $path = $config->sqlitePath();
            if ($path !== null && $path !== ':memory:') {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
            }
        }

        $pdo = new PDO($config->dsn, $config->username, $config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        return $pdo;
    }
}
