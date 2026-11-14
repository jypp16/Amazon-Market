<?php
declare(strict_types=1);

/**
 * Worker de prueba de concurrencia para apertura simultánea de sesión de caja.
 * Uso exclusivo sobre la base de ensayo del Paso 5 (Sprint 3).
 * Rechaza explícitamente `amazon_market` y las copias reservadas.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ejecución permitida únicamente por CLI.\n");
    exit(2);
}

$dbName = $argv[1] ?? '';
$workerId = (int)($argv[2] ?? 0);
$idTurno = (int)($argv[3] ?? 0);
$startEpochFloat = (float)($argv[4] ?? 0.0);

$forbiddenDbs = [
    '',
    'amazon_market',
    'amazon_market_sprint3_verify_20261112_012430',
    'amazon_market_sprint3_verify_20261112_012312',
    'amazon_market_sprint2_test',
    'amazon_market_restore_verify_20261027_113040',
    'mysql',
    'information_schema',
    'performance_schema',
    'sys',
    'phpmyadmin',
];

if (in_array(strtolower(trim($dbName)), $forbiddenDbs, true) || strpos($dbName, 'amazon_market_sprint3_migration_test_') !== 0) {
    fwrite(STDERR, "PROTECCION_ACTIVA: Base de datos no autorizada para ensayo de concurrencia.\n");
    exit(3);
}

$dsn = sprintf('mysql:host=127.0.0.1;port=3306;dbname=%s;charset=utf8mb4', $dbName);
$pdo = new PDO($dsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($currentDb !== $dbName || $currentDb === 'amazon_market') {
    fwrite(STDERR, "PROTECCION_ACTIVA: Selección de base inválida.\n");
    exit(4);
}

// Esperar hasta la barrera temporal común para disparar ambas transacciones al unísono
while (microtime(true) < $startEpochFloat) {
    usleep(500);
}

$t0 = microtime(true);
try {
    $pdo->beginTransaction();

    // 1. Bloqueo pesimista sobre la fila única de guardia física
    $stmtGuard = $pdo->query('SELECT id_caja_fisica, id_sesion_abierta_actual FROM caja_fisica_guardia WHERE id_caja_fisica = 1 FOR UPDATE');
    $guardRow = $stmtGuard->fetch();

    // Pequeña retención deliberada de 60 ms para garantizar traslape real entre ambos workers
    usleep(60000);

    // 2. Intento de inserción de sesión ABIERTA (protegido además por uq_sesion_caja_unica_abierta)
    $stmtInsert = $pdo->prepare(
        "INSERT INTO sesion_caja (
            id_caja_fisica,
            id_usuario_vendedor,
            id_turno,
            nombre_turno_referencia,
            hora_inicio_referencia,
            hora_fin_referencia,
            fecha_hora_salida_referencia,
            es_suplencia,
            abierta_fuera_horario,
            tiene_cierre_anterior,
            id_sesion_cierre_anterior,
            saldo_inicial,
            diferencia_apertura,
            efectivo_esperado_actual,
            version_caja,
            estado
        ) VALUES (
            1,
            4,
            :id_turno,
            'Turno Concurrencia',
            '08:00:00',
            '16:00:00',
            CONCAT(CURDATE(), ' 16:00:00'),
            0,
            0,
            0,
            NULL,
            100.00,
            NULL,
            100.00,
            1,
            'ABIERTA'
        )"
    );
    $stmtInsert->execute([':id_turno' => $idTurno]);
    $newId = (int)$pdo->lastInsertId();

    $pdo->commit();
    $elapsedMs = round((microtime(true) - $t0) * 1000, 2);

    echo json_encode([
        'worker' => $workerId,
        'status' => 'COMMITTED',
        'id_sesion_caja' => $newId,
        'guard_seen_open_before' => $guardRow['id_sesion_abierta_actual'] ?? null,
        'elapsed_ms' => $elapsedMs,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $elapsedMs = round((microtime(true) - $t0) * 1000, 2);
    $sqlState = ($e instanceof PDOException) ? (string)$e->getCode() : 'ERR';

    echo json_encode([
        'worker' => $workerId,
        'status' => 'REJECTED',
        'sqlstate' => $sqlState,
        'error' => $e->getMessage(),
        'elapsed_ms' => $elapsedMs,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}
