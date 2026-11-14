<?php
declare(strict_types=1);

/**
 * Verificador integral de conservación de datos (32 tablas base), RBAC,
 * idempotencia, snapshots y pruebas positivas/negativas de restricciones
 * sobre la base de ensayo del Sprint 3 (Paso 5 — incluye Correcciones 1 a 5).
 *
 * Rechaza explícitamente modificar `amazon_market` o las copias reservadas.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ejecución permitida únicamente por CLI.\n");
    exit(2);
}

$options = getopt('', ['test-db:', 'fail-sim-db:']);
$testDb = isset($options['test-db']) ? trim((string)$options['test-db']) : '';
$failSimDb = isset($options['fail-sim-db']) ? trim((string)$options['fail-sim-db']) : '';

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

if (in_array(strtolower($testDb), array_map('strtolower', $forbiddenDbs), true) || strpos($testDb, 'amazon_market_sprint3_migration_test_') !== 0) {
    fwrite(STDERR, "PROTECCION_ACTIVA: Debe indicar una base de ensayo nueva --test-db=amazon_market_sprint3_migration_test_<fecha_hora>.\n");
    exit(3);
}

$pdoTest = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$testDb};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdoTest->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

// Conexiones de solo lectura para comparación contra la base original y la copia de verificación del Paso 3
$pdoOrig = new PDO("mysql:host=127.0.0.1;port=3306;dbname=amazon_market;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdoOrig->exec("SET SESSION TRANSACTION READ ONLY");

$verifyDb = 'amazon_market_sprint3_verify_20261112_012430';
$pdoVerify = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$verifyDb};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdoVerify->exec("SET SESSION TRANSACTION READ ONLY");

$baseTables = [
    'categoria', 'cliente', 'compra', 'compra_historial',
    'control_solicitud_idempotencia', 'detalle_compra', 'detalle_venta',
    'kardex', 'metodo_pago', 'operacion_inventario', 'permiso',
    'presentacion', 'producto', 'proveedor', 'reporte_consulta_snapshot',
    'reporte_consulta_snapshot_item', 'rol', 'rol_permiso',
    'serie_comprobante', 'sprint2_archivo_compra',
    'sprint2_archivo_compra_historial', 'sprint2_archivo_detalle_compra',
    'sprint2_archivo_detalle_venta', 'sprint2_archivo_kardex',
    'sprint2_archivo_venta', 'sprint2_fase10_control', 'subcategoria',
    'tipo_comprobante', 'tipo_documento', 'unidad_medida', 'usuario', 'venta'
];

function canonicalTableSha256(PDO $pdo, string $table, array $columns, string $where = '', string $orderBy = ''): string
{
    $colList = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $columns));
    $sql = "SELECT {$colList} FROM `{$table}`";
    if ($where !== '') {
        $sql .= " WHERE {$where}";
    }
    if ($orderBy !== '') {
        $sql .= " ORDER BY {$orderBy}";
    }
    $stmt = $pdo->query($sql);
    $ctx = hash_init('sha256');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        hash_update($ctx, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    return strtoupper(hash_final($ctx));
}

function getTableColumns(PDO $pdo, string $db, string $table): array
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
          ORDER BY ORDINAL_POSITION"
    );
    $stmt->execute([':db' => $db, ':tbl' => $table]);
    return array_column($stmt->fetchAll(), 'COLUMN_NAME');
}

function getTablePrimaryKeyOrder(PDO $pdo, string $db, string $table): string
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND CONSTRAINT_NAME = 'PRIMARY'
          ORDER BY ORDINAL_POSITION"
    );
    $stmt->execute([':db' => $db, ':tbl' => $table]);
    $cols = array_column($stmt->fetchAll(), 'COLUMN_NAME');
    return implode(', ', array_map(static fn(string $c): string => "`{$c}` ASC", $cols));
}

function getTriggersSnapshot(PDO $pdo, string $dbName): array
{
    $stmt = $pdo->prepare(
        "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = :db
          ORDER BY TRIGGER_NAME ASC"
    );
    $stmt->execute([':db' => $dbName]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ctx = hash_init('sha256');
    foreach ($rows as $r) {
        hash_update($ctx, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    return [
        'count' => count($rows),
        'sha256' => strtoupper(hash_final($ctx)),
        'names' => array_column($rows, 'TRIGGER_NAME'),
    ];
}

function cleanSyntheticSprint3Rows(PDO $pdo, string $dbName): void
{
    $stmt = $pdo->prepare(
        "SELECT TRIGGER_NAME
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = :db
            AND EVENT_OBJECT_TABLE IN (
                'turno', 'programacion_semanal', 'programacion_semanal_detalle',
                'sesion_caja', 'arqueo_caja', 'autorizacion_caja',
                'movimiento_caja', 'correccion_movimiento_caja',
                'reporte_caja_snapshot_item'
            )
          ORDER BY TRIGGER_NAME"
    );
    $stmt->execute([':db' => $dbName]);
    $triggerNames = array_column($stmt->fetchAll(), 'TRIGGER_NAME');

    $savedCreateSql = [];
    foreach ($triggerNames as $tName) {
        $row = $pdo->query("SHOW CREATE TRIGGER `{$tName}`")->fetch(PDO::FETCH_ASSOC);
        $savedCreateSql[$tName] = $row['SQL Original Statement'];
        $pdo->exec("DROP TRIGGER IF EXISTS `{$tName}`");
    }

    $pdo->exec("UPDATE caja_fisica_guardia SET id_sesion_abierta_actual = NULL, id_ultima_sesion_cerrada = NULL WHERE id_caja_fisica = 1");
    $pdo->exec("UPDATE sesion_caja SET id_ultimo_arqueo = NULL, id_arqueo_cierre = NULL, id_sesion_cierre_anterior = NULL");
    $pdo->exec("UPDATE correccion_movimiento_caja SET id_correccion_previa = NULL");
    $pdo->exec("UPDATE movimiento_caja SET id_movimiento_original = NULL");
    $pdo->exec("UPDATE programacion_semanal SET id_programacion_previa = NULL, id_programacion_origen_duplicacion = NULL");

    $pdo->exec("DELETE FROM reporte_caja_snapshot_item");
    $pdo->exec("DELETE FROM reporte_consulta_snapshot WHERE tipo_reporte = 'cierres_caja'");
    $pdo->exec("ALTER TABLE reporte_consulta_snapshot AUTO_INCREMENT = 30");
    $pdo->exec("DELETE FROM correccion_movimiento_caja");
    $pdo->exec("ALTER TABLE correccion_movimiento_caja AUTO_INCREMENT = 1");
    $pdo->exec("DELETE FROM movimiento_caja");
    $pdo->exec("ALTER TABLE movimiento_caja AUTO_INCREMENT = 1");
    $pdo->exec("DELETE FROM autorizacion_caja");
    $pdo->exec("ALTER TABLE autorizacion_caja AUTO_INCREMENT = 1");
    $pdo->exec("DELETE FROM arqueo_caja");
    $pdo->exec("ALTER TABLE arqueo_caja AUTO_INCREMENT = 1");
    $pdo->exec("DELETE FROM sesion_caja");
    $pdo->exec("ALTER TABLE sesion_caja AUTO_INCREMENT = 1");
    $pdo->exec("DELETE FROM programacion_semanal_detalle");
    $pdo->exec("ALTER TABLE programacion_semanal_detalle AUTO_INCREMENT = 1");
    $pdo->exec("DELETE FROM programacion_semanal");
    $pdo->exec("ALTER TABLE programacion_semanal AUTO_INCREMENT = 1");
    $pdo->exec("DELETE FROM turno");
    $pdo->exec("ALTER TABLE turno AUTO_INCREMENT = 1");

    foreach ($savedCreateSql as $createSql) {
        $pdo->exec($createSql);
    }
}

// Capturar huella de triggers inmediatamente después de la migración antes de limpiar o ejecutar fixtures
$triggersInitialSnapshot = getTriggersSnapshot($pdoTest, $testDb);

cleanSyntheticSprint3Rows($pdoTest, $testDb);

$tableComparisons = [];
$allConservationPassed = true;

foreach ($baseTables as $tbl) {
    $cntOrig = (int)$pdoOrig->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    $cntVer = (int)$pdoVerify->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    $cntTest = (int)$pdoTest->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();

    $chkOrig = (string)($pdoOrig->query("CHECKSUM TABLE `{$tbl}` EXTENDED")->fetch()['Checksum'] ?? 'NULL');
    $chkVer = (string)($pdoVerify->query("CHECKSUM TABLE `{$tbl}` EXTENDED")->fetch()['Checksum'] ?? 'NULL');
    $chkTest = (string)($pdoTest->query("CHECKSUM TABLE `{$tbl}` EXTENDED")->fetch()['Checksum'] ?? 'NULL');

    $aiStmt = $pdoOrig->prepare("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl");
    $aiStmt->execute([':db' => 'amazon_market', ':tbl' => $tbl]);
    $aiOrig = $aiStmt->fetchColumn();

    $aiStmtTest = $pdoTest->prepare("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl");
    $aiStmtTest->execute([':db' => $testDb, ':tbl' => $tbl]);
    $aiTest = $aiStmtTest->fetchColumn();

    $colsOrig = getTableColumns($pdoOrig, 'amazon_market', $tbl);
    $pkOrder = getTablePrimaryKeyOrder($pdoOrig, 'amazon_market', $tbl);

    $whereSub = '';
    if ($tbl === 'permiso') {
        $whereSub = '`id_permiso` <= 31';
    } elseif ($tbl === 'rol_permiso') {
        $whereSub = '`id_permiso` <= 31';
    }

    $shaOrig = canonicalTableSha256($pdoOrig, $tbl, $colsOrig, $whereSub, $pkOrder);
    $shaTest = canonicalTableSha256($pdoTest, $tbl, $colsOrig, $whereSub, $pkOrder);

    $expectedCountTest = $cntOrig;
    $expectedAiTest = $aiOrig;
    if ($tbl === 'permiso') {
        $expectedCountTest = $cntOrig + 5;
        $expectedAiTest = ((int)$aiOrig) + 5;
    } elseif ($tbl === 'rol_permiso') {
        $expectedCountTest = $cntOrig + 5;
    }

    $rowOk = (
        $cntOrig === $cntVer
        && $chkOrig === $chkVer
        && $cntTest === $expectedCountTest
        && (string)$aiTest === (string)$expectedAiTest
        && $shaOrig === $shaTest
    );

    if (!in_array($tbl, ['permiso', 'rol_permiso', 'venta'], true)) {
        $rowOk = $rowOk && ($chkOrig === $chkTest);
    }

    if (!$rowOk) {
        $allConservationPassed = false;
    }

    $tableComparisons[$tbl] = [
        'count_orig' => $cntOrig,
        'count_verify' => $cntVer,
        'count_test' => $cntTest,
        'ai_orig' => $aiOrig,
        'ai_test' => $aiTest,
        'checksum_orig' => $chkOrig,
        'checksum_test' => $chkTest,
        'prior_rows_sha256_match' => ($shaOrig === $shaTest),
        'status' => $rowOk ? 'OK' : 'MISMATCH',
    ];
}

// Verificaciones específicas de conservación
$ventasNullSesion = (int)$pdoTest->query("SELECT COUNT(*) FROM venta WHERE id_sesion_caja IS NULL")->fetchColumn();
$stockSumOrig = (int)$pdoOrig->query("SELECT COALESCE(SUM(stock_actual), 0) FROM producto")->fetchColumn();
$stockSumTest = (int)$pdoTest->query("SELECT COALESCE(SUM(stock_actual), 0) FROM producto")->fetchColumn();
$seriesOrig = $pdoOrig->query("SELECT id_serie, serie, correlativo_actual, estado FROM serie_comprobante ORDER BY id_serie")->fetchAll();
$seriesTest = $pdoTest->query("SELECT id_serie, serie, correlativo_actual, estado FROM serie_comprobante ORDER BY id_serie")->fetchAll();

// Ejecutar batería de pruebas positivas y negativas de estructura, CHECKs y triggers
$caseResults = [];
$recordCase = static function (string $code, string $type, string $description, bool $passed, string $detail) use (&$caseResults): void {
    $caseResults[] = [
        'code' => $code,
        'type' => $type,
        'description' => $description,
        'passed' => $passed,
        'detail' => $detail,
    ];
};

$expectFailure = static function (string $code, string $description, callable $fn, string $expectedSubstring = '') use ($recordCase): void {
    try {
        $fn();
        $recordCase($code, 'NEGATIVA', $description, false, 'ERROR: La operación debía fallar pero fue aceptada.');
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $matched = ($expectedSubstring === '' || stripos($msg, $expectedSubstring) !== false);
        $recordCase($code, 'NEGATIVA', $description, $matched, $msg);
    }
};

$expectSuccess = static function (string $code, string $description, callable $fn) use ($recordCase): void {
    try {
        $detail = (string)$fn();
        $recordCase($code, 'POSITIVA', $description, true, $detail);
    } catch (Throwable $e) {
        $recordCase($code, 'POSITIVA', $description, false, 'EXCEPCION_INESPERADA: ' . $e->getMessage());
    }
};

// --- A. TURNOS ---
$idTurnoManana = 0;
$idTurnoTarde = 0;
$idTurnoAux = 0;

$expectSuccess('A1_TURNO_CREAR_ACTIVOS', 'Crear turnos activos Mañana (08:00-14:00) y Tarde (14:00-20:00)', function () use ($pdoTest, &$idTurnoManana, &$idTurnoTarde): string {
    $pdoTest->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado, id_usuario_creacion) VALUES ('Mañana', '08:00:00', '14:00:00', 1, 1)");
    $idTurnoManana = (int)$pdoTest->lastInsertId();
    $pdoTest->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado, id_usuario_creacion) VALUES ('Tarde', '14:00:00', '20:00:00', 1, 1)");
    $idTurnoTarde = (int)$pdoTest->lastInsertId();
    return "idTurnoManana={$idTurnoManana}, idTurnoTarde={$idTurnoTarde}";
});

$expectFailure('A2_TURNO_HORARIO_INVALIDO', 'Rechazar turno con hora_fin <= hora_inicio (14:00 a 08:00)', function () use ($pdoTest): void {
    $pdoTest->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado, id_usuario_creacion) VALUES ('Invalido', '14:00:00', '08:00:00', 1, 1)");
}, 'chk_turno_horario');

$expectFailure('A3_TURNO_NOMBRE_ACTIVO_DUPLICADO', 'Rechazar nombre activo duplicado normalizado ("  mañana ")', function () use ($pdoTest): void {
    $pdoTest->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado, id_usuario_creacion) VALUES ('  mañana ', '09:00:00', '13:00:00', 1, 1)");
}, 'uq_turno_nombre_activo');

$expectFailure('A4_TURNO_DELETE_FISICO_PROHIBIDO', 'Rechazar DELETE físico sobre catálogo de turnos', function () use ($pdoTest, &$idTurnoTarde): void {
    $pdoTest->exec("DELETE FROM turno WHERE id_turno = {$idTurnoTarde}");
}, 'BAJA_LOGICA_REQUERIDA');

$expectSuccess('A5_TURNO_BAJA_LOGICA_REUTILIZA_NOMBRE', 'Permitir baja lógica (estado = 0) y reutilizar el mismo nombre en un nuevo turno activo', function () use ($pdoTest, &$idTurnoAux): string {
    $pdoTest->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado, id_usuario_creacion) VALUES ('Intermedio', '10:00:00', '15:00:00', 1, 1)");
    $idFirst = (int)$pdoTest->lastInsertId();
    $pdoTest->exec("UPDATE turno SET estado = 0 WHERE id_turno = {$idFirst}");
    $pdoTest->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado, id_usuario_creacion) VALUES ('Intermedio', '11:00:00', '16:00:00', 1, 1)");
    $idTurnoAux = (int)$pdoTest->lastInsertId();
    $pdoTest->exec("UPDATE turno SET estado = 0 WHERE id_turno = {$idTurnoAux}");
    return "Turno inactivo id={$idFirst} conservado; nuevo turno 'Intermedio' creado con id={$idTurnoAux}";
});

// --- B. PROGRAMACIÓN SEMANAL VERSIONADA ---
$idProgBase1 = 0;
$idProgExcepcion = 0;
$idProgBase2 = 0;

$expectFailure('B1_PROG_FECHA_NO_LUNES', 'Rechazar semana_inicio que no sea lunes (2026-11-11 miércoles)', function () use ($pdoTest): void {
    $pdoTest->exec("INSERT INTO programacion_semanal (id_usuario_vendedor, semana_inicio, alcance, version_programacion, es_vigente, id_usuario_autor) VALUES (4, '2026-11-11', 'desde', 1, 1, 1)");
}, 'INVALID_WEEK_START_DATE');

$expectFailure('B2_PROG_PRIMERA_SIN_BASE', 'Rechazar primera programación si es excepción semanal (alcance = semana) sin base previa', function () use ($pdoTest): void {
    $pdoTest->exec("INSERT INTO programacion_semanal (id_usuario_vendedor, semana_inicio, alcance, version_programacion, es_vigente, id_usuario_autor) VALUES (4, '2026-11-09', 'semana', 1, 1, 1)");
}, 'PRIMERA_PROGRAMACION_REQUIERE_BASE');

$expectSuccess('B3_PROG_BASE_MULTIPLES_TURNOS_MISMO_DIA', 'Guardar primera base permanente y asignar dos turnos (Mañana y Tarde) el mismo día lunes (dia_semana = 0)', function () use ($pdoTest, &$idProgBase1, &$idTurnoManana, &$idTurnoTarde): string {
    $pdoTest->exec("INSERT INTO programacion_semanal (id_usuario_vendedor, semana_inicio, alcance, version_programacion, es_vigente, id_usuario_autor) VALUES (4, '2026-11-09', 'desde', 1, 1, 1)");
    $idProgBase1 = (int)$pdoTest->lastInsertId();
    $pdoTest->exec("INSERT INTO programacion_semanal_detalle (id_programacion, id_turno, dia_semana) VALUES ({$idProgBase1}, {$idTurnoManana}, 0), ({$idProgBase1}, {$idTurnoTarde}, 0)");
    $cnt = (int)$pdoTest->query("SELECT COUNT(*) FROM programacion_semanal_detalle WHERE id_programacion = {$idProgBase1} AND dia_semana = 0")->fetchColumn();
    return "idProgBase1={$idProgBase1}, turnos_lunes={$cnt}";
});

$expectSuccess('B4_PROG_EXCEPCION_PRESERVADA_ANTE_NUEVA_BASE', 'Crear excepción semanal (2026-11-23) y luego nueva base (desde 2026-11-16); comprobar que la excepción sigue vigente en su semana y luego rige la nueva base (Sección 13.1)', function () use ($pdoTest, &$idProgExcepcion, &$idProgBase2, &$idTurnoManana, &$idTurnoTarde): string {
    $pdoTest->exec("INSERT INTO programacion_semanal (id_usuario_vendedor, semana_inicio, alcance, version_programacion, es_vigente, id_usuario_autor) VALUES (4, '2026-11-23', 'semana', 1, 1, 1)");
    $idProgExcepcion = (int)$pdoTest->lastInsertId();
    $pdoTest->exec("INSERT INTO programacion_semanal_detalle (id_programacion, id_turno, dia_semana) VALUES ({$idProgExcepcion}, {$idTurnoTarde}, 0)");

    $pdoTest->exec("INSERT INTO programacion_semanal (id_usuario_vendedor, semana_inicio, alcance, version_programacion, es_vigente, id_usuario_autor) VALUES (4, '2026-11-16', 'desde', 1, 1, 1)");
    $idProgBase2 = (int)$pdoTest->lastInsertId();
    $pdoTest->exec("INSERT INTO programacion_semanal_detalle (id_programacion, id_turno, dia_semana) VALUES ({$idProgBase2}, {$idTurnoManana}, 1)");

    $sqlResolve = "SELECT id_programacion, alcance, semana_inicio
                     FROM programacion_semanal
                    WHERE id_usuario_vendedor = 4
                      AND es_vigente = 1
                      AND (
                          (alcance = 'semana' AND semana_inicio = :target_week)
                          OR (alcance = 'desde' AND semana_inicio <= :target_week2)
                      )
                    ORDER BY (alcance = 'semana') DESC, semana_inicio DESC, version_programacion DESC
                    LIMIT 1";
    $stmt = $pdoTest->prepare($sqlResolve);
    $stmt->execute([':target_week' => '2026-11-23', ':target_week2' => '2026-11-23']);
    $resWeek23 = $stmt->fetch();

    $stmt->execute([':target_week' => '2026-11-30', ':target_week2' => '2026-11-30']);
    $resWeek30 = $stmt->fetch();

    if ((int)$resWeek23['id_programacion'] !== $idProgExcepcion || (int)$resWeek30['id_programacion'] !== $idProgBase2) {
        throw new RuntimeException('Resolución de precedencia incorrecta.');
    }
    return "Semana 2026-11-23 -> Excepción id={$resWeek23['id_programacion']}; Semana 2026-11-30 -> Nueva base id={$resWeek30['id_programacion']}";
});

$expectFailure('B5_TURNO_BAJA_CON_VENDEDOR_ASIGNADO', 'Rechazar baja de turno asignado a vendedor en programación vigente (SHIFT_HAS_ASSIGNED_SELLERS)', function () use ($pdoTest, &$idTurnoManana): void {
    $pdoTest->exec("UPDATE turno SET estado = 0 WHERE id_turno = {$idTurnoManana}");
}, 'SHIFT_HAS_ASSIGNED_SELLERS');

// --- C. SESIONES DE CAJA Y APERTURA ---
$idSesion1 = 0;

$expectFailure('C1_APERTURA_ADMIN_PROHIBIDA', 'Rechazar apertura de sesión por usuario con rol Administrador (id_rol = 1)', function () use ($pdoTest, &$idTurnoManana): void {
    $pdoTest->exec(
        "INSERT INTO sesion_caja (
            id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
            hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
            tiene_cierre_anterior, id_sesion_cierre_anterior, saldo_inicial,
            diferencia_apertura, efectivo_esperado_actual, version_caja, estado
        ) VALUES (
            1, 1, {$idTurnoManana}, 'Mañana', '08:00:00', '14:00:00', '2026-11-12 14:00:00',
            0, NULL, 100.00, NULL, 100.00, 1, 'ABIERTA'
        )"
    );
}, 'FORBIDDEN_SELLER_ONLY');

$expectSuccess('C2_PRIMERA_APERTURA_SIN_CIERRE_ANTERIOR', 'Abrir primera sesión histórica con tiene_cierre_anterior = 0, id_sesion_cierre_anterior = NULL y diferencia_apertura = NULL', function () use ($pdoTest, &$idSesion1, &$idTurnoManana): string {
    $pdoTest->exec(
        "INSERT INTO sesion_caja (
            id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
            hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
            tiene_cierre_anterior, id_sesion_cierre_anterior, saldo_inicial,
            diferencia_apertura, efectivo_esperado_actual, version_caja, estado
        ) VALUES (
            1, 4, {$idTurnoManana}, 'Mañana', '08:00:00', '14:00:00', '2026-11-12 14:00:00',
            0, NULL, 100.00, NULL, 100.00, 1, 'ABIERTA'
        )"
    );
    $idSesion1 = (int)$pdoTest->lastInsertId();
    return "idSesion1={$idSesion1} ABIERTA con saldo_inicial=100.00, version_caja=1";
});

$expectFailure('C3_SEGUNDA_SESION_ABIERTA_RECHAZADA', 'Rechazar segunda sesión ABIERTA simultánea mediante guardia única uq_sesion_caja_unica_abierta', function () use ($pdoTest, &$idTurnoTarde): void {
    $pdoTest->exec(
        "INSERT INTO sesion_caja (
            id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
            hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
            tiene_cierre_anterior, id_sesion_cierre_anterior, saldo_inicial,
            diferencia_apertura, efectivo_esperado_actual, version_caja, estado
        ) VALUES (
            1, 6, {$idTurnoTarde}, 'Tarde', '14:00:00', '20:00:00', '2026-11-12 20:00:00',
            0, NULL, 80.00, NULL, 80.00, 1, 'ABIERTA'
        )"
    );
}, 'uq_sesion_caja_unica_abierta');

// --- D & E. MOVIMIENTOS Y CORRECCIONES ENCADENADAS ---
$idMovIngreso = 0;

$expectSuccess('D1_MOVIMIENTOS_INGRESO_EGRESO', 'Registrar INGRESO_MANUAL (+50.00), INYECCION_ADMIN (+30.00) y SALIDA_CAJA (-20.00)', function () use ($pdoTest, &$idSesion1, &$idMovIngreso): string {
    $pdoTest->exec(
        "INSERT INTO movimiento_caja (
            id_sesion_caja, tipo_movimiento, monto, efecto_saldo, motivo, id_usuario_registro
        ) VALUES ({$idSesion1}, 'INGRESO_MANUAL', 50.00, 50.00, 'Sencillo adicional', 4)"
    );
    $idMovIngreso = (int)$pdoTest->lastInsertId();

    $pdoTest->exec(
        "INSERT INTO movimiento_caja (
            id_sesion_caja, tipo_movimiento, monto, efecto_saldo, motivo, id_usuario_registro, id_usuario_admin
        ) VALUES ({$idSesion1}, 'INYECCION_ADMIN', 30.00, 30.00, 'Habilitación de cambio por admin', 4, 1)"
    );

    $pdoTest->exec(
        "INSERT INTO movimiento_caja (
            id_sesion_caja, tipo_movimiento, monto, efecto_saldo, motivo, id_usuario_registro
        ) VALUES ({$idSesion1}, 'SALIDA_CAJA', 20.00, -20.00, 'Compra urgente bolsas', 4)"
    );

    $pdoTest->exec(
        "UPDATE sesion_caja
            SET total_ingresos = 80.00,
                total_egresos = 20.00,
                efectivo_esperado_actual = 160.00,
                version_caja = version_caja + 3
          WHERE id_sesion_caja = {$idSesion1}"
    );
    return "idMovIngreso={$idMovIngreso}; efectivo_esperado_actual=160.00, version_caja=4";
});

$expectFailure('D2_INYECCION_ADMIN_SIN_ADMIN', 'Rechazar INYECCION_ADMIN sin id_usuario_admin (ADMIN_AUTHORIZATION_REQUIRED)', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec(
        "INSERT INTO movimiento_caja (
            id_sesion_caja, tipo_movimiento, monto, efecto_saldo, motivo, id_usuario_registro, id_usuario_admin
        ) VALUES ({$idSesion1}, 'INYECCION_ADMIN', 25.00, 25.00, 'Sin admin', 4, NULL)"
    );
}, 'ADMIN_AUTHORIZATION_REQUIRED');

$expectFailure('D3_MOVIMIENTO_UPDATE_PROHIBIDO', 'Rechazar edición directa (UPDATE) sobre movimiento_caja original', function () use ($pdoTest, &$idMovIngreso): void {
    $pdoTest->exec("UPDATE movimiento_caja SET monto = 99.00, efecto_saldo = 99.00 WHERE id_movimiento_caja = {$idMovIngreso}");
}, 'MOVIMIENTO_CAJA_INMUTABLE');

$expectSuccess('E1_CORRECCIONES_ENCADENADAS_SIN_MUTAR_ORIGINAL', 'Registrar 2 correcciones encadenadas sobre INGRESO_MANUAL (50 -> 65 -> 45) y comprobar que el original conserva 50.00 y el efecto neto (+15 y -20) se contabiliza una sola vez', function () use ($pdoTest, &$idSesion1, &$idMovIngreso): string {
    // Eslabón 1: 50.00 -> 65.00 (efecto +15.00)
    $pdoTest->exec(
        "INSERT INTO movimiento_caja (
            id_sesion_caja, tipo_movimiento, monto, efecto_saldo, motivo, id_usuario_registro, id_movimiento_original
        ) VALUES ({$idSesion1}, 'CORRECCION', 15.00, 15.00, 'Ajuste conteo sencillo #1', 4, {$idMovIngreso})"
    );
    $idMovCorr1 = (int)$pdoTest->lastInsertId();

    $pdoTest->exec(
        "INSERT INTO correccion_movimiento_caja (
            id_sesion_caja, id_movimiento_original, id_movimiento_correccion, id_correccion_previa,
            numero_eslabon, monto_original, monto_vigente_previo, monto_corregido, efecto_saldo, motivo, id_usuario_registro
        ) VALUES (
            {$idSesion1}, {$idMovIngreso}, {$idMovCorr1}, NULL,
            1, 50.00, 50.00, 65.00, 15.00, 'Ajuste conteo sencillo #1', 4
        )"
    );
    $idCorr1 = (int)$pdoTest->lastInsertId();

    // Eslabón 2: 65.00 -> 45.00 (efecto -20.00)
    $pdoTest->exec(
        "INSERT INTO movimiento_caja (
            id_sesion_caja, tipo_movimiento, monto, efecto_saldo, motivo, id_usuario_registro, id_movimiento_original
        ) VALUES ({$idSesion1}, 'CORRECCION', 20.00, -20.00, 'Ajuste conteo sencillo #2', 4, {$idMovIngreso})"
    );
    $idMovCorr2 = (int)$pdoTest->lastInsertId();

    $pdoTest->exec(
        "INSERT INTO correccion_movimiento_caja (
            id_sesion_caja, id_movimiento_original, id_movimiento_correccion, id_correccion_previa,
            numero_eslabon, monto_original, monto_vigente_previo, monto_corregido, efecto_saldo, motivo, id_usuario_registro
        ) VALUES (
            {$idSesion1}, {$idMovIngreso}, {$idMovCorr2}, {$idCorr1},
            2, 50.00, 65.00, 45.00, -20.00, 'Ajuste conteo sencillo #2', 4
        )"
    );

    $pdoTest->exec(
        "UPDATE sesion_caja
            SET total_ingresos = 95.00,
                total_egresos = 40.00,
                efectivo_esperado_actual = 155.00,
                version_caja = version_caja + 2
          WHERE id_sesion_caja = {$idSesion1}"
    );

    $origRow = $pdoTest->query("SELECT monto, motivo FROM movimiento_caja WHERE id_movimiento_caja = {$idMovIngreso}")->fetch();
    $currAmount = (string)$pdoTest->query(
        "SELECT COALESCE(
            (SELECT c.monto_corregido FROM correccion_movimiento_caja c WHERE c.id_movimiento_original = {$idMovIngreso} ORDER BY c.numero_eslabon DESC LIMIT 1),
            m.monto
         ) FROM movimiento_caja m WHERE m.id_movimiento_caja = {$idMovIngreso}"
    )->fetchColumn();

    if ((string)$origRow['monto'] !== '50.00' || $currAmount !== '45.00') {
        throw new RuntimeException('Monto original o monto vigente derivado inconsistente.');
    }
    return "Original intacto=50.00, current_amount derivado={$currAmount}, efectivo_esperado_actual=155.00, version_caja=6";
});

// --- F & G. ARQUEOS, REEMPLAZO Y CIERRE ANTICIPADO CON AUTORIZACIÓN (CON INCREMENTO DE VERSIÓN) ---
$idArqueo1 = 0;
$idArqueo2 = 0;

$expectFailure('F1_ARQUEO_DIFERENCIA_SIN_OBSERVACION', 'Rechazar arqueo con diferencia <> 0.00 y observación nula', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES ({$idSesion1}, 4, 6, 155.00, 145.00, -10.00, NULL, 'VIGENTE')"
    );
}, 'chk_arqueo_observacion_condicional');

$expectFailure('CORR2_F_DIFERENCIA_OBSERVACION_SOLO_ESPACIOS', 'Rechazar arqueo con diferencia <> 0.00 y observación vacía o de solo espacios ("   ")', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES ({$idSesion1}, 4, 6, 155.00, 145.00, -10.00, '     ', 'VIGENTE')"
    );
}, 'chk_arqueo_observacion_condicional');

$expectSuccess('F2_ARQUEO_REEMPLAZO_Y_UNICO_VIGENTE', 'Registrar arqueo #1 (v6), reemplazarlo por arqueo #2 (v6) conservando historial y único VIGENTE por sesión', function () use ($pdoTest, &$idSesion1, &$idArqueo1, &$idArqueo2): string {
    $pdoTest->exec(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES ({$idSesion1}, 4, 6, 155.00, 150.00, -5.00, 'Primer conteo preliminar', 'VIGENTE')"
    );
    $idArqueo1 = (int)$pdoTest->lastInsertId();

    $pdoTest->exec(
        "UPDATE arqueo_caja
            SET estado = 'REEMPLAZADO',
                causa_cambio_estado = 'NUEVO_ARQUEO',
                fecha_hora_cambio_estado = NOW()
          WHERE id_arqueo_caja = {$idArqueo1}"
    );

    $pdoTest->exec(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES ({$idSesion1}, 4, 6, 155.00, 145.00, -10.00, 'Faltante verificado de diez soles', 'VIGENTE')"
    );
    $idArqueo2 = (int)$pdoTest->lastInsertId();

    $pdoTest->exec("UPDATE sesion_caja SET id_ultimo_arqueo = {$idArqueo2} WHERE id_sesion_caja = {$idSesion1}");
    return "Arqueo #1 ({$idArqueo1}) REEMPLAZADO; Arqueo #2 ({$idArqueo2}) VIGENTE en version_caja_evaluada=6";
});

$expectFailure('G1_CIERRE_ANTICIPADO_SIN_AUTORIZACION', 'Rechazar cierre anticipado (> 15 min antes de salida de referencia) sin registro en autorizacion_caja incluso con incremento de versión correcto (6 -> 7)', function () use ($pdoTest, &$idSesion1, &$idArqueo2): void {
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = version_caja + 1,
                id_arqueo_cierre = {$idArqueo2},
                fecha_hora_cierre = '2026-11-12 13:00:00',
                efectivo_esperado_cierre = 155.00,
                efectivo_contado_cierre = 145.00,
                diferencia_cierre = -10.00
          WHERE id_sesion_caja = {$idSesion1}"
    );
}, 'EARLY_CLOSE_AUTH_REQUIRED');

$expectSuccess('G2_CORR1_E_CIERRE_ANTICIPADO_CON_AUTORIZACION_E_INCREMENTO_VERSION', 'Registrar autorización administrativa (60 min antes) y cerrar Sesión #1 incrementando version_caja de 6 a 7 con arqueo VIGENTE evaluado en v6 (Corrección 1-E)', function () use ($pdoTest, &$idSesion1, &$idArqueo2): string {
    $pdoTest->exec(
        "INSERT INTO autorizacion_caja (
            id_sesion_caja, tipo_operacion, id_usuario_vendedor, id_usuario_admin,
            motivo, hora_salida_referencia, fecha_hora_salida_referencia,
            hora_cierre_real, minutos_anticipacion
        ) VALUES (
            {$idSesion1}, 'CIERRE_ANTICIPADO', 4, 1,
            'Permiso médico autorizado por administración',
            '14:00:00', '2026-11-12 14:00:00', '2026-11-12 13:00:00', 60
        )"
    );
    $idAut = (int)$pdoTest->lastInsertId();

    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = version_caja + 1,
                id_arqueo_cierre = {$idArqueo2},
                fecha_hora_cierre = '2026-11-12 13:00:00',
                efectivo_esperado_cierre = 155.00,
                efectivo_contado_cierre = 145.00,
                diferencia_cierre = -10.00
          WHERE id_sesion_caja = {$idSesion1}"
    );

    $rowS1 = $pdoTest->query("SELECT estado, version_caja, observacion_cierre FROM sesion_caja WHERE id_sesion_caja = {$idSesion1}")->fetch();
    $rowArq2 = $pdoTest->query("SELECT estado, version_caja_evaluada FROM arqueo_caja WHERE id_arqueo_caja = {$idArqueo2}")->fetch();

    if ($rowS1['estado'] !== 'CERRADA' || (int)$rowS1['version_caja'] !== 7 || $rowArq2['estado'] !== 'VIGENTE' || (int)$rowArq2['version_caja_evaluada'] !== 6) {
        throw new RuntimeException('Estado o versión post-cierre inconsistente.');
    }
    return "idAutorizacion={$idAut}; Sesión #{$idSesion1} CERRADA en version_caja=7 con arqueo #{$idArqueo2} VIGENTE (version_caja_evaluada=6)";
});

// --- CORRECCIÓN 3: CONGELAMIENTO REAL NULL-SAFE DE SESIÓN CERRADA (36 COLUMNAS) ---
$s1Cols = getTableColumns($pdoTest, $testDb, 'sesion_caja');
$s1HashBeforeFreezeTests = canonicalTableSha256($pdoTest, 'sesion_caja', $s1Cols, "id_sesion_caja = {$idSesion1}", 'id_sesion_caja ASC');

$expectFailure('CORR3_A1_SESION_CERRADA_MUTAR_OBSERVACION_CIERRE', 'Rechazar cambio de observacion_cierre en sesión CERRADA (valor -> otro valor)', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec("UPDATE sesion_caja SET observacion_cierre = 'Intento de alterar observación post-cierre' WHERE id_sesion_caja = {$idSesion1}");
}, 'CASH_SESSION_NOT_OPEN');

$expectFailure('CORR3_A2_SESION_CERRADA_ANULAR_OBSERVACION_CIERRE', 'Rechazar cambio NULL-safe de observacion_cierre (valor -> NULL) en sesión CERRADA', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec("UPDATE sesion_caja SET observacion_cierre = NULL WHERE id_sesion_caja = {$idSesion1}");
}, 'CASH_SESSION_NOT_OPEN');

$expectFailure('CORR3_B_SESION_CERRADA_MUTAR_IMPORTES', 'Rechazar cambio de importes (efectivo_contado_cierre / diferencia_cierre) en sesión CERRADA', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec("UPDATE sesion_caja SET efectivo_contado_cierre = 155.00, diferencia_cierre = 0.00 WHERE id_sesion_caja = {$idSesion1}");
}, 'CASH_SESSION_NOT_OPEN');

$expectFailure('CORR3_C1_SESION_CERRADA_MUTAR_HORARIO_REFERENCIA', 'Rechazar cambio de horario/referencia (fecha_hora_salida_referencia / nombre_turno_referencia) en sesión CERRADA', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec("UPDATE sesion_caja SET fecha_hora_salida_referencia = '2026-11-12 15:00:00', nombre_turno_referencia = 'Alterado' WHERE id_sesion_caja = {$idSesion1}");
}, 'CASH_SESSION_NOT_OPEN');

$expectFailure('CORR3_C2_SESION_CERRADA_MUTAR_CAMPOS_NULL_A_VALOR', 'Rechazar cambio NULL -> valor en campos anulables (diferencia_apertura / monto_referencia_cierre_anterior) en sesión CERRADA', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec("UPDATE sesion_caja SET diferencia_apertura = 0.00 WHERE id_sesion_caja = {$idSesion1}");
}, 'CASH_SESSION_NOT_OPEN');

$expectFailure('CORR3_D_SESION_CERRADA_MUTAR_ARQUEO_CIERRE', 'Rechazar cambio de id_arqueo_cierre / id_ultimo_arqueo en sesión CERRADA', function () use ($pdoTest, &$idSesion1, &$idArqueo1): void {
    $pdoTest->exec("UPDATE sesion_caja SET id_arqueo_cierre = {$idArqueo1}, id_ultimo_arqueo = {$idArqueo1} WHERE id_sesion_caja = {$idSesion1}");
}, 'CASH_SESSION_NOT_OPEN');

$expectFailure('CORR3_E_SESION_CERRADA_REABRIR', 'Rechazar intento de reapertura (estado = ABIERTA) de una sesión CERRADA', function () use ($pdoTest, &$idSesion1): void {
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'ABIERTA',
                id_arqueo_cierre = NULL,
                fecha_hora_cierre = NULL,
                efectivo_esperado_cierre = NULL,
                efectivo_contado_cierre = NULL,
                diferencia_cierre = NULL,
                observacion_cierre = NULL
          WHERE id_sesion_caja = {$idSesion1}"
    );
}, 'CASH_SESSION_NOT_OPEN');

$expectFailure('CORR3_F_ARQUEO_DE_SESION_CERRADA_INMUTABLE', 'Rechazar cambio de estado en arqueo_caja perteneciente a una sesión ya CERRADA', function () use ($pdoTest, &$idArqueo2): void {
    $pdoTest->exec("UPDATE arqueo_caja SET estado = 'DESACTUALIZADO', causa_cambio_estado = 'OPERACION_CAJA_POSTERIOR', fecha_hora_cambio_estado = NOW() WHERE id_arqueo_caja = {$idArqueo2}");
}, 'HISTORIAL_ARQUEO_INMUTABLE');

$expectSuccess('CORR3_G_VERIFICAR_FILA_SESION_CERRADA_INTACTA', 'Comprobar mediante hash SHA-256 de las 36 columnas que la fila de sesion_caja CERRADA permaneció 100 % intacta tras todos los intentos de mutación', function () use ($pdoTest, &$idSesion1, $s1Cols, $s1HashBeforeFreezeTests): string {
    $s1HashAfter = canonicalTableSha256($pdoTest, 'sesion_caja', $s1Cols, "id_sesion_caja = {$idSesion1}", 'id_sesion_caja ASC');
    if ($s1HashBeforeFreezeTests !== $s1HashAfter) {
        throw new RuntimeException("El hash de sesion_caja #{$idSesion1} cambió: {$s1HashBeforeFreezeTests} vs {$s1HashAfter}");
    }
    return "SHA-256 intacto en las 36 columnas: {$s1HashAfter}";
});

// --- C/D/G. SEGUNDA SESIÓN, RETIRO OMITIDO Y CIERRE EN VENTANA DE 15 MIN ---
$idSesion2 = 0;

$expectFailure('C4_APERTURA_POSTERIOR_SIN_REFERENCIA_CIERRE', 'Rechazar apertura con tiene_cierre_anterior = 0 cuando ya existen sesiones cerradas', function () use ($pdoTest, &$idTurnoTarde): void {
    $pdoTest->exec(
        "INSERT INTO sesion_caja (
            id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
            hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
            tiene_cierre_anterior, id_sesion_cierre_anterior, saldo_inicial,
            diferencia_apertura, efectivo_esperado_actual, version_caja, estado
        ) VALUES (
            1, 4, {$idTurnoTarde}, 'Tarde', '14:00:00', '20:00:00', '2026-11-12 20:00:00',
            0, NULL, 125.00, NULL, 125.00, 1, 'ABIERTA'
        )"
    );
}, 'APERTURA_REQUIERE_CIERRE_ANTERIOR');

$expectSuccess('C5_SEGUNDA_APERTURA_CON_DIFERENCIA_Y_RETIRO_OMITIDO', 'Abrir Sesión #2 referenciando Sesión #1 (diferencia_apertura = -20.00), registrar RETIRO_OMITIDO con las 2 declaraciones (efecto_saldo = 0.00) y cerrar dentro de la ventana de 15 min incrementando version_caja (2 -> 3)', function () use ($pdoTest, &$idSesion1, &$idSesion2, &$idTurnoTarde): string {
    $pdoTest->exec(
        "INSERT INTO sesion_caja (
            id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
            hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
            tiene_cierre_anterior, id_sesion_cierre_anterior, monto_referencia_cierre_anterior,
            fecha_hora_cierre_anterior_ref, nombre_turno_cierre_anterior_ref,
            saldo_inicial, diferencia_apertura, efectivo_esperado_actual, version_caja, estado
        ) VALUES (
            1, 4, {$idTurnoTarde}, 'Tarde', '14:00:00', '20:00:00', '2026-11-12 20:00:00',
            1, {$idSesion1}, 145.00, '2026-11-12 13:00:00', 'Mañana',
            125.00, -20.00, 125.00, 1, 'ABIERTA'
        )"
    );
    $idSesion2 = (int)$pdoTest->lastInsertId();

    $pdoTest->exec(
        "INSERT INTO movimiento_caja (
            id_sesion_caja, tipo_movimiento, monto, efecto_saldo, motivo,
            id_usuario_registro, id_sesion_origen_omitido,
            declarado_ocurrio_en_sesion_origen_abierta, confirmado_reflejado_en_apertura
        ) VALUES (
            {$idSesion2}, 'RETIRO_OMITIDO', 20.00, 0.00,
            'Retiro de veinte soles al cierre del turno mañana no anotado',
            4, {$idSesion1}, 1, 1
        )"
    );

    $pdoTest->exec(
        "UPDATE sesion_caja
            SET total_retiros_omitidos_declarados = 20.00,
                version_caja = version_caja + 1
          WHERE id_sesion_caja = {$idSesion2}"
    );

    $pdoTest->exec(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES ({$idSesion2}, 4, 2, 125.00, 125.00, 0.00, NULL, 'VIGENTE')"
    );
    $idArqS2 = (int)$pdoTest->lastInsertId();

    $pdoTest->exec("UPDATE sesion_caja SET id_ultimo_arqueo = {$idArqS2} WHERE id_sesion_caja = {$idSesion2}");

    // Cierre a las 19:50:00 (10 minutos antes de las 20:00:00 -> dentro de la tolerancia de 15 min), incrementando version_caja de 2 a 3
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET id_arqueo_cierre = {$idArqS2},
                version_caja = version_caja + 1,
                estado = 'CERRADA',
                fecha_hora_cierre = '2026-11-12 19:50:00',
                efectivo_esperado_cierre = 125.00,
                efectivo_contado_cierre = 125.00,
                diferencia_cierre = 0.00
          WHERE id_sesion_caja = {$idSesion2}"
    );
    return "Sesión #{$idSesion2} abierta con dif=-20.00, RETIRO_OMITIDO=20.00 (efecto=0.00) y cerrada a las 19:50 en version_caja=3";
});

$expectFailure('CORR3_H_SESION2_CERRADA_MUTAR_OBSERVACION_NULL_A_VALOR', 'Rechazar cambio NULL-safe de observacion_cierre (NULL -> valor) en Sesión #2 CERRADA', function () use ($pdoTest, &$idSesion2): void {
    $pdoTest->exec("UPDATE sesion_caja SET observacion_cierre = 'Intento de agregar observación después de cerrada' WHERE id_sesion_caja = {$idSesion2}");
}, 'CASH_SESSION_NOT_OPEN');

// --- CORRECCIÓN 1 (A, B, C, D, F) Y CORRECCIÓN 2 (250, 251, 500 UNICODE, 501): TERCERA SESIÓN DEDICADA ---
$idSesion3 = 0;
$idArqS3_v4_reemplazado = 0;
$idArqS3_v4_desactualizado = 0;
$idArqS3_v5_250 = 0;
$idArqS3_v5_251 = 0;
$idArqS3_v5_500 = 0;
$obs500Unicode = '';

$expectSuccess('CORR1_SETUP_SESION3_V4', 'Abrir Sesión #3 y llevarla a version_caja = 4 para probar escenarios de versión de arqueo y observaciones de 250/251/500/501 caracteres', function () use ($pdoTest, &$idSesion2, &$idSesion3, &$idTurnoManana, &$idArqS3_v4_reemplazado, &$idArqS3_v4_desactualizado): string {
    $pdoTest->exec(
        "INSERT INTO sesion_caja (
            id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
            hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
            tiene_cierre_anterior, id_sesion_cierre_anterior, monto_referencia_cierre_anterior,
            fecha_hora_cierre_anterior_ref, nombre_turno_cierre_anterior_ref,
            saldo_inicial, diferencia_apertura, efectivo_esperado_actual, version_caja, estado
        ) VALUES (
            1, 4, {$idTurnoManana}, 'Mañana', '08:00:00', '14:00:00', '2026-11-13 14:00:00',
            1, {$idSesion2}, 125.00, '2026-11-12 19:50:00', 'Tarde',
            125.00, 0.00, 125.00, 4, 'ABIERTA'
        )"
    );
    $idSesion3 = (int)$pdoTest->lastInsertId();

    // Arqueo en v4 que será REEMPLAZADO
    $pdoTest->exec(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES ({$idSesion3}, 4, 4, 125.00, 125.00, 0.00, 'Arqueo v4 inicial', 'VIGENTE')"
    );
    $idArqS3_v4_reemplazado = (int)$pdoTest->lastInsertId();
    $pdoTest->exec("UPDATE arqueo_caja SET estado = 'REEMPLAZADO', causa_cambio_estado = 'NUEVO_ARQUEO', fecha_hora_cambio_estado = NOW() WHERE id_arqueo_caja = {$idArqS3_v4_reemplazado}");

    // Segundo arqueo en v4 que quedará en estado VIGENTE en la fila (para probar comparación pura de versión v4 vs sesión v5)
    $pdoTest->exec(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES ({$idSesion3}, 4, 4, 125.00, 125.00, 0.00, 'Arqueo v4 segundo', 'VIGENTE')"
    );
    $idArqS3_v4_desactualizado = (int)$pdoTest->lastInsertId();

    // La sesión avanza a versión 5 por una operación posterior
    $pdoTest->exec("UPDATE sesion_caja SET id_ultimo_arqueo = {$idArqS3_v4_desactualizado}, version_caja = 5 WHERE id_sesion_caja = {$idSesion3}");

    // Registrar también autorización administrativa en Sesión #3 para probar CORR1_F (credenciales/autorización no permiten arqueo v4 con sesión v5)
    $pdoTest->exec(
        "INSERT INTO autorizacion_caja (
            id_sesion_caja, tipo_operacion, id_usuario_vendedor, id_usuario_admin,
            motivo, hora_salida_referencia, fecha_hora_salida_referencia,
            hora_cierre_real, minutos_anticipacion
        ) VALUES (
            {$idSesion3}, 'CIERRE_ANTICIPADO', 4, 1,
            'Autorización previa registrada para verificar que no salta validación de versión de arqueo',
            '14:00:00', '2026-11-13 14:00:00', '2026-11-13 12:30:00', 90
        )"
    );

    return "idSesion3={$idSesion3} en version_caja=5 con arqueo #{$idArqS3_v4_desactualizado} evaluado en v4";
});

$expectFailure('CORR1_B_ARQUEO_V4_CON_SESION_V5_RECHAZADO', 'Corrección 1-B: Rechazar cierre de sesión en versión 5 cuando el arqueo tiene version_caja_evaluada = 4', function () use ($pdoTest, &$idSesion3, &$idArqS3_v4_desactualizado): void {
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = 6,
                id_arqueo_cierre = {$idArqS3_v4_desactualizado},
                fecha_hora_cierre = '2026-11-13 14:00:00',
                efectivo_esperado_cierre = 125.00,
                efectivo_contado_cierre = 125.00,
                diferencia_cierre = 0.00
          WHERE id_sesion_caja = {$idSesion3}"
    );
}, 'CASH_COUNT_OUTDATED');

$expectFailure('CORR1_F_AUTORIZACION_NO_PERMITE_ARQUEO_DESACTUALIZADO', 'Corrección 1-F: Comprobar que aun con autorización administrativa válida registrada, se rechaza cerrar con arqueo desactualizado (v4 en sesión v5)', function () use ($pdoTest, &$idSesion3, &$idArqS3_v4_desactualizado): void {
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = 6,
                id_arqueo_cierre = {$idArqS3_v4_desactualizado},
                fecha_hora_cierre = '2026-11-13 12:30:00',
                efectivo_esperado_cierre = 125.00,
                efectivo_contado_cierre = 125.00,
                diferencia_cierre = 0.00
          WHERE id_sesion_caja = {$idSesion3}"
    );
}, 'CASH_COUNT_OUTDATED');

// Marcar el arqueo v4 como DESACTUALIZADO por operación posterior y probar arqueos en v5 con observaciones de 250, 251, 500 y 501 caracteres
$expectSuccess('CORR2_A_OBSERVACION_250_CARACTERES', 'Corrección 2: Permitir arqueo en v5 con observación de exactamente 250 caracteres', function () use ($pdoTest, &$idSesion3, &$idArqS3_v4_desactualizado, &$idArqS3_v5_250): string {
    $pdoTest->exec("UPDATE arqueo_caja SET estado = 'DESACTUALIZADO', causa_cambio_estado = 'OPERACION_CAJA_POSTERIOR', fecha_hora_cambio_estado = NOW() WHERE id_arqueo_caja = {$idArqS3_v4_desactualizado}");

    $obs250 = str_repeat('A', 250);
    $stmt = $pdoTest->prepare(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES (?, 4, 5, 125.00, 120.00, -5.00, ?, 'VIGENTE')"
    );
    $stmt->execute([$idSesion3, $obs250]);
    $idArqS3_v5_250 = (int)$pdoTest->lastInsertId();

    $len = (int)$pdoTest->query("SELECT CHAR_LENGTH(observacion) FROM arqueo_caja WHERE id_arqueo_caja = {$idArqS3_v5_250}")->fetchColumn();
    if ($len !== 250) {
        throw new RuntimeException("Longitud esperada 250, obtenida {$len}");
    }
    return "idArqueo={$idArqS3_v5_250}, CHAR_LENGTH(observacion)={$len}";
});

$expectSuccess('CORR2_B_OBSERVACION_251_CARACTERES', 'Corrección 2: Permitir arqueo en v5 con observación de 251 caracteres (antes rechazada por límite de 250)', function () use ($pdoTest, &$idSesion3, &$idArqS3_v5_250, &$idArqS3_v5_251): string {
    $pdoTest->exec("UPDATE arqueo_caja SET estado = 'REEMPLAZADO', causa_cambio_estado = 'NUEVO_ARQUEO', fecha_hora_cambio_estado = NOW() WHERE id_arqueo_caja = {$idArqS3_v5_250}");

    $obs251 = str_repeat('B', 251);
    $stmt = $pdoTest->prepare(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES (?, 4, 5, 125.00, 120.00, -5.00, ?, 'VIGENTE')"
    );
    $stmt->execute([$idSesion3, $obs251]);
    $idArqS3_v5_251 = (int)$pdoTest->lastInsertId();

    $len = (int)$pdoTest->query("SELECT CHAR_LENGTH(observacion) FROM arqueo_caja WHERE id_arqueo_caja = {$idArqS3_v5_251}")->fetchColumn();
    if ($len !== 251) {
        throw new RuntimeException("Longitud esperada 251, obtenida {$len}");
    }
    return "idArqueo={$idArqS3_v5_251}, CHAR_LENGTH(observacion)={$len}";
});

$expectSuccess('CORR2_C_OBSERVACION_500_CARACTERES_UNICODE', 'Corrección 2: Permitir arqueo en v5 con observación de exactamente 500 caracteres con tildes y eñes (CHAR_LENGTH = 500)', function () use ($pdoTest, &$idSesion3, &$idArqS3_v5_251, &$idArqS3_v5_500, &$obs500Unicode): string {
    $pdoTest->exec("UPDATE arqueo_caja SET estado = 'REEMPLAZADO', causa_cambio_estado = 'NUEVO_ARQUEO', fecha_hora_cambio_estado = NOW() WHERE id_arqueo_caja = {$idArqS3_v5_251}");

    // Bloque base de 50 caracteres Unicode repetido 10 veces = 500 caracteres exactos
    $bloque50 = 'Arqueo lingüístico S/5.00 mañana niño acción #0123';
    if (mb_strlen($bloque50, 'UTF-8') !== 50) {
        throw new RuntimeException('El bloque base no mide 50 caracteres UTF-8.');
    }
    $obs500Unicode = str_repeat($bloque50, 10);

    $stmt = $pdoTest->prepare(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES (?, 4, 5, 125.00, 120.00, -5.00, ?, 'VIGENTE')"
    );
    $stmt->execute([$idSesion3, $obs500Unicode]);
    $idArqS3_v5_500 = (int)$pdoTest->lastInsertId();

    $pdoTest->exec("UPDATE sesion_caja SET id_ultimo_arqueo = {$idArqS3_v5_500} WHERE id_sesion_caja = {$idSesion3}");

    $row = $pdoTest->query("SELECT CHAR_LENGTH(observacion) AS char_len, OCTET_LENGTH(observacion) AS byte_len, observacion FROM arqueo_caja WHERE id_arqueo_caja = {$idArqS3_v5_500}")->fetch();
    if ((int)$row['char_len'] !== 500 || $row['observacion'] !== $obs500Unicode) {
        throw new RuntimeException("Error en almacenamiento de 500 caracteres Unicode.");
    }
    return "idArqueo={$idArqS3_v5_500}, CHAR_LENGTH={$row['char_len']}, OCTET_LENGTH={$row['byte_len']}";
});

$expectFailure('CORR2_D_OBSERVACION_501_CARACTERES_RECHAZADA', 'Corrección 2: Rechazar arqueo con observación de 501 caracteres bajo modo SQL estricto', function () use ($pdoTest, &$idSesion3): void {
    $obs501 = str_repeat('X', 501);
    $stmt = $pdoTest->prepare(
        "INSERT INTO arqueo_caja (
            id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
            monto_esperado, monto_contado, diferencia, observacion, estado
        ) VALUES (?, 4, 5, 125.00, 120.00, -5.00, ?, 'VIGENTE')"
    );
    $stmt->execute([$idSesion3, $obs501]);
});

$expectFailure('CORR1_C_ARQUEO_REEMPLAZADO_O_NO_ULTIMO_RECHAZADO', 'Corrección 1-C: Rechazar cierre cuando se intenta usar un arqueo REEMPLAZADO o distinto de id_ultimo_arqueo', function () use ($pdoTest, &$idSesion3, &$idArqS3_v5_251): void {
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = 6,
                id_arqueo_cierre = {$idArqS3_v5_251},
                fecha_hora_cierre = '2026-11-13 14:00:00',
                efectivo_esperado_cierre = 125.00,
                efectivo_contado_cierre = 120.00,
                diferencia_cierre = -5.00
          WHERE id_sesion_caja = {$idSesion3}"
    );
}, 'CASH_COUNT_REQUIRED');

$expectFailure('CORR1_D1_CIERRE_SIN_INCREMENTAR_VERSION_RECHAZADO', 'Corrección 1-D (1): Rechazar cierre cuando version_caja no se incrementa (permanece en 5 en lugar de subir a 6)', function () use ($pdoTest, &$idSesion3, &$idArqS3_v5_500): void {
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = 5,
                id_arqueo_cierre = {$idArqS3_v5_500},
                fecha_hora_cierre = '2026-11-13 14:00:00',
                efectivo_esperado_cierre = 125.00,
                efectivo_contado_cierre = 120.00,
                diferencia_cierre = -5.00
          WHERE id_sesion_caja = {$idSesion3}"
    );
}, 'CIERRE_VERSION_INCREMENTO_REQUERIDO');

$expectFailure('CORR1_D2_CIERRE_INCREMENTO_SALTO_INVALIDO_RECHAZADO', 'Corrección 1-D (2): Rechazar cierre cuando version_caja se incrementa en +2 (de 5 a 7 en lugar de 6)', function () use ($pdoTest, &$idSesion3, &$idArqS3_v5_500): void {
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = 7,
                id_arqueo_cierre = {$idArqS3_v5_500},
                fecha_hora_cierre = '2026-11-13 14:00:00',
                efectivo_esperado_cierre = 125.00,
                efectivo_contado_cierre = 120.00,
                diferencia_cierre = -5.00
          WHERE id_sesion_caja = {$idSesion3}"
    );
}, 'CIERRE_VERSION_INCREMENTO_REQUERIDO');

$expectFailure('CORR2_E_OBSERVACION_CIERRE_501_RECHAZADA', 'Corrección 2: Rechazar observacion_cierre de 501 caracteres al cerrar sesión bajo modo estricto', function () use ($pdoTest, &$idSesion3, &$idArqS3_v5_500): void {
    $obs501 = str_repeat('Z', 501);
    $stmt = $pdoTest->prepare(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = 6,
                id_arqueo_cierre = {$idArqS3_v5_500},
                fecha_hora_cierre = '2026-11-13 14:00:00',
                efectivo_esperado_cierre = 125.00,
                efectivo_contado_cierre = 120.00,
                diferencia_cierre = -5.00,
                observacion_cierre = ?
          WHERE id_sesion_caja = {$idSesion3}"
    );
    $stmt->execute([$obs501]);
});

$expectSuccess('CORR1_A_Y_CORR2_COPIA_INTACTA_500_AL_CERRAR', 'Corrección 1-A y Corrección 2: Cerrar Sesión #3 (versión 5 -> versión 6 con arqueo VIGENTE versión 5) y verificar copia íntegra sin truncamiento de los 500 caracteres Unicode a observacion_cierre', function () use ($pdoTest, &$idSesion3, &$idArqS3_v5_500, &$obs500Unicode): string {
    $pdoTest->exec(
        "UPDATE sesion_caja
            SET estado = 'CERRADA',
                version_caja = 6,
                id_arqueo_cierre = {$idArqS3_v5_500},
                fecha_hora_cierre = '2026-11-13 14:00:00',
                efectivo_esperado_cierre = 125.00,
                efectivo_contado_cierre = 120.00,
                diferencia_cierre = -5.00,
                observacion_cierre = NULL
          WHERE id_sesion_caja = {$idSesion3}"
    );

    $rowS3 = $pdoTest->query("SELECT estado, version_caja, CHAR_LENGTH(observacion_cierre) AS char_len, observacion_cierre FROM sesion_caja WHERE id_sesion_caja = {$idSesion3}")->fetch();
    $rowArq = $pdoTest->query("SELECT estado, version_caja_evaluada FROM arqueo_caja WHERE id_arqueo_caja = {$idArqS3_v5_500}")->fetch();

    if ($rowS3['estado'] !== 'CERRADA'
        || (int)$rowS3['version_caja'] !== 6
        || (int)$rowS3['char_len'] !== 500
        || $rowS3['observacion_cierre'] !== $obs500Unicode
        || $rowArq['estado'] !== 'VIGENTE'
        || (int)$rowArq['version_caja_evaluada'] !== 5
    ) {
        throw new RuntimeException('Fallo en verificación de cierre v5->v6 o en copia íntegra de 500 caracteres Unicode.');
    }

    return "Sesión #{$idSesion3} cerrada en version_caja=6; arqueo #{$idArqS3_v5_500} permanece VIGENTE en version_caja_evaluada=5; observacion_cierre copiada íntegra (CHAR_LENGTH=500)";
});

// --- I. SNAPSHOT DE REPORTE DE CIERRES DE CAJA ---
$idSnapshotCaja = 0;

$expectSuccess('I1_SNAPSHOT_CIERRES_CAJA_VALIDO', 'Crear cabecera en reporte_consulta_snapshot (tipo_reporte = cierres_caja) e insertar ítem congelado en reporte_caja_snapshot_item', function () use ($pdoTest, &$idSnapshotCaja, &$idSesion1): string {
    $pdoTest->exec(
        "INSERT INTO reporte_consulta_snapshot (
            referencia_consulta, id_usuario, tipo_reporte, hash_filtros,
            filtros_json, resumen_json, total_registros, exportable, expira_en
        ) VALUES (
            'snap_test_sprint3_cierres_0001', 1, 'cierres_caja', REPEAT('A', 64),
            '{\"fecha_desde\":\"2026-11-01\",\"fecha_hasta\":\"2026-11-12\"}',
            '{\"sesiones_cerradas_count\":1}', 1, 1, DATE_ADD(NOW(), INTERVAL 1 HOUR)
        )"
    );
    $idSnapshotCaja = (int)$pdoTest->lastInsertId();

    $pdoTest->exec(
        "INSERT INTO reporte_caja_snapshot_item (
            id_snapshot, orden_posicion, id_sesion_caja, proyeccion_json
        ) VALUES (
            {$idSnapshotCaja}, 1, {$idSesion1},
            '{\"session_id\":{$idSesion1},\"expected_cash_amount\":\"155.00\",\"counted_cash_amount\":\"145.00\",\"closing_difference_amount\":\"-10.00\"}'
        )"
    );
    return "idSnapshotCaja={$idSnapshotCaja} con ítem para sesión #{$idSesion1}";
});

$expectFailure('I2_SNAPSHOT_CAJA_JSON_INVALIDO', 'Rechazar ítem en reporte_caja_snapshot_item con proyeccion_json no válido', function () use ($pdoTest, &$idSnapshotCaja, &$idSesion2): void {
    $pdoTest->exec(
        "INSERT INTO reporte_caja_snapshot_item (
            id_snapshot, orden_posicion, id_sesion_caja, proyeccion_json
        ) VALUES ({$idSnapshotCaja}, 2, {$idSesion2}, 'JSON_CORRUPTO{{{')"
    );
}, 'chk_reporte_caja_item_orden_y_json');

// Limpieza de los datos sintéticos creados en las tablas nuevas del Sprint 3 para dejar la base de ensayo en estado limpio post-migración
cleanSyntheticSprint3Rows($pdoTest, $testDb);

// Verificar que después de limpiar las filas sintéticas quedan exactamente los mismos 34 triggers y definiciones
$triggersFinalSnapshot = getTriggersSnapshot($pdoTest, $testDb);
$triggersIdentical = (
    $triggersInitialSnapshot['count'] === 34
    && $triggersFinalSnapshot['count'] === 34
    && $triggersInitialSnapshot['sha256'] === $triggersFinalSnapshot['sha256']
);

$recordCase(
    'TRIGGERS_34_DEFINICIONES_INTACTAS',
    'POSITIVA',
    'Comprobar que tras la limpieza de datos sintéticos permanecen exactamente los mismos 34 triggers y definiciones (SHA-256 idéntico)',
    $triggersIdentical,
    "count_initial={$triggersInitialSnapshot['count']}, count_final={$triggersFinalSnapshot['count']}, sha256={$triggersFinalSnapshot['sha256']}"
);

// --- CORRECCIÓN 4 Y CORRECCIÓN 5: CONTROL DE EJECUCIÓN, GET_LOCK, DETENCIÓN EN PRIMER ERROR Y PASO_ACTUAL ---
$runnerScript = str_replace('\\', '/', __DIR__ . '/ejecutar_migracion_sprint3.php');
$sprintDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$phpExe = 'C:/xampp/php/php.exe';
$mysqlExe = 'C:/xampp/mysql/bin/mysql.exe';

// 8-D: Re-ejecución sobre la base ya COMPLETADA -> rechazada con SPRINT3_YA_APLICADA
$outReexec = [];
$exitReexec = 0;
exec(sprintf('"%s" "%s" "%s" 2>&1', $phpExe, $runnerScript, $testDb), $outReexec, $exitReexec);
$msgReexec = trim(implode(' | ', $outReexec));
$recordCase(
    'CORR5_D_REEJECUCION_POST_COMPLETADA_RECHAZADA',
    'NEGATIVA',
    'Corrección 5-D: Rechazar re-ejecución de migración sobre una base con estado COMPLETADA (SPRINT3_YA_APLICADA)',
    ($exitReexec !== 0 && stripos($msgReexec, 'SPRINT3_YA_APLICADA') !== false),
    "exit={$exitReexec}; {$msgReexec}"
);

// 7-A / Protección contra amazon_market en el ejecutor
$outProd = [];
$exitProd = 0;
exec(sprintf('"%s" "%s" amazon_market 2>&1', $phpExe, $runnerScript), $outProd, $exitProd);
$msgProd = trim(implode(' | ', $outProd));
$recordCase(
    'CORR4_PROTECCION_AMAZON_MARKET_RECHAZADA',
    'NEGATIVA',
    'Corrección 4: El ejecutor rechaza explícitamente apuntar a amazon_market (PROTECCION_ACTIVA)',
    ($exitProd !== 0 && stripos($msgProd, 'PROTECCION_ACTIVA') !== false),
    "exit={$exitProd}; {$msgProd}"
);

if ($failSimDb !== '' && strpos($failSimDb, 'amazon_market_sprint3_fail_sim_') === 0) {
    $pdoFailSim = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$failSimDb};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Limpiar cualquier objeto residual de una corrida previa de simulación en $failSimDb
    foreach ([
        'trg_prog_detalle_before_delete_prohibido',
        'trg_prog_detalle_before_insert_integridad',
        'trg_programacion_semanal_before_delete_prohibido',
        'trg_programacion_semanal_before_update_integridad',
        'trg_programacion_semanal_before_insert_integridad',
        'trg_turno_before_update_integridad',
        'trg_turno_before_delete_prohibido',
    ] as $trgDrop) {
        $pdoFailSim->exec("DROP TRIGGER IF EXISTS `{$trgDrop}`");
    }
    $pdoFailSim->exec("DROP TABLE IF EXISTS `programacion_semanal_detalle`, `programacion_semanal`, `turno`, `sprint3_migracion_control`");

    $runMysqlDriver = static function (string $db, array $lines) use ($mysqlExe): array {
        $tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/sprint3_verify_drv_' . getmypid() . '_' . uniqid('', true) . '.sql';
        file_put_contents($tmp, implode("\n", $lines) . "\n");
        $out = [];
        $code = 0;
        exec(sprintf('"%s" -u root -h 127.0.0.1 -P 3306 --default-character-set=utf8mb4 --abort-source-on-error "%s" -e "SOURCE %s" 2>&1', $mysqlExe, $db, $tmp), $out, $code);
        @unlink($tmp);
        return ['exit' => $code, 'msg' => trim(implode(' | ', $out))];
    };

    // 7-C: Intento sin @SPRINT3_BASE_CONFIRMADA
    $resNoConfirm = $runMysqlDriver($failSimDb, [
        "SOURCE {$sprintDir}/01_preflight_y_control_sprint3.sql",
    ]);
    $recordCase(
        'CORR4_C_SIN_BASE_CONFIRMADA_RECHAZADO',
        'NEGATIVA',
        'Corrección 4-C: Rechazar ejecución cuando falta @SPRINT3_BASE_CONFIRMADA (BASE_DESTINO_NO_CONFIRMADA)',
        ($resNoConfirm['exit'] !== 0 && stripos($resNoConfirm['msg'], 'BASE_DESTINO_NO_CONFIRMADA') !== false),
        "exit={$resNoConfirm['exit']}; {$resNoConfirm['msg']}"
    );

    // 7-E: Ejecución concurrente bloqueada por GET_LOCK activo en otra conexión
    $lockNameSim = 'am_sprint3_mig_' . md5($failSimDb);
    $gotLockSim = (int)$pdoFailSim->query("SELECT GET_LOCK('{$lockNameSim}', 0)")->fetchColumn();
    $outConc = [];
    $exitConc = 0;
    exec(sprintf('"%s" "%s" "%s" 2>&1', $phpExe, $runnerScript, $failSimDb), $outConc, $exitConc);
    $pdoFailSim->query("SELECT RELEASE_LOCK('{$lockNameSim}')");
    $msgConc = trim(implode(' | ', $outConc));
    $recordCase(
        'CORR4_E_CONCURRENCIA_SIMULTANEA_RECHAZADA',
        'NEGATIVA',
        'Corrección 4-E: Dos ejecuciones concurrentes no pueden correr simultáneamente sobre la misma base (MIGRACION_EN_CURSO)',
        ($gotLockSim === 1 && $exitConc !== 0 && stripos($msgConc, 'MIGRACION_EN_CURSO') !== false),
        "conn1_lock={$gotLockSim}, conn2_exit={$exitConc}; {$msgConc}"
    );

    // 7-B y 8-A: Fallo controlado antes de terminar triggers del Paso 02 -> detiene runner en seco sin ejecutar pasos 03..06 y paso_actual permanece en 01_PREFLIGHT
    $outSimFail = [];
    $exitSimFail = 0;
    exec(sprintf('"%s" "%s" "%s" --simulate-fail-step2 2>&1', $phpExe, $runnerScript, $failSimDb), $outSimFail, $exitSimFail);
    $ctrlSim = $pdoFailSim->query("SELECT estado, paso_actual FROM sprint3_migracion_control WHERE id_migracion = 3")->fetch();
    $triggersStep2Sim = (int)$pdoFailSim->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$failSimDb}' AND EVENT_OBJECT_TABLE IN ('turno', 'programacion_semanal', 'programacion_semanal_detalle')")->fetchColumn();
    $tablesStep3to5Sim = (int)$pdoFailSim->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$failSimDb}' AND TABLE_NAME IN ('sesion_caja', 'arqueo_caja', 'movimiento_caja', 'reporte_caja_snapshot_item')")->fetchColumn();

    $recordCase(
        'CORR4_B_Y_CORR5_A_FALLO_INTERMEDIO_DETIENE_Y_NO_AVANZA_PASO',
        'NEGATIVA',
        'Corrección 4-B y 5-A: Fallo antes de terminar triggers del Paso 02 detiene inmediatamente el ejecutor sin correr pasos 03..06 y mantiene paso_actual = 01_PREFLIGHT',
        (
            $exitSimFail !== 0
            && ($ctrlSim['estado'] ?? '') === 'EN_PROGRESO'
            && ($ctrlSim['paso_actual'] ?? '') === '01_PREFLIGHT'
            && $triggersStep2Sim === 6
            && $tablesStep3to5Sim === 0
        ),
        "exit={$exitSimFail}, estado={$ctrlSim['estado']}, paso_actual={$ctrlSim['paso_actual']}, triggers_paso02={$triggersStep2Sim}/7, tablas_pasos_03_a_05={$tablesStep3to5Sim}"
    );

    // 8-B: Intentar ejecutar el siguiente script (Paso 03) tras el fallo del Paso 02 -> rechazado con ORDEN_MIGRACION_INVALIDO
    $resStep3AfterFail = $runMysqlDriver($failSimDb, [
        "SET @SPRINT3_BASE_CONFIRMADA = '{$failSimDb}';",
        "SOURCE {$sprintDir}/03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql",
    ]);
    $recordCase(
        'CORR5_B_SIGUIENTE_SCRIPT_TRAS_FALLO_RECHAZADO',
        'NEGATIVA',
        'Corrección 5-B: Rechazar intento de ejecutar el Paso 03 después de que el Paso 02 falló antes de completar sus triggers (ORDEN_MIGRACION_INVALIDO)',
        ($resStep3AfterFail['exit'] !== 0 && stripos($resStep3AfterFail['msg'], 'ORDEN_MIGRACION_INVALIDO') !== false),
        "exit={$resStep3AfterFail['exit']}; {$resStep3AfterFail['msg']}"
    );

    // 7-D: Intentar ejecutar el Paso 02 desde una conexión separada que no posee el GET_LOCK activo -> rechazado con LOCK_MIGRACION_NO_VIGENTE
    $resNoLock = $runMysqlDriver($failSimDb, [
        "SET @SPRINT3_BASE_CONFIRMADA = '{$failSimDb}';",
        "SOURCE {$sprintDir}/02_estructura_turnos_y_programacion_sprint3.sql",
    ]);
    $recordCase(
        'CORR4_D_PASO_SIN_GET_LOCK_VIGENTE_RECHAZADO',
        'NEGATIVA',
        'Corrección 4-D: Rechazar ejecución de script intermedio (Paso 02) en una conexión que no posee el GET_LOCK activo del Paso 01 (LOCK_MIGRACION_NO_VIGENTE)',
        ($resNoLock['exit'] !== 0 && stripos($resNoLock['msg'], 'LOCK_MIGRACION_NO_VIGENTE') !== false),
        "exit={$resNoLock['exit']}; {$resNoLock['msg']}"
    );
}

$allCasesPassed = true;
foreach ($caseResults as $c) {
    if (!$c['passed']) {
        $allCasesPassed = false;
    }
}

$output = [
    'test_database' => $testDb,
    'conservation_all_passed' => $allConservationPassed,
    'triggers_before_after_identical' => $triggersIdentical,
    'triggers_count' => $triggersFinalSnapshot['count'],
    'triggers_sha256' => $triggersFinalSnapshot['sha256'],
    'ventas_with_null_sesion' => $ventasNullSesion,
    'stock_sum_orig' => $stockSumOrig,
    'stock_sum_test' => $stockSumTest,
    'series_orig' => $seriesOrig,
    'series_test' => $seriesTest,
    'domain_cases_all_passed' => $allCasesPassed,
    'domain_cases_count' => count($caseResults),
    'domain_cases' => $caseResults,
    'table_comparisons' => $tableComparisons,
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($allConservationPassed && $allCasesPassed && $triggersIdentical) ? 0 : 1);
