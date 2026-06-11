<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Enterprise;

/**
 * Append-only local audit log for enterprise workspace/user actions.
 */
final class AuditLog
{
    public const VERSION = '4.0.1';

    public function __construct(private readonly string $rootDir)
    {
    }

    public function auditFile(): string
    {
        return $this->enterpriseDir() . '/audit-events.jsonl';
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function append(string $action, string $actor = 'system', string $target = '', array $context = []): array
    {
        $event = [
            'event_id' => 'evt_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
            'enterprise_version' => self::VERSION,
            'action' => $this->safeToken($action, 'action'),
            'actor' => $this->safeToken($actor, 'system'),
            'target' => $target,
            'context' => $context,
            'created_at' => date(DATE_ATOM),
        ];
        $this->ensureDir(dirname($this->auditFile()));
        file_put_contents($this->auditFile(), json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        return $event;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(int $limit = 50, ?string $actor = null, ?string $action = null): array
    {
        $file = $this->auditFile();
        if (!is_file($file)) {
            return [];
        }
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            if ($actor !== null && (string) ($row['actor'] ?? '') !== $actor) {
                continue;
            }
            if ($action !== null && (string) ($row['action'] ?? '') !== $action) {
                continue;
            }
            $rows[] = $row;
        }
        $rows = array_reverse($rows);
        return array_slice($rows, 0, max(1, $limit));
    }

    private function enterpriseDir(): string
    {
        $dir = rtrim($this->rootDir, '/\\') . '/storage/enterprise';
        $this->ensureDir($dir);
        return $dir;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function safeToken(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9_.:-]+/', '-', $value);
        $value = trim($value, '-');
        return $value !== '' ? $value : $fallback;
    }
}
