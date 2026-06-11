<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Processing;

use Mnb\ScraperKit\Core\CrawlOptions;
use Mnb\ScraperKit\Core\HttpClient;
use Mnb\ScraperKit\Core\HttpResponse;
use Mnb\ScraperKit\Core\ProtectionPageDetector;
use Mnb\ScraperKit\Network\ExitPointManager;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;

final class SequentialUrlProcessor
{
    private ExternalHttpFetcher $externalFetcher;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config, private string $rootDir)
    {
        $this->externalFetcher = new ExternalHttpFetcher();
    }

    /**
     * @param array<int,string> $urls
     * @param array<string,mixed> $cliOptions
     * @param callable(string):void|null $progress
     * @return array<string,mixed>
     */
    public function process(array $urls, UrlProcessOptions $processOptions, array $cliOptions, string $outputDir, ?string $checkpointPath = null, bool $resume = false, ?callable $progress = null): array
    {
        $this->ensureDir($outputDir);
        $checkpointPath ??= rtrim($outputDir, '/\\') . '/checkpoint.json';
        $urls = array_values(array_filter(array_map('trim', $urls), static fn (string $u): bool => $u !== '' && !str_starts_with($u, '#')));
        $startIndex = 0;
        $results = [];
        if ($resume && is_file($checkpointPath)) {
            $state = json_decode((string) file_get_contents($checkpointPath), true);
            if (is_array($state)) {
                $startIndex = max(0, (int) ($state['next_index'] ?? 0));
                $results = is_array($state['results'] ?? null) ? $state['results'] : [];
            }
        }

        $startedAt = time();
        $successUrls = [];
        $failedUrls = [];
        $challengeUrls = [];
        $stoppedByFile = false;
        $stoppedByRuntime = false;

        for ($i = $startIndex; $i < count($urls); $i++) {
            if ($this->shouldStop($processOptions, $startedAt, $stoppedByFile, $stoppedByRuntime)) {
                break;
            }

            $url = $urls[$i];
            $progress && $progress(sprintf('[%d/%d] %s', $i + 1, count($urls), $url));
            $urlResult = $this->processOneUrl($url, $processOptions, $cliOptions, $outputDir, $i, $startedAt, $progress);
            $results[$i] = $urlResult;

            if (($urlResult['status'] ?? '') === 'success') {
                $successUrls[] = $url;
            } elseif (($urlResult['status'] ?? '') === 'challenge') {
                $challengeUrls[] = $url;
            } else {
                $failedUrls[] = $url;
            }

            $this->writeCheckpoint($checkpointPath, $i + 1, $urls, $results, $processOptions);

            if ($processOptions->gapMs > 0 && $i < count($urls) - 1) {
                usleep($processOptions->gapMs * 1000);
            }
        }

        foreach ($results as $row) {
            if (!is_array($row) || !isset($row['url'])) {
                continue;
            }
            $url = (string) $row['url'];
            if (($row['status'] ?? '') === 'success' && !in_array($url, $successUrls, true)) {
                $successUrls[] = $url;
            } elseif (($row['status'] ?? '') === 'challenge' && !in_array($url, $challengeUrls, true)) {
                $challengeUrls[] = $url;
            } elseif (($row['status'] ?? '') !== 'success' && ($row['status'] ?? '') !== 'challenge' && !in_array($url, $failedUrls, true)) {
                $failedUrls[] = $url;
            }
        }

        $successUrls = array_values(array_unique($successUrls));
        $failedUrls = array_values(array_unique($failedUrls));
        $challengeUrls = array_values(array_unique($challengeUrls));

        $this->writeTextList(rtrim($outputDir, '/\\') . '/success-urls.txt', $successUrls);
        $this->writeTextList(rtrim($outputDir, '/\\') . '/failed-urls.txt', $failedUrls);
        $this->writeTextList(rtrim($outputDir, '/\\') . '/challenge-urls.txt', $challengeUrls);

        $summary = [
            'urls_total' => count($urls),
            'processed' => count($results),
            'success' => count($successUrls),
            'failed' => count($failedUrls),
            'challenge' => count($challengeUrls),
            'methods' => $processOptions->methods,
            'until_success' => $processOptions->untilSuccess,
            'max_attempts' => $processOptions->maxAttempts,
            'stopped_by_file' => $stoppedByFile,
            'stopped_by_runtime' => $stoppedByRuntime,
            'duration_seconds' => max(0, time() - $startedAt),
            'output_dir' => $outputDir,
            'checkpoint' => $checkpointPath,
        ];
        $data = ['summary' => $summary, 'results' => array_values($results)];
        $this->writeJson(rtrim($outputDir, '/\\') . '/process-summary.json', $data);
        return $data;
    }

    /** @param array<string,mixed> $cliOptions @return array<string,mixed> */
    private function processOneUrl(string $url, UrlProcessOptions $processOptions, array $cliOptions, string $outputDir, int $index, int $startedAt, ?callable $progress): array
    {
        $attempts = [];
        $status = 'failed';
        $lastRecommendation = null;
        $lastFailureType = null;
        $lastError = null;
        $bestFinalUrl = null;
        $stoppedByFile = false;
        $stoppedByRuntime = false;
        $effectiveMaxAttempts = $processOptions->effectiveMaxAttempts();
        $delay = $processOptions->retryDelaySeconds;

        for ($attempt = 1; $attempt <= $effectiveMaxAttempts; $attempt++) {
            if ($this->shouldStop($processOptions, $startedAt, $stoppedByFile, $stoppedByRuntime)) {
                $status = 'stopped';
                $lastError = $stoppedByFile ? 'Stopped because stop file exists.' : 'Stopped because max runtime was reached.';
                break;
            }

            $progress && $progress(sprintf('  Attempt %d/%s', $attempt, $processOptions->untilSuccess && $processOptions->maxAttempts === 0 ? 'until-success' : (string) $effectiveMaxAttempts));
            $attemptHadRetryableFailure = false;

            foreach ($processOptions->methods as $method) {
                $response = $this->fetch($method, $url, $cliOptions);
                $analysis = $this->analyzeResponse($url, $response);
                $isChallenge = (bool) ($analysis['protection']['is_challenge'] ?? false);
                $isSuccess = $processOptions->isSuccessStatus((int) $response->statusCode) && !$response->error && !$isChallenge;
                $row = [
                    'attempt' => $attempt,
                    'method' => $method,
                    'engine_used' => $response->engine,
                    'status_code' => $response->statusCode,
                    'status_category' => $analysis['status_category'],
                    'final_url' => $response->finalUrl,
                    'response_time_ms' => $response->responseTimeMs,
                    'redirect_count' => $response->redirectCount,
                    'title' => $analysis['title'],
                    'text_length' => $analysis['text_length'],
                    'links_count' => $analysis['links_count'],
                    'error' => $response->error,
                    'failure_type' => $analysis['failure_type'],
                    'success' => $isSuccess,
                ];
                if ($processOptions->includeHeaders) {
                    $row['headers'] = $response->headers;
                }
                if ($isChallenge) {
                    $row['protection'] = $analysis['protection'];
                }
                $attempts[] = $row;
                $lastError = $response->error;
                $lastFailureType = $analysis['failure_type'];
                $lastRecommendation = $analysis['recommendation'];
                $bestFinalUrl = $response->finalUrl ?: $bestFinalUrl;

                $progress && $progress(sprintf('    %s => HTTP %d | %s%s', $method, $response->statusCode, $analysis['status_category'], $response->error ? ' | ' . $response->error : ''));

                if ($isSuccess) {
                    $status = 'success';
                    $this->saveBodyIfRequested($processOptions, $outputDir, $index, $url, $response);
                    return [
                        'url' => $url,
                        'status' => $status,
                        'final_url' => $response->finalUrl,
                        'success_method' => $method,
                        'success_engine' => $response->engine,
                        'status_code' => $response->statusCode,
                        'attempts_count' => count($attempts),
                        'attempts' => $attempts,
                    ];
                }

                if ($isChallenge) {
                    $status = 'challenge';
                    if (!$processOptions->retryChallenge || $processOptions->stopOnChallenge) {
                        return [
                            'url' => $url,
                            'status' => 'challenge',
                            'final_url' => $response->finalUrl,
                            'failure_type' => $analysis['failure_type'],
                            'recommendation' => $analysis['recommendation'],
                            'attempts_count' => count($attempts),
                            'attempts' => $attempts,
                        ];
                    }
                    $attemptHadRetryableFailure = true;
                    continue;
                }

                if ($processOptions->shouldRetryStatus((int) $response->statusCode) || $response->error) {
                    $attemptHadRetryableFailure = true;
                }
            }

            if (!$attemptHadRetryableFailure && !$processOptions->untilSuccess) {
                break;
            }
            if ($attempt < $effectiveMaxAttempts) {
                $sleep = min($processOptions->maxDelaySeconds, $delay);
                if ($sleep > 0) {
                    $progress && $progress('  Retry pause: ' . $sleep . ' seconds');
                    sleep($sleep);
                }
                $delay = (int) ceil($delay * $processOptions->backoffMultiplier);
            }
        }

        return [
            'url' => $url,
            'status' => $status,
            'final_url' => $bestFinalUrl,
            'failure_type' => $lastFailureType,
            'error' => $lastError,
            'recommendation' => $lastRecommendation,
            'attempts_count' => count($attempts),
            'attempts' => $attempts,
        ];
    }

    /** @param array<string,mixed> $cliOptions */
    private function fetch(string $method, string $url, array $cliOptions): HttpResponse
    {
        $options = $this->crawlOptions($cliOptions, $method);
        if ($this->externalFetcher->isExternalMethod($method)) {
            return $this->externalFetcher->fetch($method, $url, $options);
        }
        $network = (new ExitPointManager((array) ($this->config['network']['profiles'] ?? [])))->select($options->networkProfile ?? (string) ($this->config['network']['default'] ?? 'direct'));
        return (new HttpClient($network, new UrlSafetyGuard((array) ($this->config['safety'] ?? []))))->get($url, $options);
    }

    /** @param array<string,mixed> $cliOptions */
    private function crawlOptions(array $cliOptions, string $method): CrawlOptions
    {
        $configDefaults = (array) ($this->config['crawl'] ?? []);
        $data = $configDefaults;
        $engine = match (strtolower($method)) {
            'php-curl', 'curl' => 'curl',
            'php-stream', 'stream', 'file-get-contents', 'file_get_contents' => 'stream',
            'auto' => 'auto',
            default => (string) ($cliOptions['http-engine'] ?? $cliOptions['engine'] ?? 'auto'),
        };
        $data['http_engine'] = $engine;
        foreach ([
            'timeout' => 'timeout_seconds',
            'timeout-seconds' => 'timeout_seconds',
            'user-agent' => 'user_agent',
            'network' => 'network_profile',
            'network-profile' => 'network_profile',
            'cookie-jar' => 'cookie_jar_path',
            'max-redirects' => 'max_redirects',
        ] as $cli => $cfg) {
            if (isset($cliOptions[$cli])) {
                $data[$cfg] = $cliOptions[$cli];
            }
        }
        $headers = $this->headersFromCli($cliOptions);
        if ($headers !== []) {
            $data['request_headers'] = $headers;
        }
        if (isset($cliOptions['no-verify-ssl'])) {
            $data['verify_ssl'] = false;
        }
        if (isset($cliOptions['no-cookie-jar'])) {
            $data['use_cookie_jar'] = false;
        }
        return CrawlOptions::fromArray($data);
    }

    /** @return array<string,mixed> */
    private function analyzeResponse(string $url, HttpResponse $response): array
    {
        $title = null;
        $textLength = 0;
        $links = [];
        $meta = [];
        try {
            if ($response->body !== '') {
                $parser = new HtmlParser();
                $doc = $parser->load($response->body, $response->finalUrl ?: $url);
                $title = $parser->title($doc);
                $textLength = $this->textLength($parser->text($doc));
                $links = $parser->links($doc, $response->finalUrl ?: $url);
                $meta = [
                    'description' => $parser->meta($doc, 'description'),
                    'robots' => $parser->meta($doc, 'robots'),
                    'canonical' => $parser->canonical($doc, $response->finalUrl ?: $url),
                ];
            }
        } catch (\Throwable) {
            if (preg_match('~<title[^>]*>(.*?)</title>~is', $response->body, $m) === 1) {
                $title = trim(strip_tags(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }
            $textLength = $this->textLength(trim(strip_tags($response->body))); 
            preg_match_all('~<a\s+[^>]*href\s*=\s*["\']([^"\']+)~i', $response->body, $m);
            $links = $m[1] ?? [];
        }
        $protection = (new ProtectionPageDetector())->detect(
            $url,
            $response->finalUrl,
            $response->statusCode,
            $response->headers,
            $response->body,
            $title,
            '',
            [],
            $meta,
            $response->error
        );
        $challenge = (bool) ($protection['is_challenge'] ?? false);
        $category = $this->httpStatusCategory($response->statusCode, $response->error, $challenge);
        $failureType = $challenge ? (string) ($protection['failure_type'] ?? 'challenge_or_protection') : ($response->error ? 'http_client_error' : ($response->statusCode >= 400 ? $category : null));
        $recommendation = $challenge
            ? (string) ($protection['recommendation'] ?? 'Challenge/protection page detected. Wait, use an allowed source connector, sitemap/feed, or authorized browser workflow.')
            : $this->recommendationForCategory($category, $response->statusCode);

        return [
            'title' => $title,
            'text_length' => $textLength,
            'links_count' => count($links),
            'status_category' => $category,
            'failure_type' => $failureType,
            'recommendation' => $recommendation,
            'protection' => $protection,
        ];
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function httpStatusCategory(int $statusCode, ?string $error, bool $challenge): string
    {
        if ($challenge) {
            return 'challenge_or_protection';
        }
        if ($error) {
            return 'http_client_error';
        }
        return match (true) {
            $statusCode === 0 => 'no_response',
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode >= 300 && $statusCode < 400 => 'redirect',
            $statusCode === 401 => 'unauthorized',
            $statusCode === 403 => 'forbidden',
            $statusCode === 404 => 'not_found',
            $statusCode === 408 => 'request_timeout',
            $statusCode === 425 => 'too_early',
            $statusCode === 429 => 'rate_limited',
            $statusCode >= 400 && $statusCode < 500 => 'client_error',
            $statusCode >= 500 => 'server_error',
            default => 'unknown',
        };
    }

    private function recommendationForCategory(string $category, int $statusCode): ?string
    {
        return match ($category) {
            'rate_limited' => 'HTTP 429 received. Slow down, increase gap/cooldown, resume later, and avoid repeated requests.',
            'forbidden' => 'HTTP 403 received. This may be blocked or disallowed. Do not force retries; use source:discover or an allowed source connector.',
            'server_error' => 'Temporary server error. Safe retry with backoff can help.',
            'request_timeout', 'http_client_error', 'no_response' => 'Network/timeout failure. Retry with backoff or another local HTTP method.',
            'not_found' => 'HTTP 404 is usually permanent. Do not retry unless URL source may be stale.',
            default => $statusCode >= 400 ? 'Check URL/status before retrying.' : null,
        };
    }

    private function saveBodyIfRequested(UrlProcessOptions $options, string $outputDir, int $index, string $url, HttpResponse $response): void
    {
        if (!$options->saveBody) {
            return;
        }
        $dir = rtrim($outputDir, '/\\') . '/bodies';
        $this->ensureDir($dir);
        $name = str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT) . '-' . preg_replace('~[^a-z0-9]+~i', '-', parse_url($url, PHP_URL_HOST) ?: 'url') . '.html';
        file_put_contents($dir . '/' . trim($name, '-'), $response->body);
    }

    private function shouldStop(UrlProcessOptions $options, int $startedAt, bool &$stoppedByFile, bool &$stoppedByRuntime): bool
    {
        if ($options->stopFile && is_file($options->stopFile)) {
            $stoppedByFile = true;
            return true;
        }
        if ($options->maxRuntimeSeconds > 0 && (time() - $startedAt) >= $options->maxRuntimeSeconds) {
            $stoppedByRuntime = true;
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $opts @return array<string,string> */
    private function headersFromCli(array $opts): array
    {
        $headers = [];
        $items = $opts['header'] ?? [];
        if (is_string($items)) {
            $items = [$items];
        }
        if (!is_array($items)) {
            $items = [$items];
        }
        foreach ($items as $line) {
            if (is_array($line)) {
                foreach ($line as $inner) {
                    if (is_scalar($inner)) {
                        $this->addHeaderLine($headers, (string) $inner);
                    }
                }
                continue;
            }
            if (is_scalar($line)) {
                $this->addHeaderLine($headers, (string) $line);
            }
        }
        return $headers;
    }

    /** @param array<string,string> $headers */
    private function addHeaderLine(array &$headers, string $line): void
    {
        if (!str_contains($line, ':')) {
            return;
        }
        [$name, $value] = explode(':', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name !== '' && $value !== '') {
            $headers[$name] = $value;
        }
    }

    /** @param array<int,string> $items */
    private function writeTextList(string $path, array $items): void
    {
        file_put_contents($path, implode(PHP_EOL, $items) . ($items ? PHP_EOL : ''));
    }

    /** @param array<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<int,string> $urls @param array<int,mixed> $results */
    private function writeCheckpoint(string $path, int $nextIndex, array $urls, array $results, UrlProcessOptions $options): void
    {
        $success = [];
        $failed = [];
        $challenge = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if ($status === 'success') {
                $success[] = $url;
            } elseif ($status === 'challenge') {
                $challenge[] = $url;
            } elseif ($status !== '') {
                $failed[] = $url;
            }
        }
        $pending = array_values(array_slice($urls, $nextIndex));
        $this->writeJson($path, [
            'checkpoint_version' => '1.0.0',
            'next_index' => $nextIndex,
            'urls_total' => count($urls),
            'updated_at' => date(DATE_ATOM),
            'methods' => $options->methods,
            'queues' => [
                'pending' => $pending,
                'completed' => array_values(array_unique($success)),
                'failed' => array_values(array_unique($failed)),
                'challenge' => array_values(array_unique($challenge)),
            ],
            'counts' => [
                'pending' => count($pending),
                'completed' => count(array_unique($success)),
                'failed' => count(array_unique($failed)),
                'challenge' => count(array_unique($challenge)),
            ],
            'results' => $results,
        ]);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
