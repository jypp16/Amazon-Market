<?php
declare(strict_types=1);

/**
 * Router aislado para el servidor HTTP de pruebas del Paso 6 (Sprint 3).
 * Verifica obligatoriamente que DB_NAME apunte a la base de pruebas aislada y nunca a amazon_market.
 */

$dbName = getenv('DB_NAME');
$reservedDbs = [
    '',
    'amazon_market',
    'amazon_market_restore_verify_20261027_113040',
    'amazon_market_sprint3_verify_20261112_012312',
    'amazon_market_sprint3_verify_20261112_012430',
];
if (!is_string($dbName) || in_array($dbName, $reservedDbs, true)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => false,
        'code'    => 'TEST_ENV_UNSAFE',
        'message' => 'El servidor de pruebas requiere una base de datos aislada distinta de amazon_market y bases reservadas.',
        'errors'  => new \stdClass(),
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$uriPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rootDir = realpath(dirname(__DIR__, 2));

if (preg_match('~(?:^|/)(?:\.|Config|Libraries|Services|Models|Controllers|Views|tests|database|vendor)(?:/|$)|\.(?:sql|env|log|ini|bak)$~i', $uriPath)
    || str_contains($uriPath, '..') || str_contains($uriPath, '\\')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => false,
        'code'    => 'FORBIDDEN',
        'message' => 'Acceso denegado.',
        'errors'  => new \stdClass(),
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

if (str_starts_with($uriPath, '/Assets/')) {
    $file = realpath($rootDir . $uriPath);
    $assets = realpath($rootDir . '/Assets') . DIRECTORY_SEPARATOR;
    if ($file && str_starts_with($file, $assets) && is_file($file) && !str_ends_with(strtolower($file), '.php')) {
        return false;
    }
    http_response_code(404);
    exit(0);
}

if ($uriPath === '/__test_env_verify') {
    require_once dirname(__DIR__, 2) . '/Libraries/Core/Dotenv.php';
    \Dotenv::load(dirname(__DIR__, 2) . '/.env');
    require_once dirname(__DIR__, 2) . '/Config/Config.php';
    require_once dirname(__DIR__, 2) . '/Libraries/Core/Autoload.php';

    $con = \Libraries\Core\Conexion::getInstance()->conect();
    $row = $con->query("SELECT DATABASE() AS db, @@session.time_zone AS tz, CONNECTION_ID() AS cid")->fetch(\PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'       => true,
        'env_db_name'  => getenv('DB_NAME'),
        'effective_db' => $row['db'] ?? null,
        'db_timezone'  => $row['tz'] ?? null,
        'php_timezone' => date_default_timezone_get(),
        'server_port'  => $_SERVER['SERVER_PORT'] ?? null,
        'conn_id'      => isset($row['cid']) ? (int)$row['cid'] : null,
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

$_GET['url'] = ltrim($uriPath, '/');
require dirname(__DIR__, 2) . '/index.php';
