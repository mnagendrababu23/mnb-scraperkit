<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Mail;

final class MailMessageExtractor
{
    public const VERSION = '1.0.2';

    /** @return list<array<string,mixed>> */
    public function loadMessages(string $input): array
    {
        if (!is_file($input)) {
            throw new \InvalidArgumentException('Mail input file not found: ' . $input);
        }
        $raw = (string) file_get_contents($input);
        $ext = strtolower(pathinfo($input, PATHINFO_EXTENSION));
        if ($ext === 'eml' || str_starts_with(ltrim($raw), 'From:') || preg_match('/^Subject:/mi', $raw) === 1) {
            return [$this->parseEml($raw, $input)];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \InvalidArgumentException('Mail input must be JSON export or .eml file.');
        }
        $messages = $json['messages'] ?? $json['items'] ?? $json;
        if (!is_array($messages)) {
            return [];
        }
        $out = [];
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $out[] = $this->normalizeMessage($message, (int) $index);
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $messages @return list<array<string,mixed>> */
    public function search(array $messages, string $query = '', int $limit = 25): array
    {
        $query = trim($this->lower($query));
        $results = [];
        foreach ($messages as $message) {
            $haystack = $this->lower(implode(' ', [
                (string) ($message['subject'] ?? ''),
                (string) ($message['from'] ?? ''),
                (string) ($message['to'] ?? ''),
                (string) ($message['body_text'] ?? ''),
                strip_tags((string) ($message['body_html'] ?? '')),
            ]));
            if ($query !== '' && !str_contains($haystack, $query)) {
                continue;
            }
            $results[] = $this->messageSummary($message);
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }

    /** @param list<array<string,mixed>> $messages @param array<string,mixed> $options @return array<string,mixed> */
    public function extract(array $messages, array $options = []): array
    {
        $types = $this->types((string) ($options['extract'] ?? $options['type'] ?? 'links,pdfs,text,attachments'));
        $limit = max(1, (int) ($options['limit'] ?? 100));
        $query = trim((string) ($options['query'] ?? ''));
        $messages = $this->filterMessages($messages, $query, $limit);

        $rows = [];
        $allLinks = [];
        $allPdfs = [];
        $allAttachments = [];
        foreach ($messages as $message) {
            $text = $this->plainText($message);
            $html = (string) ($message['body_html'] ?? '');
            $links = $this->extractLinks($text . "\n" . $html);
            $pdfs = array_values(array_filter($links, static fn(string $url): bool => str_contains(strtolower(parse_url($url, PHP_URL_PATH) ?: $url), '.pdf')));
            $attachments = $this->normalizeAttachments((array) ($message['attachments'] ?? []));
            foreach ($attachments as $attachment) {
                if (str_ends_with(strtolower((string) ($attachment['filename'] ?? '')), '.pdf')) {
                    $pdfs[] = (string) ($attachment['filename'] ?? '');
                }
            }

            $row = $this->messageSummary($message);
            if (in_array('text', $types, true) || in_array('plain_text', $types, true)) {
                $row['text'] = $text;
            }
            if (in_array('html', $types, true) || in_array('inner_html', $types, true)) {
                $row['html'] = $html;
            }
            if (in_array('links', $types, true)) {
                $row['links'] = $links;
            }
            if (in_array('pdfs', $types, true) || in_array('pdf', $types, true)) {
                $row['pdfs'] = array_values(array_unique($pdfs));
            }
            if (in_array('attachments', $types, true)) {
                $row['attachments'] = $attachments;
            }
            $rows[] = $row;
            $allLinks = array_merge($allLinks, $links);
            $allPdfs = array_merge($allPdfs, $pdfs);
            $allAttachments = array_merge($allAttachments, $attachments);
        }

        return [
            'mail_extraction_version' => self::VERSION,
            'provider' => (string) ($options['provider'] ?? 'local_json'),
            'authorization' => [
                'authorized_source_required' => true,
                'credentials_stored' => false,
                'raw_passwords_supported' => false,
            ],
            'query' => $query,
            'extract_types' => $types,
            'messages_total' => count($messages),
            'links_total' => count(array_unique($allLinks)),
            'pdfs_total' => count(array_unique($allPdfs)),
            'attachments_total' => count($allAttachments),
            'links' => array_values(array_unique($allLinks)),
            'pdfs' => array_values(array_unique($allPdfs)),
            'messages' => $rows,
        ];
    }

    /** @param list<array<string,mixed>> $messages @return array<string,mixed> */
    public function attachmentManifest(array $messages, string $outputDir, bool $writeContent = false): array
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }
        $files = [];
        foreach ($messages as $message) {
            foreach ($this->normalizeAttachments((array) ($message['attachments'] ?? [])) as $attachment) {
                $entry = $attachment;
                $entry['message_id'] = (string) ($message['message_id'] ?? '');
                $entry['subject'] = (string) ($message['subject'] ?? '');
                if ($writeContent && !empty($attachment['content_base64']) && !empty($attachment['filename'])) {
                    $path = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . basename((string) $attachment['filename']);
                    file_put_contents($path, base64_decode((string) $attachment['content_base64'], true) ?: '');
                    $entry['saved_path'] = $path;
                }
                unset($entry['content_base64']);
                $files[] = $entry;
            }
        }
        $manifest = [
            'mail_attachment_manifest_version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'output_dir' => $outputDir,
            'files_total' => count($files),
            'files' => $files,
        ];
        file_put_contents(rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . 'mail-attachments-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $manifest;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** @param array<string,mixed> $message @return array<string,mixed> */
    private function normalizeMessage(array $message, int $index): array
    {
        return [
            'message_id' => (string) ($message['message_id'] ?? $message['id'] ?? 'msg-' . ($index + 1)),
            'thread_id' => (string) ($message['thread_id'] ?? ''),
            'subject' => (string) ($message['subject'] ?? ''),
            'from' => (string) ($message['from'] ?? $message['sender'] ?? ''),
            'to' => is_array($message['to'] ?? null) ? implode(', ', array_map('strval', $message['to'])) : (string) ($message['to'] ?? ''),
            'date' => (string) ($message['date'] ?? $message['sent_at'] ?? ''),
            'body_text' => (string) ($message['body_text'] ?? $message['text'] ?? $message['snippet'] ?? ''),
            'body_html' => (string) ($message['body_html'] ?? $message['html'] ?? ''),
            'labels' => array_values(array_map('strval', (array) ($message['labels'] ?? []))),
            'attachments' => (array) ($message['attachments'] ?? []),
        ];
    }

    /** @return array<string,mixed> */
    private function parseEml(string $raw, string $source): array
    {
        [$headerRaw, $body] = preg_split("/\R\R/", $raw, 2) + ['', ''];
        $headers = [];
        foreach (preg_split('/\R/', $headerRaw) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m) === 1) {
                $headers[strtolower($m[1])] = trim($m[2]);
            }
        }
        return $this->normalizeMessage([
            'id' => 'eml-' . substr(sha1($source), 0, 10),
            'subject' => $headers['subject'] ?? '',
            'from' => $headers['from'] ?? '',
            'to' => $headers['to'] ?? '',
            'date' => $headers['date'] ?? '',
            'body_text' => strip_tags($body),
            'body_html' => str_contains($body, '<') ? $body : '',
            'attachments' => [],
        ], 0);
    }

    /** @param list<array<string,mixed>> $messages @return list<array<string,mixed>> */
    private function filterMessages(array $messages, string $query, int $limit): array
    {
        if ($query === '') {
            return array_slice($messages, 0, $limit);
        }
        $summaries = $this->search($messages, $query, $limit);
        $ids = array_flip(array_map(static fn(array $row): string => (string) ($row['message_id'] ?? ''), $summaries));
        return array_values(array_filter($messages, static fn(array $message): bool => isset($ids[(string) ($message['message_id'] ?? '')])));
    }

    /** @return list<string> */
    private function types(string $spec): array
    {
        return array_values(array_unique(array_filter(array_map(static fn(string $v): string => trim(strtolower($v)), preg_split('/[,|]/', $spec) ?: []))));
    }

    /** @param array<string,mixed> $message @return array<string,mixed> */
    private function messageSummary(array $message): array
    {
        return [
            'message_id' => (string) ($message['message_id'] ?? ''),
            'thread_id' => (string) ($message['thread_id'] ?? ''),
            'subject' => (string) ($message['subject'] ?? ''),
            'from' => (string) ($message['from'] ?? ''),
            'to' => (string) ($message['to'] ?? ''),
            'date' => (string) ($message['date'] ?? ''),
            'labels' => (array) ($message['labels'] ?? []),
        ];
    }

    /** @param array<string,mixed> $message */
    private function plainText(array $message): string
    {
        $text = (string) ($message['body_text'] ?? '');
        $html = (string) ($message['body_html'] ?? '');
        $combined = trim($text . "\n" . html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim((string) preg_replace('/\s+/', ' ', $combined));
    }

    /** @return list<string> */
    private function extractLinks(string $text): array
    {
        $links = [];
        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $text, $m)) {
            foreach ($m[1] as $url) {
                $links[] = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        if (preg_match_all('#https?://[^\s<"\')]+#i', $text, $m)) {
            foreach ($m[0] as $url) {
                $links[] = rtrim((string) $url, '.,;)');
            }
        }
        return array_values(array_unique(array_filter($links, static fn(string $url): bool => preg_match('#^https?://#i', $url) === 1)));
    }

    /** @param array<int,mixed> $attachments @return list<array<string,mixed>> */
    private function normalizeAttachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $out[] = [
                'filename' => (string) ($attachment['filename'] ?? $attachment['name'] ?? ''),
                'mime_type' => (string) ($attachment['mime_type'] ?? $attachment['content_type'] ?? ''),
                'size_bytes' => (int) ($attachment['size_bytes'] ?? $attachment['size'] ?? 0),
                'attachment_id' => (string) ($attachment['attachment_id'] ?? $attachment['id'] ?? ''),
                'content_base64' => isset($attachment['content_base64']) ? (string) $attachment['content_base64'] : null,
            ];
        }
        return $out;
    }
}
