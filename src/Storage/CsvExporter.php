<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Storage;

use Mnb\ScraperKit\Core\CrawlResult;

final class CsvExporter
{
    public function export(CrawlResult $result, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fp = fopen($path, 'wb');
        if (!$fp) {
            throw new \RuntimeException('Unable to open CSV output path.');
        }

        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'url', 'final_url', 'raw_final_url', 'status_code', 'title', 'meta_description',
            'links_count', 'content_hash', 'error', 'failure_type', 'skipped', 'skip_reason',
            'response_time_ms', 'redirect_count', 'detected_encoding',
            'common_emails_count', 'common_issns_count', 'common_isbns_count', 'common_dates_count',
            'common_deadlines_count', 'common_submissions_count', 'common_phones_count', 'common_pdf_links_count'
        ]);
        foreach ($result->pages() as $page) {
            $commonCounts = $page->extracted['_common_data']['counts'] ?? [];
            fputcsv($fp, [
                $page->url,
                $page->finalUrl,
                $page->rawFinalUrl,
                $page->statusCode,
                $page->title,
                $page->meta['description'] ?? null,
                count($page->links),
                $page->contentHash,
                $page->error,
                $page->failureType,
                $page->skipped ? 'yes' : 'no',
                $page->skipReason,
                $page->responseTimeMs,
                $page->redirectCount,
                $page->detectedEncoding,
                $commonCounts['emails'] ?? 0,
                $commonCounts['issns'] ?? 0,
                $commonCounts['isbns'] ?? 0,
                $commonCounts['dates'] ?? 0,
                $commonCounts['deadlines'] ?? 0,
                $commonCounts['submissions'] ?? 0,
                $commonCounts['phones'] ?? 0,
                $commonCounts['pdf_links'] ?? 0,
            ]);
        }
        fclose($fp);
    }
}
