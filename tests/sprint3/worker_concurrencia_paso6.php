<?php
declare(strict_types=1);

/**
 * Worker CLI para pruebas de concurrencia real del Paso 6 (Sprint 3).
 * Se ejecuta en procesos paralelos contra la BD aislada de pruebas.
 */

$dbName = getenv('DB_NAME') ?: 'amazon_market_sprint3_paso6_http_20261112_1900';
if ($dbName === 'amazon_market') {
    fwrite(STDERR, "ERROR: Prohibido ejecutar worker de pruebas sobre amazon_market.\n");
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
use Services\ProgramacionService;
use Services\TurnoService;

$rawPayload = stream_get_contents(STDIN);
$task = json_decode((string)$rawPayload, true);

if (!is_array($task) || empty($task['action'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid worker task payload']);
    exit(1);
}

$action = (string)$task['action'];
$adminId = (int)($task['admin_id'] ?? 1);

try {
    if ($action === 'crear_turno') {
        $svc = new TurnoService();
        $res = $svc->crearTurno(
            (array)$task['input'],
            isset($task['header_ref']) ? (string)$task['header_ref'] : null,
            $adminId
        );
        echo json_encode([
            'ok'        => true,
            'http_code' => $res['http_code'],
            'response'  => $res['response'],
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    if ($action === 'eliminar_turno') {
        $svc = new TurnoService();
        $res = $svc->eliminarTurno(
            (int)$task['id_turno'],
            [],
            (string)$task['header_ref'],
            $adminId
        );
        echo json_encode([
            'ok'        => true,
            'http_code' => $res['http_code'],
            'response'  => $res['response'],
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    if ($action === 'guardar_programacion') {
        $svc = new ProgramacionService();
        $res = $svc->guardarProgramacionSemanal(
            (array)$task['input'],
            isset($task['header_ref']) ? (string)$task['header_ref'] : null,
            $adminId
        );
        echo json_encode([
            'ok'        => true,
            'http_code' => $res['http_code'],
            'response'  => $res['response'],
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    exit(1);
} catch (InventarioException $e) {
    echo json_encode([
        'ok'        => false,
        'http_code' => $e->getHttpCode(),
        'response'  => $e->toApiResponse(),
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
} catch (Throwable $e) {
    echo json_encode([
        'ok'        => false,
        'http_code' => 500,
        'error'     => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}
