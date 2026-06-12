<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\RuleBuilder;

/**
 * Creates starter profile schemas from page signals without requiring users to hand-write JSON first.
 */
final class AutoProfileAssistant
{
    /** @param array<string,mixed> $signals @return array<string,mixed> */
    public function suggest(array $signals): array
    {
        $scores = (array) ($signals['keywords'] ?? []);
        arsort($scores);
        $profile = (string) (array_key_first($scores) ?: 'seo');
        if (($scores[$profile] ?? 0) <= 0) {
            $profile = 'seo';
        }
        return [
            'assistant_version' => '1.0.2',
            'suggested_profile' => $profile,
            'confidence' => $this->confidence($scores, $profile),
            'scores' => $scores,
            'reasons' => $this->reasons($signals, $profile),
        ];
    }

    /** @param array<string,mixed> $signals @return array<string,mixed> */
    public function buildSchema(array $signals, string $profile = 'auto', ?string $name = null): array
    {
        $suggestion = $this->suggest($signals);
        $profile = strtolower(trim($profile));
        if ($profile === '' || $profile === 'auto') {
            $profile = (string) $suggestion['suggested_profile'];
        }
        $name = $name ?: 'auto-' . $profile;
        $template = $this->template($profile);
        $template['profile'] = $name;
        $template['generated_by'] = 'mnb-scraperkit-rule-builder';
        $template['generated_at'] = date(DATE_ATOM);
        $template['source_profile_hint'] = $profile;
        $template['assistant'] = $suggestion;
        $template['extraction_rules'] = $this->mergeInferredRules((array) ($template['extraction_rules'] ?? []), (array) ($signals['candidate_selectors'] ?? []));
        return $template;
    }

    /** @param array<string,int> $scores */
    private function confidence(array $scores, string $profile): float
    {
        $top = (int) ($scores[$profile] ?? 0);
        $total = max(1, array_sum(array_map('intval', $scores)));
        return round(min(0.99, max(0.25, $top / $total)), 2);
    }

    /** @param array<string,mixed> $signals @return array<int,string> */
    private function reasons(array $signals, string $profile): array
    {
        $reasons = [];
        $scores = (array) ($signals['keywords'] ?? []);
        if (($scores[$profile] ?? 0) > 0) {
            $reasons[] = 'Page text, metadata, JSON-LD, or selectors contain ' . $profile . ' signals.';
        }
        $types = array_map('strtolower', (array) ($signals['json_ld_types'] ?? []));
        if ($types !== []) {
            $reasons[] = 'Detected JSON-LD types: ' . implode(', ', array_slice($types, 0, 6));
        }
        $meta = (array) ($signals['meta'] ?? []);
        if (isset($meta['citation_title']) || isset($meta['citation_doi'])) {
            $reasons[] = 'Citation metadata was detected.';
        }
        if ($reasons === []) {
            $reasons[] = 'SEO/page profile is safest because no strong domain-specific signals were found.';
        }
        return $reasons;
    }

    /** @return array<string,mixed> */
    private function template(string $profile): array
    {
        return match ($profile) {
            'ecommerce' => [
                'profile' => 'auto-ecommerce',
                'record_type' => 'product',
                'required_fields' => ['title', 'price', 'url'],
                'optional_fields' => ['sku', 'brand', 'currency', 'availability', 'image_url', 'canonical_url'],
                'dedupe_keys' => ['sku', 'canonical_url', 'url', 'title'],
                'validators' => ['url' => 'url', 'price' => 'price', 'canonical_url' => 'url', 'image_url' => 'url'],
                'transformations' => ['title' => ['normalize_space'], 'price' => ['price'], 'url' => ['clean_url'], 'sku' => ['identifier_upper']],
                'export_columns' => ['record_id', 'title', 'price', 'currency', 'sku', 'brand', 'availability', 'url', 'quality_score'],
                'extraction_rules' => [
                    'title' => ['fallback' => [['css' => 'h1'], ['og' => 'title'], 'title']],
                    'price' => ['fallback' => [['css' => '.price'], ['css' => '[itemprop=price]', 'attr' => 'content'], ['text' => true, 'regex' => '(?:₹|Rs\\.?|USD|\\$)?\\s*([0-9][0-9,]*(?:\\.[0-9]{1,2})?)']]],
                    'currency' => ['fallback' => [['css' => '[itemprop=priceCurrency]', 'attr' => 'content'], ['text' => true, 'regex' => '(₹|Rs\\.?|USD|INR|\\$)']]],
                    'sku' => ['fallback' => [['css' => '[itemprop=sku]'], ['text' => true, 'regex' => 'SKU[:#\\s]+([A-Za-z0-9_-]+)']]],
                    'brand' => ['fallback' => [['css' => '[itemprop=brand]'], ['css' => '.brand']]],
                    'availability' => ['fallback' => [['css' => '[itemprop=availability]', 'attr' => 'content'], ['css' => '.availability']]],
                ],
            ],
            'jobs' => [
                'profile' => 'auto-jobs',
                'record_type' => 'job',
                'required_fields' => ['title', 'company', 'url'],
                'optional_fields' => ['location', 'salary', 'experience', 'skills', 'apply_url', 'deadline'],
                'dedupe_keys' => ['apply_url', 'url', 'title', 'company'],
                'validators' => ['url' => 'url', 'apply_url' => 'url', 'deadline' => 'date'],
                'transformations' => ['title' => ['normalize_space'], 'company' => ['normalize_space'], 'apply_url' => ['clean_url'], 'deadline' => ['date']],
                'export_columns' => ['record_id', 'title', 'company', 'location', 'salary', 'apply_url', 'deadline', 'quality_score'],
                'extraction_rules' => [
                    'title' => ['fallback' => [['css' => 'h1'], ['og' => 'title']]],
                    'company' => ['fallback' => [['css' => '.company'], ['css' => '[itemprop=hiringOrganization]']]],
                    'location' => ['fallback' => [['css' => '.location'], ['css' => '[itemprop=jobLocation]']]],
                    'salary' => ['fallback' => [['css' => '.salary'], ['text' => true, 'regex' => '((?:₹|Rs\\.?|USD|\\$)\\s*[0-9][0-9,]*(?:\\s*-\\s*(?:₹|Rs\\.?|USD|\\$)?\\s*[0-9][0-9,]*)?)']]],
                    'apply_url' => ['fallback' => [['css' => 'a.apply', 'attr' => 'href', 'url' => true], ['css' => 'a[href*=apply]', 'attr' => 'href', 'url' => true]]],
                ],
            ],
            'tender' => [
                'profile' => 'auto-tender',
                'record_type' => 'tender',
                'required_fields' => ['title', 'tender_number', 'url'],
                'optional_fields' => ['department', 'deadline', 'fee', 'document_url', 'contact_email'],
                'dedupe_keys' => ['tender_number', 'document_url', 'url'],
                'validators' => ['url' => 'url', 'document_url' => 'url', 'contact_email' => 'email', 'deadline' => 'date', 'fee' => 'price'],
                'transformations' => ['title' => ['normalize_space'], 'tender_number' => ['identifier_upper'], 'deadline' => ['date'], 'fee' => ['price']],
                'export_columns' => ['record_id', 'title', 'tender_number', 'department', 'deadline', 'fee', 'document_url', 'quality_score'],
                'extraction_rules' => [
                    'title' => ['fallback' => [['css' => 'h1'], ['og' => 'title']]],
                    'tender_number' => ['fallback' => [['css' => '.tender-number'], ['text' => true, 'regex' => '(?:Tender|Notice)\\s*(?:No\\.?|Number|#)[:\\s]+([A-Za-z0-9/-]+)']]],
                    'deadline' => ['fallback' => [['css' => '.deadline'], ['text' => true, 'regex' => '(?:deadline|last date)[:\\s]+([A-Za-z0-9, -]+)']]],
                    'document_url' => ['fallback' => [['css' => 'a[href$=.pdf]', 'attr' => 'href', 'url' => true], ['css' => 'a[href*=download]', 'attr' => 'href', 'url' => true]]],
                ],
            ],
            'academic' => [
                'profile' => 'auto-academic',
                'record_type' => 'article',
                'required_fields' => ['title', 'url'],
                'optional_fields' => ['doi', 'authors', 'journal', 'published_date', 'pdf_url', 'issn'],
                'dedupe_keys' => ['doi', 'canonical_url', 'url', 'title'],
                'validators' => ['url' => 'url', 'doi' => 'doi', 'issn' => 'issn', 'published_date' => 'date', 'pdf_url' => 'url'],
                'transformations' => ['title' => ['normalize_space'], 'doi' => ['lowercase'], 'published_date' => ['date'], 'pdf_url' => ['clean_url']],
                'export_columns' => ['record_id', 'title', 'doi', 'authors', 'journal', 'published_date', 'pdf_url', 'url', 'quality_score'],
                'extraction_rules' => [
                    'title' => ['fallback' => [['meta' => 'citation_title'], ['og' => 'title'], 'h1']],
                    'doi' => ['fallback' => [['meta' => 'citation_doi'], ['text' => true, 'regex' => '(10\\.[0-9]{4,9}/[^\\s]+)']]],
                    'authors' => ['css' => 'meta[name=citation_author]', 'attr' => 'content', 'many' => true],
                    'journal' => ['fallback' => [['meta' => 'citation_journal_title'], ['meta' => 'citation_conference_title']]],
                    'published_date' => ['fallback' => [['meta' => 'citation_publication_date'], ['meta' => 'citation_online_date']]],
                    'pdf_url' => ['css' => 'meta[name=citation_pdf_url]', 'attr' => 'content', 'url' => true],
                ],
            ],
            default => [
                'profile' => 'auto-seo',
                'record_type' => 'seo_record',
                'required_fields' => ['title', 'url'],
                'optional_fields' => ['description', 'canonical_url', 'h1', 'robots', 'og_title', 'og_description'],
                'dedupe_keys' => ['canonical_url', 'url', 'title'],
                'validators' => ['url' => 'url', 'canonical_url' => 'url'],
                'transformations' => ['title' => ['normalize_space'], 'description' => ['normalize_space'], 'canonical_url' => ['clean_url']],
                'export_columns' => ['record_id', 'title', 'description', 'canonical_url', 'h1', 'robots', 'quality_score'],
                'extraction_rules' => [
                    'title' => ['fallback' => [['og' => 'title'], 'title']],
                    'description' => ['fallback' => [['meta' => 'description'], ['og' => 'description']]],
                    'canonical_url' => ['css' => 'link[rel=canonical]', 'attr' => 'href', 'url' => true],
                    'robots' => ['meta' => 'robots'],
                    'h1' => ['css' => 'h1', 'many' => true],
                    'og_title' => ['og' => 'title'],
                    'og_description' => ['og' => 'description'],
                ],
            ],
        };
    }

    /** @param array<string,mixed> $rules @param array<string,array<int,array<string,mixed>>> $selectors @return array<string,mixed> */
    private function mergeInferredRules(array $rules, array $selectors): array
    {
        foreach ($selectors as $field => $items) {
            if (!is_string($field) || !is_array($items) || $items === []) {
                continue;
            }
            $fallbacks = [];
            foreach (array_slice($items, 0, 3) as $item) {
                $selector = (string) ($item['selector'] ?? '');
                if ($selector !== '') {
                    $fallbacks[] = ['css' => $selector];
                }
            }
            if ($fallbacks === []) {
                continue;
            }
            if (isset($rules[$field]) && is_array($rules[$field]) && isset($rules[$field]['fallback']) && is_array($rules[$field]['fallback'])) {
                $rules[$field]['fallback'] = array_values(array_merge($fallbacks, $rules[$field]['fallback']));
            } elseif (isset($rules[$field])) {
                $rules[$field] = ['fallback' => array_values(array_merge($fallbacks, [$rules[$field]]))];
            } else {
                $rules[$field] = ['fallback' => $fallbacks];
            }
        }
        return $rules;
    }
}
