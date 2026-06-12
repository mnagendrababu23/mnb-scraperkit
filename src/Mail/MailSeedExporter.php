<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Mail;

final class MailSeedExporter
{
    public const VERSION = '1.0.1';

    /** @param array<string,mixed> $extraction @return list<array<string,mixed>> */
    public function seeds(array $extraction, ?string $domain = null): array
    {
        $rows = [];
        $seen = [];
        $messages = is_array($extraction['messages'] ?? null) ? $extraction['messages'] : [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            foreach ((array) ($message['links'] ?? []) as $url) {
                $url = (string) $url;
                if (!$this->allowed($url, $domain) || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $rows[] = [
                    'url' => $url,
                    'source' => 'mail',
                    'message_id' => (string) ($message['message_id'] ?? ''),
                    'subject' => (string) ($message['subject'] ?? ''),
                    'classification' => $this->classify($url),
                ];
            }
        }
        return $rows;
    }

    private function allowed(string $url, ?string $domain): bool
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }
        if (!$domain) {
            return true;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = strtolower(ltrim($domain, '.'));
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private function classify(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (str_contains($path, '.pdf')) {
            return 'pdf_document';
        }
        if (str_contains($path, '/article/') || str_contains($path, 'doi')) {
            return 'article_seed';
        }
        if (str_contains($path, '/journal/')) {
            return 'journal_seed';
        }
        return 'web_seed';
    }
}
