-- MNB ScraperKit V3.1.0 SQLite storage schema
-- Default local database: storage/database/mnb-scraperkit.sqlite

CREATE TABLE IF NOT EXISTS mnb_storage_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_uid TEXT NOT NULL UNIQUE,
  job_type TEXT NOT NULL DEFAULT 'crawl',
  status TEXT NOT NULL DEFAULT 'created',
  manifest_json TEXT NULL,
  summary_json TEXT NULL,
  output_dir TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mnb_storage_pages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_uid TEXT NULL,
  url TEXT NOT NULL,
  final_url TEXT NULL,
  raw_final_url TEXT NULL,
  status_code INTEGER NULL,
  content_hash TEXT NULL,
  title TEXT NULL,
  text_content TEXT NULL,
  crawl_status TEXT NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  failure_type TEXT NULL,
  skipped INTEGER NOT NULL DEFAULT 0,
  skip_reason TEXT NULL,
  robots_json TEXT NULL,
  extracted_json TEXT NULL,
  response_time_ms INTEGER NOT NULL DEFAULT 0,
  redirect_count INTEGER NOT NULL DEFAULT 0,
  detected_encoding TEXT NULL,
  crawled_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mnb_storage_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_uid TEXT NULL,
  record_id TEXT NULL,
  record_key TEXT NULL,
  record_type TEXT NULL,
  source_url TEXT NULL,
  final_url TEXT NULL,
  title TEXT NULL,
  fields_json TEXT NULL,
  record_json TEXT NOT NULL,
  quality_score INTEGER NULL,
  validation_status TEXT NULL,
  dedupe_key TEXT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mnb_storage_failed_urls (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_uid TEXT NULL,
  url TEXT NOT NULL,
  final_url TEXT NULL,
  status_code INTEGER NULL,
  failure_type TEXT NULL,
  error_text TEXT NULL,
  retry_eligible INTEGER NOT NULL DEFAULT 0,
  recommended_action TEXT NULL,
  source_json TEXT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mnb_storage_validation_issues (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_uid TEXT NULL,
  record_id TEXT NULL,
  field_name TEXT NULL,
  issue_type TEXT NULL,
  message TEXT NULL,
  issue_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mnb_storage_exports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_uid TEXT NULL,
  export_type TEXT NOT NULL,
  format TEXT NULL,
  file_path TEXT NULL,
  row_count INTEGER NOT NULL DEFAULT 0,
  metadata_json TEXT NULL,
  created_at TEXT NOT NULL
);
