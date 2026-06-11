<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Command;

use Mnb\ScraperKit\Cli\NativeCliApplication;
use Mnb\ScraperKit\Console\CommandRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony command wrapper around the stable V4.0.1 native engine command runner.
 *
 * The crawler/pipeline core remains framework-independent. Symfony Console is the
 * public CLI layer for Composer/Packagist users: command discovery, --help,
 * command descriptions, and vendor/bin integration.
 */
final class NativeProxyCommand extends Command
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly string $commandName,
        private readonly string $commandDescription,
        private readonly array $config,
        private readonly string $rootDir
    ) {
        parent::__construct($commandName);
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->commandDescription)
            ->addArgument('tokens', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Command arguments and values.');

        $valueLess = array_flip(CommandRegistry::valueLessOptions());
        foreach (CommandRegistry::optionNames() as $name) {
            $shortcut = $name === 'output' ? 'o' : null;
            $mode = isset($valueLess[$name])
                ? InputOption::VALUE_NONE
                : (InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY);
            $this->addOption($name, $shortcut, $mode, 'MNB ScraperKit option.');
        }

        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argv = $_SERVER['argv'] ?? [];
        if (!is_array($argv) || count($argv) < 2) {
            $argv = ['mnb-scraper', $this->commandName];
        }

        return (new NativeCliApplication($this->config, $this->rootDir))->run(array_values(array_map('strval', $argv)));
    }
}
