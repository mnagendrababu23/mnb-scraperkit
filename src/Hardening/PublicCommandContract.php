<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Hardening;

use Mnb\ScraperKit\Console\CommandRegistry;

/**
 * Public CLI compatibility contract for release hardening.
 *
 * This class centralizes the public command and option surface so patch releases
 * can be checked for accidental duplicate options, missing command metadata, and
 * breaking option removals before publishing.
 */
final class PublicCommandContract
{
    public const VERSION = '4.0.2';

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $commands = CommandRegistry::commands();
        $options = CommandRegistry::optionNames();
        $valueLess = CommandRegistry::valueLessOptions();

        sort($options);
        sort($valueLess);

        return [
            'contract_version' => self::VERSION,
            'commands_total' => count($commands),
            'options_total' => count($options),
            'value_less_options_total' => count($valueLess),
            'commands' => $commands,
            'options' => $options,
            'value_less_options' => $valueLess,
            'compatibility_rules' => [
                'patch_release' => [
                    'Do not remove public commands.',
                    'Do not rename public options.',
                    'Do not change output file defaults without a compatibility alias.',
                    'Only add optional commands/options or bug fixes.',
                ],
                'minor_release' => [
                    'May add commands, options, profiles, connectors, and report formats.',
                    'Keep old command names as aliases when possible.',
                    'Document new optional dependencies clearly.',
                ],
                'major_release' => [
                    'May remove deprecated commands/options after migration notes.',
                    'Must update README compatibility notes and examples.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function validate(): array
    {
        $commands = CommandRegistry::commands();
        $options = CommandRegistry::optionNames();
        $valueLess = CommandRegistry::valueLessOptions();

        $issues = [];
        $duplicateOptions = $this->duplicates($options);
        if ($duplicateOptions !== []) {
            $issues[] = [
                'severity' => 'high',
                'code' => 'duplicate_options',
                'message' => 'Duplicate Symfony option names found: ' . implode(', ', $duplicateOptions),
            ];
        }

        $unknownValueLess = array_values(array_diff($valueLess, $options));
        if ($unknownValueLess !== []) {
            $issues[] = [
                'severity' => 'medium',
                'code' => 'value_less_option_not_registered',
                'message' => 'Value-less options are not registered in optionNames(): ' . implode(', ', $unknownValueLess),
            ];
        }

        foreach ($commands as $name => $description) {
            if (trim((string) $description) === '') {
                $issues[] = [
                    'severity' => 'medium',
                    'code' => 'missing_description',
                    'message' => 'Command has an empty description: ' . $name,
                ];
            }
        }

        return [
            'contract_version' => self::VERSION,
            'ok' => $issues === [],
            'issues' => $issues,
            'commands_total' => count($commands),
            'options_total' => count($options),
            'value_less_options_total' => count($valueLess),
        ];
    }

    /** @param array<int,string> $items @return array<int,string> */
    private function duplicates(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $counts[$item] = ($counts[$item] ?? 0) + 1;
        }
        return array_values(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)));
    }
}
