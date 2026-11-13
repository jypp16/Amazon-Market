<?php
declare(strict_types=1);

/**
 * Batería integral de aceptación para el Paso 9 del Sprint 3
 * (EP-25: POST /api/ventas — Integración transaccional de ventas con caja).
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
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => ($method === 'HEAD'),
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => true,
    ]);
    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'json' => null, 'raw' => '', 'headers' => '', 'error' => "cURL {$errno}: {$err}"];
    }
    $respHeaders = substr($raw, 0, $headerSize);
    $respBody = substr($raw, $headerSize);
    $json = json_decode($respBody, true);

    return [
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'raw' => $respBody,
        'headers' => $respHeaders,
        'error' => '',
    ];
}

function startPhpServer(string $phpBin, string $rootDir, string $sessionDir, string $dbName, int $port): array
{
    $routerPath = $rootDir . '/tests/sprint3/server_router_sprint3.php';
    $env = array_merge($_ENV, getenv(), [
        'DB_NAME' => $dbName,
        'BASE_URL' => "http://127.0.0.1:{$port}",
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
        'PATH' => getenv('PATH') ?: '',
    ]);
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
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['file', sys_get_temp_dir() . "/am_paso9_srv_{$port}.out.log", 'w'],
        2 => ['file', sys_get_temp_dir() . "/am_paso9_srv_{$port}.err.log", 'w'],
    ];
    $proc = proc_open($cmd, $spec, $pipes, $rootDir, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException("No se pudo iniciar servidor PHP en puerto {$port}");
    }
    $baseUrl = "http://127.0.0.1:{$port}";
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $res = httpRequest($baseUrl, 'GET', '/Auth');
        if ($res['status'] > 0) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        proc_terminate($proc);
        throw new RuntimeException("Servidor PHP no respondió a tiempo en {$baseUrl}");
    }
    return ['proc' => $proc, 'pipes' => $pipes, 'url' => $baseUrl];
}

function stopPhpServer(array $srv): void
{
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

function loginHttp(string $baseUrl, string $username, string $password, string $cookieFile): array
{
    $authPage = httpRequest($baseUrl, 'GET', '/Auth', null, [], $cookieFile);
    $csrf = '';
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i', (string)$authPage['raw'], $m)) {
        $csrf = $m[1];
    }
    if ($csrf === '') {
        return ['login' => $authPage, 'csrf' => ''];
    }

    $ch = curl_init($baseUrl . '/Auth/login');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => $username,
            'password' => $password,
            'csrf_token' => $csrf,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'login' => ['status' => $code, 'raw' => (string)$resp],
        'csrf' => ($code === 302 && str_contains((string)$resp, '/Home')) ? $csrf : '',
    ];
}

function extractPhpSessId(string $cookieFile): string
{
    if (!is_file($cookieFile)) {
        return '';
    }
    $content = file_get_contents($cookieFile) ?: '';
    if (preg_match('/PHPSESSID\s+([^\r\n]+)/', $content, $m)) {
        return trim($m[1]);
    }
    return '';
}

function snapDb(PDO $pdo, int $idProd1, int $idProd2, int $idTipoComp): array
{
    return [
        'ventas' => (int)$pdo->query('SELECT COUNT(*) FROM venta')->fetchColumn(),
        'detalles' => (int)$pdo->query('SELECT COUNT(*) FROM detalle_venta')->fetchColumn(),
        'kardex' => (int)$pdo->query('SELECT COUNT(*) FROM kardex')->fetchColumn(),
        'mov_caja' => (int)$pdo->query('SELECT COUNT(*) FROM movimiento_caja')->fetchColumn(),
        'sesiones' => (int)$pdo->query('SELECT COUNT(*) FROM sesion_caja')->fetchColumn(),
        'stock1' => (int)$pdo->query("SELECT stock_actual FROM producto WHERE id_producto = {$idProd1}")->fetchColumn(),
        'stock2' => (int)$pdo->query("SELECT stock_actual FROM producto WHERE id_producto = {$idProd2}")->fetchColumn(),
        'corr' => (int)$pdo->query("SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante = {$idTipoComp} AND estado = 1 LIMIT 1")->fetchColumn(),
    ];
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, $dbName, DB_CHARSET);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tmpDir = sys_get_temp_dir() . '/am_paso9_' . uniqid();
$sessionDir = $tmpDir . '/sessions';
mkdir($sessionDir, 0777, true);

$srv1 = startPhpServer($phpBin, $rootDir, $sessionDir, $dbName, $httpPort1);
$srv2 = startPhpServer($phpBin, $rootDir, $sessionDir, $dbName, $httpPort2);

try {
    $url1 = $srv1['url'];
    $url2 = $srv2['url'];

    // Obtener usuarios de prueba (1 admin, 2 vendedores activos) y asegurar contraseñas conocidas en copia de prueba
    $admin = $pdo->query("SELECT id_usuario, username FROM usuario WHERE id_rol = 1 AND estado = 1 ORDER BY id_usuario ASC LIMIT 1")->fetch();
    $vendedores = $pdo->query("SELECT id_usuario, username FROM usuario WHERE id_rol = 2 AND estado = 1 ORDER BY id_usuario ASC LIMIT 2")->fetchAll();
    if (!$admin || count($vendedores) < 2) {
        throw new RuntimeException('Se requiere 1 administrador y 2 vendedores activos en la BD de prueba.');
    }
    $vend1 = $vendedores[0];
    $vend2 = $vendedores[1];

    $testPass = 'TestPaso9#2026';
    $hash = password_hash($testPass, PASSWORD_BCRYPT);
    $stmtPass = $pdo->prepare('UPDATE usuario SET password_hash = :p WHERE id_usuario = :id');
    foreach ([$admin['id_usuario'], $vend1['id_usuario'], $vend2['id_usuario']] as $uid) {
        $stmtPass->execute([':p' => $hash, ':id' => $uid]);
    }

    // Buscar productos activos con stock >= 20, cliente sin RUC (Boleta) y cliente con RUC (Factura)
    $prods = $pdo->query("SELECT id_producto, nombre, precio_venta, stock_actual FROM producto WHERE estado = 1 AND stock_actual >= 20 AND precio_venta > 0 ORDER BY id_producto ASC LIMIT 3")->fetchAll();
    $prod1 = $prods[0];
    $prod2 = $prods[1];
    $prod3 = $prods[2];
    $cliSinRuc = $pdo->query("SELECT id_cliente FROM cliente WHERE estado = 1 AND id_tipo_documento <> 2 ORDER BY id_cliente ASC LIMIT 1")->fetch();
    $cliConRuc = $pdo->query("SELECT id_cliente FROM cliente WHERE estado = 1 AND id_tipo_documento = 2 ORDER BY id_cliente ASC LIMIT 1")->fetch();
    $compBoleta = $pdo->query("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE estado = 1 AND nombre LIKE '%Boleta%' LIMIT 1")->fetch();
    $compFactura = $pdo->query("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE estado = 1 AND nombre LIKE '%Factura%' LIMIT 1")->fetch();

    $idProd1 = (int)$prod1['id_producto'];
    $idProd2 = (int)$prod2['id_producto'];
    $idProd3 = (int)$prod3['id_producto'];
    $idCliBoleta = (int)$cliSinRuc['id_cliente'];
    $idCliFactura = (int)$cliConRuc['id_cliente'];
    $idBoleta = (int)$compBoleta['id_tipo_comprobante'];
    $idFactura = (int)$compFactura['id_tipo_comprobante'];

    // Logins independientes
    $cookieAdmin = $tmpDir . '/admin.cookie';
    $cookieV1A = $tmpDir . '/v1a.cookie';
    $cookieV1B = $tmpDir . '/v1b.cookie';
    $cookieV2 = $tmpDir . '/v2.cookie';

    $authAdmin = loginHttp($url1, $admin['username'], $testPass, $cookieAdmin);
    $authV1A = loginHttp($url1, $vend1['username'], $testPass, $cookieV1A);
    $authV1B = loginHttp($url2, $vend1['username'], $testPass, $cookieV1B);
    $authV2 = loginHttp($url2, $vend2['username'], $testPass, $cookieV2);

    assertTrue($authAdmin['csrf'] !== '' && $authV1A['csrf'] !== '' && $authV1B['csrf'] !== '' && $authV2['csrf'] !== '', 'Auth', 'Logins y CSRF obtenidos');
    assertTrue(extractPhpSessId($cookieV1A) !== extractPhpSessId($cookieV1B), 'Auth', 'Sesiones PHP independientes para concurrencia (PHPSESSID A != PHPSESSID B)');

    $basePayload = [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 1,
        'productos' => [
            ['id_producto' => $idProd1, 'cantidad' => 2],
        ],
    ];

    // 1. No autenticado y CSRF inválido
    $snap0 = snapDb($pdo, $idProd1, $idProd2, $idBoleta);
    $resNoAuth = httpRequest($url1, 'POST', '/api/ventas', $basePayload + ['referencia_solicitud' => 'paso9_noauth_000001']);
    assertSameVal(401, $resNoAuth['status'], 'Auth', 'POST /api/ventas sin sesión -> 401');

    $resBadCsrf = httpRequest($url1, 'POST', '/api/ventas', $basePayload + ['referencia_solicitud' => 'paso9_badcsrf_00001'], ['X-CSRF-TOKEN' => 'token_invalido'], $cookieV1A);
    assertSameVal(403, $resBadCsrf['status'], 'Auth', 'POST /api/ventas con CSRF inválido -> 403');

    // 2. Admin rechazado en POST /api/ventas (403) y permitido en GET /api/ventas (200)
    $resAdminPost = httpRequest($url1, 'POST', '/api/ventas', $basePayload + ['referencia_solicitud' => 'paso9_admin_post_001'], ['X-CSRF-TOKEN' => $authAdmin['csrf']], $cookieAdmin);
    assertSameVal(403, $resAdminPost['status'], 'Auth', 'Admin bloqueado en POST /api/ventas -> 403');
    assertSameVal('FORBIDDEN', $resAdminPost['json']['code'] ?? null, 'Auth', 'Admin recibe code FORBIDDEN');

    $resAdminGet = httpRequest($url1, 'GET', '/api/ventas', null, [], $cookieAdmin);
    assertSameVal(200, $resAdminGet['status'], 'Auth', 'Admin conserva GET /api/ventas -> 200');

    // 3. Sesión de caja cerrada -> 409 CASH_SESSION_CLOSED y cero efectos
    $resClosed = httpRequest($url1, 'POST', '/api/ventas', $basePayload + ['referencia_solicitud' => 'paso9_closed_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $resClosed['status'], 'SesionCaja', 'Venta con caja cerrada -> 409');
    assertSameVal('CASH_SESSION_CLOSED', $resClosed['json']['code'] ?? null, 'SesionCaja', 'Code CASH_SESSION_CLOSED');
    assertSameVal($snap0, snapDb($pdo, $idProd1, $idProd2, $idBoleta), 'SesionCaja', 'Cero cambios en BD tras caja cerrada');

    // Abrir sesión para Vendedor 1 (monto_apertura = 100.00)
    $pdo->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES ('Turno Paso9', '07:00:00', '23:00:00', 1)");
    $idTurno = (int)$pdo->lastInsertId();
    $resOpen = httpRequest($url1, 'POST', '/api/caja/abrir', [
        'shift_id' => $idTurno,
        'cash_counted' => 100.00,
        'referencia_solicitud' => 'paso9_open_sess_0001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $resOpen['status'], 'SesionCaja', 'Vendedor 1 abre sesión de caja -> 201');
    $idSesionV1 = (int)($resOpen['json']['data']['session_id'] ?? $resOpen['json']['data']['id_sesion_caja'] ?? 0);
    assertTrue($idSesionV1 > 0, 'SesionCaja', 'ID sesión abierta válido');

    // 4. Otro vendedor intenta vender en sesión abierta de Vendedor 1 -> 403 SESSION_NOT_OWNED
    $snapBeforeV2 = snapDb($pdo, $idProd1, $idProd2, $idBoleta);
    $resNotOwned = httpRequest($url2, 'POST', '/api/ventas', $basePayload + ['referencia_solicitud' => 'paso9_not_owned_001'], ['X-CSRF-TOKEN' => $authV2['csrf']], $cookieV2);
    assertSameVal(403, $resNotOwned['status'], 'SesionCaja', 'Otro vendedor bloqueado -> 403');
    assertSameVal('SESSION_NOT_OWNED', $resNotOwned['json']['code'] ?? null, 'SesionCaja', 'Code SESSION_NOT_OWNED');
    assertSameVal($snapBeforeV2, snapDb($pdo, $idProd1, $idProd2, $idBoleta), 'SesionCaja', 'Cero cambios tras SESSION_NOT_OWNED');

    // 5. Validaciones de alias, X-Idempotency-Key, booleanos, Factura sin RUC, stock insuficiente
    $resAmb1 = httpRequest($url1, 'POST', '/api/ventas', $basePayload + [
        'referencia_solicitud' => 'paso9_alias_uno_0001',
        'request_id' => 'paso9_alias_dos_0002',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(422, $resAmb1['status'], 'Validacion', 'Alias contradictorios body -> 422');
    assertSameVal('AMBIGUOUS_ALIAS_VALUES', $resAmb1['json']['code'] ?? null, 'Validacion', 'Code AMBIGUOUS_ALIAS_VALUES body');

    $resAmb2 = httpRequest($url1, 'POST', '/api/ventas', $basePayload + [
        'referencia_solicitud' => 'paso9_alias_uno_0001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf'], 'X-Request-Reference' => 'paso9_alias_hdr_0003'], $cookieV1A);
    assertSameVal(422, $resAmb2['status'], 'Validacion', 'Alias contradictorio body vs header -> 422');
    assertSameVal('AMBIGUOUS_ALIAS_VALUES', $resAmb2['json']['code'] ?? null, 'Validacion', 'Code AMBIGUOUS_ALIAS_VALUES header');

    $resOldHeader = httpRequest($url1, 'POST', '/api/ventas', $basePayload, [
        'X-CSRF-TOKEN' => $authV1A['csrf'],
        'X-Idempotency-Key' => 'paso9_legacy_hdr_0001',
    ], $cookieV1A);
    assertSameVal(422, $resOldHeader['status'], 'Validacion', 'X-Idempotency-Key no aceptada como referencia -> 422');

    $resBool = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => true,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'referencia_solicitud' => 'paso9_bool_check_001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(422, $resBool['status'], 'Validacion', 'Booleano rechazado en id_metodo_pago -> 422');

    $resFacNoRuc = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idFactura,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'referencia_solicitud' => 'paso9_fac_noruc_0001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(422, $resFacNoRuc['status'], 'Validacion', 'Factura sin RUC rechazada -> 422');

    $resNoStock = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 999999]],
        'referencia_solicitud' => 'paso9_nostock_000001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $resNoStock['status'], 'Validacion', 'Stock insuficiente -> 409 INSUFFICIENT_STOCK');
    assertSameVal($snapBeforeV2, snapDb($pdo, $idProd1, $idProd2, $idBoleta), 'Validacion', 'Cero efectos tras errores de validación/stock');

    // 5b. Router de pruebas bloquea .env y suites internas; acepta /__test_env_verify
    $resEnvHead = httpRequest($url1, 'HEAD', '/.env');
    assertSameVal(403, $resEnvHead['status'], 'RouterSeguridad', 'HEAD /.env bloqueado con 403');
    $resEnvGet = httpRequest($url1, 'GET', '/.env');
    assertSameVal(403, $resEnvGet['status'], 'RouterSeguridad', 'GET /.env bloqueado con 403');
    $resSuiteHttp = httpRequest($url1, 'GET', '/tests/sprint2/fase2_infraestructura_test.php');
    assertSameVal(403, $resSuiteHttp['status'], 'RouterSeguridad', 'Suite interna bloqueada por HTTP con 403');
    $resEnvVerify = httpRequest($url1, 'GET', '/__test_env_verify');
    assertSameVal(200, $resEnvVerify['status'], 'RouterSeguridad', '/__test_env_verify sigue operativo');

    // 5c. Envoltorios FORBIDDEN, {} -> 422 VALIDATION_ERROR, JSON malformado -> 400 INVALID_JSON
    assertTrue(array_key_exists('errors', $resAdminPost['json'] ?? []) && is_array($resAdminPost['json']['errors']) && array_key_exists('data', $resAdminPost['json'] ?? []) && $resAdminPost['json']['data'] === null, 'Validacion', 'FORBIDDEN respeta envoltorio errors/data');
    $resEmptyObj = httpRequest($url1, 'POST', '/api/ventas', null, ['X-CSRF-TOKEN' => $authV1A['csrf'], 'Content-Type' => 'application/json'], $cookieV1A, '{}');
    assertSameVal(422, $resEmptyObj['status'], 'Validacion', 'Cuerpo {} -> 422 VALIDATION_ERROR (no 400 INVALID_REQUEST)');
    assertSameVal('VALIDATION_ERROR', $resEmptyObj['json']['code'] ?? null, 'Validacion', 'Cuerpo {} -> code VALIDATION_ERROR');
    $resBadJson = httpRequest($url1, 'POST', '/api/ventas', null, ['X-CSRF-TOKEN' => $authV1A['csrf'], 'Content-Type' => 'application/json'], $cookieV1A, '{bad');
    assertSameVal(400, $resBadJson['status'], 'Validacion', 'JSON malformado -> 400 INVALID_JSON');
    assertSameVal('INVALID_JSON', $resBadJson['json']['code'] ?? null, 'Validacion', 'JSON malformado -> code INVALID_JSON');

    // 5d. id_metodo_pago obligatorio: ausente -> 422, referencia reutilizable después
    $refReutilizable = 'paso9_missing_mp_0001';
    $resMissingMp = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'referencia_solicitud' => $refReutilizable,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(422, $resMissingMp['status'], 'Validacion', 'id_metodo_pago ausente -> 422 VALIDATION_ERROR');
    assertSameVal('VALIDATION_ERROR', $resMissingMp['json']['code'] ?? null, 'Validacion', 'id_metodo_pago ausente code VALIDATION_ERROR');
    assertTrue(isset($resMissingMp['json']['errors']['id_metodo_pago']), 'Validacion', 'id_metodo_pago ausente reporta error en id_metodo_pago');
    assertSameVal($snapBeforeV2, snapDb($pdo, $idProd1, $idProd2, $idBoleta), 'Validacion', 'Cero efectos tras id_metodo_pago ausente');

    $resNullMp = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => null,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'referencia_solicitud' => 'paso9_null_mp_0000001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(422, $resNullMp['status'], 'Validacion', 'id_metodo_pago null -> 422 VALIDATION_ERROR');

    // Reutilizar $refReutilizable en una solicitud válida debe confirmar con 201
    $resReuseMp = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 2,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'referencia_solicitud' => $refReutilizable,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $resReuseMp['status'], 'Validacion', 'Referencia rechazada por falta de id_metodo_pago es reutilizable -> 201');
    // Restaurar stock de prod1 consumido en la prueba de reutilización para cálculos siguientes no afectados (no afecta cálculos dinámicos)

    // Helper para insertar arqueo VIGENTE en BD
    $insertArqueoVigente = function (int $idSes, int $uid, int $ver, string $esperado) use ($pdo): int {
        $stmtCur = $pdo->prepare('SELECT version_caja, efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = ? LIMIT 1');
        $stmtCur->execute([$idSes]);
        $cur = $stmtCur->fetch(PDO::FETCH_ASSOC);
        $verActual = (int)($cur['version_caja'] ?? $ver);
        $espActual = (string)($cur['efectivo_esperado_actual'] ?? $esperado);
        $pdo->prepare("UPDATE arqueo_caja SET estado = 'REEMPLAZADO', vigencia_activa = NULL, causa_cambio_estado = 'NUEVO_ARQUEO', fecha_hora_cambio_estado = NOW() WHERE id_sesion_caja = ? AND estado = 'VIGENTE'")
            ->execute([$idSes]);
        $stmt = $pdo->prepare(
            "INSERT INTO arqueo_caja (id_sesion_caja, id_usuario_vendedor, version_caja_evaluada, monto_esperado, monto_contado, diferencia, observacion, estado, vigencia_activa)
             VALUES (:s, :u, :v, :e, :e, 0.00, NULL, 'VIGENTE', 1)"
        );
        $stmt->execute([':s' => $idSes, ':u' => $uid, ':v' => $verActual, ':e' => $espActual]);
        $idArq = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE sesion_caja SET id_ultimo_arqueo = :a WHERE id_sesion_caja = :s')
            ->execute([':a' => $idArq, ':s' => $idSes]);
        return $idArq;
    };

    // 6. Venta 1: Efectivo (id_metodo_pago = 1) con suplantación ignorada de id_usuario/id_sesion_caja e invalidación de arqueo
    $idArq1 = $insertArqueoVigente($idSesionV1, (int)$vend1['id_usuario'], 2, '100.00');
    $refEfectivo = 'paso9_sale_efectivo_001';
    $resVenta1 = httpRequest($url1, 'POST', '/api/ventas', [
        'id_usuario' => (int)$admin['id_usuario'],
        'id_sesion_caja' => 99999,
        'id_cliente' => $idCliFactura,
        'id_tipo_comprobante' => $idFactura,
        'id_metodo_pago' => 1,
        'productos' => [
            ['id_producto' => $idProd1, 'cantidad' => 2],
            ['id_producto' => $idProd2, 'cantidad' => 1],
        ],
        'referencia_solicitud' => $refEfectivo,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);

    assertSameVal(201, $resVenta1['status'], 'VentasCaja', 'Venta Efectivo -> 201');
    assertSameVal(true, $resVenta1['json']['status'] ?? null, 'VentasCaja', 'Venta Efectivo status exterior = true');
    assertSameVal('SALE_CREATED', $resVenta1['json']['code'] ?? null, 'VentasCaja', 'Venta Efectivo code exterior = SALE_CREATED');
    assertSameVal(false, $resVenta1['json']['idempotent_replay'] ?? null, 'VentasCaja', 'Venta Efectivo idempotent_replay exterior = false');
    $d1 = $resVenta1['json']['data'] ?? [];
    assertSameVal('SALE_CREATED', $d1['result_code'] ?? null, 'VentasCaja', 'result_code = SALE_CREATED');
    assertSameVal($idSesionV1, $d1['id_sesion_caja'] ?? null, 'VentasCaja', 'id_sesion_caja asignado del servidor');
    assertSameVal(1, $d1['id_metodo_pago'] ?? null, 'VentasCaja', 'id_metodo_pago = 1');
    assertSameVal(3, $d1['version_caja'] ?? null, 'VentasCaja', 'version_caja incrementada a 3');
    assertTrue(is_int($d1['total'] ?? null) || is_float($d1['total'] ?? null), 'VentasCaja', 'data.total es número JSON');
    assertTrue(is_int($d1['current_cash_balance'] ?? null) || is_float($d1['current_cash_balance'] ?? null), 'VentasCaja', 'data.current_cash_balance es número JSON');

    $expectedTotal1Num = round((2 * (float)$prod1['precio_venta']) + (float)$prod2['precio_venta'], 2);
    $expectedTotal1 = number_format($expectedTotal1Num, 2, '.', '');
    $expectedBal1Num = round(100.00 + $expectedTotal1Num, 2);
    $expectedBal1 = number_format($expectedBal1Num, 2, '.', '');
    assertTrue(abs((float)($d1['total'] ?? -1) - $expectedTotal1Num) < 0.001, 'VentasCaja', 'total calculado exactamente');
    assertTrue(abs((float)($d1['current_cash_balance'] ?? -1) - $expectedBal1Num) < 0.001, 'VentasCaja', 'current_cash_balance actualizado');

    $idVenta1 = (int)($d1['id_venta'] ?? 0);
    $vRow1 = $pdo->query("SELECT id_usuario, id_sesion_caja, total, id_metodo_pago FROM venta WHERE id_venta = {$idVenta1}")->fetch();
    assertSameVal((int)$vend1['id_usuario'], (int)$vRow1['id_usuario'], 'VentasCaja', 'venta.id_usuario es el vendedor autenticado (no admin)');
    assertSameVal($idSesionV1, (int)$vRow1['id_sesion_caja'], 'VentasCaja', 'venta.id_sesion_caja persistido');

    $mcRow1 = $pdo->query("SELECT * FROM movimiento_caja WHERE id_venta = {$idVenta1}")->fetchAll();
    assertSameVal(1, count($mcRow1), 'VentasCaja', '1 fila VENTA_EFECTIVO insertada en movimiento_caja');
    assertSameVal('VENTA_EFECTIVO', $mcRow1[0]['tipo_movimiento'], 'VentasCaja', 'tipo_movimiento = VENTA_EFECTIVO');
    assertSameVal($expectedTotal1, $mcRow1[0]['efecto_saldo'] ?? null, 'VentasCaja', 'efecto_saldo = total');
    assertSameVal($expectedTotal1, $mcRow1[0]['monto'] ?? null, 'VentasCaja', 'monto movimiento = total');

    $arq1Estado = $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArq1}")->fetchColumn();
    assertSameVal('DESACTUALIZADO', $arq1Estado, 'VentasCaja', 'Arqueo Vigente invalidado a DESACTUALIZADO tras venta Efectivo');

    // 7. Venta 2: Tarjeta (id_metodo_pago = 2) usando alias request_id
    $idArq2 = $insertArqueoVigente($idSesionV1, (int)$vend1['id_usuario'], 2, $expectedBal1);
    $resVenta2 = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 2,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'request_id' => 'paso9_sale_tarjeta_0001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $resVenta2['status'], 'VentasCaja', 'Venta Tarjeta con request_id -> 201');
    $d2 = $resVenta2['json']['data'] ?? [];
    $idVenta2 = (int)($d2['id_venta'] ?? 0);
    assertTrue(abs((float)($d2['current_cash_balance'] ?? -1) - $expectedBal1Num) < 0.001, 'VentasCaja', 'Tarjeta NO modifica current_cash_balance');
    assertSameVal(4, $d2['version_caja'] ?? null, 'VentasCaja', 'Tarjeta incrementa version_caja a 4');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM movimiento_caja WHERE id_venta = {$idVenta2}")->fetchColumn(), 'VentasCaja', 'Tarjeta NO inserta fila en movimiento_caja');
    assertSameVal('DESACTUALIZADO', $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArq2}")->fetchColumn(), 'VentasCaja', 'Arqueo Vigente invalidado tras venta Tarjeta');

    // 8. Venta 3: Yape / Plin (id_metodo_pago = 3) usando cabecera X-Request-Reference
    $idArq3 = $insertArqueoVigente($idSesionV1, (int)$vend1['id_usuario'], 3, $expectedBal1);
    $resVenta3 = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 3,
        'productos' => [['id_producto' => $idProd2, 'cantidad' => 2]],
    ], ['X-CSRF-TOKEN' => $authV1A['csrf'], 'X-Request-Reference' => 'paso9_sale_yape_0000001'], $cookieV1A);
    assertSameVal(201, $resVenta3['status'], 'VentasCaja', 'Venta Yape / Plin con X-Request-Reference -> 201');
    $d3 = $resVenta3['json']['data'] ?? [];
    $idVenta3 = (int)($d3['id_venta'] ?? 0);
    assertTrue(abs((float)($d3['current_cash_balance'] ?? -1) - $expectedBal1Num) < 0.001, 'VentasCaja', 'Yape / Plin NO modifica current_cash_balance');
    assertSameVal(5, $d3['version_caja'] ?? null, 'VentasCaja', 'Yape / Plin incrementa version_caja a 5');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM movimiento_caja WHERE id_venta = {$idVenta3}")->fetchColumn(), 'VentasCaja', 'Yape / Plin NO inserta fila en movimiento_caja');
    assertSameVal('DESACTUALIZADO', $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArq3}")->fetchColumn(), 'VentasCaja', 'Arqueo Vigente invalidado tras venta Yape / Plin');

    $sesCheck = $pdo->query("SELECT total_ventas_efectivo, total_ventas_tarjeta, total_ventas_yape_plin, efectivo_esperado_actual, version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesionV1}")->fetch();
    assertSameVal($expectedTotal1, $sesCheck['total_ventas_efectivo'], 'VentasCaja', 'total_ventas_efectivo correcto en BD');
    $expectedTarjetaTot = number_format((float)$resReuseMp['json']['data']['total'] + (float)$d2['total'], 2, '.', '');
    assertSameVal($expectedTarjetaTot, $sesCheck['total_ventas_tarjeta'], 'VentasCaja', 'total_ventas_tarjeta correcto en BD');
    assertSameVal(number_format((float)$d3['total'], 2, '.', ''), $sesCheck['total_ventas_yape_plin'], 'VentasCaja', 'total_ventas_yape_plin correcto en BD');
    assertSameVal($expectedBal1, $sesCheck['efectivo_esperado_actual'], 'VentasCaja', 'efectivo_esperado_actual correcto en BD');
    assertSameVal(5, (int)$sesCheck['version_caja'], 'VentasCaja', 'version_caja final = 5 en BD');

    // 9. Idempotencia: replay (200) y payload mismatch (409)
    $snapBeforeReplay = snapDb($pdo, $idProd1, $idProd2, $idBoleta);
    $resReplay1 = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliFactura,
        'id_tipo_comprobante' => $idFactura,
        'id_metodo_pago' => 1,
        'productos' => [
            ['id_producto' => $idProd1, 'cantidad' => 2],
            ['id_producto' => $idProd2, 'cantidad' => 1],
        ],
        'referencia_solicitud' => $refEfectivo,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $resReplay1['status'], 'Idempotencia', 'Replay misma referencia y payload -> 200');
    assertSameVal('OPERATION_ALREADY_CONFIRMED', $resReplay1['json']['code'] ?? null, 'Idempotencia', 'Replay code exterior = OPERATION_ALREADY_CONFIRMED');
    assertSameVal(true, $resReplay1['json']['idempotent_replay'] ?? null, 'Idempotencia', 'Replay idempotent_replay = true');
    assertSameVal('SALE_CREATED', $resReplay1['json']['data']['result_code'] ?? null, 'Idempotencia', 'Replay conserva data.result_code original = SALE_CREATED');
    assertSameVal($d1, $resReplay1['json']['data'] ?? null, 'Idempotencia', 'Replay devuelve data exactamente igual a confirmación original');
    assertSameVal($snapBeforeReplay, snapDb($pdo, $idProd1, $idProd2, $idBoleta), 'Idempotencia', 'Replay no duplica registros ni cambia stock/correlativo');

    $resMismatch = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliFactura,
        'id_tipo_comprobante' => $idFactura,
        'id_metodo_pago' => 1,
        'productos' => [
            ['id_producto' => $idProd1, 'cantidad' => 3],
        ],
        'referencia_solicitud' => $refEfectivo,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $resMismatch['status'], 'Idempotencia', 'Misma referencia con payload distinto -> 409');
    assertSameVal('IDEMPOTENCY_PAYLOAD_MISMATCH', $resMismatch['json']['code'] ?? null, 'Idempotencia', 'Code IDEMPOTENCY_PAYLOAD_MISMATCH');

    // 10. Overflow DECIMAL(10,2) -> 409 BALANCE_OVERFLOW y rollback completo
    $pdo->prepare('UPDATE producto SET precio_venta = 60000000.00 WHERE id_producto = :id')->execute([':id' => $idProd3]);
    $snapBeforeOverflow = snapDb($pdo, $idProd3, $idProd2, $idBoleta);
    $resOverflow = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $idProd3, 'cantidad' => 2]],
        'referencia_solicitud' => 'paso9_overflow_test_01',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $resOverflow['status'], 'Overflow', 'Desbordamiento DECIMAL(10,2) -> 409');
    assertSameVal('BALANCE_OVERFLOW', $resOverflow['json']['code'] ?? null, 'Overflow', 'Code BALANCE_OVERFLOW');
    assertSameVal($snapBeforeOverflow, snapDb($pdo, $idProd3, $idProd2, $idBoleta), 'Overflow', 'Rollback total tras BALANCE_OVERFLOW');

    // 10b. Fallo controlado DESPUÉS de escrituras reales (venta, detalle, stock, kardex, correlativo, movimiento_caja)
    $idArqRb = $insertArqueoVigente($idSesionV1, (int)$vend1['id_usuario'], 5, $expectedBal1);
    $snapBeforeRb = snapDb($pdo, $idProd1, $idProd2, $idBoleta);
    $sesBeforeRb = $pdo->query("SELECT total_ventas_efectivo, efectivo_esperado_actual, version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesionV1}")->fetch();
    $refRollback = 'paso9_rollback_writes_01';

    $pdo->exec("DROP TRIGGER IF EXISTS trg_test_paso9_rollback_after_writes");
    $pdo->exec("
        CREATE TRIGGER trg_test_paso9_rollback_after_writes
        BEFORE UPDATE ON sesion_caja
        FOR EACH ROW
        BEGIN
            IF NEW.id_sesion_caja = {$idSesionV1} AND NEW.version_caja > OLD.version_caja THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TEST_CONTROLLED_ROLLBACK_AFTER_WRITES';
            END IF;
        END
    ");

    $resRb = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'referencia_solicitud' => $refRollback,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);

    $pdo->exec("DROP TRIGGER IF EXISTS trg_test_paso9_rollback_after_writes");

    assertSameVal(500, $resRb['status'], 'RollbackPostEscritura', 'Fallo posterior a escrituras responde 500');
    assertSameVal('INTERNAL_ERROR', $resRb['json']['code'] ?? null, 'RollbackPostEscritura', 'Code INTERNAL_ERROR sin filtrar SQL');
    assertTrue(array_key_exists('errors', $resRb['json'] ?? []) && array_key_exists('data', $resRb['json'] ?? []) && $resRb['json']['data'] === null, 'RollbackPostEscritura', 'Envoltorio INTERNAL_ERROR respeta errors y data=null');
    assertTrue(!str_contains((string)$resRb['raw'], 'SQLSTATE') && !str_contains((string)$resRb['raw'], 'TEST_CONTROLLED'), 'RollbackPostEscritura', 'Mensaje seguro no expone detalles SQL');
    assertSameVal($snapBeforeRb, snapDb($pdo, $idProd1, $idProd2, $idBoleta), 'RollbackPostEscritura', 'Rollback total de venta, detalle, stock, kardex, correlativo y movimiento_caja');
    assertSameVal($sesBeforeRb, $pdo->query("SELECT total_ventas_efectivo, efectivo_esperado_actual, version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesionV1}")->fetch(), 'RollbackPostEscritura', 'Rollback total de acumulados y version_caja');
    assertSameVal('VIGENTE', $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArqRb}")->fetchColumn(), 'RollbackPostEscritura', 'Arqueo Vigente conservado intacto tras rollback');

    $stmtSolRb = $pdo->prepare("SELECT estado_solicitud FROM control_solicitud_idempotencia WHERE id_usuario = :u AND tipo_operacion = 'VENTA' AND referencia_solicitud = :r LIMIT 1");
    $stmtSolRb->execute([':u' => (int)$vend1['id_usuario'], ':r' => $refRollback]);
    $estadoSolRb = $stmtSolRb->fetchColumn();
    assertTrue($estadoSolRb === false || $estadoSolRb !== 'CONFIRMADA', 'RollbackPostEscritura', 'Solicitud fallida nunca queda CONFIRMADA');

    // Reintentar con la misma referencia tras retirar el fallo controlado -> una única confirmación 201 SALE_CREATED
    $resRbRetry = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'referencia_solicitud' => $refRollback,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $resRbRetry['status'], 'RollbackPostEscritura', 'Reintento con misma referencia tras rollback -> 201 SALE_CREATED');
    assertSameVal('SALE_CREATED', $resRbRetry['json']['code'] ?? null, 'RollbackPostEscritura', 'Reintento code exterior = SALE_CREATED');
    assertSameVal(6, $resRbRetry['json']['data']['version_caja'] ?? null, 'RollbackPostEscritura', 'Reintento incrementa version_caja a 6 una sola vez');
    assertSameVal($snapBeforeRb['corr'] + 1, (int)$pdo->query("SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante = {$idBoleta} AND estado = 1 LIMIT 1")->fetchColumn(), 'RollbackPostEscritura', 'Correlativo comercial avanza exactamente 1 sin huecos');

    // 11. Concurrencia real HTTP (2 sesiones independientes de Vendedor 1 compiten por la última unidad de stock)
    $pdo->prepare('UPDATE producto SET stock_actual = 1 WHERE id_producto = :id')->execute([':id' => $idProd3]);
    $pdo->prepare('UPDATE producto SET precio_venta = 10.00 WHERE id_producto = :id')->execute([':id' => $idProd3]);

    $mh = curl_multi_init();
    $mkHandle = function (string $url, string $csrf, string $cookie, string $ref) use ($idCliBoleta, $idBoleta, $idProd3) {
        $ch = curl_init($url . '/api/ventas');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-TOKEN: {$csrf}"],
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => json_encode([
                'id_cliente' => $idCliBoleta,
                'id_tipo_comprobante' => $idBoleta,
                'id_metodo_pago' => 1,
                'productos' => [['id_producto' => $idProd3, 'cantidad' => 1]],
                'referencia_solicitud' => $ref,
            ]),
        ]);
        return $ch;
    };
    $chA = $mkHandle($url1, $authV1A['csrf'], $cookieV1A, 'paso9_conc_stock_A_001');
    $chB = $mkHandle($url2, $authV1B['csrf'], $cookieV1B, 'paso9_conc_stock_B_002');
    curl_multi_add_handle($mh, $chA);
    curl_multi_add_handle($mh, $chB);
    do {
        $mrc = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.05);
        }
    } while ($active && $mrc == CURLM_OK);

    $stA = (int)curl_getinfo($chA, CURLINFO_HTTP_CODE);
    $stB = (int)curl_getinfo($chB, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $chA);
    curl_multi_remove_handle($mh, $chB);
    curl_close($chA);
    curl_close($chB);
    curl_multi_close($mh);

    $statusesStock = [$stA, $stB];
    sort($statusesStock);
    assertSameVal([201, 409], $statusesStock, 'Concurrencia', 'Competencia por última unidad: exactamente 1 gana (201) y 1 falla (409)');
    assertSameVal(0, (int)$pdo->query("SELECT stock_actual FROM producto WHERE id_producto = {$idProd3}")->fetchColumn(), 'Concurrencia', 'Stock final exacto = 0 sin sobreventa');

    // 12. Concurrencia venta vs cierre sobre bloqueo sesion_caja FOR UPDATE + replay post-cierre
    $lockSignalFile = $tmpDir . '/worker_locked.sig';
    $workerPath = $rootDir . '/tests/sprint3/worker_concurrencia_paso9.php';
    $workerEnv = array_merge($_ENV, getenv(), ['TEST_DB_NAME' => $dbName]);
    $wProc = proc_open(
        [$phpBin, $workerPath, (string)$idSesionV1, '900', $lockSignalFile],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $wPipes,
        $rootDir,
        $workerEnv
    );
    $locked = false;
    for ($i = 0; $i < 40; $i++) {
        if (is_file($lockSignalFile)) {
            $locked = true;
            break;
        }
        usleep(50000);
    }
    assertTrue($locked, 'Concurrencia', 'Worker adquirió bloqueo FOR UPDATE sobre sesion_caja');

    $resRaceClose = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliBoleta,
        'id_tipo_comprobante' => $idBoleta,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $idProd1, 'cantidad' => 1]],
        'referencia_solicitud' => 'paso9_race_close_00001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);

    $wOut = stream_get_contents($wPipes[1]);
    $wErr = stream_get_contents($wPipes[2]);
    foreach ($wPipes as $wp) {
        if (is_resource($wp)) {
            fclose($wp);
        }
    }
    proc_close($wProc);
    assertSameVal(409, $resRaceClose['status'], 'Concurrencia', 'Venta concurrente con cierre que confirma primero -> 409');
    assertSameVal('CASH_SESSION_CLOSED', $resRaceClose['json']['code'] ?? null, 'Concurrencia', 'Code CASH_SESSION_CLOSED tras esperar bloqueo de cierre');
    assertSameVal('CERRADA', $pdo->query("SELECT estado FROM sesion_caja WHERE id_sesion_caja = {$idSesionV1}")->fetchColumn(), 'Concurrencia', 'Sesión quedó efectivamente CERRADA por el worker');

    // Replay post-cierre de Venta 1 debe seguir respondiendo 200 OPERATION_ALREADY_CONFIRMED
    $resReplayPostClose = httpRequest($url1, 'POST', '/api/ventas', [
        'id_cliente' => $idCliFactura,
        'id_tipo_comprobante' => $idFactura,
        'id_metodo_pago' => 1,
        'productos' => [
            ['id_producto' => $idProd1, 'cantidad' => 2],
            ['id_producto' => $idProd2, 'cantidad' => 1],
        ],
        'referencia_solicitud' => $refEfectivo,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $resReplayPostClose['status'], 'Idempotencia', 'Replay post-cierre -> 200');
    assertSameVal('OPERATION_ALREADY_CONFIRMED', $resReplayPostClose['json']['code'] ?? null, 'Idempotencia', 'Replay post-cierre code exterior = OPERATION_ALREADY_CONFIRMED');
    assertSameVal(true, $resReplayPostClose['json']['idempotent_replay'] ?? null, 'Idempotencia', 'Replay post-cierre idempotent_replay = true');
    assertSameVal('SALE_CREATED', $resReplayPostClose['json']['data']['result_code'] ?? null, 'Idempotencia', 'Replay post-cierre conserva data.result_code = SALE_CREATED');

    // 13. Conservación de ventas históricas (250 con id_sesion_caja IS NULL)
    $histNull = (int)$pdo->query('SELECT COUNT(*) FROM venta WHERE id_sesion_caja IS NULL')->fetchColumn();
    assertSameVal(250, $histNull, 'Conservacion', '250 ventas históricas mantienen id_sesion_caja IS NULL');

} finally {
    stopPhpServer($srv1);
    stopPhpServer($srv2);
    @array_map('unlink', glob($tmpDir . '/*') ?: []);
    @rmdir($tmpDir);
}

echo "\n=== RESUMEN PASO 9 ===\n";
foreach ($categories as $cat => $c) {
    echo "  {$cat}: {$c['passed']}/" . ($c['passed'] + $c['failed']) . "\n";
}
echo "TOTAL: {$passed}/" . ($passed + $failed) . " aserciones correctas.\n";
exit($failed > 0 ? 1 : 0);
