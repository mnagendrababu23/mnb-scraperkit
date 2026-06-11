<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Profile;

final class ProfileSchemaLoader
{
    public function __construct(private readonly string $profilesDir)
    {
    }

    /** @return array<int,array{name:string,path:string,record_type:?string,required_fields:int,extraction_rules:int}> */
    public function list(): array
    {
        $items = [];
        foreach (glob(rtrim($this->profilesDir, '/\\') . '/*.json') ?: [] as $file) {
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
        if (str_ends_with($nameOrPath, '.json') && is_file($this->profilesDir . '/' . $nameOrPath)) {
            return $this->profilesDir . '/' . $nameOrPath;
        }
        return rtrim($this->profilesDir, '/\\') . '/' . preg_replace('/[^a-z0-9_.-]+/i', '-', $nameOrPath) . '.json';
    }
}
