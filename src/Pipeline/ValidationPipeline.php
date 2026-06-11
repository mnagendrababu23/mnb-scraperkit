<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Pipeline;

final class ValidationPipeline
{
    /**
     * @param array<string,mixed> $record
     * @return array<int,array<string,string>>
     */
    public function validate(array $record, PipelineOptions $options): array
    {
        $issues = [];
        foreach ($options->requiredFields as $field) {
            if (!$this->hasValue($record[$field] ?? ($record['fields'][$field] ?? null))) {
                $issues[] = ['field' => $field, 'rule' => 'required', 'message' => 'Required field is missing or empty.'];
            }
        }

        foreach ($options->validators as $field => $rule) {
            $value = $record[$field] ?? ($record['fields'][$field] ?? null);
            $this->validateExplicit((string) $field, (string) $rule, $value, $issues);
        }

        foreach ($record as $field => $value) {
            if ($field === 'fields' && is_array($value)) {
                foreach ($value as $nestedField => $nestedValue) {
                    $this->validateField((string) $nestedField, $nestedValue, $issues);
                }
                continue;
            }
            $this->validateField((string) $field, $value, $issues);
        }

        return $this->uniqueIssues($issues);
    }


    /** @param array<int,array<string,string>> $issues */
    private function validateExplicit(string $field, string $rule, mixed $value, array &$issues): void
    {
        $rule = strtolower(trim($rule));
        if ($rule === 'required') {
            if (!$this->hasValue($value)) {
                $issues[] = ['field' => $field, 'rule' => 'required', 'message' => 'Required field is missing or empty.'];
            }
            return;
        }
        if (!$this->hasValue($value)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateExplicit($field, $rule, $item, $issues);
            }
            return;
        }
        if (!is_scalar($value)) {
            return;
        }
        $value = trim((string) $value);
        match ($rule) {
            'url' => filter_var($value, FILTER_VALIDATE_URL) ? null : $issues[] = ['field' => $field, 'rule' => 'url', 'message' => 'Value is not a valid URL.'],
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : $issues[] = ['field' => $field, 'rule' => 'email', 'message' => 'Value is not a valid email.'],
            'phone' => $this->validPhone($value) ? null : $issues[] = ['field' => $field, 'rule' => 'phone', 'message' => 'Value does not look like a valid phone number.'],
            'doi' => $this->validDoi($value) ? null : $issues[] = ['field' => $field, 'rule' => 'doi', 'message' => 'Value is not a valid DOI pattern.'],
            'issn' => $this->validIssn($value) ? null : $issues[] = ['field' => $field, 'rule' => 'issn', 'message' => 'Value is not a valid ISSN.'],
            'isbn' => $this->validIsbn($value) ? null : $issues[] = ['field' => $field, 'rule' => 'isbn', 'message' => 'Value is not a valid ISBN.'],
            'date' => strtotime($value) !== false ? null : $issues[] = ['field' => $field, 'rule' => 'date', 'message' => 'Value is not a parseable date.'],
            'price', 'number' => preg_match('/[-+]?\d+(?:\.\d+)?/', str_replace([',', ' '], '', $value)) === 1 ? null : $issues[] = ['field' => $field, 'rule' => $rule, 'message' => 'Value is not a parseable number/amount.'],
            default => null,
        };
    }

    /** @param array<int,array<string,string>> $issues */
    private function validateField(string $field, mixed $value, array &$issues): void
    {
        if (!$this->hasValue($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateField($field, $item, $issues);
            }
            return;
        }

        if (!is_scalar($value)) {
            return;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        if ($this->looksLikeUrlField($field) && !filter_var($value, FILTER_VALIDATE_URL)) {
            $issues[] = ['field' => $field, 'rule' => 'url', 'message' => 'Value is not a valid URL.'];
        }
        if ($this->looksLikeEmailField($field) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $issues[] = ['field' => $field, 'rule' => 'email', 'message' => 'Value is not a valid email.'];
        }
        if ($this->looksLikePhoneField($field) && !$this->validPhone($value)) {
            $issues[] = ['field' => $field, 'rule' => 'phone', 'message' => 'Value does not look like a valid phone number.'];
        }
        if ($this->looksLikeDoiField($field) && !$this->validDoi($value)) {
            $issues[] = ['field' => $field, 'rule' => 'doi', 'message' => 'Value is not a valid DOI pattern.'];
        }
        if ($this->looksLikeIssnField($field) && !$this->validIssn($value)) {
            $issues[] = ['field' => $field, 'rule' => 'issn', 'message' => 'Value is not a valid ISSN.'];
        }
        if ($this->looksLikeIsbnField($field) && !$this->validIsbn($value)) {
            $issues[] = ['field' => $field, 'rule' => 'isbn', 'message' => 'Value is not a valid ISBN.'];
        }
        if ($this->looksLikeDateField($field) && strtotime($value) === false) {
            $issues[] = ['field' => $field, 'rule' => 'date', 'message' => 'Value is not a parseable date.'];
        }
        if ($this->looksLikePriceField($field) && preg_match('/[-+]?\d+(?:\.\d+)?/', str_replace([',', ' '], '', $value)) !== 1) {
            $issues[] = ['field' => $field, 'rule' => 'price', 'message' => 'Value is not a parseable price/amount.'];
        }
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }

    private function looksLikeUrlField(string $field): bool
    {
        return str_ends_with($field, '_url') || str_ends_with($field, '_link') || in_array($field, ['url', 'canonical_url', 'final_url', 'source_url'], true);
    }

    private function looksLikeEmailField(string $field): bool
    {
        return str_contains($field, 'email') || in_array($field, ['emails'], true);
    }

    private function looksLikePhoneField(string $field): bool
    {
        return str_contains($field, 'phone') || str_contains($field, 'mobile') || str_contains($field, 'fax');
    }

    private function looksLikeDoiField(string $field): bool
    {
        return $field === 'doi' || str_ends_with($field, '_doi');
    }

    private function looksLikeIssnField(string $field): bool
    {
        return str_contains($field, 'issn');
    }

    private function looksLikeIsbnField(string $field): bool
    {
        return str_contains($field, 'isbn');
    }

    private function looksLikeDateField(string $field): bool
    {
        return str_contains($field, 'date') || str_contains($field, 'deadline');
    }

    private function looksLikePriceField(string $field): bool
    {
        return str_contains($field, 'price') || str_contains($field, 'fee') || str_contains($field, 'amount');
    }

    private function validPhone(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return strlen($digits) >= 7 && strlen($digits) <= 16;
    }

    private function validDoi(string $value): bool
    {
        return preg_match('~^10\.\d{4,9}/\S+$~i', $value) === 1;
    }

    private function validIssn(string $value): bool
    {
        $value = strtoupper(str_replace('-', '', trim($value)));
        if (preg_match('/^\d{7}[0-9X]$/', $value) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $digit = $value[$i] === 'X' ? 10 : (int) $value[$i];
            $sum += $digit * (8 - $i);
        }
        return $sum % 11 === 0;
    }

    private function validIsbn(string $value): bool
    {
        $v = strtoupper(preg_replace('/[^0-9X]/', '', $value) ?? '');
        if (strlen($v) === 10) {
            $sum = 0;
            for ($i = 0; $i < 10; $i++) {
                $digit = $v[$i] === 'X' ? 10 : (int) $v[$i];
                $sum += $digit * (10 - $i);
            }
            return $sum % 11 === 0;
        }
        if (strlen($v) === 13 && ctype_digit($v)) {
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $v[$i] * ($i % 2 === 0 ? 1 : 3);
            }
            $check = (10 - ($sum % 10)) % 10;
            return $check === (int) $v[12];
        }
        return false;
    }

    /** @param array<int,array<string,string>> $issues @return array<int,array<string,string>> */
    private function uniqueIssues(array $issues): array
    {
        $seen = [];
        $out = [];
        foreach ($issues as $issue) {
            $key = implode('|', [$issue['field'] ?? '', $issue['rule'] ?? '', $issue['message'] ?? '']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $issue;
            }
        }
        return $out;
    }
}
