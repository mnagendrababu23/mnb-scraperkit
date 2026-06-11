<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Parser;

use Mnb\ScraperKit\Support\UrlNormalizer;

final class CustomRuleExtractor
{
    public function __construct(private HtmlParser $parser, private UrlNormalizer $normalizer)
    {
    }

    /**
     * @param array<string,string> $rules
     * @return array<string,mixed>
     */
    public function extract(HtmlDocument $doc, array $rules, string $baseUrl): array
    {
        $output = [];
        foreach ($rules as $field => $rule) {
            [$selector, $attribute, $many] = $this->parseRule($rule);
            $values = $this->parser->select($doc, $selector, $attribute);
            if ($attribute && in_array($attribute, ['href', 'src'], true)) {
                $values = array_values(array_filter(array_map(fn (string $v): ?string => $this->normalizer->normalize($v, $baseUrl), $values)));
            }
            $output[$field] = $many ? $values : ($values[0] ?? null);
        }
        return $output;
    }

    /** @return array{0:string,1:?string,2:bool} */
    private function parseRule(string $rule): array
    {
        $many = false;
        if (str_ends_with($rule, '[]')) {
            $many = true;
            $rule = substr($rule, 0, -2);
        }

        $attribute = null;
        if (preg_match('/::attr\(([^)]+)\)$/', $rule, $match)) {
            $attribute = trim($match[1]);
            $rule = trim(substr($rule, 0, -strlen($match[0])));
        }

        return [$rule, $attribute, $many];
    }
}
