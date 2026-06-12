-- MNB ScraperKit V4.1.1 MySQL/MariaDB storage schema

CREATE TABLE IF NOT EXISTS mnb_storage_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(120) NOT NULL UNIQUE,
  job_type VARCHAR(80) NOT NULL DEFAULT 'crawl',
  status VARCHAR(40) NOT NULL DEFAULT 'created',
  manifest_json JSON NULL,
  summary_json JSON NULL,
  output_dir TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mnb_storage_pages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(120) NULL,
  url TEXT NOT NULL,
  final_url TEXT NULL,
  raw_final_url TEXT NULL,
  status_code INT NULL,
  content_hash VARCHAR(128) NULL,
  title TEXT NULL,
  text_content MEDIUMTEXT NULL,
  crawl_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  failure_type VARCHAR(120) NULL,
  skipped TINYINT(1) NOT NULL DEFAULT 0,
  skip_reason TEXT NULL,
  robots_json JSON NULL,
  extracted_json JSON NULL,
  response_time_ms INT NOT NULL DEFAULT 0,
  redirect_count INT NOT NULL DEFAULT 0,
  detected_encoding VARCHAR(120) NULL,
  crawled_at DATETIME NOT NULL,
  INDEX idx_mnb_pages_job_uid (job_uid),
  INDEX idx_mnb_pages_failure_type (failure_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mnb_storage_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(120) NULL,
  record_id VARCHAR(140) NULL,
  record_key VARCHAR(191) NULL,
  record_type VARCHAR(80) NULL,
  source_url TEXT NULL,
  final_url TEXT NULL,
  title TEXT NULL,
  fields_json JSON NULL,
  record_json JSON NOT NULL,
  quality_score INT NULL,
  validation_status VARCHAR(40) NULL,
  dedupe_key VARCHAR(128) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_mnb_records_job_uid (job_uid),
  INDEX idx_mnb_records_type (record_type),
  INDEX idx_mnb_records_dedupe (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mnb_storage_failed_urls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(120) NULL,
  url TEXT NOT NULL,
  final_url TEXT NULL,
  status_code INT NULL,
  failure_type VARCHAR(120) NULL,
  error_text TEXT NULL,
  retry_eligible TINYINT(1) NOT NULL DEFAULT 0,
  recommended_action TEXT NULL,
  source_json JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_mnb_failed_job_uid (job_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mnb_storage_validation_issues (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(120) NULL,
  record_id VARCHAR(140) NULL,
  field_name VARCHAR(190) NULL,
  issue_type VARCHAR(120) NULL,
  message TEXT NULL,
  issue_json JSON NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_mnb_validation_job_uid (job_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mnb_storage_exports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(120) NULL,
  export_type VARCHAR(80) NOT NULL,
  format VARCHAR(30) NULL,
  file_path TEXT NULL,
  row_count INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
