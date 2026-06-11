# MNB ScraperKit V2.0.0

**MNB ScraperKit** is a PHP-first professional crawling and data extraction framework for safe, resumable, pipeline-based web scraping.

V2.0.0 is the Dashboard and Admin UI Update. It adds an optional dependency-free local HTML dashboard, static dashboard snapshots, dashboard JSON output, dashboard serve/build/status commands, and consolidated read-only operations views for queue jobs, schedules, workers, profiles, plugins, API routes, and system health.

ScraperKit is designed for developers, SEO analysts, research teams, academic metadata collectors, ecommerce monitors, tender/job/government data teams, and server automation users who need safe CLI crawling, bulk jobs, resumable checkpoints, normalized records, validation, transformations, exports, and reports.

## What makes it different

Most PHP scraping tools focus on fetching HTML and extracting selectors. MNB ScraperKit is designed around a complete professional crawl flow:

```text
URL -> Safe Request -> Crawl Result -> Normalized Record -> Validate -> Dedupe -> Transform -> Export -> Report/Resume
```

The strongest part of the library is the **professional crawl pipeline**. It turns crawled pages into structured records with metadata, validation status, quality scoring, deduplication keys, failed URL handling, and export-ready output.

## V2.0.0 update focus

V2.0.0 focuses on visibility and operations. It gives users a lightweight local dashboard without requiring Laravel, Symfony HTTP Kernel, Node, Redis, or a database. The dashboard reads the same local queue, schedule, monitor, profile, plugin, and API metadata used by CLI commands.

- Added dependency-free dashboard classes: `DashboardDataCollector` and `DashboardRenderer`.
- Added `public/dashboard.php` for local HTML dashboard and `/dashboard.json` machine-readable snapshots.
- Added dashboard commands: `dashboard:status`, `dashboard:build`, and `dashboard:serve`.
- Added static dashboard snapshot generation for sharing or archiving local job state.
- Added dashboard server helper scripts for CMD and PowerShell.
- Added optional dashboard token protection using `MNB_SCRAPERKIT_DASHBOARD_TOKEN`.
- Added API dashboard endpoint: `/api/v1/dashboard`.
- Kept V1.9.0 API/webhooks, V1.8.0 plugins, V1.7.0 retry/scheduling/monitoring, V1.6.0 database storage, V1.5.0 browser-assisted crawling, V1.4.0 queue/worker commands, V1.3.0 profile schemas/extractor rules, V1.2.0 exports/reports/bundles, and V1.1.0 source connectors.

## Highlights

- **Professional PHP CLI framework** built as a Composer package with Symfony Console commands.
- **Local dashboard and admin UI** for queue jobs, schedules, workers, profiles, plugins, API routes, and system health.
- **Plugin system** for config-only add-ons with reusable profile schemas, extractor rule files, source templates, export templates, command aliases, validation, install, enable/disable, and doctor checks.
- **Advanced retry, scheduling, and monitoring** with safe retry plans, local schedules, due-job enqueueing, health summaries, and stale lock diagnostics.
- **Optional database storage layer** using PDO with SQLite and MySQL/MariaDB support for jobs, pages, records, failures, validation issues, and export metadata.
- **Optional browser-assisted crawling** for JavaScript-heavy pages using `--browser=auto` or `--browser=always`, with optional rendered HTML and screenshot artifacts.
- **Queue and worker commands** for local file-based job automation, worker loops, job pause/resume/cancel, safe failed queue retry, and worker locks.
- **Safe crawling controls** including URL safety checks, redirect safety, scope rules, robots-aware behavior, userinfo blocking, URL length limits, and private/reserved IP protection.
- **Bulk crawling support** for processing many URLs with pacing, random jitter, cooldowns, checkpointing, failed queues, skipped queues, and resume support.
- **Manifest-driven jobs** for reproducible crawl configuration, including input URLs, scope, pacing, extraction profile, output settings, and resume state.
- **Professional crawl pipeline** that converts raw page results into normalized records with metadata, validation, deduplication, transformation, quality scoring, and exports.
- **Profile schemas and extractor rules** for reusable field definitions, validators, transformations, dedupe keys, export columns, CSS/XPath/meta/JSON-LD/Open Graph extraction, and fallback selectors.
- **Common data profiles** for academic, journal, conference, ecommerce, government, tender, jobs, SEO, contact, and document-focused extraction workflows.
- **Professional exports and reports** including JSON, CSV, XML, HTML summaries, failed URL reports, validation issue reports, and ZIP project bundles.
- **Automation friendly** for PHP CLI, CMD, PowerShell, cron, Windows Task Scheduler, and server-side workflows.
- **Source connector system** for collecting crawl targets from sitemaps, RSS/Atom feeds, CSV files, JSON files, generic JSON APIs, PLOS, and Elsevier/ScienceDirect.
- **Future-ready architecture** designed for later expansion into richer dashboards, Redis queues, browser-worker orchestration, role-based access, and ML-assisted intelligence.


## Complete feature list

This section lists the main functionality available in the current V2.0.0 CLI/library release.

### Package and CLI

- Composer-installable PHP package with PSR-4 autoloading.
- Symfony Console based command-line application.
- Reusable framework-independent PHP core classes.
- Global binary support through `vendor/bin/mnb-scraper`.
- Built-in command list and per-command help screens.
- CMD, PowerShell, cron, Windows Task Scheduler, and server automation friendly scripts/workflows.

### Dashboard and local admin UI

- Optional dependency-free HTML dashboard under `public/dashboard.php`.
- `dashboard:serve` command for running the local admin dashboard with PHP built-in server.
- `dashboard:build` command for writing static HTML dashboard snapshots.
- `dashboard:status` command for checking dashboard health and available data.
- `/dashboard.json` output for machine-readable dashboard snapshots.
- Consolidated dashboard view for queue counts, recent jobs, schedules, stale locks, profiles, plugins, command count, and API route count.
- Optional token protection through `MNB_SCRAPERKIT_DASHBOARD_TOKEN`.
- CMD and PowerShell dashboard server helper scripts.

### Crawling and HTTP

- Single URL/site crawl command.
- Multi-page crawl with configurable maximum pages and depth.
- HTTP request engine with headers, timeout, redirects, response metadata, and challenge/protection detection.
- HTTP diagnostic command for checking status, headers, redirect behavior, and response handling.
- Configurable user agent, request timeout, redirects, delay, jitter, pause, and cooldown options.
- URL processing flow for sequential URL lists with retry/backoff/checkpoint behavior.

### Safety and responsible crawling

- Central URL safety guard for outgoing requests and redirects.
- HTTP/HTTPS-only scheme enforcement.
- Localhost, private IP, reserved IP, link-local, and metadata address blocking.
- Numeric and hex IPv4 host detection.
- URL userinfo credential blocking.
- URL length safety checks.
- Final-domain and scope guard support.
- Robots.txt decision inspection.
- Auth/login/cart-style URL skipping.
- Challenge/protection page detection and reporting.
- Failure-aware pacing with cooldown after repeated errors.

### URL filtering and scope control

- Allowed domain and denied path logic.
- Max depth and max page limit controls.
- Final URL tracking after redirects.
- Duplicate URL and final URL handling.
- Skipped URL classification for unsafe, blocked, out-of-scope, or challenge URLs.

### Encoding and text normalization

- Charset detection from headers and HTML.
- UTF-8 conversion support.
- Mojibake cleanup helpers.
- Text normalization helpers for cleaner extraction output.
- Encoding diagnostic command.

### Parsing and extraction

- HTML parsing helpers.
- Preset extraction support.
- Custom rule extraction support.
- V1.3 profile-driven rule extraction support.
- CSS-style selector extraction.
- XPath extraction.
- Attribute extraction such as `href`, `src`, and `content`.
- Meta tag extraction such as `meta:description`.
- Open Graph extraction such as `og:title` and `og:image`.
- JSON-LD dot-path extraction such as `jsonld:name` or `jsonld:offers.price`.
- Regex cleanup/extraction from selected text.
- Fallback selectors for fields that vary across websites.
- Multi-value fields using `many: true` or `[]` rule suffix.
- Common data extraction for emails, phones, metadata, links, documents, and profile-oriented data.
- Common data type/profile listing command.

### Plugin system

- Config-only plugins using `mnb-plugin.json`.
- Bundled plugins in `plugins/` and installed plugins in `storage/plugins/`.
- Plugin validation that checks required metadata and referenced profile/rule files.
- Plugin install command that copies a plugin into `storage/plugins/`.
- Plugin enable/disable controls by editing the manifest `enabled` flag.
- Plugin doctor command for validating all discovered plugins.
- Plugin-contributed profiles available to `profile:list`, `profile:show`, `extract:rules`, and pipeline/profile workflows.
- Safe-by-default design: V2.0.0 does not automatically execute arbitrary plugin PHP code.

### Lightweight API and webhooks

- Optional no-framework JSON API router in `public/api-router.php`.
- API server command using PHP built-in server: `api:serve`.
- API route discovery command: `api:routes`.
- API token generation command: `api:token`.
- Bearer-token authentication using `MNB_SCRAPERKIT_API_TOKEN`.
- Health/version endpoints for local monitors.
- Queue status endpoint for pending/running/completed/failed job counts.
- Job list, job show, and job create API endpoints.
- Monitoring summary endpoint for queue, schedules, and locks.
- Plugin and profile listing endpoints for lightweight dashboards.
- Webhook endpoint listing from `config/webhooks.json`.
- Webhook test command that can write a local event file without network calls.
- Webhook send command for posting JSON payloads to authorized HTTP/HTTPS endpoints.
- Webhook endpoint safety uses URL safety checks and blocks unsupported/private targets by default.

### Advanced retry, scheduling, and monitoring

- Safe retry policy for temporary failures such as timeout, DNS/SSL errors, 429 rate limits, 5xx errors, no response, and temporary network issues.
- Conservative non-retry defaults for robots blocks, private IP blocks, unsupported schemes, auth/cookie redirects, final-domain guard failures, validation failures, redirect loops, and most 4xx responses.
- `retry:plan` command for generating retry decisions from crawl JSON, failed URL reports, or failed queue jobs.
- Retry plan fields: failure type, status code, attempts, eligibility, retry delay, next attempt time, reason, and recommended action.
- `queue:retry-safe` command to retry only eligible failed jobs instead of blindly retrying everything.
- Local file-based schedules stored under `storage/schedules/`.
- `schedule:create` for cron-like scheduled crawl/source jobs without requiring a daemon.
- `schedule:run-due` for cron, Task Scheduler, Supervisor, systemd timers, or worker loop handoff.
- `schedule:list`, `schedule:show`, `schedule:enable`, and `schedule:disable` commands.
- Schedule options for one-time runs, interval runs, delay, explicit run time, and max run count.
- `monitor:summary` for queue counts, schedule counts, worker locks, stale locks, and health status.
- `monitor:stale-locks` for diagnosing stuck workers or interrupted jobs.

### Database storage layer

- Optional PDO-based storage layer.
- SQLite support for local development, CLI automation, and single-machine jobs.
- MySQL/MariaDB support for server and team workflows.
- Database migration command using built-in schema definitions.
- Database connection test command.
- Database status command with table counts.
- Save crawl JSON pages into database tables.
- Save pipeline records and validation issues into database tables.
- Export supported database tables to JSON or CSV.
- Storage tables for jobs, pages, normalized records, failed URLs, validation issues, and export metadata.
- File-based JSON/CSV output remains default; database storage is optional.

### Browser-assisted crawling

- Normal PHP HTTP crawler remains the default.
- Optional browser fallback mode with `--browser=auto`.
- Forced browser rendering mode with `--browser=always` or `--force-browser`.
- `browser:test <url>` command for fallback diagnostics.
- Auto fallback detection for low-text pages, JavaScript app markers, required JavaScript messages, challenge/browser-required pages, and missing required fields.
- Optional Panther/Chrome adapter through `symfony/panther`; not required for normal users.
- Browser options for wait selector, wait time, timeout, viewport width/height, headless mode, asset blocking, rendered HTML, and screenshots.
- Same URL safety guard, robots policy, scope rules, final-domain checks, and rate limits stay active in browser mode.
- Browser options can be stored in queued jobs and forwarded from `worker:run`.
- Browser output can save `rendered.html`, `browser-result.json`, and `screenshot.png` when configured.

### Common data profiles

- Academic and journal extraction profile direction.
- Conference extraction profile direction.
- Government and tender extraction profile direction.
- Ecommerce extraction profile direction.
- Jobs extraction profile direction.
- SEO extraction profile direction.
- Contact and document extraction profile direction.

### Profile schemas and extractor rules

- Built-in profile schema directory: `config/profiles/`.
- Example schemas for ecommerce, SEO, academic/article metadata, jobs, and tender/government data.
- Schema fields for `profile`, `record_type`, `required_fields`, `optional_fields`, `dedupe_keys`, `validators`, `transformations`, `field_map`, `export_columns`, and `extraction_rules`.
- `profile:list` command to discover available schemas.
- `profile:show <profile>` command to inspect one schema.
- `profile:validate <profile.json>` command to validate custom schemas.
- `extract:rules <url> --profile=<name>` command to test profile extraction rules before running a full crawl.
- Pipeline integration that uses schema defaults for validation, transformations, dedupe keys, record type, and export metadata.
- Custom project profiles can be added without editing PHP core classes.

### Bulk jobs and resume

- Bulk URL crawl from text file.
- Checkpoint files for long-running jobs.
- Resume support from saved checkpoint state.
- Pending, completed, failed, skipped, challenge, and retry queue tracking.
- Pause after N URLs.
- Rest gaps and jitter for less aggressive crawling.
- Job summary command for inspecting job output and manifest state.


### Queue and worker commands

- Local file-based queue stored under `storage/queue/`.
- Queue states: `pending`, `running`, `completed`, `failed`, `paused`, `cancelled`, and `retry`.
- `job:create` to create crawl, bulk crawl, URL process, sitemap, RSS, CSV, JSON, or API source jobs.
- `job:list` and `job:show` for queue inspection.
- `job:pause`, `job:resume`, and `job:cancel` for lifecycle control.
- `job:run <job-id>` to run one queued job manually.
- `worker:once` to run exactly one pending job and exit.
- `worker:run` for long-running CLI workers with `--sleep`, `--max-jobs`, `--max-runtime`, `--memory-limit`, and `--stop-when-empty`.
- `worker:status` for queue counts and active lock visibility.
- Failed queue helpers: `queue:failed`, `queue:retry`, `queue:retry-all`, and `queue:clear-failed`.
- Lock file support under `storage/queue/locks/` to avoid duplicate execution by parallel workers.
- Works without Redis, database, or external queue services.

### Job manifest

- Job manifest JSON output when `--job-dir` is used.
- Manifest sections for input, scope, request profile, pacing, extraction, output, resume state, and summary.
- Last processed URL and checkpoint metadata.
- Counts for pending, completed, failed, skipped, challenge, and retry groups.
- Job-run command for crawl/bulk jobs from JSON configuration.

### Professional crawl pipeline

- Crawl JSON reader for pipeline processing.
- Normalized record builder.
- Record IDs and record types.
- Source URL and final URL traceability.
- Field metadata and profile metadata.
- Validation pipeline.
- Deduplication pipeline.
- Transformation pipeline.
- Quality scoring.
- Dropped, duplicate, invalid, and warning record tracking.
- Pipeline summary output.

### Validation

- Required field validation.
- URL validation.
- Email validation.
- Phone validation.
- DOI validation.
- ISSN validation.
- ISBN validation.
- Date validation.
- Price validation.
- Validation issue export and record status reporting.

### Transformations

- Whitespace cleanup.
- HTML tag stripping.
- URL cleanup.
- Date normalization.
- Price number extraction.
- Lowercase/uppercase transformations.
- Identifier casing support.
- Field-name mapping for stable exports.

### Deduplication

- Dedupe key generation.
- URL/final URL/content-oriented dedupe support.
- Configurable record-level dedupe keys.
- Duplicate record reporting.

### Failure handling and retry

- Standard failure classification for timeout, DNS, SSL, redirect loop, private IP block, unsupported scheme, body-too-large, HTTP 4xx, HTTP 5xx, robots block, final-domain guard, and validation failure.
- Failed URL report generation.
- Skipped URL report generation.
- Retry list generation from failed crawl output.
- Backoff/cooldown options for safer repeated runs.

### Export, reports, and bundles

- Crawl result JSON export.
- Pipeline record JSON export.
- Pipeline record CSV export.
- Pipeline record XML export.
- Pipeline record HTML table export.
- Failed URL reports in JSON, CSV, XML, or HTML.
- Skipped URL reports when enabled with `--include-skipped`.
- Validation issue reports in JSON, CSV, XML, or HTML.
- Professional crawl summary report with job metadata, page counts, record counts, failure counts, validation status counts, quality summary, resume state, and export file list.
- HTML summary reports suitable for quick review or sharing with a team/client.
- JSON/CSV/XML summary reports for downstream systems.
- ZIP project bundle creation for records, failed URLs, skipped URLs, validation reports, manifest, checkpoint, logs, and summary report files.
- Lightweight built-in ZIP writer that does not require PHP `ext-zip`.
- Job manifest summary and export discovery.
- Basic CSV storage/export helpers.

### Source connectors

- Sitemap.xml reader support.
- Sitemap index support with nested sitemap discovery.
- Sitemap metadata extraction: `lastmod`, `changefreq`, and `priority`.
- RSS/Atom feed reader support.
- CSV URL source reader with configurable `--url-column`.
- JSON URL source reader with dot-path support, for example `items.*.url`.
- Generic JSON API URL extraction with `--path` and custom `--header` values.
- URL-list export from connector results using `source:urls`.
- Connector outputs in JSON, CSV, or TXT URL-list formats.
- Optional `--crawl` handoff from source connectors into `bulk:crawl`.
- PLOS API/feed command support.
- Elsevier/ScienceDirect API command support.
- Fallback discovery for sitemap, feeds, robots, and well-known source candidates.

### Optional/future-ready modules

- Optional browser-assisted crawling layer for JavaScript-rendered pages.
- Network profile and exit-point manager classes for future network policy expansion.
- Modular architecture ready for richer admin dashboards, Redis queues, browser-worker orchestration, role-based access, and ML-assisted intelligence.

## Package direction

- First public version: **1.0.0**
- Current version: **2.0.0** — Dashboard and admin UI update
- Professional PHP CLI framework
- Composer package with PSR-4 autoloading
- Symfony Console command layer for public usage
- Reusable PHP core classes for crawler, HTTP, parser, profile schemas, extractor rules, pipeline, manifest, checkpoint, exporter, reports, bundles, source connectors, queue workers, scheduling, monitoring, database storage, browser fallback, plugins, API router, and webhooks
- CMD, PowerShell, cron, Windows Task Scheduler, and server worker friendly
- Clean release package without `vendor/` or generated crawl outputs
- Single documentation file: this `README.md`

## Requirements

- PHP 8.2+
- Composer
- `ext-json`
- `ext-dom`
- `ext-mbstring`
- `symfony/console`
- `ext-curl` recommended for the cURL HTTP engine
- `ext-pdo` optional, only for database storage features
- `symfony/panther` optional, only for browser-assisted crawling
- Chrome/Chromium optional, only for browser-assisted crawling


## API and webhook examples

List available API routes:

```bash
php bin/mnb-scraper api:routes
php bin/mnb-scraper api:routes --json
```

Generate a Bearer token for the optional API server:

```bash
php bin/mnb-scraper api:token
php bin/mnb-scraper api:token --prefix=mnb_sk
```

Start the optional lightweight API server on localhost:

```bash
export MNB_SCRAPERKIT_API_TOKEN="paste-generated-token-here"
php bin/mnb-scraper api:serve --host=127.0.0.1 --port=8787
```

Check the health endpoint:

```bash
curl -H "Authorization: Bearer paste-generated-token-here" http://127.0.0.1:8787/api/v1/health
```

Create a queued job through the API:

```bash
curl -X POST http://127.0.0.1:8787/api/v1/jobs \
  -H "Authorization: Bearer paste-generated-token-here" \
  -H "Content-Type: application/json" \
  -d '{"job_id":"api-seo-job","command":"source:sitemap","args":["https://example.com/sitemap.xml"],"options":{"profile":"seo"}}'
```

Create a local webhook test event without making a network request:

```bash
php bin/mnb-scraper webhook:test --event=job.completed
```

Send a webhook payload to an authorized HTTP/HTTPS endpoint:

```bash
php bin/mnb-scraper webhook:send storage/jobs/job_001/job-manifest.json \
  --url=https://example.com/webhook \
  --event=job.completed \
  --webhook-header="X-Project: scraperkit"
```

Example `config/webhooks.json`:

```json
{
  "endpoints": [
    {
      "name": "ops",
      "url": "https://example.com/webhook",
      "events": ["job.completed", "job.failed"]
    }
  ]
}
```

## Plugin system examples

List discovered plugins:

```bash
php bin/mnb-scraper plugin:list
php bin/mnb-scraper plugin:list --json
```

Show the bundled example plugin:

```bash
php bin/mnb-scraper plugin:show mnb.example.profile-addon
```

Validate a plugin before installing or publishing it:

```bash
php bin/mnb-scraper plugin:validate plugins/example-profile-addon
php bin/mnb-scraper plugin:doctor
```

Install a local plugin into `storage/plugins/`:

```bash
php bin/mnb-scraper plugin:install path/to/my-plugin
php bin/mnb-scraper plugin:disable my.plugin.id
php bin/mnb-scraper plugin:enable my.plugin.id
```

Minimal plugin manifest shape:

```json
{
  "plugin_id": "vendor.project-addon",
  "name": "Project Add-on",
  "version": "1.0.0",
  "description": "Reusable profile and extractor rules for one project.",
  "enabled": true,
  "profiles": ["profiles/project-profile.json"],
  "rules": [],
  "commands": [
    {
      "name": "project:profile",
      "description": "Show the project profile.",
      "target": "profile:show",
      "args": ["project-profile"]
    }
  ]
}
```

After a plugin contributes a profile, the profile is available through normal profile commands:

```bash
php bin/mnb-scraper profile:list
php bin/mnb-scraper profile:show research-paper
php bin/mnb-scraper extract:rules https://example.com/article --profile=research-paper
```

## Profile schema and extractor rule examples

List built-in schemas:

```bash
php bin/mnb-scraper profile:list
```

Show the ecommerce schema:

```bash
php bin/mnb-scraper profile:show ecommerce
php bin/mnb-scraper profile:show ecommerce --json
```

Validate a custom schema file:

```bash
php bin/mnb-scraper profile:validate config/profiles/ecommerce.json
```

Test extraction rules against one URL:

```bash
php bin/mnb-scraper extract:rules https://example.com/product-page --profile=ecommerce
```

Use a profile schema during crawl and pipeline processing:

```bash
php bin/mnb-scraper crawl https://example.com/product-page --profile=ecommerce --pipeline --job-dir=storage/jobs/product-test
php bin/mnb-scraper pipeline:run storage/jobs/product-test/crawl.json --profile=ecommerce --output-dir=storage/jobs/product-test/pipeline
```

Simple custom profile schema shape:

```json
{
  "profile": "custom_product",
  "record_type": "product",
  "required_fields": ["title", "price", "url"],
  "dedupe_keys": ["sku", "canonical_url", "url"],
  "validators": {
    "url": "url",
    "price": "price"
  },
  "transformations": {
    "title": ["normalize_space"],
    "price": ["price"],
    "url": ["clean_url"]
  },
  "export_columns": ["record_id", "title", "price", "url", "quality_score"],
  "extraction_rules": {
    "title": {"fallback": [{"css": "h1"}, {"og": "title"}]},
    "price": {"css": ".price", "regex": "([0-9][0-9,]*(?:\\.[0-9]{1,2})?)"},
    "url": {"css": "link[rel=canonical]", "attr": "href", "url": true}
  }
}
```

## Export and report examples

Export pipeline records:

```bash
php bin/mnb-scraper export:records storage/jobs/job-id/pipeline/records.json --format=csv
php bin/mnb-scraper export:records storage/jobs/job-id/pipeline/records.json --format=xml
php bin/mnb-scraper export:records storage/jobs/job-id/pipeline/records.json --format=html
```

Export failed URLs:

```bash
php bin/mnb-scraper export:failed storage/jobs/job-id/crawl.json --format=csv --include-skipped
```

Export validation issues:

```bash
php bin/mnb-scraper export:validation storage/jobs/job-id/pipeline/records.json --format=csv
```

Generate a professional crawl summary:

```bash
php bin/mnb-scraper report:summary storage/jobs/job-id --format=html
php bin/mnb-scraper report:summary storage/jobs/job-id --format=json
```

Create a portable project bundle:

```bash
php bin/mnb-scraper bundle:create storage/jobs/job-id
```

## Installation

For normal Composer usage:

```bash
composer require mnb/scraperkit
```

For local development from this package:

```bash
composer install
php bin/mnb-scraper list
php tests/run-tests.php
```

After installing as a dependency, the binary is available as:

```bash
vendor/bin/mnb-scraper list
```

## Quick start

Crawl one page:

```bash
php bin/mnb-scraper crawl "https://example.com" --max-pages=1 --depth=0 --format=json
```

Run a small safe crawl:

```bash
php bin/mnb-scraper crawl "https://example.com" --max-pages=5 --depth=1 --delay-ms=1000 --format=json
```

Run crawl plus professional pipeline:

```bash
php bin/mnb-scraper crawl "https://example.com" --max-pages=5 --depth=1 --pipeline --pipeline-format=both --job-dir=storage/jobs/example
```

Run the professional pipeline on an existing crawl JSON file:

```bash
php bin/mnb-scraper pipeline:run storage/jobs/example/crawl.json --output=storage/jobs/example/pipeline --format=both
```

## Symfony Console commands

```text
crawl <url>                 Crawl one URL/site
http:test <url>             Test HTTP engine, redirects, headers, and challenge detection
bulk:crawl <urls.txt>       Crawl many URLs with checkpoint/resume
url:process <urls.txt>      Process URLs with retry/backoff/checkpoint
robots:test <url>           Inspect robots.txt decision
encoding:test <url>         Test encoding detection/conversion
common:extract <url>        Extract common data patterns
common:types                List common data types and profiles
plugin:list                 List discovered plugins
plugin:show <plugin-id>     Show one plugin manifest
plugin:validate <path>      Validate a plugin manifest
plugin:install <path>       Install a plugin into storage/plugins
plugin:enable <plugin-id>   Enable an installed plugin
plugin:disable <plugin-id>  Disable an installed plugin
plugin:doctor               Validate all discovered plugins
report:failed <crawl.json>  Build failed/skipped URL report
pipeline:run <crawl.json>   Run professional record pipeline
retry:failed <crawl.json>   Create retry URL list
export:records <records>    Export records to CSV/JSON
validate:records <records>  Validate records using required fields
job:summary <job-dir>       Show manifest and summaries
job:run <job.json>          Run crawl/bulk job from JSON config
source:discover <url>       Find safer source candidates
source:sitemap <source>     Read sitemap.xml or sitemap index URLs
source:rss <feed-url>       Read RSS/Atom feed records and URLs
source:csv <file.csv>       Read URLs from CSV using --url-column
source:json <file.json>     Read URLs from JSON using --path
source:api <endpoint>       Extract URLs from a generic JSON API
source:urls <source.json>   Export URL list from connector JSON
plos:*                      PLOS API/feed source commands
elsevier:*                  Elsevier/ScienceDirect API source commands
```

Useful help commands:

```bash
php bin/mnb-scraper list
php bin/mnb-scraper crawl --help
php bin/mnb-scraper pipeline:run --help
```

## Advanced retry, scheduling, and monitoring examples

Create a retry plan from a crawl output or failed URL report:

```bash
php bin/mnb-scraper retry:plan storage/jobs/example/crawl.json --output=storage/jobs/example/retry-plan.json
php bin/mnb-scraper retry:plan --failed-jobs --json
```

Retry only failed queue jobs that are safe to retry:

```bash
php bin/mnb-scraper queue:retry-safe
php bin/mnb-scraper queue:retry-safe --dry-run --json
```

Create a local schedule that enqueues a crawl job every hour:

```bash
php bin/mnb-scraper schedule:create --command=crawl https://example.com --every-minutes=60 --profile=seo
php bin/mnb-scraper schedule:list
php bin/mnb-scraper schedule:run-due
```

Use cron or Windows Task Scheduler to run due schedules periodically:

```bash
php bin/mnb-scraper schedule:run-due
php bin/mnb-scraper worker:run --stop-when-empty
```

Check queue/schedule/worker health:

```bash
php bin/mnb-scraper monitor:summary
php bin/mnb-scraper monitor:summary --json
php bin/mnb-scraper monitor:stale-locks --ttl-seconds=900
```

## Database storage examples

Initialize a default local SQLite database:

```bash
php bin/mnb-scraper db:init
```

Use an explicit SQLite file:

```bash
php bin/mnb-scraper db:init --sqlite=storage/database/project.sqlite
php bin/mnb-scraper db:status --sqlite=storage/database/project.sqlite
```

Save crawl output and pipeline records:

```bash
php bin/mnb-scraper db:save-crawl storage/jobs/example/crawl.json --job-id=example
php bin/mnb-scraper db:save-pipeline storage/jobs/example/pipeline/records.json --job-id=example
```

Export stored records:

```bash
php bin/mnb-scraper db:export mnb_storage_records --format=csv --output=records-from-db.csv
```

Use MySQL/MariaDB with a PDO DSN:

```bash
php bin/mnb-scraper db:init --database-url="mysql:host=127.0.0.1;dbname=mnb_scraperkit;charset=utf8mb4" --db-user=root --db-pass=secret
```

Database storage is optional. Normal JSON/CSV exports continue to work without SQLite, MySQL, or any database setup.

## Source connector examples

Read URLs from a sitemap and save JSON:

```bash
php bin/mnb-scraper source:sitemap "https://example.com/sitemap.xml" --rows=1000 --output=sitemap-source.json
```

Export sitemap URLs as a plain TXT list for bulk crawling:

```bash
php bin/mnb-scraper source:sitemap "https://example.com/sitemap.xml" --format=txt --output=urls.txt
php bin/mnb-scraper bulk:crawl urls.txt --delay-ms=1000 --pipeline
```

Read RSS/Atom records:

```bash
php bin/mnb-scraper source:rss "https://example.com/feed.xml" --rows=50 --format=json
```

Read URLs from CSV:

```bash
php bin/mnb-scraper source:csv urls.csv --url-column=url --format=txt --output=urls.txt
```

Read URLs from JSON with a dot path:

```bash
php bin/mnb-scraper source:json urls.json --path="items.*.url" --format=txt --output=urls.txt
```

Read URLs from a generic JSON API:

```bash
php bin/mnb-scraper source:api "https://api.example.com/items" --path="data.*.url" --header="Accept: application/json" --format=json
```

Export URLs from any connector JSON output:

```bash
php bin/mnb-scraper source:urls sitemap-source.json --output=urls.txt
```

Use `--crawl` on connector commands to hand the discovered URLs directly to `bulk:crawl`:

```bash
php bin/mnb-scraper source:sitemap "https://example.com/sitemap.xml" --format=txt --crawl --delay-ms=1000 --pipeline
```

## Professional pipeline

The pipeline converts crawled pages into normalized records that are easier to validate, deduplicate, export, and review.

Record shape:

```json
{
  "record_id": "rec_...",
  "record_type": "page",
  "profile": "page",
  "source_url": "https://example.com",
  "final_url": "https://example.com/",
  "fields": {},
  "validation": {
    "status": "valid",
    "issues": [],
    "missing_fields": []
  },
  "quality_score": 100,
  "dedupe_key": "sha256..."
}
```

Supported validation signals include:

- required fields
- URLs
- emails
- phones
- DOI
- ISSN
- ISBN
- dates
- prices

Supported transformations include:

- whitespace normalization
- URL cleanup
- ISO-style dates
- price-number extraction
- identifier casing
- field-name mapping

Example:

```bash
php bin/mnb-scraper pipeline:run crawl.json \
  --pipeline-profile=journal \
  --required-field=journal_name \
  --dedupe-key=journal_url \
  --field-map=journal_name:title \
  --format=both
```

## Common data profiles

ScraperKit is built for reusable extraction profiles instead of one-off scraping scripts.

| Profile | Typical use |
|---|---|
| `academic` / `journal` | Authors, editors, affiliations, DOI, ISSN, ISBN, ORCID, publisher, article metadata, journal data, PDF links, submission links, and deadlines |
| `conference` | Event names, speakers, organizers, venues, dates, registration links, CFP deadlines, and submission details |
| `government` / `tender` | Tender numbers, notification numbers, application numbers, deadlines, document links, contacts, addresses, fees, and eligibility |
| `ecommerce` | Product title, price, currency, SKU, brand, availability, images, ratings, reviews, variants, and structured data |
| `jobs` | Job title, company, location, salary, experience, skills, apply link, deadline, and recruiter contact |
| `seo` | Meta title, meta description, canonical URL, robots, schema, Open Graph, Twitter cards, headings, links, and sitemap hints |
| `contact` / `document` | Emails, phones, addresses, document URLs, file metadata, and page-level contact information |

## Browser-assisted crawling examples

Diagnose whether a page likely needs browser fallback:

```bash
php bin/mnb-scraper browser:test https://example.com --browser=auto --json
```

Use auto fallback during a crawl:

```bash
php bin/mnb-scraper crawl https://example.com --browser=auto --pipeline
```

Force browser rendering for one crawl:

```bash
php bin/mnb-scraper crawl https://example.com \
  --browser=always \
  --wait-selector=.product-title \
  --rendered-html \
  --screenshot \
  --job-dir=storage/jobs/browser-product-test
```

Create a queued browser fallback job:

```bash
php bin/mnb-scraper job:create --type=crawl https://example.com --profile=ecommerce --browser=auto
php bin/mnb-scraper worker:run --stop-when-empty
```

Browser mode is optional. Normal crawling works without Panther or Chrome. To enable the Panther adapter locally, install the optional dependency and browser driver support:

```bash
composer require symfony/panther
```

## Queue and worker examples

Create a queued sitemap source job:

```bash
php bin/mnb-scraper job:create --source=sitemap https://example.com/sitemap.xml --profile=seo
```

Create a queued CSV source job:

```bash
php bin/mnb-scraper job:create --source=csv urls.csv --url-column=url --profile=ecommerce
```

List queued jobs:

```bash
php bin/mnb-scraper job:list
```

Show one queued job:

```bash
php bin/mnb-scraper job:show JOB_ID
```

Run one queued job manually:

```bash
php bin/mnb-scraper job:run JOB_ID
```

Run one worker pass and exit:

```bash
php bin/mnb-scraper worker:once
```

Run a worker loop for server automation:

```bash
php bin/mnb-scraper worker:run --sleep=5 --max-jobs=10 --max-runtime=3600 --memory-limit=256M
```

Pause, resume, cancel, and retry:

```bash
php bin/mnb-scraper job:pause JOB_ID
php bin/mnb-scraper job:resume JOB_ID
php bin/mnb-scraper job:cancel JOB_ID
php bin/mnb-scraper queue:failed
php bin/mnb-scraper queue:retry JOB_ID
php bin/mnb-scraper queue:retry-all
```

The V1.4.0 queue foundation remains local and dependency-free. It is suitable for CMD, PowerShell, cron, Windows Task Scheduler, systemd, and Supervisor. Future versions can add database/Redis queue drivers without changing the crawl/pipeline core.


## Job manifest and checkpoint

When `--job-dir` is used, ScraperKit writes a `job-manifest.json` file containing:

- input
- scope
- request profile
- pacing
- extraction profile
- output settings
- resume/checkpoint metadata
- summary

Bulk and URL-processing checkpoints include these queue groups:

- pending
- completed
- failed
- skipped/challenge

This makes long jobs easier to resume, audit, and troubleshoot.

Useful pacing and safety-related options:

```bash
php bin/mnb-scraper crawl "https://example.com" \
  --max-pages=20 \
  --depth=1 \
  --delay-ms=1000 \
  --delay-jitter-ms=300 \
  --pause-after-urls=10 \
  --pause-seconds=30 \
  --cooldown-after-failures=3 \
  --cooldown-seconds=60
```

## Safety defaults

ScraperKit is safe by default:

- only HTTP/HTTPS URLs are allowed
- localhost targets are blocked
- URL userinfo credentials are blocked
- private, reserved, link-local, and metadata IP targets are blocked
- numeric IPv4 host forms are normalized and checked
- redirects are checked by the HTTP layer
- robots policy is respected unless explicitly disabled
- auth/login/cart style URLs are skipped by default
- challenge/protection pages are detected and reported
- gaps, pauses, and retry backoff are supported for responsible crawling

Use this library for public or authorized crawling, SEO audits, website diagnostics, permitted monitoring, and your own sites. Do not use it for access-control bypass, CAPTCHA bypass, paywall bypass, credential abuse, or aggressive traffic.

## Export outputs

ScraperKit focuses on practical export-ready outputs:

- crawl result JSON
- record JSON
- record CSV
- failed URL reports
- skipped URL reports
- validation issue summaries
- pipeline summaries
- job manifest summaries

Dashboard UI, PDF reports, Redis/distributed queues, browser-worker orchestration, and ML-assisted intelligence are future upgrade areas, not required for the current V1.x CLI release.

## Windows CMD

```cmd
scripts\run-crawl.cmd https://example.com 10 1
scripts\run-pipeline.cmd storage\jobs\example\crawl.json
```

## PowerShell

```powershell
.\scripts\run-crawl.ps1 -Url "https://example.com" -MaxPages 10 -Depth 1
.\scripts\run-pipeline.ps1 -Input "storage\jobs\example\crawl.json"
```

## Source connectors

ScraperKit includes source connector commands for API/feed-first workflows:

- PLOS journal catalog, search, feeds, and URL exports
- Elsevier/ScienceDirect search, metadata, DOI, serial, and URL exports
- RSS/Atom feed reader support
- fallback source discovery for sitemap, feeds, robots, and well-known endpoints

## Release package rules

This V2.0.0 dashboard and admin UI package intentionally keeps documentation simple: **README.md is the only project documentation file**.

The release package should not include generated runtime files:

- `vendor/`
- crawl outputs
- pipeline outputs
- checkpoint files
- cookie/session files

The `storage/` folder is kept with `.gitkeep`; generated files are ignored.

## Testing

Run:

```bash
php tests/run-tests.php
```

Optional Composer script:

```bash
composer test
```

## License

MIT License. See `LICENSE`.


## Dashboard usage

Start the local dashboard server:

```bash
php bin/mnb-scraper dashboard:serve
```

Open:

```text
http://127.0.0.1:8788/dashboard
```

Build a static dashboard snapshot:

```bash
php bin/mnb-scraper dashboard:build --output=storage/dashboard/index.html
```

Check dashboard status from CLI:

```bash
php bin/mnb-scraper dashboard:status --json
```

Protect the dashboard when exposing it outside localhost:

```bash
set MNB_SCRAPERKIT_DASHBOARD_TOKEN=your-token-here
php bin/mnb-scraper dashboard:serve
```

Then send `Authorization: Bearer your-token-here` or use `?token=your-token-here` for local testing.
