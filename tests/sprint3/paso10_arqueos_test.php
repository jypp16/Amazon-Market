<?php
declare(strict_types=1);

/**
 * Batería integral de aceptación para el Paso 10 del Sprint 3
 * (EP-17, EP-18, EP-19: Arqueo de Efectivo, Historial y Control de Vigencia).
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

$httpPort1 = (int)(getenv('TEST_HTTP_PORT') ?: 8094);
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
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_NOBODY         => ($method === 'HEAD'),
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADER         => true,
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
        'status'  => $status,
        'json'    => is_array($json) ? $json : null,
        'raw'     => $respBody,
        'headers' => $respHeaders,
        'error'   => '',
    ];
}

function startPhpServer(string $phpBin, string $rootDir, string $sessionDir, string $dbName, int $port): array
{
    $routerPath = $rootDir . '/tests/sprint3/server_router_sprint3.php';
    $env = array_merge($_ENV, getenv(), [
        'DB_NAME'    => $dbName,
        'BASE_URL'   => "http://127.0.0.1:{$port}",
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
        'PATH'       => getenv('PATH') ?: '',
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
        1 => ['file', sys_get_temp_dir() . "/am_paso10_srv_{$port}.out.log", 'w'],
        2 => ['file', sys_get_temp_dir() . "/am_paso10_srv_{$port}.err.log", 'w'],
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

    return [
        'login' => ['status' => $code, 'raw' => (string)$resp],
        'csrf'  => ($code === 302 && str_contains((string)$resp, '/Home')) ? $csrf : '',
    ];
}

function extractPhpSessId(string $cookieFile): string
{
    if (!is_file($cookieFile)) {
        return '';
    }
    $lines = file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }
    foreach ($lines as $line) {
        $clean = str_starts_with($line, '#HttpOnly_') ? substr($line, 10) : $line;
        if (str_starts_with($clean, '#')) {
            continue;
        }
        $parts = explode("\t", $clean);
        if (count($parts) >= 7 && $parts[5] === 'PHPSESSID') {
            return (string)$parts[6];
        }
    }
    return '';
}

function cerrarSesionFixture(PDO $pdo, int $idSesion): void
{
    $stmtS = $pdo->prepare('SELECT * FROM sesion_caja WHERE id_sesion_caja = ? LIMIT 1');
    $stmtS->execute([$idSesion]);
    $sesion = $stmtS->fetch(PDO::FETCH_ASSOC);
    if (!$sesion) {
        throw new RuntimeException("Sesión {$idSesion} no encontrada");
    }
    $esperado = (string)$sesion['efectivo_esperado_actual'];
    $idArqueo = (int)($sesion['id_ultimo_arqueo'] ?? 0);
    if ($idArqueo <= 0) {
        $stmtA = $pdo->prepare(
            "INSERT INTO arqueo_caja (id_sesion_caja, id_usuario_vendedor, version_caja_evaluada, monto_esperado, monto_contado, diferencia, observacion, estado, vigencia_activa)
             VALUES (:s, :u, :v, :e, :e, 0.00, NULL, 'VIGENTE', 1)"
        );
        $stmtA->execute([
            ':s' => $idSesion,
            ':u' => (int)$sesion['id_usuario_vendedor'],
            ':v' => (int)$sesion['version_caja'],
            ':e' => $esperado,
        ]);
        $idArqueo = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE sesion_caja SET id_ultimo_arqueo = :a WHERE id_sesion_caja = :s')->execute([':a' => $idArqueo, ':s' => $idSesion]);
    }
    $stmtAnt = $pdo->prepare(
        "SELECT (NOW() < DATE_SUB(fecha_hora_salida_referencia, INTERVAL 15 MINUTE)) AS es_anticipado,
                TIME(fecha_hora_salida_referencia) AS hora_salida_referencia,
                fecha_hora_salida_referencia,
                GREATEST(16, CEIL(TIMESTAMPDIFF(SECOND, NOW(), fecha_hora_salida_referencia) / 60)) AS mins
           FROM sesion_caja
          WHERE id_sesion_caja = :s"
    );
    $stmtAnt->execute([':s' => $idSesion]);
    $ant = $stmtAnt->fetch();
    if ($ant && (int)$ant['es_anticipado'] === 1) {
        $idAdmin = (int)$pdo->query("SELECT id_usuario FROM usuario WHERE id_rol = 1 AND estado = 1 ORDER BY id_usuario ASC LIMIT 1")->fetchColumn();
        if ($idAdmin > 0) {
            $pdo->prepare(
                "INSERT INTO autorizacion_caja (
                    id_sesion_caja, tipo_operacion, id_usuario_vendedor, id_usuario_admin,
                    motivo, hora_salida_referencia, fecha_hora_salida_referencia,
                    hora_cierre_real, minutos_anticipacion
                 ) VALUES (
                    :s, 'CIERRE_ANTICIPADO', :v, :a,
                    'Cierre fixture prueba paso 10', :hs, :fhs,
                    NOW(), :m
                 )"
            )->execute([
                ':s'   => $idSesion,
                ':v'   => (int)$sesion['id_usuario_vendedor'],
                ':a'   => $idAdmin,
                ':hs'  => $ant['hora_salida_referencia'],
                ':fhs' => $ant['fecha_hora_salida_referencia'],
                ':m'   => (int)$ant['mins'],
            ]);
        }
    }
    $pdo->prepare(
        "UPDATE sesion_caja
            SET id_arqueo_cierre = :a,
                version_caja = version_caja + 1,
                estado = 'CERRADA',
                fecha_hora_cierre = NOW(),
                efectivo_esperado_cierre = :e,
                efectivo_contado_cierre = :e,
                diferencia_cierre = 0.00
          WHERE id_sesion_caja = :s"
    )->execute([':a' => $idArqueo, ':e' => $esperado, ':s' => $idSesion]);
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, $dbName, DB_CHARSET);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tmpDir = sys_get_temp_dir() . '/am_paso10_' . uniqid();
$sessionDir = $tmpDir . '/sessions';
mkdir($sessionDir, 0777, true);

$srv1 = startPhpServer($phpBin, $rootDir, $sessionDir, $dbName, $httpPort1);
$srv2 = startPhpServer($phpBin, $rootDir, $sessionDir, $dbName, $httpPort2);

try {
    $url1 = $srv1['url'];
    $url2 = $srv2['url'];

    $testPass = 'Paso10Test#2026';
    $hash = password_hash($testPass, PASSWORD_BCRYPT);

    $admin = $pdo->query("SELECT id_usuario, username FROM usuario WHERE id_rol = 1 AND estado = 1 ORDER BY id_usuario ASC LIMIT 1")->fetch();
    $vendedores = $pdo->query("SELECT id_usuario, username FROM usuario WHERE id_rol = 2 AND estado = 1 ORDER BY id_usuario ASC LIMIT 2")->fetchAll();
    if (!$admin || count($vendedores) < 2) {
        throw new RuntimeException('Se requiere 1 administrador y 2 vendedores activos en la BD.');
    }
    $vend1 = $vendedores[0];
    $vend2 = $vendedores[1];

    // Vendedor 3 inactivo para pruebas de usuario desactivado
    $stmtInsU = $pdo->prepare("INSERT INTO usuario (id_rol, username, password_hash, nombre, dni, telefono, direccion, email, estado) VALUES (2, :u, :p, :n, :d, '900000010', 'Av Test', :e, 1)");
    $stmtInsU->execute([':u' => 'vndp10dis', ':p' => $hash, ':n' => 'Vend Dis Paso10', ':d' => '81000001', ':e' => 'vndp10dis@test.local']);
    $idVendDis = (int)$pdo->lastInsertId();

    $stmtPass = $pdo->prepare('UPDATE usuario SET password_hash = :p WHERE id_usuario = :id');
    foreach ([$admin['id_usuario'], $vend1['id_usuario'], $vend2['id_usuario']] as $uid) {
        $stmtPass->execute([':p' => $hash, ':id' => $uid]);
    }

    $cookieAdmin = $tmpDir . '/admin.cookie';
    $cookieV1A = $tmpDir . '/v1a.cookie';
    $cookieV1B = $tmpDir . '/v1b.cookie';
    $cookieV2 = $tmpDir . '/v2.cookie';
    $cookieDis = $tmpDir . '/dis.cookie';

    $authAdmin = loginHttp($url1, $admin['username'], $testPass, $cookieAdmin);
    $authV1A = loginHttp($url1, $vend1['username'], $testPass, $cookieV1A);
    $authV1B = loginHttp($url2, $vend1['username'], $testPass, $cookieV1B);
    $authV2 = loginHttp($url2, $vend2['username'], $testPass, $cookieV2);
    $authDis = loginHttp($url1, 'vndp10dis', $testPass, $cookieDis);

    assertTrue($authAdmin['csrf'] !== '' && $authV1A['csrf'] !== '' && $authV1B['csrf'] !== '' && $authV2['csrf'] !== '' && $authDis['csrf'] !== '', 'PRE', 'Logins HTTP completados');
    assertTrue(extractPhpSessId($cookieV1A) !== extractPhpSessId($cookieV1B), 'PRE', 'Sesiones PHP independientes para concurrencia (PHPSESSID A != PHPSESSID B)');

    // =========================================================================
    // SECCIÓN 1: SEGURIDAD, ROLES, PERMISOS POR RUTA Y CAJA CERRADA
    // =========================================================================
    assertSameVal(401, httpRequest($url1, 'GET', '/api/caja/arqueo/estado')['status'], 'Seguridad', 'EP-17 sin sesión -> 401');
    assertSameVal(401, httpRequest($url1, 'GET', '/api/caja/arqueos')['status'], 'Seguridad', 'EP-18 sin sesión -> 401');
    assertSameVal(401, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 0, 'version' => 1, 'referencia_solicitud' => 'p10_unauth_0000001'])['status'], 'Seguridad', 'EP-19 sin sesión -> 401');

    // Desactivar usuario en BD
    $pdo->prepare('UPDATE usuario SET estado = 0 WHERE id_usuario = ?')->execute([$idVendDis]);
    $resDis = httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieDis);
    assertSameVal(401, $resDis['status'], 'Seguridad', 'Usuario desactivado en BD -> 401 AUTH_REQUIRED');
    assertSameVal('AUTH_REQUIRED', $resDis['json']['code'] ?? null, 'Seguridad', 'Code AUTH_REQUIRED para inactivo');

    // Admin rechazado en EP-17, EP-18, EP-19
    assertSameVal(403, httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieAdmin)['status'], 'Seguridad', 'Admin rechazado en EP-17 -> 403');
    assertSameVal(403, httpRequest($url1, 'GET', '/api/caja/arqueos', null, [], $cookieAdmin)['status'], 'Seguridad', 'Admin rechazado en EP-18 -> 403');
    $resAdmPost = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 0, 'version' => 1, 'referencia_solicitud' => 'p10_adm_post_00001'], ['X-CSRF-TOKEN' => $authAdmin['csrf']], $cookieAdmin);
    assertSameVal(403, $resAdmPost['status'], 'Seguridad', 'Admin rechazado en EP-19 -> 403');

    // Vendedor sin permiso turnos.arqueo rechazado, pero conserva turnos.abrir (EP-08)
    $idPermArqueo = (int)$pdo->query("SELECT id_permiso FROM permiso WHERE slug = 'turnos.arqueo' LIMIT 1")->fetchColumn();
    $pdo->prepare('DELETE FROM rol_permiso WHERE id_rol = 2 AND id_permiso = ?')->execute([$idPermArqueo]);
    assertSameVal(403, httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A)['status'], 'Seguridad', 'Sin turnos.arqueo EP-17 -> 403');
    assertSameVal(403, httpRequest($url1, 'GET', '/api/caja/arqueos', null, [], $cookieV1A)['status'], 'Seguridad', 'Sin turnos.arqueo EP-18 -> 403');
    assertSameVal(403, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 0, 'version' => 1, 'referencia_solicitud' => 'p10_noperm_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Seguridad', 'Sin turnos.arqueo EP-19 -> 403');
    assertSameVal(200, httpRequest($url1, 'GET', '/api/caja/estado', null, [], $cookieV1A)['status'], 'Seguridad', 'EP-08 (turnos.abrir) sigue accesible sin turnos.arqueo');
    $pdo->prepare('INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (2, ?)')->execute([$idPermArqueo]);

    // CSRF y JSON inválidos en EP-19
    assertSameVal(403, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 0, 'version' => 1, 'referencia_solicitud' => 'p10_badcsrf_000001'], ['X-CSRF-TOKEN' => 'invalido'], $cookieV1A)['status'], 'Seguridad', 'EP-19 CSRF inválido -> 403');
    assertSameVal(400, httpRequest($url1, 'POST', '/api/caja/arqueo', null, ['X-CSRF-TOKEN' => $authV1A['csrf'], 'Content-Type' => 'application/json'], $cookieV1A, '{bad')['status'], 'Seguridad', 'EP-19 JSON inválido -> 400 INVALID_JSON');

    // Caja cerrada: EP-17 (200 con nulls), EP-18 (409 CASH_SESSION_CLOSED), EP-19 (409 CASH_SESSION_CLOSED)
    $ep17Closed = httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A);
    assertSameVal(200, $ep17Closed['status'], 'Estado', 'EP-17 con caja cerrada -> 200');
    $dClosed = $ep17Closed['json']['data'] ?? [];
    assertSameVal(false, $dClosed['is_open'] ?? null, 'Estado', 'Caja cerrada is_open=false');
    assertSameVal(false, $dClosed['is_owner'] ?? null, 'Estado', 'Caja cerrada is_owner=false');
    assertSameVal(null, array_key_exists('session_id', $dClosed) ? $dClosed['session_id'] : 'MISSING', 'Estado', 'Caja cerrada session_id=null');
    assertSameVal(null, array_key_exists('expected', $dClosed) ? $dClosed['expected'] : 'MISSING', 'Estado', 'Caja cerrada expected=null');
    assertSameVal([], $dClosed['history'] ?? null, 'Estado', 'Caja cerrada history=[]');
    assertSameVal(false, $dClosed['has_valid_arqueo'] ?? null, 'Estado', 'Caja cerrada has_valid_arqueo=false');
    assertSameVal(null, array_key_exists('active_arqueo_id', $dClosed) ? $dClosed['active_arqueo_id'] : 'MISSING', 'Estado', 'Caja cerrada active_arqueo_id=null');
    assertSameVal(null, array_key_exists('closed_summary', $dClosed) ? $dClosed['closed_summary'] : 'MISSING', 'Estado', 'Caja cerrada closed_summary=null');
    assertSameVal(null, array_key_exists('schedule', $dClosed) ? $dClosed['schedule'] : 'MISSING', 'Estado', 'Caja cerrada schedule=null');

    assertSameVal(409, httpRequest($url1, 'GET', '/api/caja/arqueos', null, [], $cookieV1A)['status'], 'Seguridad', 'EP-18 con caja cerrada -> 409');
    assertSameVal(409, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 0, 'version' => 1, 'referencia_solicitud' => 'p10_closed_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Seguridad', 'EP-19 con caja cerrada -> 409');

    // =========================================================================
    // SECCIÓN 2: APERTURA SALDO CERO, SESIÓN AJENA, CONTEO Y OBSERVACIÓN
    // =========================================================================
    $pdo->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES ('Mañana Paso10', '07:00:00', '12:00:00', 1)");
    $idTurno = (int)$pdo->lastInsertId();

    $openZero = httpRequest($url1, 'POST', '/api/caja/abrir', [
        'shift_id'             => $idTurno,
        'cash_counted'         => 0.00,
        'referencia_solicitud' => 'p10_open_zero_00001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $openZero['status'], 'Estado', 'Apertura con saldo 0.00 -> 201');
    $idSesZero = (int)($openZero['json']['data']['session_id'] ?? 0);

    // Sesión propia saldo cero sin arqueos
    $ep17Zero = httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A);
    assertSameVal(true, $ep17Zero['json']['data']['is_open'] ?? null, 'Estado', 'Saldo cero is_open=true');
    assertSameVal(true, $ep17Zero['json']['data']['is_owner'] ?? null, 'Estado', 'Saldo cero is_owner=true');
    assertTrue(abs((float)($ep17Zero['json']['data']['expected'] ?? -1) - 0.0) < 0.001, 'Estado', 'Saldo cero expected=0');
    assertSameVal(false, $ep17Zero['json']['data']['has_valid_arqueo'] ?? null, 'Estado', 'Sin arqueos has_valid_arqueo=false');
    assertSameVal(null, array_key_exists('active_arqueo_id', $ep17Zero['json']['data'] ?? []) ? $ep17Zero['json']['data']['active_arqueo_id'] : 'MISSING', 'Estado', 'Sin arqueos active_arqueo_id=null');

    // Sesión ajena (Vendedor 2)
    $ep17Ajena = httpRequest($url2, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV2);
    assertSameVal(true, $ep17Ajena['json']['data']['is_open'] ?? null, 'Seguridad', 'EP-17 ajena is_open=true');
    assertSameVal(false, $ep17Ajena['json']['data']['is_owner'] ?? null, 'Seguridad', 'EP-17 ajena is_owner=false');
    assertSameVal(null, array_key_exists('expected', $ep17Ajena['json']['data'] ?? []) ? $ep17Ajena['json']['data']['expected'] : 'MISSING', 'Seguridad', 'EP-17 ajena oculta expected=null');
    assertSameVal([], $ep17Ajena['json']['data']['history'] ?? null, 'Seguridad', 'EP-17 ajena oculta history=[]');

    assertSameVal(403, httpRequest($url2, 'GET', '/api/caja/arqueos', null, [], $cookieV2)['status'], 'Seguridad', 'EP-18 ajena -> 403 SESSION_NOT_OWNED');
    assertSameVal(403, httpRequest($url2, 'POST', '/api/caja/arqueo', ['counted' => 0, 'version' => 1, 'referencia_solicitud' => 'p10_ajena_00000001'], ['X-CSRF-TOKEN' => $authV2['csrf']], $cookieV2)['status'], 'Seguridad', 'EP-19 ajena -> 403 SESSION_NOT_OWNED');

    // Guardar arqueo 0.00 sin observación cuando expected = 0.00
    $resArqZero = httpRequest($url1, 'POST', '/api/caja/arqueo', [
        'counted'              => 0.00,
        'observation'          => null,
        'version'              => 1,
        'referencia_solicitud' => 'p10_arq_zero_000001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $resArqZero['status'], 'Conteo', 'Arqueo 0.00 cuando expected=0.00 sin observación -> 201');
    assertSameVal('ARQUEO_SAVED', $resArqZero['json']['code'] ?? null, 'Conteo', 'Code exterior = ARQUEO_SAVED');
    assertSameVal(false, $resArqZero['json']['idempotent_replay'] ?? null, 'Conteo', 'idempotent_replay exterior = false');

    // Cerrar sesión cero (fixture respetando triggers) para abrir sesión operativa con movimientos
    cerrarSesionFixture($pdo, $idSesZero);

    // EP-18 sobre sesión propia cerrada explícita -> 409 CASH_SESSION_CLOSED
    $resEp18ClosedExplicit = httpRequest($url1, 'GET', "/api/caja/arqueos?session_id={$idSesZero}", null, [], $cookieV1A);
    assertSameVal(409, $resEp18ClosedExplicit['status'], 'Historial', 'EP-18 con session_id propio cerrado -> 409 CASH_SESSION_CLOSED');
    assertSameVal(404, httpRequest($url1, 'GET', '/api/caja/arqueos?session_id=999999', null, [], $cookieV1A)['status'], 'Historial', 'EP-18 session_id inexistente -> 404');
    assertSameVal(422, httpRequest($url1, 'GET', '/api/caja/arqueos?session_id=1&sesion_id=2', null, [], $cookieV1A)['status'], 'Historial', 'EP-18 alias contradictorios -> 422');

    // =========================================================================
    // SECCIÓN 3: SESIÓN OPERATIVA CON TODOS LOS MOVIMIENTOS Y MEDIOS DE PAGO
    // =========================================================================
    sleep(1); // Garantizar fecha_hora_cierre < fecha_hora_apertura para retiro omitido
    $openOp = httpRequest($url1, 'POST', '/api/caja/abrir', [
        'shift_id'             => $idTurno,
        'cash_counted'         => 190.00,
        'referencia_solicitud' => 'p10_open_oper_00001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $openOp['status'], 'PRE', 'Apertura operativa con 190.00 -> 201');
    $idSesOp = (int)($openOp['json']['data']['session_id'] ?? 0);

    // Ventas (Efectivo, Tarjeta, Yape), Ingreso, Egreso, Retiro Omitido
    $prod = $pdo->query("SELECT id_producto, precio_venta FROM producto WHERE estado = 1 AND stock_actual >= 20 AND precio_venta > 0 ORDER BY id_producto ASC LIMIT 1")->fetch();
    $idProd = (int)$prod['id_producto'];
    $precioProd = (float)$prod['precio_venta'];
    $idCli = (int)$pdo->query("SELECT id_cliente FROM cliente WHERE estado = 1 AND id_tipo_documento <> 2 ORDER BY id_cliente ASC LIMIT 1")->fetchColumn();
    $idBol = (int)$pdo->query("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE estado = 1 AND nombre LIKE '%Boleta%' LIMIT 1")->fetchColumn();

    $mkVenta = function (int $mp, string $ref) use ($url1, $authV1A, $cookieV1A, $idCli, $idBol, $idProd): array {
        return httpRequest($url1, 'POST', '/api/ventas', [
            'id_cliente'           => $idCli,
            'id_tipo_comprobante'  => $idBol,
            'id_metodo_pago'       => $mp,
            'productos'            => [['id_producto' => $idProd, 'cantidad' => 1]],
            'referencia_solicitud' => $ref,
        ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    };

    $vEfectivo = $mkVenta(1, 'p10_vta_efectivo_001'); // ver 2
    $vTarjeta = $mkVenta(2, 'p10_vta_tarjeta_0001'); // ver 3
    $vYape = $mkVenta(3, 'p10_vta_yape_0000001'); // ver 4
    $ing = httpRequest($url1, 'POST', '/api/caja/ingresos', ['type' => 'manual_income', 'amount' => 35.00, 'reason' => 'Ingreso prueba Paso 10', 'referencia_solicitud' => 'p10_ingreso_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A); // ver 5
    $egr = httpRequest($url1, 'POST', '/api/caja/egresos', ['type' => 'cash_out', 'amount' => 30.00, 'reason' => 'Egreso prueba Paso 10', 'referencia_solicitud' => 'p10_egreso_00000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A); // ver 6
    $omit = httpRequest($url1, 'POST', '/api/caja/egresos', ['type' => 'omitted_withdrawal', 'amount' => 300.00, 'reason' => 'Retiro omitido previo', 'administrator_id' => (int)$admin['id_usuario'], 'origin_session_id' => $idSesZero, 'declared_occurred_in_open_origin_session' => true, 'confirmed_reflected_in_opening' => true, 'referencia_solicitud' => 'p10_omitido_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A); // ver 7

    assertTrue($vEfectivo['status'] === 201 && $vTarjeta['status'] === 201 && $vYape['status'] === 201 && $ing['status'] === 201 && $egr['status'] === 201 && $omit['status'] === 201, 'PRE', 'Operaciones previas al arqueo registradas');

    $expectedOp = round(190.00 + $precioProd + 35.00 - 30.00, 2);
    $ep17Op = httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A);
    $dOp = $ep17Op['json']['data'] ?? [];
    assertTrue(abs((float)$dOp['expected'] - $expectedOp) < 0.001, 'Estado', 'EP-17 expected = opening + cash_sales + income - expenses');
    assertTrue(abs((float)$dOp['card'] - $precioProd) < 0.001, 'Estado', 'EP-17 card refleja venta tarjeta');
    assertTrue(abs((float)$dOp['yape_plin'] - $precioProd) < 0.001, 'Estado', 'EP-17 yape_plin refleja venta yape');
    assertTrue(abs((float)$dOp['omitted_withdrawals_zero_impact_total'] - 300.00) < 0.001, 'Estado', 'EP-17 omitted_withdrawals_zero_impact_total = 300.00');
    assertSameVal(7, $dOp['version'] ?? null, 'Estado', 'version = 7 tras 6 operaciones');

    // Validaciones de conteo y observación en EP-19
    $diff001 = round($expectedOp - 0.01, 2);
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $diff001, 'version' => 7, 'referencia_solicitud' => 'p10_obs_missing_001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Conteo', 'Diferencia 0.01 sin observation -> 422 OBSERVATION_REQUIRED');
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $diff001, 'observation' => '   ', 'version' => 7, 'referencia_solicitud' => 'p10_obs_spaces_0001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Conteo', 'Diferencia con observation de solo espacios -> 422');
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $expectedOp, 'observation' => 123, 'version' => 7, 'referencia_solicitud' => 'p10_obs_int_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Conteo', 'observation no string -> 422');
    $obs501 = str_repeat('Á', 501);
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $diff001, 'observation' => $obs501, 'version' => 7, 'referencia_solicitud' => 'p10_obs_501_0000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Conteo', 'observation 501 chars -> 422');
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 1.001, 'version' => 7, 'referencia_solicitud' => 'p10_prec_bad_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Conteo', 'counted > 2 decimales -> 422 INVALID_AMOUNT');
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => 100000000.00, 'version' => 7, 'referencia_solicitud' => 'p10_range_bad_00001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Conteo', 'counted > 99999999.99 -> 422 AMOUNT_OUT_OF_RANGE');
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => true, 'version' => 7, 'referencia_solicitud' => 'p10_bool_cnt_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Conteo', 'counted booleano -> 422');
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $expectedOp, 'version' => true, 'referencia_solicitud' => 'p10_bool_ver_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Conteo', 'version booleano -> 422');

    // counted repetido con escape Unicode \u0063ounted -> 422 INVALID_AMOUNT
    $rawDup = '{"referencia_solicitud":"p10_dup_unicode_001","counted":10.00,"\\u0063ounted":20.00,"version":7}';
    assertSameVal(422, httpRequest($url1, 'POST', '/api/caja/arqueo', null, ['X-CSRF-TOKEN' => $authV1A['csrf'], 'Content-Type' => 'application/json'], $cookieV1A, $rawDup)['status'], 'Conteo', 'counted repetido con escape Unicode -> 422');

    // Observación Unicode de exactamente 500 caracteres con faltante de 0.01
    $obs500 = str_repeat('Ñ', 500);
    $resArq1 = httpRequest($url1, 'POST', '/api/caja/arqueo', [
        'counted'              => $diff001,
        'observation'          => $obs500,
        'version'              => 7,
        'referencia_solicitud' => 'p10_arq1_obs500_0001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $resArq1['status'], 'Persistencia', 'Primer arqueo con faltante 0.01 y obs Unicode 500 chars -> 201');
    $idArq1 = (int)($resArq1['json']['data']['arqueo']['id'] ?? 0);
    assertSameVal($obs500, $resArq1['json']['data']['arqueo']['observation'] ?? null, 'Persistencia', 'Observación Unicode 500 chars conservada íntegra');
    assertSameVal('Vigente', $resArq1['json']['data']['arqueo']['status'] ?? null, 'Persistencia', 'Estado público Vigente');
    assertSameVal(7, $resArq1['json']['data']['version'] ?? null, 'Persistencia', 'Guardar arqueo NO incrementa version_caja');

    // Segundo arqueo en misma versión reemplaza al primero
    $sobrante = round($expectedOp + 5.00, 2);
    $refArq2 = 'p10_arq2_sobrante_01';
    $resArq2 = httpRequest($url1, 'POST', '/api/caja/arqueo', [
        'counted'              => $sobrante,
        'observation'          => 'Sobrante S/ 5.00',
        'version'              => 7,
        'referencia_solicitud' => $refArq2,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $resArq2['status'], 'Persistencia', 'Segundo arqueo misma versión -> 201');
    $idArq2 = (int)($resArq2['json']['data']['arqueo']['id'] ?? 0);
    assertSameVal('Reemplazado', $resArq2['json']['data']['history'][0]['status'] ?? null, 'Persistencia', 'Arqueo 1 pasó a Reemplazado');
    assertSameVal('Vigente', $resArq2['json']['data']['history'][1]['status'] ?? null, 'Persistencia', 'Arqueo 2 quedó Vigente');
    assertSameVal(7, (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn(), 'Persistencia', 'version_caja intacta en 7');

    // Idempotencia replay inmediato de Arqueo 2 y payload mismatch
    $resArq2Replay = httpRequest($url1, 'POST', '/api/caja/arqueo', [
        'counted'              => $sobrante,
        'observation'          => 'Sobrante S/ 5.00',
        'version'              => 7,
        'referencia_solicitud' => $refArq2,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $resArq2Replay['status'], 'Idempotencia', 'Replay inmediato EP-19 -> 200');
    assertSameVal('OPERATION_ALREADY_CONFIRMED', $resArq2Replay['json']['code'] ?? null, 'Idempotencia', 'Replay code exterior OPERATION_ALREADY_CONFIRMED');
    assertSameVal(true, $resArq2Replay['json']['idempotent_replay'] ?? null, 'Idempotencia', 'Replay idempotent_replay = true');
    assertSameVal('ARQUEO_SAVED', $resArq2Replay['json']['data']['result_code'] ?? null, 'Idempotencia', 'Replay conserva data.result_code = ARQUEO_SAVED');
    assertSameVal($resArq2['json']['data'] ?? null, $resArq2Replay['json']['data'] ?? null, 'Idempotencia', 'Replay data idéntica a original');

    assertSameVal(409, httpRequest($url1, 'POST', '/api/caja/arqueo', [
        'counted'              => $expectedOp,
        'observation'          => null,
        'version'              => 7,
        'referencia_solicitud' => $refArq2,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A)['status'], 'Idempotencia', 'Misma referencia payload distinto -> 409 IDEMPOTENCY_PAYLOAD_MISMATCH');

    // =========================================================================
    // SECCIÓN 4: VIGENCIA DESPUÉS DE TODAS LAS OPERACIONES Y REPLAY ANTIGUO
    // =========================================================================
    $assertInvalidatedBy = function (string $label, callable $opFn) use ($url1, $authV1A, $cookieV1A, $expectedOp, $pdo, $idSesOp): void {
        $verNow = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
        $espNow = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
        $rArq = httpRequest($url1, 'POST', '/api/caja/arqueo', [
            'counted'              => $espNow,
            'observation'          => null,
            'version'              => $verNow,
            'referencia_solicitud' => 'p10_v_' . substr(md5($label . uniqid()), 0, 16),
        ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
        assertSameVal(201, $rArq['status'], 'Vigencia', "Arqueo previo a {$label} -> 201");
        $idA = (int)($rArq['json']['data']['arqueo']['id'] ?? 0);

        $rOp = $opFn();
        assertSameVal(201, $rOp['status'], 'Vigencia', "Operación {$label} -> 201");

        $ep17After = httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A);
        assertSameVal($verNow + 1, $ep17After['json']['data']['version'] ?? null, 'Vigencia', "{$label} incrementa version_caja");
        assertSameVal(false, $ep17After['json']['data']['has_valid_arqueo'] ?? null, 'Vigencia', "{$label} deja has_valid_arqueo=false");
        assertSameVal(null, array_key_exists('active_arqueo_id', $ep17After['json']['data'] ?? []) ? $ep17After['json']['data']['active_arqueo_id'] : 'MISSING', 'Vigencia', "{$label} deja active_arqueo_id=null");
        assertSameVal('DESACTUALIZADO', $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idA}")->fetchColumn(), 'Vigencia', "{$label} desactualiza arqueo en BD");
    };

    $assertInvalidatedBy('venta_efectivo', fn () => $mkVenta(1, 'p10_vig_ve_0000001'));
    $assertInvalidatedBy('venta_tarjeta', fn () => $mkVenta(2, 'p10_vig_vt_0000001'));
    $assertInvalidatedBy('venta_yape', fn () => $mkVenta(3, 'p10_vig_vy_0000001'));
    $assertInvalidatedBy('ingreso', fn () => httpRequest($url1, 'POST', '/api/caja/ingresos', ['type' => 'manual_income', 'amount' => 5.00, 'reason' => 'Ing vig', 'referencia_solicitud' => 'p10_vig_ing_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A));
    $assertInvalidatedBy('egreso', fn () => httpRequest($url1, 'POST', '/api/caja/egresos', ['type' => 'cash_out', 'amount' => 2.00, 'reason' => 'Egr vig', 'referencia_solicitud' => 'p10_vig_egr_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A));
    $assertInvalidatedBy('retiro_omitido', fn () => httpRequest($url1, 'POST', '/api/caja/egresos', ['type' => 'omitted_withdrawal', 'amount' => 10.00, 'reason' => 'Omit vig', 'administrator_id' => (int)$admin['id_usuario'], 'origin_session_id' => $idSesZero, 'declared_occurred_in_open_origin_session' => true, 'confirmed_reflected_in_opening' => true, 'referencia_solicitud' => 'p10_vig_omi_000001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A));

    // Guardar arqueo vigente y verificar que replay de venta antigua NO lo invalida, y corrección sí lo invalida
    $verNow = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $espNow = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $rArqPreReplay = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $espNow, 'version' => $verNow, 'referencia_solicitud' => 'p10_arq_prereplay_1'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArqPreReplay = (int)($rArqPreReplay['json']['data']['arqueo']['id'] ?? 0);

    $rOldReplay = $mkVenta(1, 'p10_vta_efectivo_001');
    assertSameVal(200, $rOldReplay['status'], 'Vigencia', 'Replay de venta antigua -> 200');
    assertSameVal($idArqPreReplay, httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A)['json']['data']['active_arqueo_id'] ?? null, 'Vigencia', 'Replay de venta antigua NO invalida arqueo posterior');

    // Corrección invalida el arqueo vigente
    $idIngVig = (int)$ing['json']['data']['movement']['id'];
    $rCorr = httpRequest($url1, 'POST', '/api/caja/movimientos/correcciones', ['original_movement_id' => $idIngVig, 'corrected_amount' => 40.00, 'reason' => 'Ajuste correccion Paso 10', 'referencia_solicitud' => 'p10_vig_corr_00001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $rCorr['status'], 'Vigencia', 'Corrección -> 201');
    assertSameVal('DESACTUALIZADO', $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArqPreReplay}")->fetchColumn(), 'Vigencia', 'Corrección desactualiza arqueo vigente');

    // CASH_CHANGED al enviar versión desactualizada (version=7 cuando actual es 14)
    $rChanged = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $espNow, 'version' => 7, 'referencia_solicitud' => 'p10_cash_changed_1'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(409, $rChanged['status'], 'Vigencia', 'Versión obsoleta -> 409 CASH_CHANGED');
    assertSameVal('CASH_CHANGED', $rChanged['json']['code'] ?? null, 'Vigencia', 'Code CASH_CHANGED');
    assertTrue(isset($rChanged['json']['data']['version']) && isset($rChanged['json']['data']['expected']), 'Vigencia', 'CASH_CHANGED devuelve expected y version actuales');

    // =========================================================================
    // SECCIÓN 5: ROLLBACK POSTERIOR A ESCRITURAS Y REPLAY POST-DESACTUALIZACIÓN/CIERRE
    // =========================================================================
    $verBeforeRb = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $espBeforeRb = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $rArqVigenteRb = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $espBeforeRb, 'version' => $verBeforeRb, 'referencia_solicitud' => 'p10_arq_before_rb_1'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $idArqVigenteRb = (int)($rArqVigenteRb['json']['data']['arqueo']['id'] ?? 0);
    $countArqBeforeRb = (int)$pdo->query("SELECT COUNT(*) FROM arqueo_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();

    $pdo->exec('DROP TRIGGER IF EXISTS trg_test_paso10_rollback_after_insert');
    $pdo->exec("
        CREATE TRIGGER trg_test_paso10_rollback_after_insert
        BEFORE UPDATE ON sesion_caja
        FOR EACH ROW
        BEGIN
            IF NEW.id_sesion_caja = {$idSesOp} AND NOT (OLD.id_ultimo_arqueo <=> NEW.id_ultimo_arqueo) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TEST_PASO10_ROLLBACK';
            END IF;
        END
    ");
    $refRb = 'p10_rb_arqueo_000001';
    $rRbFail = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $espBeforeRb, 'version' => $verBeforeRb, 'referencia_solicitud' => $refRb], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    $pdo->exec('DROP TRIGGER IF EXISTS trg_test_paso10_rollback_after_insert');

    assertSameVal(500, $rRbFail['status'], 'Persistencia', 'Fallo posterior a escrituras de arqueo -> 500');
    assertSameVal($countArqBeforeRb, (int)$pdo->query("SELECT COUNT(*) FROM arqueo_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn(), 'Persistencia', 'Rollback elimina arqueo nuevo');
    assertSameVal('VIGENTE', $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArqVigenteRb}")->fetchColumn(), 'Persistencia', 'Rollback restaura vigencia del arqueo anterior');
    assertSameVal($idArqVigenteRb, (int)$pdo->query("SELECT id_ultimo_arqueo FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn(), 'Persistencia', 'Rollback restaura sesion_caja.id_ultimo_arqueo');

    $rRbRetry = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $espBeforeRb, 'version' => $verBeforeRb, 'referencia_solicitud' => $refRb], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $rRbRetry['status'], 'Persistencia', 'Reintento tras rollback -> 201 ARQUEO_SAVED');

    // =========================================================================
    // SECCIÓN 6: CONCURRENCIA REAL (2 SESIONES PHP INDEPENDIENTES)
    // =========================================================================
    $verConc = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $espConc = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();

    // 6A: Dos arqueos nuevos simultáneos (referencias distintas, misma versión)
    $runTwoConcurrent = function (array $req1, array $req2) use ($url1, $url2, $authV1A, $authV1B, $cookieV1A, $cookieV1B): array {
        $mh = curl_multi_init();
        $mk = function (string $url, string $csrf, string $cookie, array $payload) {
            $ch = curl_init($url . '/api/caja/arqueo');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "X-CSRF-TOKEN: {$csrf}"],
                CURLOPT_COOKIEFILE     => $cookie,
                CURLOPT_COOKIEJAR      => $cookie,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_POSTFIELDS     => json_encode($payload),
            ]);
            return $ch;
        };
        $c1 = $mk($url1, $authV1A['csrf'], $cookieV1A, $req1);
        $c2 = $mk($url2, $authV1B['csrf'], $cookieV1B, $req2);
        curl_multi_add_handle($mh, $c1);
        curl_multi_add_handle($mh, $c2);
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

    $resTwoNew = $runTwoConcurrent(
        ['counted' => $espConc, 'version' => $verConc, 'referencia_solicitud' => 'p10_conc_new_A_0001'],
        ['counted' => $espConc, 'version' => $verConc, 'referencia_solicitud' => 'p10_conc_new_B_0002']
    );
    assertSameVal([201, 201], [$resTwoNew[0]['status'], $resTwoNew[1]['status']], 'Concurrencia', 'Dos arqueos nuevos misma versión -> ambos 201 secuenciales');
    assertSameVal(1, (int)$pdo->query("SELECT COUNT(*) FROM arqueo_caja WHERE id_sesion_caja = {$idSesOp} AND estado = 'VIGENTE'")->fetchColumn(), 'Concurrencia', 'Exactamente 1 arqueo VIGENTE tras dos arqueos simultáneos');

    // 6B: Dos reintentos simultáneos con la misma referencia -> 1 solo arqueo efectivo
    $cntPreDup = (int)$pdo->query("SELECT COUNT(*) FROM arqueo_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $resTwoSame = $runTwoConcurrent(
        ['counted' => $espConc, 'version' => $verConc, 'referencia_solicitud' => 'p10_conc_same_00001'],
        ['counted' => $espConc, 'version' => $verConc, 'referencia_solicitud' => 'p10_conc_same_00001']
    );
    assertSameVal($cntPreDup + 1, (int)$pdo->query("SELECT COUNT(*) FROM arqueo_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn(), 'Concurrencia', 'Reintentos concurrentes misma referencia -> exactamente 1 arqueo insertado');

    // 6C: Arqueo vs Venta simultáneos (dos sesiones PHP independientes)
    $verConcRace = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $espConcRace = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $mhRace = curl_multi_init();
    $chArqRace = curl_init($url1 . '/api/caja/arqueo');
    curl_setopt_array($chArqRace, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "X-CSRF-TOKEN: {$authV1A['csrf']}"],
        CURLOPT_COOKIEFILE     => $cookieV1A,
        CURLOPT_COOKIEJAR      => $cookieV1A,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => json_encode([
            'counted'              => $espConcRace,
            'version'              => $verConcRace,
            'referencia_solicitud' => 'p10_conc_race_arq_1',
        ]),
    ]);
    $chVtaRace = curl_init($url2 . '/api/ventas');
    curl_setopt_array($chVtaRace, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "X-CSRF-TOKEN: {$authV1B['csrf']}"],
        CURLOPT_COOKIEFILE     => $cookieV1B,
        CURLOPT_COOKIEJAR      => $cookieV1B,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => json_encode([
            'id_cliente'           => $idCli,
            'id_tipo_comprobante'  => $idBol,
            'id_metodo_pago'       => 1,
            'productos'            => [['id_producto' => $idProd, 'cantidad' => 1]],
            'referencia_solicitud' => 'p10_conc_race_vta_1',
        ]),
    ]);
    curl_multi_add_handle($mhRace, $chArqRace);
    curl_multi_add_handle($mhRace, $chVtaRace);
    $activeRace = null;
    do {
        $mrcRace = curl_multi_exec($mhRace, $activeRace);
        if ($activeRace) {
            curl_multi_select($mhRace, 0.05);
        }
    } while ($activeRace && $mrcRace == CURLM_OK);
    $stArqRace = (int)curl_getinfo($chArqRace, CURLINFO_HTTP_CODE);
    $stVtaRace = (int)curl_getinfo($chVtaRace, CURLINFO_HTTP_CODE);
    $jsArqRace = json_decode((string)curl_multi_getcontent($chArqRace), true);
    curl_multi_remove_handle($mhRace, $chArqRace);
    curl_multi_remove_handle($mhRace, $chVtaRace);
    curl_close($chArqRace);
    curl_close($chVtaRace);
    curl_multi_close($mhRace);

    assertSameVal(201, $stVtaRace, 'Concurrencia', 'Arqueo vs Venta: venta concurrente -> 201');
    assertTrue($stArqRace === 201 || ($stArqRace === 409 && ($jsArqRace['code'] ?? '') === 'CASH_CHANGED'), 'Concurrencia', 'Arqueo vs Venta: arqueo -> 201 (ganó) o 409 CASH_CHANGED (perdió)');
    assertSameVal($verConcRace + 1, (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn(), 'Concurrencia', 'Arqueo vs Venta: version_caja final = +1');
    assertSameVal(0, (int)$pdo->query("SELECT COUNT(*) FROM arqueo_caja WHERE id_sesion_caja = {$idSesOp} AND estado = 'VIGENTE'")->fetchColumn(), 'Concurrencia', 'Arqueo vs Venta: 0 arqueos VIGENTE tras la venta concurrente');
    $ep17AfterRace = httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A);
    assertSameVal(false, $ep17AfterRace['json']['data']['has_valid_arqueo'] ?? null, 'Concurrencia', 'Arqueo vs Venta: has_valid_arqueo=false');
    // Actualizar verConc/espConc tras la venta concurrente para los tests finales
    $verConc = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $espConc = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();

    // =========================================================================
    // SECCIÓN 7: EVALUACIÓN HORARIA Y REPLAY POST-CIERRE
    // =========================================================================
    // Verificar EP-18 orden cronológico completo
    $ep18Hist = httpRequest($url1, 'GET', '/api/caja/arqueos', null, [], $cookieV1A);
    assertSameVal(200, $ep18Hist['status'], 'Historial', 'EP-18 devuelve 200');
    $histList = $ep18Hist['json']['data']['history'] ?? [];
    assertTrue(count($histList) >= 4, 'Historial', 'EP-18 devuelve historial completo');
    $prevId = 0;
    $ordered = true;
    foreach ($histList as $h) {
        if ((int)$h['id'] <= $prevId) {
            $ordered = false;
        }
        $prevId = (int)$h['id'];
    }
    assertTrue($ordered, 'Historial', 'EP-18 ordenado cronológicamente ASC');

    // Pruebas de límites horarios exactos via CajaService::setClockProvider
    $cajaSvc = new \Services\CajaService($pdo);
    $salidaRefStr = (string)$pdo->query("SELECT fecha_hora_salida_referencia FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $salidaRefDt  = new DateTimeImmutable($salidaRefStr, new DateTimeZone('America/Lima'));

    $cajaSvc->setClockProvider(fn () => $salidaRefDt->modify('-15 minutes -1 second'));
    $sch1144 = $cajaSvc->consultarEstadoArqueo((int)$vend1['id_usuario'], [])['data']['schedule'];
    assertSameVal(true, $sch1144['requires_authorization'], 'Horario', '11:44:59 para salida 12:00 -> requires_authorization=true');
    assertTrue($sch1144['reason'] !== null, 'Horario', '11:44:59 tiene reason no nulo');

    // Guardar arqueo con reloj controlado ANTES del margen (requires_authorization=true) sin credenciales admin
    $verPreEarlySvc = (int)$pdo->query("SELECT version_caja FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $espPreEarlySvc = (float)$pdo->query("SELECT efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = {$idSesOp}")->fetchColumn();
    $resSvcBeforeMargin = $cajaSvc->guardarArqueo([
        'counted'              => $espPreEarlySvc,
        'version'              => $verPreEarlySvc,
        'referencia_solicitud' => 'p10_svc_before_margin_1',
    ], null, $vend1);
    assertSameVal(201, $resSvcBeforeMargin['http_code'] ?? null, 'Horario', 'guardarArqueo antes del margen (11:44:59) sin credenciales admin -> 201');
    assertSameVal('ARQUEO_SAVED', $resSvcBeforeMargin['response']['code'] ?? null, 'Horario', 'guardarArqueo antes del margen code = ARQUEO_SAVED');
    $idArqSvcEarly = (int)($resSvcBeforeMargin['response']['data']['arqueo']['id'] ?? 0);
    assertSameVal('VIGENTE', $pdo->query("SELECT estado FROM arqueo_caja WHERE id_arqueo_caja = {$idArqSvcEarly}")->fetchColumn(), 'Horario', 'guardarArqueo antes del margen persiste VIGENTE en BD');

    $cajaSvc->setClockProvider(fn () => $salidaRefDt->modify('-15 minutes'));
    $sch1145 = $cajaSvc->consultarEstadoArqueo((int)$vend1['id_usuario'], [])['data']['schedule'];
    assertSameVal(false, $sch1145['requires_authorization'], 'Horario', '11:45:00 para salida 12:00 -> requires_authorization=false');
    assertSameVal(null, $sch1145['reason'], 'Horario', '11:45:00 reason=null');

    $resSvcAtMargin = $cajaSvc->guardarArqueo([
        'counted'              => $espPreEarlySvc,
        'version'              => $verPreEarlySvc,
        'referencia_solicitud' => 'p10_svc_at_margin_0001',
    ], null, $vend1);
    assertSameVal(201, $resSvcAtMargin['http_code'] ?? null, 'Horario', 'guardarArqueo en el instante del margen (11:45:00) -> 201');

    $cajaSvc->setClockProvider(fn () => $salidaRefDt->modify('+35 minutes'));
    $sch1235 = $cajaSvc->consultarEstadoArqueo((int)$vend1['id_usuario'], [])['data']['schedule'];
    assertSameVal(false, $sch1235['requires_authorization'], 'Horario', '12:35:00 después de salida -> requires_authorization=false');

    // Después de medianoche conserva salida original
    $cajaSvc->setClockProvider(fn () => $salidaRefDt->modify('+1 day')->setTime(0, 15, 0));
    $schMidnight = $cajaSvc->consultarEstadoArqueo((int)$vend1['id_usuario'], [])['data']['schedule'];
    assertSameVal(false, $schMidnight['requires_authorization'], 'Horario', 'Después de medianoche conserva salida original -> false');

    // Editar turno posteriormente no altera el snapshot de referencia de la sesión
    $pdo->prepare("UPDATE turno SET hora_fin = '18:00:00' WHERE id_turno = ?")->execute([$idTurno]);
    $ep17AfterTurnoEdit = httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A);
    assertSameVal('12:00', $ep17AfterTurnoEdit['json']['data']['reference_schedule']['end'] ?? null, 'Horario', 'Editar turno no altera reference_schedule de sesión abierta');

    // Guardar un arqueo vigente antes de cerrar sesión operativa y verificar replay de Arqueo 2 post-cierre
    $rArqPreClose = httpRequest($url1, 'POST', '/api/caja/arqueo', [
        'counted'              => $espConc,
        'version'              => $verConc,
        'referencia_solicitud' => 'p10_arq_pre_close_01',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $rArqPreClose['status'], 'Idempotencia', 'Arqueo vigente previo a cierre -> 201');
    cerrarSesionFixture($pdo, $idSesOp);
    $resReplayPostClose = httpRequest($url1, 'POST', '/api/caja/arqueo', [
        'counted'              => $sobrante,
        'observation'          => 'Sobrante S/ 5.00',
        'version'              => 7,
        'referencia_solicitud' => $refArq2,
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(200, $resReplayPostClose['status'], 'Idempotencia', 'Replay post-cierre de EP-19 -> 200');
    assertSameVal('OPERATION_ALREADY_CONFIRMED', $resReplayPostClose['json']['code'] ?? null, 'Idempotencia', 'Replay post-cierre code OPERATION_ALREADY_CONFIRMED');
    assertSameVal($resArq2['json']['data'] ?? null, $resReplayPostClose['json']['data'] ?? null, 'Idempotencia', 'Replay post-cierre conserva data original (no recalcula historial actual)');

    // Guardar arqueo permitido por HTTP aunque requires_authorization=true (sin credenciales admin)
    $hIniEarly = '00:00:00';
    $hFinEarly = '23:59:00';
    $pdo->prepare("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES ('Turno Anticipado P10', ?, ?, 1)")->execute([$hIniEarly, $hFinEarly]);
    $idTurnoEarly = (int)$pdo->lastInsertId();
    $openEarly = httpRequest($url1, 'POST', '/api/caja/abrir', [
        'shift_id'             => $idTurnoEarly,
        'cash_counted'         => $espConc,
        'referencia_solicitud' => 'p10_open_early_0001',
    ], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $openEarly['status'], 'Horario', 'Apertura sesión para prueba horaria HTTP -> 201');
    $idSesEarly = (int)($openEarly['json']['data']['session_id'] ?? 0);
    $tzLima = new DateTimeZone('America/Lima');
    $salidaEarlyRef = new DateTimeImmutable((string)$pdo->query("SELECT fecha_hora_salida_referencia FROM sesion_caja WHERE id_sesion_caja = {$idSesEarly}")->fetchColumn(), $tzLima);
    $umbralEarlyRef = $salidaEarlyRef->modify('-15 minutes');
    $nowAntesHttp = new DateTimeImmutable('now', $tzLima);
    if (abs($nowAntesHttp->getTimestamp() - $umbralEarlyRef->getTimestamp()) <= 2) {
        sleep(3);
    }
    $nowParaHttp = new DateTimeImmutable('now', $tzLima);
    $expectedReqAuthHttp = ($nowParaHttp < $umbralEarlyRef);
    $ep17Early = httpRequest($url1, 'GET', '/api/caja/arqueo/estado', null, [], $cookieV1A);
    assertSameVal(200, $ep17Early['status'], 'Horario', 'EP-17 HTTP responde 200 en sesión abierta');
    assertSameVal($expectedReqAuthHttp, $ep17Early['json']['data']['schedule']['requires_authorization'] ?? null, 'Horario', 'EP-17 HTTP coincide con cálculo horario esperado respecto al margen de 15m');
    $rArqEarly = httpRequest($url1, 'POST', '/api/caja/arqueo', ['counted' => $espConc, 'version' => 1, 'referencia_solicitud' => 'p10_arq_early_ok_001'], ['X-CSRF-TOKEN' => $authV1A['csrf']], $cookieV1A);
    assertSameVal(201, $rArqEarly['status'], 'Horario', 'EP-19 HTTP permitido sin credenciales admin en cualquier horario');

} finally {
    stopPhpServer($srv1);
    stopPhpServer($srv2);
    @array_map('unlink', glob($sessionDir . '/*') ?: []);
    @rmdir($sessionDir);
    @array_map('unlink', glob($tmpDir . '/*') ?: []);
    @rmdir($tmpDir);
}

echo "\n=== RESUMEN PASO 10 ===\n";
foreach ($categories as $cat => $c) {
    echo "  {$cat}: {$c['passed']}/" . ($c['passed'] + $c['failed']) . "\n";
}
echo "TOTAL: {$passed}/" . ($passed + $failed) . " aserciones correctas.\n";
exit($failed > 0 ? 1 : 0);
