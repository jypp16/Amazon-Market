<?php
declare(strict_types=1);

/**
 * Batería integral de aceptación para el Paso 12 del Sprint 3
 * (EP-22, EP-23A, EP-23B, EP-23C, EP-24A, EP-24B).
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

$httpPort1 = (int)(getenv('TEST_HTTP_PORT') ?: 8098);
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

function recordResult(string $cat, string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $categories;
    if (!isset($categories[$cat])) {
        $categories[$cat] = ['passed' => 0, 'failed' => 0];
    }
    if ($ok) {
        $passed++;
        $categories[$cat]['passed']++;
        echo "[PASS] [{$cat}] {$label}\n";
    } else {
        $failed++;
        $categories[$cat]['failed']++;
        echo "[FAIL] [{$cat}] {$label}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
    }
}

function assertSameVal($expected, $actual, string $cat, string $label): void
{
    $ok = ($expected === $actual);
    $detail = $ok ? '' : ('esperado=' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ' actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    recordResult($cat, $label, $ok, $detail);
}

function assertTrue(bool $cond, string $cat, string $label, string $detail = ''): void
{
    recordResult($cat, $label, $cond, $detail);
}

function startPhpServer(string $phpBin, string $rootDir, int $port, string $dbName, string $sessionDir): array
{
    $router = $rootDir . '/tests/sprint3/server_router_sprint3.php';
    $cmd = sprintf('"%s" -S 127.0.0.1:%d "%s"', $phpBin, $port, $router);
    $env = array_merge($_ENV, getenv(), [
        'DB_NAME'          => $dbName,
        'TEST_DB_NAME'     => $dbName,
        'TEST_SESSION_DIR' => $sessionDir,
    ]);
    $desc = [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']];
    $proc = proc_open($cmd, $desc, $pipes, $rootDir, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException("No se pudo iniciar servidor PHP en puerto {$port}");
    }
    $baseUrl = "http://127.0.0.1:{$port}";
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(100000);
        $ch = curl_init($baseUrl . '/__test_env_verify');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $raw !== false) {
            $decoded = json_decode($raw, true);
            if (($decoded['effective_db'] ?? '') === $dbName) {
                $ready = true;
                break;
            }
        }
    }
    if (!$ready) {
        stopPhpServer(['proc' => $proc, 'pipes' => $pipes]);
        throw new RuntimeException("Servidor PHP en {$baseUrl} no respondió con DB_NAME={$dbName}");
    }
    return ['proc' => $proc, 'pipes' => $pipes, 'baseUrl' => $baseUrl];
}

function stopPhpServer(?array $srv): void
{
    if ($srv === null) {
        return;
    }
    if (!empty($srv['pipes'])) {
        foreach ($srv['pipes'] as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
    }
    if (!empty($srv['proc']) && is_resource($srv['proc'])) {
        $status = proc_get_status($srv['proc']);
        if (!empty($status['pid'])) {
            @exec("taskkill /F /T /PID " . (int)$status['pid'] . " 2>NUL");
        }
        @proc_terminate($srv['proc']);
        @proc_close($srv['proc']);
    }
}

function httpRequest(
    string $method,
    string $url,
    ?string $cookieFile = null,
    $body = null,
    array $headers = []
): array {
    $ch = curl_init($url);
    $hdrs = $headers;
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 20,
    ];
    if ($cookieFile !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if ($body !== null) {
        $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $opts[CURLOPT_POSTFIELDS] = $payload;
        $hdrs[] = 'Content-Type: application/json';
    }
    if (!empty($hdrs)) {
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [
        'status' => $code,
        'raw'    => $raw === false ? '' : $raw,
        'json'   => $raw !== false ? json_decode($raw, true) : null,
        'error'  => $err,
    ];
}

function loginViaHttp(string $baseUrl, string $cookieFile, string $username, string $password): array
{
    $authPage = httpRequest('GET', $baseUrl . '/Auth', $cookieFile);
    $csrf = '';
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i', (string)$authPage['raw'], $m)) {
        $csrf = $m[1];
    }
    $ch = curl_init($baseUrl . '/Auth/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'username'   => $username,
            'password'   => $password,
            'csrf_token' => $csrf,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $home = httpRequest('GET', $baseUrl . '/Dashboard', $cookieFile);
    $csrf2 = $csrf;
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/i', (string)$home['raw'], $m2)) {
        $csrf2 = $m2[1];
    } elseif (preg_match('/csrf_token["\']?\s*[:=]\s*["\']([a-f0-9]{64})["\']/i', (string)$home['raw'], $m2)) {
        $csrf2 = $m2[1];
    }
    return ['status' => $code, 'csrf' => $csrf2];
}

$sessionDir = sys_get_temp_dir() . '\\am_p12_sess_' . uniqid();
@mkdir($sessionDir, 0777, true);
$cookieAdmin = tempnam(sys_get_temp_dir(), 'p12_adm_');
$cookieSeller1 = tempnam(sys_get_temp_dir(), 'p12_s1_');
$cookieSeller2 = tempnam(sys_get_temp_dir(), 'p12_s2_');
$srv1 = null;

try {
    $pdo = \Libraries\Core\Conexion::getInstance()->conect();
    $srv1 = startPhpServer($phpBin, $rootDir, $httpPort1, $dbName, $sessionDir);
    $baseUrl = $srv1['baseUrl'];

    // Crear usuarios aislados de prueba en copia nueva
    $passPlain = 'ClavePrueba123!';
    $passHash = password_hash($passPlain, PASSWORD_BCRYPT);

    $pdo->prepare('DELETE FROM usuario WHERE email IN (?, ?, ?)')->execute([
        'p12.admin@test.local',
        'p12.vendedor1@test.local',
        'p12.vendedor2@test.local',
    ]);

    $stmtInsUser = $pdo->prepare(
        'INSERT INTO usuario (id_rol, username, nombre, dni, telefono, direccion, email, password_hash, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $stmtInsUser->execute([1, 'admp12', 'Admin Paso 12', '78888001', '988880001', 'Dir', 'p12.admin@test.local', $passHash]);
    $adminId = (int)$pdo->lastInsertId();

    $stmtInsUser->execute([2, 'vndp12a', 'Vendedor Uno P12', '78888002', '988880002', 'Dir', 'p12.vendedor1@test.local', $passHash]);
    $seller1Id = (int)$pdo->lastInsertId();

    $stmtInsUser->execute([2, 'vndp12b', 'Vendedor Dos P12', '78888003', '988880003', 'Dir', 'p12.vendedor2@test.local', $passHash]);
    $seller2Id = (int)$pdo->lastInsertId();

    // Asegurar que exista un turno vigente 00:00 - 23:59 para pruebas sin dependencia horaria
    $pdo->prepare("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES ('Turno P12 Completo', '00:00:00', '23:59:00', 1)")->execute();
    $shiftId = (int)$pdo->lastInsertId();

    // Login de los 3 usuarios
    $resLoginAdm = loginViaHttp($baseUrl, $cookieAdmin, 'admp12', $passPlain);
    assertTrue($resLoginAdm['status'] >= 200 && $resLoginAdm['status'] < 400 && $resLoginAdm['csrf'] !== '', 'AUTH', 'Login admin P12');
    $resLoginS1 = loginViaHttp($baseUrl, $cookieSeller1, 'vndp12a', $passPlain);
    assertTrue($resLoginS1['status'] >= 200 && $resLoginS1['status'] < 400 && $resLoginS1['csrf'] !== '', 'AUTH', 'Login vendedor 1 P12');
    $resLoginS2 = loginViaHttp($baseUrl, $cookieSeller2, 'vndp12b', $passPlain);
    assertTrue($resLoginS2['status'] >= 200 && $resLoginS2['status'] < 400 && $resLoginS2['csrf'] !== '', 'AUTH', 'Login vendedor 2 P12');
    $csrfS1 = ['X-CSRF-TOKEN: ' . $resLoginS1['csrf']];
    $csrfS2 = ['X-CSRF-TOKEN: ' . $resLoginS2['csrf']];

    // 1. RBAC y autenticación
    $endpointsAdmin = [
        '/api/caja/consulta/vendedores',
        '/api/caja/consulta/sesiones',
        '/api/caja/consulta?session_id=1',
        '/api/reportes/cierres-caja',
        '/api/reportes/cierres-caja/1',
    ];
    foreach ($endpointsAdmin as $ep) {
        $unauth = httpRequest('GET', $baseUrl . $ep);
        assertSameVal(401, $unauth['status'], 'RBAC', "Sin sesión {$ep} -> 401");

        $asSeller = httpRequest('GET', $baseUrl . $ep, $cookieSeller1);
        assertSameVal(403, $asSeller['status'], 'RBAC', "Vendedor en {$ep} -> 403");
    }

    $unauthEp22 = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa');
    assertSameVal(401, $unauthEp22['status'], 'RBAC', 'Sin sesión EP-22 -> 401');

    $asAdminEp22 = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa', $cookieAdmin);
    assertSameVal(403, $asAdminEp22['status'], 'RBAC', 'Admin en EP-22 -> 403');

    // 2. EP-22 sin sesión abierta
    $ep22Empty = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa', $cookieSeller1);
    assertSameVal(200, $ep22Empty['status'], 'EP-22', 'Sin sesión activa responde 200');
    assertSameVal(false, $ep22Empty['json']['data']['has_active_session'] ?? null, 'EP-22', 'has_active_session=false');
    assertTrue(
        is_array($ep22Empty['json']['data'] ?? null)
        && array_key_exists('session', $ep22Empty['json']['data'])
        && $ep22Empty['json']['data']['session'] === null,
        'EP-22',
        'session=null sin sesión abierta'
    );

    // 3. Abrir sesión 1 con Vendedor 1 y ejecutar movimientos variados
    $open1 = httpRequest('POST', $baseUrl . '/api/caja/abrir', $cookieSeller1, [
        'shift_id'     => $shiftId,
        'cash_counted' => 100.00,
        'request_id'   => 'p12-open-1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $open1['status'], 'SETUP', 'Apertura sesión 1 HTTP 201');
    $sesion1Id = (int)($open1['json']['data']['session_id'] ?? 0);
    assertTrue($sesion1Id > 0, 'SETUP', 'Sesión 1 creada con ID > 0');

    // Arqueo previo al cierre de sesión 1
    $arq1 = httpRequest('POST', $baseUrl . '/api/caja/arqueo', $cookieSeller1, [
        'counted'     => 100.00,
        'observation' => 'Arqueo de cierre sesion 1',
        'version'     => 1,
        'request_id'  => 'p12-arq1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $arq1['status'], 'SETUP', 'Arqueo sesión 1 HTTP 201');
    $arq1Id = (int)($arq1['json']['data']['arqueo']['id'] ?? 0);

    // Cerrar sesión 1 para que exista un cierre anterior elegible para retiro omitido en sesión 2
    $close1 = httpRequest('POST', $baseUrl . '/api/caja/cerrar', $cookieSeller1, [
        'arqueo_id'        => $arq1Id,
        'admin_email'      => 'p12.admin@test.local',
        'admin_password'   => $passPlain,
        'exception_reason' => 'Cierre temprano controlado sesion 1',
        'request_id'       => 'p12-close-1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(200, $close1['status'], 'SETUP', 'Cierre anticipado sesión 1 HTTP 200');
    sleep(1);

    // Abrir sesión 2 con Vendedor 1
    $open2 = httpRequest('POST', $baseUrl . '/api/caja/abrir', $cookieSeller1, [
        'shift_id'     => $shiftId,
        'cash_counted' => 150.00,
        'request_id'   => 'p12-open-2-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $open2['status'], 'SETUP', 'Apertura sesión 2 HTTP 201');
    $sesion2Id = (int)($open2['json']['data']['session_id'] ?? 0);

    // Registrar ingreso manual, inyección admin, egreso, retiro omitido y corrección
    $refLarga = str_repeat('R', 220);
    $ing1 = httpRequest('POST', $baseUrl . '/api/caja/ingresos', $cookieSeller1, [
        'type'       => 'manual_income',
        'amount'     => 25.50,
        'reason'     => 'Ingreso de sencillo',
        'request_id' => 'p12-ing1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $ing1['status'], 'SETUP', 'Ingreso manual HTTP 201');
    $ing1Id = (int)($ing1['json']['data']['movement']['id'] ?? 0);

    $inj1 = httpRequest('POST', $baseUrl . '/api/caja/ingresos', $cookieSeller1, [
        'type'             => 'admin_injection',
        'amount'           => 40.00,
        'reason'           => $refLarga,
        'administrator_id' => $adminId,
        'request_id'       => 'p12-inj1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $inj1['status'], 'SETUP', 'Inyección admin HTTP 201');

    $egr1 = httpRequest('POST', $baseUrl . '/api/caja/egresos', $cookieSeller1, [
        'type'       => 'cash_out',
        'amount'     => 10.00,
        'reason'     => 'Compra bolsas',
        'request_id' => 'p12-egr1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $egr1['status'], 'SETUP', 'Salida caja HTTP 201');

    $omit1 = httpRequest('POST', $baseUrl . '/api/caja/egresos', $cookieSeller1, [
        'type'                                     => 'omitted_withdrawal',
        'amount'                                   => 15.00,
        'reason'                                   => 'Retiro omitido del turno anterior',
        'origin_session_id'                        => $sesion1Id,
        'administrator_id'                         => $adminId,
        'declared_occurred_in_open_origin_session' => true,
        'confirmed_reflected_in_opening'           => true,
        'request_id'                               => 'p12-omit1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $omit1['status'], 'SETUP', 'Retiro omitido HTTP 201');

    // Corrección del ingreso manual de 25.50 -> 30.50 (+5.00)
    $corr1 = httpRequest('POST', $baseUrl . '/api/caja/movimientos/correcciones', $cookieSeller1, [
        'original_movement_id' => $ing1Id,
        'corrected_amount'     => 30.50,
        'reason'               => 'Ajuste de conteo en ingreso',
        'request_id'           => 'p12-corr1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $corr1['status'], 'SETUP', 'Corrección movimiento HTTP 201');

    // Guardar un arqueo en sesión 2
    $arq2 = httpRequest('POST', $baseUrl . '/api/caja/arqueo', $cookieSeller1, [
        'counted'     => 210.50,
        'observation' => 'Cierre sesion 2 con retiros omitidos',
        'version'     => 6,
        'request_id'  => 'p12-arq2-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $arq2['status'], 'SETUP', 'Arqueo sesión 2 HTTP 201');
    $arq2Id = (int)($arq2['json']['data']['arqueo']['id'] ?? 0);
    assertSameVal(201, $arq2['status'], 'SETUP', 'Arqueo sesión 2 HTTP 201');

    // EP-22 con Vendedor 2 (no propietario) -> 409
    $ep22Other = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa', $cookieSeller2);
    assertSameVal(409, $ep22Other['status'], 'EP-22', 'Vendedor no propietario -> 409 CASH_SESSION_OTHER_SELLER');
    assertSameVal('CASH_SESSION_OTHER_SELLER', $ep22Other['json']['code'] ?? null, 'EP-22', 'Código 409 exacto');

    // EP-22 con Vendedor 1 (paginado page=1&page_size=2)
    $ep22Page1 = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page=1&page_size=2', $cookieSeller1);
    assertSameVal(200, $ep22Page1['status'], 'EP-22', 'Vendedor propietario EP-22 -> 200');
    $d22 = $ep22Page1['json']['data'] ?? [];
    assertSameVal(true, $d22['has_active_session'] ?? null, 'EP-22', 'has_active_session=true');
    assertSameVal($sesion2Id, $d22['session']['session_id'] ?? null, 'EP-22', 'session_id coincide');
    assertSameVal(150.0, (float)($d22['summary']['opening_balance'] ?? -1), 'EP-22', 'summary.opening_balance = 150.0');
    assertSameVal(210.5, (float)($d22['summary']['current_balance'] ?? -1), 'EP-22', 'summary.current_balance = 210.5');
    assertSameVal(1, count($d22['arqueos'] ?? []), 'EP-22', 'Incluye 1 arqueo');
    assertSameVal(2, count($d22['movements'] ?? []), 'EP-22', 'Página 1 trae 2 movimientos');
    assertSameVal(5, $d22['pagination']['total'] ?? -1, 'EP-22', 'Total movimientos = 5');
    assertSameVal(3, $d22['pagination']['total_pages'] ?? -1, 'EP-22', 'Total páginas = 3');

    // EP-22 página 2 completa para verificar current_amount, reference no truncada, origin_session_id, correction_id
    $ep22All = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page=1&page_size=20', $cookieSeller1);
    $movsAll = $ep22All['json']['data']['movements'] ?? [];
    assertSameVal(5, count($movsAll), 'EP-22', 'Todos los 5 movimientos recuperados');
    $foundIng = null;
    $foundInj = null;
    $foundOmit = null;
    $foundCorr = null;
    foreach ($movsAll as $m) {
        if ((int)$m['id'] === $ing1Id) $foundIng = $m;
        if (($m['type'] ?? '') === 'admin_injection') $foundInj = $m;
        if (($m['type'] ?? '') === 'omitted_withdrawal') $foundOmit = $m;
        if (($m['type'] ?? '') === 'correction') $foundCorr = $m;
    }
    assertSameVal(30.5, (float)($foundIng['current_amount'] ?? -1), 'EP-22', 'Movimiento corregido expone current_amount=30.5');
    assertTrue(strlen((string)($foundInj['reference'] ?? '')) >= 220, 'EP-22', 'Referencia larga no truncada en consulta');
    assertSameVal($sesion1Id, $foundOmit['origin_session_id'] ?? null, 'EP-22', 'omitted_withdrawal expone origin_session_id');
    assertSameVal($ing1Id, $foundCorr['original_movement_id'] ?? null, 'EP-22', 'correction expone original_movement_id');
    assertTrue(($foundCorr['correction_id'] ?? 0) > 0, 'EP-22', 'correction expone correction_id');

    // EP-22 paginación inválida -> 422
    $ep22BadPage = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page=0', $cookieSeller1);
    assertSameVal(422, $ep22BadPage['status'], 'EP-22', 'page=0 -> 422');
    $ep22BadSize = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page_size=101', $cookieSeller1);
    assertSameVal(422, $ep22BadSize['status'], 'EP-22', 'page_size=101 -> 422');

    // 4. EP-23A: Vendedores para consulta
    $ep23a = httpRequest('GET', $baseUrl . '/api/caja/consulta/vendedores', $cookieAdmin);
    assertSameVal(200, $ep23a['status'], 'EP-23A', 'Admin consulta vendedores -> 200');
    $sellersList = $ep23a['json']['data']['sellers'] ?? [];
    $sellerIds = array_column($sellersList, 'id');
    assertTrue(in_array($seller1Id, $sellerIds, true) && in_array($seller2Id, $sellerIds, true), 'EP-23A', 'Incluye vendedores activos de prueba');

    // 5. EP-23B: Sesiones por fecha y vendedor
    $hoyLima = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d');
    $ep23b = httpRequest('GET', $baseUrl . "/api/caja/consulta/sesiones?date={$hoyLima}&seller_id={$seller1Id}", $cookieAdmin);
    assertSameVal(200, $ep23b['status'], 'EP-23B', 'Admin consulta sesiones por fecha y vendedor -> 200');
    $sessList = $ep23b['json']['data']['sessions'] ?? [];
    assertTrue(count($sessList) >= 2, 'EP-23B', 'Lista sesiones abiertas y cerradas del día');
    $ep23bBadDate = httpRequest('GET', $baseUrl . '/api/caja/consulta/sesiones?date=2026-99-99', $cookieAdmin);
    assertSameVal(422, $ep23bBadDate['status'], 'EP-23B', 'Fecha inválida -> 422');

    // 6. EP-23C: Detalle de sesión (sesión 1 cerrada con autorización anticipada)
    $ep23c = httpRequest('GET', $baseUrl . "/api/caja/consulta?session_id={$sesion1Id}", $cookieAdmin);
    assertSameVal(200, $ep23c['status'], 'EP-23C', 'Admin consulta detalle sesión 1 -> 200');
    $d23c = $ep23c['json']['data'] ?? [];
    assertSameVal('CERRADA', $d23c['session']['status'] ?? null, 'EP-23C', 'Estado CERRADA');
    assertSameVal(100.0, (float)($d23c['summary']['expected_cash'] ?? -1), 'EP-23C', 'summary.expected_cash=100');
    assertSameVal(100.0, (float)($d23c['summary']['counted_cash'] ?? -1), 'EP-23C', 'summary.counted_cash=100');
    assertSameVal(0.0, (float)($d23c['summary']['closing_difference'] ?? -1), 'EP-23C', 'summary.closing_difference=0');
    assertTrue(!empty($d23c['authorization']), 'EP-23C', 'Incluye autorización de cierre anticipado');
    assertSameVal('Admin Paso 12', $d23c['authorization']['authorized_by_admin'] ?? null, 'EP-23C', 'Nombre del admin autorizador');

    $ep23c404 = httpRequest('GET', $baseUrl . '/api/caja/consulta?session_id=9999999', $cookieAdmin);
    assertSameVal(404, $ep23c404['status'], 'EP-23C', 'Sesión inexistente -> 404');

    // 7. EP-24A y EP-24B: Reporte de cierres de caja, snapshots y detalle
    $ep24a = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja?from={$hoyLima}&to={$hoyLima}&seller_id={$seller1Id}&page=1&page_size=10", $cookieAdmin);
    assertSameVal(200, $ep24a['status'], 'EP-24A', 'Admin genera reporte cierres-caja -> 200');
    $d24a = $ep24a['json']['data'] ?? [];
    $snapId = (string)($d24a['snapshot_id'] ?? '');
    assertTrue(str_starts_with($snapId, 'SNAP-CIE-'), 'EP-24A', 'Snapshot ID generado con prefijo SNAP-CIE-');
    assertTrue(($d24a['totals']['total_closures'] ?? 0) >= 1, 'EP-24A', 'totals.total_closures >= 1');
    $rowS1 = null;
    foreach ($d24a['rows'] ?? [] as $r) {
        if ((int)($r['session_id'] ?? 0) === $sesion1Id) {
            $rowS1 = $r;
            break;
        }
    }
    assertTrue($rowS1 !== null, 'EP-24A', 'Sesión 1 incluida en filas del reporte');
    assertSameVal(sprintf('CIE-%04d', $sesion1Id), $rowS1['code'] ?? null, 'EP-24A', 'Código CIE-xxxx correcto');
    assertSameVal(true, $rowS1['has_early_close_authorization'] ?? null, 'EP-24A', 'has_early_close_authorization=true');
    assertSameVal('Admin Paso 12', $rowS1['authorized_by_admin'] ?? null, 'EP-24A', 'authorized_by_admin correcto');

    // Cerrar sesión 2 DESPUÉS de crear snapshot y verificar que snapshot conserva total original
    $close2 = httpRequest('POST', $baseUrl . '/api/caja/cerrar', $cookieSeller1, [
        'arqueo_id'        => $arq2Id,
        'admin_email'      => 'p12.admin@test.local',
        'admin_password'   => $passPlain,
        'exception_reason' => 'Cierre temprano sesion 2',
        'request_id'       => 'p12-close-2-' . uniqid(),
    ], $csrfS1);
    assertSameVal(200, $close2['status'], 'SETUP', 'Cierre sesión 2 HTTP 200');

    $ep24aSnapPage = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja?snapshot_id={$snapId}&page=1&page_size=10", $cookieAdmin);
    assertSameVal(200, $ep24aSnapPage['status'], 'EP-24A', 'Lectura por snapshot_id -> 200');
    assertSameVal($d24a['totals']['total_closures'], $ep24aSnapPage['json']['data']['totals']['total_closures'] ?? -1, 'EP-24A', 'Snapshot inmutable mantiene total_closures previo al segundo cierre');

    // Mismatch de filtros con snapshot_id -> 422
    $ep24aMismatch = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja?snapshot_id={$snapId}&from=2020-01-01&to=2020-01-02", $cookieAdmin);
    assertSameVal(422, $ep24aMismatch['status'], 'EP-24A', 'Filtros incompatibles con snapshot_id -> 422');
    assertSameVal('SNAPSHOT_FILTER_MISMATCH', $ep24aMismatch['json']['code'] ?? null, 'EP-24A', 'Código SNAPSHOT_FILTER_MISMATCH');

    // Nuevo reporte sin snapshot_id ahora sí ve sesión 2 con has_omitted_withdrawals=true
    $ep24aFresh = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja?from={$hoyLima}&to={$hoyLima}&seller_id={$seller1Id}", $cookieAdmin);
    assertSameVal(200, $ep24aFresh['status'], 'EP-24A', 'Nuevo reporte ve los 2 cierres');
    $rowS2 = null;
    foreach ($ep24aFresh['json']['data']['rows'] ?? [] as $r) {
        if ((int)($r['session_id'] ?? 0) === $sesion2Id) {
            $rowS2 = $r;
            break;
        }
    }
    assertTrue($rowS2 !== null, 'EP-24A', 'Sesión 2 presente en nuevo reporte');
    assertSameVal(true, $rowS2['has_omitted_withdrawals'] ?? null, 'EP-24A', 'Sesión 2 marca has_omitted_withdrawals=true');

    // Expiración determinista de snapshot usando setClockProvider() (30 minutos)
    $cajaSvc = new \Services\CajaService($pdo);
    $ahoraReal = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    $cajaSvc->setClockProvider(static fn (): DateTimeImmutable => $ahoraReal->modify('+31 minutes'));
    $expiredCaught = false;
    try {
        $cajaSvc->reporteCierresCaja(['snapshot_id' => $snapId], $adminId);
    } catch (\Libraries\Core\InventarioException $e) {
        $expiredCaught = ($e->getHttpCode() === 410 && $e->getErrorCode() === 'SNAPSHOT_EXPIRED');
    }
    assertTrue($expiredCaught, 'EP-24A', 'Snapshot expirado tras +31m lanza 410 SNAPSHOT_EXPIRED');
    $stmtCheckSnap = $pdo->prepare('SELECT COUNT(*) FROM reporte_consulta_snapshot WHERE referencia_consulta = ?');
    $stmtCheckSnap->execute([$snapId]);
    assertSameVal(1, (int)$stmtCheckSnap->fetchColumn(), 'EP-24A', 'Snapshot expirado NO es eliminado globalmente de la BD');

    // EP-24B: Detalle de cierre de caja
    $ep24b = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja/{$sesion2Id}?page=1&page_size=20", $cookieAdmin);
    assertSameVal(200, $ep24b['status'], 'EP-24B', 'Detalle cierre sesión 2 -> 200');
    $d24b = $ep24b['json']['data'] ?? [];
    assertSameVal(sprintf('CIE-%04d', $sesion2Id), $d24b['closure']['code'] ?? null, 'EP-24B', 'Código CIE-xxxx en detalle');
    assertSameVal('Cierre sesion 2 con retiros omitidos', $d24b['closure']['observation'] ?? null, 'EP-24B', 'Observación de cierre presente');
    assertSameVal(210.5, (float)($d24b['closure']['counted_cash'] ?? -1), 'EP-24B', 'counted_cash=210.5');
    assertSameVal(5, $d24b['pagination']['total'] ?? -1, 'EP-24B', '5 movimientos en detalle de cierre');

    // EP-24B sobre sesión inexistente -> 404
    $ep24b404 = httpRequest('GET', $baseUrl . '/api/reportes/cierres-caja/9999999', $cookieAdmin);
    assertSameVal(404, $ep24b404['status'], 'EP-24B', 'Cierre inexistente -> 404');

    // EP-24B sobre sesión ABIERTA -> 409 SESSION_NOT_CLOSED
    $open3 = httpRequest('POST', $baseUrl . '/api/caja/abrir', $cookieSeller2, [
        'shift_id'     => $shiftId,
        'cash_counted' => 195.50,
        'request_id'   => 'p12-open-3-' . uniqid(),
    ], $csrfS2);
    $sesion3Id = (int)($open3['json']['data']['session_id'] ?? 0);
    $ep24b409 = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja/{$sesion3Id}", $cookieAdmin);
    assertSameVal(409, $ep24b409['status'], 'EP-24B', 'Sesión abierta en detalle de cierre -> 409');
    assertSameVal('SESSION_NOT_CLOSED', $ep24b409['json']['code'] ?? null, 'EP-24B', 'Código SESSION_NOT_CLOSED');
} finally {
    stopPhpServer($srv1);
    @unlink($cookieAdmin);
    @unlink($cookieSeller1);
    @unlink($cookieSeller2);
    if (is_dir($sessionDir)) {
        @array_map('unlink', glob($sessionDir . '/*'));
        @rmdir($sessionDir);
    }
}

echo "\n=== RESUMEN PASO 12 ===\n";
foreach ($categories as $cat => $counts) {
    echo sprintf(" - %-10s: %d PASS, %d FAIL\n", $cat, $counts['passed'], $counts['failed']);
}
echo sprintf("TOTAL: %d/%d aserciones correctas.\n", $passed, $passed + $failed);
exit($failed === 0 ? 0 : 1);
