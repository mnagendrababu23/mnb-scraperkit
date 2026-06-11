<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Hardening;

use Mnb\ScraperKit\Console\CommandRegistry;

final class CommandErrorRenderer
{
    public static function exception(string $command, \Throwable $e): string
    {
        $lines = [
            'ERROR: ' . $e->getMessage(),
        ];
        if ($command !== '') {
            $lines[] = 'Command: ' . $command;
            $lines[] = 'Run help: php bin/mnb-scraper ' . $command . ' --help';
        }
        $lines[] = 'Run diagnostics: php bin/mnb-scraper hardening:doctor';
        return implode(PHP_EOL, $lines);
    }

    public static function unknownCommand(string $command): string
    {
        $commands = array_keys(CommandRegistry::commands());
        $suggestions = [];
        foreach ($commands as $candidate) {
            $distance = levenshtein($command, $candidate);
            if ($distance <= 8 || str_starts_with($candidate, strtok($command, ':') ?: $command)) {
                $suggestions[$candidate] = $distance;
            }
        }
        asort($suggestions);
        $suggestions = array_slice(array_keys($suggestions), 0, 5);

        $lines = [
            'Unknown command: ' . $command,
            'Run: php bin/mnb-scraper list',
        ];
        if ($suggestions !== []) {
            $lines[] = 'Did you mean: ' . implode(', ', $suggestions) . '?';
        }
        return implode(PHP_EOL, $lines);
    }
}
