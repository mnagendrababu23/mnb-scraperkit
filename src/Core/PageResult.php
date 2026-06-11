<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class PageResult
{
    /** @param array<int,string> $links @param array<string,mixed> $meta @param array<string,mixed> $extracted */
    public function __construct(
        public string $url,
        public ?string $finalUrl = null,
        public ?int $statusCode = null,
        public ?string $title = null,
        public ?string $html = null,
        public ?string $text = null,
        public array $links = [],
        public array $meta = [],
        public array $extracted = [],
        public ?string $contentHash = null,
        public ?string $error = null,
        public int $depth = 0,
        public int $responseTimeMs = 0,
        public ?string $detectedEncoding = null,
        public array $robots = [],
        public bool $skipped = false,
        public ?string $skipReason = null,
        public ?string $failureType = null,
        public ?string $rawFinalUrl = null,
        public int $redirectCount = 0,
        public array $protection = [],
        public ?string $httpEngine = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(bool $includeHtml = false): array
    {
        $data = [
            'url' => $this->url,
            'final_url' => $this->finalUrl,
            'raw_final_url' => $this->rawFinalUrl,
            'status_code' => $this->statusCode,
            'title' => $this->title,
            'text_length' => $this->text ? mb_strlen($this->text) : 0,
            'links_count' => count($this->links),
            'meta' => $this->meta,
            'extracted' => $this->extracted,
            'content_hash' => $this->contentHash,
            'error' => $this->error,
            'failure_type' => $this->failureType,
            'depth' => $this->depth,
            'response_time_ms' => $this->responseTimeMs,
            'redirect_count' => $this->redirectCount,
            'http_engine' => $this->httpEngine,
            'protection' => $this->protection,
            'detected_encoding' => $this->detectedEncoding,
            'robots' => $this->robots,
            'skipped' => $this->skipped,
            'skip_reason' => $this->skipReason,
        ];

        if ($includeHtml) {
            $data['html'] = $this->html;
            $data['text'] = $this->text;
        }

        return $data;
    }
}
