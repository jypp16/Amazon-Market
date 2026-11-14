<?php
declare(strict_types=1);

/**
 * Batería integral de aceptación y conformidad de contrato para el Paso 12 del Sprint 3
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
    $cmd = [$phpBin, '-S', '127.0.0.1:' . $port, $router];
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
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $loc = '';
    if (is_string($resp) && preg_match('/^Location:\s*(.+)$/im', $resp, $lm)) {
        $loc = trim($lm[1]);
    }

    $home = httpRequest('GET', $baseUrl . '/Dashboard', $cookieFile);
    $csrf2 = $csrf;
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/i', (string)$home['raw'], $m2)) {
        $csrf2 = $m2[1];
    } elseif (preg_match('/csrf_token["\']?\s*[:=]\s*["\']([a-f0-9]{64})["\']/i', (string)$home['raw'], $m2)) {
        $csrf2 = $m2[1];
    }
    $ok = ($code >= 200 && $code < 400 && $csrf2 !== '' && $loc !== '' && !str_ends_with($loc, '/Auth'));
    return ['status' => $code, 'csrf' => $csrf2, 'ok' => $ok];
}

if (defined('SPRINT3_PASO12_SOLO_HELPERS') && SPRINT3_PASO12_SOLO_HELPERS) return;

$sessionDir = sys_get_temp_dir() . '\\am_p12_sess_' . uniqid();
@mkdir($sessionDir, 0777, true);
$cookieAdmin = tempnam(sys_get_temp_dir(), 'p12_adm_');
$cookieAdmin2 = tempnam(sys_get_temp_dir(), 'p12_adm2_');
$cookieSeller1 = tempnam(sys_get_temp_dir(), 'p12_s1_');
$cookieSeller2 = tempnam(sys_get_temp_dir(), 'p12_s2_');
$srv1 = null;

try {
    $pdo = \Libraries\Core\Conexion::getInstance()->conect();
    $srv1 = startPhpServer($phpBin, $rootDir, $httpPort1, $dbName, $sessionDir);
    $baseUrl = $srv1['baseUrl'];

    $passPlain = 'ClavePrueba123!';
    $passHash = password_hash($passPlain, PASSWORD_BCRYPT);
    $tag = substr(md5((string)microtime(true)), 0, 4);

    $stmtInsUser = $pdo->prepare(
        'INSERT INTO usuario (id_rol, username, nombre, dni, telefono, direccion, email, password_hash, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmtInsUser->execute([1, "a1_{$tag}", 'Admin Paso 12', '78' . random_int(100000, 999999), '988880001', 'Dir', "p12.admin1.{$tag}@test.local", $passHash, 1]);
    $adminId = (int)$pdo->lastInsertId();

    $stmtInsUser->execute([1, "a2_{$tag}", 'Admin Dos P12', '78' . random_int(100000, 999999), '988880002', 'Dir', "p12.admin2.{$tag}@test.local", $passHash, 1]);
    $admin2Id = (int)$pdo->lastInsertId();

    $stmtInsUser->execute([2, "v1_{$tag}", 'Vendedor Uno Ñandú', '78' . random_int(100000, 999999), '988880003', 'Dir', "p12.vendedor1.{$tag}@test.local", $passHash, 1]);
    $seller1Id = (int)$pdo->lastInsertId();

    $stmtInsUser->execute([2, "v2_{$tag}", 'Vendedor Dos P12', '78' . random_int(100000, 999999), '988880004', 'Dir', "p12.vendedor2.{$tag}@test.local", $passHash, 1]);
    $seller2Id = (int)$pdo->lastInsertId();

    $stmtInsUser->execute([2, "vi_{$tag}", 'Zzz Vendedor Inactivo P12', '78' . random_int(100000, 999999), '988880005', 'Dir', "p12.vendedor_inact.{$tag}@test.local", $passHash, 0]);
    $sellerInactId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES (?, '00:00:00', '23:59:00', 1)")
        ->execute(["Turno P12 {$tag}"]);
    $shiftId = (int)$pdo->lastInsertId();

    $resLoginAdm = loginViaHttp($baseUrl, $cookieAdmin, "a1_{$tag}", $passPlain);
    assertTrue($resLoginAdm['ok'], 'AUTH', 'Login admin 1 P12');
    $resLoginAdm2 = loginViaHttp($baseUrl, $cookieAdmin2, "a2_{$tag}", $passPlain);
    assertTrue($resLoginAdm2['ok'], 'AUTH', 'Login admin 2 P12');
    $resLoginS1 = loginViaHttp($baseUrl, $cookieSeller1, "v1_{$tag}", $passPlain);
    assertTrue($resLoginS1['ok'], 'AUTH', 'Login vendedor 1 P12');
    $resLoginS2 = loginViaHttp($baseUrl, $cookieSeller2, "v2_{$tag}", $passPlain);
    assertTrue($resLoginS2['ok'], 'AUTH', 'Login vendedor 2 P12');
    $csrfS1 = ['X-CSRF-TOKEN: ' . $resLoginS1['csrf']];
    $csrfS2 = ['X-CSRF-TOKEN: ' . $resLoginS2['csrf']];

    // 1. RBAC
    foreach ([
        '/api/caja/consulta/vendedores',
        '/api/caja/consulta/sesiones?date=2026-11-12&seller_id=' . $seller1Id,
        '/api/caja/consulta?session_id=1',
        '/api/reportes/cierres-caja',
        '/api/reportes/cierres-caja/1',
    ] as $ep) {
        $unauth = httpRequest('GET', $baseUrl . $ep);
        assertSameVal(401, $unauth['status'], 'RBAC', "Sin sesión {$ep} -> 401");
        $asSeller = httpRequest('GET', $baseUrl . $ep, $cookieSeller1);
        assertSameVal(403, $asSeller['status'], 'RBAC', "Vendedor en {$ep} -> 403");
    }
    assertSameVal(401, httpRequest('GET', $baseUrl . '/api/caja/consulta/activa')['status'], 'RBAC', 'Sin sesión EP-22 -> 401');
    assertSameVal(403, httpRequest('GET', $baseUrl . '/api/caja/consulta/activa', $cookieAdmin)['status'], 'RBAC', 'Admin en EP-22 -> 403');

    // 2. EP-22 sin sesión abierta
    $ep22Empty = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa', $cookieSeller1);
    assertSameVal(200, $ep22Empty['status'], 'EP-22', 'Sin sesión activa -> 200');
    assertSameVal('NO_OPEN_SESSION', $ep22Empty['json']['code'] ?? null, 'EP-22', 'Sin sesión code=NO_OPEN_SESSION');
    assertSameVal('no_session', $ep22Empty['json']['data']['type'] ?? null, 'EP-22', 'Sin sesión data.type=no_session');
    assertSameVal(false, $ep22Empty['json']['data']['has_active_session'] ?? null, 'EP-22', 'Sin sesión has_active_session=false');
    assertTrue(array_key_exists('session', $ep22Empty['json']['data'] ?? []) && $ep22Empty['json']['data']['session'] === null, 'EP-22', 'Sin sesión session=null');
    assertSameVal(0, $ep22Empty['json']['data']['pagination']['total_items'] ?? -1, 'EP-22', 'Paginación vacía total_items=0');
    assertSameVal(0, $ep22Empty['json']['data']['pagination']['total_movements'] ?? -1, 'EP-22', 'Paginación vacía total_movements=0');

    // Filtros prohibidos en EP-22 -> 403 FORBIDDEN
    foreach (['session_id=1', 'seller_id=' . $seller2Id, 'date=2026-11-12'] as $fParam) {
        $forb = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?' . $fParam, $cookieSeller1);
        assertSameVal(403, $forb['status'], 'EP-22', "Filtro prohibido {$fParam} -> 403");
        assertSameVal('FORBIDDEN', $forb['json']['code'] ?? null, 'EP-22', "Filtro prohibido {$fParam} code=FORBIDDEN");
    }

    // 3. Sesión 1 (primera sesión, diferencia_apertura NULL) - apertura sin movimientos primero
    $open1 = httpRequest('POST', $baseUrl . '/api/caja/abrir', $cookieSeller1, [
        'shift_id'     => $shiftId,
        'cash_counted' => 100.00,
        'request_id'   => 'p12-open-1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $open1['status'], 'SETUP', 'Apertura sesión 1 HTTP 201');
    $sesion1Id = (int)($open1['json']['data']['session_id'] ?? 0);

    // EP-22 sin movimientos conserva saldo inicial y current_balance=opening_balance
    $ep22NoMov = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa', $cookieSeller1);
    assertSameVal(200, $ep22NoMov['status'], 'EP-22', 'Sesión propia sin movimientos -> 200');
    $d22NoMov = $ep22NoMov['json']['data'] ?? [];
    assertSameVal('result', $d22NoMov['type'] ?? null, 'EP-22', 'data.type=result');
    assertSameVal('Abierta', $d22NoMov['session']['status'] ?? null, 'EP-22', 'session.status=Abierta');
    assertSameVal('Vendedor Uno Ñandú', $d22NoMov['session']['seller'] ?? null, 'EP-22', 'session.seller Unicode exacto');
    assertTrue(is_array($d22NoMov['session']['reference_schedule'] ?? null) && isset($d22NoMov['session']['reference_schedule']['label']), 'EP-22', 'reference_schedule es objeto');
    assertTrue(array_key_exists('opening_difference', $d22NoMov['session'] ?? []), 'EP-22', 'opening_difference presente');
    assertSameVal(100.0, (float)($d22NoMov['summary']['opening_balance'] ?? -1), 'EP-22', 'Sin movimientos opening_balance=100');
    assertSameVal(100.0, (float)($d22NoMov['summary']['current_balance'] ?? -1), 'EP-22', 'Sin movimientos current_balance=100');
    assertSameVal(10, $d22NoMov['pagination']['page_size'] ?? -1, 'EP-22', 'Default page_size=10');

    // EP-22 desde Vendedor 2 mientras Vendedor 1 ocupa la caja -> HTTP 200 NO_OPEN_SESSION sin fuga del otro vendedor
    $ep22Foreign = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa', $cookieSeller2);
    assertSameVal(200, $ep22Foreign['status'], 'EP-22', 'Caja ocupada por otro vendedor -> 200');
    assertSameVal('NO_OPEN_SESSION', $ep22Foreign['json']['code'] ?? null, 'EP-22', 'Caja ocupada por otro -> NO_OPEN_SESSION');
    assertSameVal('no_session', $ep22Foreign['json']['data']['type'] ?? null, 'EP-22', 'Caja ocupada por otro -> data.type=no_session');
    assertSameVal(false, $ep22Foreign['json']['data']['has_active_session'] ?? null, 'EP-22', 'Caja ocupada por otro -> has_active_session=false');
    assertTrue(!str_contains($ep22Foreign['raw'], 'Vendedor Uno'), 'EP-22', 'No filtra nombre del otro vendedor');

    // Cerrar sesión 1 anticipadamente
    $arq1 = httpRequest('POST', $baseUrl . '/api/caja/arqueo', $cookieSeller1, [
        'counted'     => 100.00,
        'observation' => 'Cierre sesion 1',
        'version'     => 1,
        'request_id'  => 'p12-arq1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $arq1['status'], 'SETUP', 'Arqueo sesión 1 HTTP 201');
    $arq1Id = (int)($arq1['json']['data']['arqueo']['id'] ?? 0);

    $close1 = httpRequest('POST', $baseUrl . '/api/caja/cerrar', $cookieSeller1, [
        'arqueo_id'        => $arq1Id,
        'admin_email'      => "p12.admin1.{$tag}@test.local",
        'admin_password'   => $passPlain,
        'exception_reason' => 'Cierre temprano controlado sesion 1',
        'request_id'       => 'p12-close-1-' . uniqid(),
    ], $csrfS1);
    assertSameVal(200, $close1['status'], 'SETUP', 'Cierre anticipado sesión 1 HTTP 200');
    sleep(1);

    // 4. Sesión 2 (apertura con diferencia negativa -10.00 -> 90.00 vs 100.00) + ventas + movimientos + correcciones
    $open2 = httpRequest('POST', $baseUrl . '/api/caja/abrir', $cookieSeller1, [
        'shift_id'     => $shiftId,
        'cash_counted' => 90.00,
        'request_id'   => 'p12-open-2-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $open2['status'], 'SETUP', 'Apertura sesión 2 HTTP 201');
    $sesion2Id = (int)($open2['json']['data']['session_id'] ?? 0);

    // Ventas en efectivo, tarjeta y yape_plin (usando primer producto con stock suficiente y método de pago 1, 2, 3)
    $prodRow = $pdo->query('SELECT id_producto, precio_venta FROM producto WHERE estado = 1 AND stock_actual >= 10 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $idProd = (int)$prodRow['id_producto'];
    $precioProd = (float)$prodRow['precio_venta'];
    $idCli = (int)$pdo->query("SELECT id_cliente FROM cliente WHERE estado = 1 ORDER BY id_cliente ASC LIMIT 1")->fetchColumn();
    $idBoleta = (int)$pdo->query("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE LOWER(nombre) LIKE '%boleta%' AND estado = 1 LIMIT 1")->fetchColumn();
    if ($idBoleta <= 0) {
        $idBoleta = 1;
    }
    $metodos = $pdo->query('SELECT id_metodo_pago, LOWER(nombre) AS n FROM metodo_pago')->fetchAll(PDO::FETCH_ASSOC);
    $idEfectivo = 1;
    $idTarjeta = 2;
    $idYape = 3;
    foreach ($metodos as $mp) {
        if (str_contains($mp['n'], 'efectivo')) $idEfectivo = (int)$mp['id_metodo_pago'];
        if (str_contains($mp['n'], 'tarjeta')) $idTarjeta = (int)$mp['id_metodo_pago'];
        if (str_contains($mp['n'], 'yape') || str_contains($mp['n'], 'plin')) $idYape = (int)$mp['id_metodo_pago'];
    }

    foreach ([$idEfectivo, $idTarjeta, $idYape] as $idxMp => $mpId) {
        $vRes = httpRequest('POST', $baseUrl . '/api/ventas', $cookieSeller1, [
            'id_cliente'          => $idCli,
            'id_tipo_comprobante' => $idBoleta,
            'id_metodo_pago'      => $mpId,
            'referencia_solicitud' => 'p12-vta-' . $idxMp . '-' . uniqid(),
            'productos'           => [['id_producto' => $idProd, 'cantidad' => 1]],
        ], $csrfS1);
        assertSameVal(201, $vRes['status'], 'SETUP', "Venta método {$mpId} HTTP 201");
    }

    // Movimientos variados incluyendo referencia Unicode > 200 chars
    $refLarga = str_repeat('Ñáéíóú', 40);
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
    $egr1Id = (int)($egr1['json']['data']['movement']['id'] ?? 0);

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

    $reqCorr1 = 'p12-corr1-' . uniqid();
    $corr1 = httpRequest('POST', $baseUrl . '/api/caja/movimientos/correcciones', $cookieSeller1, [
        'original_movement_id' => $ing1Id,
        'corrected_amount'     => 30.50,
        'reason'               => 'Ajuste de conteo en ingreso',
        'request_id'           => $reqCorr1,
    ], $csrfS1);
    assertSameVal(201, $corr1['status'], 'SETUP', 'Corrección movimiento HTTP 201');

    $verAntesReplay = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$sesion2Id}")->fetchColumn();
    $corr1Replay = httpRequest('POST', $baseUrl . '/api/caja/movimientos/correcciones', $cookieSeller1, [
        'original_movement_id' => $ing1Id,
        'corrected_amount'     => 30.50,
        'reason'               => 'Ajuste de conteo en ingreso',
        'request_id'           => $reqCorr1,
    ], $csrfS1);
    assertSameVal(200, $corr1Replay['status'], 'VERSION', 'Replay idempotente HTTP 200');
    $verDespuesReplay = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$sesion2Id}")->fetchColumn();
    assertSameVal($verAntesReplay, $verDespuesReplay, 'VERSION', 'Replay idempotente NO incrementa version_caja');

    // Corrección encadenada: ing1 pasa de 30.50 -> 35.00
    $corr1b = httpRequest('POST', $baseUrl . '/api/caja/movimientos/correcciones', $cookieSeller1, [
        'original_movement_id' => $ing1Id,
        'corrected_amount'     => 35.00,
        'reason'               => 'Segundo ajuste encadenado en ingreso',
        'request_id'           => 'p12-corr1b-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $corr1b['status'], 'SETUP', 'Corrección encadenada HTTP 201');

    // Corrección a cero: egr1 pasa de 10.00 -> 0.00
    $corrZero = httpRequest('POST', $baseUrl . '/api/caja/movimientos/correcciones', $cookieSeller1, [
        'original_movement_id' => $egr1Id,
        'corrected_amount'     => 0.00,
        'reason'               => 'Anulación contable de salida por error',
        'request_id'           => 'p12-corr0-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $corrZero['status'], 'SETUP', 'Corrección a cero HTTP 201');

    $verS2Esperada = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$sesion2Id}")->fetchColumn();
    assertSameVal($verAntesReplay + 2, $verS2Esperada, 'VERSION', 'Dos correcciones nuevas incrementan version_caja en +2');

    // Esperado en sesión 2: 90.00 + precioProd (efectivo) + 35.00 (ing1 encadenado) + 40.00 (inj1) - 0.00 (egr1 corregido a 0) = 165.00 + precioProd
    $esperadoS2 = round(165.00 + $precioProd, 2);

    // 5. EP-22 paginación, alias limit, conflictos, página fuera de rango y version
    $ep22Lim = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page=1&limit=2', $cookieSeller1);
    assertSameVal(200, $ep22Lim['status'], 'EP-22', 'Alias limit=2 aceptado -> 200');
    assertSameVal(2, count($ep22Lim['json']['data']['movements'] ?? []), 'EP-22', 'Página 1 trae 2 movimientos');
    assertSameVal(8, $ep22Lim['json']['data']['pagination']['total_items'] ?? -1, 'EP-22', 'total_items=8 (1 venta efectivo + 7 movimientos/correcciones)');
    assertSameVal(8, $ep22Lim['json']['data']['pagination']['total_movements'] ?? -1, 'EP-22', 'total_movements=8');
    assertSameVal(round($precioProd, 2), (float)($ep22Lim['json']['data']['summary']['cash_sales'] ?? -1), 'EP-22', 'summary.cash_sales exacto sin duplicar');
    assertSameVal(round($precioProd, 2), (float)($ep22Lim['json']['data']['summary']['card'] ?? -1), 'EP-22', 'summary.card exacto');
    assertSameVal(round($precioProd, 2), (float)($ep22Lim['json']['data']['summary']['yape_plin'] ?? -1), 'EP-22', 'summary.yape_plin exacto');
    assertSameVal($esperadoS2, (float)($ep22Lim['json']['data']['summary']['current_balance'] ?? -1), 'EP-22', 'summary.current_balance exacto sin doble suma');
    assertTrue(is_int($ep22Lim['json']['data']['session']['version'] ?? null), 'VERSION', 'EP-22 session.version es entero');
    assertSameVal($verS2Esperada, $ep22Lim['json']['data']['session']['version'] ?? -1, 'VERSION', 'EP-22 session.version coincide con BD');

    $ep22Full = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page=1&page_size=20', $cookieSeller1);
    assertSameVal(200, $ep22Full['status'], 'EP-22', 'Consulta completa movimientos -> 200');
    assertSameVal($verS2Esperada, (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$sesion2Id}")->fetchColumn(), 'VERSION', 'Consultar EP-22 no incrementa version_caja');
    $m22ById = [];
    foreach (($ep22Full['json']['data']['movements'] ?? []) as $m22) {
        $m22ById[(int)$m22['id']] = $m22;
    }
    assertSameVal($sesion2Id, $m22ById[$ing1Id]['session_id'] ?? null, 'MOV', 'EP-22 mov.session_id coincide con sesión real');
    assertSameVal('Ingreso de sencillo', $m22ById[$ing1Id]['reason'] ?? null, 'MOV', 'EP-22 mov.reason conserva motivo original');
    assertSameVal('Corregido', $m22ById[$ing1Id]['status'] ?? null, 'MOV', 'EP-22 original encadenado status=Corregido');
    assertSameVal(35.0, (float)($m22ById[$ing1Id]['current_amount'] ?? -1), 'MOV', 'EP-22 original encadenado current_amount=35.0');
    assertSameVal('Corregido', $m22ById[$egr1Id]['status'] ?? null, 'MOV', 'EP-22 original corregido a 0.00 status=Corregido');
    assertSameVal(0.0, (float)($m22ById[$egr1Id]['current_amount'] ?? -1), 'MOV', 'EP-22 original corregido a 0.00 current_amount=0.0');
    assertTrue(array_key_exists('administrator_id', $m22ById[$ing1Id] ?? []) && $m22ById[$ing1Id]['administrator_id'] === null, 'MOV', 'EP-22 ingreso manual administrator_id=null');
    assertTrue(array_key_exists('declared_occurred_in_open_origin_session', $m22ById[$ing1Id] ?? []) && $m22ById[$ing1Id]['declared_occurred_in_open_origin_session'] === null, 'MOV', 'EP-22 ingreso manual declared=null');
    assertTrue(array_key_exists('confirmed_reflected_in_opening', $m22ById[$ing1Id] ?? []) && $m22ById[$ing1Id]['confirmed_reflected_in_opening'] === null, 'MOV', 'EP-22 ingreso manual confirmed=null');

    $ep22Conflict = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page_size=5&limit=10', $cookieSeller1);
    assertSameVal(422, $ep22Conflict['status'], 'EP-22', 'Conflicto page_size vs limit -> 422');
    assertSameVal('AMBIGUOUS_ALIAS_VALUES', $ep22Conflict['json']['code'] ?? null, 'EP-22', 'Conflicto code=AMBIGUOUS_ALIAS_VALUES');

    $ep22EmptyPage = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page=', $cookieSeller1);
    assertSameVal(422, $ep22EmptyPage['status'], 'EP-22', 'page= vacío -> 422');

    $ep22OutOfRange = httpRequest('GET', $baseUrl . '/api/caja/consulta/activa?page=99&page_size=2', $cookieSeller1);
    assertSameVal(200, $ep22OutOfRange['status'], 'EP-22', 'Página fuera de rango -> 200');
    assertSameVal([], $ep22OutOfRange['json']['data']['movements'] ?? ['x'], 'EP-22', 'Página fuera de rango movements=[]');
    assertSameVal(99, $ep22OutOfRange['json']['data']['pagination']['page'] ?? -1, 'EP-22', 'Página fuera de rango conserva page=99');
    assertSameVal($esperadoS2, (float)($ep22OutOfRange['json']['data']['summary']['current_balance'] ?? -1), 'EP-22', 'Página fuera de rango conserva summary completo');

    // 6. EP-23A: vendedores (arreglo directo con username y estado, incluyendo inactivo, orden nombre ASC)
    $ep23a = httpRequest('GET', $baseUrl . '/api/caja/consulta/vendedores', $cookieAdmin);
    assertSameVal(200, $ep23a['status'], 'EP-23A', 'Admin consulta vendedores -> 200');
    $sellersArr = $ep23a['json']['data'] ?? null;
    assertTrue(is_array($sellersArr) && array_is_list($sellersArr), 'EP-23A', 'data es lista plana (sin wrapper data.sellers)');
    $foundInact = null;
    foreach ($sellersArr as $sItem) {
        if ((int)($sItem['id'] ?? 0) === $sellerInactId) {
            $foundInact = $sItem;
            break;
        }
    }
    assertTrue($foundInact !== null, 'EP-23A', 'Incluye vendedor inactivo para historial');
    assertSameVal("vi_{$tag}", $foundInact['username'] ?? null, 'EP-23A', 'Incluye username');
    assertSameVal(0, $foundInact['estado'] ?? -1, 'EP-23A', 'Incluye estado=0');
    assertTrue(!array_key_exists('email', $foundInact ?? []), 'EP-23A', 'No sustituye campos por email');

    // 7. EP-23B: sesiones por date + seller_id obligatorios, turn_id opcional
    $hoyLima = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d');
    assertSameVal(422, httpRequest('GET', $baseUrl . '/api/caja/consulta/sesiones', $cookieAdmin)['status'], 'EP-23B', 'Sin date ni seller_id -> 422');
    assertSameVal(422, httpRequest('GET', $baseUrl . "/api/caja/consulta/sesiones?date={$hoyLima}", $cookieAdmin)['status'], 'EP-23B', 'Sin seller_id -> 422');
    $ep23bBadDate = httpRequest('GET', $baseUrl . "/api/caja/consulta/sesiones?date=2026-99-99&seller_id={$seller1Id}", $cookieAdmin);
    assertSameVal(422, $ep23bBadDate['status'], 'EP-23B', 'Fecha inválida -> 422');
    assertSameVal('INVALID_DATE', $ep23bBadDate['json']['code'] ?? null, 'EP-23B', 'Fecha inválida code=INVALID_DATE');

    $ep23b = httpRequest('GET', $baseUrl . "/api/caja/consulta/sesiones?date={$hoyLima}&seller_id={$seller1Id}&turn_id={$shiftId}", $cookieAdmin);
    assertSameVal(200, $ep23b['status'], 'EP-23B', 'Con date, seller_id y turn_id -> 200');
    $sessArr = $ep23b['json']['data'] ?? null;
    assertTrue(is_array($sessArr) && array_is_list($sessArr) && count($sessArr) >= 2, 'EP-23B', 'data es lista plana con sesiones abierta y cerrada');
    assertSameVal('Cerrada', $sessArr[0]['status'] ?? null, 'EP-23B', 'Sesión 1 status=Cerrada');
    assertSameVal('Abierta', $sessArr[1]['status'] ?? null, 'EP-23B', 'Sesión 2 status=Abierta');
    assertSameVal($shiftId, $sessArr[0]['turn_id'] ?? null, 'EP-23B', 'turn_id presente');

    // 8. EP-23C: alias sesion_id, overflow PHP_INT_MAX, campos de movimientos y autorizaciones
    $ep23cAlias = httpRequest('GET', $baseUrl . "/api/caja/consulta?sesion_id={$sesion1Id}", $cookieAdmin);
    assertSameVal(200, $ep23cAlias['status'], 'EP-23C', 'Alias sesion_id aceptado -> 200');
    $d23c = $ep23cAlias['json']['data'] ?? [];
    assertSameVal('result', $d23c['type'] ?? null, 'EP-23C', 'data.type=result');
    assertTrue(array_key_exists('opening_difference', $d23c['session'] ?? []) && $d23c['session']['opening_difference'] === null, 'EP-23C', 'Primera sesión opening_difference=null');
    $auth0 = $d23c['authorizations'][0] ?? [];
    assertSameVal($sesion1Id, $auth0['session_id'] ?? null, 'EP-23C', 'auth.session_id');
    assertSameVal('CIERRE_ANTICIPADO', $auth0['operation'] ?? null, 'EP-23C', 'auth.operation');
    assertSameVal($adminId, $auth0['administrator_id'] ?? null, 'EP-23C', 'auth.administrator_id');
    assertSameVal('Admin Paso 12', $auth0['administrator_name'] ?? null, 'EP-23C', 'auth.administrator_name');
    assertSameVal("p12.admin1.{$tag}@test.local", $auth0['administrator_email'] ?? null, 'EP-23C', 'auth.administrator_email');
    assertTrue(isset($auth0['scheduled_end']) && isset($auth0['minutes_before_end']), 'EP-23C', 'auth.scheduled_end y minutes_before_end');
    assertTrue(!str_contains($ep23cAlias['raw'], $passHash), 'EP-23C', 'Sin hash de contraseña en respuesta');

    $ep23cS2 = httpRequest('GET', $baseUrl . "/api/caja/consulta?session_id={$sesion2Id}&page_size=20", $cookieAdmin);
    assertSameVal(200, $ep23cS2['status'], 'EP-23C', 'Sesión 2 detalle -> 200');
    assertTrue(is_int($ep23cS2['json']['data']['session']['version'] ?? null), 'VERSION', 'EP-23C session.version es entero');
    assertSameVal($verS2Esperada, $ep23cS2['json']['data']['session']['version'] ?? -1, 'VERSION', 'EP-23C session.version coincide con BD');
    assertSameVal($verS2Esperada, (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$sesion2Id}")->fetchColumn(), 'VERSION', 'Consultar EP-23C no incrementa version_caja');
    $movs23c = $ep23cS2['json']['data']['movements'] ?? [];
    $mIng = null;
    $mEgr = null;
    $mInj = null;
    $mOmit = null;
    $mCorr = null;
    foreach ($movs23c as $m) {
        if ((int)$m['id'] === $ing1Id) $mIng = $m;
        if ((int)$m['id'] === $egr1Id) $mEgr = $m;
        if (($m['type'] ?? '') === 'admin_injection') $mInj = $m;
        if (($m['type'] ?? '') === 'omitted_withdrawal') $mOmit = $m;
        if (($m['type'] ?? '') === 'correction' && $mCorr === null) $mCorr = $m;
    }
    assertSameVal($sesion2Id, $mIng['session_id'] ?? null, 'EP-23C', 'mov.session_id=sesion2');
    assertSameVal('Ingreso', $mIng['label'] ?? null, 'EP-23C', 'mov.label=Ingreso');
    assertSameVal('Ingreso de sencillo', $mIng['reason'] ?? null, 'EP-23C', 'mov.reason original intacto');
    assertSameVal('Corregido', $mIng['status'] ?? null, 'EP-23C', 'mov.status=Corregido');
    assertTrue(isset($mIng['date']) && isset($mIng['time']) && isset($mIng['recorded_at']), 'EP-23C', 'mov.date/time/recorded_at');
    assertSameVal(25.5, (float)($mIng['amount'] ?? -1), 'EP-23C', 'mov.amount original=25.5');
    assertSameVal(35.0, (float)($mIng['current_amount'] ?? -1), 'EP-23C', 'mov.current_amount encadenado=35.0');
    assertSameVal(25.5, (float)($mIng['impact'] ?? -1), 'EP-23C', 'mov.impact numérico=25.5');
    assertSameVal('Suma', $mIng['effect'] ?? null, 'EP-23C', 'mov.effect texto=Suma');
    assertSameVal('Vendedor Uno Ñandú', $mIng['responsible'] ?? null, 'EP-23C', 'mov.responsible');
    assertTrue(array_key_exists('administrator_id', $mIng ?? []) && $mIng['administrator_id'] === null, 'EP-23C', 'mov.administrator_id=null en ingreso manual');
    assertSameVal('Corregido', $mEgr['status'] ?? null, 'EP-23C', 'egreso corregido a 0.00 status=Corregido');
    assertSameVal(0.0, (float)($mEgr['current_amount'] ?? -1), 'EP-23C', 'egreso corregido a 0.00 current_amount=0.0');
    assertSameVal($adminId, $mInj['administrator_id'] ?? null, 'EP-23C', 'mov.administrator_id en inyección');
    assertSameVal('Admin Paso 12', $mInj['administrator_name'] ?? null, 'EP-23C', 'mov.administrator_name');
    assertSameVal('Confirmado', $mInj['status'] ?? null, 'EP-23C', 'mov sin correcciones status=Confirmado');
    assertSameVal($refLarga, $mInj['reason'] ?? null, 'EP-23C', 'Inyección reason Unicode completo');
    assertTrue(mb_strlen((string)($mInj['reference'] ?? ''), 'UTF-8') >= 200, 'EP-23C', 'Referencia Unicode larga completa');
    assertSameVal('Sin efecto: ya reflejado en la apertura', $mOmit['effect'] ?? null, 'EP-23C', 'Retiro omitido effect descriptivo');
    assertSameVal($sesion1Id, $mOmit['origin_session_id'] ?? null, 'EP-23C', 'Retiro omitido origin_session_id');
    assertSameVal(true, $mOmit['declared_occurred_in_open_origin_session'] ?? null, 'EP-23C', 'Retiro omitido declared=true');
    assertSameVal(true, $mOmit['confirmed_reflected_in_opening'] ?? null, 'EP-23C', 'Retiro omitido confirmed=true');
    assertSameVal($adminId, $mOmit['administrator_id'] ?? null, 'EP-23C', 'Retiro omitido administrator_id');
    assertSameVal($ing1Id, $mCorr['original_movement_id'] ?? null, 'EP-23C', 'Corrección original_movement_id');
    assertSameVal('Confirmado', $mCorr['status'] ?? null, 'EP-23C', 'Fila de corrección status=Confirmado');

    $ep23cOverflow = httpRequest('GET', $baseUrl . "/api/caja/consulta?session_id={$sesion2Id}&page=9223372036854775807", $cookieAdmin);
    assertSameVal(422, $ep23cOverflow['status'], 'EP-23C', 'Overflow PHP_INT_MAX -> 422 (sin 500)');

    // Cerrar sesión 2 para pruebas de EP-24A y EP-24B
    $verS2 = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$sesion2Id}")->fetchColumn();
    $arq2 = httpRequest('POST', $baseUrl . '/api/caja/arqueo', $cookieSeller1, [
        'counted'     => $esperadoS2,
        'observation' => 'Arqueo cierre sesion 2',
        'version'     => $verS2,
        'request_id'  => 'p12-arq2-' . uniqid(),
    ], $csrfS1);
    assertSameVal(201, $arq2['status'], 'SETUP', 'Arqueo sesión 2 HTTP 201');
    $arq2Id = (int)($arq2['json']['data']['arqueo']['id'] ?? 0);

    $close2 = httpRequest('POST', $baseUrl . '/api/caja/cerrar', $cookieSeller1, [
        'arqueo_id'        => $arq2Id,
        'admin_email'      => "p12.admin1.{$tag}@test.local",
        'admin_password'   => $passPlain,
        'exception_reason' => 'Cierre temprano sesion 2',
        'request_id'       => 'p12-close-2-' . uniqid(),
    ], $csrfS1);
    assertSameVal(200, $close2['status'], 'SETUP', 'Cierre sesión 2 HTTP 200');

    // 9. EP-24A: filtros canónicos, defaults, nulabilidad, snapshot entero, mismatch 409, expiración 404
    $mesInicio = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-01');
    $ep24aDef = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja?vendedor_id={$seller1Id}", $cookieAdmin);
    assertSameVal(200, $ep24aDef['status'], 'EP-24A', 'Reporte con vendedor_id y defaults -> 200');
    $d24a = $ep24aDef['json']['data'] ?? [];
    $snapId = $d24a['snapshot_id'] ?? null;
    assertTrue(is_int($snapId) && $snapId > 0, 'EP-24A', 'snapshot_id es entero positivo');
    assertTrue(!empty($d24a['generated_at']) && !empty($d24a['expires_at']), 'EP-24A', 'generated_at y expires_at presentes');
    assertSameVal($mesInicio, $d24a['filters']['desde'] ?? null, 'EP-24A', 'Default desde = primer día del mes');
    assertSameVal($hoyLima, $d24a['filters']['hasta'] ?? null, 'EP-24A', 'Default hasta = fecha actual');
    assertTrue(isset($d24a['closures']) && is_array($d24a['closures']), 'EP-24A', 'data.closures presente');
    assertSameVal(2, is_array($d24a['closures'] ?? null) ? count($d24a['closures']) : -1, 'EP-24A', '2 cierres del vendedor 1');

    // Orden DESC: sesión 2 primero, sesión 1 segunda
    assertSameVal($sesion2Id, $d24a['closures'][0]['session_id'] ?? null, 'EP-24A', 'Orden cierre DESC: sesión 2 primero');
    assertSameVal(-10.0, (float)($d24a['closures'][0]['opening_difference'] ?? 0), 'EP-24A', 'Sesión 2 opening_difference = -10.0');
    assertSameVal(true, $d24a['closures'][0]['has_omitted_withdrawals'] ?? null, 'EP-24A', 'Sesión 2 has_omitted_withdrawals=true');
    assertSameVal($sesion1Id, $d24a['closures'][1]['session_id'] ?? null, 'EP-24A', 'Sesión 1 segunda');
    assertTrue(array_key_exists('opening_difference', $d24a['closures'][1]) && $d24a['closures'][1]['opening_difference'] === null, 'EP-24A', 'Primera sesión conserva opening_difference=null');

    // Lectura por snapshot_id con filtro parcial coincidente (hereda desde/hasta del snapshot)
    $ep24aSnapOk = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja?snapshot_id={$snapId}&vendedor_id={$seller1Id}&page=1&limit=1", $cookieAdmin);
    assertSameVal(200, $ep24aSnapOk['status'], 'EP-24A', 'Lectura snapshot_id entero con filtro parcial -> 200');
    assertSameVal(1, count($ep24aSnapOk['json']['data']['closures'] ?? []), 'EP-24A', 'limit=1 devuelve 1 fila');
    assertSameVal(2, $ep24aSnapOk['json']['data']['pagination']['total_closures'] ?? -1, 'EP-24A', 'total_closures=2 conservado');

    // Filtro incompatible -> 409 SNAPSHOT_FILTER_MISMATCH
    $ep24aMismatch = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja?snapshot_id={$snapId}&desde=2020-01-01", $cookieAdmin);
    assertSameVal(409, $ep24aMismatch['status'], 'EP-24A', 'Filtro incompatible con snapshot -> 409');
    assertSameVal('SNAPSHOT_FILTER_MISMATCH', $ep24aMismatch['json']['code'] ?? null, 'EP-24A', 'code=SNAPSHOT_FILTER_MISMATCH');

    // snapshot_id vacío -> 422
    assertSameVal(422, httpRequest('GET', $baseUrl . '/api/reportes/cierres-caja?snapshot_id=', $cookieAdmin)['status'], 'EP-24A', 'snapshot_id vacío -> 422');

    // Rango invertido -> 422 INVALID_DATE_RANGE
    $ep24aInvRange = httpRequest('GET', $baseUrl . '/api/reportes/cierres-caja?desde=2026-11-20&hasta=2026-11-01', $cookieAdmin);
    assertSameVal(422, $ep24aInvRange['status'], 'EP-24A', 'Rango invertido -> 422');
    assertSameVal('INVALID_DATE_RANGE', $ep24aInvRange['json']['code'] ?? null, 'EP-24A', 'code=INVALID_DATE_RANGE');

    // Snapshot ajeno (Admin 2 intenta leer snapshot de Admin 1) -> 404 SNAPSHOT_NOT_FOUND
    $ep24aForeignSnap = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja?snapshot_id={$snapId}", $cookieAdmin2);
    assertSameVal(404, $ep24aForeignSnap['status'], 'EP-24A', 'Snapshot ajeno -> 404');
    assertSameVal('SNAPSHOT_NOT_FOUND', $ep24aForeignSnap['json']['code'] ?? null, 'EP-24A', 'Snapshot ajeno code=SNAPSHOT_NOT_FOUND');

    // Expiración determinista con setClockProvider() -> 404 SNAPSHOT_NOT_FOUND sin DELETE físico
    $cajaSvc = new \Services\CajaService($pdo);
    $ahoraReal = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    $cajaSvc->setClockProvider(static fn (): DateTimeImmutable => $ahoraReal->modify('+31 minutes'));
    $expiredCode = null;
    $expiredStatus = 0;
    try {
        $cajaSvc->reporteCierresCaja(['snapshot_id' => $snapId], $adminId);
    } catch (\Libraries\Core\InventarioException $e) {
        $expiredStatus = $e->getHttpCode();
        $expiredCode = $e->getErrorCode();
    }
    assertSameVal(404, $expiredStatus, 'EP-24A', 'Snapshot expirado +31m -> 404');
    assertSameVal('SNAPSHOT_NOT_FOUND', $expiredCode, 'EP-24A', 'Snapshot expirado -> SNAPSHOT_NOT_FOUND');
    $stmtCntSnap = $pdo->prepare('SELECT COUNT(*) FROM reporte_consulta_snapshot WHERE id_snapshot = ?');
    $stmtCntSnap->execute([$snapId]);
    assertSameVal(1, (int)$stmtCntSnap->fetchColumn(), 'EP-24A', 'Snapshot expirado NO es eliminado físicamente');

    // 10. EP-24B: detalle cierre 1 (opening_difference=null, reference_schedule objeto) y sesión abierta -> 409 CASH_SESSION_NOT_CLOSED
    $ep24bS1 = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja/{$sesion1Id}", $cookieAdmin);
    assertSameVal(200, $ep24bS1['status'], 'EP-24B', 'Detalle cierre 1 -> 200');
    $d24b1 = $ep24bS1['json']['data'] ?? [];
    assertTrue(array_key_exists('opening_difference', $d24b1['closure'] ?? []) && $d24b1['closure']['opening_difference'] === null, 'EP-24B', 'Detalle primer cierre opening_difference=null');
    assertTrue(is_array($d24b1['closure']['reference_schedule'] ?? null) && isset($d24b1['closure']['reference_schedule']['label']), 'EP-24B', 'closure.reference_schedule es objeto');
    assertSameVal(20, $d24b1['pagination']['page_size'] ?? -1, 'EP-24B', 'Default page_size=20');
    assertSameVal(0, $d24b1['pagination']['total_movements'] ?? -1, 'EP-24B', 'total_movements=0');

    // EP-24B detalle cierre 2: verificar consistencia de MovimientoCajaResource en detalle del cierre
    $ep24bS2 = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja/{$sesion2Id}", $cookieAdmin);
    assertSameVal(200, $ep24bS2['status'], 'EP-24B', 'Detalle cierre 2 -> 200');
    $m24bById = [];
    foreach (($ep24bS2['json']['data']['movements'] ?? []) as $m24) {
        $m24bById[(int)$m24['id']] = $m24;
    }
    assertSameVal($sesion2Id, $m24bById[$ing1Id]['session_id'] ?? null, 'EP-24B', 'mov.session_id consistente');
    assertSameVal('Ingreso de sencillo', $m24bById[$ing1Id]['reason'] ?? null, 'EP-24B', 'mov.reason consistente');
    assertSameVal('Corregido', $m24bById[$ing1Id]['status'] ?? null, 'EP-24B', 'mov.status=Corregido consistente');
    assertSameVal(35.0, (float)($m24bById[$ing1Id]['current_amount'] ?? -1), 'EP-24B', 'mov.current_amount=35.0 consistente');
    assertSameVal('Corregido', $m24bById[$egr1Id]['status'] ?? null, 'EP-24B', 'egreso a cero status=Corregido consistente');
    assertSameVal(0.0, (float)($m24bById[$egr1Id]['current_amount'] ?? -1), 'EP-24B', 'egreso a cero current_amount=0.0 consistente');
    assertSameVal('Confirmado', $m24bById[(int)$inj1['json']['data']['movement']['id']]['status'] ?? null, 'EP-24B', 'inyección status=Confirmado');
    assertSameVal($adminId, $m24bById[(int)$inj1['json']['data']['movement']['id']]['administrator_id'] ?? null, 'EP-24B', 'inyección administrator_id consistente');
    assertSameVal(true, $m24bById[(int)$omit1['json']['data']['movement']['id']]['declared_occurred_in_open_origin_session'] ?? null, 'EP-24B', 'retiro omitido declared=true consistente');
    assertSameVal(true, $m24bById[(int)$omit1['json']['data']['movement']['id']]['confirmed_reflected_in_opening'] ?? null, 'EP-24B', 'retiro omitido confirmed=true consistente');
    assertSameVal('25.50', $pdo->query("SELECT monto FROM movimiento_caja WHERE id_movimiento_caja = {$ing1Id}")->fetchColumn(), 'CONSERVACION', 'Monto original inmutable en BD');

    // Abrir sesión 3 y verificar 409 CASH_SESSION_NOT_CLOSED en EP-24B
    $open3 = httpRequest('POST', $baseUrl . '/api/caja/abrir', $cookieSeller2, [
        'shift_id'     => $shiftId,
        'cash_counted' => $esperadoS2,
        'request_id'   => 'p12-open-3-' . uniqid(),
    ], $csrfS2);
    assertSameVal(201, $open3['status'], 'SETUP', 'Apertura sesión 3 HTTP 201');
    $sesion3Id = (int)($open3['json']['data']['session_id'] ?? 0);

    $ep24bOpen = httpRequest('GET', $baseUrl . "/api/reportes/cierres-caja/{$sesion3Id}", $cookieAdmin);
    assertSameVal(409, $ep24bOpen['status'], 'EP-24B', 'Sesión abierta en EP-24B -> 409');
    assertSameVal('CASH_SESSION_NOT_CLOSED', $ep24bOpen['json']['code'] ?? null, 'EP-24B', 'code=CASH_SESSION_NOT_CLOSED');
} finally {
    stopPhpServer($srv1);
    @unlink($cookieAdmin);
    @unlink($cookieAdmin2);
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
