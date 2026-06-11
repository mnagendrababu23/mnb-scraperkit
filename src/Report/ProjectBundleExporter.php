<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Report;

final class ProjectBundleExporter
{
    /** @return array<string,mixed> */
    public function create(string $jobDir, string $outputZip): array
    {
        $jobDir = rtrim($jobDir, '/\\');
        if (!is_dir($jobDir)) {
            throw new \RuntimeException('Job directory not found: ' . $jobDir);
        }
        $files = $this->collectFiles($jobDir);
        $this->ensureDir(dirname($outputZip));
        $writer = new SimpleZipWriter($outputZip);
        foreach ($files as $relative => $path) {
            $writer->addFile($relative, (string) file_get_contents($path));
        }
        $writer->close();
        return [
            'bundle_version' => '1.6.0',
            'created_at' => date(DATE_ATOM),
            'job_dir' => $jobDir,
            'output' => $outputZip,
            'files_total' => count($files),
            'size_bytes' => is_file($outputZip) ? filesize($outputZip) : null,
            'files' => array_keys($files),
        ];
    }

    /** @return array<string,string> relative => path */
    private function collectFiles(string $jobDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jobDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['json', 'csv', 'xml', 'html', 'txt', 'log'], true)) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($jobDir))), '/');
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }
            $files[$relative] = $path;
        }
        ksort($files);
        return $files;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
