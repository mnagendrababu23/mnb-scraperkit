<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser\Adapters;

use Mnb\ScraperKit\Browser\BrowserEngine;
use Mnb\ScraperKit\Browser\BrowserPageResult;
use Mnb\ScraperKit\Browser\BrowserProfile;
use Symfony\Component\Panther\Client;

final class PantherBrowserAdapter implements BrowserEngine
{
    private ?Client $client = null;

    public function open(string $url, BrowserProfile $profile): BrowserPageResult
    {
        if (!class_exists(Client::class)) {
            throw new \RuntimeException('Install symfony/panther and Chrome/Firefox driver to use Panther browser mode.');
        }

        $arguments = [
            '--window-size=' . $profile->windowWidth . ',' . $profile->windowHeight,
            '--disable-gpu',
        ];
        if ($profile->headless) {
            $arguments[] = '--headless=new';
        }

        $started = microtime(true);
        $this->client = Client::createChromeClient(null, $arguments);
        $crawler = $this->client->request('GET', $url);

        if ($profile->waitAfterLoadMs > 0) {
            usleep($profile->waitAfterLoadMs * 1000);
        }

        return new BrowserPageResult(
            url: $url,
            finalUrl: $this->client->getCurrentURL(),
            title: $this->client->getTitle(),
            html: $this->client->getPageSource(),
            text: $crawler->text(null, false),
            loadTimeMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    public function screenshot(string $path): void
    {
        $this->ensureClient();
        $this->client->takeScreenshot($path);
    }

    public function html(): string
    {
        $this->ensureClient();
        return $this->client->getPageSource();
    }

    public function text(): string
    {
        return trim(strip_tags($this->html()));
    }

    public function click(string $selector): void
    {
        $this->ensureClient();
        $this->client->getCrawler()->filter($selector)->click();
    }

    public function type(string $selector, string $value): void
    {
        $this->ensureClient();
        $element = $this->client->getCrawler()->filter($selector)->getElement(0);
        $element->clear();
        $element->sendKeys($value);
    }

    public function waitFor(string $selector, int $timeoutSeconds = 10): void
    {
        $this->ensureClient();
        $this->client->waitFor($selector, $timeoutSeconds);
    }

    public function scrollToBottom(): void
    {
        $this->ensureClient();
        $this->client->executeScript('window.scrollTo(0, document.body.scrollHeight);');
    }

    public function close(): void
    {
        if ($this->client) {
            $this->client->quit();
            $this->client = null;
        }
    }

    private function ensureClient(): void
    {
        if (!$this->client) {
            throw new \RuntimeException('Browser session is not open.');
        }
    }
}
