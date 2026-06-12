<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extractor;

use DOMElement;
use Mnb\ScraperKit\Parser\HtmlDocument;
use Mnb\ScraperKit\Parser\HtmlParser;
use Mnb\ScraperKit\Support\UrlNormalizer;

final class CommonDataExtractor
{
    private HtmlParser $parser;
    private UrlNormalizer $normalizer;

    /** @var array<string,array<int,string>> */
    private array $profiles = [
        'academic' => [
            'authors', 'editors', 'reviewers', 'affiliations', 'organizations', 'doi', 'issns', 'isbns', 'orcids',
            'dates', 'deadlines', 'pdf_links', 'document_links', 'submission_links', 'submission_guidelines',
            'journal_metrics', 'article_metadata', 'seo_metadata', 'structured_data', 'status_terms',
        ],
        'journal' => [
            'authors', 'editors', 'reviewers', 'affiliations', 'organizations', 'doi', 'issns', 'isbns', 'orcids',
            'dates', 'deadlines', 'pdf_links', 'submission_links', 'submission_guidelines', 'journal_metrics',
            'article_metadata', 'emails', 'seo_metadata', 'structured_data', 'status_terms',
        ],
        'conference' => [
            'event_data', 'dates', 'times', 'deadlines', 'person_names', 'organizations', 'addresses', 'locations',
            'emails', 'phones', 'price_data', 'submission_links', 'document_links', 'pdf_links', 'social_links',
        ],
        'education' => [
            'education_data', 'dates', 'deadlines', 'document_links', 'pdf_links', 'application_numbers',
            'person_names', 'organizations', 'addresses', 'emails', 'phones', 'price_data', 'status_terms',
        ],
        'ecommerce' => [
            'product_data', 'price_data', 'status_terms', 'image_links', 'document_links', 'seo_metadata', 'structured_data',
        ],
        'government' => [
            'registration_numbers', 'license_numbers', 'gst_numbers', 'pan_numbers', 'cin_numbers', 'tender_numbers',
            'application_numbers', 'dates', 'deadlines', 'document_links', 'pdf_links', 'addresses', 'emails', 'phones',
            'organizations', 'status_terms',
        ],
        'tender' => [
            'tender_data', 'tender_numbers', 'dates', 'deadlines', 'price_data', 'document_links', 'pdf_links',
            'organizations', 'addresses', 'emails', 'phones', 'status_terms',
        ],
        'jobs' => [
            'job_data', 'dates', 'deadlines', 'price_data', 'organizations', 'addresses', 'locations', 'emails',
            'phones', 'document_links', 'application_numbers', 'status_terms',
        ],
        'seo' => [
            'seo_metadata', 'structured_data', 'api_links', 'social_links', 'document_links', 'image_links', 'dates', 'status_terms',
        ],
        'contact_directory' => [
            'emails', 'phones', 'fax_numbers', 'person_names', 'organizations', 'addresses', 'locations', 'postal_codes',
            'countries', 'social_links', 'document_links', 'seo_metadata',
        ],
        'all' => ['all'],
    ];

    public function __construct(?HtmlParser $parser = null, ?UrlNormalizer $normalizer = null)
    {
        $this->parser = $parser ?? new HtmlParser();
        $this->normalizer = $normalizer ?? new UrlNormalizer();
    }

    /** @return array<int,string> */
    public static function supportedTypes(): array
    {
        return [
            'dates', 'times', 'deadlines',
            'emails', 'phones', 'fax_numbers',
            'person_names', 'editors', 'authors', 'reviewers',
            'affiliations', 'organizations', 'addresses', 'locations', 'postal_codes', 'countries',
            'social_links', 'pdf_links', 'document_links', 'image_links',
            'doi', 'issns', 'isbns', 'orcids',
            'registration_numbers', 'license_numbers', 'gst_numbers', 'pan_numbers', 'cin_numbers', 'tender_numbers', 'application_numbers',
            'submission_links', 'submission_guidelines',
            'journal_metrics', 'article_metadata',
            'product_data', 'price_data', 'event_data', 'education_data', 'job_data', 'tender_data',
            'seo_metadata', 'structured_data', 'api_links', 'status_terms',
        ];
    }

    /** @return array<int,string> */
    public static function supportedProfiles(): array
    {
        return ['academic', 'journal', 'conference', 'education', 'ecommerce', 'government', 'tender', 'jobs', 'seo', 'contact_directory', 'all'];
    }

    /**
     * @param array<int,string> $types Common data types. Supports aliases and comma-separated values.
     * @return array<string,mixed>
     */
    public function extract(HtmlDocument $doc, string $baseUrl, array $types = ['all'], ?string $profile = null): array
    {
        $profile = $this->normalizeProfile($profile);
        $types = $this->normalizeTypes($types, $profile);
        $text = $this->parser->text($doc);
        $lines = $this->meaningfulLines($text);

        $result = [
            'extractor' => 'common-data',
            'schema_version' => '0.7',
            'profile' => $profile,
            'enabled_types' => $types,
        ];

        if ($this->enabled($types, 'dates')) {
            $result['dates'] = $this->extractDates($text);
        }
        if ($this->enabled($types, 'times')) {
            $result['times'] = $this->extractTimes($text);
        }
        if ($this->enabled($types, 'deadlines')) {
            $result['deadlines'] = $this->extractContextLines($lines, ['deadline', 'due date', 'last date', 'closing date', 'submission deadline', 'apply by', 'valid until', 'expires']);
        }
        if ($this->enabled($types, 'emails')) {
            $result['emails'] = $this->extractEmails($text);
        }
        if ($this->enabled($types, 'phones')) {
            $result['phones'] = $this->extractPhones($text);
        }
        if ($this->enabled($types, 'fax_numbers')) {
            $result['fax_numbers'] = $this->extractContextPhones($lines, ['fax']);
        }
        if ($this->enabled($types, 'person_names')) {
            $result['person_names'] = $this->extractPersonNames($text);
        }
        if ($this->enabled($types, 'editors')) {
            $result['editors'] = $this->extractContextPeople($lines, ['editor', 'editors', 'editor-in-chief', 'editorial board', 'guest editor']);
        }
        if ($this->enabled($types, 'authors')) {
            $result['authors'] = $this->extractContextPeople($lines, ['author', 'authors', 'written by', 'by ']);
        }
        if ($this->enabled($types, 'reviewers')) {
            $result['reviewers'] = $this->extractContextPeople($lines, ['reviewer', 'reviewers', 'review board', 'peer reviewer']);
        }
        if ($this->enabled($types, 'affiliations')) {
            $result['affiliations'] = $this->extractAffiliations($lines);
        }
        if ($this->enabled($types, 'organizations')) {
            $result['organizations'] = $this->extractOrganizations($lines);
        }
        if ($this->enabled($types, 'addresses')) {
            $result['addresses'] = $this->extractAddresses($lines);
        }
        if ($this->enabled($types, 'locations')) {
            $result['locations'] = $this->extractLocations($lines);
        }
        if ($this->enabled($types, 'postal_codes')) {
            $result['postal_codes'] = $this->extractPostalCodes($text);
        }
        if ($this->enabled($types, 'countries')) {
            $result['countries'] = $this->extractCountries($text);
        }
        if ($this->enabled($types, 'social_links')) {
            $result['social_links'] = $this->extractSocialLinks($doc, $baseUrl);
        }
        if ($this->enabled($types, 'pdf_links')) {
            $result['pdf_links'] = $this->extractFileLinks($doc, $baseUrl, ['pdf']);
        }
        if ($this->enabled($types, 'document_links')) {
            $result['document_links'] = $this->extractDocumentLinks($doc, $baseUrl);
        }
        if ($this->enabled($types, 'image_links')) {
            $result['image_links'] = $this->extractImageLinks($doc, $baseUrl);
        }
        if ($this->enabled($types, 'doi')) {
            $result['doi'] = $this->extractDoi($text, $doc);
        }
        if ($this->enabled($types, 'issns')) {
            $result['issns'] = $this->extractIssns($text);
        }
        if ($this->enabled($types, 'isbns')) {
            $result['isbns'] = $this->extractIsbns($text);
        }
        if ($this->enabled($types, 'orcids')) {
            $result['orcids'] = $this->extractOrcids($text, $doc);
        }
        if ($this->enabled($types, 'registration_numbers')) {
            $result['registration_numbers'] = $this->extractRegisterNumbers($text);
            $result['register_numbers'] = $result['registration_numbers']; // backward-compatible alias
        }
        if ($this->enabled($types, 'license_numbers')) {
            $result['license_numbers'] = $this->extractLabeledNumbers($text, ['license', 'licence', 'lic no', 'license no', 'licence no', 'certificate']);
        }
        if ($this->enabled($types, 'gst_numbers')) {
            $result['gst_numbers'] = $this->extractGstNumbers($text);
        }
        if ($this->enabled($types, 'pan_numbers')) {
            $result['pan_numbers'] = $this->extractPanNumbers($text);
        }
        if ($this->enabled($types, 'cin_numbers')) {
            $result['cin_numbers'] = $this->extractCinNumbers($text);
        }
        if ($this->enabled($types, 'tender_numbers')) {
            $result['tender_numbers'] = $this->extractLabeledNumbers($text, ['tender', 'bid', 'rfp', 'rfq', 'eoi']);
        }
        if ($this->enabled($types, 'application_numbers')) {
            $result['application_numbers'] = $this->extractLabeledNumbers($text, ['application', 'application no', 'application number', 'roll', 'hall ticket', 'admit card']);
        }
        if ($this->enabled($types, 'submission_links')) {
            $result['submission_links'] = $this->extractKeywordLinks($doc, $baseUrl, ['submit', 'submission', 'manuscript', 'apply', 'register', 'application']);
        }
        if ($this->enabled($types, 'submission_guidelines')) {
            $result['submission_guidelines'] = $this->extractContextLines($lines, ['submission', 'submit', 'manuscript', 'author guidelines', 'instructions for authors', 'call for papers', 'cfp', 'article processing charge', 'apc']);
        }
        if ($this->enabled($types, 'journal_metrics')) {
            $result['journal_metrics'] = $this->extractJournalMetrics($lines);
        }
        if ($this->enabled($types, 'article_metadata')) {
            $result['article_metadata'] = $this->extractArticleMetadata($doc, $text, $baseUrl);
        }
        if ($this->enabled($types, 'product_data')) {
            $result['product_data'] = $this->extractProductData($doc, $text, $baseUrl);
        }
        if ($this->enabled($types, 'price_data')) {
            $result['price_data'] = $this->extractPrices($text);
        }
        if ($this->enabled($types, 'event_data')) {
            $result['event_data'] = $this->extractEventData($lines, $doc, $baseUrl);
        }
        if ($this->enabled($types, 'education_data')) {
            $result['education_data'] = $this->extractEducationData($lines, $doc, $baseUrl);
        }
        if ($this->enabled($types, 'job_data')) {
            $result['job_data'] = $this->extractJobData($lines, $doc, $baseUrl);
        }
        if ($this->enabled($types, 'tender_data')) {
            $result['tender_data'] = $this->extractTenderData($lines, $doc, $baseUrl);
        }
        if ($this->enabled($types, 'seo_metadata')) {
            $result['seo_metadata'] = $this->extractSeoMetadata($doc, $baseUrl);
        }
        if ($this->enabled($types, 'structured_data')) {
            $result['structured_data'] = $this->extractStructuredData($doc);
        }
        if ($this->enabled($types, 'api_links')) {
            $result['api_links'] = $this->extractApiLinks($doc, $baseUrl);
        }
        if ($this->enabled($types, 'status_terms')) {
            $result['status_terms'] = $this->extractStatusTerms($lines);
        }

        // Backward-compatible broad name candidates from V0.6.
        // Keep this separate from person_names: pages such as publisher A-Z indexes may contain
        // journal/book/organization names that are useful as generic names but are not humans.
        if (isset($result['person_names']) && !isset($result['names'])) {
            $result['names'] = $this->extractNameCandidates($text);
        }

        $result['model'] = $this->buildModel($result);
        $result['counts'] = $this->counts($result);

        return $result;
    }

    private function normalizeProfile(?string $profile): ?string
    {
        if ($profile === null || trim($profile) === '') {
            return null;
        }
        $profile = strtolower(trim($profile));
        $profile = str_replace(['-', ' '], '_', $profile);
        $aliases = [
            'contact-directory' => 'contact_directory',
            'directory' => 'contact_directory',
            'contacts' => 'contact_directory',
            'job' => 'jobs',
            'career' => 'jobs',
            'careers' => 'jobs',
            'gov' => 'government',
            'product' => 'ecommerce',
            'shop' => 'ecommerce',
        ];
        $profile = $aliases[$profile] ?? $profile;
        return isset($this->profiles[$profile]) ? $profile : null;
    }

    /** @param array<int,string> $types @return array<int,string> */
    private function normalizeTypes(array $types, ?string $profile = null): array
    {
        $aliases = [
            'email' => 'emails',
            'e-mails' => 'emails',
            'e_mail' => 'emails',
            'phone' => 'phones',
            'phone_numbers' => 'phones',
            'telephone' => 'phones',
            'mobile' => 'phones',
            'mobile_numbers' => 'phones',
            'fax' => 'fax_numbers',
            'fax_number' => 'fax_numbers',
            'name' => 'person_names',
            'names' => 'person_names',
            'person' => 'person_names',
            'people' => 'person_names',
            'user_names' => 'person_names',
            'users_names' => 'person_names',
            'usernames' => 'person_names',
            'editor' => 'editors',
            'author' => 'authors',
            'reviewer' => 'reviewers',
            'affiliation' => 'affiliations',
            'org' => 'organizations',
            'organization' => 'organizations',
            'company' => 'organizations',
            'institution' => 'organizations',
            'address' => 'addresses',
            'location' => 'locations',
            'postal_code' => 'postal_codes',
            'pin' => 'postal_codes',
            'pincode' => 'postal_codes',
            'pin_code' => 'postal_codes',
            'country' => 'countries',
            'social' => 'social_links',
            'social_profiles' => 'social_links',
            'pdf' => 'pdf_links',
            'pdfs' => 'pdf_links',
            'pdf-links' => 'pdf_links',
            'document' => 'document_links',
            'documents' => 'document_links',
            'files' => 'document_links',
            'image' => 'image_links',
            'images' => 'image_links',
            'issn' => 'issns',
            'isbn' => 'isbns',
            'orcid' => 'orcids',
            'dois' => 'doi',
            'register' => 'registration_numbers',
            'register_numbers' => 'registration_numbers',
            'registration' => 'registration_numbers',
            'reg_no' => 'registration_numbers',
            'license' => 'license_numbers',
            'license_number' => 'license_numbers',
            'licence' => 'license_numbers',
            'gst' => 'gst_numbers',
            'gstin' => 'gst_numbers',
            'pan' => 'pan_numbers',
            'cin' => 'cin_numbers',
            'tender' => 'tender_numbers',
            'tender_number' => 'tender_numbers',
            'application' => 'application_numbers',
            'application_number' => 'application_numbers',
            'deadline' => 'deadlines',
            'submission' => 'submission_links',
            'submissions' => 'submission_links',
            'submission_guideline' => 'submission_guidelines',
            'guidelines' => 'submission_guidelines',
            'journal' => 'journal_metrics',
            'metrics' => 'journal_metrics',
            'article' => 'article_metadata',
            'article_meta' => 'article_metadata',
            'product' => 'product_data',
            'price' => 'price_data',
            'prices' => 'price_data',
            'event' => 'event_data',
            'education' => 'education_data',
            'job' => 'job_data',
            'jobs' => 'job_data',
            'tenders' => 'tender_data',
            'seo' => 'seo_metadata',
            'metadata' => 'seo_metadata',
            'structured' => 'structured_data',
            'jsonld' => 'structured_data',
            'json_ld' => 'structured_data',
            'api' => 'api_links',
            'apis' => 'api_links',
            'status' => 'status_terms',
        ];

        $out = [];
        if ($profile !== null && isset($this->profiles[$profile])) {
            $out = array_merge($out, $this->profiles[$profile]);
        }

        foreach ($types as $type) {
            foreach (explode(',', (string) $type) as $item) {
                $item = strtolower(trim($item));
                $item = str_replace(['-', ' '], '_', $item);
                if ($item === '' || $item === 'profile') {
                    continue;
                }
                $out[] = $aliases[$item] ?? $item;
            }
        }

        $out = array_values(array_unique($out));
        if ($out === []) {
            return ['all'];
        }
        if (in_array('all', $out, true)) {
            return ['all'];
        }

        $supported = array_flip(self::supportedTypes());
        return array_values(array_filter($out, static fn (string $type): bool => isset($supported[$type])));
    }

    /** @param array<int,string> $types */
    private function enabled(array $types, string $type): bool
    {
        return in_array('all', $types, true) || in_array($type, $types, true);
    }

    /** @return array<int,string> */
    private function meaningfulLines(string $text): array
    {
        $lines = preg_split('/\R+/u', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = $this->cleanText($line);
            if ($line !== '' && strlen($line) >= 4) {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,array<string,mixed>> */
    private function extractDates(string $text): array
    {
        $patterns = [
            '~\b(?:\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4})\b~u',
            '~\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},?\s+\d{4}\b~iu',
            '~\b\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{4}\b~iu',
            '~\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{4}\b~iu',
        ];

        $items = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[0] ?? [] as $value) {
                $value = $this->cleanText($value);
                if ($value === '') {
                    continue;
                }
                $items[$value] = [
                    'value' => $value,
                    'normalized' => $this->normalizeDate($value),
                ];
            }
        }

        return array_values($items);
    }

    private function normalizeDate(string $date): ?string
    {
        $timestamp = strtotime($date);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /** @return array<int,string> */
    private function extractTimes(string $text): array
    {
        preg_match_all('/\b(?:[01]?\d|2[0-3]):[0-5]\d(?:\s?(?:AM|PM))?\b|\b(?:1[0-2]|0?[1-9])(?:\.[0-5]\d|:[0-5]\d)?\s?(?:AM|PM)\b/iu', $text, $matches);
        return $this->uniqueClean($matches[0] ?? [], 100);
    }

    /** @param array<int,string> $lines @param array<int,string> $keywords @return array<int,array<string,mixed>> */
    private function extractContextPeople(array $lines, array $keywords): array
    {
        $items = [];
        foreach ($this->contextLines($lines, $keywords) as $line) {
            $names = $this->extractPersonNames($line, 8);
            $items[] = [
                'line' => $line,
                'names' => array_column($names, 'value'),
            ];
        }
        return $items;
    }

    /**
     * Extract likely human names only.
     *
     * This intentionally uses conservative validation. Generic title/name candidates such as
     * journal names are exposed through the backward-compatible `names` field, not here.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractPersonNames(string $text, int $limit = 80): array
    {
        $items = [];
        preg_match_all('/\b(?:Prof\.?|Dr\.?|Mr\.?|Ms\.?|Mrs\.?)?\s*((?:[A-Z][\p{L}\'’.-]{1,40}|[A-Z]\.)\s+(?:[A-Z][\p{L}\'’.-]{1,40}|[A-Z]\.)(?:\s+(?:[A-Z][\p{L}\'’.-]{1,40}|[A-Z]\.)){0,2})\b/u', $text, $matches);
        foreach ($matches[1] ?? [] as $name) {
            $name = $this->cleanText($name);
            if ($name === '' || !$this->looksLikeHumanName($name)) {
                continue;
            }
            $items[$name] = ['value' => $name];
            if (count($items) >= $limit) {
                break;
            }
        }
        return array_values($items);
    }

    /**
     * Broad title/name candidates kept for backward compatibility and page index use-cases.
     * These can include journal titles, product names, organizations, and other proper-name phrases.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractNameCandidates(string $text, int $limit = 80): array
    {
        $items = [];
        preg_match_all('/\b((?:[A-Z][\p{L}\'’.-]{1,40}|[A-Z]\.)\s+(?:[A-Z][\p{L}\'’.-]{1,40}|[A-Z]\.)(?:\s+(?:[A-Z][\p{L}\'’.-]{1,40}|[A-Z]\.)){0,4})\b/u', $text, $matches);
        foreach ($matches[1] ?? [] as $name) {
            $name = $this->cleanText($name);
            if ($name === '' || $this->looksLikeUiNoise($name)) {
                continue;
            }
            $items[$name] = ['value' => $name];
            if (count($items) >= $limit) {
                break;
            }
        }
        return array_values($items);
    }

    private function looksLikeHumanName(string $name): bool
    {
        $name = $this->cleanText($name);
        if ($name === '' || $this->looksLikeUiNoise($name) || $this->looksLikeNonPersonName($name)) {
            return false;
        }
        if (preg_match('~[0-9_@#:/\\\\]|&~u', $name)) {
            return false;
        }

        $tokens = preg_split('/\s+/u', trim($name)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
        $count = count($tokens);
        if ($count < 2 || $count > 4) {
            return false;
        }

        $particles = ['bin', 'binti', 'da', 'de', 'del', 'der', 'di', 'dos', 'du', 'el', 'ibn', 'la', 'le', 'van', 'von', 'y'];
        $longNameTokens = 0;
        $initialTokens = 0;
        foreach ($tokens as $token) {
            $clean = trim($token, " .'-’");
            if ($clean === '') {
                return false;
            }
            if (in_array(strtolower($clean), $particles, true)) {
                continue;
            }
            if (preg_match('/^[A-Z]\.$/u', $token)) {
                $initialTokens++;
                continue;
            }
            if (strtoupper($clean) === $clean && strlen($clean) > 1) {
                return false;
            }
            if (!preg_match('/^\p{Lu}[\p{L}\'’.-]{1,40}$/u', $token)) {
                return false;
            }
            $longNameTokens++;
        }

        if ($longNameTokens < 2 && $initialTokens === 0) {
            return false;
        }

        return $this->hasHumanNameShape($tokens);
    }

    /** @param array<int,string> $tokens */
    private function hasHumanNameShape(array $tokens): bool
    {
        $lower = array_map(static fn (string $token): string => strtolower(trim($token, " .'-’")), $tokens);
        $joined = implode(' ', $lower);

        // Reject phrases that look like adjacent catalog/list titles rather than one person.
        if (preg_match('/\b(?:journal|journals|annals|annales|annali|acta|advanced|advances|international|technology|engineering|mathematics|physics|chemistry|biology|medicine|surgery|urology|law|review|reports|transactions|proceedings|bulletin|letters|series|next|previous|over)\b/u', $joined)) {
            return false;
        }

        // Many catalog false positives are two or more long discipline/title words.
        $longGenericCount = 0;
        $genericSuffixes = ['ology', 'ics', 'tion', 'sion', 'ment', 'ence', 'ance', 'istry', 'graphy', 'metry', 'nomy', 'tomy', 'scopy'];
        foreach ($lower as $token) {
            foreach ($genericSuffixes as $suffix) {
                if (strlen($token) >= 8 && str_ends_with($token, $suffix)) {
                    $longGenericCount++;
                    break;
                }
            }
        }
        if ($longGenericCount >= 2) {
            return false;
        }

        return true;
    }

    private function looksLikeNonPersonName(string $name): bool
    {
        $badExact = [
            'Springer Nature', 'Open Access', 'Privacy Policy', 'Privacy Statement', 'Terms Conditions',
            'Google Scholar', 'Article Processing', 'Call For', 'Editor In', 'Editorial Board', 'Home Page',
            'Journals A-Z', 'Books A-Z', 'Footer Navigation', 'Site Navigation', 'Search Journals',
        ];
        foreach ($badExact as $item) {
            if (strcasecmp($name, $item) === 0) {
                return true;
            }
        }

        $nonPersonTerms = [
            'about', 'abstract', 'academic', 'acta', 'applicandae', 'applicatae', 'access', 'accessibility', 'accounting', 'advances', 'aerospace',
            'agriculture', 'algorithm', 'analysis', 'applications', 'archive', 'article', 'artificial', 'astronomy',
            'behavior', 'biochemistry', 'biology', 'biomedical', 'books', 'browse', 'bulletin', 'business', 'cancer',
            'cardiology', 'cell', 'chemistry', 'child', 'clinical', 'collection', 'computational', 'computer', 'conference',
            'cookie', 'data', 'dermatology', 'discover', 'download', 'education', 'engineering', 'ethics', 'footer',
            'forum', 'global', 'health', 'homepage', 'imaging', 'information', 'intelligence', 'journal', 'journals',
            'legal', 'letters', 'login', 'management', 'materials', 'mathematica', 'mathematical', 'mathematics',
            'medicine', 'menu', 'molecular', 'nature', 'navigation', 'neuro', 'oncology', 'open', 'pharmacology',
            'physics', 'policy', 'privacy', 'proceedings', 'published', 'publisher', 'reports', 'research', 'review',
            'reviews', 'rights', 'science', 'sciences', 'scientific', 'search', 'series', 'services', 'signaling',
            'society', 'springer', 'statement', 'studies', 'submission', 'sustainability', 'systems', 'technology',
            'therapy', 'transactions', 'university', 'volume', 'worldwide',
            'agenda', 'autotechnology', 'electronics', 'elektronik', 'extra', 'production', 'radiology', 'seminar',
            'metallurgica', 'sinica', 'physica', 'hungarica', 'academiae', 'scientiarum', 'activitas', 'nervosa',
            'superior', 'adaptive', 'biotechnology', 'composites', 'metamaterials', 'modeling', 'maritime',
            'aequationes', 'spazio', 'aesthetic', 'plastic', 'afrika', 'matematika', 'ageing', 'anesthesiology',
            'quality', 'aurikulomedizin', 'algebra', 'colloquium', 'logic', 'representation', 'theory', 'algorithmica',
            'algorithms', 'analog', 'integrated', 'circuits', 'palliativmedizin', 'angiogenesis', 'animal',
            'biotelemetry', 'cognition', 'diseases', 'microbiome', 'annalen', 'philosophie', 'kritik', 'geophysicae',
            'poincaré', 'québec', 'pura', 'antimicrobials', 'combinatorics', 'diagnostic', 'paediatric', 'pathology',
            'dyslexia', 'finance', 'psychiatry', 'geometry', 'hematology', 'intensive', 'care',
        ];

        foreach ($nonPersonTerms as $term) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $name)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeUiNoise(string $value): bool
    {
        return (bool) preg_match('/\b(?:privacy|cookie|cookies|accessibility|terms|legal|faq|contact us|footer|navigation|skip to|manage cookies|rights|statement)\b/i', $value);
    }

    /** @param array<int,string> $lines @return array<int,string> */
    private function extractAffiliations(array $lines): array
    {
        return $this->keywordLines($lines, [
            'university', 'college', 'institute', 'department', 'school of', 'faculty of', 'centre for', 'center for',
            'laboratory', 'lab ', 'hospital', 'academy', 'research center', 'research centre', 'cnrs', 'iit', 'iisc',
        ], 70, 280);
    }

    /** @param array<int,string> $lines @return array<int,string> */
    private function extractOrganizations(array $lines): array
    {
        return $this->keywordLines($lines, [
            'university', 'college', 'school', 'institute', 'department', 'ministry', 'government', 'publisher',
            'society', 'association', 'foundation', 'organization', 'organisation', 'company', 'corporation',
            'pvt', 'private limited', 'ltd', 'limited', 'inc', 'llc', 'gmbh', 'hospital', 'clinic', 'academy', 'lab',
        ], 80, 220);
    }

    /** @param array<int,string> $lines @return array<int|string> */
    private function extractAddresses(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (strlen($line) > 300 || !$this->isLikelyAddressLine($line)) {
                continue;
            }
            $items[$line] = $line;
            if (count($items) >= 80) {
                break;
            }
        }
        return array_values($items);
    }

    /** @param array<int,string> $lines @return array<int,string> */
    private function extractLocations(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (strlen($line) > 220 || !$this->isLikelyLocationLine($line)) {
                continue;
            }
            $items[$line] = $line;
            if (count($items) >= 60) {
                break;
            }
        }
        return array_values($items);
    }

    private function isLikelyAddressLine(string $line): bool
    {
        $line = $this->cleanText($line);
        if ($line === '' || $this->looksLikeUiNoise($line) || $this->looksLikeCatalogTitle($line)) {
            return false;
        }

        $strongAddressTerms = [
            'road', 'rd', 'street', 'st', 'avenue', 'ave', 'lane', 'ln', 'district', 'zip', 'postal', 'pincode',
            'pin code', 'address', 'building', 'floor', 'block', 'suite', 'campus', 'post office', 'p.o.', 'po box',
        ];
        $hasStrongTerm = $this->containsAnyKeyword($line, $strongAddressTerms);
        $hasNumber = (bool) preg_match('/\b\d{1,6}(?:[-\/]\d{1,6})?\b/u', $line);
        $hasPostal = (bool) preg_match('/\b\d{5}(?:-\d{4})?\b|\b\d{6}\b/u', $line);
        $hasCommaPlace = substr_count($line, ',') >= 1 && $this->containsAnyKeyword($line, ['city', 'state', 'country', 'india', 'usa', 'united states', 'united kingdom', 'germany', 'france', 'china', 'japan', 'australia', 'canada', 'singapore']);

        return $hasPostal || ($hasStrongTerm && ($hasNumber || $hasCommaPlace || $this->containsAnyKeyword($line, ['address', 'campus', 'post office', 'po box'])));
    }

    private function isLikelyLocationLine(string $line): bool
    {
        $line = $this->cleanText($line);
        if ($line === '' || $this->looksLikeUiNoise($line) || $this->looksLikeCatalogTitle($line)) {
            return false;
        }

        if ($this->containsAnyKeyword($line, ['venue', 'headquarters', 'branch office', 'registered office', 'campus'])) {
            return true;
        }

        // Location lines normally have structure such as "Hyderabad, Telangana, India".
        if (substr_count($line, ',') >= 1 && $this->containsAnyKeyword($line, ['india', 'usa', 'united states', 'united kingdom', 'germany', 'france', 'china', 'japan', 'australia', 'canada', 'singapore', 'netherlands', 'italy', 'spain', 'brazil', 'south korea'])) {
            return true;
        }

        return false;
    }

    private function looksLikeCatalogTitle(string $line): bool
    {
        if (preg_match('/\b(?:journal|journals|book|books|bulletin|review|reports|transactions|advances|research|therapy|mathematics|physics|chemistry|biology|medicine|botany|privacy|rights|statement)\b/i', $line)) {
            return true;
        }
        // Short title-case phrases such as "Alpine Botany" are names/titles, not addresses.
        $words = preg_split('/\s+/u', trim($line)) ?: [];
        if (count($words) <= 4 && !preg_match('/[,0-9]/u', $line)) {
            return true;
        }
        return false;
    }

    /** @return array<int,string> */
    private function extractPostalCodes(string $text): array
    {
        $items = [];
        preg_match_all('/\b(?:PIN|Pincode|Postal Code|ZIP)?\s*:?[\s-]*(\d{6}|\d{5}(?:-\d{4})?)\b/i', $text, $matches);
        foreach ($matches[1] ?? [] as $code) {
            $items[$code] = $code;
        }
        return array_values($items);
    }

    /** @return array<int,string> */
    private function extractCountries(string $text): array
    {
        $countries = ['India', 'United States', 'USA', 'United Kingdom', 'UK', 'Germany', 'France', 'China', 'Japan', 'Australia', 'Canada', 'Singapore', 'Netherlands', 'Italy', 'Spain', 'Brazil', 'South Korea', 'Russia'];
        $items = [];
        foreach ($countries as $country) {
            if (preg_match('/\b' . preg_quote($country, '/') . '\b/i', $text)) {
                $items[$country] = $country;
            }
        }
        return array_values($items);
    }

    /** @return array<int,string> */
    private function extractEmails(string $text): array
    {
        $text = str_replace(['[at]', '(at)', ' at ', '[dot]', '(dot)', ' dot '], ['@', '@', '@', '.', '.', '.'], $text);
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);
        return $this->uniqueClean($matches[0] ?? [], 150);
    }

    /** @return array<int,string> */
    private function extractPhones(string $text): array
    {
        preg_match_all('/(?<![\p{L}\d])(?:\+?\d{1,3}[\s.-]?)?(?:\(?\d{2,5}\)?[\s.-]?)?\d{3,5}[\s.-]?\d{3,5}(?![\p{L}\d])/u', $text, $matches);
        $items = [];
        foreach ($matches[0] ?? [] as $phone) {
            $phone = trim($phone);
            if (!$this->isValidPhoneCandidate($phone)) {
                continue;
            }
            $items[$phone] = $phone;
            if (count($items) >= 100) {
                break;
            }
        }
        return array_values($items);
    }

    private function isValidPhoneCandidate(string $phone): bool
    {
        $phone = trim($phone);
        if ($phone === '') {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digitCount = strlen($digits);

        if ($digitCount < 10 || $digitCount > 15) {
            return false;
        }

        // Reject historical/year ranges and ISSN-like values, e.g. 1858-1865 or 1234-567X.
        if (preg_match('/^(?:1[5-9]\d{2}|20\d{2})\s*[-–—]\s*(?:1[5-9]\d{2}|20\d{2})$/u', $phone)) {
            return false;
        }
        if (preg_match('/^\d{4}[-–—]\d{3}[0-9Xx]$/u', $phone)) {
            return false;
        }

        // Reject dates and dotted IP addresses accidentally collected as phone numbers.
        if (preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}$/u', $phone)) {
            return false;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/u', $phone)) {
            return false;
        }

        // Require phone-like formatting for unlabelled numbers: country code, parentheses,
        // spaces, or separators. Long plain digit strings are often IDs; keep only sane lengths.
        $hasPhoneFormatting = str_contains($phone, '+') || str_contains($phone, '(') || preg_match('/[\s.-]/u', $phone);
        if (!$hasPhoneFormatting && !in_array($digitCount, [10, 11, 12], true)) {
            return false;
        }

        // Reject repeated/suspicious numeric sequences.
        if (preg_match('/^(\d)\1{9,}$/', $digits)) {
            return false;
        }

        return true;
    }

    /** @param array<int,string> $lines @param array<int,string> $keywords @return array<int,string> */
    private function extractContextPhones(array $lines, array $keywords): array
    {
        $phones = [];
        foreach ($this->contextLines($lines, $keywords, 30) as $line) {
            foreach ($this->extractPhones($line) as $phone) {
                $phones[$phone] = $phone;
            }
        }
        return array_values($phones);
    }

    /** @return array<int,string> */
    private function extractDoi(string $text, HtmlDocument $doc): array
    {
        $items = [];
        preg_match_all('~\b10\.\d{4,9}/[-._;()/:A-Z0-9]+\b~i', $text, $matches);
        foreach ($matches[0] ?? [] as $doi) {
            $doi = rtrim($doi, '.,;:)');
            $items[$doi] = $doi;
        }
        foreach ($this->metaValues($doc, ['citation_doi', 'dc.identifier', 'dc.identifier.doi']) as $doi) {
            if (preg_match('~10\.\d{4,9}/\S+~i', $doi, $m)) {
                $items[rtrim($m[0], '.,;:)')] = rtrim($m[0], '.,;:)');
            }
        }
        return array_values($items);
    }

    /** @return array<int,string> */
    private function extractIssns(string $text): array
    {
        preg_match_all('/\b(?:ISSN|E-ISSN|Online ISSN|Print ISSN)?\s*:?\s*([0-9]{4}-[0-9]{3}[0-9Xx])\b/u', $text, $matches);
        return $this->uniqueClean($matches[1] ?? [], 100);
    }

    /** @return array<int,string> */
    private function extractIsbns(string $text): array
    {
        preg_match_all('/\b(?:ISBN(?:-1[03])?)?\s*:?\s*((?:97[89][-\s]?)?[0-9][-0-9\s]{8,20}[0-9Xx])\b/u', $text, $matches);
        $items = [];
        foreach ($matches[1] ?? [] as $isbn) {
            $clean = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
            if (in_array(strlen($clean), [10, 13], true)) {
                $items[$clean] = $clean;
            }
        }
        return array_values($items);
    }

    /** @return array<int,string> */
    private function extractOrcids(string $text, HtmlDocument $doc): array
    {
        $items = [];
        preg_match_all('/\b\d{4}-\d{4}-\d{4}-\d{3}[0-9X]\b/i', $text, $matches);
        foreach ($matches[0] ?? [] as $value) {
            $items[$value] = $value;
        }
        foreach ($this->extractLinksMatching($doc, '', ['orcid.org']) as $link) {
            if (preg_match('/\d{4}-\d{4}-\d{4}-\d{3}[0-9X]/i', $link['url'], $m)) {
                $items[$m[0]] = $m[0];
            }
        }
        return array_values($items);
    }

    /** @return array<int,array<string,string>> */
    private function extractRegisterNumbers(string $text): array
    {
        $patterns = [
            '/\b(?:registration|register|reg\.?|certificate|license|licence|cin|gstin|doi|orcid)(?![\p{L}])\s*(?:no\.?|number|id)?\s*[:#-]?\s*([A-Z0-9][A-Z0-9.\/-]{4,50})\b/iu',
            '/\b(?:NCT|ISRCTN|EUCTR)[\s:-]*([A-Z0-9\/-]{4,40})\b/iu',
        ];
        return $this->extractByPatterns($text, $patterns);
    }

    /** @param array<int,string> $labels @return array<int,array<string,string>> */
    private function extractLabeledNumbers(string $text, array $labels): array
    {
        $label = implode('|', array_map(static fn (string $v): string => preg_quote($v, '/'), $labels));
        return $this->extractByPatterns($text, ['/\b(?:' . $label . ')(?![\p{L}])\s*(?:no\.?|number|id|ref)?\s*[:#-]?\s*([A-Z0-9][A-Z0-9.\/-]{3,60})\b/iu']);
    }

    /** @param array<int,string> $patterns @return array<int,array<string,string>> */
    private function extractByPatterns(string $text, array $patterns): array
    {
        $items = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[0] ?? [] as $i => $full) {
                $value = $matches[1][$i] ?? $full;
                $value = $this->cleanText($value);
                $context = $this->cleanText($full);
                if ($value === '' || !$this->isValidLabeledIdentifier($value, $context)) {
                    continue;
                }
                $items[$value] = ['value' => $value, 'context' => $context];
            }
        }
        return array_values($items);
    }

    private function isValidLabeledIdentifier(string $value, string $context): bool
    {
        if (strlen($value) < 4 || strlen($value) > 80) {
            return false;
        }
        if ($this->looksLikeUiNoise($value) || $this->looksLikeCatalogTitle($value)) {
            return false;
        }
        // IDs/application/registration numbers should have numeric or formal identifier structure.
        if (!preg_match('/\d/u', $value) && !preg_match('/[\/-]/u', $value)) {
            return false;
        }
        if (preg_match('/^(?:form|forms|open|access|applied|applicable|applicandae|applicatae|regeneration|privacy|statement)$/iu', $value)) {
            return false;
        }
        return true;
    }

    /** @return array<int,string> */
    private function extractGstNumbers(string $text): array
    {
        preg_match_all('/\b\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]\b/i', $text, $matches);
        return $this->uniqueClean(array_map('strtoupper', $matches[0] ?? []), 50);
    }

    /** @return array<int,string> */
    private function extractPanNumbers(string $text): array
    {
        preg_match_all('/\b[A-Z]{5}\d{4}[A-Z]\b/i', $text, $matches);
        return $this->uniqueClean(array_map('strtoupper', $matches[0] ?? []), 50);
    }

    /** @return array<int,string> */
    private function extractCinNumbers(string $text): array
    {
        preg_match_all('/\b[UL]\d{5}[A-Z]{2}\d{4}[A-Z]{3}\d{6}\b/i', $text, $matches);
        return $this->uniqueClean(array_map('strtoupper', $matches[0] ?? []), 50);
    }

    /** @return array<int,array<string,string|null>> */
    private function extractDocumentLinks(HtmlDocument $doc, string $baseUrl): array
    {
        return $this->extractFileLinks($doc, $baseUrl, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'ppt', 'pptx']);
    }

    /** @param array<int,string> $extensions @return array<int,array<string,string|null>> */
    private function extractFileLinks(HtmlDocument $doc, string $baseUrl, array $extensions): array
    {
        $items = [];
        $seen = [];
        foreach ($doc->xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            $text = $this->cleanText($node->textContent);
            $url = $this->normalizer->normalize($href, $baseUrl);
            if (!$url) {
                continue;
            }
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $looksMatched = in_array($ext, $extensions, true);
            if (!$looksMatched) {
                foreach ($extensions as $extension) {
                    if (str_contains(strtolower($href), $extension) || str_contains(strtolower($text), $extension)) {
                        $looksMatched = true;
                        $ext = $ext !== '' ? $ext : $extension;
                        break;
                    }
                }
            }
            if (!$looksMatched || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $items[] = ['text' => $text !== '' ? $text : null, 'url' => $url, 'extension' => $ext ?: null];
        }
        return $items;
    }

    /** @return array<int,array<string,string|null>> */
    private function extractImageLinks(HtmlDocument $doc, string $baseUrl): array
    {
        $items = [];
        $seen = [];
        foreach ($doc->xpath->query('//img[@src]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $url = $this->normalizer->normalize(trim($node->getAttribute('src')), $baseUrl);
            if (!$url || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $items[] = [
                'url' => $url,
                'alt' => $this->cleanText($node->getAttribute('alt')) ?: null,
                'width' => $node->getAttribute('width') ?: null,
                'height' => $node->getAttribute('height') ?: null,
            ];
        }
        return $items;
    }

    /** @param array<int,string> $keywords @return array<int,array<string,string|null>> */
    private function extractKeywordLinks(HtmlDocument $doc, string $baseUrl, array $keywords): array
    {
        $items = [];
        $seen = [];
        foreach ($doc->xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $text = $this->cleanText($node->textContent);
            $href = trim($node->getAttribute('href'));
            $haystack = strtolower($text . ' ' . $href);
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, strtolower($keyword))) {
                    $url = $this->normalizer->normalize($href, $baseUrl);
                    if ($url && !isset($seen[$url])) {
                        $seen[$url] = true;
                        $items[] = ['text' => $text !== '' ? $text : null, 'url' => $url, 'keyword' => $keyword];
                    }
                    break;
                }
            }
        }
        return $items;
    }

    /** @return array<int,array<string,string|null>> */
    private function extractSocialLinks(HtmlDocument $doc, string $baseUrl): array
    {
        $platforms = ['facebook.com' => 'facebook', 'twitter.com' => 'twitter', 'x.com' => 'x', 'linkedin.com' => 'linkedin', 'instagram.com' => 'instagram', 'youtube.com' => 'youtube', 'github.com' => 'github', 'orcid.org' => 'orcid', 'scholar.google' => 'google_scholar', 'researchgate.net' => 'researchgate'];
        $items = [];
        $seen = [];
        foreach ($doc->xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $url = $this->normalizer->normalize(trim($node->getAttribute('href')), $baseUrl);
            if (!$url || isset($seen[$url])) {
                continue;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            foreach ($platforms as $domain => $platform) {
                if (str_contains($host, $domain)) {
                    $seen[$url] = true;
                    $items[] = ['platform' => $platform, 'url' => $url, 'text' => $this->cleanText($node->textContent) ?: null];
                    break;
                }
            }
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function extractSeoMetadata(HtmlDocument $doc, string $baseUrl): array
    {
        $meta = [
            'title' => $this->parser->title($doc),
            'description' => $this->parser->meta($doc, 'description'),
            'keywords' => $this->parser->meta($doc, 'keywords'),
            'robots' => $this->parser->meta($doc, 'robots'),
            'canonical' => $this->parser->canonical($doc, $baseUrl),
            'open_graph' => [],
            'twitter' => [],
            'feeds' => [],
            'hreflang' => [],
        ];

        foreach ($doc->xpath->query('//meta[@property or @name]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $key = strtolower($node->getAttribute('property') ?: $node->getAttribute('name'));
            $content = $node->getAttribute('content');
            if ($key === '' || $content === '') {
                continue;
            }
            if (str_starts_with($key, 'og:')) {
                $meta['open_graph'][$key] = $content;
            } elseif (str_starts_with($key, 'twitter:')) {
                $meta['twitter'][$key] = $content;
            }
        }

        foreach ($doc->xpath->query('//link[@rel]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $rel = strtolower($node->getAttribute('rel'));
            $href = $this->normalizer->normalize($node->getAttribute('href'), $baseUrl);
            if (!$href) {
                continue;
            }
            if (str_contains($rel, 'alternate') && str_contains(strtolower($node->getAttribute('type')), 'rss')) {
                $meta['feeds'][] = $href;
            }
            if ($node->hasAttribute('hreflang')) {
                $meta['hreflang'][] = ['lang' => $node->getAttribute('hreflang'), 'url' => $href];
            }
        }

        return $meta;
    }

    /** @return array<int,array<string,mixed>> */
    private function extractStructuredData(HtmlDocument $doc): array
    {
        $items = [];
        foreach ($doc->xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $json = trim($node->textContent ?? '');
            if ($json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            $items[] = [
                'type' => 'json-ld',
                'valid_json' => is_array($decoded),
                'data' => is_array($decoded) ? $decoded : null,
                'raw_length' => strlen($json),
            ];
        }
        foreach ($doc->xpath->query('//*[@itemscope]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $items[] = ['type' => 'microdata', 'itemtype' => $node->getAttribute('itemtype') ?: null];
            }
        }
        return $items;
    }

    /** @return array<int,array<string,string|null>> */
    private function extractApiLinks(HtmlDocument $doc, string $baseUrl): array
    {
        $items = [];
        $seen = [];
        $attributes = ['href', 'src', 'data-url', 'data-api', 'data-endpoint'];
        foreach ($doc->xpath->query('//*[@href or @src or @data-url or @data-api or @data-endpoint]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            foreach ($attributes as $attr) {
                if (!$node->hasAttribute($attr)) {
                    continue;
                }
                $raw = $node->getAttribute($attr);
                if (!preg_match('~(?:/api/|graphql|\.json\b|format=json|callback=|/ajax/)~i', $raw)) {
                    continue;
                }
                $url = $this->normalizer->normalize($raw, $baseUrl);
                if ($url && !isset($seen[$url])) {
                    $seen[$url] = true;
                    $items[] = ['url' => $url, 'source_attribute' => $attr];
                }
            }
        }
        return $items;
    }

    /** @param array<int,string> $lines @return array<int,array<string,string>> */
    private function extractJournalMetrics(array $lines): array
    {
        $keywords = ['impact factor', 'cite score', 'citescore', 'h-index', 'acceptance rate', 'review time', 'submission to first decision', 'apc', 'article processing charge', 'indexed in', 'scopus', 'web of science'];
        $items = [];
        foreach ($this->contextLines($lines, $keywords, 60) as $line) {
            $items[] = ['line' => $line];
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function extractArticleMetadata(HtmlDocument $doc, string $text, string $baseUrl): array
    {
        return [
            'title' => $this->firstNonEmpty($this->metaValues($doc, ['citation_title', 'dc.title'])) ?: $this->parser->title($doc),
            'authors' => $this->metaValues($doc, ['citation_author', 'dc.creator']),
            'published_dates' => $this->metaValues($doc, ['citation_publication_date', 'citation_online_date', 'dc.date']),
            'journal_title' => $this->firstNonEmpty($this->metaValues($doc, ['citation_journal_title'])) ,
            'volume' => $this->firstNonEmpty($this->metaValues($doc, ['citation_volume'])),
            'issue' => $this->firstNonEmpty($this->metaValues($doc, ['citation_issue'])),
            'first_page' => $this->firstNonEmpty($this->metaValues($doc, ['citation_firstpage'])),
            'last_page' => $this->firstNonEmpty($this->metaValues($doc, ['citation_lastpage'])),
            'doi' => $this->extractDoi($text, $doc),
            'pdf_links' => $this->extractFileLinks($doc, $baseUrl, ['pdf']),
        ];
    }

    /** @return array<string,mixed> */
    private function extractProductData(HtmlDocument $doc, string $text, string $baseUrl): array
    {
        return [
            'possible_names' => $this->uniqueClean(array_filter([$this->parser->title($doc)]), 10),
            'prices' => $this->extractPrices($text),
            'availability' => $this->extractStatusTerms($this->meaningfulLines($text), ['in stock', 'out of stock', 'available', 'unavailable', 'sold out']),
            'images' => $this->extractImageLinks($doc, $baseUrl),
        ];
    }

    /** @return array<int,array<string,string|null>> */
    private function extractPrices(string $text): array
    {
        $patterns = [
            '/(?<!\w)(₹|Rs\.?|INR|USD|US\$|\$|EUR|€|GBP|£)\s?\d{1,3}(?:[,\s]\d{2,3})*(?:\.\d{1,2})?/iu',
            '/\b\d{1,3}(?:[,\s]\d{2,3})*(?:\.\d{1,2})?\s?(?:INR|USD|EUR|GBP)\b/iu',
        ];
        $items = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[0] ?? [] as $value) {
                $value = $this->cleanText($value);
                $items[$value] = ['value' => $value, 'numeric' => $this->numericAmount($value), 'currency' => $this->currencyFromAmount($value)];
            }
        }
        return array_values($items);
    }

    private function numericAmount(string $value): ?float
    {
        $number = preg_replace('/[^0-9.]/', '', $value) ?? '';
        return $number === '' ? null : (float) $number;
    }

    private function currencyFromAmount(string $value): ?string
    {
        $upper = strtoupper($value);
        return match (true) {
            str_contains($upper, 'INR') || str_contains($value, '₹') || stripos($value, 'Rs') !== false => 'INR',
            str_contains($upper, 'USD') || str_contains($value, '$') => 'USD',
            str_contains($upper, 'EUR') || str_contains($value, '€') => 'EUR',
            str_contains($upper, 'GBP') || str_contains($value, '£') => 'GBP',
            default => null,
        };
    }

    /** @param array<int,string> $lines @return array<string,mixed> */
    private function extractEventData(array $lines, HtmlDocument $doc, string $baseUrl): array
    {
        return [
            'context_lines' => $this->contextLines($lines, ['conference', 'webinar', 'workshop', 'event', 'seminar', 'venue', 'agenda', 'speaker', 'organizer'], 60),
            'registration_links' => $this->extractKeywordLinks($doc, $baseUrl, ['register', 'registration', 'attend', 'book now']),
        ];
    }

    /** @param array<int,string> $lines @return array<string,mixed> */
    private function extractEducationData(array $lines, HtmlDocument $doc, string $baseUrl): array
    {
        return [
            'context_lines' => $this->contextLines($lines, ['course', 'syllabus', 'exam', 'result', 'admit card', 'hall ticket', 'eligibility', 'marks', 'grade', 'rank', 'cutoff'], 70),
            'document_links' => $this->extractKeywordLinks($doc, $baseUrl, ['syllabus', 'result', 'admit card', 'hall ticket', 'notification', 'prospectus']),
        ];
    }

    /** @param array<int,string> $lines @return array<string,mixed> */
    private function extractJobData(array $lines, HtmlDocument $doc, string $baseUrl): array
    {
        return [
            'context_lines' => $this->contextLines($lines, ['job', 'career', 'vacancy', 'salary', 'experience', 'qualification', 'remote', 'hybrid', 'apply'], 70),
            'apply_links' => $this->extractKeywordLinks($doc, $baseUrl, ['apply', 'career', 'job', 'vacancy']),
        ];
    }

    /** @param array<int,string> $lines @return array<string,mixed> */
    private function extractTenderData(array $lines, HtmlDocument $doc, string $baseUrl): array
    {
        return [
            'context_lines' => $this->contextLines($lines, ['tender', 'bid', 'rfp', 'rfq', 'eoi', 'emd', 'corrigendum', 'procurement', 'closing date'], 80),
            'document_links' => $this->extractKeywordLinks($doc, $baseUrl, ['tender', 'bid', 'rfp', 'rfq', 'corrigendum', 'document']),
        ];
    }

    /** @param array<int,string> $lines @param array<int,string>|null $terms @return array<int,array<string,string>> */
    private function extractStatusTerms(array $lines, ?array $terms = null): array
    {
        $terms ??= ['in stock', 'out of stock', 'available', 'unavailable', 'open', 'closed', 'active', 'inactive', 'published', 'withdrawn', 'accepted', 'rejected', 'upcoming', 'expired', 'deadline passed'];
        $items = [];
        foreach ($lines as $line) {
            $lower = strtolower($line);
            foreach ($terms as $term) {
                if (str_contains($lower, strtolower($term))) {
                    $items[] = ['term' => $term, 'line' => $line];
                    break;
                }
            }
            if (count($items) >= 80) {
                break;
            }
        }
        return $items;
    }

    /** @param array<int,string> $lines @param array<int,string> $keywords @return array<int,string> */
    private function extractContextLines(array $lines, array $keywords): array
    {
        return $this->contextLines($lines, $keywords, 50);
    }

    /** @param array<int,string> $lines @param array<int,string> $keywords @return array<int,string> */
    private function contextLines(array $lines, array $keywords, int $limit = 50): array
    {
        $items = [];
        foreach ($lines as $line) {
            $lower = strtolower($line);
            foreach ($keywords as $keyword) {
                if (str_contains($lower, strtolower($keyword))) {
                    $items[$line] = $line;
                    break;
                }
            }
            if (count($items) >= $limit) {
                break;
            }
        }
        return array_values($items);
    }

    /** @param array<int,string> $lines @param array<int,string> $keywords @return array<int,string> */
    private function keywordLines(array $lines, array $keywords, int $limit, int $maxLength): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (strlen($line) > $maxLength) {
                continue;
            }
            if ($this->containsAnyKeyword($line, $keywords)) {
                $items[$line] = $line;
            }
            if (count($items) >= $limit) {
                break;
            }
        }
        return array_values($items);
    }

    /** @param array<int,string> $keywords */
    private function containsAnyKeyword(string $line, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }
            $escaped = preg_quote($keyword, '/');
            if (preg_match('/(?<![\p{L}\p{N}])' . $escaped . '(?![\p{L}\p{N}])/iu', $line)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,string> $items @return array<int,string> */
    private function uniqueClean(array $items, int $limit): array
    {
        $out = [];
        foreach ($items as $item) {
            $item = $this->cleanText((string) $item);
            if ($item === '') {
                continue;
            }
            $out[$item] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }
        return array_values($out);
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /** @param array<int,string> $names @return array<int,string> */
    private function metaValues(HtmlDocument $doc, array $names): array
    {
        $items = [];
        foreach ($names as $name) {
            $lower = strtolower($name);
            $query = sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s" or translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $lower, $lower);
            foreach ($doc->xpath->query($query) ?: [] as $node) {
                $value = $this->cleanText($node->nodeValue ?? '');
                if ($value !== '') {
                    $items[$value] = $value;
                }
            }
        }
        return array_values($items);
    }

    /** @param array<int,string> $values */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $value = $this->cleanText((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /** @param array<int,string> $hostNeedles @return array<int,array<string,string|null>> */
    private function extractLinksMatching(HtmlDocument $doc, string $baseUrl, array $hostNeedles): array
    {
        $items = [];
        foreach ($doc->xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $url = $this->normalizer->normalize($node->getAttribute('href'), $baseUrl);
            if (!$url) {
                continue;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            foreach ($hostNeedles as $needle) {
                if (str_contains($host, strtolower($needle))) {
                    $items[] = ['url' => $url, 'text' => $this->cleanText($node->textContent) ?: null];
                    break;
                }
            }
        }
        return $items;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function buildModel(array $result): array
    {
        return [
            'contacts' => [
                'emails' => $result['emails'] ?? [],
                'phones' => $result['phones'] ?? [],
                'fax_numbers' => $result['fax_numbers'] ?? [],
            ],
            'people' => [
                'authors' => $result['authors'] ?? [],
                'editors' => $result['editors'] ?? [],
                'reviewers' => $result['reviewers'] ?? [],
                'person_names' => $result['person_names'] ?? [],
            ],
            'organizations' => [
                'organizations' => $result['organizations'] ?? [],
                'affiliations' => $result['affiliations'] ?? [],
            ],
            'locations' => [
                'addresses' => $result['addresses'] ?? [],
                'locations' => $result['locations'] ?? [],
                'postal_codes' => $result['postal_codes'] ?? [],
                'countries' => $result['countries'] ?? [],
            ],
            'academic' => [
                'doi' => $result['doi'] ?? [],
                'issns' => $result['issns'] ?? [],
                'isbns' => $result['isbns'] ?? [],
                'orcids' => $result['orcids'] ?? [],
                'journal_metrics' => $result['journal_metrics'] ?? [],
                'article_metadata' => $result['article_metadata'] ?? [],
            ],
            'dates' => [
                'dates' => $result['dates'] ?? [],
                'times' => $result['times'] ?? [],
                'deadlines' => $result['deadlines'] ?? [],
            ],
            'files' => [
                'pdf_links' => $result['pdf_links'] ?? [],
                'document_links' => $result['document_links'] ?? [],
                'image_links' => $result['image_links'] ?? [],
            ],
            'business' => [
                'registration_numbers' => $result['registration_numbers'] ?? [],
                'license_numbers' => $result['license_numbers'] ?? [],
                'gst_numbers' => $result['gst_numbers'] ?? [],
                'pan_numbers' => $result['pan_numbers'] ?? [],
                'cin_numbers' => $result['cin_numbers'] ?? [],
                'tender_numbers' => $result['tender_numbers'] ?? [],
                'application_numbers' => $result['application_numbers'] ?? [],
            ],
            'web' => [
                'seo_metadata' => $result['seo_metadata'] ?? [],
                'social_links' => $result['social_links'] ?? [],
                'structured_data' => $result['structured_data'] ?? [],
                'api_links' => $result['api_links'] ?? [],
                'status_terms' => $result['status_terms'] ?? [],
            ],
            'domain_models' => [
                'submissions' => [
                    'submission_links' => $result['submission_links'] ?? [],
                    'submission_guidelines' => $result['submission_guidelines'] ?? [],
                ],
                'product_data' => $result['product_data'] ?? [],
                'price_data' => $result['price_data'] ?? [],
                'event_data' => $result['event_data'] ?? [],
                'education_data' => $result['education_data'] ?? [],
                'job_data' => $result['job_data'] ?? [],
                'tender_data' => $result['tender_data'] ?? [],
            ],
        ];
    }

    /** @param array<string,mixed> $result @return array<string,int> */
    private function counts(array $result): array
    {
        $counts = [];
        foreach ($result as $key => $value) {
            if (in_array($key, ['extractor', 'schema_version', 'profile', 'enabled_types', 'counts', 'model'], true)) {
                continue;
            }
            if (is_array($value)) {
                $counts[$key] = $this->countValue($value);
            }
        }
        return $counts;
    }

    /** @param mixed $value */
    private function countValue(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }
        if ($value === []) {
            return 0;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return count($value);
        }
        $count = 0;
        foreach ($value as $child) {
            if (is_array($child)) {
                $count += $this->countValue($child);
            } elseif ($child !== null && $child !== '') {
                $count++;
            }
        }
        return $count;
    }
}
