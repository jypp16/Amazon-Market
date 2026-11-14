<?php
declare(strict_types=1);

/**
 * Worker CLI para pruebas de concurrencia real del Paso 7 (Sprint 3 - Apertura de caja).
 * Se ejecuta en procesos paralelos con conexiones PDO independientes contra la BD aislada.
 */

$dbName = getenv('DB_NAME') ?: '';
$protected = [
    'amazon_market',
    'amazon_market_restore_verify_20261027_113040',
    'amazon_market_sprint2_test',
    'amazon_market_sprint3_verify_20261112_012312',
    'amazon_market_sprint3_verify_20261112_012430',
];
if ($dbName === '' || in_array($dbName, $protected, true)) {
    fwrite(STDERR, "ERROR: Prohibido ejecutar worker de pruebas sobre base protegida o vacía ({$dbName}).\n");
    exit(2);
}

putenv("DB_NAME={$dbName}");
$_ENV['DB_NAME']    = $dbName;
$_SERVER['DB_NAME'] = $dbName;

require_once dirname(__DIR__, 2) . '/Libraries/Core/Dotenv.php';
\Dotenv::load(dirname(__DIR__, 2) . '/.env');
require_once dirname(__DIR__, 2) . '/Config/Config.php';
require_once dirname(__DIR__, 2) . '/Libraries/Core/Autoload.php';

use Libraries\Core\InventarioException;
use Services\CajaService;

$rawPayload = stream_get_contents(STDIN);
$task = json_decode((string)$rawPayload, true);

if (!is_array($task) || ($task['action'] ?? '') !== 'abrir_caja') {
    echo json_encode(['ok' => false, 'error' => 'Invalid worker task payload']);
    exit(1);
}

try {
    $svc = new CajaService();
    $res = $svc->abrirCaja(
        (array)$task['input'],
        isset($task['header_ref']) ? (string)$task['header_ref'] : null,
        (array)$task['vendedor']
    );
    echo json_encode([
        'ok'        => true,
        'http_code' => $res['http_code'],
        'response'  => $res['response'],
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
} catch (InventarioException $e) {
    echo json_encode([
        'ok'        => false,
        'http_code' => $e->getHttpCode(),
        'response'  => $e->toApiResponse(),
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
} catch (Throwable $e) {
    echo json_encode([
        'ok'      => false,
        'error'   => $e->getMessage(),
        'class'   => get_class($e),
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}
