<?php

declare(strict_types=1);

use Mnb\ScraperKit\Api\ApiRouter;

$root = dirname(__DIR__);
require $root . '/autoload.php';

$token = getenv('MNB_SCRAPERKIT_API_TOKEN') ?: null;
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
        $headers[$name] = (string) $value;
        $headers[strtolower($name)] = (string) $value;
    }
}

$body = null;
$raw = file_get_contents('php://input');
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : null;
}

$response = (new ApiRouter($root, $token))->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['REQUEST_URI'] ?? '/api/v1/health'),
    $headers,
    $body
);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo json_encode($response->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
