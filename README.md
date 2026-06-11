# MNB ScraperKit V1.0

**MNB ScraperKit** is a PHP-first professional crawling and data extraction framework for safe, resumable, pipeline-based web scraping.

V1.0 is the first public release. It is packaged as a Composer library with a Symfony Console command-line interface, while the crawler core remains reusable framework-independent PHP code.

ScraperKit is designed for developers, SEO analysts, research teams, academic metadata collectors, ecommerce monitors, tender/job/government data teams, and server automation users who need safe CLI crawling, bulk jobs, resumable checkpoints, normalized records, validation, transformations, exports, and reports.

## What makes it different

Most PHP scraping tools focus on fetching HTML and extracting selectors. MNB ScraperKit is designed around a complete professional crawl flow:

```text
URL -> Safe Request -> Crawl Result -> Normalized Record -> Validate -> Dedupe -> Transform -> Export -> Report/Resume
```

The strongest part of the library is the **professional crawl pipeline**. It turns crawled pages into structured records with metadata, validation status, quality scoring, deduplication keys, failed URL handling, and export-ready output.

## Highlights

- **Professional PHP CLI framework** built as a Composer package with Symfony Console commands.
- **Safe crawling controls** including URL safety checks, redirect safety, scope rules, robots-aware behavior, and private IP protection.
- **Bulk crawling support** for processing many URLs with pacing, checkpointing, failed queues, skipped queues, and resume support.
- **Manifest-driven jobs** for reproducible crawl configuration, including input URLs, scope, pacing, extraction profile, output settings, and resume state.
- **Professional crawl pipeline** that converts raw page results into normalized records with metadata, validation, deduplication, transformation, quality scoring, and exports.
- **Common data profiles** for academic, journal, conference, ecommerce, government, tender, jobs, SEO, contact, and document-focused extraction workflows.
- **Export-ready outputs** including structured JSON and CSV results for crawl data, records, failed URLs, skipped URLs, validation issues, and pipeline summaries.
- **Automation friendly** for PHP CLI, CMD, PowerShell, cron, Windows Task Scheduler, and server-side workflows.
- **Future-ready architecture** designed for later expansion into source connectors, dashboards, workers, APIs, reports, and ML-assisted intelligence.

## Package direction

- First public version: **1.0.0**
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
plos:*                      PLOS API/feed source commands
elsevier:*                  Elsevier/ScienceDirect API source commands
```

Useful help commands:

```bash
php bin/mnb-scraper list
php bin/mnb-scraper crawl --help
php bin/mnb-scraper pipeline:run --help
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

## Safety defaults

ScraperKit is safe by default:

- only HTTP/HTTPS URLs are allowed
- localhost targets are blocked
- private, reserved, and link-local IP targets are blocked
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

This V1.0 package intentionally keeps documentation simple: **README.md is the only project documentation file**.

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
