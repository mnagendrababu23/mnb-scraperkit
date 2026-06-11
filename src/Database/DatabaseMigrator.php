<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Database;

use PDO;

final class DatabaseMigrator
{
    public function __construct(private PDO $pdo, private string $driver = 'sqlite')
    {
    }

    /** @return array{driver:string,statements:int,tables:list<string>} */
    public function migrate(): array
    {
        $count = 0;
        foreach (DatabaseSchema::statements($this->driver) as $sql) {
            $this->pdo->exec($sql);
            $count++;
        }
        return [
            'driver' => $this->driver,
            'statements' => $count,
            'tables' => DatabaseSchema::tableNames(),
        ];
    }
}
