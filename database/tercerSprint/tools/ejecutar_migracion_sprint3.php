<?php
declare(strict_types=1);

/**
 * Ejecutor atómico de sesión única para las migraciones incrementales del Sprint 3.
 *
 * Propósito (Corrección 4 y Corrección 5):
 *   - Ejecutar los scripts 01..06 dentro de UNA SOLA conexión de cliente (`mysql.exe`),
 *     preservando el mismo `CONNECTION_ID()` y el candado `GET_LOCK` desde el paso 01
 *     hasta su liberación en el paso 06.
 *   - Detener inmediatamente la ejecución ante el primer error (`--abort-source-on-error`),
 *     impidiendo que se ejecuten los scripts posteriores.
 *   - Rechazar explícitamente `amazon_market` y bases reservadas de verificación.
 *
 * Uso normal:
 *   php database/tercerSprint/tools/ejecutar_migracion_sprint3.php <base_destino>
 *
 * Modo de prueba de fallo controlado (exclusivo para bases `amazon_market_sprint3_fail_sim_*`):
 *   php database/tercerSprint/tools/ejecutar_migracion_sprint3.php <base_fail_sim> --simulate-fail-step2
 */

$targetDb = $argv[1] ?? '';
$mode = $argv[2] ?? '';

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
    fwrite(STDERR, "[ABORTADO] PROTECCION_ACTIVA: La base destino '{$targetDb}' está reservada o no es válida para ejecutar migraciones.\n");
    exit(2);
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $targetDb)) {
    fwrite(STDERR, "[ABORTADO] Nombre de base de datos inválido: '{$targetDb}'.\n");
    exit(2);
}

$simulateFailStep2 = ($mode === '--simulate-fail-step2');
if ($simulateFailStep2 && strpos($targetDb, 'amazon_market_sprint3_fail_sim_') !== 0) {
    fwrite(STDERR, "[ABORTADO] El modo --simulate-fail-step2 solo se permite sobre bases prefijadas con 'amazon_market_sprint3_fail_sim_'.\n");
    exit(2);
}

$mysqlExe = 'C:/xampp/mysql/bin/mysql.exe';
if (!is_file($mysqlExe)) {
    fwrite(STDERR, "[ABORTADO] No se encontró el cliente mysql.exe en {$mysqlExe}.\n");
    exit(2);
}

$sprintDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$scripts = [
    '01_preflight_y_control_sprint3.sql',
    '02_estructura_turnos_y_programacion_sprint3.sql',
    '03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql',
    '04_estructura_movimientos_correcciones_y_ventas_sprint3.sql',
    '05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql',
    '06_configuracion_permisos_y_cierre_migracion_sprint3.sql',
];

foreach ($scripts as $script) {
    if (!is_file($sprintDir . '/' . $script)) {
        fwrite(STDERR, "[ABORTADO] No se encontró el script obligatorio: {$script}\n");
        exit(2);
    }
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

$tempFiles = [];
$backupPath = 'C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql';
if (!is_file($backupPath)) {
    fwrite(STDERR, "[ABORTADO] No se encontró el respaldo verificado en {$backupPath}.\n");
    exit(2);
}
$backupSha256 = strtoupper((string)hash_file('sha256', $backupPath));

$driverLines = [
    "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;",
    "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';",
    "SET @SPRINT3_BASE_CONFIRMADA = '{$targetDb}';",
    "SET @SPRINT3_RESPALDO_ARCHIVO = '{$backupPath}';",
    "SET @SPRINT3_RESPALDO_SHA256 = '{$backupSha256}';",
    "SELECT DATABASE() AS base_activa, CONNECTION_ID() AS id_conexion_unica, @@SESSION.sql_mode AS sql_mode_activo;",
];

foreach ($scripts as $script) {
    $fullPath = $sprintDir . '/' . $script;
    if ($simulateFailStep2 && $script === '02_estructura_turnos_y_programacion_sprint3.sql') {
        $content = (string)file_get_contents($fullPath);
        $needle = "CREATE TRIGGER `trg_prog_detalle_before_delete_prohibido`";
        $injected = "BEGIN NOT ATOMIC\n"
            . "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FALLO_SIMULADO_ANTES_DE_FINALIZAR_TRIGGERS_PASO02';\n"
            . "END$$\n\n"
            . $needle;
        $modified = str_replace($needle, $injected, $content, $count);
        if ($count !== 1) {
            fwrite(STDERR, "[ABORTADO] No fue posible preparar el script de simulación de fallo del paso 02.\n");
            exit(2);
        }
        $tmpStep2 = str_replace('\\', '/', sys_get_temp_dir()) . '/sprint3_sim_fail_step2_' . getmypid() . '.sql';
        file_put_contents($tmpStep2, $modified);
        $tempFiles[] = $tmpStep2;
        $fullPath = $tmpStep2;
    }

    $driverLines[] = "SELECT 'INICIO_{$script}' AS hito_migracion, CONNECTION_ID() AS conn_id, COALESCE(IS_USED_LOCK(CONCAT('am_sprint3_mig_', MD5(DATABASE()))), -1) AS lock_owner;";
    $driverLines[] = "SOURCE {$fullPath};";
    $driverLines[] = "SELECT 'FIN_{$script}' AS hito_migracion, CONNECTION_ID() AS conn_id, estado, paso_actual FROM sprint3_migracion_control WHERE id_migracion = 3;";
}

$driverSqlPath = str_replace('\\', '/', sys_get_temp_dir()) . '/sprint3_runner_' . getmypid() . '_' . uniqid('', true) . '.sql';
file_put_contents($driverSqlPath, implode("\n", $driverLines) . "\n");
$tempFiles[] = $driverSqlPath;

$cmd = sprintf(
    '"%s" -- host=127.0.0.1 --port=3306 --user=root --default-character-set=utf8mb4 --abort-source-on-error --batch --raw "%s" -e "SOURCE %s"',
    $mysqlExe,
    $targetDb,
    $driverSqlPath
);
// Nota: En mysql.exe para Windows, usamos opciones sin espacio tras --
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
    foreach ($tempFiles as $f) {
        @unlink($f);
    }
    fwrite(STDERR, "[ABORTADO] No se pudo iniciar el proceso mysql.exe.\n");
    exit(2);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

foreach ($tempFiles as $f) {
    @unlink($f);
}

if ($stdout !== '') {
    fwrite(STDOUT, $stdout);
}
if ($stderr !== '') {
    fwrite(STDERR, $stderr);
}

if ($exitCode !== 0) {
    fwrite(STDERR, "[ERROR_MIGRACION] Ejecución detenida con código de salida {$exitCode} en base '{$targetDb}'.\n");
    exit($exitCode);
}

fwrite(STDOUT, "[OK] Migración Sprint 3 completada exitosamente en una única sesión sobre '{$targetDb}'.\n");
exit(0);
