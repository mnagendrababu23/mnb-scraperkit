<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Profile;

final class ProfileSchemaLoader
{
    /** @var list<string> */
    private array $profilesDirs;

    /** @var list<string> */
    private array $profileFiles;

    /** @param string|list<string> $profilesDir @param list<string> $profileFiles */
    public function __construct(string|array $profilesDir, array $profileFiles = [])
    {
        $dirs = is_array($profilesDir) ? $profilesDir : [$profilesDir];
        $this->profilesDirs = array_values(array_unique(array_map(static fn (string $dir): string => rtrim($dir, '/\\'), $dirs)));
        $this->profileFiles = array_values(array_unique($profileFiles));
    }

    /** @return array<int,array{name:string,path:string,record_type:?string,required_fields:int,extraction_rules:int}> */
    public function list(): array
    {
        $items = [];
        foreach ($this->allProfileFiles() as $file) {
            try {
                $schema = $this->load($file);
                $items[] = [
                    'name' => $schema->profile,
                    'path' => $file,
                    'record_type' => $schema->recordType,
                    'required_fields' => count($schema->requiredFields),
                    'extraction_rules' => count($schema->extractionRules),
                ];
            } catch (\Throwable) {
                $items[] = [
                    'name' => basename($file, '.json'),
                    'path' => $file,
                    'record_type' => null,
                    'required_fields' => 0,
                    'extraction_rules' => 0,
                ];
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $items;
    }

    public function load(string $nameOrPath): ProfileSchema
    {
        $file = $this->resolvePath($nameOrPath);
        if (!is_file($file)) {
            throw new \RuntimeException('Profile schema not found: ' . $nameOrPath);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Profile schema is not valid JSON object: ' . $file);
        }
        return ProfileSchema::fromArray($data);
    }

    public function resolvePath(string $nameOrPath): string
    {
        $nameOrPath = trim($nameOrPath);
        if ($nameOrPath === '') {
            throw new \InvalidArgumentException('Profile name/path is required.');
        }
        if (is_file($nameOrPath)) {
            return $nameOrPath;
        }
        if (str_ends_with($nameOrPath, '.json')) {
            foreach ($this->profilesDirs as $dir) {
                if (is_file($dir . '/' . $nameOrPath)) {
                    return $dir . '/' . $nameOrPath;
                }
            }
        }
        $safe = preg_replace('/[^a-z0-9_.-]+/i', '-', $nameOrPath) . '.json';
        foreach ($this->profilesDirs as $dir) {
            if (is_file($dir . '/' . $safe)) {
                return $dir . '/' . $safe;
            }
        }
        foreach ($this->profileFiles as $file) {
            if (basename($file, '.json') === $nameOrPath || basename($file) === $nameOrPath) {
                return $file;
            }
            if (is_file($file)) {
                $data = json_decode((string) file_get_contents($file), true);
                if (is_array($data) && (string) ($data['profile'] ?? '') === $nameOrPath) {
                    return $file;
                }
            }
        }
        return ($this->profilesDirs[0] ?? getcwd()) . '/' . $safe;
    }

    /** @return list<string> */
    private function allProfileFiles(): array
    {
        $files = $this->profileFiles;
        foreach ($this->profilesDirs as $dir) {
            foreach (glob(rtrim($dir, '/\\') . '/*.json') ?: [] as $file) {
                $files[] = $file;
            }
        }
        return array_values(array_unique(array_filter($files, static fn (string $file): bool => is_file($file))));
    }

}
