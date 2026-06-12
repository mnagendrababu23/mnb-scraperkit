<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Enterprise;

/**
 * Small role-capability map for CLI/API guidance and dashboard summaries.
 */
final class AccessPolicy
{
    public const VERSION = '1.0.0';

    /** @return array<string,array<int,string>> */
    public static function capabilities(): array
    {
        return [
            'owner' => ['*'],
            'admin' => ['workspace.manage', 'user.manage', 'job.manage', 'queue.manage', 'dashboard.view', 'audit.view'],
            'operator' => ['job.create', 'job.run', 'queue.manage', 'dashboard.view', 'report.view'],
            'analyst' => ['dataset.view', 'report.view', 'evaluation.view', 'dashboard.view'],
            'viewer' => ['dashboard.view', 'report.view'],
        ];
    }

    /** @return array<string,mixed> */
    public static function describe(): array
    {
        return [
            'enterprise_version' => self::VERSION,
            'roles' => self::capabilities(),
            'notes' => [
                'This role map is used for workspace metadata, dashboard/API visibility, and future multi-user enforcement.',
                'ScraperKit does not store passwords in v1.0.0. Authentication should be handled by API tokens or the hosting layer.',
            ],
        ];
    }
}
