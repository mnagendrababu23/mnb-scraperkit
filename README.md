# MNB ScraperKit V1.1.0

**MNB ScraperKit** is a PHP-first professional crawling and data extraction framework for safe, resumable, pipeline-based web scraping.

V1.1.0 is the Source Connectors Update. It adds first-class connector commands for sitemap, RSS/Atom, CSV, JSON, and generic JSON API URL sources, while keeping the Symfony Console CLI and framework-independent PHP crawler core.

ScraperKit is designed for developers, SEO analysts, research teams, academic metadata collectors, ecommerce monitors, tender/job/government data teams, and server automation users who need safe CLI crawling, bulk jobs, resumable checkpoints, normalized records, validation, transformations, exports, and reports.

## What makes it different

Most PHP scraping tools focus on fetching HTML and extracting selectors. MNB ScraperKit is designed around a complete professional crawl flow:

```text
URL -> Safe Request -> Crawl Result -> Normalized Record -> Validate -> Dedupe -> Transform -> Export -> Report/Resume
```

The strongest part of the library is the **professional crawl pipeline**. It turns crawled pages into structured records with metadata, validation status, quality scoring, deduplication keys, failed URL handling, and export-ready output.

## V1.1.0 update focus

V1.1.0 focuses on source connectors so users can collect crawl targets before running bulk crawls or pipelines.

- Added `source:sitemap` for sitemap.xml and sitemap index URL extraction.
- Added `source:rss` for generic RSS/Atom feed records and URLs.
- Added `source:csv` for CSV URL lists with configurable URL columns.
- Added `source:json` for JSON URL lists using dot paths such as `items.*.url`.
- Added `source:api` for generic JSON API endpoints using headers and URL extraction paths.
- Added `source:urls` to convert connector JSON output into plain URL lists.
- Added connector outputs in JSON, CSV, and TXT URL-list formats.
- Kept V1.0.1 safety, checkpoint, failure classification, and pipeline improvements.

## Highlights

- **Professional PHP CLI framework** built as a Composer package with Symfony Console commands.
- **Safe crawling controls** including URL safety checks, redirect safety, scope rules, robots-aware behavior, userinfo blocking, URL length limits, and private/reserved IP protection.
- **Bulk crawling support** for processing many URLs with pacing, random jitter, cooldowns, checkpointing, failed queues, skipped queues, and resume support.
- **Manifest-driven jobs** for reproducible crawl configuration, including input URLs, scope, pacing, extraction profile, output settings, and resume state.
- **Professional crawl pipeline** that converts raw page results into normalized records with metadata, validation, deduplication, transformation, quality scoring, and exports.
- **Common data profiles** for academic, journal, conference, ecommerce, government, tender, jobs, SEO, contact, and document-focused extraction workflows.
- **Export-ready outputs** including structured JSON and CSV results for crawl data, records, failed URLs, skipped URLs, validation issues, and pipeline summaries.
- **Automation friendly** for PHP CLI, CMD, PowerShell, cron, Windows Task Scheduler, and server-side workflows.
- **Source connector system** for collecting crawl targets from sitemaps, RSS/Atom feeds, CSV files, JSON files, generic JSON APIs, PLOS, and Elsevier/ScienceDirect.
- **Future-ready architecture** designed for later expansion into dashboards, workers, richer reports, API/webhooks, and ML-assisted intelligence.


## Complete feature list

This section lists the main functionality available in the current V1.1.0 CLI/library release.

### Package and CLI

- Composer-installable PHP package with PSR-4 autoloading.
- Symfony Console based command-line application.
- Reusable framework-independent PHP core classes.
- Global binary support through `vendor/bin/mnb-scraper`.
- Built-in command list and per-command help screens.
- CMD, PowerShell, cron, Windows Task Scheduler, and server automation friendly scripts/workflows.

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
- JSON-LD extraction support.
- Common data extraction for emails, phones, metadata, links, documents, and profile-oriented data.
- Common data type/profile listing command.

### Common data profiles

- Academic and journal extraction profile direction.
- Conference extraction profile direction.
- Government and tender extraction profile direction.
- Ecommerce extraction profile direction.
- Jobs extraction profile direction.
- SEO extraction profile direction.
- Contact and document extraction profile direction.

### Bulk jobs and resume

- Bulk URL crawl from text file.
- Checkpoint files for long-running jobs.
- Resume support from saved checkpoint state.
- Pending, completed, failed, skipped, challenge, and retry queue tracking.
- Pause after N URLs.
- Rest gaps and jitter for less aggressive crawling.
- Job summary command for inspecting job output and manifest state.

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

### Export and storage

- Crawl result JSON export.
- Pipeline record JSON export.
- Pipeline record CSV export.
- Failed URL reports.
- Skipped URL reports.
- Validation issue summaries.
- Pipeline summary JSON.
- Job manifest summary.
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

- Browser adapter structure for future browser-assisted crawling workflows.
- Network profile and exit-point manager classes for future network policy expansion.
- Modular architecture ready for future dashboard, workers, API/webhooks, richer reports, and ML-assisted intelligence.

## Package direction

- First public version: **1.0.0**
- Current version: **1.1.0** — source connectors update
- Professional PHP CLI framework
- Composer package with PSR-4 autoloading
- Symfony Console command layer for public usage
- Reusable PHP core classes for crawler, HTTP, parser, pipeline, manifest, checkpoint, exporter, and source connectors
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

Dashboard, PDF reports, large worker queues, browser-worker orchestration, API/webhooks, and ML-assisted intelligence are future upgrade areas, not required for the V1.0 CLI release.

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

This V1.1.0 package intentionally keeps documentation simple: **README.md is the only project documentation file**.

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
