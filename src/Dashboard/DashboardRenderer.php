<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Dashboard;

/**
 * Renders a small dependency-free HTML dashboard.
 */
final class DashboardRenderer
{
    /** @param array<string,mixed> $data @param array<string,mixed> $options */
    public function render(array $data, array $options = []): string
    {
        $refresh = max(0, (int) ($options['refresh_seconds'] ?? 0));
        $title = $this->e((string) ($options['title'] ?? 'MNB ScraperKit Dashboard'));
        $health = $this->e((string) ($data['health'] ?? 'unknown'));
        $generatedAt = $this->e((string) ($data['generated_at'] ?? ''));
        $version = $this->e((string) ($data['library_version'] ?? DashboardDataCollector::VERSION));
        $queueCounts = (array) ($data['queue']['counts'] ?? []);
        $scheduleInfo = (array) ($data['schedules'] ?? []);
        $monitor = (array) ($data['monitor'] ?? []);
        $profiles = (array) ($data['profiles']['items'] ?? []);
        $plugins = (array) ($data['plugins']['items'] ?? []);
        $jobs = (array) ($data['queue']['recent_jobs'] ?? []);
        $schedules = (array) ($data['schedules']['recent'] ?? []);
        $commandsTotal = (int) ($data['commands']['total'] ?? 0);
        $apiTotal = (int) ($data['api']['routes_total'] ?? 0);
        $enterprise = (array) ($data['enterprise'] ?? []);
        $workspaceSummary = (array) ($enterprise['workspaces'] ?? []);
        $userSummary = (array) ($enterprise['users'] ?? []);

        $metaRefresh = $refresh > 0 ? '<meta http-equiv="refresh" content="' . $refresh . '">' : '';
        $cards = [
            ['Queue pending', (string) ($queueCounts['pending'] ?? 0)],
            ['Queue running', (string) ($queueCounts['running'] ?? 0)],
            ['Queue failed', (string) ($queueCounts['failed'] ?? 0)],
            ['Schedules due', (string) ($scheduleInfo['due_total'] ?? 0)],
            ['Stale locks', (string) ($monitor['stale_locks_total'] ?? 0)],
            ['Profiles', (string) ($data['profiles']['total'] ?? 0)],
            ['Plugins', (string) ($data['plugins']['total'] ?? 0)],
            ['Commands', (string) $commandsTotal],
            ['API routes', (string) $apiTotal],
            ['Workspaces', (string) ($workspaceSummary['workspaces_total'] ?? 0)],
            ['Users', (string) ($userSummary['users_total'] ?? 0)],
        ];

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $metaRefresh . '<title>' . $title . '</title>';
        $html .= '<style>' . $this->css() . '</style></head><body>';
        $html .= '<main class="shell"><header class="hero"><div><p class="eyebrow">MNB ScraperKit V' . $version . '</p><h1>' . $title . '</h1><p>Local admin dashboard for queue jobs, schedules, workers, profiles, plugins, API routes, and system health.</p></div><div class="health health-' . $this->classSafe($health) . '">' . $health . '</div></header>';
        $html .= '<section class="grid cards">';
        foreach ($cards as [$label, $value]) {
            $html .= '<article class="card"><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong></article>';
        }
        $html .= '</section>';

        $html .= '<section class="panel"><h2>Recent jobs</h2>' . $this->jobsTable($jobs) . '</section>';
        $html .= '<section class="grid two"><article class="panel"><h2>Schedules</h2>' . $this->schedulesTable($schedules) . '</article><article class="panel"><h2>Profiles and plugins</h2>' . $this->profilePluginList($profiles, $plugins) . '</article></section>';
        $html .= '<section class="panel"><h2>Operations notes</h2><ul><li>Run <code>php bin/mnb-scraper worker:run</code> to process queued jobs.</li><li>Run <code>php bin/mnb-scraper schedule:run-due</code> from cron or Windows Task Scheduler to enqueue due schedules.</li><li>Use <code>workspace:create</code> and <code>user:create</code> to organize team/project metadata.</li><li>Set <code>MNB_SCRAPERKIT_DASHBOARD_TOKEN</code> to protect this dashboard when exposed beyond localhost.</li><li>Use <code>/dashboard.json</code> for a machine-readable dashboard snapshot.</li></ul></section>';
        $html .= '<footer>Generated at ' . $generatedAt . ' from ' . $this->e((string) ($data['root_dir'] ?? '')) . '</footer></main></body></html>';
        return $html;
    }

    /** @param array<int,mixed> $jobs */
    private function jobsTable(array $jobs): string
    {
        if ($jobs === []) {
            return '<p class="muted">No queued jobs found yet.</p>';
        }
        $html = '<div class="table-wrap"><table><thead><tr><th>Job ID</th><th>State</th><th>Command</th><th>Attempts</th><th>Updated</th></tr></thead><tbody>';
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }
            $html .= '<tr><td><code>' . $this->e((string) ($job['job_id'] ?? '')) . '</code></td><td>' . $this->badge((string) ($job['state'] ?? 'unknown')) . '</td><td>' . $this->e((string) ($job['command'] ?? '')) . '</td><td>' . $this->e((string) ($job['attempts'] ?? 0)) . '</td><td>' . $this->e((string) ($job['updated_at'] ?? $job['created_at'] ?? '')) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** @param array<int,mixed> $schedules */
    private function schedulesTable(array $schedules): string
    {
        if ($schedules === []) {
            return '<p class="muted">No schedules created yet.</p>';
        }
        $html = '<div class="table-wrap"><table><thead><tr><th>Schedule</th><th>Status</th><th>Command</th><th>Next run</th></tr></thead><tbody>';
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $status = (bool) ($schedule['enabled'] ?? true) ? 'enabled' : 'disabled';
            $html .= '<tr><td><code>' . $this->e((string) ($schedule['schedule_id'] ?? '')) . '</code></td><td>' . $this->badge($status) . '</td><td>' . $this->e((string) ($schedule['command'] ?? '')) . '</td><td>' . $this->e((string) ($schedule['next_run_at'] ?? '')) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** @param array<int,mixed> $profiles @param array<int,mixed> $plugins */
    private function profilePluginList(array $profiles, array $plugins): string
    {
        $profileNames = [];
        foreach ($profiles as $profile) {
            if (is_array($profile)) {
                $profileNames[] = (string) ($profile['profile'] ?? $profile['name'] ?? $profile['id'] ?? 'profile');
            } else {
                $profileNames[] = (string) $profile;
            }
        }
        $pluginNames = [];
        foreach ($plugins as $plugin) {
            if (is_array($plugin)) {
                $pluginNames[] = (string) ($plugin['id'] ?? $plugin['name'] ?? 'plugin');
            }
        }
        return '<p><strong>Profiles:</strong> ' . $this->e(implode(', ', array_slice($profileNames, 0, 20)) ?: 'none') . '</p><p><strong>Plugins:</strong> ' . $this->e(implode(', ', array_slice($pluginNames, 0, 20)) ?: 'none') . '</p>';
    }

    private function badge(string $value): string
    {
        return '<span class="badge badge-' . $this->classSafe($value) . '">' . $this->e($value) . '</span>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function classSafe(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value));
    }

    private function css(): string
    {
        return 'body{margin:0;background:#0f172a;color:#e5e7eb;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.shell{max-width:1180px;margin:0 auto;padding:32px}.hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;background:linear-gradient(135deg,#1e293b,#111827);border:1px solid #334155;border-radius:24px;padding:28px;margin-bottom:22px}.eyebrow{margin:0 0 8px;color:#93c5fd;text-transform:uppercase;letter-spacing:.08em;font-weight:700}h1{margin:0 0 10px;font-size:36px}h2{margin-top:0}.health{padding:10px 16px;border-radius:999px;font-weight:800;text-transform:uppercase;background:#334155}.health-ok,.badge-completed,.badge-enabled{background:#064e3b;color:#bbf7d0}.health-attention,.badge-failed{background:#7f1d1d;color:#fecaca}.health-warning,.badge-running,.badge-pending{background:#78350f;color:#fde68a}.grid{display:grid;gap:16px}.cards{grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:16px}.two{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}.card,.panel{background:#111827;border:1px solid #334155;border-radius:18px;padding:18px}.card span{display:block;color:#94a3b8}.card strong{display:block;font-size:30px;margin-top:4px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #253044;padding:10px;text-align:left;vertical-align:top}th{color:#93c5fd}.badge{display:inline-block;padding:3px 9px;border-radius:999px;background:#334155;color:#e5e7eb;font-size:12px;font-weight:700}.muted,footer{color:#94a3b8}code{background:#020617;border:1px solid #334155;border-radius:6px;padding:1px 5px}footer{margin-top:22px;font-size:13px}a{color:#93c5fd}';
    }
}
