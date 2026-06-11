<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Support;

final class Logger
{
    public function __construct(private ?string $logFile = null)
    {
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf('[%s] %s %s', date('c'), $level, $message);
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $line .= PHP_EOL;

        if ($this->logFile) {
            $dir = dirname($this->logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($this->logFile, $line, FILE_APPEND);
            return;
        }

        fwrite(STDERR, $line);
    }
}
