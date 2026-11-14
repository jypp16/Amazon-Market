<?php
declare(strict_types=1);

/**
 * Ejecutor de la migración incremental 07 (ajuste CHECK corrección a cero para Paso 8).
 * Requiere que la base destino tenga aplicados los scripts 01..06 (estado COMPLETADA).
 *
 * Uso:
 *   php database/tercerSprint/tools/ejecutar_ajuste_paso8_sprint3.php <base_destino>
 */

$targetDb = $argv[1] ?? '';

$basesProhibidas = [
    '',
    'amazon_market',
    'amazon_market_sprint3_verify_20261112_012430',
    'mysql',
    'information_schema',
    'performance_schema',
    'phpmyadmin',
];

if (in_array(strtolower($targetDb), array_map('strtolower', $basesProhibidas), true)) {
    fwrite(STDERR, "[ABORTADO] PROTECCION_ACTIVA: La base destino '{$targetDb}' está reservada o no es válida.\n");
    exit(2);
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $targetDb)) {
    fwrite(STDERR, "[ABORTADO] Nombre de base de datos inválido: '{$targetDb}'.\n");
    exit(2);
}

$mysqlExe = 'C:/xampp/mysql/bin/mysql.exe';
if (!is_file($mysqlExe)) {
    fwrite(STDERR, "[ABORTADO] No se encontró el cliente mysql.exe en {$mysqlExe}.\n");
    exit(2);
}

$sprintDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$script07 = $sprintDir . '/07_ajuste_check_correccion_cero_sprint3.sql';
if (!is_file($script07)) {
    fwrite(STDERR, "[ABORTADO] No se encontró el script obligatorio: {$script07}.\n");
    exit(2);
}

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$stmtExists = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$stmtExists->execute([$targetDb]);
if (!$stmtExists->fetchColumn()) {
    fwrite(STDERR, "[ABORTADO] La base de datos destino '{$targetDb}' no existe.\n");
    exit(2);
}

$driverLines = [
    "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;",
    "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';",
    "SET @SPRINT3_BASE_CONFIRMADA = '{$targetDb}';",
    "SOURCE {$script07};",
    "SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'correccion_movimiento_caja' AND CONSTRAINT_NAME = 'chk_correccion_importes_y_motivo';",
];

$driverSqlPath = str_replace('\\', '/', sys_get_temp_dir()) . '/sprint3_paso8_runner_' . getmypid() . '_' . uniqid('', true) . '.sql';
file_put_contents($driverSqlPath, implode("\n", $driverLines) . "\n");

$cmd = sprintf(
    '"%s" --host=127.0.0.1 --port=3306 --user=root --default-character-set=utf8mb4 --abort-source-on-error --batch --raw "%s" -e "SOURCE %s"',
    $mysqlExe,
    $targetDb,
    $driverSqlPath
);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($process)) {
    @unlink($driverSqlPath);
    fwrite(STDERR, "[ABORTADO] No se pudo iniciar mysql.exe.\n");
    exit(2);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
@unlink($driverSqlPath);

if ($stdout !== '') {
    echo $stdout;
}
if ($stderr !== '') {
    fwrite(STDERR, $stderr);
}

exit($exitCode);
