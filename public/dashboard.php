<?php

declare(strict_types=1);

use Mnb\ScraperKit\Dashboard\DashboardDataCollector;
use Mnb\ScraperKit\Dashboard\DashboardRenderer;

$root = dirname(__DIR__);
require $root . '/autoload.php';

$token = getenv('MNB_SCRAPERKIT_DASHBOARD_TOKEN') ?: getenv('MNB_SCRAPERKIT_API_TOKEN') ?: null;
$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$queryToken = (string) ($_GET['token'] ?? '');
$bearer = '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $bearer = trim($m[1]);
}
if (is_string($token) && $token !== '' && !hash_equals($token, $bearer !== '' ? $bearer : $queryToken)) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Unauthorized</title><body style="font-family:system-ui;background:#111827;color:#e5e7eb;padding:32px"><h1>Dashboard locked</h1><p>Set a valid Bearer token or add <code>?token=...</code> for local testing.</p></body>';
    return;
}

$collector = new DashboardDataCollector($root);
$data = $collector->collect(
    max(1, (int) ($_GET['recent'] ?? 20)),
    max(60, (int) ($_GET['stale_ttl'] ?? 900))
);

$path = '/' . ltrim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard'), PHP_URL_PATH) ?: '/', '/');
if ($path === '/dashboard.json' || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

header('Content-Type: text/html; charset=utf-8');
echo (new DashboardRenderer())->render($data, [
    'refresh_seconds' => max(0, (int) ($_GET['refresh'] ?? 0)),
]);
