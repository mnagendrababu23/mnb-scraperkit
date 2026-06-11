<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

/**
 * Runtime options for optional browser-assisted crawling.
 *
 * Browser mode is intentionally separate from CrawlOptions so the default PHP
 * HTTP crawler stays lightweight and browser dependencies remain optional.
 */
final class BrowserOptions
{
    /** @param list<string> $requiredFields */
    public function __construct(
        public string $mode = 'off', // off | auto | always
        public string $profile = 'chrome_headless',
        public string $engine = 'panther',
        public ?string $waitSelector = null,
        public string $waitUntil = 'load',
        public int $timeoutMs = 30000,
        public int $waitAfterLoadMs = 1000,
        public int $viewportWidth = 1366,
        public int $viewportHeight = 768,
        public bool $headless = true,
        public bool $screenshot = false,
        public bool $saveRenderedHtml = false,
        public bool $blockAssets = true,
        public ?string $outputDir = null,
        public int $fallbackMinTextLength = 300,
        public array $requiredFields = [],
        public ?string $sessionName = null,
        public ?string $cookieFile = null,
        public array $allowedDomains = [],
    ) {
        $this->mode = self::normalizeMode($this->mode);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $browser = $data['browser'] ?? $data['browser_mode'] ?? $data['mode'] ?? 'off';
        $mode = self::normalizeMode($browser);
        if (isset($data['force_browser']) && filter_var($data['force_browser'], FILTER_VALIDATE_BOOLEAN)) {
            $mode = 'always';
        }
        if (isset($data['no_browser_fallback']) && filter_var($data['no_browser_fallback'], FILTER_VALIDATE_BOOLEAN)) {
            $mode = 'off';
        }

        return new self(
            mode: $mode,
            profile: (string) ($data['browser_profile'] ?? $data['profile'] ?? 'chrome_headless'),
            engine: strtolower((string) ($data['browser_engine'] ?? $data['engine'] ?? 'panther')),
            waitSelector: self::nullableString($data['wait_selector'] ?? $data['wait-selector'] ?? null),
            waitUntil: (string) ($data['wait_until'] ?? $data['wait-until'] ?? 'load'),
            timeoutMs: max(1000, (int) ($data['browser_timeout_ms'] ?? $data['browser-timeout-ms'] ?? $data['timeout_ms'] ?? 30000)),
            waitAfterLoadMs: max(0, (int) ($data['wait_after_load_ms'] ?? $data['wait-ms'] ?? $data['wait_ms'] ?? 1000)),
            viewportWidth: max(320, (int) ($data['viewport_width'] ?? $data['viewport-width'] ?? 1366)),
            viewportHeight: max(240, (int) ($data['viewport_height'] ?? $data['viewport-height'] ?? 768)),
            headless: self::bool($data['headless'] ?? true),
            screenshot: self::bool($data['screenshot'] ?? false),
            saveRenderedHtml: self::bool($data['rendered_html'] ?? $data['rendered-html'] ?? $data['save_rendered_html'] ?? false),
            blockAssets: self::bool($data['block_assets'] ?? $data['block-assets'] ?? true),
            outputDir: self::nullableString($data['browser_output_dir'] ?? $data['browser-output-dir'] ?? $data['output_dir'] ?? null),
            fallbackMinTextLength: max(0, (int) ($data['fallback_min_text'] ?? $data['fallback-min-text'] ?? 300)),
            requiredFields: self::stringList($data['fallback_required_field'] ?? $data['fallback-required-field'] ?? []),
            sessionName: self::nullableString($data['session'] ?? $data['browser_session'] ?? $data['browser-session'] ?? null),
            cookieFile: self::nullableString($data['cookie_file'] ?? $data['cookie-file'] ?? null),
            allowedDomains: self::stringList($data['allowed_domains'] ?? $data['domain'] ?? $data['domains'] ?? []),
        );
    }

    public static function normalizeMode(mixed $value): string
    {
        if ($value === true || $value === '') {
            return 'auto';
        }
        if ($value === false || $value === null) {
            return 'off';
        }
        $value = strtolower(trim((string) $value));
        return match ($value) {
            '1', 'true', 'yes', 'on', 'auto', 'fallback', 'browser-auto' => 'auto',
            'always', 'force', 'forced', 'only', 'browser', 'render' => 'always',
            '0', 'false', 'no', 'off', 'none', 'never', 'disabled' => 'off',
            default => 'auto',
        };
    }

    public function enabled(): bool
    {
        return $this->mode !== 'off';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'profile' => $this->profile,
            'engine' => $this->engine,
            'wait_selector' => $this->waitSelector,
            'wait_until' => $this->waitUntil,
            'timeout_ms' => $this->timeoutMs,
            'wait_after_load_ms' => $this->waitAfterLoadMs,
            'viewport_width' => $this->viewportWidth,
            'viewport_height' => $this->viewportHeight,
            'headless' => $this->headless,
            'screenshot' => $this->screenshot,
            'save_rendered_html' => $this->saveRenderedHtml,
            'block_assets' => $this->blockAssets,
            'output_dir' => $this->outputDir,
            'fallback_min_text_length' => $this->fallbackMinTextLength,
            'required_fields' => $this->requiredFields,
            'session' => $this->sessionName,
            'cookie_file' => $this->cookieFile,
            'allowed_domains' => $this->allowedDomains,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        if (is_array($value)) {
            $value = end($value);
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function bool(mixed $value): bool
    {
        if (is_array($value)) {
            $value = end($value);
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on', ''], true);
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if ($value === null || $value === false) {
            return [];
        }
        if (is_string($value)) {
            $value = str_contains($value, ',') ? explode(',', $value) : [$value];
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                foreach (self::stringList($item) as $nested) {
                    $out[] = $nested;
                }
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }
}
