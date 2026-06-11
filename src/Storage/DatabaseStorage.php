<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Storage;

use Mnb\ScraperKit\Core\CrawlResult;
use PDO;

final class DatabaseStorage
{
    public function __construct(private PDO $pdo)
    {
    }

    public function saveCrawlResult(CrawlResult $result, ?int $jobId = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scraper_pages (job_id, url, final_url, raw_final_url, status_code, content_hash, title, html, text_content, crawl_status, error_message, failure_type, skipped, skip_reason, robots_json, extracted_json, response_time_ms, redirect_count, detected_encoding, crawled_at)
             VALUES (:job_id, :url, :final_url, :raw_final_url, :status_code, :content_hash, :title, :html, :text_content, :crawl_status, :error_message, :failure_type, :skipped, :skip_reason, :robots_json, :extracted_json, :response_time_ms, :redirect_count, :detected_encoding, NOW())'
        );

        foreach ($result->pages() as $page) {
            $stmt->execute([
                ':job_id' => $jobId,
                ':url' => $page->url,
                ':final_url' => $page->finalUrl,
                ':raw_final_url' => $page->rawFinalUrl,
                ':status_code' => $page->statusCode,
                ':content_hash' => $page->contentHash,
                ':title' => $page->title,
                ':html' => $page->html,
                ':text_content' => $page->text,
                ':crawl_status' => $page->skipped ? 'skipped' : ($page->error ? 'failed' : 'completed'),
                ':error_message' => $page->error,
                ':failure_type' => $page->failureType,
                ':skipped' => $page->skipped ? 1 : 0,
                ':skip_reason' => $page->skipReason,
                ':robots_json' => json_encode($page->robots, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':extracted_json' => json_encode($page->extracted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':response_time_ms' => $page->responseTimeMs,
                ':redirect_count' => $page->redirectCount,
                ':detected_encoding' => $page->detectedEncoding,
            ]);
        }
    }
}
