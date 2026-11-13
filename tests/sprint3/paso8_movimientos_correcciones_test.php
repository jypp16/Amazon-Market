<?php
declare(strict_types=1);

/**
 * Batería integral de aceptación para el Paso 8 del Sprint 3
 * (HU 15 y HU 16: EP-10 a EP-16 — Movimientos, Retiros Omitidos y Correcciones Vinculadas).
 *
 * Incluye:
 * - Pruebas HTTP reales contra dos servidores PHP independientes (para concurrencia real multi-proceso).
 * - Verificación de persistencia en MariaDB, triggers, versión de caja e invalidación de arqueos.
 * - Validación de precisión decimal, claves duplicadas, overflow DECIMAL(10,2), idempotencia y replay post-cierre.
 * - Conservación íntegra de las 32 tablas originales y ventas históricas.
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

$httpPort1 = (int)(getenv('TEST_HTTP_PORT') ?: 8096);
$httpPort2 = $httpPort1 + 1;
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
    assertTrue($ok, $cat, $name, $ok ? '' : ($exists ? 'actual=' . json_encode($arr[$key]) : "clave '{$key}' ausente"));
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
    if (($body !== null || $rawBody !== null) && !isset($headers['Content-Type'])) {
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

function cerrarSesionFixture(PDO $pdo, int $idSesion, float $contadoCierre, ?string $fechaCierreOverride = null): void
{
    $stmtS = $pdo->prepare('SELECT * FROM sesion_caja WHERE id_sesion_caja = ? LIMIT 1');
    $stmtS->execute([$idSesion]);
    $sesion = $stmtS->fetch(PDO::FETCH_ASSOC);
    if (!$sesion) {
        throw new RuntimeException("Sesión {$idSesion} no encontrada para fixture de cierre.");
    }

    $idVendedor = (int)$sesion['id_usuario_vendedor'];
    $esperado = round((float)$sesion['efectivo_esperado_actual'], 2);
    $contado = round($contadoCierre, 2);
    $diff = round($contado - $esperado, 2);
    $obs = ($diff !== 0.0) ? 'Fixture cierre con diferencia controlada.' : null;

    $pdo->prepare("UPDATE arqueo_caja SET estado = 'REEMPLAZADO', vigencia_activa = NULL, causa_cambio_estado = 'NUEVO_ARQUEO', fecha_hora_cambio_estado = NOW() WHERE id_sesion_caja = ? AND estado = 'VIGENTE'")
        ->execute([$idSesion]);

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

    $cierreStr = $fechaCierreOverride;
    if ($cierreStr === null) {
        $ahoraStr = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
        $cierreDt = new DateTimeImmutable((string)$sesion['fecha_hora_salida_referencia'], new DateTimeZone('America/Lima'));
        $cierreStr = ((new DateTimeImmutable($ahoraStr, new DateTimeZone('America/Lima'))) > $cierreDt)
            ? $ahoraStr
            : $cierreDt->format('Y-m-d H:i:s');
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

function insertarArqueoVigenteFixture(PDO $pdo, int $idSesion): int
{
    $stmtS = $pdo->prepare('SELECT id_usuario_vendedor, version_caja, efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = ? LIMIT 1');
    $stmtS->execute([$idSesion]);
    $sesion = $stmtS->fetch(PDO::FETCH_ASSOC);
    if (!$sesion) {
        throw new RuntimeException("Sesión {$idSesion} no encontrada.");
    }

    $pdo->prepare("UPDATE arqueo_caja SET estado = 'REEMPLAZADO', vigencia_activa = NULL, causa_cambio_estado = 'NUEVO_ARQUEO', fecha_hora_cambio_estado = NOW() WHERE id_sesion_caja = ? AND estado = 'VIGENTE'")
        ->execute([$idSesion]);

    $esperado = (string)$sesion['efectivo_esperado_actual'];
    $stmtA = $pdo->prepare(
        "INSERT INTO arqueo_caja (id_sesion_caja, id_usuario_vendedor, version_caja_evaluada, monto_esperado, monto_contado, diferencia, observacion, estado, vigencia_activa)
         VALUES (:id_sesion, :id_vendedor, :version, :esperado, :contado, 0.00, NULL, 'VIGENTE', 1)"
    );
    $stmtA->execute([
        ':id_sesion'   => $idSesion,
        ':id_vendedor' => (int)$sesion['id_usuario_vendedor'],
        ':version'     => (int)$sesion['version_caja'],
        ':esperado'    => $esperado,
        ':contado'     => $esperado,
    ]);
    $idArqueo = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE sesion_caja SET id_ultimo_arqueo = :id_arq WHERE id_sesion_caja = :id_sesion')
        ->execute([':id_arq' => $idArqueo, ':id_sesion' => $idSesion]);
    return $idArqueo;
}

function runParallelHttpWorkers(string $phpBin, string $workerPath, array $tasks): array
{
    $procs = [];
    $pipesList = [];
    $startAt = microtime(true) + 0.50;

    foreach ($tasks as $idx => $task) {
        $cmd = [
            $phpBin,
            $workerPath,
            '--base-url=' . $task['base_url'],
            '--cookie-file=' . $task['cookie_file'],
            '--csrf=' . $task['csrf'],
            '--path=' . $task['path'],
            '--payload-b64=' . base64_encode((string)json_encode($task['payload'], JSON_UNESCAPED_UNICODE)),
            '--start-at=' . sprintf('%.6F', $startAt),
        ];
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException("No se pudo iniciar worker {$idx}");
        }
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

function startPhpServer(string $phpBin, string $rootDir, string $sessionDir, string $dbName, int $port)
{
    $routerPath = $rootDir . '/tests/sprint3/server_router_sprint3.php';
    $cmd = [
        $phpBin,
        '-d',
        'session.save_path=' . $sessionDir,
        '-S',
        '127.0.0.1:' . $port,
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
        'BASE_URL'   => "http://127.0.0.1:{$port}",
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
        'PATH'       => getenv('PATH') ?: '',
        'TEMP'       => getenv('TEMP') ?: sys_get_temp_dir(),
        'TMP'        => getenv('TMP') ?: sys_get_temp_dir(),
    ]);
    $proc = proc_open($cmd, $descriptors, $pipes, $rootDir, $serverEnv);
    if (!is_resource($proc)) {
        throw new RuntimeException("No se pudo iniciar servidor PHP en puerto {$port}.");
    }
    return $proc;
}

$tempDir = sys_get_temp_dir() . '/am_paso8_' . bin2hex(random_bytes(4));
mkdir($tempDir, 0777, true);
$sessionSaveDir = $tempDir . '/sessions';
mkdir($sessionSaveDir, 0777, true);

$serverProc1 = null;
$serverProc2 = null;
$serverUrl1 = "http://127.0.0.1:{$httpPort1}";
$serverUrl2 = "http://127.0.0.1:{$httpPort2}";

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
    ob_implicit_flush(true);
    $serverProc1 = startPhpServer($phpBin, $rootDir, $sessionSaveDir, $dbName, $httpPort1);
    $serverProc2 = startPhpServer($phpBin, $rootDir, $sessionSaveDir, $dbName, $httpPort2);

    $ready1 = false;
    $ready2 = false;
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        if (!$ready1) {
            $ping = httpRequest($serverUrl1, 'GET', '/Auth');
            if ($ping['http_code'] > 0) {
                $ready1 = true;
            }
        }
        if (!$ready2) {
            $ping = httpRequest($serverUrl2, 'GET', '/Auth');
            if ($ping['http_code'] > 0) {
                $ready2 = true;
            }
        }
        if ($ready1 && $ready2) {
            break;
        }
    }
    if (!$ready1 || !$ready2) {
        throw new RuntimeException('Los servidores PHP aislados no respondieron a tiempo.');
    }

    // -------------------------------------------------------------------------
    // Preparación de usuarios y turnos fixtures en la copia aislada
    // -------------------------------------------------------------------------
    $hashPrueba = password_hash('Paso8Test#2026', PASSWORD_BCRYPT);
    $stmtUsr = $pdo->prepare("INSERT INTO usuario (id_rol, nombre, username, dni, telefono, direccion, email, password_hash, estado) VALUES (:rol, :nom, :usr, :dni, :tel, :dir, :eml, :p, 1)");
    $stmtUsr->execute([':rol' => 1, ':nom' => 'Admin Paso8', ':usr' => 'admp8', ':dni' => '80000001', ':tel' => '900000011', ':dir' => 'Av Test 1', ':eml' => 'admp8@test.local', ':p' => $hashPrueba]);
    $idAdminTest = (int)$pdo->lastInsertId();

    $stmtUsr->execute([':rol' => 2, ':nom' => 'Vendedor A Paso8', ':usr' => 'vndp8a', ':dni' => '80000002', ':tel' => '900000012', ':dir' => 'Av Test 2', ':eml' => 'vndp8a@test.local', ':p' => $hashPrueba]);
    $idVendA = (int)$pdo->lastInsertId();

    $stmtUsr->execute([':rol' => 2, ':nom' => 'Vendedor B Paso8', ':usr' => 'vndp8b', ':dni' => '80000003', ':tel' => '900000013', ':dir' => 'Av Test 3', ':eml' => 'vndp8b@test.local', ':p' => $hashPrueba]);
    $idVendB = (int)$pdo->lastInsertId();

    $stmtUsr->execute([':rol' => 2, ':nom' => 'Vendedor Inact Paso8', ':usr' => 'vndp8inact', ':dni' => '80000004', ':tel' => '900000014', ':dir' => 'Av Test 4', ':eml' => 'vndp8inact@test.local', ':p' => $hashPrueba]);
    $idVendInact = (int)$pdo->lastInsertId();

    // Crear un turno activo para pruebas
    $pdo->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES ('Mañana Test', '07:00:00', '15:00:00', 1)");
    $turnoId = (int)$pdo->lastInsertId();

    $cookieV1 = $tempDir . '/v1.cookie';
    $cookieV2 = $tempDir . '/v2.cookie';
    $cookieV3 = $tempDir . '/v3.cookie';
    $cookieAdmin = $tempDir . '/admin.cookie';

    $csrfV1 = loginHttp($serverUrl1, 'vndp8a', 'Paso8Test#2026', $cookieV1);
    $csrfV2 = loginHttp($serverUrl1, 'vndp8b', 'Paso8Test#2026', $cookieV2);
    $csrfAdmin = loginHttp($serverUrl1, 'admp8', 'Paso8Test#2026', $cookieAdmin);

    assertTrue($csrfV1 !== null && $csrfV2 !== null && $csrfAdmin !== null, 'PRE', 'Login HTTP de usuarios de ensayo');

    // =========================================================================
    // SECCIÓN A: SEGURIDAD, ROLES, PERMISOS, CSRF, JSON Y PROYECCIÓN MÍNIMA
    // =========================================================================
    $unauthGet = httpRequest($serverUrl1, 'GET', '/api/caja/sesion-actual');
    assertSameVal(401, $unauthGet['http_code'], 'SEGURIDAD', 'EP-10 sin sesión devuelve 401');
    assertSameVal('AUTH_REQUIRED', $unauthGet['json']['code'] ?? null, 'SEGURIDAD', 'EP-10 sin sesión código AUTH_REQUIRED');

    $unauthPost = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', ['type' => 'manual_income']);
    assertSameVal(401, $unauthPost['http_code'], 'SEGURIDAD', 'EP-13 sin sesión devuelve 401');

    // Administrador rechazado en EP-10..EP-16
    $adminEp10 = httpRequest($serverUrl1, 'GET', '/api/caja/sesion-actual', null, [], $cookieAdmin);
    assertSameVal(403, $adminEp10['http_code'], 'SEGURIDAD', 'EP-10 rechaza Administrador con 403');
    assertSameVal('FORBIDDEN', $adminEp10['json']['code'] ?? null, 'SEGURIDAD', 'EP-10 Admin código FORBIDDEN');

    $adminEp13 = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', ['type' => 'manual_income'], ['X-CSRF-Token' => (string)$csrfAdmin], $cookieAdmin);
    assertSameVal(403, $adminEp13['http_code'], 'SEGURIDAD', 'EP-13 rechaza Administrador con 403');

    $adminEp16 = httpRequest($serverUrl1, 'POST', '/api/caja/movimientos/correcciones', ['original_movement_id' => 1], ['X-CSRF-Token' => (string)$csrfAdmin], $cookieAdmin);
    assertSameVal(403, $adminEp16['http_code'], 'SEGURIDAD', 'EP-16 rechaza Administrador con 403');

    // Usuario logueado y luego desactivado en BD
    $csrfV3 = loginHttp($serverUrl1, 'vndp8inact', 'Paso8Test#2026', $cookieV3);
    $pdo->prepare('UPDATE usuario SET estado = 0 WHERE id_usuario = ?')->execute([$idVendInact]);
    $inactResp = httpRequest($serverUrl1, 'GET', '/api/caja/sesion-actual', null, [], $cookieV3);
    assertSameVal(401, $inactResp['http_code'], 'SEGURIDAD', 'Usuario desactivado en BD devuelve 401 AUTH_REQUIRED');
    assertSameVal('AUTH_REQUIRED', $inactResp['json']['code'] ?? null, 'SEGURIDAD', 'Usuario desactivado código AUTH_REQUIRED');

    // Vendedor sin permiso turnos.movimientos (simulado temporalmente revocando en rol_permiso y restaurando)
    $idPermMov = (int)$pdo->query("SELECT id_permiso FROM permiso WHERE slug = 'turnos.movimientos'")->fetchColumn();
    $pdo->prepare('DELETE FROM rol_permiso WHERE id_rol = 2 AND id_permiso = ?')->execute([$idPermMov]);
    $sinPermEp10 = httpRequest($serverUrl1, 'GET', '/api/caja/sesion-actual', null, [], $cookieV1);
    assertSameVal(403, $sinPermEp10['http_code'], 'SEGURIDAD', 'EP-10 rechaza vendedor sin permiso turnos.movimientos');
    $sinPermEp13 = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', ['type' => 'manual_income'], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(403, $sinPermEp13['http_code'], 'SEGURIDAD', 'EP-13 rechaza vendedor sin permiso turnos.movimientos');
    // Verificar que turnos.abrir sigue funcionando sin turnos.movimientos
    $conPermAbrir = httpRequest($serverUrl1, 'GET', '/api/caja/estado', null, [], $cookieV1);
    assertSameVal(200, $conPermAbrir['http_code'], 'SEGURIDAD', 'EP-08 sigue accesible con turnos.abrir aunque falte turnos.movimientos');
    // Restaurar permiso turnos.movimientos
    $pdo->prepare('INSERT INTO rol_permiso (id_rol, id_permiso) VALUES (2, ?)')->execute([$idPermMov]);

    // CSRF inválido e INVALID_JSON
    $badCsrf = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', ['type' => 'manual_income'], ['X-CSRF-Token' => 'invalid_token'], $cookieV1);
    assertSameVal(403, $badCsrf['http_code'], 'SEGURIDAD', 'EP-13 CSRF inválido devuelve 403');
    assertSameVal('CSRF_INVALID', $badCsrf['json']['code'] ?? null, 'SEGURIDAD', 'EP-13 CSRF inválido código CSRF_INVALID');

    $badJson = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', null, ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$csrfV1], $cookieV1, '{bad_json');
    assertSameVal(400, $badJson['http_code'], 'SEGURIDAD', 'EP-13 JSON inválido devuelve 400');
    assertSameVal('INVALID_JSON', $badJson['json']['code'] ?? null, 'SEGURIDAD', 'EP-13 JSON inválido código INVALID_JSON');

    $badCt = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', null, ['Content-Type' => 'text/plain', 'X-CSRF-Token' => (string)$csrfV1], $cookieV1, 'hola');
    assertSameVal(415, $badCt['http_code'], 'SEGURIDAD', 'EP-13 Content-Type text/plain devuelve 415');

    // Sin sesión abierta: EP-11, EP-12, EP-15 -> 409 CASH_SESSION_CLOSED
    $closedEp11 = httpRequest($serverUrl1, 'GET', '/api/caja/administradores', null, [], $cookieV1);
    assertSameVal(409, $closedEp11['http_code'], 'SEGURIDAD', 'EP-11 con caja cerrada devuelve 409');
    assertSameVal('CASH_SESSION_CLOSED', $closedEp11['json']['code'] ?? null, 'SEGURIDAD', 'EP-11 con caja cerrada código CASH_SESSION_CLOSED');

    $closedEp12 = httpRequest($serverUrl1, 'GET', '/api/caja/turnos-anteriores', null, [], $cookieV1);
    assertSameVal(409, $closedEp12['http_code'], 'SEGURIDAD', 'EP-12 con caja cerrada devuelve 409');

    $closedEp15 = httpRequest($serverUrl1, 'GET', '/api/caja/movimientos/corregibles', null, [], $cookieV1);
    assertSameVal(409, $closedEp15['http_code'], 'SEGURIDAD', 'EP-15 con caja cerrada devuelve 409');

    // =========================================================================
    // SECCIÓN B: EP-10 (DECISIÓN NULL) Y EP-12 VACÍO
    // =========================================================================
    $ep10Cerrada = httpRequest($serverUrl1, 'GET', '/api/caja/sesion-actual', null, [], $cookieV1);
    assertSameVal(200, $ep10Cerrada['http_code'], 'CONSULTAS', 'EP-10 con caja cerrada devuelve 200');
    assertSameVal(false, $ep10Cerrada['json']['data']['is_open'] ?? null, 'CONSULTAS', 'EP-10 cerrada is_open=false');
    assertSameVal(false, $ep10Cerrada['json']['data']['is_owner'] ?? null, 'CONSULTAS', 'EP-10 cerrada is_owner=false');
    assertNullField($ep10Cerrada['json']['data'] ?? null, 'session_id', 'CONSULTAS', 'EP-10 cerrada session_id=null');
    assertNullField($ep10Cerrada['json']['data'] ?? null, 'current_balance', 'CONSULTAS', 'EP-10 cerrada current_balance=null');
    assertNullField($ep10Cerrada['json']['data'] ?? null, 'balance', 'CONSULTAS', 'EP-10 cerrada balance=null');

    // Vendedor 1 abre sesión con saldo 0.00
    $openV1Zero = httpRequest($serverUrl1, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p8_open_v1_zero_0001',
        'shift_id'             => $turnoId,
        'cash_counted'         => 0.00,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $openV1Zero['http_code'], 'CONSULTAS', 'Apertura V1 con 0.00 devuelve 201');
    $sesionV1ZeroId = (int)($openV1Zero['json']['data']['session_id'] ?? 0);

    // EP-10 sesión propia abierta con saldo real cero devuelve 0.00
    $ep10Zero = httpRequest($serverUrl1, 'GET', '/api/caja/sesion-actual', null, [], $cookieV1);
    assertSameVal(true, $ep10Zero['json']['data']['is_open'] ?? null, 'CONSULTAS', 'EP-10 propia saldo cero is_open=true');
    assertSameVal(true, $ep10Zero['json']['data']['is_owner'] ?? null, 'CONSULTAS', 'EP-10 propia saldo cero is_owner=true');
    assertTrue((float)($ep10Zero['json']['data']['current_balance'] ?? -1) === 0.0, 'CONSULTAS', 'EP-10 propia saldo cero current_balance=0');
    assertTrue((float)($ep10Zero['json']['data']['balance'] ?? -1) === 0.0, 'CONSULTAS', 'EP-10 propia saldo cero balance=0');

    // EP-10 desde Vendedor 2 cuando la caja está abierta por Vendedor 1 -> null sin exponer datos
    $ep10Ajena = httpRequest($serverUrl1, 'GET', '/api/caja/sesion-actual', null, [], $cookieV2);
    assertSameVal(200, $ep10Ajena['http_code'], 'CONSULTAS', 'EP-10 sesión ajena devuelve 200');
    assertSameVal(false, $ep10Ajena['json']['data']['is_open'] ?? null, 'CONSULTAS', 'EP-10 ajena is_open=false');
    assertSameVal(false, $ep10Ajena['json']['data']['is_owner'] ?? null, 'CONSULTAS', 'EP-10 ajena is_owner=false');
    assertNullField($ep10Ajena['json']['data'] ?? null, 'session_id', 'CONSULTAS', 'EP-10 ajena session_id=null');
    assertNullField($ep10Ajena['json']['data'] ?? null, 'current_balance', 'CONSULTAS', 'EP-10 ajena current_balance=null');
    assertNullField($ep10Ajena['json']['data'] ?? null, 'balance', 'CONSULTAS', 'EP-10 ajena balance=null');
    assertNullField($ep10Ajena['json']['data'] ?? null, 'seller', 'CONSULTAS', 'EP-10 ajena no expone nombre de otro vendedor');

    // Sesión ajena en EP-11, EP-12, EP-13, EP-14, EP-15, EP-16 -> 403 SESSION_NOT_OWNED
    $ajenaEp11 = httpRequest($serverUrl1, 'GET', '/api/caja/administradores', null, [], $cookieV2);
    assertSameVal(403, $ajenaEp11['http_code'], 'SEGURIDAD', 'EP-11 en sesión ajena devuelve 403');
    assertSameVal('SESSION_NOT_OWNED', $ajenaEp11['json']['code'] ?? null, 'SEGURIDAD', 'EP-11 sesión ajena código SESSION_NOT_OWNED');

    $ajenaEp13 = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_ajena_ing_000001',
        'type'                 => 'manual_income',
        'amount'               => 10.0,
        'reason'               => 'Intento ajeno',
    ], ['X-CSRF-Token' => (string)$csrfV2], $cookieV2);
    assertSameVal(403, $ajenaEp13['http_code'], 'SEGURIDAD', 'EP-13 en sesión ajena devuelve 403');
    assertSameVal('SESSION_NOT_OWNED', $ajenaEp13['json']['code'] ?? null, 'SEGURIDAD', 'EP-13 sesión ajena código SESSION_NOT_OWNED');

    // EP-11 proyección mínima de administradores activos (incluye id=1 aunque email=NULL)
    $ep11Ok = httpRequest($serverUrl1, 'GET', '/api/caja/administradores', null, [], $cookieV1);
    assertSameVal(200, $ep11Ok['http_code'], 'SEGURIDAD', 'EP-11 devuelve 200');
    $admins = $ep11Ok['json']['data'] ?? [];
    $adminIds = array_column($admins, 'id');
    assertTrue(in_array(1, $adminIds, true) && in_array(9, $adminIds, true), 'SEGURIDAD', 'EP-11 incluye admin id=1 (sin email) e id=9');
    $firstAdminKeys = array_keys($admins[0] ?? []);
    sort($firstAdminKeys);
    assertSameVal(['id', 'name'], $firstAdminKeys, 'SEGURIDAD', 'EP-11 solo expone claves id y name');

    // Vendedor sin acceso a /api/usuarios
    $dirUsuarios = httpRequest($serverUrl1, 'GET', '/api/usuarios', null, [], $cookieV1);
    assertSameVal(403, $dirUsuarios['http_code'], 'SEGURIDAD', 'Vendedor sigue bloqueado en /api/usuarios');

    // EP-12 vacío (primera sesión histórica)
    $ep12Vacio = httpRequest($serverUrl1, 'GET', '/api/caja/turnos-anteriores', null, [], $cookieV1);
    assertSameVal(200, $ep12Vacio['http_code'], 'CONSULTAS', 'EP-12 primera sesión devuelve 200');
    assertSameVal([], $ep12Vacio['json']['data'] ?? null, 'CONSULTAS', 'EP-12 primera sesión devuelve []');

    // Cerrar sesión V1 (0.00) para generar historial de 22 sesiones cerradas (para probar límite 20 de EP-12)
    $salidaRefV1 = (string)$pdo->query("SELECT fecha_hora_salida_referencia FROM sesion_caja WHERE id_sesion_caja = {$sesionV1ZeroId}")->fetchColumn();
    cerrarSesionFixture($pdo, $sesionV1ZeroId, 0.00, $salidaRefV1);

    // Crear 21 sesiones cerradas adicionales (algunas de V2) anteriores a la apertura actual
    $closedIds = [$sesionV1ZeroId];
    $baseDt = new DateTimeImmutable($salidaRefV1, new DateTimeZone('America/Lima'));
    $prevClosedId = $sesionV1ZeroId;
    $prevContado = 0.00;
    $prevCierre = $salidaRefV1;
    for ($k = 1; $k <= 21; $k++) {
        $vId = ($k % 2 === 0) ? $idVendB : $idVendA;
        $esperado = 100.00 + $k;
        $contado = ($k === 21) ? 80.00 : $esperado; // última cerrada con faltante de 41.00 (121 - 80)
        $apertura = $baseDt->modify("+{$k} minutes")->format('Y-m-d H:i:s');
        $cierre = $baseDt->modify("+{$k} minutes +30 seconds")->format('Y-m-d H:i:s');
        $salidaRef = $apertura;
        $diffApertura = round($esperado - $prevContado, 2);
        $stmtIns = $pdo->prepare(
            "INSERT INTO sesion_caja (
                id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
                hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
                es_suplencia, abierta_fuera_horario, fecha_hora_apertura,
                tiene_cierre_anterior, id_sesion_cierre_anterior, monto_referencia_cierre_anterior,
                fecha_hora_cierre_anterior_ref, nombre_turno_cierre_anterior_ref,
                saldo_inicial, diferencia_apertura, efectivo_esperado_actual, version_caja, estado
             ) VALUES (
                1, :vendedor, :turno, 'Mañana Test',
                '07:00:00', '15:00:00', :salida_ref,
                0, 0, :apertura,
                1, :prev_id, :prev_contado,
                :prev_cierre, 'Mañana Test',
                :saldo, :diff_ap, :saldo, 1, 'ABIERTA'
             )"
        );
        $stmtIns->execute([
            ':vendedor'     => $vId,
            ':turno'        => $turnoId,
            ':salida_ref'   => $salidaRef,
            ':apertura'     => $apertura,
            ':prev_id'      => $prevClosedId,
            ':prev_contado' => number_format($prevContado, 2, '.', ''),
            ':prev_cierre'  => $prevCierre,
            ':saldo'        => number_format($esperado, 2, '.', ''),
            ':diff_ap'      => number_format($diffApertura, 2, '.', ''),
        ]);
        $newId = (int)$pdo->lastInsertId();
        cerrarSesionFixture($pdo, $newId, $contado, $cierre);
        $closedIds[] = $newId;
        $prevClosedId = $newId;
        $prevContado = $contado;
        $prevCierre = $cierre;
    }

    // Vendedor 1 abre nueva sesión operativa con 200.00
    $openV1Op = httpRequest($serverUrl1, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p8_open_v1_oper_0002',
        'shift_id'             => $turnoId,
        'cash_counted'         => 200.00,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $openV1Op['http_code'], 'PRE', 'Apertura V1 operativa con 200.00 devuelve 201');
    $sesionActivaId = (int)($openV1Op['json']['data']['session_id'] ?? 0);

    // EP-12 default 10, límite 20, orden DESC, shortage, rechazo > 20
    $ep12Def = httpRequest($serverUrl1, 'GET', '/api/caja/turnos-anteriores', null, [], $cookieV1);
    assertSameVal(200, $ep12Def['http_code'], 'CONSULTAS', 'EP-12 default devuelve 200');
    assertSameVal(10, count($ep12Def['json']['data'] ?? []), 'CONSULTAS', 'EP-12 default devuelve 10 filas');
    assertSameVal($closedIds[21], $ep12Def['json']['data'][0]['id'] ?? null, 'CONSULTAS', 'EP-12 ordena por fecha_hora_cierre DESC (primero más reciente)');
    assertTrue((float)($ep12Def['json']['data'][0]['shortage'] ?? -1) === 41.0, 'CONSULTAS', 'EP-12 calcula shortage = max(expected - counted, 0)');

    $ep12Max = httpRequest($serverUrl1, 'GET', '/api/caja/turnos-anteriores?limit=20', null, [], $cookieV1);
    assertSameVal(20, count($ep12Max['json']['data'] ?? []), 'CONSULTAS', 'EP-12 limit=20 devuelve 20 filas');
    $ep12Ids = array_column($ep12Max['json']['data'] ?? [], 'id');
    assertTrue(!in_array($closedIds[0], $ep12Ids, true) && !in_array($closedIds[1], $ep12Ids, true), 'CONSULTAS', 'EP-12 excluye sesiones fuera de las últimas 20');

    $ep12Over = httpRequest($serverUrl1, 'GET', '/api/caja/turnos-anteriores?page_size=21', null, [], $cookieV1);
    assertSameVal(422, $ep12Over['http_code'], 'CONSULTAS', 'EP-12 page_size=21 devuelve 422');

    // =========================================================================
    // SECCIÓN C: EP-13 INGRESOS, PRECISIÓN, CLAVES DUPLICADAS Y ARQUEOS
    // =========================================================================
    $idArqueoV1 = insertarArqueoVigenteFixture($pdo, $sesionActivaId);

    // Subtipo 'manual' normalizado a 'manual_income'
    $ingManual = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_ing_manual_000001',
        'type'                 => 'manual',
        'amount'               => 25.50,
        'reason'               => 'Fondo adicional de caja',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $ingManual['http_code'], 'MOVIMIENTOS', 'EP-13 type=manual devuelve 201');
    assertSameVal('manual_income', $ingManual['json']['data']['movement']['type'] ?? null, 'MOVIMIENTOS', 'EP-13 normaliza manual -> manual_income');
    assertTrue((float)($ingManual['json']['data']['current_balance'] ?? 0) === 225.5, 'MOVIMIENTOS', 'EP-13 actualiza saldo a 225.50');
    assertSameVal(2, $ingManual['json']['data']['version'] ?? null, 'MOVIMIENTOS', 'EP-13 incrementa version_caja a 2');

    // Verificar invalidación del arqueo vigente previo
    $arqEstado = $pdo->query("SELECT estado, causa_cambio_estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArqueoV1}")->fetch();
    assertSameVal('DESACTUALIZADO', $arqEstado['estado'] ?? null, 'ARQUEOS', 'EP-13 desactualiza arqueo vigente previo');
    assertSameVal('OPERACION_CAJA_POSTERIOR', $arqEstado['causa_cambio_estado'] ?? null, 'ARQUEOS', 'EP-13 conserva causa OPERACION_CAJA_POSTERIOR');

    // Subtipo 'admin_injection' con admin id=1 (sin email)
    $ingInj = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_ing_inj_00000002',
        'type'                 => 'admin_injection',
        'amount'               => '50.00',
        'reason'               => 'Sencillo entregado para cambio',
        'administrator_id'     => 1,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $ingInj['http_code'], 'MOVIMIENTOS', 'EP-13 admin_injection (admin sin email) devuelve 201');
    $movInjId = (int)($ingInj['json']['data']['movement']['id'] ?? 0);
    assertTrue((float)($ingInj['json']['data']['current_balance'] ?? 0) === 275.5, 'MOVIMIENTOS', 'EP-13 admin_injection suma 50.00 -> 275.50');

    // Validaciones negativas EP-13
    $ingZero = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_ing_zero_00000003',
        'type'                 => 'manual_income',
        'amount'               => 0,
        'reason'               => 'Cero inválido',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $ingZero['http_code'], 'MOVIMIENTOS', 'EP-13 amount=0 devuelve 422');
    assertSameVal('INVALID_AMOUNT', $ingZero['json']['code'] ?? null, 'MOVIMIENTOS', 'EP-13 amount=0 código INVALID_AMOUNT');

    $ingEmptyReason = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_ing_reason_000004',
        'type'                 => 'manual_income',
        'amount'               => 10.00,
        'reason'               => '   ',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $ingEmptyReason['http_code'], 'MOVIMIENTOS', 'EP-13 motivo vacío devuelve 422');
    assertSameVal('MOTIVE_REQUIRED', $ingEmptyReason['json']['code'] ?? null, 'MOVIMIENTOS', 'EP-13 motivo vacío código MOTIVE_REQUIRED');

    $ingBadAdmin = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_ing_admin_0000005',
        'type'                 => 'admin_injection',
        'amount'               => 10.00,
        'reason'               => 'Admin vendedor inválido',
        'administrator_id'     => $idVendA, // vendedor, no admin
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $ingBadAdmin['http_code'], 'MOVIMIENTOS', 'EP-13 administrator_id no admin devuelve 422');
    assertSameVal('ADMINISTRATOR_REQUIRED', $ingBadAdmin['json']['code'] ?? null, 'MOVIMIENTOS', 'EP-13 administrator_id no admin código ADMINISTRATOR_REQUIRED');

    $ingIncompat = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_ing_incomp_000006',
        'type'                 => 'manual_income',
        'amount'               => 10.00,
        'reason'               => 'Campo incompatible',
        'administrator_id'     => 1,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $ingIncompat['http_code'], 'MOVIMIENTOS', 'EP-13 manual_income con administrator_id devuelve 422');

    // Precisión decimal y claves repetidas en JSON raíz
    $ingPrec = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', null, ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$csrfV1], $cookieV1, '{"referencia_solicitud":"p8_ing_prec_00000007","type":"manual_income","amount":1.00000000001,"reason":"Exceso decimales"}');
    assertSameVal(422, $ingPrec['http_code'], 'MOVIMIENTOS', 'EP-13 1.00000000001 devuelve 422 INVALID_AMOUNT');
    assertSameVal('INVALID_AMOUNT', $ingPrec['json']['code'] ?? null, 'MOVIMIENTOS', 'EP-13 código INVALID_AMOUNT para exceso decimales');

    $ingDup = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', null, ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$csrfV1], $cookieV1, '{"referencia_solicitud":"p8_ing_dup_000000008","type":"manual_income","amount":10,"\\u0061mount":99,"reason":"Clave repetida"}');
    assertSameVal(422, $ingDup['http_code'], 'MOVIMIENTOS', 'EP-13 clave amount repetida con escape Unicode devuelve 422');

    // Overflow DECIMAL(10,2)
    $ingOverflow = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_ing_over_00000009',
        'type'                 => 'manual_income',
        'amount'               => 99999990.00,
        'reason'               => 'Prueba de overflow',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(409, $ingOverflow['http_code'], 'MOVIMIENTOS', 'EP-13 overflow DECIMAL(10,2) devuelve 409');
    assertSameVal('BALANCE_OVERFLOW', $ingOverflow['json']['code'] ?? null, 'MOVIMIENTOS', 'EP-13 overflow código BALANCE_OVERFLOW');

    // =========================================================================
    // SECCIÓN D: EP-14 EGRESOS Y RETIRO OMITIDO
    // =========================================================================
    // cash_out y admin_withdrawal
    $egrOut = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud' => 'p8_egr_out_000000001',
        'type'                 => 'cash_out',
        'amount'               => 15.50,
        'reason'               => 'Compra de bolsas',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $egrOut['http_code'], 'MOVIMIENTOS', 'EP-14 cash_out devuelve 201');
    assertTrue((float)($egrOut['json']['data']['current_balance'] ?? 0) === 260.0, 'MOVIMIENTOS', 'EP-14 cash_out descuenta 15.50 -> 260.00');

    $egrAdmin = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud' => 'p8_egr_adm_000000002',
        'type'                 => 'admin_withdrawal',
        'amount'               => 30.00,
        'reason'               => 'Retiro a bóveda',
        'administrator_id'     => 9,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $egrAdmin['http_code'], 'MOVIMIENTOS', 'EP-14 admin_withdrawal devuelve 201');
    $movRetiroAdminId = (int)($egrAdmin['json']['data']['movement']['id'] ?? 0);
    assertTrue((float)($egrAdmin['json']['data']['current_balance'] ?? 0) === 230.0, 'MOVIMIENTOS', 'EP-14 admin_withdrawal descuenta 30.00 -> 230.00');

    // Egreso superior al saldo por 0.01 -> 409 INSUFFICIENT_BALANCE
    $egrInsuf = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud' => 'p8_egr_insuf_0000003',
        'type'                 => 'cash_out',
        'amount'               => 230.01,
        'reason'               => 'Supera por un céntimo',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(409, $egrInsuf['http_code'], 'MOVIMIENTOS', 'EP-14 supera saldo por 0.01 devuelve 409');
    assertSameVal('INSUFFICIENT_BALANCE', $egrInsuf['json']['code'] ?? null, 'MOVIMIENTOS', 'EP-14 código INSUFFICIENT_BALANCE');
    assertTrue((float)($egrInsuf['json']['data']['current_balance'] ?? 0) === 230.0, 'MOVIMIENTOS', 'EP-14 INSUFFICIENT_BALANCE devuelve data.current_balance');

    // Retiro omitido: validaciones de declaraciones
    $omitFalse = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud'                     => 'p8_omit_false_000001',
        'type'                                     => 'omitted_withdrawal',
        'amount'                                   => 300.00,
        'reason'                                   => 'Retiro olvidado',
        'administrator_id'                         => 9,
        'origin_session_id'                        => $closedIds[21],
        'declared_occurred_in_open_origin_session' => false,
        'confirmed_reflected_in_opening'           => true,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $omitFalse['http_code'], 'RETIRO_OMITIDO', 'Declaración false devuelve 422');
    assertSameVal('CONFIRMATION_REQUIRED', $omitFalse['json']['code'] ?? null, 'RETIRO_OMITIDO', 'Declaración false código CONFIRMATION_REQUIRED');

    $omitStrTrue = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud'                     => 'p8_omit_str_00000002',
        'type'                                     => 'omitted_withdrawal',
        'amount'                                   => 300.00,
        'reason'                                   => 'Retiro olvidado',
        'administrator_id'                         => 9,
        'origin_session_id'                        => $closedIds[21],
        'declared_occurred_in_open_origin_session' => 'true',
        'confirmed_reflected_in_opening'           => true,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $omitStrTrue['http_code'], 'RETIRO_OMITIDO', 'Declaración string "true" rechazada con 422 CONFIRMATION_REQUIRED');

    // Origen fuera del top 20 (closedIds[0]) o actual abierto -> 422 ORIGIN_TURN_REQUIRED
    $omitOutSet = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud'                     => 'p8_omit_out_00000003',
        'type'                                     => 'omitted_withdrawal',
        'amount'                                   => 300.00,
        'reason'                                   => 'Origen fuera de las 20 últimas',
        'administrator_id'                         => 9,
        'origin_session_id'                        => $closedIds[0],
        'declared_occurred_in_open_origin_session' => true,
        'confirmed_reflected_in_opening'           => true,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $omitOutSet['http_code'], 'RETIRO_OMITIDO', 'Origen fuera de top 20 devuelve 422');
    assertSameVal('ORIGIN_TURN_REQUIRED', $omitOutSet['json']['code'] ?? null, 'RETIRO_OMITIDO', 'Origen fuera de top 20 código ORIGIN_TURN_REQUIRED');

    $omitOpenOrigin = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud'                     => 'p8_omit_open_0000004',
        'type'                                     => 'omitted_withdrawal',
        'amount'                                   => 300.00,
        'reason'                                   => 'Origen abierto',
        'administrator_id'                         => 9,
        'origin_session_id'                        => $sesionActivaId,
        'declared_occurred_in_open_origin_session' => true,
        'confirmed_reflected_in_opening'           => true,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $omitOpenOrigin['http_code'], 'RETIRO_OMITIDO', 'Origen en sesión abierta devuelve 422 ORIGIN_TURN_REQUIRED');

    // Retiro omitido válido (500.00 > saldo actual 230.00, sobre origen de otro vendedor closedIds[20], con arqueo vigente)
    $idArqueoAntesOmit = insertarArqueoVigenteFixture($pdo, $sesionActivaId);
    $antesOrigen = $pdo->query("SELECT efectivo_esperado_cierre, efectivo_contado_cierre, diferencia_cierre, version_caja FROM sesion_caja WHERE id_sesion_caja = {$closedIds[20]}")->fetch();

    $omitOk = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud'                     => 'p8_omit_ok_000000005',
        'type'                                     => 'omitted_withdrawal',
        'amount'                                   => 500.00,
        'reason'                                   => 'Retiro omitido del turno anterior',
        'administrator_id'                         => 9,
        'origin_turn_id'                           => $closedIds[20],
        'declared_occurred_in_open_origin_session' => true,
        'confirm_previous_open'                    => true,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $omitOk['http_code'], 'RETIRO_OMITIDO', 'Retiro omitido válido (> saldo actual, otro vendedor) devuelve 201');
    $movOmitidoId = (int)($omitOk['json']['data']['movement']['id'] ?? 0);
    assertTrue((float)($omitOk['json']['data']['movement']['impact'] ?? -1) === 0.0, 'RETIRO_OMITIDO', 'Retiro omitido tiene impacto 0.0');
    assertTrue((float)($omitOk['json']['data']['current_balance'] ?? 0) === 230.0, 'RETIRO_OMITIDO', 'Retiro omitido mantiene saldo actual en 230.00');
    assertSameVal(6, $omitOk['json']['data']['version'] ?? null, 'RETIRO_OMITIDO', 'Retiro omitido incrementa version_caja a 6');

    $despuesOrigen = $pdo->query("SELECT efectivo_esperado_cierre, efectivo_contado_cierre, diferencia_cierre, version_caja FROM sesion_caja WHERE id_sesion_caja = {$closedIds[20]}")->fetch();
    assertSameVal($antesOrigen, $despuesOrigen, 'RETIRO_OMITIDO', 'Sesión cerrada de origen permanece 100% íntegra');

    $arqEstadoOmit = $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArqueoAntesOmit}")->fetchColumn();
    assertSameVal('DESACTUALIZADO', $arqEstadoOmit, 'RETIRO_OMITIDO', 'Retiro omitido impacto cero desactualiza el arqueo vigente');

    // =========================================================================
    // SECCIÓN E: EP-15 Y EP-16 CORRECCIONES VINCULADAS (INCLUYENDO A CERO Y DESDE CERO)
    // =========================================================================
    // Inyección original #2 ($movInjId) fue 50.00 -> corregir a 30.00 (-20.00) -> corregir a 35.00 (+5.00)
    $corr1 = httpRequest($serverUrl1, 'POST', '/api/caja/movimientos/correcciones', [
        'referencia_solicitud' => 'p8_corr_1_0000000001',
        'session_id'           => $sesionActivaId,
        'original_movement_id' => $movInjId,
        'corrected_amount'     => 30.00,
        'reason'               => 'Se digitó 50.00 pero fueron 30.00',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $corr1['http_code'], 'CORRECCIONES', 'Corrección 1 (50 -> 30) devuelve 201');
    assertTrue((float)($corr1['json']['data']['correction']['balance_effect'] ?? 0) === -20.0, 'CORRECCIONES', 'Corrección 1 balance_effect=-20.0');
    assertTrue((float)($corr1['json']['data']['current_balance'] ?? 0) === 210.0, 'CORRECCIONES', 'Corrección 1 saldo 230 -> 210');

    $corr2 = httpRequest($serverUrl1, 'POST', '/api/caja/movimientos/correcciones', [
        'referencia_solicitud' => 'p8_corr_2_0000000002',
        'original_movement_id' => $movInjId,
        'corrected_amount'     => 35.00,
        'reason'               => 'Apareció moneda adicional de 5.00',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $corr2['http_code'], 'CORRECCIONES', 'Corrección 2 (30 -> 35) devuelve 201');
    assertTrue((float)($corr2['json']['data']['correction']['previous_corrected_amount'] ?? 0) === 30.0, 'CORRECCIONES', 'Corrección 2 previous_corrected_amount=30.0');
    assertTrue((float)($corr2['json']['data']['correction']['balance_effect'] ?? 0) === 5.0, 'CORRECCIONES', 'Corrección 2 balance_effect=+5.0');
    assertTrue((float)($corr2['json']['data']['current_balance'] ?? 0) === 215.0, 'CORRECCIONES', 'Corrección 2 saldo 210 -> 215');

    // Corrección a 0.00 y posterior desde 0.00 a 10.00 (sobre $movRetiroAdminId de 30.00)
    $corrZero = httpRequest($serverUrl1, 'POST', '/api/caja/movimientos/correcciones', [
        'referencia_solicitud' => 'p8_corr_zero_0000003',
        'original_movement_id' => $movRetiroAdminId,
        'corrected_amount'     => 0.00,
        'reason'               => 'El retiro finalmente se anuló por error de registro',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $corrZero['http_code'], 'CORRECCIONES', 'Corrección de egreso 30.00 -> 0.00 devuelve 201 (migración 07)');
    assertTrue((float)($corrZero['json']['data']['correction']['balance_effect'] ?? 0) === 30.0, 'CORRECCIONES', 'Corrección egreso a 0.00 suma +30.00');
    assertTrue((float)($corrZero['json']['data']['current_balance'] ?? 0) === 245.0, 'CORRECCIONES', 'Saldo sube 215 -> 245');

    $corrFromZero = httpRequest($serverUrl1, 'POST', '/api/caja/movimientos/correcciones', [
        'referencia_solicitud' => 'p8_corr_from_zero_04',
        'original_movement_id' => $movRetiroAdminId,
        'corrected_amount'     => 10.00,
        'reason'               => 'Se confirma que sí hubo entrega de 10.00',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $corrFromZero['http_code'], 'CORRECCIONES', 'Corrección desde 0.00 -> 10.00 devuelve 201 (migración 07)');
    assertTrue((float)($corrFromZero['json']['data']['correction']['balance_effect'] ?? 0) === -10.0, 'CORRECCIONES', 'Corrección egreso 0 -> 10 resta -10.00');
    assertTrue((float)($corrFromZero['json']['data']['current_balance'] ?? 0) === 235.0, 'CORRECCIONES', 'Saldo baja 245 -> 235');

    // Verificar que el original conserva su monto inmutable
    $origMontoDb = (float)$pdo->query("SELECT monto FROM movimiento_caja WHERE id_movimiento_caja = {$movInjId}")->fetchColumn();
    assertTrue($origMontoDb === 50.0, 'CORRECCIONES', 'Movimiento original conserva monto inmutable 50.00');

    // Importe sin cambio -> 422 NO_CORRECTION_CHANGE
    $corrSame = httpRequest($serverUrl1, 'POST', '/api/caja/movimientos/correcciones', [
        'referencia_solicitud' => 'p8_corr_same_0000005',
        'original_movement_id' => $movRetiroAdminId,
        'corrected_amount'     => 10.00,
        'reason'               => 'Sin cambio real',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(422, $corrSame['http_code'], 'CORRECCIONES', 'Corrección sin cambio devuelve 422');
    assertSameVal('NO_CORRECTION_CHANGE', $corrSame['json']['code'] ?? null, 'CORRECCIONES', 'Código NO_CORRECTION_CHANGE');

    // No corregible (retiro omitido y registro de corrección) -> 409 CORRECTION_NOT_ALLOWED
    $corrOmit = httpRequest($serverUrl1, 'POST', '/api/caja/movimientos/correcciones', [
        'referencia_solicitud' => 'p8_corr_omit_0000006',
        'original_movement_id' => $movOmitidoId,
        'corrected_amount'     => 250.00,
        'reason'               => 'Intento sobre retiro omitido',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(409, $corrOmit['http_code'], 'CORRECCIONES', 'Corregir retiro omitido devuelve 409 CORRECTION_NOT_ALLOWED');
    assertSameVal('CORRECTION_NOT_ALLOWED', $corrOmit['json']['code'] ?? null, 'CORRECCIONES', 'Código CORRECTION_NOT_ALLOWED');

    // Saldo insuficiente por corrección (egreso de 10.00 -> corregir a 300.00, efecto -290.00 > 235.00)
    $corrInsuf = httpRequest($serverUrl1, 'POST', '/api/caja/movimientos/correcciones', [
        'referencia_solicitud' => 'p8_corr_insuf_000007',
        'original_movement_id' => $movRetiroAdminId,
        'corrected_amount'     => 300.00,
        'reason'               => 'Excede saldo disponible',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(409, $corrInsuf['http_code'], 'CORRECCIONES', 'Corrección que deja saldo negativo devuelve 409 INSUFFICIENT_BALANCE');
    assertSameVal('INSUFFICIENT_BALANCE', $corrInsuf['json']['code'] ?? null, 'CORRECCIONES', 'Código INSUFFICIENT_BALANCE en corrección');

    // EP-15 lista movimientos corregibles (excluye omitido y correcciones) y muestra current_amount
    $ep15 = httpRequest($serverUrl1, 'GET', '/api/caja/movimientos/corregibles?session_id=' . $sesionActivaId, null, [], $cookieV1);
    assertSameVal(200, $ep15['http_code'], 'CONSULTAS', 'EP-15 devuelve 200');
    $ep15Movs = $ep15['json']['data']['movements'] ?? [];
    $ep15Types = array_column($ep15Movs, 'type');
    assertTrue(!in_array('omitted_withdrawal', $ep15Types, true) && !in_array('correction', $ep15Types, true), 'CONSULTAS', 'EP-15 excluye omitted_withdrawal y correction');
    assertSameVal(4, count($ep15['json']['data']['corrections'] ?? []), 'CONSULTAS', 'EP-15 devuelve las 4 correcciones de la sesión');

    // EP-15 con session_id distinto al activo -> 403 SESSION_NOT_OWNED
    $ep15Otro = httpRequest($serverUrl1, 'GET', '/api/caja/movimientos/corregibles?sesion_id=' . $closedIds[21], null, [], $cookieV1);
    assertSameVal(403, $ep15Otro['http_code'], 'SEGURIDAD', 'EP-15 con session_id ajena/cerrada devuelve 403 SESSION_NOT_OWNED');

    // =========================================================================
    // SECCIÓN F: CONCURRENCIA MULTI-PROCESO REAL E IDEMPOTENCIA
    // =========================================================================
    // Dejar saldo exacto en 100.00 mediante egreso de 135.00 (235.00 - 135.00 = 100.00)
    $egrPrep = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud' => 'p8_prep_100_00000001',
        'type'                 => 'cash_out',
        'amount'               => 135.00,
        'reason'               => 'Preparación saldo 100.00',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertTrue((float)($egrPrep['json']['data']['current_balance'] ?? 0) === 100.0, 'CONCURRENCIA', 'Saldo preparado en 100.00');

    // 1. Dos egresos simultáneos de 80.00 contra saldo 100.00 en 2 servidores independientes
    $workerPath = $rootDir . '/tests/sprint3/worker_concurrencia_paso8.php';
    $resEgresos = runParallelHttpWorkers($phpBin, $workerPath, [
        [
            'base_url'    => $serverUrl1,
            'cookie_file' => $cookieV1,
            'csrf'        => (string)$csrfV1,
            'path'        => '/api/caja/egresos',
            'payload'     => [
                'referencia_solicitud' => 'p8_conc_egr_A_000001',
                'type'                 => 'cash_out',
                'amount'               => 80.00,
                'reason'               => 'Egreso concurrente A',
            ],
        ],
        [
            'base_url'    => $serverUrl2,
            'cookie_file' => $cookieV1,
            'csrf'        => (string)$csrfV1,
            'path'        => '/api/caja/egresos',
            'payload'     => [
                'referencia_solicitud' => 'p8_conc_egr_B_000002',
                'type'                 => 'cash_out',
                'amount'               => 80.00,
                'reason'               => 'Egreso concurrente B',
            ],
        ],
    ]);
    $codesEgr = [$resEgresos[0]['json']['http_code'] ?? 0, $resEgresos[1]['json']['http_code'] ?? 0];
    sort($codesEgr);
    assertSameVal([201, 409], $codesEgr, 'CONCURRENCIA', 'Dos egresos concurrentes de 80.00 contra 100.00: uno 201 y otro 409 INSUFFICIENT_BALANCE');
    $saldoPostConc = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$sesionActivaId}")->fetchColumn();
    assertTrue($saldoPostConc === 20.0, 'CONCURRENCIA', 'Saldo final tras egresos concurrentes es exactamente 20.00');

    // Egreso exactamente igual al saldo restante (20.00) -> deja 0.00
    $egrExacto = httpRequest($serverUrl1, 'POST', '/api/caja/egresos', [
        'referencia_solicitud' => 'p8_egr_exact_zero_01',
        'type'                 => 'cash_out',
        'amount'               => 20.00,
        'reason'               => 'Retiro total saldo restante',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $egrExacto['http_code'], 'MOVIMIENTOS', 'Egreso exactamente igual al saldo (20.00) devuelve 201');
    assertTrue((float)($egrExacto['json']['data']['current_balance'] ?? -1) === 0.0, 'MOVIMIENTOS', 'Egreso igual al saldo deja current_balance = 0.0');

    // 2. Dos correcciones concurrentes sobre el mismo original ($movInjId vigente en 35.00 -> ambos intentan cambiar a 40.00)
    $resCorrSame = runParallelHttpWorkers($phpBin, $workerPath, [
        [
            'base_url'    => $serverUrl1,
            'cookie_file' => $cookieV1,
            'csrf'        => (string)$csrfV1,
            'path'        => '/api/caja/movimientos/correcciones',
            'payload'     => [
                'referencia_solicitud' => 'p8_conc_corr_A_00001',
                'original_movement_id' => $movInjId,
                'corrected_amount'     => 40.00,
                'reason'               => 'Corrección concurrente A',
            ],
        ],
        [
            'base_url'    => $serverUrl2,
            'cookie_file' => $cookieV1,
            'csrf'        => (string)$csrfV1,
            'path'        => '/api/caja/movimientos/correcciones',
            'payload'     => [
                'referencia_solicitud' => 'p8_conc_corr_B_00002',
                'original_movement_id' => $movInjId,
                'corrected_amount'     => 40.00,
                'reason'               => 'Corrección concurrente B',
            ],
        ],
    ]);
    $codesCorr = [$resCorrSame[0]['json']['http_code'] ?? 0, $resCorrSame[1]['json']['http_code'] ?? 0];
    sort($codesCorr);
    assertSameVal([201, 422], $codesCorr, 'CONCURRENCIA', 'Dos correcciones concurrentes al mismo monto: una 201 y otra 422 NO_CORRECTION_CHANGE');

    // 3. Reintentos simultáneos con la misma referencia idempotente
    $resReplaySim = runParallelHttpWorkers($phpBin, $workerPath, [
        [
            'base_url'    => $serverUrl1,
            'cookie_file' => $cookieV1,
            'csrf'        => (string)$csrfV1,
            'path'        => '/api/caja/ingresos',
            'payload'     => [
                'referencia_solicitud' => 'p8_conc_replay_00001',
                'type'                 => 'manual_income',
                'amount'               => 15.00,
                'reason'               => 'Ingreso idempotente simultáneo',
            ],
        ],
        [
            'base_url'    => $serverUrl2,
            'cookie_file' => $cookieV1,
            'csrf'        => (string)$csrfV1,
            'path'        => '/api/caja/ingresos',
            'payload'     => [
                'referencia_solicitud' => 'p8_conc_replay_00001',
                'type'                 => 'manual_income',
                'amount'               => 15.00,
                'reason'               => 'Ingreso idempotente simultáneo',
            ],
        ],
    ]);
    $c0 = $resReplaySim[0]['json']['http_code'] ?? 0;
    $c1 = $resReplaySim[1]['json']['http_code'] ?? 0;
    $saldoPostReplaySim = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$sesionActivaId}")->fetchColumn();
    assertTrue(in_array(201, [$c0, $c1], true) && $saldoPostReplaySim === 20.0, 'CONCURRENCIA', 'Reintentos simultáneos confirman exactamente una vez (saldo 5.00 + 15.00 = 20.00)');

    // 4. Replay posterior al cierre y después de abrir otra sesión
    $versionAntesReplay = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$sesionActivaId}")->fetchColumn();
    $idArqReplay = insertarArqueoVigenteFixture($pdo, $sesionActivaId);

    $replayInmediato = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_conc_replay_00001',
        'type'                 => 'manual_income',
        'amount'               => 15.00,
        'reason'               => 'Ingreso idempotente simultáneo',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(200, $replayInmediato['http_code'], 'IDEMPOTENCIA', 'Replay devuelve 200');
    assertSameVal(true, $replayInmediato['json']['idempotent_replay'] ?? null, 'IDEMPOTENCIA', 'Replay idempotent_replay=true');
    assertSameVal('OPERATION_ALREADY_CONFIRMED', $replayInmediato['json']['code'] ?? null, 'IDEMPOTENCIA', 'Replay código OPERATION_ALREADY_CONFIRMED');
    $versionDespuesReplay = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$sesionActivaId}")->fetchColumn();
    $arqEstadoReplay = $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArqReplay}")->fetchColumn();
    assertSameVal($versionAntesReplay, $versionDespuesReplay, 'IDEMPOTENCIA', 'Replay NO incrementa version_caja');
    assertSameVal('VIGENTE', $arqEstadoReplay, 'IDEMPOTENCIA', 'Replay NO invalida el arqueo vigente');

    // Conflicto de payload con misma referencia -> 409 IDEMPOTENCY_PAYLOAD_MISMATCH
    $mismatch = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_conc_replay_00001',
        'type'                 => 'manual_income',
        'amount'               => 99.00,
        'reason'               => 'Monto cambiado',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(409, $mismatch['http_code'], 'IDEMPOTENCIA', 'Misma referencia con distinto payload devuelve 409');
    assertSameVal('IDEMPOTENCY_PAYLOAD_MISMATCH', $mismatch['json']['code'] ?? null, 'IDEMPOTENCIA', 'Código IDEMPOTENCY_PAYLOAD_MISMATCH');

    // Cerrar sesión activa y abrir otra sesión nueva para V1, luego hacer replay de 'p8_conc_replay_00001'
    cerrarSesionFixture($pdo, $sesionActivaId, 20.00);
    $openNueva = httpRequest($serverUrl1, 'POST', '/api/caja/abrir', [
        'referencia_solicitud' => 'p8_open_v1_nueva_0003',
        'shift_id'             => $turnoId,
        'cash_counted'         => 50.00,
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(201, $openNueva['http_code'], 'IDEMPOTENCIA', 'Nueva sesión abierta tras cierre con 50.00');
    $nuevaSesionId = (int)($openNueva['json']['data']['session_id'] ?? 0);

    $replayPostCierre = httpRequest($serverUrl1, 'POST', '/api/caja/ingresos', [
        'referencia_solicitud' => 'p8_conc_replay_00001',
        'type'                 => 'manual_income',
        'amount'               => 15.00,
        'reason'               => 'Ingreso idempotente simultáneo',
    ], ['X-CSRF-Token' => (string)$csrfV1], $cookieV1);
    assertSameVal(200, $replayPostCierre['http_code'], 'IDEMPOTENCIA', 'Replay posterior al cierre y nueva apertura devuelve 200');
    assertSameVal($sesionActivaId, $replayPostCierre['json']['data']['movement']['session_id'] ?? null, 'IDEMPOTENCIA', 'Replay devuelve session_id de la sesión original');
    $saldoNuevaSesion = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$nuevaSesionId}")->fetchColumn();
    assertTrue($saldoNuevaSesion === 50.0, 'IDEMPOTENCIA', 'Replay NO altera el saldo de la nueva sesión abierta');

    // =========================================================================
    // SECCIÓN G: CONSERVACIÓN DE DATOS ORIGINALES
    // =========================================================================
    $ventasNull = (int)$pdo->query('SELECT COUNT(*) FROM venta WHERE id_sesion_caja IS NULL')->fetchColumn();
    $ventasOrig = (int)$pdoOrig->query('SELECT COUNT(*) FROM venta')->fetchColumn();
    assertSameVal($ventasOrig, $ventasNull, 'CONSERVACION', 'Las 250 ventas históricas conservan id_sesion_caja = NULL');

    foreach (['venta', 'compra', 'producto', 'kardex'] as $tbl) {
        $cntTest = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        $cntOrig = (int)$pdoOrig->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        assertSameVal($cntOrig, $cntTest, 'CONSERVACION', "Tabla {$tbl} conserva conteo original ({$cntOrig})");
    }

    echo "\n=== RESUMEN PASO 8 ===\n";
    foreach ($categories as $cat => $st) {
        echo sprintf("  - %-15s : %d passed, %d failed\n", $cat, $st['passed'], $st['failed']);
    }
    echo sprintf("TOTAL: %d / %d aserciones superadas.\n", $passed, $passed + $failed);
} finally {
    foreach ([$serverProc1, $serverProc2] as $proc) {
        if (is_resource($proc)) {
            $status = proc_get_status($proc);
            if (!empty($status['pid']) && PHP_OS_FAMILY === 'Windows') {
                exec('taskkill /F /T /PID ' . (int)$status['pid'] . ' >NUL 2>&1');
            }
            @proc_terminate($proc);
            @proc_close($proc);
        }
    }
    if (is_dir($tempDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($tempDir);
    }
}

exit($failed === 0 ? 0 : 1);
