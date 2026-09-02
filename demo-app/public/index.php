<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use function Tlon\Core\package_value;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/') {
    http_response_code(404);
    echo 'not found';
    exit;
}

echo '<!doctype html><html lang="en"><title>Tlon</title><body><main>'
    . htmlspecialchars(package_value(), ENT_QUOTES)
    . '</main></body></html>';
