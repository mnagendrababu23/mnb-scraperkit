<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\RuleBuilder;

use Mnb\ScraperKit\Extractor\RuleBasedExtractor;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Profile\ProfileSchema;
use Mnb\ScraperKit\Profile\ProfileSchemaValidator;
use Mnb\ScraperKit\Support\UrlNormalizer;

final class ProfileRuleDoctor
{
    /** @return array<string,mixed> */
    public function inspect(ProfileSchema $schema, ?string $html = null, string $baseUrl = ''): array
    {
        $data = $schema->toArray();
        $issues = (new ProfileSchemaValidator())->validateArray($data);
        $warnings = [];
        foreach ($schema->requiredFields as $field) {
            if (!array_key_exists($field, $schema->extractionRules)) {
                $warnings[] = ['field' => $field, 'rule' => 'missing_rule', 'message' => 'Required field has no extraction rule.'];
            }
        }
        foreach ($schema->extractionRules as $field => $rule) {
            if (!in_array($field, array_merge($schema->requiredFields, $schema->optionalFields), true)) {
                $warnings[] = ['field' => (string) $field, 'rule' => 'undeclared_field', 'message' => 'Extraction rule field is not declared as required or optional.'];
            }
            if (is_array($rule) && isset($rule['fallback']) && is_array($rule['fallback']) && $rule['fallback'] === []) {
                $warnings[] = ['field' => (string) $field, 'rule' => 'empty_fallback', 'message' => 'Fallback list is empty.'];
            }
        }

        $extracted = [];
        $missingRequired = [];
        if ($html !== null) {
            $parser = new HtmlParser();
            $doc = $parser->load($html, $baseUrl);
            $extracted = (new RuleBasedExtractor($parser, new UrlNormalizer()))->extract($doc, $schema->extractionRules, $baseUrl);
            foreach ($schema->requiredFields as $field) {
                if (!array_key_exists($field, $extracted) || $extracted[$field] === '' || $extracted[$field] === []) {
                    $missingRequired[] = $field;
                }
            }
        }

        return [
            'doctor_version' => '4.0.0',
            'profile' => $schema->profile,
            'record_type' => $schema->recordType,
            'valid_schema' => $issues === [],
            'issues' => $issues,
            'warnings' => $warnings,
            'rules_total' => count($schema->extractionRules),
            'required_fields_total' => count($schema->requiredFields),
            'missing_required_on_sample' => $missingRequired,
            'extracted_fields' => $extracted,
            'status' => ($issues === [] && $warnings === [] && $missingRequired === []) ? 'ok' : 'needs_attention',
        ];
    }
}
