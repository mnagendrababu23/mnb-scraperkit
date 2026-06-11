<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\RuleBuilder;

use Mnb\ScraperKit\Extractor\RuleBasedExtractor;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Profile\ProfileSchema;
use Mnb\ScraperKit\Support\UrlNormalizer;

final class RuleBuilderService
{
    /** @return array<string,mixed> */
    public function analyze(string $html, string $baseUrl = ''): array
    {
        $signals = (new HtmlSignalAnalyzer())->analyze($html, $baseUrl);
        $signals['assistant'] = (new AutoProfileAssistant())->suggest($signals);
        return $signals;
    }

    /** @return array<string,mixed> */
    public function generateSchema(string $html, string $baseUrl = '', string $profile = 'auto', ?string $name = null): array
    {
        $signals = (new HtmlSignalAnalyzer())->analyze($html, $baseUrl);
        return (new AutoProfileAssistant())->buildSchema($signals, $profile, $name);
    }

    /** @param array<string,mixed> $rules @return array<string,mixed> */
    public function testRules(string $html, array $rules, string $baseUrl = ''): array
    {
        $parser = new HtmlParser();
        $doc = $parser->load($html, $baseUrl);
        $fields = (new RuleBasedExtractor($parser, new UrlNormalizer()))->extract($doc, $rules, $baseUrl);
        return [
            'test_version' => '3.6.0',
            'base_url' => $baseUrl,
            'rules_total' => count($rules),
            'fields_total' => count($fields),
            'fields' => $fields,
            'empty_fields' => array_values(array_diff(array_keys($rules), array_keys($fields))),
        ];
    }

    /** @return array<string,mixed> */
    public function doctor(ProfileSchema $schema, ?string $html = null, string $baseUrl = ''): array
    {
        return (new ProfileRuleDoctor())->inspect($schema, $html, $baseUrl);
    }
}
