<?php

declare(strict_types=1);

return [
    'app_name' => 'MNB ScraperKit',

    'crawl' => [
        'max_pages' => 100,
        'max_depth' => 3,
        'delay_ms' => 500,
        'timeout_seconds' => 30,
        'http_engine' => 'auto', // auto | curl | stream
        'request_headers' => [],
        'verify_ssl' => true,
        'same_domain' => true,
        'respect_robots' => true,
        'user_agent' => 'MNB-ScraperKit/3.1.0 (+https://example.com)',
        'max_response_bytes' => 5242880,
        'skip_auth_links' => true,
        'avoid_duplicate_final_urls' => true,
        'stay_under_start_path' => false,
        'extract_preset' => null,
        'skip_url_patterns' => [
            '/signup-login*',
            '/login*',
            '/sign-in*',
            '/logout*',
            '/account*',
            '/cart*',
            '/checkout*',
        ],
        'allow_path_patterns' => [],
        'use_cookie_jar' => true,
        'cookie_jar_path' => __DIR__ . '/../storage/sessions/default-cookiejar.txt',
        'max_redirects' => 5,
        'strip_final_url_query_params' => true,
        'final_url_strip_params' => [
            'error', 'code', 'utm_source', 'utm_medium', 'utm_campaign',
            'utm_term', 'utm_content', 'fbclid', 'gclid'
        ],
        'skip_identity_provider_final_urls' => true,
        'identity_provider_host_patterns' => [
            'idp.*', '*.idp.*', 'login.*', 'auth.*', 'sso.*'
        ],
        'same_final_host' => false,
        'skip_final_host_patterns' => [
            'idp.springer.com'
        ],
        'common_data' => false,
        'common_data_profile' => null,
        'common_data_types' => [
            'all'
        ],
        'common_data_profiles' => [
            'academic', 'journal', 'conference', 'education', 'ecommerce',
            'government', 'tender', 'jobs', 'seo', 'contact_directory', 'all'
        ],
    ],


    'pipeline' => [
        'enabled' => false,
        'format' => 'both',
        'profile' => 'page',
        'required_fields' => [],
        'dedupe_keys' => ['record_key'],
        'min_quality' => 0,
        'include_failed_pages' => false,
        'include_skipped_pages' => false,
        'field_map' => [],
        'transformations' => [],
    ],

    'url_processor' => [
        'methods' => ['auto', 'curl', 'stream'],
        'max_attempts' => 3,
        'retry_delay_seconds' => 5,
        'backoff_multiplier' => 1.5,
        'max_delay_seconds' => 300,
        'gap_ms' => 0,
        'retry_statuses' => [0, 408, 425, 429, 500, 502, 503, 504],
        'success_status' => '200-399',
        'stop_on_challenge' => true,
        'retry_challenge' => false,
    ],

    'encoding' => [
        'auto_detect' => true,
        'fallback_encoding' => 'UTF-8',
        'output_encoding' => 'UTF-8',
        'fix_mojibake' => true,
        'decode_html_entities' => true,
        'normalize_unicode' => true,
        'remove_control_characters' => true,
        'remove_zero_width_spaces' => true,
        'supported_encodings' => [
            'UTF-8', 'UTF-16', 'UTF-32', 'ISO-8859-1', 'ISO-8859-15',
            'Windows-1252', 'ASCII', 'Shift-JIS', 'EUC-JP', 'GBK', 'GB2312',
            'Big5', 'KOI8-R', 'Macintosh'
        ],
    ],

    'network' => [
        'default' => 'direct',
        'profiles' => [
            'direct' => [
                'type' => 'direct',
                'enabled' => true,
                'max_requests_per_minute' => 60,
                'cooldown_seconds' => 60,
            ],
        ],
    ],

    'browser' => [
        // Browser mode is optional. Normal crawling uses fast PHP HTTP first.
        // Use --browser=auto for fallback or --browser=always for force rendering.
        'default' => 'chrome_headless',
        'fallback_min_text_length' => 300,
        'rendered_html' => false,
        'screenshot' => false,
        'profiles' => [
            'chrome_headless' => [
                'engine' => 'panther',
                'browser' => 'chrome',
                'headless' => true,
                'window_width' => 1366,
                'window_height' => 768,
                'timeout_seconds' => 30,
                'wait_after_load_ms' => 1000,
                'block_assets' => true,
            ],
        ],
    ],

    'storage' => [
        'database' => [
            'dsn' => getenv('MNB_SCRAPER_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=mnb_scraperkit;charset=utf8mb4',
            'username' => getenv('MNB_SCRAPER_DB_USER') ?: 'root',
            'password' => getenv('MNB_SCRAPER_DB_PASS') ?: '',
        ],
        'output_dir' => __DIR__ . '/../storage',
    ],

    'safety' => [
        'block_private_ips' => true,
        'block_localhost' => true,
        'blocked_host_patterns' => [],
        'max_pages_hard_limit' => 10000,
        'allow_vpn_control_only_in_cli' => true,
    ],
];
