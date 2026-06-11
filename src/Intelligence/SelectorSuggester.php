<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Intelligence;

final class SelectorSuggester
{
    /** @return array<string,mixed> */
    public function suggestFromHtml(string $html, string $profile = 'seo'): array
    {
        $profile = strtolower(trim($profile) ?: 'seo');
        $suggestions = [
            'title' => $this->firstMatchingSelectors($html, ['h1', 'meta[property="og:title"]', 'title']),
            'description' => $this->firstMatchingSelectors($html, ['meta[name="description"]', 'meta[property="og:description"]']),
            'canonical_url' => $this->firstMatchingSelectors($html, ['link[rel="canonical"]']),
        ];

        if (in_array($profile, ['ecommerce', 'product'], true)) {
            $suggestions += [
                'price' => $this->classContainsSuggestions($html, ['price', 'amount', 'sale']),
                'sku' => $this->classContainsSuggestions($html, ['sku', 'product-id']),
                'availability' => $this->classContainsSuggestions($html, ['stock', 'availability']),
            ];
        } elseif (in_array($profile, ['jobs', 'job'], true)) {
            $suggestions += [
                'job_title' => $this->classContainsSuggestions($html, ['job-title', 'position', 'role']),
                'company' => $this->classContainsSuggestions($html, ['company', 'employer']),
                'location' => $this->classContainsSuggestions($html, ['location', 'city']),
            ];
        } elseif (in_array($profile, ['academic', 'journal', 'research-paper'], true)) {
            $suggestions += [
                'authors' => $this->classContainsSuggestions($html, ['author', 'creator']),
                'doi' => $this->classContainsSuggestions($html, ['doi']),
                'abstract' => $this->classContainsSuggestions($html, ['abstract', 'summary']),
            ];
        } elseif (in_array($profile, ['tender', 'government'], true)) {
            $suggestions += [
                'tender_number' => $this->classContainsSuggestions($html, ['tender', 'notice', 'bid']),
                'deadline' => $this->classContainsSuggestions($html, ['deadline', 'closing', 'date']),
            ];
        }

        return [
            'intelligence_version' => '4.0.1',
            'generated_at' => date(DATE_ATOM),
            'profile' => $profile,
            'suggestions' => $suggestions,
            'json_ld_present' => preg_match('/application\/ld\+json/i', $html) === 1,
            'opengraph_present' => preg_match('/property=["\']og:/i', $html) === 1,
        ];
    }

    /** @param array<int,string> $selectors @return array<int,array<string,mixed>> */
    private function firstMatchingSelectors(string $html, array $selectors): array
    {
        $out = [];
        foreach ($selectors as $selector) {
            $present = match ($selector) {
                'h1' => preg_match('/<h1\b/i', $html) === 1,
                'title' => preg_match('/<title\b/i', $html) === 1,
                'meta[name="description"]' => preg_match('/<meta[^>]+name=["\']description["\']/i', $html) === 1,
                'meta[property="og:title"]' => preg_match('/<meta[^>]+property=["\']og:title["\']/i', $html) === 1,
                'meta[property="og:description"]' => preg_match('/<meta[^>]+property=["\']og:description["\']/i', $html) === 1,
                'link[rel="canonical"]' => preg_match('/<link[^>]+rel=["\']canonical["\']/i', $html) === 1,
                default => false,
            };
            $out[] = ['selector' => $selector, 'present' => $present, 'confidence' => $present ? 0.8 : 0.35];
        }
        return $out;
    }

    /** @param array<int,string> $needles @return array<int,array<string,mixed>> */
    private function classContainsSuggestions(string $html, array $needles): array
    {
        $out = [];
        foreach ($needles as $needle) {
            $needleQuoted = preg_quote($needle, '/');
            $present = preg_match('/<(?:[^>]+)(?:class|id)=["\'][^"\']*' . $needleQuoted . '[^"\']*["\']/i', $html) === 1;
            $out[] = [
                'selector' => '[class*="' . $needle . '"], [id*="' . $needle . '"]',
                'present' => $present,
                'confidence' => $present ? 0.7 : 0.3,
            ];
        }
        return $out;
    }
}
