<?php
declare(strict_types=1);

/** Regresión HU 18: solo las asignaciones efectivas actuales/futuras impiden la baja.
 * Cada escenario inserta exclusivamente fixtures nuevos y termina en ROLLBACK.
 * No elimina filas, triggers ni bases; los AUTO_INCREMENT nuevos pueden avanzar.
 */
if (PHP_SAPI !== 'cli') {
    exit(2);
}
$options = getopt('', ['test-db:']);
$db = (string)($options['test-db'] ?? '');
if (!preg_match('/^amazon_market_sprint3_(?:migration_test_|deploy_rehearsal_)[A-Za-z0-9_]+$/D', $db)) {
    fwrite(STDERR, "Indique una copia desechable mediante --test-db=amazon_market_sprint3_migration_test_<nombre>.\n");
    exit(2);
}
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$db};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET SESSION time_zone = '-05:00'");
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
$admin = (int)$pdo->query('SELECT id_usuario FROM usuario WHERE id_rol=1 AND estado=1 ORDER BY id_usuario LIMIT 1')->fetchColumn();
$seller = (int)$pdo->query('SELECT id_usuario FROM usuario WHERE id_rol=2 AND estado=1 ORDER BY id_usuario LIMIT 1')->fetchColumn();
if (!$admin || !$seller) {
    throw new RuntimeException('La copia requiere un administrador y un vendedor activos existentes.');
}
$monday = new DateTimeImmutable((string)$pdo->query('SELECT DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)')->fetchColumn());
$week = static fn(int $offset): string => $monday->modify(sprintf('%+d weeks', $offset))->format('Y-m-d');
$newTurn = static function (string $name) use ($pdo, $admin): int {
    $q = $pdo->prepare('INSERT INTO turno(nombre,hora_inicio,hora_fin,estado,id_usuario_creacion) VALUES(?,\'07:00:00\',\'12:00:00\',1,?)');
    $q->execute([$name, $admin]);
    return (int)$pdo->lastInsertId();
};
$schedule = static function (int $turn, int $offset, string $scope = 'desde') use ($pdo, $seller, $admin, $week): int {
    $q = $pdo->prepare('INSERT INTO programacion_semanal(id_usuario_vendedor,semana_inicio,alcance,version_programacion,es_vigente,id_usuario_autor) VALUES(?,?,?,1,1,?)');
    $q->execute([$seller, $week($offset), $scope, $admin]);
    $id = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO programacion_semanal_detalle(id_programacion,id_turno,dia_semana) VALUES({$id},{$turn},0)");
    return $id;
};
$historyHash = static function () use ($pdo, $seller): string {
    $headers = $pdo->query("SELECT * FROM programacion_semanal WHERE id_usuario_vendedor={$seller} ORDER BY id_programacion")->fetchAll();
    $details = $pdo->query("SELECT d.* FROM programacion_semanal_detalle d JOIN programacion_semanal p ON p.id_programacion=d.id_programacion WHERE p.id_usuario_vendedor={$seller} ORDER BY d.id_programacion_detalle")->fetchAll();
    return hash('sha256', json_encode([$headers, $details], JSON_THROW_ON_ERROR));
};
$cases = [
    // Bases en distintos lunes siguen disponibles para resolver las semanas pasadas.
    'HU18_BASE_SOLO_HISTORICA' => [true, '', static function ($a, $b) use ($schedule): void { $schedule($a, -2); $schedule($b, 0); }],
    'HU18_BASE_ACTUAL' => [false, 'SHIFT_HAS_ASSIGNED_SELLERS', static function ($a, $b) use ($schedule): void { $schedule($a, 0); }],
    'HU18_BASE_FUTURA' => [false, 'SHIFT_HAS_ASSIGNED_SELLERS', static function ($a, $b) use ($schedule): void { $schedule($b, 0); $schedule($a, 2); }],
    'HU18_EXCEPCION_FUTURA_CONSERVADA' => [false, 'SHIFT_HAS_ASSIGNED_SELLERS', static function ($a, $b) use ($schedule): void { $schedule($a, -2); $schedule($a, 2, 'semana'); $schedule($b, 0); }],
    'HU18_EXCEPCION_PASADA' => [true, '', static function ($a, $b) use ($schedule): void { $schedule($b, -2); $schedule($a, -1, 'semana'); }],
    'HU18_BASE_PASADA_APLICABLE_HOY' => [false, 'SHIFT_HAS_ASSIGNED_SELLERS', static function ($a, $b) use ($schedule): void { $schedule($a, -2); $schedule($b, 1); }],
    'HU18_INTERVALO_CUBIERTO_POR_EXCEPCIONES' => [true, '', static function ($a, $b) use ($schedule): void { $schedule($a, -2); $schedule($b, 2); $schedule($b, 0, 'semana'); $schedule($b, 1, 'semana'); }],
    'HU18_INTERVALO_CON_SEMANA_SIN_EXCEPCION' => [false, 'SHIFT_HAS_ASSIGNED_SELLERS', static function ($a, $b) use ($schedule): void { $schedule($a, -2); $schedule($b, 2); $schedule($b, 0, 'semana'); }],
    'HU18_ULTIMA_BASE_VUELVE_TRAS_EXCEPCION' => [false, 'SHIFT_HAS_ASSIGNED_SELLERS', static function ($a, $b) use ($schedule): void { $schedule($a, -2); $schedule($b, 0, 'semana'); $schedule($b, 1, 'semana'); }],
    'HU18_EXCEPCION_ACTUAL' => [false, 'SHIFT_HAS_ASSIGNED_SELLERS', static function ($a, $b) use ($schedule): void { $schedule($b, -2); $schedule($a, 0, 'semana'); }],
];
// La sesión activa sigue bloqueando la baja aunque el turno no tenga programación.
$cases['HU18_SESION_ABIERTA'] = [false, 'SHIFT_IN_USE', static function ($a, $b) use ($pdo, $seller, $week): void {
    $prev = $pdo->query("SELECT id_sesion_caja,efectivo_contado_cierre,fecha_hora_cierre,nombre_turno_referencia FROM sesion_caja WHERE estado='CERRADA' ORDER BY id_sesion_caja DESC LIMIT 1")->fetch();
    $q = $pdo->prepare('INSERT INTO sesion_caja(id_caja_fisica,id_usuario_vendedor,id_turno,nombre_turno_referencia,hora_inicio_referencia,hora_fin_referencia,fecha_hora_salida_referencia,tiene_cierre_anterior,id_sesion_cierre_anterior,monto_referencia_cierre_anterior,fecha_hora_cierre_anterior_ref,nombre_turno_cierre_anterior_ref,saldo_inicial,diferencia_apertura,efectivo_esperado_actual) VALUES(1,?,?,?,\'07:00:00\',\'12:00:00\',?,?,?,?,?,?,0,?,0)');
    $q->execute([$seller, $a, 'HU18 sesión abierta', $week(0).' 12:00:00', $prev ? 1 : 0, $prev['id_sesion_caja'] ?? null, $prev['efectivo_contado_cierre'] ?? null, $prev['fecha_hora_cierre'] ?? null, $prev['nombre_turno_referencia'] ?? null, $prev ? -((float)$prev['efectivo_contado_cierre']) : null]);
}];
$results = [];
foreach ($cases as $name => [$allowed, $expectedError, $setup]) {
    $pdo->beginTransaction();
    try {
        // Evita mezclar fixtures con programación previa de este vendedor.
        if ((int)$pdo->query("SELECT COUNT(*) FROM programacion_semanal WHERE id_usuario_vendedor={$seller}")->fetchColumn() !== 0
            || (int)$pdo->query("SELECT COUNT(*) FROM sesion_caja WHERE estado='ABIERTA'")->fetchColumn() !== 0) {
            throw new RuntimeException('Use una copia nueva sin programación de prueba ni sesión ABIERTA. No se limpia información existente.');
        }
        $a = $newTurn($name.' A');
        $b = $newTurn($name.' B');
        $setup($a, $b);
        $before = $historyHash();
        $error = null;
        try {
            $pdo->exec("UPDATE turno SET estado=0 WHERE id_turno={$a}");
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
        $state = (int)$pdo->query("SELECT estado FROM turno WHERE id_turno={$a}")->fetchColumn();
        $passed = $allowed ? ($error === null && $state === 0) : ($error !== null && str_contains($error, $expectedError) && $state === 1);
        $preserved = $before === $historyHash();
        $results[] = ['case' => $name, 'passed' => $passed && $preserved, 'history_preserved' => $preserved, 'error' => $error];
    } catch (Throwable $e) {
        $results[] = ['case' => $name, 'passed' => false, 'setup_error' => $e->getMessage()];
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
$failed = array_values(array_filter($results, static fn($r) => !$r['passed']));
echo json_encode(['test_database' => $db, 'cases' => count($results), 'passed' => count($results)-count($failed), 'failed' => count($failed), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($failed ? 1 : 0);
