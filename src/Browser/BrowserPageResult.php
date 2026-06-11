<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

final class BrowserPageResult
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $url,
        public ?string $finalUrl = null,
        public ?string $title = null,
        public string $html = '',
        public string $text = '',
        public ?string $screenshotPath = null,
        public ?string $error = null,
        public int $loadTimeMs = 0,
        public string $engine = 'browser',
        public array $metadata = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(bool $includeHtml = true): array
    {
        $data = [
            'url' => $this->url,
            'final_url' => $this->finalUrl,
            'title' => $this->title,
            'text_length' => function_exists('mb_strlen') ? mb_strlen($this->text) : strlen($this->text),
            'screenshot_path' => $this->screenshotPath,
            'error' => $this->error,
            'load_time_ms' => $this->loadTimeMs,
            'engine' => $this->engine,
            'metadata' => $this->metadata,
        ];
        if ($includeHtml) {
            $data['html'] = $this->html;
            $data['text'] = $this->text;
        }
        return $data;
    }
}
