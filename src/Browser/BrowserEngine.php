<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

interface BrowserEngine
{
    public function open(string $url, BrowserProfile $profile): BrowserPageResult;
    public function screenshot(string $path): void;
    public function html(): string;
    public function text(): string;
    public function click(string $selector): void;
    public function type(string $selector, string $value): void;
    public function waitFor(string $selector, int $timeoutSeconds = 10): void;
    public function scrollToBottom(): void;
    public function close(): void;
}
