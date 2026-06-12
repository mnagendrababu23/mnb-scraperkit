<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Extraction;

final class ExtractionOptions
{
    public const TYPES = [
        'links',
        'text',
        'inner_html',
        'outer_html',
        'whole_html',
        'images',
        'pdfs',
        'tables',
        'lists',
        'headings',
        'navigation_links',
        'breadcrumbs',
        'social_links',
        'download_links',
        'bio',
        'cards',
        'components',
        'patterns',
        'dictionary',
    ];

    public function __construct(
        /** @var list<string> */
        public array $types = ['links', 'text', 'headings', 'components'],
        public ?string $selector = null,
        public int $minRepeats = 2,
        public bool $includeImages = true,
        public bool $includeHtml = false,
        public bool $saveWholeHtml = false,
        public ?string $dictionaryFile = null,
        public ?string $patternsFile = null,
        public ?string $mappingsFile = null,
    ) {
        $this->types = self::normalizeTypes($this->types);
        $this->minRepeats = max(1, $this->minRepeats);
    }

    /** @param list<string>|string $types @return list<string> */
    public static function normalizeTypes(array|string $types): array
    {
        if (is_string($types)) {
            $types = preg_split('/[,|]+/', $types) ?: [];
        }
        $aliases = [
            'plain_text' => 'text',
            'plain-text' => 'text',
            'inner-html' => 'inner_html',
            'outer-html' => 'outer_html',
            'html' => 'whole_html',
            'save_html' => 'whole_html',
            'save-hole-html' => 'whole_html',
            'save-whole-html' => 'whole_html',
            'image' => 'images',
            'only-images' => 'images',
            'pdf' => 'pdfs',
            'pdf_files' => 'pdfs',
            'pdf-files' => 'pdfs',
            'nav' => 'navigation_links',
            'nav_links' => 'navigation_links',
            'navigation-links' => 'navigation_links',
            'social-links' => 'social_links',
            'download-links' => 'download_links',
            'word_dictionary' => 'dictionary',
            'words' => 'dictionary',
        ];
        $out = [];
        foreach ($types as $type) {
            $type = strtolower(trim((string) $type));
            $type = str_replace(' ', '_', $type);
            if ($type === '') {
                continue;
            }
            if ($type === 'all') {
                return self::TYPES;
            }
            $type = $aliases[$type] ?? $type;
            if (in_array($type, self::TYPES, true)) {
                $out[$type] = $type;
            }
        }
        return array_values($out ?: ['links', 'text', 'headings', 'components']);
    }

    public function enabled(string $type): bool
    {
        return in_array($type, $this->types, true) || in_array('components', $this->types, true);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'types' => $this->types,
            'selector' => $this->selector,
            'min_repeats' => $this->minRepeats,
            'include_images' => $this->includeImages,
            'include_html' => $this->includeHtml,
            'save_whole_html' => $this->saveWholeHtml,
            'dictionary_file' => $this->dictionaryFile,
            'patterns_file' => $this->patternsFile,
            'mappings_file' => $this->mappingsFile,
        ];
    }
}
