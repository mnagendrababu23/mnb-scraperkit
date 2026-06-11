<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser\Adapters;

use Mnb\ScraperKit\Browser\BrowserClientInterface;
use Mnb\ScraperKit\Browser\BrowserEngine;
use Mnb\ScraperKit\Browser\BrowserOptions;
use Mnb\ScraperKit\Browser\BrowserPageResult;
use Mnb\ScraperKit\Browser\BrowserProfile;
use Symfony\Component\Panther\Client;

final class PantherBrowserAdapter implements BrowserEngine, BrowserClientInterface
{
    private ?Client $client = null;

    public function isAvailable(): bool
    {
        return class_exists(Client::class);
    }

    public function availabilityMessage(): string
    {
        return $this->isAvailable()
            ? 'Panther browser adapter is available.'
            : 'Browser mode is optional. Install symfony/panther and a Chrome/Firefox driver to enable browser-assisted crawling.';
    }

    public function render(string $url, BrowserProfile $profile, BrowserOptions $options): BrowserPageResult
    {
        $result = $this->open($url, $profile, $options);
        if ($options->waitSelector) {
            $this->waitFor($options->waitSelector, max(1, (int) ceil($options->timeoutMs / 1000)));
        }
        $html = $this->html();
        $text = $this->text();
        $screenshotPath = null;
        if ($options->screenshot && $options->outputDir) {
            $screenshotPath = rtrim($options->outputDir, '/\\') . '/screenshot.png';
            if (!is_dir(dirname($screenshotPath))) {
                mkdir(dirname($screenshotPath), 0775, true);
            }
            $this->screenshot($screenshotPath);
        }
        $this->exportCookies($options);
        $this->close();

        return new BrowserPageResult(
            url: $result->url,
            finalUrl: $result->finalUrl,
            title: $result->title,
            html: $html,
            text: $text,
            screenshotPath: $screenshotPath,
            error: $result->error,
            loadTimeMs: $result->loadTimeMs,
            engine: 'panther',
            metadata: ['profile' => $profile->name, 'mode' => $options->mode]
        );
    }

    public function open(string $url, BrowserProfile $profile, ?BrowserOptions $options = null): BrowserPageResult
    {
        if (!class_exists(Client::class)) {
            throw new \RuntimeException($this->availabilityMessage());
        }

        $arguments = [
            '--window-size=' . $profile->windowWidth . ',' . $profile->windowHeight,
            '--disable-gpu',
        ];
        if ($profile->headless) {
            $arguments[] = '--headless=new';
        }
        if ($profile->blockAssets) {
            $arguments[] = '--blink-settings=imagesEnabled=false';
        }

        $started = microtime(true);
        $this->client = Client::createChromeClient(null, $arguments);
        $this->importCookies($options);
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
            engine: 'panther',
            metadata: ['profile' => $profile->name]
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


    private function importCookies(?BrowserOptions $options): void
    {
        if (!$options || !$options->cookieFile || !is_file($options->cookieFile) || !$this->client) {
            return;
        }
        $data = json_decode((string) file_get_contents($options->cookieFile), true);
        $cookies = is_array($data['cookies'] ?? null) ? $data['cookies'] : (is_array($data) ? $data : []);
        if ($cookies === [] || !method_exists($this->client, 'manage')) {
            return;
        }
        $cookieClass = '\Facebook\WebDriver\Cookie';
        foreach ($cookies as $cookie) {
            if (!is_array($cookie) || empty($cookie['name'])) {
                continue;
            }
            try {
                if (class_exists($cookieClass)) {
                    $object = new $cookieClass(
                        (string) $cookie['name'],
                        (string) ($cookie['value'] ?? ''),
                        (string) ($cookie['path'] ?? '/'),
                        isset($cookie['domain']) ? (string) $cookie['domain'] : null,
                        isset($cookie['expiry']) ? (int) $cookie['expiry'] : null,
                        (bool) ($cookie['secure'] ?? false),
                        (bool) ($cookie['httpOnly'] ?? $cookie['http_only'] ?? false)
                    );
                    $this->client->manage()->addCookie($object);
                } elseif (method_exists($this->client->manage(), 'addCookie')) {
                    $this->client->manage()->addCookie($cookie);
                }
            } catch (\Throwable) {
                // Cookie import is best-effort across browser driver versions.
            }
        }
    }

    private function exportCookies(BrowserOptions $options): void
    {
        if (!$options->cookieFile || !$this->client || !method_exists($this->client, 'manage')) {
            return;
        }
        try {
            $raw = $this->client->manage()->getCookies();
            $cookies = [];
            foreach ($raw as $cookie) {
                if (is_object($cookie) && method_exists($cookie, 'toArray')) {
                    $cookies[] = $cookie->toArray();
                } elseif (is_array($cookie)) {
                    $cookies[] = $cookie;
                }
            }
            if (!is_dir(dirname($options->cookieFile))) {
                mkdir(dirname($options->cookieFile), 0775, true);
            }
            file_put_contents($options->cookieFile, json_encode([
                'cookies' => $cookies,
                'saved_at' => date(DATE_ATOM),
                'source' => 'mnb-scraperkit-browser-adapter',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // Cookie export is optional and adapter-dependent.
        }
    }

    private function ensureClient(): void
    {
        if (!$this->client) {
            throw new \RuntimeException('Browser session is not open.');
        }
    }
}
