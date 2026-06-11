<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Core;

use Mnb\ScraperKit\Encoding\EncodingConverter;
use Mnb\ScraperKit\Encoding\EncodingDetector;
use Mnb\ScraperKit\Extractor\CommonDataExtractor;
use Mnb\ScraperKit\Network\ExitPointManager;
use Mnb\ScraperKit\Network\NetworkPolicy;
use Mnb\ScraperKit\Extractor\RuleBasedExtractor;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Parser\PresetExtractor;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;
use Mnb\ScraperKit\Support\Config;
use Mnb\ScraperKit\Support\Logger;
use Mnb\ScraperKit\Support\UrlNormalizer;
use SplQueue;

final class Scraper
{
    private Config $config;
    private UrlNormalizer $urlNormalizer;

    /** @param array<string,mixed> $config */
    public function __construct(array $config = [], private ?Logger $logger = null)
    {
        $this->config = new Config($config);
        $this->urlNormalizer = new UrlNormalizer();
        $this->logger ??= new Logger();
    }

    /** @param array<string,string> $rules */
    public function crawl(string $startUrl, ?CrawlOptions $options = null, array $rules = []): CrawlResult
    {
        $options ??= CrawlOptions::fromArray((array) $this->config->get('crawl', []));
        $network = (new ExitPointManager((array) $this->config->get('network.profiles', [])))
            ->select($options->networkProfile ?? (string) $this->config->get('network.default', 'direct'));
        (new NetworkPolicy())->validate($network);

        $safetyGuard = new UrlSafetyGuard((array) $this->config->get('safety', []));
        $client = new HttpClient($network, $safetyGuard);
        $robots = new RobotsPolicy($client);
        $parser = new HtmlParser();
        $encodingDetector = new EncodingDetector((array) $this->config->get('encoding', []));
        $encodingConverter = new EncodingConverter((array) $this->config->get('encoding', []));
        $extractor = new RuleBasedExtractor($parser, $this->urlNormalizer);
        $presetExtractor = new PresetExtractor($this->urlNormalizer);
        $commonDataExtractor = new CommonDataExtractor($parser, $this->urlNormalizer);
        $rateLimiter = new RateLimiter();
        $urlFilter = new CrawlUrlFilter($this->urlNormalizer);
        $protectionDetector = new ProtectionPageDetector();

        $normalizedStart = $this->urlNormalizer->normalize($startUrl);
        if (!$normalizedStart) {
            throw new \InvalidArgumentException('Invalid start URL.');
        }
        $safetyGuard->assertAllowed($normalizedStart);

        $result = new CrawlResult($normalizedStart);
        $visited = [];
        $finalVisited = [];
        $queue = new SplQueue();
        $queue->enqueue([$normalizedStart, 0]);

        while (!$queue->isEmpty() && count($visited) < $options->maxPages) {
            [$url, $depth] = $queue->dequeue();
            $url = (string) $url;
            $depth = (int) $depth;

            if (isset($visited[$url])) {
                continue;
            }

            try {
                $safetyGuard->assertAllowed($url);
            } catch (\RuntimeException $e) {
                $visited[$url] = true;
                $result->addPage(new PageResult(url: $url, error: $e->getMessage(), failureType: FailureClassifier::fromSafetyMessage($e->getMessage()), depth: $depth));
                continue;
            }
            $visited[$url] = true;

            if ($depth > $options->maxDepth) {
                continue;
            }

            if ($url !== $normalizedStart) {
                $skipReason = $urlFilter->skipReason($url, $normalizedStart, $options);
                if ($skipReason) {
                    $result->addPage(new PageResult(url: $url, error: null, depth: $depth, skipped: true, skipReason: $skipReason));
                    continue;
                }
            }

            $robotsDecision = $robots->inspect($url, $options);
            if (!$robotsDecision->allowed) {
                $result->addPage(new PageResult(
                    url: $url,
                    error: null,
                    failureType: 'robots_blocked',
                    depth: $depth,
                    robots: $robotsDecision->toArray(),
                    skipped: true,
                    skipReason: 'Blocked by robots.txt policy. ' . $robotsDecision->reason,
                ));
                continue;
            }

            $rateLimiter->waitFor($url, $options);
            $this->logger->info('Fetching URL', ['url' => $url, 'depth' => $depth]);
            try {
                $response = $client->get($url, $options);
            } catch (\RuntimeException $e) {
                $failureType = FailureClassifier::fromSafetyMessage($e->getMessage());
                $result->addPage(new PageResult(
                    url: $url,
                    error: $e->getMessage(),
                    failureType: $failureType,
                    depth: $depth,
                    robots: $robotsDecision->toArray(),
                ));
                $rateLimiter->registerOutcome($failureType, $options);
                continue;
            }

            $rawFinalUrl = $response->finalUrl;
            $cleanFinalUrl = $this->cleanFinalUrl($rawFinalUrl, $options);
            $baseUrl = $cleanFinalUrl ?: $rawFinalUrl ?: $url;
            $normalizedFinal = $this->urlNormalizer->normalize($baseUrl);
            $failureType = $urlFilter->classifyFailure($rawFinalUrl, $response->error, $options, $response->statusCode, $response->curlErrno);
            $failureType ??= $this->classifyHttpStatus($response->statusCode);
            $rateLimiter->registerOutcome($failureType, $options);

            $finalSkipReason = $urlFilter->finalUrlSkipReason($url, $rawFinalUrl, $normalizedStart, $options);
            if ($finalSkipReason) {
                $failureType ??= 'final_domain_guard';
                $result->addPage(new PageResult(
                    url: $url,
                    finalUrl: $cleanFinalUrl,
                    rawFinalUrl: $rawFinalUrl,
                    statusCode: $response->statusCode,
                    error: null,
                    failureType: $failureType,
                    depth: $depth,
                    responseTimeMs: $response->responseTimeMs,
                    redirectCount: $response->redirectCount,
                    httpEngine: $response->engine,
                    robots: $robotsDecision->toArray(),
                    skipped: true,
                    skipReason: $finalSkipReason,
                ));
                continue;
            }

            if ($options->avoidDuplicateFinalUrls && $normalizedFinal && isset($finalVisited[$normalizedFinal]) && $normalizedFinal !== $url) {
                $result->addPage(new PageResult(
                    url: $url,
                    finalUrl: $cleanFinalUrl,
                    rawFinalUrl: $rawFinalUrl,
                    statusCode: $response->statusCode,
                    error: null,
                    failureType: 'duplicate_final_url',
                    depth: $depth,
                    responseTimeMs: $response->responseTimeMs,
                    redirectCount: $response->redirectCount,
                    httpEngine: $response->engine,
                    robots: $robotsDecision->toArray(),
                    skipped: true,
                    skipReason: 'Skipped because final URL was already crawled: ' . $normalizedFinal,
                ));
                continue;
            }
            if ($normalizedFinal) {
                $finalVisited[$normalizedFinal] = true;
            }

            try {
                $detectedEncoding = $encodingDetector->detect($response->body, $response->headers);
                $html = $encodingConverter->toUtf8($response->body, $detectedEncoding);
                $doc = $parser->load($html, $baseUrl);
                $links = $parser->links($doc, $baseUrl);
                $text = $parser->text($doc);
                $title = $parser->title($doc);
                $meta = [
                    'description' => $parser->meta($doc, 'description'),
                    'keywords' => $parser->meta($doc, 'keywords'),
                    'canonical' => $parser->canonical($doc, $baseUrl),
                    'robots' => $parser->meta($doc, 'robots'),
                ];

                $protection = $protectionDetector->detect(
                    $url,
                    $rawFinalUrl,
                    $response->statusCode,
                    $response->headers,
                    $html,
                    $title,
                    $text,
                    $links,
                    $meta,
                    $response->error
                );
                if (($protection['is_challenge'] ?? false) === true) {
                    $failureType = (string) ($protection['failure_type'] ?? 'challenge_or_protection_page');
                    $message = (string) ($protection['recommendation'] ?? 'Challenge/protection page detected.');
                    $result->addPage(new PageResult(
                        url: $url,
                        finalUrl: $cleanFinalUrl,
                        rawFinalUrl: $rawFinalUrl,
                        statusCode: $response->statusCode,
                        title: $title,
                        html: $html,
                        text: $text,
                        links: $links,
                        meta: $meta,
                        extracted: [],
                        contentHash: hash('sha256', $text ?: $html),
                        error: $options->skipChallengePages ? null : $message,
                        failureType: $failureType,
                        depth: $depth,
                        responseTimeMs: $response->responseTimeMs,
                        redirectCount: $response->redirectCount,
                    httpEngine: $response->engine,
                        detectedEncoding: $detectedEncoding,
                        robots: $robotsDecision->toArray(),
                        skipped: $options->skipChallengePages,
                        skipReason: $options->skipChallengePages ? $message : null,
                        protection: $protection,
                    ));
                    continue;
                }

                $extracted = $rules ? $extractor->extract($doc, $rules, $baseUrl) : [];
                $presetData = $presetExtractor->extract($doc, $options->extractPreset, $baseUrl);
                if ($presetData !== []) {
                    $extracted['_preset'] = $presetData;
                }
                if ($options->commonData) {
                    $extracted['_common_data'] = $commonDataExtractor->extract($doc, $baseUrl, $options->commonDataTypes, $options->commonDataProfile);
                }

                $error = $response->error;
                if (!$error && $response->statusCode >= 400) {
                    $error = 'HTTP status ' . $response->statusCode;
                }
                if ($failureType === 'auth_or_cookie_redirect') {
                    $error = 'Auth/cookie redirect flow detected: ' . ($response->error ?: 'final URL landed on identity provider');
                }

                $page = new PageResult(
                    url: $url,
                    finalUrl: $cleanFinalUrl,
                    rawFinalUrl: $rawFinalUrl,
                    statusCode: $response->statusCode,
                    title: $title,
                    html: $html,
                    text: $text,
                    links: $links,
                    meta: $meta,
                    extracted: $extracted,
                    contentHash: hash('sha256', $text ?: $html),
                    error: $error,
                    failureType: $failureType,
                    depth: $depth,
                    responseTimeMs: $response->responseTimeMs,
                    redirectCount: $response->redirectCount,
                    httpEngine: $response->engine,
                    detectedEncoding: $detectedEncoding,
                    robots: $robotsDecision->toArray(),
                );
                $result->addPage($page);
            } catch (\Throwable $e) {
                $this->logger->error('Page processing failed', [
                    'url' => $url,
                    'depth' => $depth,
                    'error' => $e->getMessage(),
                ]);

                $result->addPage(new PageResult(
                    url: $url,
                    finalUrl: $cleanFinalUrl,
                    rawFinalUrl: $rawFinalUrl,
                    statusCode: $response->statusCode,
                    error: 'Page processing failed: ' . $e->getMessage(),
                    failureType: 'page_processing_failed',
                    depth: $depth,
                    responseTimeMs: $response->responseTimeMs,
                    redirectCount: $response->redirectCount,
                    httpEngine: $response->engine,
                    robots: $robotsDecision->toArray(),
                ));
                continue;
            }

            if ($response->error || $response->statusCode >= 400 || $depth >= $options->maxDepth) {
                continue;
            }

            foreach ($links as $link) {
                $normalized = $this->urlNormalizer->normalize($link, $baseUrl);
                if (!$normalized || isset($visited[$normalized])) {
                    continue;
                }
                if ($options->sameDomain && !$this->urlNormalizer->sameHost($normalizedStart, $normalized)) {
                    continue;
                }
                if ($urlFilter->skipReason($normalized, $normalizedStart, $options)) {
                    continue;
                }
                $queue->enqueue([$normalized, $depth + 1]);
            }
        }

        $result->finish();
        return $result;
    }


    private function classifyHttpStatus(int $statusCode): ?string
    {
        return FailureClassifier::fromStatus($statusCode);
    }

    private function cleanFinalUrl(?string $url, CrawlOptions $options): ?string
    {
        if (!$url || !$options->stripFinalUrlQueryParams || $options->finalUrlStripParams === []) {
            return $url;
        }

        $parts = parse_url($url);
        if (!$parts) {
            return $url;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        foreach ($options->finalUrlStripParams as $param) {
            unset($query[$param]);
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $newQuery = $query !== [] ? '?' . http_build_query($query) : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . $newQuery . $fragment;
    }
}
