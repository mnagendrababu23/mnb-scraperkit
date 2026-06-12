<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Enterprise;

/**
 * File-backed project workspace registry with user membership and role labels.
 */
final class WorkspaceStore
{
    public const VERSION = '4.0.2';
    public const ROLES = ['owner', 'admin', 'operator', 'analyst', 'viewer'];

    public function __construct(private readonly string $rootDir)
    {
    }

    public function workspacesDir(): string
    {
        $dir = $this->enterpriseDir() . '/workspaces';
        $this->ensureDir($dir);
        return $dir;
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function create(array $options): array
    {
        $id = $this->id((string) ($options['workspace_id'] ?? $options['id'] ?? $options['name'] ?? ''));
        if ($id === '') {
            throw new \InvalidArgumentException('Workspace name or ID is required.');
        }
        $file = $this->workspaceFile($id);
        if (is_file($file)) {
            throw new \RuntimeException('Workspace already exists: ' . $id);
        }
        $owner = $this->userId((string) ($options['owner'] ?? $options['user_id'] ?? ''));
        $now = date(DATE_ATOM);
        $workspace = [
            'workspace_id' => $id,
            'name' => trim((string) ($options['name'] ?? $id)),
            'description' => trim((string) ($options['description'] ?? '')),
            'project_dir' => trim((string) ($options['project_dir'] ?? ('storage/projects/' . $id))),
            'status' => 'active',
            'members' => [],
            'settings' => [
                'default_profile' => trim((string) ($options['profile'] ?? 'seo')),
                'default_queue' => trim((string) ($options['queue'] ?? 'local')),
                'data_retention_days' => (int) ($options['retention_days'] ?? 90),
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($owner !== '') {
            $workspace['members'][] = [
                'user_id' => $owner,
                'role' => 'owner',
                'assigned_at' => $now,
                'assigned_by' => (string) ($options['actor'] ?? 'system'),
            ];
        }
        $this->writeWorkspace($workspace);
        (new AuditLog($this->rootDir))->append('workspace.create', (string) ($options['actor'] ?? 'system'), $id, ['owner' => $owner]);
        return $workspace;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $rows = [];
        foreach (glob($this->workspacesDir() . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            if ($status !== null && $status !== '' && (string) ($data['status'] ?? '') !== $status) {
                continue;
            }
            $rows[] = $data;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) ($a['workspace_id'] ?? ''), (string) ($b['workspace_id'] ?? '')));
        return $rows;
    }

    /** @return array<string,mixed> */
    public function show(string $id): array
    {
        $id = $this->id($id);
        $file = $this->workspaceFile($id);
        if (!is_file($file)) {
            throw new \RuntimeException('Workspace not found: ' . $id);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid workspace JSON: ' . $id);
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public function assignUser(string $workspaceId, string $userId, string $role = 'viewer', string $actor = 'system'): array
    {
        $workspace = $this->show($workspaceId);
        $userId = $this->userId($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('User ID is required.');
        }
        $role = $this->role($role);
        $members = array_values(array_filter((array) ($workspace['members'] ?? []), static fn(mixed $m): bool => is_array($m) && (string) ($m['user_id'] ?? '') !== $userId));
        $members[] = [
            'user_id' => $userId,
            'role' => $role,
            'assigned_at' => date(DATE_ATOM),
            'assigned_by' => $actor,
        ];
        $workspace['members'] = $members;
        $workspace['updated_at'] = date(DATE_ATOM);
        $this->writeWorkspace($workspace);
        (new AuditLog($this->rootDir))->append('workspace.assign-user', $actor, (string) ($workspace['workspace_id'] ?? $workspaceId), ['user_id' => $userId, 'role' => $role]);
        return $workspace;
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $workspaces = $this->list();
        $members = 0;
        foreach ($workspaces as $workspace) {
            $members += count((array) ($workspace['members'] ?? []));
        }
        return [
            'workspaces_total' => count($workspaces),
            'memberships_total' => $members,
            'workspaces_dir' => $this->workspacesDir(),
        ];
    }

    private function workspaceFile(string $id): string
    {
        return $this->workspacesDir() . '/' . $this->id($id) . '.json';
    }

    /** @param array<string,mixed> $workspace */
    private function writeWorkspace(array $workspace): void
    {
        $workspace['enterprise_version'] = self::VERSION;
        file_put_contents($this->workspaceFile((string) ($workspace['workspace_id'] ?? 'workspace')), json_encode($workspace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
        $value = (string) preg_replace('/[^a-z0-9_.-]+/', '-', $value);
        return trim($value, '-');
    }

    private function userId(string $value): string
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
