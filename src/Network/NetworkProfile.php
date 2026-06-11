<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Network;

final class NetworkProfile
{
    public function __construct(
        public string $name,
        public string $type = 'direct',
        public bool $enabled = true,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $countryCode = null,
        public int $maxRequestsPerMinute = 60,
        public int $cooldownSeconds = 60,
        public ?string $connectCommand = null,
        public ?string $disconnectCommand = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            type: (string) ($data['type'] ?? 'direct'),
            enabled: (bool) ($data['enabled'] ?? true),
            host: isset($data['host']) ? (string) $data['host'] : null,
            port: isset($data['port']) ? (int) $data['port'] : null,
            username: isset($data['username']) ? (string) $data['username'] : null,
            password: isset($data['password']) ? (string) $data['password'] : null,
            countryCode: isset($data['country_code']) ? (string) $data['country_code'] : null,
            maxRequestsPerMinute: (int) ($data['max_requests_per_minute'] ?? 60),
            cooldownSeconds: (int) ($data['cooldown_seconds'] ?? 60),
            connectCommand: isset($data['connect_command']) ? (string) $data['connect_command'] : null,
            disconnectCommand: isset($data['disconnect_command']) ? (string) $data['disconnect_command'] : null,
        );
    }

    public function isProxy(): bool
    {
        return in_array($this->type, ['http_proxy', 'https_proxy', 'socks5'], true);
    }

    public function isVpn(): bool
    {
        return in_array($this->type, ['openvpn', 'wireguard', 'corporate_vpn', 'windows_vpn'], true);
    }

    public function proxyAddress(): string
    {
        if (!$this->host || !$this->port) {
            throw new \RuntimeException('Proxy host and port are required.');
        }
        return $this->host . ':' . $this->port;
    }
}
