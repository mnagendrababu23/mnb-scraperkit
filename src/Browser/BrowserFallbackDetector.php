<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

use Mnb\ScraperKit\Core\PageResult;

final class BrowserFallbackDetector
{
    /**
     * Lightweight HTML-only browser fallback analysis used by AI/site analysis flows.
     *
     * This keeps the older assessPage(PageResult, BrowserOptions) API intact while giving
     * non-crawler callers a safe way to ask whether a saved/public HTML document looks
     * like it needs browser rendering.
     *
     * @param array<string,mixed> $extracted
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function analyze(string $html, array $extracted = [], array $options = []): array
    {
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $browserOptions = BrowserOptions::fromArray($options + [
            'mode' => 'auto',
            'fallback_min_text' => $options['fallback_min_text'] ?? $options['fallback_min_text_length'] ?? 300,
        ]);

        $page = new PageResult(
            url: (string) ($options['url'] ?? 'about:blank'),
            statusCode: isset($options['status_code']) ? (int) $options['status_code'] : 200,
            html: $html,
            text: $text,
            extracted: $extracted,
            protection: is_array($options['protection'] ?? null) ? $options['protection'] : []
        );

        $assessment = $this->assessPage($page, $browserOptions);
        $reasons = (array) ($assessment['reasons'] ?? []);

        return [
            'requires_browser' => $reasons !== [],
            'should_use_browser' => (bool) ($assessment['should_use_browser'] ?? false),
            'mode' => $assessment['mode'] ?? $browserOptions->mode,
            'reasons' => array_values(array_unique(array_map('strval', $reasons))),
            'text_length' => (int) ($assessment['text_length'] ?? strlen($text)),
            'status_code' => $assessment['status_code'] ?? $page->statusCode,
        ];
    }

    /** @return array<string,mixed> */
    public function assessPage(PageResult $page, BrowserOptions $options): array
    {
        $reasons = [];
        $html = (string) ($page->html ?? '');
        $text = trim((string) ($page->text ?? ''));
        $textLength = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        if (($page->protection['browser_required'] ?? false) === true || ($page->protection['is_challenge'] ?? false) === true) {
            $reasons[] = 'challenge_or_browser_required';
        }
        if ($textLength < $options->fallbackMinTextLength && $page->statusCode !== null && $page->statusCode < 400) {
            $reasons[] = 'low_text_length';
        }
        if ($html !== '' && $this->looksLikeJavascriptApp($html)) {
            $reasons[] = 'javascript_app_markers';
        }
        if ($html !== '' && preg_match('/enable javascript|requires javascript|please turn on javascript/i', $html) === 1) {
            $reasons[] = 'javascript_required_message';
        }
        if ($options->requiredFields !== []) {
            $missing = $this->missingRequiredFields($page, $options->requiredFields);
            if ($missing !== []) {
                $reasons[] = 'missing_required_fields:' . implode(',', $missing);
            }
        }

        return [
            'should_use_browser' => $options->mode === 'always' || ($options->mode === 'auto' && $reasons !== []),
            'mode' => $options->mode,
            'reasons' => array_values(array_unique($reasons)),
            'text_length' => $textLength,
            'status_code' => $page->statusCode,
        ];
    }

    public function looksLikeJavascriptApp(string $html): bool
    {
        return preg_match('/(__NEXT_DATA__|id=["\'](?:app|root)["\']|data-reactroot|ng-version|window\.__NUXT__|<app-root|vite|webpackJsonp)/i', $html) === 1;
    }

    /** @param list<string> $fields @return list<string> */
    private function missingRequiredFields(PageResult $page, array $fields): array
    {
        $missing = [];
        $flat = $this->flatten($page->extracted);
        foreach ($fields as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            $value = $flat[$field] ?? null;
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value) && !array_is_list($value)) {
                $out += $this->flatten($value, $name);
            } else {
                $out[$name] = $value;
                $out[(string) $key] = $value;
            }
        }
        return $out;
    }
}
