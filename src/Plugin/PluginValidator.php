<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Plugin;

final class PluginValidator
{
    /** @return array{valid:bool,issues:array<int,array<string,string>>,manifest?:array<string,mixed>} */
    public function validateFile(string $manifestFile): array
    {
        $issues = [];
        if (!is_file($manifestFile)) {
            return ['valid' => false, 'issues' => [[
                'field' => 'manifest',
                'rule' => 'exists',
                'message' => 'Plugin manifest file does not exist.',
            ]]];
        }

        $data = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($data)) {
            return ['valid' => false, 'issues' => [[
                'field' => 'manifest',
                'rule' => 'json_object',
                'message' => 'Plugin manifest must be a valid JSON object.',
            ]]];
        }

        $pluginId = trim((string) ($data['plugin_id'] ?? $data['id'] ?? ''));
        if ($pluginId === '') {
            $issues[] = ['field' => 'plugin_id', 'rule' => 'required', 'message' => 'plugin_id is required.'];
        } elseif (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/i', $pluginId)) {
            $issues[] = ['field' => 'plugin_id', 'rule' => 'format', 'message' => 'plugin_id must be 3-81 characters: letters, numbers, dot, underscore, dash.'];
        }

        foreach (['name', 'version'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $issues[] = ['field' => $field, 'rule' => 'required', 'message' => $field . ' is required.'];
            }
        }

        foreach (['profiles', 'rules', 'commands', 'source_templates', 'export_templates', 'hooks'] as $field) {
            if (array_key_exists($field, $data) && !is_array($data[$field])) {
                $issues[] = ['field' => $field, 'rule' => 'type', 'message' => $field . ' must be an array/object.'];
            }
        }

        $manifest = null;
        if ($issues === []) {
            $manifest = PluginManifest::fromArray($data, $manifestFile);
            foreach ($manifest->profiles as $profile) {
                $path = $manifest->resolvePath($profile);
                if (!is_file($path)) {
                    $issues[] = ['field' => 'profiles', 'rule' => 'file_exists', 'message' => 'Profile file not found: ' . $profile];
                } else {
                    $profileData = json_decode((string) file_get_contents($path), true);
                    if (!is_array($profileData)) {
                        $issues[] = ['field' => 'profiles', 'rule' => 'valid_json', 'message' => 'Profile file is not valid JSON: ' . $profile];
                    }
                }
            }
            foreach ($manifest->rules as $rulesFile) {
                if (!is_file($manifest->resolvePath($rulesFile))) {
                    $issues[] = ['field' => 'rules', 'rule' => 'file_exists', 'message' => 'Rules file not found: ' . $rulesFile];
                }
            }
            foreach ($manifest->commands as $index => $command) {
                if (trim((string) ($command['name'] ?? '')) === '') {
                    $issues[] = ['field' => 'commands.' . $index . '.name', 'rule' => 'required', 'message' => 'Plugin command aliases need a name.'];
                }
            }
        }

        $out = ['valid' => $issues === [], 'issues' => $issues];
        if ($manifest instanceof PluginManifest) {
            $out['manifest'] = $manifest->toArray();
        }
        return $out;
    }
}
