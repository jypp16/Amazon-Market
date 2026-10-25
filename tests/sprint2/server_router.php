<?php
/** Router exclusivo del servidor local de pruebas. Nunca cambia la conexión habitual. */
if (PHP_SAPI !== 'cli-server' || getenv('DB_NAME') !== 'amazon_market_sprint2_test') {
    http_response_code(503);
    exit('Servidor de pruebas no configurado.');
}
$root = realpath(__DIR__ . '/../..');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)(?:\.|Config|Libraries|Services|Models|Controllers|Views|tests|database|vendor)(?:/|$)|\.(?:sql|env|log|ini|bak)$~i', $path)
    || str_contains($path, '..') || str_contains($path, '\\')) {
    http_response_code(403);
    exit('Acceso denegado.');
}
if (str_starts_with($path, '/Assets/')) {
    $file = realpath($root . $path);
    $assets = realpath($root . '/Assets') . DIRECTORY_SEPARATOR;
    if ($file && str_starts_with($file, $assets) && is_file($file) && !str_ends_with(strtolower($file), '.php')) return false;
    http_response_code(404);
    exit;
}
$_GET['url'] = $path === '/' || $path === '/index.php' ? 'Auth' : ltrim($path, '/');
chdir($root);
require $root . '/index.php';
