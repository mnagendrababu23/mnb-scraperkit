<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Elsevier;

final class ElsevierJournalCatalog
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return [
            [
                'key' => 'sciencedirect',
                'name' => 'ScienceDirect',
                'browse_url' => 'https://www.sciencedirect.com/browse/journals-and-books',
                'connector' => 'elsevier:search',
                'notes' => 'Protected browse pages should use the ScienceDirect Search API connector when API access is available.',
            ],
            [
                'key' => 'serial-title-api',
                'name' => 'Elsevier Serial Title API',
                'connector' => 'elsevier:serial <issn>',
                'notes' => 'Returns serial title metadata for a known ISSN when entitled/API key is available.',
            ],
            [
                'key' => 'article-api',
                'name' => 'ScienceDirect Article API',
                'connector' => 'elsevier:doi <doi> or elsevier:metadata <pii> --type=pii',
                'notes' => 'Returns article metadata/full-text fields depending on API key entitlements.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function connectorInfo(): array
    {
        return [
            'source_type' => 'elsevier_sciencedirect_connector_catalog',
            'api_key_env' => 'ELSEVIER_API_KEY',
            'insttoken_env' => 'ELSEVIER_INSTTOKEN',
            'commands' => [
                'elsevier:search <query> --rows=25 --json',
                'elsevier:doi <doi> --json',
                'elsevier:metadata <identifier> --type=doi|pii --json',
                'elsevier:serial <issn> --json',
                'elsevier:urls <query> --rows=100 --output=storage/elsevier-urls.txt',
            ],
            'items' => $this->all(),
        ];
    }
}
