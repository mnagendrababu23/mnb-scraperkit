<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

/**
 * File-based browser session profile store.
 */
final class BrowserSessionStore
{
    private string $profilesDir;
    private string $sessionsDir;

    public function __construct(private string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, '/\\');
        $this->profilesDir = $this->rootDir . '/config/browser-profiles';
        $this->sessionsDir = $this->rootDir . '/storage/browser-sessions';
    }

    public function profilesDir(): string
    {
        return $this->profilesDir;
    }

    public function sessionsDir(): string
    {
        return $this->sessionsDir;
    }

    public function create(string $name, array $domains, ?string $loginUrl = null, array $options = []): BrowserSessionProfile
    {
        $profileName = BrowserSessionProfile::normalizeName($name);
        $sessionDir = $this->sessionDir($profileName);
        $this->ensureDir($sessionDir);
        $cookieFile = $options['cookie_file'] ?? ($sessionDir . '/cookies.json');
        $profile = new BrowserSessionProfile(
            name: $profileName,
            allowedDomains: $domains,
            loginUrl: $loginUrl,
            cookieFile: (string) $cookieFile,
            waitSelector: isset($options['wait_selector']) ? (string) $options['wait_selector'] : null,
            browserMode: (string) ($options['browser_mode'] ?? 'auto'),
            timeoutMs: (int) ($options['timeout_ms'] ?? 30000),
            headless: (bool) ($options['headless'] ?? true),
            blockAssets: (bool) ($options['block_assets'] ?? true),
            metadata: ['created_by' => 'mnb-scraperkit', 'workflow' => 'authorized_browser_session'],
        );
        $this->save($profile);
        if (!is_file($profile->cookieFile ?? '')) {
            file_put_contents((string) $profile->cookieFile, json_encode(['cookies' => [], 'created_at' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        return $profile;
    }

    public function save(BrowserSessionProfile $profile): void
    {
        $this->ensureDir($this->profilesDir);
        $profile->updatedAt = date(DATE_ATOM);
        $path = $this->profilePath($profile->name);
        file_put_contents($path, json_encode($profile->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function load(string $name): BrowserSessionProfile
    {
        $path = $this->profilePath(BrowserSessionProfile::normalizeName($name));
        if (!is_file($path)) {
            throw new \RuntimeException('Browser session profile not found: ' . $name);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid browser session profile JSON: ' . $path);
        }
        return BrowserSessionProfile::fromArray($data);
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = [];
        foreach (glob($this->profilesDir . '/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            $profile = BrowserSessionProfile::fromArray($data);
            $rows[] = [
                'name' => $profile->name,
                'allowed_domains' => $profile->allowedDomains,
                'login_url' => $profile->loginUrl,
                'cookie_file' => $profile->cookieFile,
                'cookie_file_exists' => $profile->cookieFile ? is_file($profile->cookieFile) : false,
                'browser_mode' => $profile->browserMode,
                'updated_at' => $profile->updatedAt,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        return $rows;
    }

    public function clear(string $name, bool $removeProfile = false): array
    {
        $profile = $this->load($name);
        $removed = [];
        foreach ([$profile->cookieFile, $this->sessionDir($profile->name) . '/login-instructions.txt', $this->sessionDir($profile->name) . '/session-test.json'] as $path) {
            if ($path && is_file($path)) {
                unlink($path);
                $removed[] = $path;
            }
        }
        if ($removeProfile && is_file($this->profilePath($profile->name))) {
            unlink($this->profilePath($profile->name));
            $removed[] = $this->profilePath($profile->name);
        }
        return ['session' => $profile->name, 'removed' => $removed, 'profile_removed' => $removeProfile];
    }

    public function sessionDir(string $name): string
    {
        return $this->sessionsDir . '/' . BrowserSessionProfile::normalizeName($name);
    }

    public function profilePath(string $name): string
    {
        return $this->profilesDir . '/' . BrowserSessionProfile::normalizeName($name) . '.json';
    }

    public function assertUrlAllowed(BrowserSessionProfile $profile, string $url): void
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('~/(logout|log-out|signout|sign-out)(/|$|\?)~', $path) === 1) {
            throw new \RuntimeException('Browser session refused logout/signout URL for safety: ' . $url);
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new \RuntimeException('Session URL host is empty: ' . $url);
        }
        if ($profile->allowedDomains === []) {
            throw new \RuntimeException('Browser session has no allowed domains. Add --domain when creating the session.');
        }
        foreach ($profile->allowedDomains as $domain) {
            $domain = strtolower($domain);
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return;
            }
        }
        throw new \RuntimeException(sprintf('URL host "%s" is outside browser session allowed domains: %s', $host, implode(', ', $profile->allowedDomains)));
    }

    public function writeLoginInstructions(BrowserSessionProfile $profile, string $url): string
    {
        $this->ensureDir($this->sessionDir($profile->name));
        $path = $this->sessionDir($profile->name) . '/login-instructions.txt';
        $text = implode(PHP_EOL, [
            'MNB ScraperKit authorized browser session login assist',
            'Session: ' . $profile->name,
            'Login URL: ' . $url,
            'Allowed domains: ' . implode(', ', $profile->allowedDomains),
            '',
            'Use this only for websites where you have permission.',
            'Manual login workflow:',
            '1. Open the login URL in your browser/authorized environment.',
            '2. Log in manually. Do not store passwords in ScraperKit config.',
            '3. Export or save cookies to the session cookie file when using an adapter that supports it.',
            '4. Test with: php bin/mnb-scraper browser:session-test ' . $profile->name . ' <authorized-url> --render',
            '',
            'Cookie file: ' . ($profile->cookieFile ?? ''),
        ]) . PHP_EOL;
        file_put_contents($path, $text);
        return $path;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
