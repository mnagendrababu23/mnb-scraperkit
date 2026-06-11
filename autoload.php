<?php

declare(strict_types=1);

/**
 * MNB ScraperKit fallback PSR-4 autoloader for core classes.
 * The public CLI build uses Composer + Symfony Console.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Mnb\\ScraperKit\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
