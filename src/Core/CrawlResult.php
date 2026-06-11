<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

final class CrawlResult
{
    /** @var array<int,PageResult> */
    private array $pages = [];
    private int $startedAt;
    private ?int $finishedAt = null;

    public function __construct(public readonly string $startUrl)
    {
        $this->startedAt = time();
    }

    public function addPage(PageResult $page): void
    {
        $this->pages[] = $page;
    }

    /** @return array<int,PageResult> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function finish(): void
    {
        $this->finishedAt = time();
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $challenge = 0;
        foreach ($this->pages as $page) {
            if (($page->protection['is_challenge'] ?? false) === true) {
                $challenge++;
            }
            if ($page->skipped) {
                $skipped++;
            } elseif ($page->error || ($page->statusCode !== null && $page->statusCode >= 400)) {
                $failed++;
            } else {
                $ok++;
            }
        }

        return [
            'start_url' => $this->startUrl,
            'pages_total' => count($this->pages),
            'pages_ok' => $ok,
            'pages_failed' => $failed,
            'pages_skipped' => $skipped,
            'pages_challenge' => $challenge,
            'duration_seconds' => ($this->finishedAt ?? time()) - $this->startedAt,
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(bool $includeHtml = false): array
    {
        return [
            'summary' => $this->summary(),
            'pages' => array_map(fn (PageResult $page): array => $page->toArray($includeHtml), $this->pages),
        ];
    }
}
