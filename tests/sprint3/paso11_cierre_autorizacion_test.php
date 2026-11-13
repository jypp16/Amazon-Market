<?php
declare(strict_types=1);

/**
 * Batería integral de aceptación para el Paso 11 del Sprint 3
 * (EP-20: Cierre definitivo + Autorización anticipada, EP-21: Recuperación de cierre).
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
            $ready = true;
            break;
        }
    }
    if (!$ready) {
        stopPhpServer(['proc' => $proc, 'pipes' => $pipes]);
        throw new RuntimeException("Servidor PHP no respondió en {$baseUrl}");
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

function httpRequest(string $baseUrl, string $method, string $path, ?array $body = null, array $headers = [], ?string $cookieFile = null, ?string $rawBody = null): array
{
    $ch = curl_init($baseUrl . $path);
    $hdrs = [];
    if ($body !== null && $rawBody === null) {
        $rawBody = json_encode($body, JSON_UNESCAPED_UNICODE);
        $hdrs[] = 'Content-Type: application/json';
    }
    foreach ($headers as $k => $v) {
        $hdrs[] = "{$k}: {$v}";
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_TIMEOUT        => 20,
    ];
    if ($rawBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = $rawBody;
    }
    if ($cookieFile !== null) {
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($resp) ? json_decode($resp, true) : null;
    return ['status' => $status, 'raw' => $resp, 'json' => is_array($json) ? $json : null];
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

    $home = httpRequest($baseUrl, 'GET', '/Dashboard', null, [], $cookieFile);
    $csrf2 = $csrf;
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/i', (string)$home['raw'], $m2)) {
        $csrf2 = $m2[1];
    } elseif (preg_match('/csrf_token["\']?\s*[:=]\s*["\']([a-f0-9]{64})["\']/i', (string)$home['raw'], $m2)) {
        $csrf2 = $m2[1];
    }
    return ['login' => ['status' => $code], 'csrf' => $csrf2];
}

function extractPhpSessionId(string $cookieFile): string
{
    if (!is_file($cookieFile)) {
        return '';
    }
    $lines = file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (str_starts_with($line, '#HttpOnly_')) {
            $line = substr($line, strlen('#HttpOnly_'));
        } elseif (str_starts_with($line, '#')) {
            continue;
        }
        $cols = explode("\t", $line);
        if (count($cols) >= 7 && str_contains(strtoupper($cols[5]), 'SESS')) {
            return (string)$cols[6];
        }
    }
    return '';
}

$pdo = \Libraries\Core\Conexion::getInstance()->conect();
$tmpDir = sys_get_temp_dir() . '/am_paso11_test_' . uniqid();
$sessionDir = $tmpDir . '/sessions';
@mkdir($sessionDir, 0777, true);

// Preparar usuarios de prueba con contraseñas conocidas en la copia aislada
$passPlain = 'Paso11Secret#2026';
$passHash = password_hash($passPlain, PASSWORD_BCRYPT);
$adminEmail = 'admin.auth.p11@amazonmarket.local';
$admin2Email = 'admin2.auth.p11@amazonmarket.local';

$pdo->exec("UPDATE usuario SET estado = 1");
$vend1 = $pdo->query("SELECT id_usuario, username FROM usuario WHERE id_rol = 2 AND estado = 1 ORDER BY id_usuario ASC LIMIT 1")->fetch();
$vend2 = $pdo->query("SELECT id_usuario, username FROM usuario WHERE id_rol = 2 AND estado = 1 AND id_usuario <> " . (int)$vend1['id_usuario'] . " ORDER BY id_usuario ASC LIMIT 1")->fetch();
$admin1 = $pdo->query("SELECT id_usuario, username FROM usuario WHERE id_rol = 1 AND estado = 1 ORDER BY id_usuario ASC LIMIT 1")->fetch();

// Crear segundo administrador activo con correo conocido para autorizar, y otro inactivo para prueba
$pdo->prepare("UPDATE usuario SET password_hash = ? WHERE id_usuario IN (?, ?, ?)")->execute([
    $passHash,
    (int)$vend1['id_usuario'],
    (int)$vend2['id_usuario'],
    (int)$admin1['id_usuario'],
]);
$pdo->prepare("UPDATE usuario SET email = ? WHERE id_usuario = ?")->execute([$adminEmail, (int)$admin1['id_usuario']]);
$pdo->prepare("UPDATE usuario SET email = ? WHERE id_usuario = ?")->execute(['vend1.p11@amazonmarket.local', (int)$vend1['id_usuario']]);

$pdo->prepare("INSERT INTO usuario (id_rol, username, nombre, dni, telefono, direccion, email, password_hash, estado) VALUES (1, 'admp11ina', 'Admin Inactivo P11', '79999901', '999999001', 'Dir', 'admin.inactivo.p11@amazonmarket.local', ?, 0)")->execute([$passHash]);
$idAdminInact = (int)$pdo->lastInsertId();

// Dos usuarios con correo duplicado para probar identificación ambigua
$dupEmail = 'duplicado.p11@amazonmarket.local';
$pdo->prepare("INSERT INTO usuario (id_rol, username, nombre, dni, telefono, direccion, email, password_hash, estado) VALUES (1, 'admp11dp1', 'Admin Dup 1', '79999902', '999999002', 'Dir', ?, ?, 1)")->execute([$dupEmail, $passHash]);
$pdo->prepare("INSERT INTO usuario (id_rol, username, nombre, dni, telefono, direccion, email, password_hash, estado) VALUES (1, 'admp11dp2', 'Admin Dup 2', '79999903', '999999003', 'Dir', ?, ?, 1)")->execute([strtoupper($dupEmail), $passHash]);

// Vendedor desactivado
$pdo->prepare("INSERT INTO usuario (id_rol, username, nombre, dni, telefono, direccion, email, password_hash, estado) VALUES (2, 'vndp11dis', 'Vendedor Inactivo P11', '79999904', '999999004', 'Dir', 'vnddis.p11@amazonmarket.local', ?, 1)")->execute([$passHash]);
$idVendDis = (int)$pdo->lastInsertId();

$srv1 = startPhpServer($phpBin, $rootDir, $httpPort1, $dbName, $sessionDir);
$srv2 = startPhpServer($phpBin, $rootDir, $httpPort2, $dbName, $sessionDir);
$url1 = $srv1['url'];
$url2 = $srv2['url'];

try {
    $cookieV1A = $tmpDir . '/v1a.cookie';
    $cookieV1B = $tmpDir . '/v1b.cookie';
    $cookieV2  = $tmpDir . '/v2.cookie';
    $cookieAdm = $tmpDir . '/adm.cookie';
    $cookieDis = $tmpDir . '/dis.cookie';

    $authV1A = loginHttp($url1, (string)$vend1['username'], $passPlain, $cookieV1A);
    $authV1B = loginHttp($url2, (string)$vend1['username'], $passPlain, $cookieV1B);
    $authV2  = loginHttp($url2, (string)$vend2['username'], $passPlain, $cookieV2);
    $authAdm = loginHttp($url1, (string)$admin1['username'], $passPlain, $cookieAdm);
    $authDis = loginHttp($url1, 'vndp11dis', $passPlain, $cookieDis);

    assertTrue($authV1A['csrf'] !== '' && $authV1B['csrf'] !== '' && $authV2['csrf'] !== '' && $authAdm['csrf'] !== '', 'PRE', 'Logins HTTP completados');
    $sessIdA = extractPhpSessionId($cookieV1A);
    $sessIdB = extractPhpSessionId($cookieV1B);
    assertTrue($sessIdA !== '' && $sessIdB !== '' && $sessIdA !== $sessIdB, 'PRE', 'Sesiones PHP independientes para concurrencia');

    // =========================================================================
    // SECCIÓN 1: SEGURIDAD, PERMISOS, CSRF, JSON Y RBAC
    // =========================================================================
    assertSameVal(401, httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => 1, 'referencia_solicitud' => 'p11_noauth_0000001'])['status'], 'Seguridad', 'EP-20 sin sesión -> 401');
    assertSameVal(401, httpRequest($url1, 'GET', '/api/caja/cierre?session_id=1&referencia_solicitud=p11_noauth_0000001')['status'], 'Seguridad', 'EP-21 sin sesión -> 401');

    $pdo->prepare("UPDATE usuario SET estado = 0 WHERE id_usuario = ?")->execute([$idVendDis]);
    $resDis = httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => 1, 'referencia_solicitud' => 'p11_disuser_000001'], ['X-CSRF-TOKEN' => $authDis['csrf']], $cookieDis);
    assertSameVal(401, $resDis['status'], 'Seguridad', 'Usuario desactivado en BD -> 401 AUTH_REQUIRED');
    assertSameVal('AUTH_REQUIRED', $resDis['json']['code'] ?? null, 'Seguridad', 'Code AUTH_REQUIRED para usuario inactivo');

    assertSameVal(403, httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => 1, 'referencia_solicitud' => 'p11_admop_00000001'], ['X-CSRF-TOKEN' => $authAdm['csrf']], $cookieAdm)['status'], 'Seguridad', 'Administrador rechazado como operador en EP-20 -> 403');
    assertSameVal(403, httpRequest($url1, 'GET', '/api/caja/cierre?session_id=1&referencia_solicitud=p11_admop_00000001', null, [], $cookieAdm)['status'], 'Seguridad', 'Administrador rechazado como operador en EP-21 -> 403');

    $idPermCerrar = (int)$pdo->query("SELECT id_permiso FROM permiso WHERE slug = 'turnos.cerrar' LIMIT 1")->fetchColumn();
    $pdo->prepare('DELETE FROM rol_permiso WHERE id_rol = 2 AND id_permiso = ?')->execute([$idPermCerrar]);
    assertSameVal(403, httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => 1, 'referencia_solicitud' => 'p11_noperm_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Seguridad', 'Sin permiso turnos.cerrar EP-20 -> 403');
    assertSameVal(403, httpRequest($url1, 'GET', '/api/caja/cierre?session_id=1&referencia_solicitud=p11_noperm_0000001', null, [], $cookieV1A)['status'], 'Seguridad', 'Sin permiso turnos.cerrar EP-21 -> 403');
    assertSameVal(200, httpRequest($url1, 'GET', '/api/caja/estado', null, [], $cookieV1A)['status'], 'Seguridad', 'Sin turnos.cerrar conserva acceso a EP-08 (turnos.abrir)');
    $pdo->prepare('INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (2, ?)')->execute([$idPermCerrar]);

    assertSameVal(403, httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => 1, 'referencia_solicitud' => 'p11_badcsrf_000001'], ['X-CSRF-TOKEN' => 'invalido'], $cookieV1A)['status'], 'Seguridad', 'EP-20 CSRF inválido -> 403');
    assertSameVal(400, httpRequest($url1, 'POST', '/api/caja/cerrar', null, ['X-CSRF-TOKEN' => $authV1A['csrf'], 'Content-Type' => 'application/json'], $cookieV1A, '{malformed')['status'], 'Seguridad', 'EP-20 JSON inválido -> 400 INVALID_JSON');

    // =========================================================================
    // SECCIÓN 2: REQUISITOS DEL ARQUEO Y PROPIEDAD DE SESIÓN
    // =========================================================================
    $pdo->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES ('Turno Paso11', '07:00:00', '12:00:00', 1)");
    $idTurno = (int)$pdo->lastInsertId();

    $open1 = httpRequest($url1, 'POST', '/api/caja/abrir', [
        'shift_id'             => $idTurno,
        'cash_counted'         => 100.00,
        'referencia_solicitud' => 'p11_open_ses1_000001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $open1['status'], 'PRE', 'Apertura sesión 1 con 100.00 -> 201');
    $idSes1 = (int)($open1['json']['data']['session_id'] ?? 0);

    // Caja ajena en EP-21
    $resAjenaGet = httpRequest($url2, 'GET', "/api/caja/cierre?session_id={$idSes1}&referencia_solicitud=p11_ajena_get_000001", null, [], $cookieV2);
    assertSameVal(403, $resAjenaGet['status'], 'Seguridad', 'EP-21 sesión ajena -> 403 SESSION_NOT_OWNED');

    // EP-21 sobre sesión propia aún abierta -> 200 data=null
    $ep21Open = httpRequest($url1, 'GET', "/api/caja/cierre?session_id={$idSes1}&referencia_solicitud=p11_close_ses1_00001", null, [], $cookieV1A);
    assertSameVal(200, $ep21Open['status'], 'Recuperación', 'EP-21 sesión abierta sin confirmar -> 200');
    assertSameVal(null, array_key_exists('data', $ep21Open['json'] ?? []) ? $ep21Open['json']['data'] : 'MISSING', 'Recuperación', 'EP-21 sesión abierta devuelve data=null');

    // Arqueo ausente (sin arqueos todavía)
    $resArqMissing = httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => 999999, 'referencia_solicitud' => 'p11_arq_miss_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $resArqMissing['status'], 'Arqueo', 'Arqueo inexistente -> 409 ARQUEO_MISSING');
    assertSameVal('ARQUEO_MISSING', $resArqMissing['json']['code'] ?? null, 'Arqueo', 'Code ARQUEO_MISSING');
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => true, 'referencia_solicitud' => 'p11_arq_bool_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Arqueo', 'arqueo_id booleano -> 422');

    // Guardar Arqueo 1 (VIGENTE) y luego Arqueo 2 (reemplaza a 1)
    $arq1 = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 100.00, 'version' => 1, 'referencia_solicitud' => 'p11_s1_arq1_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq1 = (int)($arq1['json']['data']['arqueo']['id'] ?? 0);
    $arq2 = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 100.00, 'version' => 1, 'referencia_solicitud' => 'p11_s1_arq2_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq2 = (int)($arq2['json']['data']['arqueo']['id'] ?? 0);
    assertTrue($idArq1 > 0 && $idArq2 > $idArq1, 'PRE', 'Arqueos 1 y 2 creados en sesión 1');

    // Caja ajena en EP-20 (sobre arqueo existente de sesión 1 abierta)
    $resAjenaPost = httpRequest($url2, 'POST', '/api/caja/cerrar', ['arqueo_id' => $idArq2, 'referencia_solicitud' => 'p11_ajena_post_00001'], ['X-CSRF-TOKEN' => $authV2['csrf']], $cookieV2);
    assertSameVal(403, $resAjenaPost['status'], 'Seguridad', 'EP-20 sesión ajena -> 403 SESSION_NOT_OWNED');
    assertSameVal('SESSION_NOT_OWNED', $resAjenaPost['json']['code'] ?? null, 'Seguridad', 'EP-20 code SESSION_NOT_OWNED');

    // Arqueo reemplazado -> 409 ARQUEO_STALE (incluso con credenciales admin válidas)
    $resReemp = httpRequest($url1, 'POST', '/api/caja/cerrar', [
        'arqueo_id'            => $idArq1,
        'admin_email'          => $adminEmail,
        'admin_password'       => $passPlain,
        'exception_reason'     => 'Intento con arqueo reemplazado',
        'referencia_solicitud' => 'p11_arq_reemp_000001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $resReemp['status'], 'Arqueo', 'Arqueo reemplazado (aunque misma versión y admin válido) -> 409');
    assertSameVal('ARQUEO_STALE', $resReemp['json']['code'] ?? null, 'Arqueo', 'Code ARQUEO_STALE para arqueo reemplazado');

    // Desactualizar Arqueo 2 mediante un ingreso -> 409 ARQUEO_STALE incluso con credenciales admin válidas
    $ing1 = httpRequest($url1, 'POST', '/api/caja/ingresos', ['type' => 'manual_income', 'amount' => 25.00, 'reason' => 'Ingreso pre cierre P11', 'referencia_solicitud' => 'p11_ing_pre_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $ing1['status'], 'PRE', 'Ingreso previo al cierre -> 201 (esperado=125.00, ver=2)');

    $resStale = httpRequest($url1, 'POST', '/api/caja/cerrar', [
        'arqueo_id'            => $idArq2,
        'admin_email'          => $adminEmail,
        'admin_password'       => $passPlain,
        'exception_reason'     => 'Intento con arqueo desactualizado',
        'referencia_solicitud' => 'p11_arq_stale_000001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $resStale['status'], 'Arqueo', 'Arqueo desactualizado con admin válido -> 409 ARQUEO_STALE');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM autorizacion_caja WHERE id_sesion_caja = {$idSes1}")->fetchColumn(), 'Arqueo', 'ARQUEO_STALE no consume autorización en BD');

    // Arqueo 3 con diferencia -5.00 y observación para cerrar sesión 1
    $arq3 = httpRequest($url1, 'POST', '/api/caja/arqueo', [
        'counted'              => 120.00,
        'observation'          => 'Faltante S/ 5.00 validado en cierre P11',
        'version'              => 2,
        'referencia_solicitud' => 'p11_s1_arq3_0000001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $arq3['status'], 'PRE', 'Arqueo 3 VIGENTE con faltante -5.00 -> 201');
    $idArq3 = (int)($arq3['json']['data']['arqueo']['id'] ?? 0);

    // =========================================================================
    // SECCIÓN 3: HORARIO DETERMINISTA Y VALIDACIÓN DE ADMINISTRADOR (setClockProvider)
    // =========================================================================
    $cajaSvc = new \Services\CajaService($pdo);
    $tzLima = new DateTimeZone('America/Lima');
    $salidaSes1 = new DateTimeImmutable((string)$pdo->query("SELECT fecha_hora_salida_referencia FROM sesion_caja WHERE id_sesion_caja = {$idSes1}")->fetchColumn(), $tzLima);
    // Editar turno posteriormente no altera fecha_hora_salida_referencia
    $pdo->prepare("UPDATE turno SET hora_fin = '19:00:00' WHERE id_turno = ?")->execute([$idTurno]);

    $vend1Row = $pdo->query("SELECT id_usuario, id_rol, username, nombre FROM usuario WHERE id_usuario = " . (int)$vend1['id_usuario'])->fetch();
    $cajaSvc->setClockProvider(fn () => $salidaSes1->modify('-15 minutes -1 second'));

    // 3A. Sin credenciales antes del margen -> 409 AUTHORIZATION_REQUIRED con data.schedule
    $caughtAuthReq = null;
    try {
        $cajaSvc->cerrarCaja(['arqueo_id' => $idArq3, 'referencia_solicitud' => 'p11_early_nocred_001'], null, $vend1Row);
    } catch (\Libraries\Core\InventarioException $e) {
        $caughtAuthReq = $e;
    }
    assertTrue($caughtAuthReq !== null && $caughtAuthReq->getHttpCode() === 409 && $caughtAuthReq->toApiResponse()['code'] === 'AUTHORIZATION_REQUIRED', 'Horario', '11:44:59 sin credenciales -> 409 AUTHORIZATION_REQUIRED');
    assertSameVal(true, $caughtAuthReq ? ($caughtAuthReq->toApiResponse()['data']['schedule']['requires_authorization'] ?? null) : null, 'Horario', 'AUTHORIZATION_REQUIRED incluye data.schedule.requires_authorization=true');

    // 3B. Motivo vacío, correo inválido, correo ambiguo, admin inactivo, autoautorización, contraseña incorrecta
    $assertCloseErr = function (array $payload, int $expHttp, string $expCode, string $label) use ($cajaSvc, $vend1Row): void {
        try {
            $cajaSvc->cerrarCaja($payload, null, $vend1Row);
            recordResult('Seguridad', $label, false, "No lanzó excepción (esperado {$expHttp} {$expCode})");
        } catch (\Libraries\Core\InventarioException $e) {
            $ok = ($e->getHttpCode() === $expHttp && ($e->toApiResponse()['code'] ?? '') === $expCode);
            recordResult('Seguridad', $label, $ok, $ok ? '' : "esperado={$expHttp}/{$expCode} actual={$e->getHttpCode()}/" . ($e->toApiResponse()['code'] ?? ''));
        }
    };

    $assertCloseErr(['arqueo_id' => $idArq3, 'admin_email' => $adminEmail, 'admin_password' => $passPlain, 'exception_reason' => '   ', 'referencia_solicitud' => 'p11_err_motive_00001'], 422, 'MOTIVE_REQUIRED', 'Motivo vacío -> 422 MOTIVE_REQUIRED');
    $assertCloseErr(['arqueo_id' => $idArq3, 'admin_email' => 'correo-invalido', 'admin_password' => $passPlain, 'exception_reason' => 'Motivo válido', 'referencia_solicitud' => 'p11_err_email_000001'], 422, 'INVALID_ADMIN_EMAIL', 'Correo inválido -> 422 INVALID_ADMIN_EMAIL');
    $assertCloseErr(['arqueo_id' => $idArq3, 'admin_email' => $adminEmail, 'admin_password' => 'WrongPass#123', 'exception_reason' => 'Motivo válido', 'referencia_solicitud' => 'p11_err_pass_0000001'], 403, 'INVALID_ADMIN_CREDENTIALS', 'Contraseña incorrecta -> 403 INVALID_ADMIN_CREDENTIALS');
    $assertCloseErr(['arqueo_id' => $idArq3, 'admin_email' => 'vend1.p11@amazonmarket.local', 'admin_password' => $passPlain, 'exception_reason' => 'Motivo válido', 'referencia_solicitud' => 'p11_err_self_0000001'], 403, 'INVALID_ADMIN_CREDENTIALS', 'Vendedor intentando autorizar -> 403 INVALID_ADMIN_CREDENTIALS');
    $assertCloseErr(['arqueo_id' => $idArq3, 'admin_email' => 'admin.inactivo.p11@amazonmarket.local', 'admin_password' => $passPlain, 'exception_reason' => 'Motivo válido', 'referencia_solicitud' => 'p11_err_inact_000001'], 403, 'INVALID_ADMIN_CREDENTIALS', 'Admin inactivo -> 403 INVALID_ADMIN_CREDENTIALS');
    $assertCloseErr(['arqueo_id' => $idArq3, 'admin_email' => $dupEmail, 'admin_password' => $passPlain, 'exception_reason' => 'Motivo válido', 'referencia_solicitud' => 'p11_err_dup_00000001'], 403, 'INVALID_ADMIN_CREDENTIALS', 'Correo ambiguo (duplicado en BD) -> 403 INVALID_ADMIN_CREDENTIALS');

    // 3C. Rollback atómico ante fallo posterior a escrituras (trigger temporal sobre caja_fisica_guardia)
    $pdo->exec('DROP TRIGGER IF EXISTS trg_test_p11_rollback_guardia');
    $pdo->exec("
        CREATE TRIGGER trg_test_p11_rollback_guardia
        BEFORE UPDATE ON caja_fisica_guardia
        FOR EACH ROW
        BEGIN
            IF NEW.id_ultima_sesion_cerrada = {$idSes1} THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TEST_P11_ROLLBACK';
            END IF;
        END
    ");
    $refClose1 = 'p11_close_ses1_ok_01';
    $rbCaught = false;
    try {
        $cajaSvc->cerrarCaja([
            'arqueo_id'            => $idArq3,
            'admin_email'          => '  ' . strtoupper($adminEmail) . '  ',
            'admin_password'       => $passPlain,
            'exception_reason'     => 'Cita médica urgente del vendedor',
            'referencia_solicitud' => $refClose1,
        ], null, $vend1Row);
    } catch (\Throwable $e) {
        $rbCaught = true;
    }
    $pdo->exec('DROP TRIGGER IF EXISTS trg_test_p11_rollback_guardia');
    assertTrue($rbCaught, 'Persistencia', 'Fallo posterior a escrituras de cierre lanza excepción');
    assertSameVal('ABIERTA', $pdo->query("SELECT estado FROM sesion_caja WHERE id_sesion_caja = {$idSes1}")->fetchColumn(), 'Persistencia', 'Rollback mantiene sesion_caja ABIERTA');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM autorizacion_caja WHERE id_sesion_caja = {$idSes1}")->fetchColumn(), 'Persistencia', 'Rollback revierte autorizacion_caja');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM control_solicitud_idempotencia WHERE referencia_solicitud = '{$refClose1}'")->fetchColumn(), 'Persistencia', 'Rollback no deja solicitud confirmada');

    // 3D. Confirmación válida antes del margen (11:44:59) con misma referencia tras rollback + medición de latencia
    $t0 = microtime(true);
    $resClose1 = $cajaSvc->cerrarCaja([
        'arqueo_id'            => $idArq3,
        'admin_email'          => '  ' . strtoupper($adminEmail) . ' ',
        'admin_password'       => $passPlain,
        'exception_reason'     => 'Cita médica urgente del vendedor',
        'referencia_solicitud' => $refClose1,
    ], null, $vend1Row);
    $elapsedMs = (microtime(true) - $t0) * 1000.0;
    assertSameVal(200, $resClose1['http_code'] ?? null, 'Horario', 'Reintento cierre anticipado 11:44:59 -> 200');
    assertSameVal('CLOSE_CONFIRMED', $resClose1['response']['code'] ?? null, 'Horario', 'Code exterior CLOSE_CONFIRMED');
    assertSameVal(false, $resClose1['response']['idempotent_replay'] ?? null, 'Horario', 'idempotent_replay=false');
    assertSameVal('CLOSE_CONFIRMED', $resClose1['response']['data']['result_code'] ?? null, 'Horario', 'data.result_code=CLOSE_CONFIRMED');
    assertSameVal(true, $resClose1['response']['data']['authorization']['required'] ?? null, 'Horario', 'authorization.required=true antes del margen');
    assertSameVal(16, $resClose1['response']['data']['authorization']['minutes_before_end'] ?? null, 'Horario', 'minutes_before_end=16 a las 11:44:59');
    assertTrue($elapsedMs < 2000.0, 'Rendimiento', sprintf('Autorización+cierre ejecutado en %.1f ms (< 2000 ms)', $elapsedMs));

    // Verificar BD congelada, versión +1, arqueo conservado VIGENTE, sin secretos persistidos
    $ses1Db = $pdo->query("SELECT * FROM sesion_caja WHERE id_sesion_caja = {$idSes1}")->fetch();
    assertSameVal('CERRADA', $ses1Db['estado'], 'Persistencia', 'sesion_caja.estado=CERRADA');
    assertSameVal(3, (int)$ses1Db['version_caja'], 'Persistencia', 'version_caja incrementada exactamente +1 (2->3)');
    assertSameVal('125.00', $ses1Db['efectivo_esperado_cierre'], 'Persistencia', 'efectivo_esperado_cierre congelado=125.00');
    assertSameVal('120.00', $ses1Db['efectivo_contado_cierre'], 'Persistencia', 'efectivo_contado_cierre congelado=120.00');
    assertSameVal('-5.00', $ses1Db['diferencia_cierre'], 'Persistencia', 'diferencia_cierre congelado=-5.00');
    assertSameVal('Faltante S/ 5.00 validado en cierre P11', $ses1Db['observacion_cierre'], 'Persistencia', 'observacion_cierre idéntica al arqueo');
    assertSameVal('VIGENTE', $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArq3}")->fetchColumn(), 'Persistencia', 'Arqueo de cierre conserva estado VIGENTE (no invalidado)');
    assertSameVal(1, (int)$pdo->query("SELECT COUNT(*) FROM autorizacion_caja WHERE id_sesion_caja = {$idSes1}")->fetchColumn(), 'Persistencia', '1 fila en autorizacion_caja');
    assertSameVal(null, $ses1Db['diferencia_apertura'], 'Persistencia', 'Primera sesión conserva diferencia_apertura=NULL en BD');
    assertSameVal(null, array_key_exists('opening_difference', $resClose1['response']['data']['summary'] ?? []) ? $resClose1['response']['data']['summary']['opening_difference'] : 'MISSING', 'Persistencia', 'EP-20 primer cierre devuelve opening_difference=null');

    // Verificar ausencia de secretos en control_solicitud_idempotencia y respuesta
    $idempJson = (string)$pdo->query("SELECT respuesta_json FROM control_solicitud_idempotencia WHERE referencia_solicitud = '{$refClose1}' LIMIT 1")->fetchColumn();
    $idempDecoded = json_decode($idempJson, true);
    assertSameVal(null, array_key_exists('opening_difference', $idempDecoded['data']['summary'] ?? []) ? $idempDecoded['data']['summary']['opening_difference'] : 'MISSING', 'Persistencia', 'respuesta_json de confirmación conserva opening_difference=null');
    assertTrue(!str_contains(json_encode($resClose1), $passPlain) && !str_contains($idempJson, $passPlain), 'Seguridad', 'Sin secretos (admin_password) en respuesta ni en control_solicitud_idempotencia');

    // Verificar sesión PHP del vendedor intacta en ambos servidores HTTP y replay de EP-20 sin repetir contraseña
    $replay1 = httpRequest($url1, 'POST', '/api/caja/cerrar', [
        'arqueo_id'            => $idArq3,
        'exception_reason'     => 'Cita médica urgente del vendedor',
        'referencia_solicitud' => $refClose1,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $replay1['status'], 'Recuperación', 'Replay EP-20 sin repetir password -> 200');
    assertSameVal('OPERATION_ALREADY_CONFIRMED', $replay1['json']['code'] ?? null, 'Recuperación', 'Replay code=OPERATION_ALREADY_CONFIRMED');
    assertSameVal(true, $replay1['json']['idempotent_replay'] ?? null, 'Recuperación', 'Replay idempotent_replay=true');
    assertSameVal(null, array_key_exists('opening_difference', $replay1['json']['data']['summary'] ?? []) ? $replay1['json']['data']['summary']['opening_difference'] : 'MISSING', 'Recuperación', 'Replay EP-20 conserva opening_difference=null');
    assertSameVal(json_encode($resClose1['response']['data']), json_encode($replay1['json']['data'] ?? null), 'Recuperación', 'Replay devuelve data original idéntico');
    assertSameVal($sessIdA, extractPhpSessionId($cookieV1A), 'Seguridad', 'Sesión PHP del vendedor conservada sin cambios');

    // Misma referencia con payload funcional distinto -> 409 IDEMPOTENCY_PAYLOAD_MISMATCH
    $mismatch1 = httpRequest($url1, 'POST', '/api/caja/cerrar', [
        'arqueo_id'            => $idArq3,
        'exception_reason'     => 'Otro motivo distinto',
        'referencia_solicitud' => $refClose1,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $mismatch1['status'], 'Recuperación', 'Misma referencia distinto exception_reason -> 409 IDEMPOTENCY_PAYLOAD_MISMATCH');
    assertSameVal('IDEMPOTENCY_PAYLOAD_MISMATCH', $mismatch1['json']['code'] ?? null, 'Recuperación', 'Code IDEMPOTENCY_PAYLOAD_MISMATCH');

    // Operaciones sobre sesión cerrada rechazadas
    assertSameVal(409, httpRequest($url1, 'POST', '/api/caja/ingresos', ['type' => 'manual_income', 'amount' => 1.00, 'reason' => 'Post cierre', 'referencia_solicitud' => 'p11_post_close_ing_1'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Inmutabilidad', 'Ingreso tras cierre -> 409 CASH_SESSION_CLOSED');
    assertSameVal(409, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 120.00, 'version' => 3, 'referencia_solicitud' => 'p11_post_close_arq_1'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Inmutabilidad', 'Arqueo tras cierre -> 409 CASH_SESSION_CLOSED');

    // Inmutabilidad en BD de sesion_caja cerrada y autorizacion_caja
    $trigBlocked = 0;
    try { $pdo->exec("UPDATE sesion_caja SET estado = 'ABIERTA' WHERE id_sesion_caja = {$idSes1}"); } catch (\Throwable) { $trigBlocked++; }
    try { $pdo->exec("UPDATE autorizacion_caja SET motivo = 'Editado' WHERE id_sesion_caja = {$idSes1}"); } catch (\Throwable) { $trigBlocked++; }
    try { $pdo->exec("DELETE FROM autorizacion_caja WHERE id_sesion_caja = {$idSes1}"); } catch (\Throwable) { $trigBlocked++; }
    try { $pdo->exec("UPDATE arqueo_caja SET estado = 'DESACTUALIZADO' WHERE id_arqueo_caja = {$idArq3}"); } catch (\Throwable) { $trigBlocked++; }
    assertSameVal(4, $trigBlocked, 'Inmutabilidad', 'Triggers bloquean reapertura, edición/borrado de autorización y edición de arqueo cerrado');

    // =========================================================================
    // SECCIÓN 4: PRÓXIMA APERTURA CON CONTADO REAL + ESCENARIOS HORARIOS SIN AUTORIZACIÓN
    // =========================================================================
    sleep(1);
    $estadoPreOpen2 = httpRequest($url1, 'GET', '/api/caja/estado', null, [], $cookieV1A);
    assertSameVal(120.0, (float)($estadoPreOpen2['json']['data']['reference_amount'] ?? -1), 'Persistencia', 'Próxima apertura toma efectivo_contado_cierre (120.00), no el esperado (125.00)');

    // Abrir Sesión 2 (contado 120.00), verificar replay de Sesión 1 y EP-21 después de nueva apertura
    $open2 = httpRequest($url1, 'POST', '/api/caja/abrir', [
        'shift_id'             => $idTurno,
        'cash_counted'         => 120.00,
        'referencia_solicitud' => 'p11_open_ses2_000001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $open2['status'], 'PRE', 'Apertura sesión 2 con 120.00 -> 201');
    $idSes2 = (int)($open2['json']['data']['session_id'] ?? 0);

    // Replay de Sesión 1 después de abrir Sesión 2 -> recupera Sesión 1 y deja Sesión 2 ABIERTA
    $replayPostOpen2 = httpRequest($url1, 'POST', '/api/caja/cerrar', [
        'arqueo_id'            => $idArq3,
        'exception_reason'     => 'Cita médica urgente del vendedor',
        'referencia_solicitud' => $refClose1,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $replayPostOpen2['status'], 'Recuperación', 'Replay EP-20 tras nueva apertura -> 200');
    assertSameVal($idSes1, (int)($replayPostOpen2['json']['data']['session_id'] ?? 0), 'Recuperación', 'Replay recupera session_id=1 (no la nueva sesión 2)');
    assertSameVal('ABIERTA', $pdo->query("SELECT estado FROM sesion_caja WHERE id_sesion_caja = {$idSes2}")->fetchColumn(), 'Recuperación', 'Replay de sesión 1 no cierra sesión 2');

    // EP-21 de Sesión 1 después de nueva apertura y con alias sesion_id/request_id
    $ep21Ses1 = httpRequest($url1, 'GET', "/api/caja/cierre?sesion_id={$idSes1}&request_id={$refClose1}", null, [], $cookieV1A);
    assertSameVal(200, $ep21Ses1['status'], 'Recuperación', 'EP-21 de sesión 1 tras nueva apertura -> 200');
    assertSameVal($idSes1, (int)($ep21Ses1['json']['data']['session_id'] ?? 0), 'Recuperación', 'EP-21 devuelve resumen de sesión 1');
    assertSameVal(null, array_key_exists('opening_difference', $ep21Ses1['json']['data']['summary'] ?? []) ? $ep21Ses1['json']['data']['summary']['opening_difference'] : 'MISSING', 'Recuperación', 'EP-21 de primer cierre conserva opening_difference=null tras nueva apertura');
    assertSameVal(403, httpRequest($url1, 'GET', "/api/caja/cierre?session_id={$idSes1}&referencia_solicitud=p11_wrong_ref_000001", null, [], $cookieV1A)['status'], 'Recuperación', 'EP-21 sesión cerrada sin referencia concordante -> 403 HISTORICAL_QUERY_NOT_ALLOWED');
    assertSameVal(422, httpRequest($url1, 'GET', "/api/caja/cierre?session_id={$idSes1}&sesion_id={$idSes2}&referencia_solicitud={$refClose1}", null, [], $cookieV1A)['status'], 'Recuperación', 'EP-21 alias contradictorios -> 422 AMBIGUOUS_ALIAS_VALUES');
    assertSameVal(404, httpRequest($url1, 'GET', "/api/caja/cierre?session_id=999999&referencia_solicitud={$refClose1}", null, [], $cookieV1A)['status'], 'Recuperación', 'EP-21 sesión inexistente -> 404 CASH_SESSION_NOT_FOUND');

    // Pendiente C: validación contra la sesión objetivo real del arqueo (Sesión 1 cerrada vs Sesión 2 abierta)
    $closeOldNewRef = httpRequest($url1, 'POST', '/api/caja/cerrar', [
        'arqueo_id'            => $idArq3,
        'referencia_solicitud' => 'p11_close_old_newref01',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $closeOldNewRef['status'], 'Recuperación', 'EP-20 con arqueo de sesión cerrada A y ref nueva mientras B está abierta -> 409');
    assertSameVal('CASH_SESSION_CLOSED', $closeOldNewRef['json']['code'] ?? null, 'Recuperación', 'Code CASH_SESSION_CLOSED al intentar cerrar A con ref nueva');
    assertSameVal('ABIERTA', $pdo->query("SELECT estado FROM sesion_caja WHERE id_sesion_caja = {$idSes2}")->fetchColumn(), 'Recuperación', 'Sesión B permanece ABIERTA intacta tras rechazo CASH_SESSION_CLOSED');

    $closeMissingArq = httpRequest($url1, 'POST', '/api/caja/cerrar', [
        'arqueo_id'            => 999999,
        'referencia_solicitud' => 'p11_close_missing_arq1',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $closeMissingArq['status'], 'Arqueo', 'EP-20 con arqueo_id inexistente -> 409 ARQUEO_MISSING');
    assertSameVal('ARQUEO_MISSING', $closeMissingArq['json']['code'] ?? null, 'Arqueo', 'Code ARQUEO_MISSING para arqueo_id inexistente');

    $closeOtherSeller = httpRequest($url1, 'POST', '/api/caja/cerrar', [
        'arqueo_id'            => $idArq3,
        'referencia_solicitud' => 'p11_close_other_vend1',
    ], ['X-CSRF-TOKEN' => $authV2['csrf']], $cookieV2);
    assertSameVal(403, $closeOtherSeller['status'], 'Seguridad', 'EP-20 con arqueo de otro vendedor -> 403 SESSION_NOT_OWNED');
    assertSameVal('SESSION_NOT_OWNED', $closeOtherSeller['json']['code'] ?? null, 'Seguridad', 'Code SESSION_NOT_OWNED');
    assertSameVal(null, array_key_exists('data', $closeOtherSeller['json'] ?? []) ? $closeOtherSeller['json']['data'] : 'MISSING', 'Seguridad', 'SESSION_NOT_OWNED no revela datos financieros');

    // Cerrar Sesión 2 en el instante exacto del margen (-15m) sin credenciales
    $arqSes2 = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 120.00, 'version' => 1, 'referencia_solicitud' => 'p11_s2_arq1_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArqSes2 = (int)($arqSes2['json']['data']['arqueo']['id'] ?? 0);
    $salidaSes2 = new DateTimeImmutable((string)$pdo->query("SELECT fecha_hora_salida_referencia FROM sesion_caja WHERE id_sesion_caja = {$idSes2}")->fetchColumn(), $tzLima);
    $cajaSvc->setClockProvider(fn () => $salidaSes2->modify('-15 minutes'));
    $resClose2 = $cajaSvc->cerrarCaja(['arqueo_id' => $idArqSes2, 'referencia_solicitud' => 'p11_close_ses2_00001'], null, $vend1Row);
    assertSameVal(200, $resClose2['http_code'] ?? null, 'Horario', 'Instante exacto del margen (-15m) sin credenciales -> 200 CLOSE_CONFIRMED');
    assertSameVal(0.0, $resClose2['response']['data']['summary']['opening_difference'] ?? null, 'Persistencia', 'Diferencia apertura 0.00 devuelve número 0 (no null)');
    assertSameVal(null, array_key_exists('authorization', $resClose2['response']['data'] ?? []) ? $resClose2['response']['data']['authorization'] : 'MISSING', 'Horario', 'Instante exacto del margen authorization=null');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM autorizacion_caja WHERE id_sesion_caja = {$idSes2}")->fetchColumn(), 'Horario', 'Instante exacto del margen no inserta autorizacion_caja');

    // Sesión 3: después de la salida (+35m) sin credenciales
    sleep(1);
    $open3 = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurno, 'cash_counted' => 120.00, 'referencia_solicitud' => 'p11_open_ses3_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSes3 = (int)($open3['json']['data']['session_id'] ?? 0);
    $arqSes3 = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 120.00, 'version' => 1, 'referencia_solicitud' => 'p11_s3_arq1_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArqSes3 = (int)($arqSes3['json']['data']['arqueo']['id'] ?? 0);
    $salidaSes3 = new DateTimeImmutable((string)$pdo->query("SELECT fecha_hora_salida_referencia FROM sesion_caja WHERE id_sesion_caja = {$idSes3}")->fetchColumn(), $tzLima);
    $cajaSvc->setClockProvider(fn () => $salidaSes3->modify('+35 minutes'));
    $resClose3 = $cajaSvc->cerrarCaja(['arqueo_id' => $idArqSes3, 'referencia_solicitud' => 'p11_close_ses3_00001'], null, $vend1Row);
    assertSameVal(200, $resClose3['http_code'] ?? null, 'Horario', 'Después de salida (+35m) sin credenciales -> 200 CLOSE_CONFIRMED');
    assertSameVal(null, array_key_exists('authorization', $resClose3['response']['data'] ?? []) ? $resClose3['response']['data']['authorization'] : 'MISSING', 'Horario', 'Después de salida authorization=null');

    // Sesión 4: después de medianoche (+12h 15m respecto a salida) + contado 0.00
    sleep(1);
    $open4 = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurno, 'cash_counted' => 0.00, 'referencia_solicitud' => 'p11_open_ses4_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSes4 = (int)($open4['json']['data']['session_id'] ?? 0);
    $arqSes4 = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 0.00, 'version' => 1, 'referencia_solicitud' => 'p11_s4_arq1_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArqSes4 = (int)($arqSes4['json']['data']['arqueo']['id'] ?? 0);
    $salidaSes4 = new DateTimeImmutable((string)$pdo->query("SELECT fecha_hora_salida_referencia FROM sesion_caja WHERE id_sesion_caja = {$idSes4}")->fetchColumn(), $tzLima);
    $cajaSvc->setClockProvider(fn () => $salidaSes4->modify('+12 hours +15 minutes'));
    $resClose4 = $cajaSvc->cerrarCaja(['arqueo_id' => $idArqSes4, 'referencia_solicitud' => 'p11_close_ses4_00001'], null, $vend1Row);
    assertSameVal(200, $resClose4['http_code'] ?? null, 'Horario', 'Después de medianoche (00:15 día siguiente) sin credenciales -> 200 CLOSE_CONFIRMED');
    assertSameVal(0.0, (float)($resClose4['response']['data']['summary']['counted'] ?? -1), 'Persistencia', 'Cierre con contado cero (0.00) conservado');

    // =========================================================================
    // SECCIÓN 5: CONCURRENCIA REAL HTTP (2 SESIONES PHP INDEPENDIENTES)
    // =========================================================================
    // Turno que garantice que el cierre HTTP actual no requiera credenciales (hora_fin = 00:01:00 o pasada)
    $pdo->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES ('Turno Conc P11', '00:00:00', '00:01:00', 1)");
    $idTurnoPast = (int)$pdo->lastInsertId();

    $runCurlMulti = function (array $reqA, array $reqB) use ($url1, $url2, $authV1A, $authV1B, $cookieV1A, $cookieV1B): array {
        $mh = curl_multi_init();
        $mk = function (string $url, string $csrf, string $cookie, array $r) {
            $ch = curl_init($url . $r['path']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "X-CSRF-TOKEN: {$csrf}"],
                CURLOPT_COOKIEFILE     => $cookie,
                CURLOPT_COOKIEJAR      => $cookie,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_POSTFIELDS     => json_encode($r['body']),
            ]);
            return $ch;
        };
        $c1 = $mk($url1, $authV1A['csrf'], $cookieV1A, $reqA);
        $c2 = $mk($url2, $authV1B['csrf'], $cookieV1B, $reqB);
        curl_multi_add_handle($mh, $c1);
        curl_multi_add_handle($mh, $c2);
        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.05);
            }
        } while ($active && $mrc == CURLM_OK);
        $res = [
            ['status' => (int)curl_getinfo($c1, CURLINFO_HTTP_CODE), 'json' => json_decode((string)curl_multi_getcontent($c1), true)],
            ['status' => (int)curl_getinfo($c2, CURLINFO_HTTP_CODE), 'json' => json_decode((string)curl_multi_getcontent($c2), true)],
        ];
        curl_multi_remove_handle($mh, $c1);
        curl_multi_remove_handle($mh, $c2);
        curl_close($c1);
        curl_close($c2);
        curl_multi_close($mh);
        return $res;
    };

    // 5A: Dos cierres simultáneos con misma referencia -> 1 solo cierre efectivo
    sleep(1);
    $op5a = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_open_5a_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSes5a = (int)($op5a['json']['data']['session_id'] ?? 0);
    $arq5a = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_arq_5a_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq5a = (int)($arq5a['json']['data']['arqueo']['id'] ?? 0);

    $rSameRef = $runCurlMulti(
        ['path' => '/api/caja/cerrar', 'body' => ['arqueo_id' => $idArq5a, 'referencia_solicitud' => 'p11_conc_same_00001']],
        ['path' => '/api/caja/cerrar', 'body' => ['arqueo_id' => $idArq5a, 'referencia_solicitud' => 'p11_conc_same_00001']]
    );
    $codes5a = [$rSameRef[0]['json']['code'] ?? '', $rSameRef[1]['json']['code'] ?? ''];
    assertSameVal('CERRADA', $pdo->query("SELECT estado FROM sesion_caja WHERE id_sesion_caja = {$idSes5a}")->fetchColumn(), 'Concurrencia', 'Dos cierres misma referencia -> sesión CERRADA');
    assertSameVal(2, (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSes5a}")->fetchColumn(), 'Concurrencia', 'Dos cierres misma referencia -> version_caja incrementada 1 vez');
    assertTrue(in_array('CLOSE_CONFIRMED', $codes5a, true), 'Concurrencia', 'Dos cierres misma referencia -> al menos uno CLOSE_CONFIRMED');

    // 5B: Dos cierres simultáneos con referencias distintas -> uno 200 CLOSE_CONFIRMED, otro 409 CASH_SESSION_CLOSED
    sleep(1);
    $op5b = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_open_5b_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSes5b = (int)($op5b['json']['data']['session_id'] ?? 0);
    $arq5b = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_arq_5b_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq5b = (int)($arq5b['json']['data']['arqueo']['id'] ?? 0);

    $rDiffRef = $runCurlMulti(
        ['path' => '/api/caja/cerrar', 'body' => ['arqueo_id' => $idArq5b, 'referencia_solicitud' => 'p11_conc_diff_A_001']],
        ['path' => '/api/caja/cerrar', 'body' => ['arqueo_id' => $idArq5b, 'referencia_solicitud' => 'p11_conc_diff_B_002']]
    );
    $stDiff = [$rDiffRef[0]['status'], $rDiffRef[1]['status']];
    sort($stDiff);
    assertSameVal([200, 409], $stDiff, 'Concurrencia', 'Dos cierres referencias distintas -> exactamente uno 200 y otro 409 CASH_SESSION_CLOSED');

    // 5C: Venta vs Cierre simultáneos
    $prod = $pdo->query("SELECT id_producto, stock_actual FROM producto WHERE estado = 1 AND stock_actual >= 10 AND precio_venta > 0 ORDER BY id_producto ASC LIMIT 1")->fetch();
    $idProd = (int)$prod['id_producto'];
    $idCli = (int)$pdo->query("SELECT id_cliente FROM cliente WHERE estado = 1 AND id_tipo_documento <> 2 ORDER BY id_cliente ASC LIMIT 1")->fetchColumn();
    $idBol = (int)$pdo->query("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE estado = 1 AND nombre LIKE '%Boleta%' LIMIT 1")->fetchColumn();

    sleep(1);
    $op5c = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_open_5c_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSes5c = (int)($op5c['json']['data']['session_id'] ?? 0);
    $arq5c = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_arq_5c_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq5c = (int)($arq5c['json']['data']['arqueo']['id'] ?? 0);

    $rVtaClose = $runCurlMulti(
        ['path' => '/api/caja/cerrar', 'body' => ['arqueo_id' => $idArq5c, 'referencia_solicitud' => 'p11_conc_vc_close_1']],
        ['path' => '/api/ventas', 'body' => ['id_cliente' => $idCli, 'id_tipo_comprobante' => $idBol, 'id_metodo_pago' => 1, 'productos' => [['id_producto' => $idProd, 'cantidad' => 1]], 'referencia_solicitud' => 'p11_conc_vc_venta_1']]
    );
    $okVC = ($rVtaClose[0]['status'] === 200 && $rVtaClose[1]['status'] === 409 && ($rVtaClose[1]['json']['code'] ?? '') === 'CASH_SESSION_CLOSED')
         || ($rVtaClose[1]['status'] === 201 && $rVtaClose[0]['status'] === 409 && ($rVtaClose[0]['json']['code'] ?? '') === 'ARQUEO_STALE');
    assertTrue($okVC, 'Concurrencia', 'Venta vs Cierre simultáneos: serialización exacta (200/409 CASH_SESSION_CLOSED o 201/409 ARQUEO_STALE)');
    // Asegurar cierre de 5c si venta ganó
    if ($rVtaClose[1]['status'] === 201) {
        $esp5c = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$idSes5c}")->fetchColumn();
        $arq5c2 = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $esp5c, 'version' => 2, 'referencia_solicitud' => 'p11_arq_5c_fix_0001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
        httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => (int)$arq5c2['json']['data']['arqueo']['id'], 'referencia_solicitud' => 'p11_cls_5c_fix_0001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    }

    // 5D: Ingreso vs Cierre simultáneos
    sleep(1);
    $op5d = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_open_5d_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSes5d = (int)($op5d['json']['data']['session_id'] ?? 0);
    $arq5d = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_arq_5d_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq5d = (int)($arq5d['json']['data']['arqueo']['id'] ?? 0);

    $rIngClose = $runCurlMulti(
        ['path' => '/api/caja/cerrar', 'body' => ['arqueo_id' => $idArq5d, 'referencia_solicitud' => 'p11_conc_ic_close_1']],
        ['path' => '/api/caja/ingresos', 'body' => ['type' => 'manual_income', 'amount' => 10.00, 'reason' => 'Ing conc P11', 'referencia_solicitud' => 'p11_conc_ic_ing_001']]
    );
    $okIC = ($rIngClose[0]['status'] === 200 && $rIngClose[1]['status'] === 409 && ($rIngClose[1]['json']['code'] ?? '') === 'CASH_SESSION_CLOSED')
         || ($rIngClose[1]['status'] === 201 && $rIngClose[0]['status'] === 409 && ($rIngClose[0]['json']['code'] ?? '') === 'ARQUEO_STALE');
    assertTrue($okIC, 'Concurrencia', 'Movimiento vs Cierre simultáneos: serialización exacta');
    if ($rIngClose[1]['status'] === 201) {
        $arq5d2 = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 60.00, 'version' => 2, 'referencia_solicitud' => 'p11_arq_5d_fix_0001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
        httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => (int)$arq5d2['json']['data']['arqueo']['id'], 'referencia_solicitud' => 'p11_cls_5d_fix_0001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    }

    // 5E: Nuevo arqueo vs Cierre simultáneos
    sleep(1);
    $op5e = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_open_5e_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSes5e = (int)($op5e['json']['data']['session_id'] ?? 0);
    $arq5e = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_arq_5e_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq5e = (int)($arq5e['json']['data']['arqueo']['id'] ?? 0);

    $rArqClose = $runCurlMulti(
        ['path' => '/api/caja/cerrar', 'body' => ['arqueo_id' => $idArq5e, 'referencia_solicitud' => 'p11_conc_ac_close_1']],
        ['path' => '/api/caja/arqueo', 'body' => ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_conc_ac_arq_001']]
    );
    $okAC = ($rArqClose[0]['status'] === 200 && $rArqClose[1]['status'] === 409 && ($rArqClose[1]['json']['code'] ?? '') === 'CASH_SESSION_CLOSED')
         || ($rArqClose[1]['status'] === 201 && $rArqClose[0]['status'] === 409 && ($rArqClose[0]['json']['code'] ?? '') === 'ARQUEO_STALE');
    assertTrue($okAC, 'Concurrencia', 'Nuevo arqueo vs Cierre simultáneos: serialización exacta');
    if ($rArqClose[1]['status'] === 201) {
        httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => (int)$rArqClose[1]['json']['data']['arqueo']['id'], 'referencia_solicitud' => 'p11_cls_5e_fix_0001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    }

    // 5F: Apertura vs Cierre simultáneos (guardia física consistente)
    sleep(1);
    $op5f = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_open_5f_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSes5f = (int)($op5f['json']['data']['session_id'] ?? 0);
    $arq5f = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_arq_5f_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq5f = (int)($arq5f['json']['data']['arqueo']['id'] ?? 0);

    $rOpenClose = $runCurlMulti(
        ['path' => '/api/caja/cerrar', 'body' => ['arqueo_id' => $idArq5f, 'referencia_solicitud' => 'p11_conc_oc_close_1']],
        ['path' => '/api/caja/abrir', 'body' => ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_conc_oc_open_01']]
    );
    assertSameVal(200, $rOpenClose[0]['status'], 'Concurrencia', 'Apertura vs Cierre: cierre -> 200');
    assertTrue($rOpenClose[1]['status'] === 201 || ($rOpenClose[1]['status'] === 409 && ($rOpenClose[1]['json']['code'] ?? '') === 'CASH_ALREADY_OPEN'), 'Concurrencia', 'Apertura vs Cierre: apertura -> 201 (tras cierre) o 409 CASH_ALREADY_OPEN (antes)');
    $guardia = $pdo->query("SELECT id_sesion_abierta_actual, id_ultima_sesion_cerrada FROM caja_fisica_guardia WHERE id_caja_fisica = 1")->fetch();
    if ($rOpenClose[1]['status'] === 201) {
        assertSameVal((int)$rOpenClose[1]['json']['data']['session_id'], (int)$guardia['id_sesion_abierta_actual'], 'Concurrencia', 'Guardia consistente cuando apertura corre tras cierre');
    } else {
        assertSameVal(null, $guardia['id_sesion_abierta_actual'], 'Concurrencia', 'Guardia liberada cuando apertura fue rechazada antes del cierre');
        assertSameVal($idSes5f, (int)$guardia['id_ultima_sesion_cerrada'], 'Concurrencia', 'Guardia registra id_ultima_sesion_cerrada');
    }

    // 5G: Barrera controlada en MariaDB (Orden 1: Cierre adquiere guardia FOR UPDATE -> Apertura espera en BD -> Cierre confirma -> Apertura 201 OPENED)
    if ($rOpenClose[1]['status'] !== 201) {
        $op5gPre = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_open_5g_pre_001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
        $idSes5g = (int)($op5gPre['json']['data']['session_id'] ?? 0);
    } else {
        $idSes5g = (int)$rOpenClose[1]['json']['data']['session_id'];
    }
    $arq5g = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_arq_5g_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq5g = (int)($arq5g['json']['data']['arqueo']['id'] ?? 0);

    $lockSig5g = $tmpDir . '/lock_5g.sig';
    @unlink($lockSig5g);
    $workerCode5g = <<<'PHP'
$db = $argv[1];
$idSes = (int)$argv[2];
$idArq = (int)$argv[3];
$sig = $argv[4];
$pdo = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET SESSION time_zone = '-05:00'");
$pdo->beginTransaction();
$pdo->query("SELECT id_caja_fisica FROM caja_fisica_guardia WHERE id_caja_fisica = 1 FOR UPDATE")->fetch();
file_put_contents($sig, 'LOCKED');
usleep(1400000);
$pdo->prepare("UPDATE sesion_caja SET id_arqueo_cierre = ?, version_caja = version_caja + 1, estado = 'CERRADA', fecha_hora_cierre = NOW(), efectivo_esperado_cierre = 50.00, efectivo_contado_cierre = 50.00, diferencia_cierre = 0.00 WHERE id_sesion_caja = ?")->execute([$idArq, $idSes]);
$pdo->commit();
PHP;
    $workerFile5g = $tmpDir . '/worker_5g.php';
    file_put_contents($workerFile5g, "<?php\n" . $workerCode5g);
    $p5g = proc_open([PHP_BINARY, $workerFile5g, $dbName, (string)$idSes5g, (string)$idArq5g, $lockSig5g], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes5g);
    $tLimit5g = microtime(true) + 5.0;
    while (!is_file($lockSig5g) && microtime(true) < $tLimit5g) {
        usleep(20000);
    }
    assertTrue(is_file($lockSig5g), 'Concurrencia', 'Worker 5G adquirió guardia FOR UPDATE en MariaDB');

    $tStartOpen5g = microtime(true);
    $open5gAfterClose = httpRequest($url2, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 50.00, 'referencia_solicitud' => 'p11_open_5g_wait_01'], ['X-CSRF-TOKEN' => $authV1B['csrf']], $cookieV1B);
    $elapsedOpen5g = microtime(true) - $tStartOpen5g;
    proc_close($p5g);
    assertTrue($elapsedOpen5g >= 1.0, 'Concurrencia', sprintf('Apertura esperó contención real en MariaDB (%.2fs >= 1.0s)', $elapsedOpen5g));
    assertSameVal(201, $open5gAfterClose['status'], 'Concurrencia', 'Orden 1 (Cierre->Apertura): apertura tras cierre devuelve 201 OPENED (sin 500)');
    assertSameVal('OPENED', $open5gAfterClose['json']['code'] ?? null, 'Concurrencia', 'Orden 1 code=OPENED');
    assertSameVal(50.0, (float)($open5gAfterClose['json']['data']['reference_amount'] ?? -1), 'Concurrencia', 'Orden 1 toma efectivo_contado_cierre del cierre recién confirmado');
    $idSes5gNew = (int)($open5gAfterClose['json']['data']['session_id'] ?? 0);
    assertSameVal($idSes5g, (int)$pdo->query("SELECT id_sesion_cierre_anterior FROM sesion_caja WHERE id_sesion_caja = {$idSes5gNew}")->fetchColumn(), 'Concurrencia', 'Orden 1 referencia id_sesion_cierre_anterior correcto');
    assertSameVal(1, (int)$pdo->query("SELECT COUNT(*) FROM sesion_caja WHERE estado = 'ABIERTA'")->fetchColumn(), 'Concurrencia', 'Existe exactamente 1 sesión ABIERTA');
    assertSameVal($idSes5gNew, (int)$pdo->query("SELECT id_sesion_abierta_actual FROM caja_fisica_guardia WHERE id_caja_fisica = 1")->fetchColumn(), 'Concurrencia', 'Guardia física coincide con nueva sesión abierta');

    // 5H: Orden 2 (Apertura mientras sesión ABIERTA -> 409 CASH_ALREADY_OPEN, luego Cierre -> 200, luego reintento con misma referencia -> 201 OPENED)
    $refRetry5h = 'p11_open_5h_retry_01';
    $openFail5h = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 60.50, 'referencia_solicitud' => $refRetry5h], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $openFail5h['status'], 'Concurrencia', 'Orden 2 (Apertura antes de cierre) -> 409 CASH_ALREADY_OPEN');
    assertSameVal('CASH_ALREADY_OPEN', $openFail5h['json']['code'] ?? null, 'Concurrencia', 'Orden 2 code=CASH_ALREADY_OPEN');

    $arq5h = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 50.00, 'version' => 1, 'referencia_solicitud' => 'p11_arq_5h_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArq5h = (int)($arq5h['json']['data']['arqueo']['id'] ?? 0);
    $close5h = httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => $idArq5h, 'referencia_solicitud' => 'p11_close_5h_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $close5h['status'], 'Concurrencia', 'Orden 2 cierre posterior -> 200 CLOSE_CONFIRMED');

    // Reintento de la apertura fallida con la misma referencia -> 201 OPENED (con diferencia positiva +10.50)
    $openRetry5h = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 60.50, 'referencia_solicitud' => $refRetry5h], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $openRetry5h['status'], 'Concurrencia', 'Reintento con misma referencia tras rechazo 409 -> 201 OPENED');
    $idSesPos = (int)($openRetry5h['json']['data']['session_id'] ?? 0);
    assertSameVal(10.5, $openRetry5h['json']['data']['opening_difference'] ?? null, 'Persistencia', 'Apertura con sobrante +10.50 conserva valor y signo');

    // Pendiente B: cierre con diferencia_apertura positiva (+10.50) y negativa (-5.25) sin alterar diferencia_cierre ni crear movimientos
    $arqPos = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 60.50, 'version' => 1, 'referencia_solicitud' => 'p11_arq_pos_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $closePos = httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => (int)($arqPos['json']['data']['arqueo']['id'] ?? 0), 'referencia_solicitud' => 'p11_close_pos_00001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $closePos['status'], 'Persistencia', 'Cierre con diferencia_apertura positiva -> 200');
    assertSameVal(10.5, $closePos['json']['data']['summary']['opening_difference'] ?? null, 'Persistencia', 'EP-20 conserva opening_difference=+10.5');
    assertSameVal(0, $closePos['json']['data']['summary']['difference'] ?? null, 'Persistencia', 'diferencia_apertura positiva no altera summary.difference');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM movimiento_caja WHERE id_sesion_caja = {$idSesPos}")->fetchColumn(), 'Persistencia', 'No se generan movimientos para corregir opening_difference positiva');

    $openNeg = httpRequest($url1, 'POST', '/api/caja/abrir', ['shift_id' => $idTurnoPast, 'cash_counted' => 55.25, 'referencia_solicitud' => 'p11_open_neg_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idSesNeg = (int)($openNeg['json']['data']['session_id'] ?? 0);
    $arqNeg = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 55.25, 'version' => 1, 'referencia_solicitud' => 'p11_arq_neg_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $closeNeg = httpRequest($url1, 'POST', '/api/caja/cerrar', ['arqueo_id' => (int)($arqNeg['json']['data']['arqueo']['id'] ?? 0), 'referencia_solicitud' => 'p11_close_neg_00001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $closeNeg['status'], 'Persistencia', 'Cierre con diferencia_apertura negativa -> 200');
    assertSameVal(-5.25, $closeNeg['json']['data']['summary']['opening_difference'] ?? null, 'Persistencia', 'EP-20 conserva opening_difference=-5.25');
    assertSameVal(0, $closeNeg['json']['data']['summary']['difference'] ?? null, 'Persistencia', 'diferencia_apertura negativa no altera summary.difference');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM movimiento_caja WHERE id_sesion_caja = {$idSesNeg}")->fetchColumn(), 'Persistencia', 'No se generan movimientos para corregir opening_difference negativa');

} finally {
    stopPhpServer($srv1);
    stopPhpServer($srv2);
    @array_map('unlink', glob($sessionDir . '/*') ?: []);
    @rmdir($sessionDir);
    @array_map('unlink', glob($tmpDir . '/*') ?: []);
    @rmdir($tmpDir);
}

echo "\n=== RESUMEN PASO 11 ===\n";
foreach ($categories as $cat => $c) {
    echo "  {$cat}: {$c['passed']}/" . ($c['passed'] + $c['failed']) . "\n";
}
echo "TOTAL: {$passed}/" . ($passed + $failed) . " aserciones correctas.\n";
exit($failed > 0 ? 1 : 0);
