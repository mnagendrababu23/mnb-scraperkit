<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Security;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SecurityAuditScanner
{
    public function __construct(private readonly string $rootDir)
    {
    }

    /** @return array<string,mixed> */
    public function audit(?string $policyFile = null): array
    {
        $policy = CompliancePolicy::load($policyFile);
        $findings = [];
        $checks = [];

        $this->checkMissingCoreFiles($findings, $checks);
        $this->checkReleaseHygiene($findings, $checks);
        $this->checkReadableJsonConfigs($findings, $checks);
        $this->checkBrowserSessions($findings, $checks);
        $this->checkWebhookAndExportConnectorConfigs($findings, $checks);
        $this->checkPluginSafety($findings, $checks);
        $this->checkPublicSurface($findings, $checks);

        $secretScan = (new SecretScanner())->scan($this->rootDir);
        foreach ((array) ($secretScan['findings'] ?? []) as $finding) {
            if (is_array($finding)) {
                $findings[] = $finding;
            }
        }
        $checks[] = [
            'id' => 'secrets_scan',
            'ok' => (bool) ($secretScan['ok'] ?? false),
            'message' => 'Scanned package files for common committed secret patterns.',
            'files_scanned' => (int) ($secretScan['files_scanned'] ?? 0),
        ];

        $summary = $this->summarize($findings);
        $score = $this->score($summary);

        return [
            'ok' => $summary['critical'] === 0 && $summary['high'] === 0,
            'security_audit_version' => '4.1.0',
            'generated_at' => date(DATE_ATOM),
            'root_dir' => $this->rootDir,
            'score' => $score,
            'status' => $score >= 90 ? 'good' : ($score >= 75 ? 'needs_review' : 'needs_attention'),
            'summary' => $summary,
            'checks' => $checks,
            'findings' => $findings,
            'policy' => $policy,
            'recommendations' => $this->recommendations($summary),
        ];
    }

    /** @param array<int,array<string,mixed>> $findings @param array<int,array<string,mixed>> $checks */
    private function checkMissingCoreFiles(array &$findings, array &$checks): void
    {
        $required = ['composer.json', 'README.md', 'LICENSE', 'bin/mnb-scraper'];
        foreach ($required as $file) {
            $exists = is_file($this->rootDir . '/' . $file);
            $checks[] = ['id' => 'core_file_' . str_replace(['/', '.'], '_', $file), 'ok' => $exists, 'message' => $file . ($exists ? ' exists.' : ' is missing.')];
            if (!$exists) {
                $findings[] = $this->finding('missing_' . basename($file), 'high', 'release', 'Required release file missing', $file . ' is required for a clean package release.', $file, null, 'Add the missing release file before publishing.');
            }
        }
    }

    /** @param array<int,array<string,mixed>> $findings @param array<int,array<string,mixed>> $checks */
    private function checkReleaseHygiene(array &$findings, array &$checks): void
    {
        $repoMode = is_dir($this->rootDir . '/.git');
        $vendorPresent = is_dir($this->rootDir . '/vendor');
        $vendorTracked = $repoMode ? $this->gitTracksPath('vendor') : $vendorPresent;
        $checks[] = ['id' => 'no_vendor_folder', 'ok' => !$vendorTracked, 'message' => $repoMode ? 'vendor/ may exist locally after composer install, but must not be tracked by Git.' : 'vendor/ should not be shipped in a Composer package release.'];
        if ($vendorTracked) {
            $findings[] = $this->finding('vendor_committed', 'medium', 'release', 'vendor folder is present in the release surface', 'Composer dependencies should be installed by users, not shipped in the source package.', 'vendor', null, 'Remove vendor/ from Git/release archives and keep dependencies in composer.json.');
        }

        $markdownFiles = $this->globRelative('*.md');
        $checks[] = ['id' => 'single_markdown_doc', 'ok' => $markdownFiles === ['README.md'], 'message' => 'Package should keep README.md as the single markdown documentation file.', 'markdown_files' => $markdownFiles];
        if ($markdownFiles !== ['README.md']) {
            $findings[] = $this->finding('extra_markdown_docs', 'low', 'documentation', 'Extra markdown documentation files found', 'User-facing release policy says only README.md should exist.', null, null, 'Move extra markdown content into README.md or non-markdown docs if needed.');
        }

        foreach (['.env', '.env.local', '.env.production', 'composer.lock'] as $file) {
            $presentInReleaseSurface = $repoMode ? $this->gitTracksPath($file) : is_file($this->rootDir . '/' . $file);
            if ($presentInReleaseSurface) {
                $severity = str_starts_with($file, '.env') ? 'high' : 'low';
                $findings[] = $this->finding('release_file_' . str_replace('.', '_', $file), $severity, 'release', 'Local/release-sensitive file present in release surface', $file . ' is present in Git or the package archive.', $file, null, 'Remove local-only files from Git/release archives.');
            }
        }

        if ($repoMode) {
            $storageFiles = array_values(array_filter($this->gitTrackedPaths('storage'), static fn (string $path): bool => $path !== 'storage/.gitkeep'));
        } else {
            $storageFiles = [];
            $storageDir = $this->rootDir . '/storage';
            if (is_dir($storageDir)) {
                foreach ($this->files($storageDir) as $file) {
                    $rel = $this->relative($file->getPathname());
                    if (!str_ends_with($rel, '.gitkeep')) {
                        $storageFiles[] = $rel;
                    }
                }
            }
        }

        $checks[] = ['id' => 'no_generated_storage_outputs', 'ok' => count($storageFiles) === 0, 'message' => $repoMode ? 'Generated storage outputs should not be tracked by Git.' : 'Generated storage outputs should not be included in release archives.', 'files' => $storageFiles];
        foreach (array_slice($storageFiles, 0, 20) as $file) {
            $findings[] = $this->finding('generated_storage_output', 'medium', 'release', 'Generated storage file present in release surface', 'Generated crawl/session/queue output is included in Git or the package archive.', $file, null, 'Remove generated files and keep only .gitkeep placeholders.');
        }
    }

    private function gitTracksPath(string $path): bool
    {
        return $this->gitTrackedPaths($path) !== [];
    }

    /** @return list<string> */
    private function gitTrackedPaths(string $path): array
    {
        if (!is_dir($this->rootDir . '/.git')) {
            return [];
        }

        $command = 'git -C ' . escapeshellarg($this->rootDir) . ' ls-files -- ' . escapeshellarg($path) . ' 2>' . $this->nullDevice();
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $output), static fn (string $line): bool => $line !== ''));
    }

    private function nullDevice(): string
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    }

    /** @param array<int,array<string,mixed>> $findings @param array<int,array<string,mixed>> $checks */
    private function checkReadableJsonConfigs(array &$findings, array &$checks): void
    {
        $jsonFiles = [];
        foreach (['config', 'plugins'] as $dirName) {
            $dir = $this->rootDir . '/' . $dirName;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->files($dir) as $file) {
                if (strtolower($file->getExtension()) === 'json') {
                    $jsonFiles[] = $file->getPathname();
                }
            }
        }
        $invalid = [];
        foreach ($jsonFiles as $file) {
            json_decode((string) file_get_contents($file), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalid[] = $this->relative($file) . ': ' . json_last_error_msg();
                $findings[] = $this->finding('invalid_json_config', 'high', 'configuration', 'Invalid JSON configuration', json_last_error_msg(), $this->relative($file), null, 'Fix invalid JSON before publishing.');
            }
        }
        $checks[] = ['id' => 'json_configs_valid', 'ok' => $invalid === [], 'message' => 'Config/plugin JSON files should parse cleanly.', 'files_checked' => count($jsonFiles), 'invalid' => $invalid];
    }

    /** @param array<int,array<string,mixed>> $findings @param array<int,array<string,mixed>> $checks */
    private function checkBrowserSessions(array &$findings, array &$checks): void
    {
        $profilesDir = $this->rootDir . '/config/browser-profiles';
        $profiles = [];
        if (is_dir($profilesDir)) {
            foreach ($this->files($profilesDir) as $file) {
                if (strtolower($file->getExtension()) !== 'json') {
                    continue;
                }
                $data = json_decode((string) file_get_contents($file->getPathname()), true);
                if (!is_array($data)) {
                    continue;
                }
                $profiles[] = $this->relative($file->getPathname());
                $domains = (array) ($data['allowed_domains'] ?? []);
                if ($domains === [] || in_array('*', $domains, true)) {
                    $findings[] = $this->finding('browser_session_missing_domain_guard', 'high', 'browser_session', 'Browser session missing allowed-domain guard', 'Authorized browser sessions must be tied to specific allowed domains.', $this->relative($file->getPathname()), null, 'Set explicit allowed_domains for every browser session profile.');
                }
                foreach (['username', 'password', 'pass', 'secret'] as $key) {
                    if (array_key_exists($key, $data)) {
                        $findings[] = $this->finding('browser_session_secret_field', 'high', 'browser_session', 'Browser session profile contains a secret-like field', 'Session profiles should not store credentials.', $this->relative($file->getPathname()), null, 'Use manual login assist and environment variables instead of storing passwords.');
                    }
                }
            }
        }
        $checks[] = ['id' => 'browser_sessions_have_domain_guards', 'ok' => true, 'message' => 'Browser session profiles were checked for allowed-domain and credential hygiene.', 'profiles_checked' => count($profiles)];
    }

    /** @param array<int,array<string,mixed>> $findings @param array<int,array<string,mixed>> $checks */
    private function checkWebhookAndExportConnectorConfigs(array &$findings, array &$checks): void
    {
        foreach (['config/webhooks.json', 'config/export-connectors.json'] as $relative) {
            $path = $this->rootDir . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            $json = json_encode($data, JSON_UNESCAPED_SLASHES) ?: '';
            if (str_contains($json, 'http://')) {
                $findings[] = $this->finding('insecure_http_endpoint', 'medium', 'configuration', 'Insecure HTTP endpoint configured', 'Webhook/export connector configuration includes an http:// endpoint.', $relative, null, 'Use HTTPS endpoints for webhook/export delivery unless it is strictly local testing.');
            }
            if (preg_match('/"send"\s*:\s*true/i', $json) === 1) {
                $findings[] = $this->finding('send_enabled_in_config', 'medium', 'configuration', 'Network send behavior appears enabled in config', 'Delivery should stay dry-run by default and require explicit --send.', $relative, null, 'Keep send=false in committed examples and enable sends only in local private config.');
            }
        }
        $checks[] = ['id' => 'network_delivery_configs_reviewed', 'ok' => true, 'message' => 'Webhook/export connector configs were reviewed for HTTP and send defaults.'];
    }

    /** @param array<int,array<string,mixed>> $findings @param array<int,array<string,mixed>> $checks */
    private function checkPluginSafety(array &$findings, array &$checks): void
    {
        $pluginPhpFiles = [];
        foreach (['plugins', 'storage/plugins'] as $dirName) {
            $dir = $this->rootDir . '/' . $dirName;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->files($dir) as $file) {
                if (strtolower($file->getExtension()) === 'php') {
                    $pluginPhpFiles[] = $this->relative($file->getPathname());
                }
            }
        }
        foreach ($pluginPhpFiles as $file) {
            $findings[] = $this->finding('plugin_php_code_present', 'medium', 'plugin', 'Plugin PHP code file found', 'The public plugin system is intended to be config/profile/rule based by default.', $file, null, 'Avoid auto-executed plugin PHP code or gate it behind a signed/explicit trust model.');
        }
        $checks[] = ['id' => 'plugins_config_only', 'ok' => count($pluginPhpFiles) === 0, 'message' => 'Plugins should remain safe config-only add-ons by default.', 'php_files' => $pluginPhpFiles];
    }

    /** @param array<int,array<string,mixed>> $findings @param array<int,array<string,mixed>> $checks */
    private function checkPublicSurface(array &$findings, array &$checks): void
    {
        $publicFiles = [];
        foreach (['public/api-router.php', 'public/dashboard.php'] as $file) {
            $exists = is_file($this->rootDir . '/' . $file);
            $publicFiles[] = ['file' => $file, 'exists' => $exists];
        }
        $checks[] = ['id' => 'public_surface_identified', 'ok' => true, 'message' => 'Optional API/dashboard public files identified. Protect with local network binding and tokens when serving.', 'files' => $publicFiles];
    }

    /** @return array<string,int> */
    private function summarize(array $findings): array
    {
        $summary = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0, 'total' => count($findings)];
        foreach ($findings as $finding) {
            $severity = strtolower((string) ($finding['severity'] ?? 'info'));
            if (!array_key_exists($severity, $summary)) {
                $severity = 'info';
            }
            $summary[$severity]++;
        }
        return $summary;
    }

    /** @param array<string,int> $summary */
    private function score(array $summary): int
    {
        $score = 100;
        $score -= ($summary['critical'] ?? 0) * 30;
        $score -= ($summary['high'] ?? 0) * 18;
        $score -= ($summary['medium'] ?? 0) * 8;
        $score -= ($summary['low'] ?? 0) * 2;
        return max(0, $score);
    }

    /** @param array<string,int> $summary @return list<string> */
    private function recommendations(array $summary): array
    {
        $out = [
            'Run security:audit before every tagged release.',
            'Keep secrets in environment variables or local ignored files, not in the package.',
            'Keep browser-session crawling limited to authorized domains and manual login workflows.',
        ];
        if (($summary['critical'] ?? 0) > 0 || ($summary['high'] ?? 0) > 0) {
            array_unshift($out, 'Fix critical/high findings before publishing the package.');
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function finding(string $id, string $severity, string $category, string $title, string $message, ?string $path = null, ?int $line = null, string $recommendation = ''): array
    {
        return (new SecurityFinding($id, $severity, $category, $title, $message, $path, $line, $recommendation))->toArray();
    }

    /** @return list<string> */
    private function globRelative(string $pattern): array
    {
        $files = glob($this->rootDir . '/' . $pattern) ?: [];
        $out = array_map(fn(string $path): string => $this->relative($path), $files);
        sort($out);
        return $out;
    }

    /** @return iterable<SplFileInfo> */
    private function files(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return [];
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }

    private function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', realpath($this->rootDir) ?: $this->rootDir), '/') . '/';
        $real = str_replace('\\', '/', realpath($path) ?: $path);
        return str_starts_with($real, $root) ? substr($real, strlen($root)) : $path;
    }
}
