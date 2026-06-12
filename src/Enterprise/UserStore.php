<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Enterprise;

/**
 * File-backed user registry for local/team ScraperKit workspaces.
 *
 * This is intentionally lightweight. It stores user metadata and role labels,
 * not passwords. Authentication should be handled by the hosting layer/API token
 * until a future full multi-user web application is introduced.
 */
final class UserStore
{
    public const VERSION = '4.1.0';
    public const ROLES = ['owner', 'admin', 'operator', 'analyst', 'viewer'];

    public function __construct(private readonly string $rootDir)
    {
    }

    public function usersFile(): string
    {
        return $this->enterpriseDir() . '/users.json';
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function create(array $options): array
    {
        $users = $this->readUsers();
        $id = $this->id((string) ($options['user_id'] ?? $options['id'] ?? $options['email'] ?? $options['name'] ?? ''));
        if ($id === '') {
            throw new \InvalidArgumentException('User ID, email, or name is required.');
        }
        if (isset($users[$id])) {
            throw new \RuntimeException('User already exists: ' . $id);
        }
        $role = $this->role((string) ($options['role'] ?? 'viewer'));
        $now = date(DATE_ATOM);
        $user = [
            'user_id' => $id,
            'display_name' => trim((string) ($options['display_name'] ?? $options['name'] ?? $id)),
            'email' => trim((string) ($options['email'] ?? '')),
            'role' => $role,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $users[$id] = $user;
        $this->writeUsers($users);
        (new AuditLog($this->rootDir))->append('user.create', (string) ($options['actor'] ?? 'system'), $id, ['role' => $role]);
        return $user;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(?string $role = null, bool $includeDisabled = true): array
    {
        $users = array_values($this->readUsers());
        if ($role !== null && $role !== '') {
            $role = $this->role($role);
            $users = array_values(array_filter($users, static fn(array $u): bool => (string) ($u['role'] ?? '') === $role));
        }
        if (!$includeDisabled) {
            $users = array_values(array_filter($users, static fn(array $u): bool => (string) ($u['status'] ?? 'active') !== 'disabled'));
        }
        usort($users, static fn(array $a, array $b): int => strcmp((string) ($a['user_id'] ?? ''), (string) ($b['user_id'] ?? '')));
        return $users;
    }

    /** @return array<string,mixed> */
    public function show(string $id): array
    {
        $id = $this->id($id);
        $users = $this->readUsers();
        if (!isset($users[$id])) {
            throw new \RuntimeException('User not found: ' . $id);
        }
        return $users[$id];
    }

    /** @return array<string,mixed> */
    public function disable(string $id, string $actor = 'system'): array
    {
        $id = $this->id($id);
        $users = $this->readUsers();
        if (!isset($users[$id])) {
            throw new \RuntimeException('User not found: ' . $id);
        }
        $users[$id]['status'] = 'disabled';
        $users[$id]['updated_at'] = date(DATE_ATOM);
        $this->writeUsers($users);
        (new AuditLog($this->rootDir))->append('user.disable', $actor, $id);
        return $users[$id];
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $users = $this->list();
        $roles = array_fill_keys(self::ROLES, 0);
        $active = 0;
        foreach ($users as $user) {
            $roles[(string) ($user['role'] ?? 'viewer')] = ($roles[(string) ($user['role'] ?? 'viewer')] ?? 0) + 1;
            if ((string) ($user['status'] ?? 'active') !== 'disabled') {
                $active++;
            }
        }
        return [
            'users_total' => count($users),
            'active_users' => $active,
            'disabled_users' => count($users) - $active,
            'roles' => $roles,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function readUsers(): array
    {
        $file = $this->usersFile();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        $users = $data['users'] ?? $data;
        if (!is_array($users)) {
            return [];
        }
        $out = [];
        foreach ($users as $key => $user) {
            if (is_array($user)) {
                $id = $this->id((string) ($user['user_id'] ?? $key));
                if ($id !== '') {
                    $user['user_id'] = $id;
                    $out[$id] = $user;
                }
            }
        }
        return $out;
    }

    /** @param array<string,array<string,mixed>> $users */
    private function writeUsers(array $users): void
    {
        $this->ensureDir(dirname($this->usersFile()));
        file_put_contents($this->usersFile(), json_encode([
            'enterprise_version' => self::VERSION,
            'updated_at' => date(DATE_ATOM),
            'users' => $users,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

    private function id(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9_.@-]+/', '-', $value);
        return trim($value, '-');
    }

    private function role(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, self::ROLES, true) ? $role : 'viewer';
    }
}
