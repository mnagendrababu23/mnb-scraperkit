<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Hardening;

use Mnb\ScraperKit\Console\CommandRegistry;
use Mnb\ScraperKit\Safety\UrlSafetyGuard;
use Mnb\ScraperKit\Support\UrlNormalizer;

/** Lightweight deterministic benchmarks that do not perform network calls. */
final class BenchmarkRunner
{
    public const VERSION = '1.0.2';

    /** @return array<string,mixed> */
    public function run(int $iterations = 1000): array
    {
        $iterations = max(10, min(200000, $iterations));

        $results = [];
        $normalizer = new UrlNormalizer();
        $results[] = $this->measure('url_normalization', $iterations, static function (int $i) use ($normalizer): void {
            $normalizer->normalize('https://Example.com/A/../B/?utm_source=x&id=' . $i . '#frag');
        });

        $guard = new UrlSafetyGuard();
        $urls = [
            'https://93.184.216.34/products?id=1',
            'https://8.8.8.8/path?q=test',
            'http://127.0.0.1/admin',
            'file:///etc/passwd',
        ];
        $results[] = $this->measure('url_safety_checks', $iterations, static function (int $i) use ($guard, $urls): void {
            try {
                $guard->assertAllowed($urls[$i % count($urls)]);
            } catch (\Throwable $e) {
                // Expected for intentionally unsafe sample URLs.
            }
        });

        $payload = ['id' => 'bench', 'fields' => ['title' => 'Sample', 'price' => '12.50'], 'quality_score' => 95];
        $results[] = $this->measure('json_encode_decode', $iterations, static function () use ($payload): void {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        });

        $results[] = $this->measure('command_registry_snapshot', max(10, intdiv($iterations, 10)), static function (): void {
            CommandRegistry::commands();
            CommandRegistry::optionNames();
            CommandRegistry::valueLessOptions();
        });

        return [
            'benchmark_version' => self::VERSION,
            'iterations_requested' => $iterations,
            'php_version' => PHP_VERSION,
            'generated_at' => gmdate('c'),
            'benchmarks' => $results,
        ];
    }

    /** @return array<string,mixed> */
    private function measure(string $name, int $iterations, callable $callback): array
    {
        $started = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $callback($i);
        }
        $elapsedMs = (hrtime(true) - $started) / 1000000;
        $opsPerSecond = $elapsedMs > 0 ? ($iterations / ($elapsedMs / 1000)) : $iterations;

        return [
            'name' => $name,
            'iterations' => $iterations,
            'elapsed_ms' => round($elapsedMs, 3),
            'ops_per_second' => round($opsPerSecond, 2),
        ];
    }
}
