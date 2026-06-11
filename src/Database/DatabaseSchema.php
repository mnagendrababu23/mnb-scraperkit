<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Database;

final class DatabaseSchema
{
    /** @return list<string> */
    public static function statements(string $driver = 'sqlite'): array
    {
        $driver = strtolower($driver);
        return $driver === 'mysql' ? self::mysqlStatements() : self::sqliteStatements();
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return [
            'mnb_storage_jobs',
            'mnb_storage_pages',
            'mnb_storage_records',
            'mnb_storage_failed_urls',
            'mnb_storage_validation_issues',
            'mnb_storage_exports',
        ];
    }

    /** @return list<string> */
    private static function sqliteStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS mnb_storage_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, job_uid TEXT NOT NULL UNIQUE, job_type TEXT NOT NULL DEFAULT "crawl", status TEXT NOT NULL DEFAULT "created", manifest_json TEXT NULL, summary_json TEXT NULL, output_dir TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS mnb_storage_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, job_uid TEXT NULL, url TEXT NOT NULL, final_url TEXT NULL, raw_final_url TEXT NULL, status_code INTEGER NULL, content_hash TEXT NULL, title TEXT NULL, text_content TEXT NULL, crawl_status TEXT NOT NULL DEFAULT "pending", error_message TEXT NULL, failure_type TEXT NULL, skipped INTEGER NOT NULL DEFAULT 0, skip_reason TEXT NULL, robots_json TEXT NULL, extracted_json TEXT NULL, response_time_ms INTEGER NOT NULL DEFAULT 0, redirect_count INTEGER NOT NULL DEFAULT 0, detected_encoding TEXT NULL, crawled_at TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS mnb_storage_records (id INTEGER PRIMARY KEY AUTOINCREMENT, job_uid TEXT NULL, record_id TEXT NULL, record_key TEXT NULL, record_type TEXT NULL, source_url TEXT NULL, final_url TEXT NULL, title TEXT NULL, fields_json TEXT NULL, record_json TEXT NOT NULL, quality_score INTEGER NULL, validation_status TEXT NULL, dedupe_key TEXT NULL, created_at TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS mnb_storage_failed_urls (id INTEGER PRIMARY KEY AUTOINCREMENT, job_uid TEXT NULL, url TEXT NOT NULL, final_url TEXT NULL, status_code INTEGER NULL, failure_type TEXT NULL, error_text TEXT NULL, retry_eligible INTEGER NOT NULL DEFAULT 0, recommended_action TEXT NULL, source_json TEXT NULL, created_at TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS mnb_storage_validation_issues (id INTEGER PRIMARY KEY AUTOINCREMENT, job_uid TEXT NULL, record_id TEXT NULL, field_name TEXT NULL, issue_type TEXT NULL, message TEXT NULL, issue_json TEXT NOT NULL, created_at TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS mnb_storage_exports (id INTEGER PRIMARY KEY AUTOINCREMENT, job_uid TEXT NULL, export_type TEXT NOT NULL, format TEXT NULL, file_path TEXT NULL, row_count INTEGER NOT NULL DEFAULT 0, metadata_json TEXT NULL, created_at TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_mnb_pages_job_uid ON mnb_storage_pages(job_uid)',
            'CREATE INDEX IF NOT EXISTS idx_mnb_pages_failure_type ON mnb_storage_pages(failure_type)',
            'CREATE INDEX IF NOT EXISTS idx_mnb_records_job_uid ON mnb_storage_records(job_uid)',
            'CREATE INDEX IF NOT EXISTS idx_mnb_records_type ON mnb_storage_records(record_type)',
            'CREATE INDEX IF NOT EXISTS idx_mnb_records_dedupe ON mnb_storage_records(dedupe_key)',
            'CREATE INDEX IF NOT EXISTS idx_mnb_failed_job_uid ON mnb_storage_failed_urls(job_uid)',
            'CREATE INDEX IF NOT EXISTS idx_mnb_validation_job_uid ON mnb_storage_validation_issues(job_uid)',
        ];
    }

    /** @return list<string> */
    private static function mysqlStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS mnb_storage_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_uid VARCHAR(120) NOT NULL UNIQUE, job_type VARCHAR(80) NOT NULL DEFAULT "crawl", status VARCHAR(40) NOT NULL DEFAULT "created", manifest_json JSON NULL, summary_json JSON NULL, output_dir TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS mnb_storage_pages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_uid VARCHAR(120) NULL, url TEXT NOT NULL, final_url TEXT NULL, raw_final_url TEXT NULL, status_code INT NULL, content_hash VARCHAR(128) NULL, title TEXT NULL, text_content MEDIUMTEXT NULL, crawl_status VARCHAR(50) NOT NULL DEFAULT "pending", error_message TEXT NULL, failure_type VARCHAR(120) NULL, skipped TINYINT(1) NOT NULL DEFAULT 0, skip_reason TEXT NULL, robots_json JSON NULL, extracted_json JSON NULL, response_time_ms INT NOT NULL DEFAULT 0, redirect_count INT NOT NULL DEFAULT 0, detected_encoding VARCHAR(120) NULL, crawled_at DATETIME NOT NULL, INDEX idx_mnb_pages_job_uid (job_uid), INDEX idx_mnb_pages_failure_type (failure_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS mnb_storage_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_uid VARCHAR(120) NULL, record_id VARCHAR(140) NULL, record_key VARCHAR(191) NULL, record_type VARCHAR(80) NULL, source_url TEXT NULL, final_url TEXT NULL, title TEXT NULL, fields_json JSON NULL, record_json JSON NOT NULL, quality_score INT NULL, validation_status VARCHAR(40) NULL, dedupe_key VARCHAR(128) NULL, created_at DATETIME NOT NULL, INDEX idx_mnb_records_job_uid (job_uid), INDEX idx_mnb_records_type (record_type), INDEX idx_mnb_records_dedupe (dedupe_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS mnb_storage_failed_urls (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_uid VARCHAR(120) NULL, url TEXT NOT NULL, final_url TEXT NULL, status_code INT NULL, failure_type VARCHAR(120) NULL, error_text TEXT NULL, retry_eligible TINYINT(1) NOT NULL DEFAULT 0, recommended_action TEXT NULL, source_json JSON NULL, created_at DATETIME NOT NULL, INDEX idx_mnb_failed_job_uid (job_uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS mnb_storage_validation_issues (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_uid VARCHAR(120) NULL, record_id VARCHAR(140) NULL, field_name VARCHAR(190) NULL, issue_type VARCHAR(120) NULL, message TEXT NULL, issue_json JSON NOT NULL, created_at DATETIME NOT NULL, INDEX idx_mnb_validation_job_uid (job_uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS mnb_storage_exports (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_uid VARCHAR(120) NULL, export_type VARCHAR(80) NOT NULL, format VARCHAR(30) NULL, file_path TEXT NULL, row_count INT NOT NULL DEFAULT 0, metadata_json JSON NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
