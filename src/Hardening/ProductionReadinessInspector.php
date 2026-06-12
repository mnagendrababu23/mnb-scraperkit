<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Hardening;

use Mnb\ScraperKit\Console\CommandRegistry;

final class ProductionReadinessInspector
{
    public const VERSION = '4.0.2';

    /** @return array<string,mixed> */
    public function inspect(string $rootDir, string $mode = 'repo'): array
    {
        $rootDir = rtrim($rootDir, '/\\');
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['repo', 'archive'], true)) {
            $mode = 'repo';
        }
        $checks = [];
        $isGitCheckout = $this->isGitCheckout($rootDir);
        if ($mode === 'repo') {
            $this->addCheck(
                $checks,
                'ci_workflow',
                'CI workflow exists in Git checkout',
                !$isGitCheckout || is_file($rootDir . '/.github/workflows/ci.yml'),
                'Add .github/workflows/ci.yml for lint, composer validate, tests, and package hygiene.'
            );
        }
        $this->addCheck($checks, 'composer_json', 'composer.json is valid JSON', $this->validJsonFile($rootDir . '/composer.json'), 'Fix composer.json syntax before release.');
        $this->addCheck($checks, 'readme_only_markdown', 'Only README.md exists as root/package markdown documentation', $this->onlyReadmeMarkdown($rootDir), 'Keep public package documentation centralized in README.md or move deep docs outside release package.');

        if ($mode === 'archive') {
            $this->addCheck($checks, 'no_vendor_in_archive', 'Release archive does not include vendor/', !is_dir($rootDir . '/vendor'), 'Create releases with git archive or exclude vendor/ from the final package.');
            $this->addCheck($checks, 'storage_clean', 'Release archive does not include generated storage outputs', $this->storageLooksClean($rootDir), 'Remove generated jobs, queues, datasets, sessions, and exports from the release package.');
            $this->addCheck($checks, 'no_git_dir_in_archive', 'Release archive does not include .git/', !is_dir($rootDir . '/.git'), 'Use git archive or export-ignore rules so .git/ is never shipped.');
            $this->addCheck($checks, 'no_composer_lock_in_archive', 'Library release archive does not include composer.lock', !is_file($rootDir . '/composer.lock'), 'Do not ship composer.lock for this reusable library package.');
        } else {
            $vendorOk = $isGitCheckout ? !$this->gitTracksPath($rootDir, 'vendor') : true;
            $storageOk = $isGitCheckout ? !$this->gitTracksPath($rootDir, 'storage', true) : $this->storageLooksClean($rootDir);
            $composerLockOk = $isGitCheckout ? !$this->gitTracksPath($rootDir, 'composer.lock') : !is_file($rootDir . '/composer.lock');
            $this->addCheck($checks, 'vendor_not_tracked', 'vendor/ is not tracked by Git', $vendorOk, 'Remove vendor/ from Git tracking; Composer may still install it locally or in CI.');
            $this->addCheck($checks, 'storage_outputs_not_tracked', 'Generated storage outputs are not tracked by Git', $storageOk, 'Keep generated storage outputs ignored; only storage/.gitkeep should be tracked.');
            $this->addCheck($checks, 'composer_lock_not_tracked', 'composer.lock is not tracked for library packaging', $composerLockOk, 'Remove composer.lock from Git for this reusable library package.');
            $this->addCheck(
                $checks,
                'gitattributes_exists',
                '.gitattributes exists for clean export archives in Git checkout',
                !$isGitCheckout || is_file($rootDir . '/.gitattributes'),
                'Add .gitattributes with export-ignore rules for local-only paths.'
            );
        }

        $this->addCheck($checks, 'tests_exist', 'Test runner exists', is_file($rootDir . '/tests/run-tests.php'), 'Add tests/run-tests.php before publishing.');

        $contract = (new PublicCommandContract())->validate();
        $this->addCheck($checks, 'command_contract', 'Public command/option contract validates', (bool) ($contract['ok'] ?? false), 'Fix duplicate options, missing command descriptions, or value-less option registration gaps.');

        $duplicateCliCommands = $this->duplicateCliDispatchCommands($rootDir . '/src/Cli/NativeCliApplication.php');
        $this->addCheck($checks, 'no_duplicate_cli_dispatch', 'Native CLI dispatch has no duplicate command literals', $duplicateCliCommands === [], 'Remove duplicate command cases: ' . implode(', ', $duplicateCliCommands));

        $largeCli = $this->largestCliFile($rootDir);
        $this->addCheck($checks, 'cli_refactor_boundary', 'CLI hardening commands are split into a trait/refactor boundary', is_file($rootDir . '/src/Cli/Commands/HardeningCommandTrait.php'), 'Move new hardening commands out of NativeCliApplication and continue splitting command groups over time.');

        $score = $this->score($checks);
        $high = count(array_filter($checks, static fn (array $check): bool => !$check['ok'] && $check['severity'] === 'high'));

        return [
            'hardening_version' => self::VERSION,
            'status' => $high === 0 ? 'pass' : 'fail',
            'score' => $score,
            'generated_at' => gmdate('c'),
            'php_version' => PHP_VERSION,
            'project' => [
                'root' => $rootDir,
                'mode' => $mode,
                'git_checkout' => $isGitCheckout,
                'php_files' => $this->countFiles($rootDir, 'php'),
                'largest_cli_file' => $largeCli,
            ],
            'command_contract' => $contract,
            'runtime_matrix' => $this->runtimeMatrix(),
            'checks' => $checks,
            'backward_compatibility' => [
                'release_type' => 'patch',
                'version' => self::VERSION,
                'policy' => 'Patch releases may add diagnostics and tests, but should not remove public commands or rename public options.',
                'public_commands_total' => count(CommandRegistry::commands()),
                'public_options_total' => count(CommandRegistry::optionNames()),
            ],
        ];
    }

    /** @param array<int,array<string,mixed>> $checks */
    private function addCheck(array &$checks, string $code, string $title, bool $ok, string $recommendation, string $severity = 'high'): void
    {
        $checks[] = [
            'code' => $code,
            'title' => $title,
            'ok' => $ok,
            'severity' => $severity,
            'recommendation' => $ok ? null : $recommendation,
        ];
    }

    private function validJsonFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        json_decode((string) file_get_contents($path), true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function onlyReadmeMarkdown(string $rootDir): bool
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS));
        $markdown = [];
        foreach ($rii as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/vendor/') || str_contains($path, '/.git/')) {
                continue;
            }
            if (preg_match('/\.(md|markdown)$/i', $file->getFilename()) === 1) {
                $markdown[] = basename($path);
            }
        }
        sort($markdown);
        return $markdown === ['README.md'];
    }

    private function gitTracksPath(string $rootDir, string $path, bool $allowStorageGitkeep = false): bool
    {
        if (!$this->isGitCheckout($rootDir)) {
            return false;
        }

        $command = 'git -C ' . escapeshellarg($rootDir) . ' ls-files -- ' . escapeshellarg($path) . ' 2>' . $this->nullDevice();
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return false;
        }

        if ($allowStorageGitkeep) {
            $tracked = array_values(array_filter($output, static fn (string $line): bool => trim($line) !== '' && trim($line) !== 'storage/.gitkeep'));
            return $tracked !== [];
        }

        return true;
    }

    private function isGitCheckout(string $rootDir): bool
    {
        return is_dir($rootDir . '/.git') || is_file($rootDir . '/.git');
    }

    private function nullDevice(): string
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    }

    private function storageLooksClean(string $rootDir): bool
    {
        $storage = $rootDir . '/storage';
        if (!is_dir($storage)) {
            return true;
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getFilename() !== '.gitkeep') {
                return false;
            }
        }
        return true;
    }

    /** @return array<int,string> */
    private function duplicateCliDispatchCommands(string $path): array
    {
        if (!is_file($path)) {
            return ['NativeCliApplication.php missing'];
        }
        $source = (string) file_get_contents($path);
        $start = strpos($source, 'return match ($command)');
        $end = strpos($source, 'default => $this->unknown($command)', $start === false ? 0 : $start);
        if ($start !== false && $end !== false) {
            $source = substr($source, $start, $end - $start);
        }
        if (!preg_match_all('/\'([a-z][a-z0-9:-]+)\'\s*=>\s*\$this->/', $source, $matches)) {
            return [];
        }
        $counts = [];
        foreach ($matches[1] as $command) {
            $counts[$command] = ($counts[$command] ?? 0) + 1;
        }
        return array_values(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)));
    }

    /** @return array<string,mixed> */
    private function largestCliFile(string $rootDir): array
    {
        $file = $rootDir . '/src/Cli/NativeCliApplication.php';
        return [
            'path' => 'src/Cli/NativeCliApplication.php',
            'bytes' => is_file($file) ? filesize($file) : 0,
            'lines' => is_file($file) ? count(file($file) ?: []) : 0,
            'note' => 'v4.0.2 keeps the hardening trait boundary and adds parser/package hygiene checks; continue splitting command groups in later maintenance releases.',
        ];
    }

    private function countFiles(string $rootDir, string $extension): int
    {
        $count = 0;
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === $extension) {
                $count++;
            }
        }
        return $count;
    }

    /** @param array<int,array<string,mixed>> $checks */
    private function score(array $checks): int
    {
        if ($checks === []) {
            return 100;
        }
        $passed = count(array_filter($checks, static fn (array $check): bool => (bool) $check['ok']));
        return (int) round(($passed / count($checks)) * 100);
    }

    /** @return array<string,mixed> */
    private function runtimeMatrix(): array
    {
        return [
            'browser' => [
                'status' => class_exists('Symfony\\Component\\Panther\\Client') ? 'available' : 'optional_not_installed',
                'note' => 'Browser tests are optional and require symfony/panther plus Chrome/Chromium.',
            ],
            'redis' => [
                'status' => extension_loaded('redis') ? 'available' : 'optional_not_installed',
                'note' => 'Distributed Redis queue tests require ext-redis. File adapter remains available.',
            ],
            'database' => [
                'pdo' => extension_loaded('pdo') ? 'available' : 'optional_not_installed',
                'pdo_sqlite' => extension_loaded('pdo_sqlite') ? 'available' : 'optional_not_installed',
                'pdo_mysql' => extension_loaded('pdo_mysql') ? 'available' : 'optional_not_installed',
            ],
            'dom' => [
                'status' => class_exists('DOMDocument') ? 'available' : 'missing',
                'note' => 'DOMDocument is required by composer.json for full extractor/rule-builder behavior.',
            ],
        ];
    }
}
