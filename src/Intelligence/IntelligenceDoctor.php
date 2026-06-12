<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Intelligence;

final class IntelligenceDoctor
{
    /** @return array<string,mixed> */
    public function inspect(): array
    {
        return [
            'intelligence_version' => '4.1.1',
            'generated_at' => date(DATE_ATOM),
            'mode' => 'deterministic_ml_ready_baseline',
            'php_ml_available' => class_exists('Phpml\\Classification\\KNearestNeighbors') || class_exists('Phpml\\Classification\\SVC'),
            'optional_packages' => [
                'php-ai/php-ml' => 'Optional trainer/inference dependency. MNB ScraperKit v4.1.1 works without it and exports ML-ready features.',
            ],
            'available_tools' => [
                'feature_extraction',
                'heuristic_page_classification',
                'quality_prediction',
                'url_priority_scoring',
                'selector_suggestions',
            ],
            'note' => 'No external model is executed by default. Use exported feature JSON as input for future PHP-ML training workflows.',
        ];
    }
}
