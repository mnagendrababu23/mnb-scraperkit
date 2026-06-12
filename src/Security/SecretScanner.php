<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Security;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SecretScanner
{
    /** @var array<string,string> */
    private array $patterns = [
        'private_key_block' => '/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/i',
        'aws_access_key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'generic_api_key' => '/\b(api[_-]?key|apikey|secret[_-]?key|access[_-]?token|bearer[_-]?token)\s*[:=]\s*["\']?[A-Za-z0-9_\-\.]{20,}/i',
        'password_assignment' => '/\b(password|passwd|pwd)\s*[:=]\s*["\'][^"\']{6,}["\']/i',
        'github_token' => '/\bgh[pousr]_[A-Za-z0-9_]{20,}\b/',
        'slack_token' => '/\bxox[baprs]-[A-Za-z0-9\-]{20,}\b/',
    ];

    /** @return array<string,mixed> */
    public function scan(string $rootDir, int $maxFileBytes = 1048576): array
    {
        $rootDir = rtrim($rootDir, '/\\');
        $findings = [];
        $filesScanned = 0;

        foreach ($this->files($rootDir) as $file) {
            $path = $file->getPathname();
            $relative = $this->relative($rootDir, $path);
            if ($this->shouldSkip($relative, $file, $maxFileBytes)) {
                continue;
            }
            $filesScanned++;
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $index => $line) {
                foreach ($this->patterns as $id => $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $findings[] = (new SecurityFinding(
                            'secret_' . $id,
                            in_array($id, ['private_key_block', 'aws_access_key', 'github_token', 'slack_token'], true) ? 'critical' : 'high',
                            'secrets',
                            'Possible secret detected',
                            'A line matches the secret pattern: ' . $id,
                            $relative,
                            $index + 1,
                            'Remove the secret from the package, rotate it if it was real, and load secrets through environment variables or a local ignored config file.'
                        ))->toArray();
                    }
                }
            }
        }

        return [
            'ok' => count($findings) === 0,
            'scanner_version' => '1.0.1',
            'files_scanned' => $filesScanned,
            'findings_total' => count($findings),
            'findings' => $findings,
        ];
    }

    /** @return iterable<SplFileInfo> */
    private function files(string $rootDir): iterable
    {
        if (!is_dir($rootDir)) {
            return [];
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }

    private function shouldSkip(string $relative, SplFileInfo $file, int $maxFileBytes): bool
    {
        $normalized = str_replace('\\', '/', $relative);
        foreach (['/.git/', '/vendor/', '/node_modules/', '/.idea/', '/.vscode/'] as $skip) {
            if (str_contains('/' . $normalized, $skip)) {
                return true;
            }
        }
        if ($file->getSize() > $maxFileBytes) {
            return true;
        }
        $ext = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if (in_array($ext, ['zip', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'sqlite', 'db', 'phar'], true)) {
            return true;
        }
        return false;
    }

    private function relative(string $rootDir, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', realpath($rootDir) ?: $rootDir), '/') . '/';
        $real = str_replace('\\', '/', realpath($path) ?: $path);
        return str_starts_with($real, $root) ? substr($real, strlen($root)) : $path;
    }
}
