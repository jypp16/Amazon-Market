<?php
declare(strict_types=1);

/**
 * Batería integral de aceptación para el Paso 7 del Sprint 3 (HU 11: EP-07, EP-08, EP-09).
 * Ejecuta pruebas reales HTTP, persistencia en MariaDB, idempotencia, suplencias,
 * fixtures de cierre respetando triggers, concurrencia multi-proceso y conservación.
 */

$dbName = getenv('TEST_DB_NAME') ?: '';
$protectedDbs = [
    'amazon_market',
    'amazon_market_restore_verify_20261027_113040',
    'amazon_market_sprint2_test',
    'amazon_market_sprint3_verify_20261112_012312',
    'amazon_market_sprint3_verify_20261112_012430',
];
if ($dbName === '' || in_array($dbName, $protectedDbs, true)) {
    fwrite(STDERR, "ERROR: Debe especificar TEST_DB_NAME apuntando a una copia aislada nueva (actual: '{$dbName}').\n");
    exit(2);
}

$httpPort = (int)(getenv('TEST_HTTP_PORT') ?: 8094);
$phpBin = 'C:\\xampp\\php\\php.exe';
$rootDir = dirname(__DIR__, 2);

putenv("DB_NAME={$dbName}");
$_ENV['DB_NAME'] = $dbName;
$_SERVER['DB_NAME'] = $dbName;

require_once $rootDir . '/Libraries/Core/Dotenv.php';
\Dotenv::load($rootDir . '/.env');
require_once $rootDir . '/Config/Config.php';
require_once $rootDir . '/Libraries/Core/Autoload.php';

$passed = 0;
$failed = 0;
$categories = [];

function assertTrue(bool $cond, string $cat, string $name, string $detail = ''): void
{
    global $passed, $failed, $categories;
    if (!isset($categories[$cat])) {
        $categories[$cat] = ['passed' => 0, 'failed' => 0];
    }
    if ($cond) {
        $passed++;
        $categories[$cat]['passed']++;
        echo "[PASS] [{$cat}] {$name}\n";
    } else {
        $failed++;
        $categories[$cat]['failed']++;
        echo "[FAIL] [{$cat}] {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
    }
}

function assertSameVal($expected, $actual, string $cat, string $name): void
{
    $ok = ($expected === $actual);
    assertTrue($ok, $cat, $name, $ok ? '' : 'esperado=' . json_encode($expected) . ' actual=' . json_encode($actual));
}

function assertNullField(?array $arr, string $key, string $cat, string $name): void
{
    $exists = is_array($arr) && array_key_exists($key, $arr);
    $ok = $exists && $arr[$key] === null;
    assertTrue($ok, $cat, $name, $ok ? '' : ($exists ? "actual=" . json_encode($arr[$key]) : "clave '{$key}' ausente"));
}

function httpRequest(
    string $baseUrl,
    string $method,
    string $path,
    ?array $body = null,
    array $headers = [],
    ?string $cookieFile = null,
    ?string $rawBody = null
): array {
    $ch = curl_init($baseUrl . $path);
    $hdrs = [];
    foreach ($headers as $k => $v) {
        $hdrs[] = "{$k}: {$v}";
    }
    if ($body !== null && !isset($headers['Content-Type'])) {
        $hdrs[] = 'Content-Type: application/json';
    }

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if (!empty($hdrs)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    }
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($resp === false) {
        return ['http_code' => 0, 'raw' => '', 'json' => null, 'error' => $err];
    }

    $rawHeaders = substr((string)$resp, 0, $headerSize);
    $rawRespBody = substr((string)$resp, $headerSize);
    $json = json_decode($rawRespBody, true);

    return [
        'http_code'   => $httpCode,
        'raw_headers' => $rawHeaders,
        'raw'         => $rawRespBody,
        'json'        => is_array($json) ? $json : null,
    ];
}

function loginHttp(string $baseUrl, string $username, string $password, string $cookieFile): ?string
{
    $authPage = httpRequest($baseUrl, 'GET', '/Auth', null, [], $cookieFile);
    $csrfToken = '';
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i', (string)$authPage['raw'], $m)) {
        $csrfToken = $m[1];
    }
    if ($csrfToken === '') {
        return null;
    }

    $ch = curl_init($baseUrl . '/Auth/login');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username'   => $username,
        'password'   => $password,
        'csrf_token' => $csrfToken,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 302 || !str_contains((string)$resp, '/Home')) {
        return null;
    }

    return $csrfToken;
}

/**
 * Cierra una sesión abierta en la copia de ensayo respetando 100% los triggers de arqueo y cierre.
 */
function cerrarSesionFixture(PDO $pdo, int $idSesion, int $idVendedor, float $contadoCierre): void
{
    $stmtS = $pdo->prepare('SELECT efectivo_esperado_actual, version_caja, fecha_hora_salida_referencia FROM sesion_caja WHERE id_sesion_caja = :id');
    $stmtS->execute([':id' => $idSesion]);
    $sesion = $stmtS->fetch(PDO::FETCH_ASSOC);
    if (!$sesion) {
        throw new RuntimeException("Sesión {$idSesion} no encontrada para fixture de cierre.");
    }

    $esperado = round((float)$sesion['efectivo_esperado_actual'], 2);
    $contado = round($contadoCierre, 2);
    $diff = round($contado - $esperado, 2);
    $obs = ($diff !== 0.0) ? 'Fixture cierre con diferencia controlada.' : null;

    $stmtA = $pdo->prepare(
        "INSERT INTO arqueo_caja (id_sesion_caja, id_usuario_vendedor, version_caja_evaluada, monto_esperado, monto_contado, diferencia, observacion, estado, vigencia_activa)
         VALUES (:id_sesion, :id_vendedor, :version, :esperado, :contado, :diff, :obs, 'VIGENTE', 1)"
    );
    $stmtA->execute([
        ':id_sesion'   => $idSesion,
        ':id_vendedor' => $idVendedor,
        ':version'     => (int)$sesion['version_caja'],
        ':esperado'    => number_format($esperado, 2, '.', ''),
        ':contado'     => number_format($contado, 2, '.', ''),
        ':diff'        => number_format($diff, 2, '.', ''),
        ':obs'         => $obs,
    ]);
    $idArqueo = (int)$pdo->lastInsertId();

    $ahoraStr = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
    // Usar fecha_hora_cierre >= fecha_hora_salida_referencia para no requerir autorizacion_caja en cierre anticipado
    $cierreDt = new DateTimeImmutable((string)$sesion['fecha_hora_salida_referencia'], new DateTimeZone('America/Lima'));
    if ((new DateTimeImmutable($ahoraStr, new DateTimeZone('America/Lima'))) > $cierreDt) {
        $cierreStr = $ahoraStr;
    } else {
        $cierreStr = $cierreDt->format('Y-m-d H:i:s');
    }

    $pdo->prepare('UPDATE sesion_caja SET id_ultimo_arqueo = :id_arq WHERE id_sesion_caja = :id_sesion')
        ->execute([':id_arq' => $idArqueo, ':id_sesion' => $idSesion]);

    $stmtU = $pdo->prepare(
        "UPDATE sesion_caja
            SET id_arqueo_cierre = :id_arq,
                version_caja = version_caja + 1,
                estado = 'CERRADA',
                fecha_hora_cierre = :cierre,
                efectivo_esperado_cierre = :esperado,
                efectivo_contado_cierre = :contado,
                diferencia_cierre = :diff,
                observacion_cierre = :obs
          WHERE id_sesion_caja = :id_sesion"
    );
    $stmtU->execute([
        ':id_arq'    => $idArqueo,
        ':cierre'    => $cierreStr,
        ':esperado'  => number_format($esperado, 2, '.', ''),
        ':contado'   => number_format($contado, 2, '.', ''),
        ':diff'      => number_format($diff, 2, '.', ''),
        ':obs'       => $obs,
        ':id_sesion' => $idSesion,
    ]);
}

function runParallelWorkers(string $phpBin, string $workerPath, string $dbName, array $tasks): array
{
    $procs = [];
    $pipesList = [];
    $env = array_merge($_ENV, [
        'DB_NAME'    => $dbName,
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
        'PATH'       => getenv('PATH') ?: '',
        'TEMP'       => getenv('TEMP') ?: sys_get_temp_dir(),
        'TMP'        => getenv('TMP') ?: sys_get_temp_dir(),
    ]);

    foreach ($tasks as $idx => $task) {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$phpBin, $workerPath], $desc, $pipes, dirname($workerPath, 2), $env);
        if (!is_resource($proc)) {
            throw new RuntimeException("No se pudo lanzar worker {$idx}");
        }
        fwrite($pipes[0], json_encode($task, JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        $procs[$idx] = $proc;
        $pipesList[$idx] = $pipes;
    }

    $results = [];
    foreach ($procs as $idx => $proc) {
        $stdout = stream_get_contents($pipesList[$idx][1]);
        $stderr = stream_get_contents($pipesList[$idx][2]);
        fclose($pipesList[$idx][1]);
        fclose($pipesList[$idx][2]);
        $exitCode = proc_close($proc);
        $json = json_decode((string)$stdout, true);
        $results[$idx] = [
            'exit_code' => $exitCode,
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'json'      => is_array($json) ? $json : null,
        ];
    }

    return $results;
}

$tempDir = sys_get_temp_dir() . '/am_paso7_' . bin2hex(random_bytes(4));
mkdir($tempDir, 0777, true);
$sessionSaveDir = $tempDir . '/sessions';
mkdir($sessionSaveDir, 0777, true);

$serverProc = null;
$serverUrl = "http://127.0.0.1:{$httpPort}";
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$pdo->exec("SET SESSION time_zone = '-05:00'");

$pdoOrig = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=amazon_market;charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

try {
    // -------------------------------------------------------------------------
    // Iniciar servidor PHP aislado
    // -------------------------------------------------------------------------
    ob_implicit_flush(true);
    $routerPath = $rootDir . '/tests/sprint3/server_router_sprint3.php';
    $cmd = [
        $phpBin,
        '-d',
        'session.save_path=' . $sessionSaveDir,
        '-S',
        '127.0.0.1:' . $httpPort,
        '-t',
        $rootDir,
        $routerPath,
    ];
    $nullFile = (PHP_OS_FAMILY === 'Windows') ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $nullFile, 'w'],
        2 => ['file', $nullFile, 'w'],
    ];
    $serverEnv = array_merge($_ENV, [
        'DB_NAME'    => $dbName,
        'BASE_URL'   => $serverUrl,
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
        'PATH'       => getenv('PATH') ?: '',
        'TEMP'       => getenv('TEMP') ?: sys_get_temp_dir(),
        'TMP'        => getenv('TMP') ?: sys_get_temp_dir(),
    ]);
    $serverProc = proc_open($cmd, $descriptors, $serverPipes, $rootDir, $serverEnv);
    if (!is_resource($serverProc)) {
        throw new RuntimeException('No se pudo iniciar el servidor HTTP aislado.');
    }
    fclose($serverPipes[0]);

    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $probe = httpRequest($serverUrl, 'GET', '/__test_env_verify');
        if ($probe['http_code'] === 200 && ($probe['json']['effective_db'] ?? '') === $dbName) {
            $ready = true;
            break;
        }
        usleep(150000);
    }

    assertTrue($ready, 'PRE', 'PRE-01 Servidor HTTP aislado activo y apuntando a la copia Paso 7');
    $envInfo = httpRequest($serverUrl, 'GET', '/__test_env_verify');
    assertSameVal($dbName, $envInfo['json']['effective_db'] ?? null, 'PRE', 'PRE-02 Base efectiva verificada');
    assertSameVal('America/Lima', $envInfo['json']['php_timezone'] ?? null, 'PRE', 'PRE-03 Timezone PHP America/Lima');
    assertSameVal('-05:00', $envInfo['json']['db_timezone'] ?? null, 'PRE', 'PRE-04 Timezone MariaDB -05:00');

    // -------------------------------------------------------------------------
    // Preparar usuarios y turnos fixtures en la copia aislada
    // -------------------------------------------------------------------------
    $passPlain = 'TestPaso7!2026';
    $passHash = password_hash($passPlain, PASSWORD_BCRYPT);

    $stmtUsr = $pdo->prepare("INSERT INTO usuario (id_rol, nombre, username, dni, telefono, direccion, email, password_hash, estado) VALUES (:rol, :nom, :usr, :dni, :tel, :dir, :eml, :p, 1)");
    $stmtUsr->execute([':rol' => 1, ':nom' => 'Admin Paso7', ':usr' => 'admp7', ':dni' => '70000001', ':tel' => '900000001', ':dir' => 'Av Test 1', ':eml' => 'admp7@test.local', ':p' => $passHash]);
    $idAdmin = (int)$pdo->lastInsertId();

    $stmtUsr->execute([':rol' => 2, ':nom' => 'Vendedor A Paso7', ':usr' => 'vndp7a', ':dni' => '70000002', ':tel' => '900000002', ':dir' => 'Av Test 2', ':eml' => 'vndp7a@test.local', ':p' => $passHash]);
    $idVendA = (int)$pdo->lastInsertId();

    $stmtUsr->execute([':rol' => 2, ':nom' => 'Vendedor B Paso7', ':usr' => 'vndp7b', ':dni' => '70000003', ':tel' => '900000003', ':dir' => 'Av Test 3', ':eml' => 'vndp7b@test.local', ':p' => $passHash]);
    $idVendB = (int)$pdo->lastInsertId();

    $stmtUsr->execute([':rol' => 2, ':nom' => 'Vendedor Inactivo Paso7', ':usr' => 'vndp7inact', ':dni' => '70000004', ':tel' => '900000004', ':dir' => 'Av Test 4', ':eml' => 'vndp7inact@test.local', ':p' => $passHash]);
    $idVendInact = (int)$pdo->lastInsertId();

    $cookieAnon = $tempDir . '/c_anon.txt';
    $cookieAdmin = $tempDir . '/c_admin.txt';
    $cookieVendA = $tempDir . '/c_venda.txt';
    $cookieVendB = $tempDir . '/c_vendb.txt';
    $cookieInact = $tempDir . '/c_inact.txt';

    $csrfAdmin = loginHttp($serverUrl, 'admp7', $passPlain, $cookieAdmin);
    $csrfVendA = loginHttp($serverUrl, 'vndp7a', $passPlain, $cookieVendA);
    $csrfVendB = loginHttp($serverUrl, 'vndp7b', $passPlain, $cookieVendB);
    $csrfInact = loginHttp($serverUrl, 'vndp7inact', $passPlain, $cookieInact);

    // Desactivar vendedor inactivo después de iniciar sesión HTTP
    $pdo->prepare('UPDATE usuario SET estado = 0 WHERE id_usuario = :id')->execute([':id' => $idVendInact]);

    assertTrue($csrfAdmin !== null && $csrfVendA !== null && $csrfVendB !== null && $csrfInact !== null, 'PRE', 'PRE-05 Logins HTTP y tokens CSRF obtenidos');

    // Crear turnos vía API real con el Admin
    $ahora = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    $horaActual = $ahora->format('H:i');
    $diaHoy = (int)$ahora->format('N') - 1;
    $semanaActual = $ahora->modify('-' . $diaHoy . ' days')->format('Y-m-d');

    // Turno que garantiza estar dentro del horario actual (00:00 - 23:59)
    $rT1 = httpRequest($serverUrl, 'POST', '/api/turnos', [
        'referencia_solicitud' => 'p7_t_actual_' . bin2hex(random_bytes(8)),
        'nombre' => 'Jornada Completa P7',
        'inicio' => '00:00',
        'fin'    => '23:59',
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);
    $idTurnoActual = (int)($rT1['json']['data']['id'] ?? 0);

    // Turno adicional hoy (para probar mañana + tarde en el mismo día)
    $rT2 = httpRequest($serverUrl, 'POST', '/api/turnos', [
        'referencia_solicitud' => 'p7_t_tarde_' . bin2hex(random_bytes(8)),
        'nombre' => 'Tarde P7',
        'inicio' => '15:00',
        'fin'    => '22:00',
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);
    $idTurnoTarde = (int)($rT2['json']['data']['id'] ?? 0);

    // Turno garantizado fuera de horario (ya terminado si hora >= 01:30, o futuro si hora < 01:30)
    $yaTerminado = ($horaActual >= '01:30');
    $outStart = $yaTerminado ? '00:00' : '23:00';
    $outEnd   = $yaTerminado ? '01:00' : '23:30';
    $rT3 = httpRequest($serverUrl, 'POST', '/api/turnos', [
        'referencia_solicitud' => 'p7_t_fuera_' . bin2hex(random_bytes(8)),
        'nombre' => 'Turno Corto P7',
        'inicio' => $outStart,
        'fin'    => $outEnd,
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);
    $idTurnoFuera = (int)($rT3['json']['data']['id'] ?? 0);

    // Turno inactivo
    $rT4 = httpRequest($serverUrl, 'POST', '/api/turnos', [
        'referencia_solicitud' => 'p7_t_inact_' . bin2hex(random_bytes(8)),
        'nombre' => 'Turno Inactivo P7',
        'inicio' => '06:00',
        'fin'    => '12:00',
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);
    $idTurnoInactivo = (int)($rT4['json']['data']['id'] ?? 0);
    $rDelT4 = httpRequest($serverUrl, 'DELETE', "/api/turnos/{$idTurnoInactivo}", null, [
        'X-CSRF-Token'        => $csrfAdmin,
        'X-Request-Reference' => 'p7_t_baja_' . bin2hex(random_bytes(8)),
    ], $cookieAdmin);

    assertTrue($idTurnoActual > 0 && $idTurnoTarde > 0 && $idTurnoFuera > 0 && $idTurnoInactivo > 0 && $rDelT4['http_code'] === 200, 'PRE', 'PRE-06 Turnos fixtures creados vía API');

    // =========================================================================
    // A: SEGURIDAD, ROLES, PERMISOS, CSRF Y FORMATO
    // =========================================================================
    $r = httpRequest($serverUrl, 'GET', '/api/caja/turno-hoy', null, [], $cookieAnon);
    assertSameVal(401, $r['http_code'], 'A', 'A-01 EP-07 sin autenticación -> 401');
    assertSameVal('AUTH_REQUIRED', $r['json']['code'] ?? null, 'A', 'A-02 EP-07 código AUTH_REQUIRED');

    $r = httpRequest($serverUrl, 'GET', '/api/caja/estado', null, [], $cookieAnon);
    assertSameVal(401, $r['http_code'], 'A', 'A-03 EP-08 sin autenticación -> 401');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', ['referencia_solicitud' => 'p7_unauth_1234567890', 'shift_id' => $idTurnoActual, 'cash_counted' => 10], [], $cookieAnon);
    assertSameVal(401, $r['http_code'], 'A', 'A-04 EP-09 sin autenticación -> 401');

    // Vendedor inactivo
    $r = httpRequest($serverUrl, 'GET', '/api/caja/turno-hoy', null, [], $cookieInact);
    assertSameVal(401, $r['http_code'], 'A', 'A-05 Vendedor inactivo rechazado -> 401');

    // Administrador rechazado pese a bypass RBAC
    $r = httpRequest($serverUrl, 'GET', '/api/caja/turno-hoy', null, [], $cookieAdmin);
    assertSameVal(403, $r['http_code'], 'A', 'A-06 Admin en EP-07 -> 403');
    assertSameVal('FORBIDDEN', $r['json']['code'] ?? null, 'A', 'A-07 Admin en EP-07 código FORBIDDEN');

    $r = httpRequest($serverUrl, 'GET', '/api/caja/estado', null, [], $cookieAdmin);
    assertSameVal(403, $r['http_code'], 'A', 'A-08 Admin en EP-08 -> 403');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_admin_deny_12345678',
        'shift_id' => $idTurnoActual,
        'cash_counted' => 100,
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);
    assertSameVal(403, $r['http_code'], 'A', 'A-09 Admin en EP-09 -> 403');

    // Vendedor sin permiso turnos.abrir (temporal en copia aislada con finally)
    $stmtPerm = $pdo->prepare("SELECT id_permiso FROM permiso WHERE slug = 'turnos.abrir' LIMIT 1");
    $stmtPerm->execute();
    $idPermAbrir = (int)$stmtPerm->fetchColumn();
    $pdo->prepare('DELETE FROM rol_permiso WHERE id_rol = 2 AND id_permiso = :p')->execute([':p' => $idPermAbrir]);
    try {
        $r = httpRequest($serverUrl, 'GET', '/api/caja/turno-hoy', null, [], $cookieVendA);
        assertSameVal(403, $r['http_code'], 'A', 'A-10 Vendedor sin turnos.abrir en EP-07 -> 403');
        $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
            'referencia_solicitud' => 'p7_noperm_1234567890',
            'shift_id' => $idTurnoActual,
            'cash_counted' => 10,
        ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
        assertSameVal(403, $r['http_code'], 'A', 'A-11 Vendedor sin turnos.abrir en EP-09 -> 403');
    } finally {
        $pdo->prepare('INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (2, :p)')->execute([':p' => $idPermAbrir]);
    }

    // vendedor_id ajeno en EP-07 y EP-08
    $r = httpRequest($serverUrl, 'GET', "/api/caja/turno-hoy?vendedor_id={$idVendB}", null, [], $cookieVendA);
    assertSameVal(403, $r['http_code'], 'A', 'A-12 EP-07 con vendedor_id ajeno -> 403 FORBIDDEN');
    $r = httpRequest($serverUrl, 'GET', "/api/caja/estado?vendedor_id={$idVendB}", null, [], $cookieVendA);
    assertSameVal(403, $r['http_code'], 'A', 'A-13 EP-08 con vendedor_id ajeno -> 403 FORBIDDEN');
    $r = httpRequest($serverUrl, 'GET', "/api/caja/turno-hoy?vendedor_id={$idVendA}", null, [], $cookieVendA);
    assertSameVal(200, $r['http_code'], 'A', 'A-14 EP-07 con vendedor_id propio -> 200 OK');

    // CSRF ausente o inválido en POST
    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_nocsrf_1234567890',
        'shift_id' => $idTurnoActual,
        'cash_counted' => 10,
    ], [], $cookieVendA);
    assertSameVal(403, $r['http_code'], 'A', 'A-15 EP-09 sin CSRF -> 403');
    assertSameVal('CSRF_INVALID', $r['json']['code'] ?? null, 'A', 'A-16 EP-09 sin CSRF código CSRF_INVALID');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_badcsrf_123456789',
        'shift_id' => $idTurnoActual,
        'cash_counted' => 10,
    ], ['X-CSRF-Token' => 'invalid_token_xyz'], $cookieVendA);
    assertSameVal(403, $r['http_code'], 'A', 'A-17 EP-09 con CSRF inválido -> 403');

    // JSON mal formado, 415, 404, 405
    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrfVendA], $cookieVendA, '{malformed');
    assertSameVal(400, $r['http_code'], 'A', 'A-18 EP-09 JSON mal formado -> 400 INVALID_JSON');
    assertSameVal('INVALID_JSON', $r['json']['code'] ?? null, 'A', 'A-19 Código INVALID_JSON');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, ['Content-Type' => 'text/plain', 'X-CSRF-Token' => $csrfVendA], $cookieVendA, 'hola');
    assertSameVal(415, $r['http_code'], 'A', 'A-20 Content-Type inválido -> 415');

    $r = httpRequest($serverUrl, 'GET', '/api/caja/no-existe', null, [], $cookieVendA);
    assertSameVal(404, $r['http_code'], 'A', 'A-21 Subruta inexistente -> 404');

    $r = httpRequest($serverUrl, 'PUT', '/api/caja/abrir', ['shift_id' => 1], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal(405, $r['http_code'], 'A', 'A-22 Método PUT no permitido -> 405');

    // Campos prohibidos (credenciales admin / manipulación)
    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_badfields_1234567',
        'shift_id' => $idTurnoActual,
        'cash_counted' => 10,
        'admin_password' => '123456',
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal(422, $r['http_code'], 'A', 'A-23 Rechazo de admin_password en apertura -> 422');
    assertTrue(strpos($r['raw'], '"errors": {}') !== false || is_array($r['json']['errors'] ?? null), 'A', 'A-24 Serialización de errores presente');

    // =========================================================================
    // B: EP-07 (TURNOS Y PROGRAMACIÓN PROPIA DE HOY)
    // =========================================================================
    // Vendedor B sin programación
    $r = httpRequest($serverUrl, 'GET', '/api/caja/turno-hoy', null, [], $cookieVendB);
    assertSameVal(200, $r['http_code'], 'B', 'B-01 EP-07 vendedor sin programación -> 200');
    assertSameVal('no_shift', $r['json']['data']['status'] ?? null, 'B', 'B-02 status = no_shift');
    assertSameVal(false, $r['json']['data']['has_shift_today'] ?? null, 'B', 'B-03 has_shift_today = false');
    assertNullField($r['json']['data'] ?? null, 'shift', 'B', 'B-04 shift = null');
    assertSameVal([], $r['json']['data']['scheduled_shifts'] ?? null, 'B', 'B-05 scheduled_shifts = []');
    assertSameVal(false, $r['json']['data']['requires_authorization_to_open'] ?? null, 'B', 'B-06 requires_authorization_to_open = false');
    assertTrue(count($r['json']['data']['available_shifts'] ?? []) >= 3, 'B', 'B-07 available_shifts contiene catálogo activo');

    // Asignar base a Vendedor A: hoy tiene Turno Actual + Turno Tarde; otro día es descanso
    $rProg = httpRequest($serverUrl, 'PUT', '/api/programaciones', [
        'referencia_solicitud' => 'p7_prog_base_' . bin2hex(random_bytes(8)),
        'usuario_id' => $idVendA,
        'semana_inicio' => $semanaActual,
        'alcance' => 'desde',
        'turnos' => [
            ['turno_id' => $idTurnoActual, 'dias' => [$diaHoy]],
            ['turno_id' => $idTurnoTarde, 'dias' => [$diaHoy]],
        ],
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);
    assertSameVal(200, $rProg['http_code'], 'B', 'B-08 Programación base (2 turnos hoy) guardada');

    $r = httpRequest($serverUrl, 'GET', '/api/caja/turno-hoy', null, [], $cookieVendA);
    assertSameVal('within_hours', $r['json']['data']['status'] ?? null, 'B', 'B-09 status = within_hours');
    assertSameVal(true, $r['json']['data']['has_shift_today'] ?? null, 'B', 'B-10 has_shift_today = true');
    assertSameVal(2, count($r['json']['data']['scheduled_shifts'] ?? []), 'B', 'B-11 scheduled_shifts incluye 2 turnos el mismo día');
    assertSameVal($idTurnoActual, $r['json']['data']['recommended_shift_id'] ?? null, 'B', 'B-12 recommended_shift_id apunta al turno en horario');

    // Excepción semanal solo esta semana: cambiar hoy a Turno Corto (fuera de horario)
    $rExc = httpRequest($serverUrl, 'PUT', '/api/programaciones', [
        'referencia_solicitud' => 'p7_prog_exc_' . bin2hex(random_bytes(8)),
        'usuario_id' => $idVendA,
        'semana_inicio' => $semanaActual,
        'alcance' => 'semana',
        'turnos' => [
            ['turno_id' => $idTurnoFuera, 'dias' => [$diaHoy]],
        ],
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);
    assertSameVal(200, $rExc['http_code'], 'B', 'B-13 Excepción SOLO_ESTA_SEMANA aplicada');

    $r = httpRequest($serverUrl, 'GET', '/api/caja/turno-hoy', null, [], $cookieVendA);
    assertSameVal('outside_hours', $r['json']['data']['status'] ?? null, 'B', 'B-14 Excepción semanal efectiva -> status = outside_hours');
    assertSameVal($idTurnoFuera, $r['json']['data']['recommended_shift_id'] ?? null, 'B', 'B-15 recommended_shift_id refleja excepción semanal');

    // Excepción semanal a día de descanso hoy (solo otro día programado)
    $otroDia = ($diaHoy + 1) % 7;
    httpRequest($serverUrl, 'PUT', '/api/programaciones', [
        'referencia_solicitud' => 'p7_prog_desc_' . bin2hex(random_bytes(8)),
        'usuario_id' => $idVendA,
        'semana_inicio' => $semanaActual,
        'alcance' => 'semana',
        'turnos' => [
            ['turno_id' => $idTurnoActual, 'dias' => [$otroDia]],
        ],
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);
    $r = httpRequest($serverUrl, 'GET', '/api/caja/turno-hoy', null, [], $cookieVendA);
    assertSameVal('no_shift', $r['json']['data']['status'] ?? null, 'B', 'B-16 Día de descanso -> status = no_shift');

    // Restaurar excepción de hoy a Turno Actual para pruebas siguientes
    httpRequest($serverUrl, 'PUT', '/api/programaciones', [
        'referencia_solicitud' => 'p7_prog_rest_' . bin2hex(random_bytes(8)),
        'usuario_id' => $idVendA,
        'semana_inicio' => $semanaActual,
        'alcance' => 'semana',
        'turnos' => [
            ['turno_id' => $idTurnoActual, 'dias' => [$diaHoy]],
        ],
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);

    // =========================================================================
    // C: EP-08 (CASO A), VALIDACIONES EP-09, PRIMERA APERTURA CON 0.00 Y CASOS C/D
    // =========================================================================
    $rEstadoA = httpRequest($serverUrl, 'GET', '/api/caja/estado', null, [], $cookieVendA);
    assertSameVal(200, $rEstadoA['http_code'], 'C', 'C-01 EP-08 Caso A -> 200');
    assertSameVal(false, $rEstadoA['json']['data']['is_open'] ?? null, 'C', 'C-02 Caso A is_open = false');
    assertSameVal(false, $rEstadoA['json']['data']['has_previous_close'] ?? null, 'C', 'C-03 Caso A has_previous_close = false');
    assertNullField($rEstadoA['json']['data'] ?? null, 'reference_amount', 'C', 'C-04 Caso A reference_amount = null');
    assertNullField($rEstadoA['json']['data'] ?? null, 'reference_date', 'C', 'C-05 Caso A reference_date = null');
    assertNullField($rEstadoA['json']['data'] ?? null, 'reference_turn', 'C', 'C-06 Caso A reference_turn = null');

    // Validaciones de entrada EP-09
    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_neg_1234567890123',
        'shift_id' => $idTurnoActual,
        'cash_counted' => -5,
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal(422, $r['http_code'], 'C', 'C-07 Importe negativo -> 422');
    assertSameVal('INVALID_AMOUNT', $r['json']['code'] ?? null, 'C', 'C-08 Código INVALID_AMOUNT');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_dec_1234567890123',
        'shift_id' => $idTurnoActual,
        'cash_counted' => 10.123,
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal('INVALID_AMOUNT', $r['json']['code'] ?? null, 'C', 'C-09 Más de 2 decimales -> INVALID_AMOUNT');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_oor_1234567890123',
        'shift_id' => $idTurnoActual,
        'cash_counted' => '100000000.00',
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal('AMOUNT_OUT_OF_RANGE', $r['json']['code'] ?? null, 'C', 'C-10 Exceso de rango -> AMOUNT_OUT_OF_RANGE');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_badshift_123456789',
        'shift_id' => $idTurnoInactivo,
        'cash_counted' => 10,
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal('INVALID_SHIFT', $r['json']['code'] ?? null, 'C', 'C-11 Turno inactivo -> INVALID_SHIFT');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_noshift_123456789',
        'shift_id' => 999999,
        'cash_counted' => 10,
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal('INVALID_SHIFT', $r['json']['code'] ?? null, 'C', 'C-12 Turno inexistente -> INVALID_SHIFT');

    $r = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_alias_123456789012',
        'shift_id' => $idTurnoActual,
        'turno_id' => $idTurnoTarde,
        'cash_counted' => 10,
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal('AMBIGUOUS_ALIAS_VALUES', $r['json']['code'] ?? null, 'C', 'C-13 Alias shift_id/turno_id contradictorios -> AMBIGUOUS_ALIAS_VALUES');

    // Pruebas HTTP de precisión decimal (números JSON crudos y cadenas) sin persistencia ante rechazo
    $invalidDecimals = [
        'C-13b número JSON 1.00000000001'          => ['ref' => 'p7_decnum1_123456789', 'raw' => '1.00000000001'],
        'C-13c número JSON 0.00000000001'          => ['ref' => 'p7_decnum2_123456789', 'raw' => '0.00000000001'],
        'C-13d número JSON 1.0000000000000000001'  => ['ref' => 'p7_decnum3_123456789', 'raw' => '1.0000000000000000001'],
        'C-13e número JSON 1.001'                  => ['ref' => 'p7_decnum4_123456789', 'raw' => '1.001'],
        'C-13f cadena "1.00000000001"'             => ['ref' => 'p7_decstr1_123456789', 'raw' => '"1.00000000001"'],
        'C-13g cadena "0.00000000001"'             => ['ref' => 'p7_decstr2_123456789', 'raw' => '"0.00000000001"'],
        'C-13h cadena "1.001"'                     => ['ref' => 'p7_decstr3_123456789', 'raw' => '"1.001"'],
    ];
    foreach ($invalidDecimals as $label => $cfg) {
        $rawJson = '{"referencia_solicitud":"' . $cfg['ref'] . '","shift_id":' . $idTurnoActual . ',"cash_counted":' . $cfg['raw'] . '}';
        $rDec = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $csrfVendA,
        ], $cookieVendA, $rawJson);
        assertSameVal(422, $rDec['http_code'], 'C', "{$label} -> HTTP 422");
        assertSameVal('INVALID_AMOUNT', $rDec['json']['code'] ?? null, 'C', "{$label} -> INVALID_AMOUNT");

        $stmtCheckRef = $pdo->prepare('SELECT COUNT(*) FROM control_solicitud_idempotencia WHERE referencia_solicitud = :r');
        $stmtCheckRef->execute([':r' => $cfg['ref']]);
        assertSameVal(0, (int)$stmtCheckRef->fetchColumn(), 'C', "{$label} no ocupa referencia idempotente");
    }
    assertSameVal(0, (int)$pdo->query('SELECT COUNT(*) FROM sesion_caja')->fetchColumn(), 'C', 'C-13i Rechazos decimales no crean sesion_caja');
    assertSameVal(null, $pdo->query('SELECT id_sesion_abierta_actual FROM caja_fisica_guardia WHERE id_caja_fisica = 1')->fetchColumn(), 'C', 'C-13j Guardia permanece NULL tras rechazos decimales');

    // Pruebas HTTP de claves repetidas cash_counted en el objeto raíz (JSON crudo)
    $refDupReuse = 'p7_dupreuse_' . bin2hex(random_bytes(8));
    $duplicateCases = [
        'C-13k número + número diferente'          => ['ref' => $refDupReuse, 'pair' => '"cash_counted":1,"cash_counted":999'],
        'C-13l número + cadena'                    => ['ref' => 'p7_dup2_123456789012', 'pair' => '"cash_counted":1,"cash_counted":"1.0000000000000000001"'],
        'C-13m cadena + número muchos decimales'   => ['ref' => 'p7_dup3_123456789012', 'pair' => '"cash_counted":"1","cash_counted":1.0000000000000000001'],
        'C-13n dos valores iguales'                => ['ref' => 'p7_dup4_123456789012', 'pair' => '"cash_counted":10,"cash_counted":10'],
        'C-13o literal + escape Unicode'           => ['ref' => 'p7_dup5_123456789012', 'pair' => '"cash_counted":1,"cash\u005fcounted":999'],
        'C-13p escape Unicode + literal'           => ['ref' => 'p7_dup6_123456789012', 'pair' => '"cash\u005fcounted":1,"cash_counted":999'],
    ];
    foreach ($duplicateCases as $label => $cfg) {
        $rawDupJson = '{"referencia_solicitud":"' . $cfg['ref'] . '","shift_id":' . $idTurnoActual . ',' . $cfg['pair'] . '}';
        $rDup = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $csrfVendA,
        ], $cookieVendA, $rawDupJson);
        assertSameVal(422, $rDup['http_code'], 'C', "{$label} -> HTTP 422");
        assertSameVal('VALIDATION_ERROR', $rDup['json']['code'] ?? null, 'C', "{$label} -> VALIDATION_ERROR");
        assertTrue(!empty($rDup['json']['errors']['cash_counted'] ?? []), 'C', "{$label} -> errors.cash_counted presente");

        $stmtCheckRef = $pdo->prepare('SELECT COUNT(*) FROM control_solicitud_idempotencia WHERE referencia_solicitud = :r');
        $stmtCheckRef->execute([':r' => $cfg['ref']]);
        assertSameVal(0, (int)$stmtCheckRef->fetchColumn(), 'C', "{$label} no ocupa referencia idempotente");
    }
    assertSameVal(0, (int)$pdo->query('SELECT COUNT(*) FROM sesion_caja')->fetchColumn(), 'C', 'C-13q Claves repetidas no crean sesion_caja');
    assertSameVal(null, $pdo->query('SELECT id_sesion_abierta_actual FROM caja_fisica_guardia WHERE id_caja_fisica = 1')->fetchColumn(), 'C', 'C-13r Guardia permanece NULL tras claves repetidas');

    // Primera apertura histórica con cash_counted = 0.00 reutilizando la referencia de C-13k
    $refOpen1 = $refDupReuse;
    $rOpen1 = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'turno_id'     => $idTurnoActual,
        'cash_counted' => 0,
    ], ['X-CSRF-Token' => $csrfVendA, 'X-Request-Reference' => $refOpen1], $cookieVendA);

    assertSameVal(201, $rOpen1['http_code'], 'C', 'C-14 Primera apertura con 0.00 -> 201');
    assertSameVal('OPENED', $rOpen1['json']['code'] ?? null, 'C', 'C-15 code = OPENED');
    assertSameVal(false, $rOpen1['json']['idempotent_replay'] ?? null, 'C', 'C-16 idempotent_replay = false');
    assertSameVal(0, (int)($rOpen1['json']['data']['opening_balance'] ?? -1), 'C', 'C-17 opening_balance = 0');
    assertNullField($rOpen1['json']['data'] ?? null, 'opening_difference', 'C', 'C-18 opening_difference = null');
    assertNullField($rOpen1['json']['data'] ?? null, 'reference_amount', 'C', 'C-19 reference_amount = null');
    assertSameVal(false, $rOpen1['json']['data']['has_previous_close'] ?? null, 'C', 'C-20 has_previous_close = false');
    assertSameVal(false, $rOpen1['json']['data']['is_substitution'] ?? null, 'C', 'C-21 is_substitution = false');
    assertSameVal(false, $rOpen1['json']['data']['opened_outside_schedule'] ?? null, 'C', 'C-22 opened_outside_schedule = false');
    assertTrue(str_ends_with((string)($rOpen1['json']['data']['opened_at'] ?? ''), '-05:00'), 'C', 'C-23 opened_at con offset -05:00');
    $idSesion1 = (int)($rOpen1['json']['data']['session_id'] ?? 0);
    assertTrue($idSesion1 > 0, 'C', 'C-24 session_id > 0');

    // Caso C EP-08: propio vendedor
    $rEstadoC = httpRequest($serverUrl, 'GET', '/api/caja/estado', null, [], $cookieVendA);
    assertSameVal(true, $rEstadoC['json']['data']['is_open'] ?? null, 'C', 'C-25 EP-08 Caso C is_open = true');
    assertSameVal(true, $rEstadoC['json']['data']['is_owner'] ?? null, 'C', 'C-26 EP-08 Caso C is_owner = true');
    assertSameVal($idSesion1, $rEstadoC['json']['data']['session_id'] ?? null, 'C', 'C-27 EP-08 Caso C session_id coincide');
    assertTrue(array_key_exists('counted_amount', $rEstadoC['json']['data'] ?? []) && (float)$rEstadoC['json']['data']['counted_amount'] === 0.0, 'C', 'C-28 EP-08 Caso C expone counted_amount propio');

    // Caso D EP-08: otro vendedor (privacidad financiera)
    $rEstadoD = httpRequest($serverUrl, 'GET', '/api/caja/estado', null, [], $cookieVendB);
    assertSameVal(true, $rEstadoD['json']['data']['is_open'] ?? null, 'C', 'C-29 EP-08 Caso D is_open = true');
    assertSameVal(false, $rEstadoD['json']['data']['is_owner'] ?? null, 'C', 'C-30 EP-08 Caso D is_owner = false');
    assertNullField($rEstadoD['json']['data'] ?? null, 'counted_amount', 'C', 'C-31 EP-08 Caso D oculta counted_amount');
    assertNullField($rEstadoD['json']['data'] ?? null, 'reference_amount', 'C', 'C-32 EP-08 Caso D oculta reference_amount');
    assertSameVal('Vendedor A Paso7', $rEstadoD['json']['data']['occupied_by']['seller_name'] ?? null, 'C', 'C-33 EP-08 Caso D muestra occupied_by');

    // Rechazo por caja ya abierta (otro vendedor y mismo vendedor con otra ref)
    $rBusyB = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_busyb_' . bin2hex(random_bytes(10)),
        'shift_id' => $idTurnoActual,
        'cash_counted' => 50,
    ], ['X-CSRF-Token' => $csrfVendB], $cookieVendB);
    assertSameVal(409, $rBusyB['http_code'], 'C', 'C-34 Otro vendedor abriendo caja ocupada -> 409');
    assertSameVal('CASH_ALREADY_OPEN', $rBusyB['json']['code'] ?? null, 'C', 'C-35 Código CASH_ALREADY_OPEN');
    assertSameVal('Vendedor A Paso7', $rBusyB['json']['data']['occupied_by']['seller_name'] ?? null, 'C', 'C-36 data.occupied_by presente en CASH_ALREADY_OPEN');

    // =========================================================================
    // D: IDEMPOTENCIA Y ROLLBACK TRANSACCIONAL
    // =========================================================================
    // Reenvío idéntico con alias equivalentes (request_id + shift_id + "0.00") incluso estando abierta la caja
    $rReplay1 = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'request_id'   => $refOpen1,
        'shift_id'     => $idTurnoActual,
        'cash_counted' => '0.00',
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal(200, $rReplay1['http_code'], 'D', 'D-01 Replay idempotente con alias equivalentes -> 200');
    assertSameVal('OPERATION_ALREADY_CONFIRMED', $rReplay1['json']['code'] ?? null, 'D', 'D-02 code = OPERATION_ALREADY_CONFIRMED');
    assertSameVal(true, $rReplay1['json']['idempotent_replay'] ?? null, 'D', 'D-03 idempotent_replay = true');
    assertSameVal('OPENED', $rReplay1['json']['data']['result_code'] ?? null, 'D', 'D-04 data.result_code = OPENED');
    assertSameVal($idSesion1, $rReplay1['json']['data']['session_id'] ?? null, 'D', 'D-05 Replay devuelve mismo session_id');

    // Misma referencia con importe distinto -> IDEMPOTENCY_PAYLOAD_MISMATCH
    $rMismatch = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => $refOpen1,
        'shift_id'             => $idTurnoActual,
        'cash_counted'         => 25.00,
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal(409, $rMismatch['http_code'], 'D', 'D-06 Misma referencia con importe distinto -> 409');
    assertSameVal('IDEMPOTENCY_PAYLOAD_MISMATCH', $rMismatch['json']['code'] ?? null, 'D', 'D-07 Código IDEMPOTENCY_PAYLOAD_MISMATCH');

    // Fallo transaccional sin confirmaciones parciales (vendedor inexistente a nivel servicio tras cerrar o directo)
    $refFail = 'p7_failtx_' . bin2hex(random_bytes(10));
    $ctrlSvc = new Services\ControlSolicitudService($pdo);
    $threw = false;
    try {
        $ctrlSvc->ejecutarConIdempotencia($idVendA, 'CAJA_ABRIR', $refFail, ['test' => 1], function (PDO $txPdo, int $idSol): array {
            throw new RuntimeException('Fallo simulado dentro de transacción de apertura');
        });
    } catch (Throwable $e) {
        $threw = true;
    }
    $stmtFail = $pdo->prepare('SELECT COUNT(*) FROM control_solicitud_idempotencia WHERE referencia_solicitud = :r');
    $stmtFail->execute([':r' => $refFail]);
    assertTrue($threw && (int)$stmtFail->fetchColumn() === 0, 'D', 'D-08 Fallo transaccional revierte confirmación idempotente sin dejar huérfanos');

    // =========================================================================
    // E: CIERRE FIXTURE (200.00), APERTURA POSTERIOR CON SUPLENCIA (190.00), SNAPSHOT Y CIERRE EN 0.00
    // =========================================================================
    cerrarSesionFixture($pdo, $idSesion1, $idVendA, 200.00);

    // EP-08 Caso B: caja cerrada con referencia 200.00
    $rEstadoB = httpRequest($serverUrl, 'GET', '/api/caja/estado', null, [], $cookieVendB);
    assertSameVal(false, $rEstadoB['json']['data']['is_open'] ?? null, 'E', 'E-01 EP-08 Caso B is_open = false');
    assertSameVal(true, $rEstadoB['json']['data']['has_previous_close'] ?? null, 'E', 'E-02 EP-08 Caso B has_previous_close = true');
    assertSameVal(200, (int)($rEstadoB['json']['data']['reference_amount'] ?? -1), 'E', 'E-03 EP-08 Caso B reference_amount = 200');
    assertSameVal('Jornada Completa P7', $rEstadoB['json']['data']['reference_turn'] ?? null, 'E', 'E-04 EP-08 Caso B reference_turn correcto');

    // Guardar snapshot de programación de Vendedor A antes de la suplencia de Vendedor B
    $progAntes = $pdo->query("SELECT COUNT(*) FROM programacion_semanal")->fetchColumn();

    // Vendedor B (sin programación) abre en suplencia con Turno Corto (fuera de horario) y cash_counted = 190.00
    $refOpen2 = 'p7_open2_' . bin2hex(random_bytes(10));
    $rOpen2 = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => $refOpen2,
        'shift_id'             => $idTurnoFuera,
        'cash_counted'         => 190.00,
    ], ['X-CSRF-Token' => $csrfVendB], $cookieVendB);

    assertSameVal(201, $rOpen2['http_code'], 'E', 'E-05 Apertura posterior en suplencia -> 201');
    assertSameVal(true, $rOpen2['json']['data']['is_substitution'] ?? null, 'E', 'E-06 is_substitution = true');
    assertSameVal(true, $rOpen2['json']['data']['opened_outside_schedule'] ?? null, 'E', 'E-07 opened_outside_schedule = true');
    assertSameVal(true, $rOpen2['json']['data']['has_previous_close'] ?? null, 'E', 'E-08 has_previous_close = true');
    assertSameVal(200, (int)($rOpen2['json']['data']['reference_amount'] ?? -1), 'E', 'E-09 reference_amount = 200');
    assertSameVal(190, (int)($rOpen2['json']['data']['opening_balance'] ?? -1), 'E', 'E-10 opening_balance = 190');
    assertSameVal(-10, (int)($rOpen2['json']['data']['opening_difference'] ?? 0), 'E', 'E-11 opening_difference = -10');
    $idSesion2 = (int)($rOpen2['json']['data']['session_id'] ?? 0);

    // Verificar que la suplencia no alteró programacion_semanal
    $progDespues = $pdo->query("SELECT COUNT(*) FROM programacion_semanal")->fetchColumn();
    assertSameVal($progAntes, $progDespues, 'E', 'E-12 Suplencia no altera programacion_semanal');

    // Verificar fecha_hora_salida_referencia (misma fecha local de apertura, sin trasladar al día siguiente)
    $stmtSnap = $pdo->prepare('SELECT nombre_turno_referencia, DATE(fecha_hora_apertura) AS f_ap, DATE(fecha_hora_salida_referencia) AS f_sal, TIME_FORMAT(fecha_hora_salida_referencia, "%H:%i") AS h_sal FROM sesion_caja WHERE id_sesion_caja = :id');
    $stmtSnap->execute([':id' => $idSesion2]);
    $snap = $stmtSnap->fetch(PDO::FETCH_ASSOC);
    assertSameVal($snap['f_ap'] ?? 'a', $snap['f_sal'] ?? 'b', 'E', 'E-13 Salida de referencia conserva fecha local de apertura');
    assertSameVal($outEnd, $snap['h_sal'] ?? null, 'E', 'E-14 Hora de salida de referencia coincide con fin del turno');

    // Editar turno en catálogo y comprobar que el snapshot de sesion_caja NO cambia
    httpRequest($serverUrl, 'PUT', "/api/turnos/{$idTurnoFuera}", [
        'referencia_solicitud' => 'p7_edit_t_' . bin2hex(random_bytes(8)),
        'nombre' => 'Turno Corto Editado',
        'inicio' => $outStart,
        'fin'    => '23:45',
    ], ['X-CSRF-Token' => $csrfAdmin], $cookieAdmin);

    $stmtSnap->execute([':id' => $idSesion2]);
    $snapAfterEdit = $stmtSnap->fetch(PDO::FETCH_ASSOC);
    assertSameVal('Turno Corto P7', $snapAfterEdit['nombre_turno_referencia'] ?? null, 'E', 'E-15 Snapshot de sesión inmutable tras editar turno');
    assertSameVal($outEnd, $snapAfterEdit['h_sal'] ?? null, 'E', 'E-16 Hora fin de sesión inmutable tras editar turno');

    // Replay de refOpen2 tras editar el turno: conserva nombre original del turno
    $rReplay2 = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => $refOpen2,
        'shift_id'             => $idTurnoFuera,
        'cash_counted'         => 190.00,
    ], ['X-CSRF-Token' => $csrfVendB], $cookieVendB);
    assertSameVal(200, $rReplay2['http_code'], 'E', 'E-17 Replay tras editar turno -> 200');
    assertSameVal('Turno Corto P7', $rReplay2['json']['data']['shift']['name'] ?? null, 'E', 'E-18 Replay conserva snapshot original');

    // Cerrar sesión 2 con efectivo contado real en 0.00 y abrir sesión 3 con 25.50
    cerrarSesionFixture($pdo, $idSesion2, $idVendB, 0.00);
    $rOpen3 = httpRequest($serverUrl, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p7_open3_' . bin2hex(random_bytes(10)),
        'shift_id'             => $idTurnoActual,
        'cash_counted'         => 25.50,
    ], ['X-CSRF-Token' => $csrfVendA], $cookieVendA);
    assertSameVal(201, $rOpen3['http_code'], 'E', 'E-19 Apertura tras cierre real en 0.00 -> 201');
    assertSameVal(true, $rOpen3['json']['data']['has_previous_close'] ?? null, 'E', 'E-20 has_previous_close = true tras cierre en 0.00');
    assertSameVal(0.0, (float)($rOpen3['json']['data']['reference_amount'] ?? -1), 'E', 'E-21 reference_amount = 0.00');
    assertSameVal(25.5, (float)($rOpen3['json']['data']['opening_difference'] ?? -1), 'E', 'E-22 opening_difference = 25.50');
    $idSesion3 = (int)($rOpen3['json']['data']['session_id'] ?? 0);

    // E-23..E-29: Valores válidos extremos y equivalencia número JSON vs cadena
    cerrarSesionFixture($pdo, $idSesion3, $idVendA, 25.50);
    $refEq190 = 'p7_eq190_' . bin2hex(random_bytes(9));
    $r190Num = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrfVendA,
    ], $cookieVendA, '{"referencia_solicitud":"' . $refEq190 . '","shift_id":' . $idTurnoActual . ',"cash_counted":190.50}');
    assertSameVal(201, $r190Num['http_code'], 'E', 'E-23 Apertura válida con número JSON 190.50 -> 201');
    assertSameVal(190.5, (float)($r190Num['json']['data']['opening_balance'] ?? -1), 'E', 'E-24 opening_balance = 190.5');
    $idSes190 = (int)($r190Num['json']['data']['session_id'] ?? 0);

    $r190Str = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrfVendA,
    ], $cookieVendA, '{"referencia_solicitud":"' . $refEq190 . '","shift_id":' . $idTurnoActual . ',"cash_counted":"190.50"}');
    assertSameVal(200, $r190Str['http_code'], 'E', 'E-25 Replay equivalente con cadena "190.50" -> 200 OPERATION_ALREADY_CONFIRMED');
    assertSameVal($idSes190, $r190Str['json']['data']['session_id'] ?? null, 'E', 'E-26 Replay equivalente devuelve mismo session_id');

    $r190Diff = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrfVendA,
    ], $cookieVendA, '{"referencia_solicitud":"' . $refEq190 . '","shift_id":' . $idTurnoActual . ',"cash_counted":190.51}');
    assertSameVal(409, $r190Diff['http_code'], 'E', 'E-27 Misma referencia con 190.51 -> 409 IDEMPOTENCY_PAYLOAD_MISMATCH');
    assertSameVal('IDEMPOTENCY_PAYLOAD_MISMATCH', $r190Diff['json']['code'] ?? null, 'E', 'E-28 Código IDEMPOTENCY_PAYLOAD_MISMATCH');

    cerrarSesionFixture($pdo, $idSes190, $idVendA, 190.50);
    $refMax = 'p7_max99_' . bin2hex(random_bytes(9));
    $rMaxNum = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrfVendA,
    ], $cookieVendA, '{"referencia_solicitud":"' . $refMax . '","shift_id":' . $idTurnoActual . ',"cash_counted":99999999.99}');
    assertSameVal(201, $rMaxNum['http_code'], 'E', 'E-29 Apertura válida con máximo 99999999.99 -> 201');
    $idSesMax = (int)($rMaxNum['json']['data']['session_id'] ?? 0);

    cerrarSesionFixture($pdo, $idSesMax, $idVendA, 0.01);
    $rMinPos = httpRequest($serverUrl, 'POST', '/api/caja/abrir', null, [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $csrfVendA,
    ], $cookieVendA, '{"referencia_solicitud":"p7_min01_' . bin2hex(random_bytes(9)) . '","shift_id":' . $idTurnoActual . ',"cash_counted":0.01}');
    assertSameVal(201, $rMinPos['http_code'], 'E', 'E-30 Apertura válida con 0.01 -> 201');
    $idSesion3 = (int)($rMinPos['json']['data']['session_id'] ?? 0);

    // =========================================================================
    // F: CONCURRENCIA REAL MULTI-PROCESO (worker_concurrencia_paso7.php)
    // =========================================================================
    $workerPath = $rootDir . '/tests/sprint3/worker_concurrencia_paso7.php';

    // Cerrar sesión 3 para dejar la caja libre antes de la carrera 1
    cerrarSesionFixture($pdo, $idSesion3, $idVendA, 0.01);

    // F-01: Dos vendedores distintos compitiendo simultáneamente
    $resF1 = runParallelWorkers($phpBin, $workerPath, $dbName, [
        [
            'action'   => 'abrir_caja',
            'vendedor' => ['id_usuario' => $idVendA, 'nombre' => 'Vendedor A Paso7', 'id_rol' => 2],
            'input'    => ['referencia_solicitud' => 'p7_conc_a_' . bin2hex(random_bytes(9)), 'shift_id' => $idTurnoActual, 'cash_counted' => 100],
        ],
        [
            'action'   => 'abrir_caja',
            'vendedor' => ['id_usuario' => $idVendB, 'nombre' => 'Vendedor B Paso7', 'id_rol' => 2],
            'input'    => ['referencia_solicitud' => 'p7_conc_b_' . bin2hex(random_bytes(9)), 'shift_id' => $idTurnoActual, 'cash_counted' => 120],
        ],
    ]);
    $codesF1 = array_map(static fn ($x) => (int)($x['json']['http_code'] ?? 0), $resF1);
    sort($codesF1);
    assertSameVal([201, 409], $codesF1, 'F', 'F-01 Carrera dos vendedores -> uno 201 y otro 409');
    $errCodesF1 = array_map(static fn ($x) => (string)($x['json']['response']['code'] ?? ''), $resF1);
    sort($errCodesF1);
    assertSameVal(['CASH_ALREADY_OPEN', 'OPENED'], $errCodesF1, 'F', 'F-02 Carrera dos vendedores -> OPENED + CASH_ALREADY_OPEN');

    $abiertasF1 = (int)$pdo->query("SELECT COUNT(*) FROM sesion_caja WHERE estado = 'ABIERTA'")->fetchColumn();
    assertSameVal(1, $abiertasF1, 'F', 'F-03 Solo una sesión ABIERTA globalmente tras F-01');

    $idAbiertaF1 = (int)$pdo->query("SELECT id_sesion_caja FROM sesion_caja WHERE estado = 'ABIERTA' LIMIT 1")->fetchColumn();
    $idVendAbiertaF1 = (int)$pdo->query("SELECT id_usuario_vendedor FROM sesion_caja WHERE id_sesion_caja = {$idAbiertaF1}")->fetchColumn();
    $guardiaActual = (int)$pdo->query("SELECT id_sesion_abierta_actual FROM caja_fisica_guardia WHERE id_caja_fisica = 1")->fetchColumn();
    assertSameVal($idAbiertaF1, $guardiaActual, 'F', 'F-04 caja_fisica_guardia.id_sesion_abierta_actual consistente');

    // Cerrar sesión de F-01 para F-02
    cerrarSesionFixture($pdo, $idAbiertaF1, $idVendAbiertaF1, 100.00);

    // F-02: Mismo vendedor con dos referencias distintas simultáneas
    $resF2 = runParallelWorkers($phpBin, $workerPath, $dbName, [
        [
            'action'   => 'abrir_caja',
            'vendedor' => ['id_usuario' => $idVendA, 'nombre' => 'Vendedor A Paso7', 'id_rol' => 2],
            'input'    => ['referencia_solicitud' => 'p7_conc_m1_' . bin2hex(random_bytes(9)), 'shift_id' => $idTurnoActual, 'cash_counted' => 110],
        ],
        [
            'action'   => 'abrir_caja',
            'vendedor' => ['id_usuario' => $idVendA, 'nombre' => 'Vendedor A Paso7', 'id_rol' => 2],
            'input'    => ['referencia_solicitud' => 'p7_conc_m2_' . bin2hex(random_bytes(9)), 'shift_id' => $idTurnoActual, 'cash_counted' => 110],
        ],
    ]);
    $codesF2 = array_map(static fn ($x) => (int)($x['json']['http_code'] ?? 0), $resF2);
    sort($codesF2);
    assertSameVal([201, 409], $codesF2, 'F', 'F-05 Carrera mismo vendedor dos refs -> uno 201 y otro 409');

    $idAbiertaF2 = (int)$pdo->query("SELECT id_sesion_caja FROM sesion_caja WHERE estado = 'ABIERTA' LIMIT 1")->fetchColumn();
    cerrarSesionFixture($pdo, $idAbiertaF2, $idVendA, 110.00);

    // F-03: Reintentos simultáneos con la MISMA referencia
    $refSame = 'p7_conc_same_' . bin2hex(random_bytes(8));
    $resF3 = runParallelWorkers($phpBin, $workerPath, $dbName, [
        [
            'action'   => 'abrir_caja',
            'vendedor' => ['id_usuario' => $idVendA, 'nombre' => 'Vendedor A Paso7', 'id_rol' => 2],
            'input'    => ['referencia_solicitud' => $refSame, 'shift_id' => $idTurnoActual, 'cash_counted' => 130],
        ],
        [
            'action'   => 'abrir_caja',
            'vendedor' => ['id_usuario' => $idVendA, 'nombre' => 'Vendedor A Paso7', 'id_rol' => 2],
            'input'    => ['referencia_solicitud' => $refSame, 'shift_id' => $idTurnoActual, 'cash_counted' => 130],
        ],
    ]);
    $abiertasF3 = (int)$pdo->query("SELECT COUNT(*) FROM sesion_caja WHERE estado = 'ABIERTA'")->fetchColumn();
    $stmtF3Count = $pdo->prepare('SELECT COUNT(*) FROM sesion_caja WHERE id_solicitud_apertura IN (SELECT id_solicitud FROM control_solicitud_idempotencia WHERE referencia_solicitud = :r)');
    $stmtF3Count->execute([':r' => $refSame]);
    $sesionesRefSame = (int)$stmtF3Count->fetchColumn();
    assertSameVal(1, $abiertasF3, 'F', 'F-06 Reintentos simultáneos misma ref -> solo 1 sesión ABIERTA');
    assertSameVal(1, $sesionesRefSame, 'F', 'F-07 Reintentos simultáneos misma ref -> exactamente 1 fila en sesion_caja');

    // =========================================================================
    // G: REGRESIÓN Y CONSERVACIÓN DE DATOS
    // =========================================================================
    $rReg1 = httpRequest($serverUrl, 'GET', '/api/usuarios', null, [], $cookieAdmin);
    $rReg2 = httpRequest($serverUrl, 'GET', '/api/productos', null, [], $cookieAdmin);
    $rReg3 = httpRequest($serverUrl, 'GET', '/api/reportes/kardex', null, [], $cookieAdmin);
    $rReg4 = httpRequest($serverUrl, 'GET', '/api/ventas', null, [], $cookieAdmin);
    $rReg5 = httpRequest($serverUrl, 'GET', '/api/compras', null, [], $cookieAdmin);
    assertTrue(
        $rReg1['http_code'] === 200 && $rReg2['http_code'] === 200 && $rReg3['http_code'] === 200
        && $rReg4['http_code'] === 200 && $rReg5['http_code'] === 200,
        'G',
        'G-01 Endpoints previos (/usuarios, /productos, /reportes/kardex, /ventas, /compras) operan con 200'
    );

    $ventasSinSesion = (int)$pdo->query('SELECT COUNT(*) FROM venta WHERE id_sesion_caja IS NULL')->fetchColumn();
    $totalVentasCopia = (int)$pdo->query('SELECT COUNT(*) FROM venta')->fetchColumn();
    assertSameVal(250, $totalVentasCopia, 'G', 'G-02 Se conservan las 250 ventas en copia');
    assertSameVal(250, $ventasSinSesion, 'G', 'G-03 Las 250 ventas históricas conservan id_sesion_caja = NULL');

    // Conservación intacta en amazon_market original
    $origVentas = (int)$pdoOrig->query('SELECT COUNT(*) FROM venta')->fetchColumn();
    $origCompras = (int)$pdoOrig->query('SELECT COUNT(*) FROM compra')->fetchColumn();
    $origProductos = (int)$pdoOrig->query('SELECT COUNT(*) FROM producto')->fetchColumn();
    $origStock = (int)$pdoOrig->query('SELECT COALESCE(SUM(stock_actual), 0) FROM producto')->fetchColumn();
    $origKardex = (int)$pdoOrig->query('SELECT COUNT(*) FROM kardex')->fetchColumn();
    assertSameVal(250, $origVentas, 'G', 'G-04 amazon_market ventas intactas (250)');
    assertSameVal(80, $origCompras, 'G', 'G-05 amazon_market compras intactas (80)');
    assertSameVal(108, $origProductos, 'G', 'G-06 amazon_market productos intactos (108)');
    assertSameVal(2497, $origStock, 'G', 'G-07 amazon_market stock intacto (2497)');
    assertSameVal(470, $origKardex, 'G', 'G-08 amazon_market kardex intacto (470)');

    // =========================================================================
    // V: PRUEBAS DIRECTAS DEL VALIDADOR MONETARIO (sin HTTP)
    // =========================================================================
    $validCases = [
        '0 int'           => [0, null, 0, '0.00'],
        '0.00 str'        => ['0.00', null, 0, '0.00'],
        '0.01 float'      => [0.01, null, 1, '0.01'],
        '0.01 str'        => ['0.01', null, 1, '0.01'],
        '1 int'           => [1, null, 100, '1.00'],
        '1.01 float'      => [1.01, null, 101, '1.01'],
        '190.50 float'    => [190.50, null, 19050, '190.50'],
        '190.50 str'      => ['190.50', null, 19050, '190.50'],
        '99999999.99 num' => [99999999.99, '99999999.99', 9999999999, '99999999.99'],
        '99999999.99 str' => ['99999999.99', null, 9999999999, '99999999.99'],
    ];
    foreach ($validCases as $lbl => [$val, $rawLit, $expCents, $expNorm]) {
        $resV = Services\CajaService::validarImporteApertura($val, true, $rawLit);
        assertSameVal($expCents, $resV['centimos'], 'V', "Válido {$lbl} -> céntimos {$expCents}");
        assertSameVal($expNorm, $resV['normalizado'], 'V', "Válido {$lbl} -> normalizado {$expNorm}");
    }

    $invalidDirect = [
        '1.00000000001 float'   => [1.00000000001, null, 'INVALID_AMOUNT'],
        '0.00000000001 float'   => [0.00000000001, null, 'INVALID_AMOUNT'],
        '1.0000000000000000001' => [1.0, '1.0000000000000000001', 'INVALID_AMOUNT'],
        '1.001 float'           => [1.001, null, 'INVALID_AMOUNT'],
        '-0.01 float'           => [-0.01, null, 'INVALID_AMOUNT'],
        '100000000.00 str'      => ['100000000.00', null, 'AMOUNT_OUT_OF_RANGE'],
    ];
    foreach ($invalidDirect as $lbl => [$val, $rawLit, $expCode]) {
        $codeGot = null;
        try {
            Services\CajaService::validarImporteApertura($val, true, $rawLit);
        } catch (Libraries\Core\InventarioException $e) {
            $codeGot = $e->getErrorCode();
        }
        assertSameVal($expCode, $codeGot, 'V', "Inválido directo {$lbl} -> {$expCode}");
    }

    // Pruebas directas de JsonDecimalExtractor (anidados, cadenas, arreglos y escapes Unicode)
    $nestedObj = Libraries\Core\JsonDecimalExtractor::inspectTopLevelKey(
        '{"meta":{"cash_counted":999},"note":"{\"cash_counted\":888}","arr":[{"cash_counted":777}],"cash_counted":15.25}',
        'cash_counted'
    );
    assertSameVal(1, $nestedObj['count'], 'V', 'Extractor ignora cash_counted en objeto anidado, cadena y arreglo (count=1)');
    assertSameVal('15.25', $nestedObj['number_literal'], 'V', 'Extractor devuelve literal raíz 15.25');

    $unicodeDup = Libraries\Core\JsonDecimalExtractor::inspectTopLevelKey(
        '{"cash_counted":1,"cash\u005fcounted":999}',
        'cash_counted'
    );
    assertSameVal(2, $unicodeDup['count'], 'V', 'Extractor detecta duplicado literal + escape Unicode (count=2)');
    assertSameVal(null, $unicodeDup['number_literal'], 'V', 'Extractor anula number_literal ante duplicado');
} finally {
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($tempDir);
}

echo "\n=== RESUMEN PASO 7 ===\n";
foreach ($categories as $cat => $counts) {
    echo "Categoría {$cat}: {$counts['passed']} superadas, {$counts['failed']} fallidas\n";
}
echo "TOTAL: {$passed} / " . ($passed + $failed) . " aserciones superadas.\n";

exit($failed === 0 ? 0 : 1);
