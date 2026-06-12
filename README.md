# MNB ScraperKit V4.1.1

**MNB ScraperKit** is a PHP-first professional crawling and data extraction framework for safe, resumable, pipeline-based web scraping.

V4.1.1 is the Real Publisher Metadata Crawling Pack. It builds on the V4.0.2 stability release and adds safe academic-publisher metadata workflows, a 37-publisher catalog, article metadata normalization, publisher seed exports, metadata-only crawl plans, and API/CLI coverage for scholarly article data pipelines.

ScraperKit is designed for developers, SEO analysts, research teams, academic metadata collectors, ecommerce monitors, tender/job/government data teams, and server automation users who need safe CLI crawling, bulk jobs, resumable checkpoints, normalized records, validation, transformations, exports, and reports.

## What makes it different

Most PHP scraping tools focus on fetching HTML and extracting selectors. MNB ScraperKit is designed around a complete professional crawl flow:

```text
URL -> Safe Request -> Crawl Result -> Normalized Record -> Validate -> Dedupe -> Transform -> Export -> Report/Resume
```

The strongest part of the library is the **professional crawl pipeline**. It turns crawled pages into structured records with metadata, validation status, quality scoring, deduplication keys, failed URL handling, and export-ready output.

## V4.1.1 update focus

V4.1.1 is a focused academic metadata upgrade. It does not try to bypass publisher protections or scrape paywalled full text. Instead, it adds safe, metadata-first crawling and normalization tools for journals, articles, DOI records, public TOC pages, sitemaps, feeds, and official APIs where available.

- Added `config/publishers/academic-publishers.json` with 37 publisher crawl targets from Elsevier through Columbia University Press.
- Added `publisher:list`, `publisher:show`, `publisher:seeds`, `publisher:plan`, `publisher:schema`, and `publisher:normalize`.
- Added `PublisherCatalog` for crawl-flexibility metadata, seed export rows, safe crawl defaults, and job-plan generation.
- Added `ArticleMetadataNormalizer` for DOI normalization, ISSN cleanup, author normalization, and export-ready scholarly article rows.
- Expanded the built-in `academic` profile with citation meta tags, DOI, ISSN/eISSN, journal, publisher, volume, issue, abstract, PDF, canonical URL, license, and open-access metadata fields.
- Added `/api/v1/publishers`, `/api/v1/publishers/{publisher_id}`, and `/api/v1/publishers/schema` routes for local dashboard/API use.
- Added publisher seed examples under `examples/publisher-seeds/` plus a ready metadata plan job example.
- Added real tests for publisher catalog validation, article metadata normalization, native publisher commands, and API publisher routes.
- Kept V4.0.2 CLI parser, packaging, CI, release-hygiene, command compatibility, and production-readiness hardening.

## Highlights

- **Hardening and production readiness** with CI workflow, release hygiene checks, public command compatibility validation, local benchmarks, improved error guidance, and duplicate command dispatch checks.
- **Enterprise project workspaces and access control metadata** for local/team project organization, user roles, workspace membership, audit events, and dashboard/API summaries without storing passwords.
- **Professional PHP CLI framework** built as a Composer package with Symfony Console commands.
- **Security audit and compliance toolkit** for release hygiene, secret scanning, responsible crawling policy checks, browser-session safety review, plugin/config checks, and JSON/HTML compliance reports.
- **Project templates and preset packs** for ready-to-run SEO, ecommerce, academic, tender, and research workflows with generated command files and job manifests.
- **Advanced export connectors** for local artifact delivery, webhook payload automation, checksum manifests, connector validation, and downstream workflow handoff.
- **Distributed workers and optional Redis queue** with adapter auto-selection, file fallback, job leases, heartbeats, distributed worker loops, and multi-worker deployment support.
- **Advanced browser sessions for authorized workflows** with allowed-domain session profiles, manual login assist, cookie/session artifacts, session tests, and `--session` crawl support.
- **Rule builder and auto-profile assistant** for analyzing HTML, suggesting profile types, generating starter schemas, testing rules, scaffolding profiles, and finding rule gaps.
- **Evaluation, benchmarking, and training data quality layer** for field completeness, validation health, duplicate analysis, profile benchmarking, selector performance, annotation coverage, and training-ready exports.
- **Dataset versioning and annotation layer** for dataset snapshots, quality summaries, JSON/CSV/JSONL exports, dataset diffs, and review labels.
- **ML-ready intelligence layer** for feature extraction, page classification, quality prediction, URL priority scoring, and selector suggestions.
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
- **Future-ready architecture** designed for later expansion into richer dashboards, advanced browser-worker orchestration, role-based access, and trainable ML models.


## Complete feature list

This section lists the main functionality available in the current V4.1.1 CLI/library release.

### Package and CLI

- Composer-installable PHP package with PSR-4 autoloading.
- Symfony Console based command-line application.
- Reusable framework-independent PHP core classes.
- Global binary support through `vendor/bin/mnb-scraper`.
- Built-in command list and per-command help screens.
- CMD, PowerShell, cron, Windows Task Scheduler, and server automation friendly scripts/workflows.

### Production readiness and hardening

- `hardening:doctor` runs production-readiness diagnostics for CI, release hygiene, command contracts, duplicate dispatch cases, optional runtimes, storage cleanliness, and backward compatibility posture.
- `ci:check` runs strict repo/CI checks without failing just because Composer installed `vendor/` locally.
- `release:check [archive-dir]` runs final package checks and fails if the archive includes `vendor/`, `.git/`, `composer.lock`, or generated `storage/` outputs.
- `benchmark:run` runs deterministic local micro-benchmarks without network calls.
- `compat:commands` prints or validates the public command and option compatibility contract.
- `.github/workflows/ci.yml` validates Composer metadata, installs dependencies, lints PHP files, runs tests, runs `ci:check`, builds a `git archive`, runs `release:check` on that archive, and performs a benchmark smoke test on PHP 8.2, 8.3, and 8.4.
- CLI errors now include command context, help guidance, and diagnostics hints. Unknown commands include best-effort suggestions.
- V4.1.1 keeps the hardening trait boundary and fixes native CLI option parsing; future maintenance releases can continue splitting command groups.

Examples:

```bash
php bin/mnb-scraper hardening:doctor
php bin/mnb-scraper ci:check --strict
php bin/mnb-scraper release:check /path/to/extracted-release --strict
php bin/mnb-scraper benchmark:run --iterations=1000
php bin/mnb-scraper compat:commands --validate
```

### Backward compatibility policy

- Patch releases such as V4.1.1 should not remove public commands, rename public options, or change default output behavior without a compatibility alias.
- Minor releases may add new commands, options, profiles, connectors, and optional integrations while keeping older workflows usable.
- Major releases may remove deprecated functionality only after migration guidance is added to README examples and command compatibility notes.

### Academic publisher metadata crawling

V4.1.1 adds safe publisher metadata workflows for academic journal/article discovery. The default model is **metadata only**: prefer official APIs, public sitemaps, RSS/Atom feeds, DOI/Crossref-style metadata, and public article landing pages. Do not bypass paywalls, CAPTCHAs, authentication, or access controls.

Commands:

```bash
php bin/mnb-scraper publisher:list --json
php bin/mnb-scraper publisher:show elsevier
php bin/mnb-scraper publisher:seeds --format=txt --output=storage/publisher-seeds.txt
php bin/mnb-scraper publisher:plan --max-pages=10 --delay-ms=3500 --output=storage/publisher-crawl-jobs.json
php bin/mnb-scraper publisher:schema --format=csv --output=storage/article-schema.csv
php bin/mnb-scraper publisher:normalize storage/source-records.json --publisher=elsevier --output=storage/article-metadata.json
```

Included catalog:

```text
config/publishers/academic-publishers.json
examples/publisher-seeds/academic-publishers.csv
examples/publisher-seeds/*.txt
examples/publisher-metadata-plan-job.json
```

The normalized article metadata schema covers title, subtitle, authors, DOI, normalized DOI, ISSN/eISSN, journal, publisher, volume, issue, page range, publication date, article type, abstract, URL, HTML/PDF URL, license, open-access marker, source, and quality score.

### Enterprise project workspaces and access control

- `enterprise:doctor` shows workspace, user, role, and audit readiness.
- `enterprise:roles` prints the built-in role capability map.
- `workspace:create <name>` creates a local project workspace manifest.
- `workspace:list` lists project workspaces.
- `workspace:show <workspace>` shows one workspace manifest.
- `workspace:assign-user <workspace> <user> --role=operator` assigns a user to a workspace.
- `user:create <user>` creates a local user metadata record without storing passwords.
- `user:list` lists user metadata records.
- `user:disable <user>` disables a user metadata record.
- `audit:events` lists recent enterprise audit events.
- Workspace files live under `storage/enterprise/workspaces/`.
- User metadata lives in `storage/enterprise/users.json`.
- Audit events live in append-only JSONL form under `storage/enterprise/audit-events.jsonl`.
- Read-only API routes expose enterprise summary, workspaces, users, and audit events for dashboard/internal automation.

Example:

```bash
php bin/mnb-scraper user:create admin@example.com --display-name="Admin User" --role=owner
php bin/mnb-scraper workspace:create seo-team --owner=admin@example.com --profile=seo
php bin/mnb-scraper workspace:assign-user seo-team analyst@example.com --role=analyst
php bin/mnb-scraper enterprise:doctor
php bin/mnb-scraper audit:events --limit=20
```

### Security audit and compliance toolkit

- `security:audit` runs a package/project audit for release hygiene, secrets, config validity, browser sessions, plugins, generated storage files, and public API/dashboard surfaces.
- `security:doctor` prints a concise security score and recommended actions.
- `security:secrets-scan` scans local files for common committed secret patterns.
- `security:policy` prints or writes a responsible crawling policy template.
- `compliance:report` generates JSON or HTML compliance output for maintainers, internal teams, and release reviews.
- `config/compliance-policy.example.json` documents safe defaults for responsible crawling, release hygiene, and secret handling.
- API routes expose read-only security/compliance summaries for local admin/dashboard integrations.

Example:

```bash
php bin/mnb-scraper security:audit --format=html --output=storage/security-audit.html
php bin/mnb-scraper security:doctor
php bin/mnb-scraper security:secrets-scan
php bin/mnb-scraper compliance:report --format=html --output=storage/compliance-report.html
```

### Project templates and preset packs

- `template:list` lists bundled project templates.
- `template:show <template>` displays one template manifest and generated file plan.
- `template:validate <template>` validates template JSON and generated file paths.
- `template:create <template> --output-dir=projects/name --name=name` creates a ready-to-run project workspace.
- `preset:list` lists bundled preset packs.
- `preset:show <pack>` displays grouped profiles, templates, and workflow files.
- `preset:validate <pack>` checks referenced profiles and templates.
- `preset:install <pack> --output-dir=presets/name` installs a preset pack into a local project folder.
- Bundled project templates include `seo-audit`, `ecommerce-monitor`, `academic-metadata`, and `tender-monitor`.
- Bundled preset packs include `seo-research-pack` and `commerce-gov-pack`.

### Distributed workers and Redis queue

- `distributed:doctor` checks selected adapter, Redis availability, queue namespace, worker group, and file fallback status.
- `distributed:status` shows pending, leased, completed, and failed distributed queue counts.
- `distributed:enqueue --command=crawl --arg=https://example.com` adds one command payload to the distributed queue.
- `distributed:reserve` reserves one job for debugging worker leases.
- `distributed:ack <job-id>` marks a leased job as completed.
- `distributed:fail <job-id>` marks a leased job as failed with a message.
- `distributed:heartbeat <job-id>` refreshes a job lease heartbeat.
- `distributed:purge --force` clears distributed queue state for local/dev cleanup.
- `worker:distributed` runs distributed jobs using Redis when configured, or the file adapter fallback otherwise.
- Distributed jobs use worker IDs, lease IDs, visibility timeouts, and heartbeats so crashed workers can be recovered.
- Redis is optional. Use `--distributed-adapter=file` for local fallback or `--distributed-adapter=redis --redis-url=redis://127.0.0.1:6379/0` for Redis.

Example:

```bash
php bin/mnb-scraper distributed:doctor --distributed-adapter=auto --json
php bin/mnb-scraper distributed:enqueue --command=crawl --arg=https://example.com --pipeline --distributed-adapter=file
php bin/mnb-scraper worker:distributed --distributed-adapter=file --stop-when-empty --max-jobs=5
```

Redis example:

```bash
set MNB_SCRAPERKIT_REDIS_URL=redis://127.0.0.1:6379/0
php bin/mnb-scraper distributed:enqueue --command=bulk:crawl --arg=urls.txt --distributed-adapter=redis
php bin/mnb-scraper worker:distributed --distributed-adapter=redis --worker-group=seo-workers --max-jobs=100
```

### Advanced export connectors

- `export:connector-list` lists configured export delivery connectors.
- `export:connector-show <connector-id>` shows one connector definition.
- `export:connector-validate` checks connector IDs, types, local target paths, and webhook endpoints.
- `export:connector-test <connector-id>` creates a sample artifact and dry-runs the connector.
- `export:manifest <file|dir>` builds a checksum manifest with size, extension, SHA-256, and modified time.
- `export:deliver <connector-id> --file=records.json --file=report.html` delivers selected artifacts.
- Local connectors copy artifacts into a delivery folder with `delivery-manifest.json`.
- Webhook connectors create a JSON payload and only send when `--send` is explicitly used.
- Connector configuration is stored in `config/export-connectors.json`; a safe example is provided in `config/export-connectors.example.json`.

Example:

```bash
php bin/mnb-scraper export:connector-list
php bin/mnb-scraper export:connector-validate
php bin/mnb-scraper export:manifest storage/jobs/example --output=storage/jobs/example/export-manifest.json
php bin/mnb-scraper export:deliver local_exports --dir=storage/jobs/example
php bin/mnb-scraper export:connector-test webhook_dry_run
```

### Advanced browser sessions and authorized login workflows

- `browser:session-create <name> --domain=example.com --login-url=https://example.com/login` creates a domain-guarded browser session profile.
- `browser:session-list` lists stored session profiles and cookie/session files.
- `browser:session-show <name>` shows one session profile and its safety metadata.
- `browser:session-clear <name>` removes session cookies and artifacts; add `--remove-profile` to remove the profile too.
- `browser:login <name> --url=https://example.com/login` writes manual login instructions and prepares the session for an authorized login flow.
- `browser:session-test <name> <url> --render` tests a session against an allowed URL using the optional browser adapter.
- `crawl <url> --browser=auto --session=<name>` uses the session profile during browser fallback or browser rendering.
- Session profiles require allowed domains and block URLs outside that allowlist.
- Passwords are not stored by default. Session files are intended for authorized, user-controlled workflows only.
- Cookie import/export is best-effort and depends on the optional browser adapter/driver support. Normal HTTP crawling still works without browser dependencies.

Example:

```bash
php bin/mnb-scraper browser:session-create client_portal --domain=example.com --login-url=https://example.com/login
php bin/mnb-scraper browser:login client_portal --url=https://example.com/login
php bin/mnb-scraper browser:session-test client_portal https://example.com/dashboard --browser=always --render --json
php bin/mnb-scraper crawl https://example.com/dashboard --browser=auto --session=client_portal --pipeline
```

### Rule builder and auto-profile assistant

- `rule:analyze <html-file|url>` inspects saved HTML or one URL and reports title, metadata, headings, JSON-LD types, keyword signals, candidate selectors, and suggested profile type.
- `rule:generate <html-file|url> --profile=auto --name=my-profile --output=config/profiles/my-profile.json` creates a starter profile schema.
- `rule:test <html-file|url> --profile=my-profile` tests existing/generated extraction rules locally before running a crawl.
- `rule:doctor <profile|profile.json> --input=sample.html` checks profile schema validity, missing required-field rules, undeclared rule fields, and sample extraction gaps.
- `profile:scaffold <name> --profile=seo|ecommerce|jobs|tender|academic` creates a new profile schema template.
- Auto-profile suggestions currently target SEO/page, ecommerce/product, jobs, tender/government notice, and academic/article workflows.
- Generated schemas include required fields, optional fields, validators, transformations, dedupe keys, export columns, and extraction rules.
- Rule builder works with saved HTML files first, so users can develop selectors safely without repeatedly hitting websites.

Example:

```bash
php bin/mnb-scraper rule:analyze examples/sample-product-page.html --json
php bin/mnb-scraper rule:generate examples/sample-product-page.html --profile=auto --name=my-product --output=config/profiles/my-product.json
php bin/mnb-scraper rule:test examples/sample-product-page.html --profile-file=config/profiles/my-product.json
php bin/mnb-scraper rule:doctor config/profiles/my-product.json --input=examples/sample-product-page.html
```

### Evaluation, benchmarking, and training data quality

- `eval:dataset <dataset-id|manifest.json>` evaluates dataset completeness, duplicates, validation health, annotation coverage, and training readiness.
- `eval:pipeline <pipeline.json>` evaluates pipeline output directly without first creating a dataset snapshot.
- `eval:profile <profile> --dataset=DATASET_ID` evaluates a profile schema against dataset records.
- `eval:selectors --profile=PROFILE --dataset=DATASET_ID` reports selector/field success, empty fields, and example failed records.
- `benchmark:profile <profile> --dataset=DATASET_ID` measures profile field success and profile grade.
- `benchmark:compare <old> <new>` compares quality, record count, duplicate rate, and training-readiness changes between two datasets.
- `annotation:stats <dataset>` shows label counts, field counts, annotated record totals, and coverage percentage.
- `annotation:coverage <dataset>` gives quick annotation coverage for review and training readiness.
- `annotation:export <dataset> --format=jsonl|json|csv` exports annotation rows with labels, notes, source URL, text, fields, and quality score.
- `dataset:export <dataset> --training-ready --format=jsonl` creates ML-friendly rows with `text`, `label`, `fields`, `quality_score`, and metadata.

### Dataset versioning and annotations

- `dataset:create <input.json|urls.txt>` creates versioned dataset snapshots from crawl, pipeline, source, intelligence, or URL-list data.
- `dataset:list` lists local dataset snapshots.
- `dataset:show <dataset-id|manifest.json>` shows one dataset manifest and quality summary.
- `dataset:diff <old> <new>` compares two dataset snapshots.
- `dataset:export <dataset-id|manifest.json>` exports normalized records as JSON, CSV, or JSONL. Add `--training-ready` for ML-friendly JSONL/CSV/JSON exports.
- `annotation:init <dataset-dir>` creates an annotation file for review labels.
- `annotation:add <annotations.json>` adds labels, notes, field comments, and reviewer metadata.
- Dataset folders include `dataset-manifest.json`, `records.json`, `records.jsonl`, `quality-summary.json`, and `annotations.json`.

### ML-ready intelligence

- `intelligence:doctor` shows available intelligence tools and optional PHP-ML availability.
- `intelligence:analyze <input.json>` extracts ML-ready page, record, and URL features.
- `intelligence:classify <input.json>` classifies crawled pages into useful workflow groups.
- `intelligence:quality <input.json>` predicts page and record quality labels with explainable reasons.
- `intelligence:priority <urls.txt|source.json>` ranks URLs so high-value crawl targets can run first.
- `intelligence:selectors <html-file>` suggests profile-aware selectors for saved HTML.
- Works without external ML dependencies. Optional PHP-ML integration can be added later using the exported feature JSON.

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
- Safe-by-default design: V4.1.1 does not automatically execute arbitrary plugin PHP code.

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

- Advanced export connector layer for local delivery folders and webhook automation payloads.
- Export checksum manifests with SHA-256, file sizes, extensions, and modified timestamps.
- Export connector validation, dry-run testing, and delivery result manifests.
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
- Modular architecture ready for richer admin dashboards, advanced browser-worker orchestration, role-based access, distributed deployments, and ML-assisted intelligence.

## Package direction

- First public version: **1.0.0**
- Current version: **4.1.1** — CLI Parser, Packaging, and CI Fix Update
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

PDF reports and richer role-based enterprise orchestration remain future upgrade areas. The current V4.1.1 release already includes CLI workflows, source connectors, exports/reports/bundles, export delivery connectors, local and distributed queue/worker commands, optional Redis queue support, optional browser-assisted crawling, API/webhooks, dashboard UI, ML-ready intelligence, dataset versioning, and annotation tools.

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

This V4.1.1 package intentionally keeps documentation simple: **README.md is the only project documentation file**.

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

## Evaluation and training data quality examples

Evaluate one dataset:

```bash
php bin/mnb-scraper eval:dataset dataset_products --profile=ecommerce --format=html --output=storage/reports/products-evaluation.html
```

Evaluate pipeline output directly:

```bash
php bin/mnb-scraper eval:pipeline storage/jobs/job-001/pipeline/records.json --profile=ecommerce --json
```

Benchmark a profile against a dataset:

```bash
php bin/mnb-scraper benchmark:profile ecommerce --dataset=dataset_products --json
```

Measure selector/field success for a profile:

```bash
php bin/mnb-scraper eval:selectors --profile=ecommerce --dataset=dataset_products --output=storage/reports/selector-report.json
```

Check annotation coverage and export labels:

```bash
php bin/mnb-scraper annotation:stats dataset_products --json
php bin/mnb-scraper annotation:export dataset_products --format=jsonl --output=storage/datasets/products/annotations.jsonl
```

Export training-ready data:

```bash
php bin/mnb-scraper dataset:export dataset_products --format=jsonl --training-ready --training-type=classification
```

## Dataset versioning examples

Create a dataset snapshot from pipeline records:

```bash
php bin/mnb-scraper dataset:create storage/jobs/example/pipeline/records.json --id=example_dataset
```

List and inspect datasets:

```bash
php bin/mnb-scraper dataset:list
php bin/mnb-scraper dataset:show example_dataset
```

Export normalized dataset records:

```bash
php bin/mnb-scraper dataset:export example_dataset --format=csv --output=storage/datasets/example_dataset/export.csv
```

Compare two dataset snapshots:

```bash
php bin/mnb-scraper dataset:diff old_dataset new_dataset --json
```

Initialize annotations and add review labels:

```bash
php bin/mnb-scraper annotation:init storage/datasets/example_dataset
php bin/mnb-scraper annotation:add storage/datasets/example_dataset/annotations.json --record-id=dsrec_123 --label=good --note="Ready for training"
```

## ML-ready intelligence examples

Analyze crawl or pipeline output and export features:

```bash
php bin/mnb-scraper intelligence:analyze storage/jobs/example/crawl.json --output=storage/intelligence/features.json
```

Classify pages and recommend profiles:

```bash
php bin/mnb-scraper intelligence:classify storage/jobs/example/crawl.json --output=storage/intelligence/classes.json
```

Predict quality for pages and records:

```bash
php bin/mnb-scraper intelligence:quality storage/jobs/example/pipeline.json --output=storage/intelligence/quality.json
```

Prioritize URLs before crawling:

```bash
php bin/mnb-scraper intelligence:priority urls.txt --format=txt --output=priority-urls.txt
```

Suggest selectors from saved HTML:

```bash
php bin/mnb-scraper intelligence:selectors page.html --profile=ecommerce --output=selectors.json
```

## Project template examples

List available templates:

```bash
php bin/mnb-scraper template:list
```

Create an SEO audit project workspace:

```bash
php bin/mnb-scraper template:create seo-audit --output-dir=projects/seo-audit --name=seo-audit
```

Create an ecommerce monitoring workspace:

```bash
php bin/mnb-scraper template:create ecommerce-monitor --output-dir=projects/products --name=products
```

Install a preset pack with grouped profiles and workflow examples:

```bash
php bin/mnb-scraper preset:install commerce-gov-pack --output-dir=presets/commerce-gov
```

Validate a template before sharing it with a team:

```bash
php bin/mnb-scraper template:validate seo-audit
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

### V4.1.1 Enterprise Publisher Graph Crawling

MNB ScraperKit now models real academic publisher crawling as a metadata-first navigation graph:

```text
publisher/about page -> journal/book indexes -> journal landing pages -> volumes/issues or book chapters -> article/chapter metadata pages -> normalized article records
```

New commands:

```bash
php bin/mnb-scraper publisher:graph springer --json
php bin/mnb-scraper publisher:enterprise-plan springer --max-journals=10 --max-books=10 --max-issues=10 --max-articles=25 --output=storage/springer-enterprise-plan.json
php bin/mnb-scraper publisher:extract-article saved-springer-article.html --publisher=springer --url=https://link.springer.com/article/10.1007/s007770050003 --output=storage/article.json
```

The publisher graph supports journal/book listing pages, book landing URLs, journal volume/issue tables of contents, article/chapter URLs, and detailed metadata fields such as title, article type, published date, authors, affiliation/contact metadata when public, abstract, DOI, keywords, and references. It remains metadata-only by default and does not include paywall, CAPTCHA, or access-control bypass.
