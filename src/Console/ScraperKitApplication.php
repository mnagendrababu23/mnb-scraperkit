<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Console;

use Mnb\ScraperKit\Command\NativeProxyCommand;
use Symfony\Component\Console\Application;

final class ScraperKitApplication
{
    /** @param array<string,mixed> $config */
    public static function create(array $config, string $rootDir): Application
    {
        $application = new Application('MNB ScraperKit', '3.6.0');
        $application->setCatchExceptions(true);

        foreach (CommandRegistry::commands() as $name => $description) {
            $application->add(new NativeProxyCommand($name, $description, $config, $rootDir));
        }

        return $application;
    }
}
