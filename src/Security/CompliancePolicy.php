<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Security;

final class CompliancePolicy
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'policy_version' => '4.0.0',
            'name' => 'MNB ScraperKit Responsible Crawling Policy',
            'responsible_crawling' => [
                'respect_robots_when_enabled' => true,
                'require_allowed_domains_for_browser_sessions' => true,
                'block_private_networks_by_default' => true,
                'avoid_captcha_or_stealth_bypass' => true,
                'manual_login_only_for_authorized_workflows' => true,
                'do_not_store_passwords_by_default' => true,
            ],
            'release_hygiene' => [
                'do_not_commit_vendor' => true,
                'do_not_commit_generated_storage_outputs' => true,
                'do_not_commit_local_env_files' => true,
                'single_readme_documentation' => true,
            ],
            'secrets' => [
                'use_environment_variables' => true,
                'scan_before_release' => true,
                'rotate_any_accidentally_committed_secret' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function load(?string $path): array
    {
        if ($path && is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                return array_replace_recursive(self::defaults(), $data);
            }
        }
        return self::defaults();
    }

    public static function writeExample(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode(self::defaults(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
