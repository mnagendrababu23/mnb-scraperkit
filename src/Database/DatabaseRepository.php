<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Database;

use PDO;

final class DatabaseRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $manifest */
    public function upsertJob(string $jobUid, string $type = 'crawl', string $status = 'created', array $manifest = [], ?string $outputDir = null): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->scalar('SELECT COUNT(*) FROM mnb_storage_jobs WHERE job_uid = :job_uid', [':job_uid' => $jobUid]) > 0;
        if ($exists) {
            $stmt = $this->pdo->prepare('UPDATE mnb_storage_jobs SET job_type = :job_type, status = :status, manifest_json = :manifest_json, output_dir = :output_dir, updated_at = :updated_at WHERE job_uid = :job_uid');
            $stmt->execute([
                ':job_uid' => $jobUid,
                ':job_type' => $type,
                ':status' => $status,
                ':manifest_json' => $this->json($manifest),
                ':output_dir' => $outputDir,
                ':updated_at' => $now,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO mnb_storage_jobs (job_uid, job_type, status, manifest_json, output_dir, created_at, updated_at) VALUES (:job_uid, :job_type, :status, :manifest_json, :output_dir, :created_at, :updated_at)');
        $stmt->execute([
            ':job_uid' => $jobUid,
            ':job_type' => $type,
            ':status' => $status,
            ':manifest_json' => $this->json($manifest),
            ':output_dir' => $outputDir,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $crawl */
    public function saveCrawlArray(array $crawl, string $jobUid): int
    {
        $pages = isset($crawl['pages']) && is_array($crawl['pages']) ? $crawl['pages'] : [];
        $stmt = $this->pdo->prepare('INSERT INTO mnb_storage_pages (job_uid, url, final_url, raw_final_url, status_code, content_hash, title, text_content, crawl_status, error_message, failure_type, skipped, skip_reason, robots_json, extracted_json, response_time_ms, redirect_count, detected_encoding, crawled_at) VALUES (:job_uid, :url, :final_url, :raw_final_url, :status_code, :content_hash, :title, :text_content, :crawl_status, :error_message, :failure_type, :skipped, :skip_reason, :robots_json, :extracted_json, :response_time_ms, :redirect_count, :detected_encoding, :crawled_at)');
        $count = 0;
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $error = $page['error'] ?? $page['error_message'] ?? null;
            $skipped = !empty($page['skipped']);
            $stmt->execute([
                ':job_uid' => $jobUid,
                ':url' => (string) ($page['url'] ?? ''),
                ':final_url' => $page['final_url'] ?? $page['finalUrl'] ?? null,
                ':raw_final_url' => $page['raw_final_url'] ?? $page['rawFinalUrl'] ?? null,
                ':status_code' => isset($page['status_code']) ? (int) $page['status_code'] : (isset($page['statusCode']) ? (int) $page['statusCode'] : null),
                ':content_hash' => $page['content_hash'] ?? $page['contentHash'] ?? null,
                ':title' => $page['title'] ?? null,
                ':text_content' => $page['text'] ?? $page['text_content'] ?? null,
                ':crawl_status' => $skipped ? 'skipped' : ($error ? 'failed' : 'completed'),
                ':error_message' => $error,
                ':failure_type' => $page['failure_type'] ?? $page['failureType'] ?? null,
                ':skipped' => $skipped ? 1 : 0,
                ':skip_reason' => $page['skip_reason'] ?? $page['skipReason'] ?? null,
                ':robots_json' => $this->json($page['robots'] ?? []),
                ':extracted_json' => $this->json($page['extracted'] ?? []),
                ':response_time_ms' => (int) ($page['response_time_ms'] ?? $page['responseTimeMs'] ?? 0),
                ':redirect_count' => (int) ($page['redirect_count'] ?? $page['redirectCount'] ?? 0),
                ':detected_encoding' => $page['detected_encoding'] ?? $page['detectedEncoding'] ?? null,
                ':crawled_at' => date('Y-m-d H:i:s'),
            ]);
            $count++;
        }
        return $count;
    }

    /** @param array<string,mixed> $pipeline */
    public function savePipelineArray(array $pipeline, string $jobUid): array
    {
        $recordCount = $this->saveRecords(isset($pipeline['records']) && is_array($pipeline['records']) ? $pipeline['records'] : [], $jobUid);
        $issueCount = $this->saveValidationIssues(isset($pipeline['validation_issues']) && is_array($pipeline['validation_issues']) ? $pipeline['validation_issues'] : [], $jobUid);
        return ['records' => $recordCount, 'validation_issues' => $issueCount];
    }

    /** @param array<int,mixed> $records */
    public function saveRecords(array $records, string $jobUid): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO mnb_storage_records (job_uid, record_id, record_key, record_type, source_url, final_url, title, fields_json, record_json, quality_score, validation_status, dedupe_key, created_at) VALUES (:job_uid, :record_id, :record_key, :record_type, :source_url, :final_url, :title, :fields_json, :record_json, :quality_score, :validation_status, :dedupe_key, :created_at)');
        $count = 0;
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $fields = isset($record['fields']) && is_array($record['fields']) ? $record['fields'] : [];
            $stmt->execute([
                ':job_uid' => $jobUid,
                ':record_id' => $record['record_id'] ?? null,
                ':record_key' => $record['record_key'] ?? null,
                ':record_type' => $record['record_type'] ?? null,
                ':source_url' => $record['source_url'] ?? null,
                ':final_url' => $record['final_url'] ?? null,
                ':title' => $record['title'] ?? ($fields['title'] ?? $fields['page_title'] ?? null),
                ':fields_json' => $this->json($fields),
                ':record_json' => $this->json($record),
                ':quality_score' => isset($record['quality_score']) ? (int) $record['quality_score'] : null,
                ':validation_status' => is_array($record['validation'] ?? null) ? ($record['validation']['status'] ?? null) : null,
                ':dedupe_key' => $record['dedupe_key'] ?? null,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
            $count++;
        }
        return $count;
    }

    /** @param array<int,mixed> $issues */
    public function saveValidationIssues(array $issues, string $jobUid): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO mnb_storage_validation_issues (job_uid, record_id, field_name, issue_type, message, issue_json, created_at) VALUES (:job_uid, :record_id, :field_name, :issue_type, :message, :issue_json, :created_at)');
        $count = 0;
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $stmt->execute([
                ':job_uid' => $jobUid,
                ':record_id' => $issue['record_id'] ?? null,
                ':field_name' => $issue['field'] ?? $issue['field_name'] ?? null,
                ':issue_type' => $issue['type'] ?? $issue['issue_type'] ?? null,
                ':message' => $issue['message'] ?? null,
                ':issue_json' => $this->json($issue),
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
            $count++;
        }
        return $count;
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $out = [];
        foreach (DatabaseSchema::tableNames() as $table) {
            try {
                $out[$table] = $this->scalar('SELECT COUNT(*) FROM ' . $table);
            } catch (\Throwable) {
                $out[$table] = -1;
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchRows(string $table, int $limit = 100): array
    {
        if (!in_array($table, DatabaseSchema::tableNames(), true)) {
            throw new \InvalidArgumentException('Unsupported table: ' . $table);
        }
        $limit = max(1, min(10000, $limit));
        return $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY id DESC LIMIT ' . $limit)->fetchAll() ?: [];
    }

    /** @param array<string,mixed> $params */
    private function scalar(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';
    }
}
