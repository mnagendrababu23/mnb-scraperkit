CREATE TABLE scraper_projects (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    base_url TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT NULL,
    start_url TEXT NOT NULL,
    max_pages INT DEFAULT 100,
    max_depth INT DEFAULT 3,
    status VARCHAR(50) DEFAULT 'pending',
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_pages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT NULL,
    url TEXT NOT NULL,
    final_url TEXT NULL,
    raw_final_url TEXT NULL,
    status_code INT NULL,
    content_hash CHAR(64) NULL,
    title TEXT NULL,
    html MEDIUMTEXT NULL,
    text_content MEDIUMTEXT NULL,
    crawl_status VARCHAR(50) DEFAULT 'pending',
    error_message TEXT NULL,
    failure_type VARCHAR(100) NULL,
    skipped TINYINT(1) DEFAULT 0,
    skip_reason TEXT NULL,
    robots_json JSON NULL,
    extracted_json JSON NULL,
    response_time_ms INT DEFAULT 0,
    redirect_count INT DEFAULT 0,
    detected_encoding VARCHAR(100) NULL,
    crawled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


CREATE TABLE scraper_bulk_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    url_file TEXT NOT NULL,
    output_dir TEXT NULL,
    checkpoint_path TEXT NULL,
    total_urls INT DEFAULT 0,
    completed_urls INT DEFAULT 0,
    status VARCHAR(50) DEFAULT 'running',
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_bulk_run_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bulk_run_id BIGINT NULL,
    url_index INT NOT NULL,
    url TEXT NOT NULL,
    output_path TEXT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    summary_json JSON NULL,
    error_message TEXT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_extracted_data (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT NOT NULL,
    field_name VARCHAR(190) NOT NULL,
    field_value MEDIUMTEXT NULL,
    extraction_method VARCHAR(100) NULL,
    confidence_score DECIMAL(5,2) DEFAULT 100.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT NULL,
    field_name VARCHAR(190) NOT NULL,
    selector TEXT NULL,
    regex_pattern TEXT NULL,
    extraction_type VARCHAR(50) DEFAULT 'css',
    required TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_network_profiles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    type VARCHAR(50) NOT NULL,
    host VARCHAR(255) NULL,
    port INT NULL,
    username VARCHAR(190) NULL,
    password_encrypted TEXT NULL,
    country_code VARCHAR(20) NULL,
    is_active TINYINT(1) DEFAULT 1,
    max_requests_per_minute INT DEFAULT 60,
    cooldown_seconds INT DEFAULT 60,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_browser_profiles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    engine VARCHAR(50) NOT NULL,
    browser VARCHAR(50) NOT NULL,
    headless TINYINT(1) DEFAULT 1,
    window_width INT DEFAULT 1366,
    window_height INT DEFAULT 768,
    timeout_seconds INT DEFAULT 30,
    wait_after_load_ms INT DEFAULT 1000,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_encoding_reports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT NULL,
    url TEXT NOT NULL,
    http_charset VARCHAR(100) NULL,
    html_charset VARCHAR(100) NULL,
    detected_charset VARCHAR(100) NULL,
    final_charset VARCHAR(100) DEFAULT 'UTF-8',
    bom_type VARCHAR(100) NULL,
    mojibake_fixed TINYINT(1) DEFAULT 0,
    invalid_characters_count INT DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_ml_training_data (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT NOT NULL,
    label VARCHAR(100) NOT NULL,
    features JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE scraper_common_data_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT NULL,
    source_url TEXT NOT NULL,
    data_type VARCHAR(100) NOT NULL,
    data_value TEXT NULL,
    data_context MEDIUMTEXT NULL,
    normalized_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_common_type (data_type)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- V1.0 Professional Crawl Pipeline additions
CREATE TABLE IF NOT EXISTS mnb_scraper_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(100) NOT NULL UNIQUE,
  job_type VARCHAR(50) NOT NULL DEFAULT 'crawl',
  start_url TEXT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'created',
  manifest_json JSON NULL,
  summary_json JSON NULL,
  output_dir TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mnb_scraper_pipeline_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(100) NULL,
  record_key VARCHAR(191) NULL,
  record_type VARCHAR(80) NULL,
  source_page_url TEXT NULL,
  final_url TEXT NULL,
  title TEXT NULL,
  record_json JSON NOT NULL,
  quality_score INT NULL,
  validation_status VARCHAR(40) NULL,
  dedupe_key CHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_job_uid (job_uid),
  INDEX idx_dedupe_key (dedupe_key),
  INDEX idx_record_type (record_type),
  INDEX idx_quality_score (quality_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mnb_scraper_failed_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_uid VARCHAR(100) NULL,
  url TEXT NOT NULL,
  final_url TEXT NULL,
  failure_type VARCHAR(120) NULL,
  error_text TEXT NULL,
  retry_count INT NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_job_uid (job_uid),
  INDEX idx_failure_type (failure_type),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
