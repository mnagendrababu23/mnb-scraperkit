<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Mail;

/**
 * Describes authorized mail/webmail extraction connector slots.
 *
 * This registry intentionally does not scrape inboxes or store passwords. Live Gmail/IMAP
 * access must be implemented through OAuth/app-password/secret-managed connectors in the
 * host application. The built-in local_json provider lets tests and enterprises validate
 * extraction workflows from exported/approved mailbox data without credentials.
 */
final class MailProviderRegistry
{
    public const VERSION = '1.0.1';

    /** @return array<string,mixed> */
    public static function summary(?string $configFile = null): array
    {
        $configured = self::configuredProviders($configFile);
        $providers = [
            self::provider('local_json', 'Local authorized JSON/mail export', true, false, true, ['input']),
            self::provider('eml_file', 'Local authorized .eml file import', true, false, true, ['input']),
            self::provider('gmail_api', 'Gmail API OAuth connector slot', self::envConfigured(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GMAIL_OAUTH_TOKEN_FILE']) || in_array('gmail_api', $configured, true), true, false, ['oauth_client_id', 'oauth_client_secret', 'oauth_token_file']),
            self::provider('imap', 'Authorized IMAP connector slot', self::envConfigured(['MNB_MAIL_IMAP_HOST', 'MNB_MAIL_IMAP_USER']) || in_array('imap', $configured, true), true, false, ['host', 'user', 'secret_env']),
            self::provider('webmail_export', 'User-exported webmail archive connector', in_array('webmail_export', $configured, true), false, true, ['export_file']),
        ];

        return [
            'mail_connector_version' => self::VERSION,
            'policy' => [
                'authorization_required' => true,
                'no_password_storage' => true,
                'no_hidden_login' => true,
                'no_webmail_screen_scraping' => true,
                'prefer_provider_api_or_user_export' => true,
                'pii_review_required' => true,
            ],
            'providers' => $providers,
        ];
    }

    /** @param list<string> $keys */
    private static function envConfigured(array $keys): bool
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function configuredProviders(?string $configFile): array
    {
        if (!$configFile || !is_file($configFile)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($configFile), true);
        if (!is_array($json)) {
            return [];
        }
        $providers = [];
        foreach ((array) ($json['providers'] ?? []) as $id => $provider) {
            if (is_array($provider) && !empty($provider['enabled'])) {
                $providers[] = is_string($id) ? $id : (string) ($provider['id'] ?? '');
            }
        }
        return array_values(array_filter($providers));
    }

    /** @param list<string> $requires @return array<string,mixed> */
    private static function provider(string $id, string $name, bool $configured, bool $requiresNetwork, bool $offlineSafe, array $requires): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'configured' => $configured,
            'requires_network' => $requiresNetwork,
            'offline_safe' => $offlineSafe,
            'requires' => $requires,
            'status' => $configured ? 'available_or_import_ready' : 'not_configured',
        ];
    }
}
