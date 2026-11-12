<?php
declare(strict_types=1);

/**
 * Orquestador de prueba de concurrencia real (2 conexiones paralelas) para
 * verificar que solo una sesión de caja puede quedar ABIERTA simultáneamente.
 *
 * Rechaza explícitamente operar sobre `amazon_market` o las copias reservadas.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ejecución permitida únicamente por CLI.\n");
    exit(2);
}

$options = getopt('', ['test-db:']);
$testDb = isset($options['test-db']) ? trim((string)$options['test-db']) : '';

$forbiddenDbs = [
    '',
    'amazon_market',
    'amazon_market_sprint3_verify_20261112_012430',
    'amazon_market_sprint3_verify_20261112_012312',
    'amazon_market_sprint3_migration_test_20261112_140017',
    'amazon_market_sprint3_migration_test_20261112_140630',
    'amazon_market_sprint2_test',
    'amazon_market_restore_verify_20261027_113040',
    'mysql',
    'information_schema',
    'performance_schema',
    'sys',
    'phpmyadmin',
];

if (in_array(strtolower($testDb), $forbiddenDbs, true) || strpos($testDb, 'amazon_market_sprint3_migration_test_') !== 0) {
    fwrite(STDERR, "PROTECCION_ACTIVA: Debe indicar --test-db=amazon_market_sprint3_migration_test_<fecha_hora>.\n");
    exit(3);
}

$dsn = sprintf('mysql:host=127.0.0.1;port=3306;dbname=%s;charset=utf8mb4', $testDb);
$pdo = new PDO($dsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$activeDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($activeDb !== $testDb || $activeDb === 'amazon_market') {
    fwrite(STDERR, "PROTECCION_ACTIVA: Base activa inesperada.\n");
    exit(4);
}

// 1. Crear un turno temporal de prueba en la base de ensayo
$pdo->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado, id_usuario_creacion) VALUES ('Turno Concurrencia', '08:00:00', '16:00:00', 1, 1)");
$idTurno = (int)$pdo->lastInsertId();

$phpBin = PHP_BINARY;
$workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'worker_apertura_concurrente.php';
$barrierTime = sprintf('%.4f', microtime(true) + 0.45);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$cmd1 = sprintf('"%s" "%s" "%s" 1 %d %s', $phpBin, $workerScript, $testDb, $idTurno, $barrierTime);
$cmd2 = sprintf('"%s" "%s" "%s" 2 %d %s', $phpBin, $workerScript, $testDb, $idTurno, $barrierTime);

$proc1 = proc_open($cmd1, $descriptors, $pipes1);
$proc2 = proc_open($cmd2, $descriptors, $pipes2);

fclose($pipes1[0]);
fclose($pipes2[0]);

$out1 = stream_get_contents($pipes1[1]);
$err1 = stream_get_contents($pipes1[2]);
fclose($pipes1[1]);
fclose($pipes1[2]);
$code1 = proc_close($proc1);

$out2 = stream_get_contents($pipes2[1]);
$err2 = stream_get_contents($pipes2[2]);
fclose($pipes2[1]);
fclose($pipes2[2]);
$code2 = proc_close($proc2);

$res1 = json_decode(trim((string)$out1), true) ?: ['raw_out' => $out1, 'raw_err' => $err1, 'exit_code' => $code1];
$res2 = json_decode(trim((string)$out2), true) ?: ['raw_out' => $out2, 'raw_err' => $err2, 'exit_code' => $code2];

$openCount = (int)$pdo->query("SELECT COUNT(*) FROM sesion_caja WHERE estado = 'ABIERTA'")->fetchColumn();
$guardRow = $pdo->query("SELECT id_caja_fisica, id_sesion_abierta_actual, id_ultima_sesion_cerrada FROM caja_fisica_guardia WHERE id_caja_fisica = 1")->fetch();

// Limpiar datos temporales de la prueba para dejar la base de ensayo limpia preservando exactamente la definición original de los triggers
$pdo->exec("UPDATE caja_fisica_guardia SET id_sesion_abierta_actual = NULL, id_ultima_sesion_cerrada = NULL WHERE id_caja_fisica = 1");
$trgSesionCreate = $pdo->query("SHOW CREATE TRIGGER `trg_sesion_caja_before_delete_prohibido`")->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
$trgTurnoCreate = $pdo->query("SHOW CREATE TRIGGER `trg_turno_before_delete_prohibido`")->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
$pdo->exec("DROP TRIGGER IF EXISTS `trg_sesion_caja_before_delete_prohibido`");
$pdo->exec("DROP TRIGGER IF EXISTS `trg_turno_before_delete_prohibido`");
$pdo->exec("DELETE FROM sesion_caja");
$pdo->exec("DELETE FROM turno");
$pdo->exec("ALTER TABLE sesion_caja AUTO_INCREMENT = 1");
$pdo->exec("ALTER TABLE turno AUTO_INCREMENT = 1");
$pdo->exec($trgSesionCreate);
$pdo->exec($trgTurnoCreate);

$statuses = [$res1['status'] ?? 'ERR', $res2['status'] ?? 'ERR'];
sort($statuses);
$passed = ($statuses === ['COMMITTED', 'REJECTED'] && $openCount === 1);

$summary = [
    'test_database' => $testDb,
    'passed' => $passed,
    'open_sessions_after_race' => $openCount,
    'guard_row_after_race' => $guardRow,
    'worker_1' => $res1,
    'worker_2' => $res2,
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($passed ? 0 : 1);
