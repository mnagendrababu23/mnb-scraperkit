<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Cli\Commands;

use Mnb\ScraperKit\Hardening\BenchmarkRunner;
use Mnb\ScraperKit\Hardening\ProductionReadinessInspector;
use Mnb\ScraperKit\Hardening\PublicCommandContract;

trait HardeningCommandTrait
{
    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function hardeningDoctor(array $args, array $opts): int
    {
        $mode = strtolower((string) ($opts['mode'] ?? 'repo'));
        $report = (new ProductionReadinessInspector())->inspect($this->rootDir, $mode);
        if ($this->bool($opts, 'json')) {
            $this->outJson($report);
        } else {
            $this->out('MNB ScraperKit ' . ($report['hardening_version'] ?? '4.1.0') . ' production readiness');
            $this->out('Status: ' . strtoupper((string) ($report['status'] ?? 'unknown')) . ' | Score: ' . (string) ($report['score'] ?? 0) . '/100 | Mode: ' . strtoupper((string) ($report['project']['mode'] ?? 'repo')));
            $this->out('');
            foreach ((array) ($report['checks'] ?? []) as $check) {
                $this->out(sprintf('[%s] %s', !empty($check['ok']) ? 'OK' : 'FIX', (string) ($check['title'] ?? $check['code'] ?? 'check')));
                if (empty($check['ok']) && !empty($check['recommendation'])) {
                    $this->out('     ' . (string) $check['recommendation']);
                }
            }
        }

        return $this->bool($opts, 'strict') && (($report['status'] ?? 'fail') !== 'pass') ? 2 : 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function ciCheck(array $args, array $opts): int
    {
        $opts['strict'] = $opts['strict'] ?? true;
        $opts['mode'] = 'repo';
        return $this->hardeningDoctor($args, $opts);
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function releaseCheck(array $args, array $opts): int
    {
        $opts['strict'] = $opts['strict'] ?? true;
        $opts['mode'] = 'archive';
        $originalRoot = $this->rootDir;
        if (!empty($args[0])) {
            $this->rootDir = rtrim((string) $args[0], '/\\');
        }

        try {
            return $this->hardeningDoctor($args, $opts);
        } finally {
            $this->rootDir = $originalRoot;
        }
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function benchmarkRun(array $args, array $opts): int
    {
        $iterations = (int) ($this->optString($opts, 'iterations', '1000') ?? '1000');
        $report = (new BenchmarkRunner())->run($iterations);
        if ($this->bool($opts, 'json')) {
            $this->outJson($report);
            return 0;
        }
        $this->out('MNB ScraperKit benchmark report');
        $this->out('PHP: ' . (string) ($report['php_version'] ?? PHP_VERSION));
        $this->out('');
        foreach ((array) ($report['benchmarks'] ?? []) as $row) {
            $this->out(sprintf(
                '%-28s %8d iterations %10.3f ms %12.2f ops/sec',
                (string) ($row['name'] ?? 'benchmark'),
                (int) ($row['iterations'] ?? 0),
                (float) ($row['elapsed_ms'] ?? 0),
                (float) ($row['ops_per_second'] ?? 0)
            ));
        }
        return 0;
    }

    /** @param array<int,string> $args @param array<string,mixed> $opts */
    private function compatCommands(array $args, array $opts): int
    {
        $contract = new PublicCommandContract();
        $data = $this->bool($opts, 'validate') ? $contract->validate() : $contract->snapshot();
        if ($this->bool($opts, 'json')) {
            $this->outJson($data);
            return (!empty($data['ok']) || !$this->bool($opts, 'validate')) ? 0 : 2;
        }

        $this->out('MNB ScraperKit public command compatibility contract v' . PublicCommandContract::VERSION);
        $this->out('Commands: ' . (string) ($data['commands_total'] ?? 0));
        $this->out('Options: ' . (string) ($data['options_total'] ?? 0));
        if (isset($data['issues'])) {
            foreach ((array) $data['issues'] as $issue) {
                $this->out('[FIX] ' . (string) ($issue['message'] ?? $issue['code'] ?? 'issue'));
            }
        } else {
            $rules = (array) ($data['compatibility_rules']['patch_release'] ?? []);
            $this->out('Patch release rules:');
            foreach ($rules as $rule) {
                $this->out('- ' . (string) $rule);
            }
        }
        return (!empty($data['ok']) || !$this->bool($opts, 'validate')) ? 0 : 2;
    }
}
