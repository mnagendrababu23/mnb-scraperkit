<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Browser;

use Mnb\ScraperKit\Browser\Adapters\PantherBrowserAdapter;
use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;
use Mnb\ScraperKit\Core\RobotsPolicy;
use Mnb\ScraperKit\Network\ExitPointManager;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;

final class BrowserCrawlService
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function clientFor(BrowserOptions $options): BrowserClientInterface
    {
        return match (strtolower($options->engine)) {
            'panther', 'chrome', 'chromium' => new PantherBrowserAdapter(),
            default => new PantherBrowserAdapter(),
        };
    }

    public function availability(BrowserOptions $options): string
    {
        return $this->clientFor($options)->availabilityMessage();
    }

    public function isAvailable(BrowserOptions $options): bool
    {
        return $this->clientFor($options)->isAvailable();
    }

    public function render(string $url, CrawlOptions $crawlOptions, BrowserOptions $browserOptions): BrowserPageResult
    {
        $guard = new UrlSafetyGuard((array) ($this->config['safety'] ?? []));
        $guard->assertAllowed($url);

        $network = (new ExitPointManager((array) ($this->config['network']['profiles'] ?? [])))
            ->select($crawlOptions->networkProfile ?? (string) ($this->config['network']['default'] ?? 'direct'));
        $robots = (new RobotsPolicy(new HttpClient($network, $guard)))->inspect($url, $crawlOptions);
        if (!$robots->allowed) {
            throw new \RuntimeException('Browser render blocked by robots policy: ' . $robots->reason);
        }

        $profile = $this->profile($browserOptions);
        $client = $this->clientFor($browserOptions);
        if (!$client->isAvailable()) {
            throw new \RuntimeException($client->availabilityMessage());
        }

        $result = $client->render($url, $profile, $browserOptions);
        if ($browserOptions->outputDir) {
            $this->writeArtifacts($result, $browserOptions);
        }
        return $result;
    }

    private function profile(BrowserOptions $options): BrowserProfile
    {
        $profiles = (array) ($this->config['browser']['profiles'] ?? []);
        $data = (array) ($profiles[$options->profile] ?? []);
        $profile = BrowserProfile::fromArray($options->profile, $data);
        $profile->engine = $options->engine ?: $profile->engine;
        $profile->headless = $options->headless;
        $profile->windowWidth = $options->viewportWidth;
        $profile->windowHeight = $options->viewportHeight;
        $profile->timeoutSeconds = max(1, (int) ceil($options->timeoutMs / 1000));
        $profile->waitAfterLoadMs = $options->waitAfterLoadMs;
        return $profile;
    }

    private function writeArtifacts(BrowserPageResult $result, BrowserOptions $options): void
    {
        $dir = rtrim((string) $options->outputDir, '/\\');
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if ($options->saveRenderedHtml) {
            file_put_contents($dir . '/rendered.html', $result->html);
        }
        file_put_contents($dir . '/browser-result.json', json_encode($result->toArray(false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
