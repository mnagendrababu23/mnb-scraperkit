<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Ai;

/**
 * Describes optional AI providers without forcing SDK dependencies.
 *
 * v1.0.2 intentionally keeps provider execution pluggable. The core analyzer
 * always has a deterministic rule-based fallback, and provider keys are only
 * read from environment variables or explicit local config.
 */
final class AiProviderRegistry
{
    public const VERSION = '1.0.2';

    /** @return array<string,array<string,mixed>> */
    public static function providers(): array
    {
        return [
            'rule_based' => [
                'id' => 'rule_based',
                'name' => 'Deterministic rule-based analyzer',
                'configured' => true,
                'requires_network' => false,
                'requires_api_key' => false,
                'env' => [],
                'capabilities' => ['site_flexibility', 'page_type_detection', 'recipe_suggestion', 'risk_scoring'],
                'notes' => 'Always available. Produces explainable crawl-flexibility reports without sending page content to external AI services.',
            ],
            'openai' => [
                'id' => 'openai',
                'name' => 'OpenAI-compatible structured analysis provider',
                'configured' => getenv('OPENAI_API_KEY') !== false && trim((string) getenv('OPENAI_API_KEY')) !== '',
                'requires_network' => true,
                'requires_api_key' => true,
                'env' => ['OPENAI_API_KEY', 'OPENAI_MODEL'],
                'capabilities' => ['structured_outputs', 'site_analysis', 'field_suggestion', 'crawl_plan_review'],
                'notes' => 'Optional. Use only with authorized content and enterprise privacy review. The bundled command defaults to rule-based analysis unless AI execution is explicitly wired by an adapter.',
            ],
            'local_ml' => [
                'id' => 'local_ml',
                'name' => 'Local ML / PHP-ML style provider',
                'configured' => class_exists('Phpml\\Classification\\KNearestNeighbors') || class_exists('Phpml\\FeatureExtraction\\TokenCountVectorizer'),
                'requires_network' => false,
                'requires_api_key' => false,
                'env' => [],
                'capabilities' => ['offline_classification', 'feature_scoring'],
                'notes' => 'Optional local inference/training provider. v1.0.2 exposes the provider slot; production models can be added without changing crawler commands.',
            ],
            'ollama' => [
                'id' => 'ollama',
                'name' => 'Local Ollama-compatible provider',
                'configured' => getenv('OLLAMA_HOST') !== false && trim((string) getenv('OLLAMA_HOST')) !== '',
                'requires_network' => true,
                'requires_api_key' => false,
                'env' => ['OLLAMA_HOST', 'OLLAMA_MODEL'],
                'capabilities' => ['local_llm_review', 'recipe_suggestion'],
                'notes' => 'Optional local LLM integration point. Not invoked by default; use only on machines where local model serving is approved.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function summary(): array
    {
        $providers = self::providers();
        return [
            'ai_version' => self::VERSION,
            'default_provider' => 'rule_based',
            'providers_total' => count($providers),
            'configured_total' => count(array_filter($providers, static fn(array $p): bool => (bool) ($p['configured'] ?? false))),
            'providers' => array_values($providers),
            'policy' => [
                'external_ai_disabled_by_default' => true,
                'do_not_send_private_or_paywalled_content_without_approval' => true,
                'prefer_metadata_and_public_signals' => true,
                'store_audit_decisions' => true,
            ],
        ];
    }
}
