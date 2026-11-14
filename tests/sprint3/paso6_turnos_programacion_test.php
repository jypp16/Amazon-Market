<?php
declare(strict_types=1);

/**
 * Suite integral de pruebas de integración HTTP, persistencia MariaDB, roles, idempotencia,
 * concurrencia y regresión HU 18 para el Paso 6 del Sprint 3 (EP-01 .. EP-06).
 *
 * Base de datos aislada de pruebas: amazon_market_sprint3_paso6_http_20261112_1900
 * Servidor HTTP aislado:            http://127.0.0.1:8093
 */

$testDbName = getenv('TEST_DB_NAME') ?: 'amazon_market_sprint3_paso6_http_20261112_2022';
$hu18DbName = getenv('HU18_DB_NAME') ?: 'amazon_market_sprint3_migration_test_paso6_hu18_20261112_2022';
$serverHost = '127.0.0.1';
$serverPort = (int)(getenv('TEST_HTTP_PORT') ?: 8093);
$baseUrl    = "http://{$serverHost}:{$serverPort}";
$projectRoot = dirname(__DIR__, 2);
$phpBin      = 'C:\xampp\php\php.exe';

$basesProtegidas = [
    'amazon_market',
    'amazon_market_sprint3_verify_20261112_012430',
    'amazon_market_sprint3_verify_20261112_012312',
    'amazon_market_restore_verify_20261027_113040',
];
if (in_array($testDbName, $basesProtegidas, true) || in_array($hu18DbName, $basesProtegidas, true)) {
    fwrite(STDERR, "[ABORT] Prohibido ejecutar pruebas sobre la base principal o copias reservadas.\n");
    exit(1);
}

// 1. Verificar que el archivo .env real permanezca intacto apuntando a amazon_market
$envRaw = (string)file_get_contents($projectRoot . '/.env');
if (!preg_match('/^DB_NAME\s*=\s*amazon_market\s*$/m', $envRaw)) {
    fwrite(STDERR, "[ABORT] El archivo .env no apunta a amazon_market.\n");
    exit(1);
}

// Configurar variables de entorno del proceso actual para apuntar exclusivamente a la copia de prueba
putenv("DB_NAME={$testDbName}");
putenv("BASE_URL={$baseUrl}");
$_ENV['DB_NAME']     = $testDbName;
$_SERVER['DB_NAME']  = $testDbName;
$_ENV['BASE_URL']    = $baseUrl;
$_SERVER['BASE_URL'] = $baseUrl;

require_once $projectRoot . '/Libraries/Core/Dotenv.php';
\Dotenv::load($projectRoot . '/.env');
require_once $projectRoot . '/Config/Config.php';
require_once $projectRoot . '/Libraries/Core/Autoload.php';

use Libraries\Core\Conexion;
use Libraries\Core\InventarioException;
use Services\ControlSolicitudService;

$pdo = Conexion::getInstance()->conect();
$currentDb = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
if ($currentDb !== $testDbName) {
    fwrite(STDERR, "[ABORT] Destino efectivo de BD inesperado: {$currentDb} (esperado: {$testDbName}).\n");
    exit(1);
}

// 2. Iniciar servidor HTTP aislado si no está activo aún
$serverProc = null;
$serverPipes = [];
$routerPath = $projectRoot . '/tests/sprint3/server_router_sprint3.php';

function httpRequest(
    string $method,
    string $url,
    ?string $cookieFile = null,
    $body = null,
    array $headers = [],
    string $contentType = 'application/json'
): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $reqHeaders = [];
    if ($body !== null) {
        if (is_array($body) && str_contains(strtolower($contentType), 'application/json')) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        } elseif (is_array($body) && str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            $payload = http_build_query($body);
        } else {
            $payload = (string)$body;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        if ($contentType !== '') {
            $reqHeaders[] = 'Content-Type: ' . $contentType;
        }
    } elseif ($contentType !== '' && $contentType !== 'NONE') {
        $reqHeaders[] = 'Content-Type: ' . $contentType;
    }

    foreach ($headers as $k => $v) {
        if (is_int($k)) {
            $reqHeaders[] = $v;
        } else {
            $reqHeaders[] = "{$k}: {$v}";
        }
    }

    if (!empty($reqHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
    }

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status_code' => 0, 'headers' => '', 'raw_body' => '', 'json' => null, 'curl_error' => $err];
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($rawResponse, 0, $headerSize);
    $rawBody    = substr($rawResponse, $headerSize);
    $json       = json_decode($rawBody, true);

    return [
        'status_code' => $statusCode,
        'headers'     => $rawHeaders,
        'raw_body'    => $rawBody,
        'json'        => $json,
    ];
}

$probe = httpRequest('GET', "{$baseUrl}/__test_env_verify", null, null, [], 'NONE');
if ($probe['status_code'] !== 200) {
    $cmd = [$phpBin, '-S', $serverHost . ':' . $serverPort, '-t', $projectRoot, $routerPath];
    $envVars = array_merge($_ENV, [
        'DB_NAME'    => $testDbName,
        'BASE_URL'   => $baseUrl,
        'PHPRC' => getenv('PHPRC') ?: '',
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\Windows',
        'PATH'       => getenv('PATH') ?: '',
        'TEMP'       => getenv('TEMP') ?: sys_get_temp_dir(),
        'TMP'        => getenv('TMP') ?: sys_get_temp_dir(),
    ]);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['file', 'NUL', 'w'],
    ];
    $serverProc = proc_open($cmd, $descriptors, $serverPipes, $projectRoot, $envVars);
    if (!is_resource($serverProc)) {
        fwrite(STDERR, "[ABORT] No se pudo iniciar el servidor HTTP aislado en {$baseUrl}.\n");
        exit(1);
    }
    for ($i = 0; $i < 30; $i++) {
        usleep(150000);
        $probe = httpRequest('GET', "{$baseUrl}/__test_env_verify", null, null, [], 'NONE');
        if ($probe['status_code'] === 200) {
            break;
        }
    }
}

register_shutdown_function(static function () use (&$serverProc, &$serverPipes): void {
    foreach ($serverPipes as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }
    if (is_resource($serverProc)) {
        @proc_terminate($serverProc);
        @proc_close($serverProc);
    }
});

if (
    $probe['status_code'] !== 200
    || !is_array($probe['json'])
    || ($probe['json']['effective_db'] ?? '') !== $testDbName
) {
    fwrite(STDERR, "[ABORT] Verificación de entorno HTTP aislado falló: " . $probe['raw_body'] . "\n");
    exit(1);
}

echo "================================================================================\n";
echo " SUITE INTEGRAL PASO 6 SPRINT 3 - TURNOS Y PROGRAMACION SEMANAL (EP-01..EP-06)\n";
echo "================================================================================\n";
echo "  Base de datos HTTP aislada : {$probe['json']['effective_db']}\n";
echo "  URL / Puerto aislado       : {$baseUrl} (puerto {$probe['json']['server_port']})\n";
echo "  Zona horaria PHP / DB      : {$probe['json']['php_timezone']} / {$probe['json']['db_timezone']}\n";
echo "  Connection ID inicial      : {$probe['json']['conn_id']}\n";
echo "================================================================================\n\n";

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = [];
$categoryStats = [
    'PRE' => ['passed' => 0, 'failed' => 0],
    'A'   => ['passed' => 0, 'failed' => 0],
    'B'   => ['passed' => 0, 'failed' => 0],
    'C'   => ['passed' => 0, 'failed' => 0],
    'D'   => ['passed' => 0, 'failed' => 0],
    'E'   => ['passed' => 0, 'failed' => 0],
    'F'   => ['passed' => 0, 'failed' => 0],
];
$currentCategory = 'PRE';

function assertCond(bool $condition, string $label, string $detail = ''): void
{
    global $totalAssertions, $passedAssertions, $failedAssertions, $categoryStats, $currentCategory;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        $categoryStats[$currentCategory]['passed']++;
        echo "  [PASS] [{$currentCategory}] {$label}\n";
    } else {
        $categoryStats[$currentCategory]['failed']++;
        $msg = "[FAIL] [{$currentCategory}] {$label}" . ($detail !== '' ? " -> {$detail}" : '');
        $failedAssertions[] = $msg;
        echo "  {$msg}\n";
    }
}

assertCond(
    $probe['json']['effective_db'] === $testDbName && $probe['json']['effective_db'] !== 'amazon_market',
    'PRE-01: Entorno HTTP aislado apunta exclusivamente a ' . $testDbName
);
assertCond(
    $probe['json']['php_timezone'] === 'America/Lima' && $probe['json']['db_timezone'] === '-05:00',
    'PRE-02: Timezone centralizado en America/Lima y SET SESSION time_zone = -05:00'
);

// Comparación relativa: la principal puede estar en Sprint 2 o ya activada en Sprint 3.
require_once $projectRoot . '/database/tercerSprint/tools/DespliegueSprint3.php';
$principalBaseline = \DespliegueSprint3::snapshot('amazon_market');
if (!in_array(count($principalBaseline['tables']), [32, 43], true)) throw new RuntimeException('Esquema principal parcial o inesperado.');

// 3. Verificar que la copia aislada sea una base limpia recién migrada (sin datos de prueba previos ni DELETE FROM)
$cntExistentes = (int)$pdo->query("SELECT COUNT(*) FROM turno")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM programacion_semanal")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM sesion_caja")->fetchColumn();
if ($cntExistentes > 0) {
    fwrite(STDERR, "[ABORT] La base {$testDbName} ya contiene datos en turno/programacion_semanal/sesion_caja. Use una copia nueva migrada.\n");
    exit(1);
}
$cntFixturesPrevios = (int)$pdo->query("SELECT COUNT(*) FROM usuario WHERE username IN ('admp6a', 'admp6i', 'vndp6a', 'vndp6b', 'vndp6i', 'vndp6e2')")->fetchColumn();
if ($cntFixturesPrevios > 0) {
    fwrite(STDERR, "[ABORT] La base {$testDbName} ya contiene usuarios fixture previos. Use una copia nueva migrada.\n");
    exit(1);
}

// Crear usuarios fixture exclusivos en la copia de pruebas (sin alterar credenciales de usuarios reales 1,4,6,7,8,9)
$testPasswordPlain = 'Paso6Test#2026!';
$testPasswordHash  = password_hash($testPasswordPlain, PASSWORD_DEFAULT);

$stmtInsertUser = $pdo->prepare("
    INSERT INTO usuario (id_rol, username, password_hash, nombre, dni, telefono, direccion, email, estado)
    VALUES (:rol, :username, :password_hash, :nombre, :dni, '999000111', 'Av. Pruebas Paso 6', :email, :estado)
");

$fixtures = [
    'admin_activo'      => ['username' => 'admp6a', 'dni' => '99060001', 'nombre' => 'AdminPaso6 Activo',    'rol' => 1, 'estado' => 1, 'email' => 'admin.paso6@test.local'],
    'admin_inactivo'    => ['username' => 'admp6i', 'dni' => '99060002', 'nombre' => 'AdminPaso6 Inactivo',  'rol' => 1, 'estado' => 1, 'email' => 'admin.inactivo@test.local'],
    'vendedor_a'        => ['username' => 'vndp6a', 'dni' => '99060003', 'nombre' => 'VendedorPaso6 Uno',    'rol' => 2, 'estado' => 1, 'email' => 'vendedor1.paso6@test.local'],
    'vendedor_b'        => ['username' => 'vndp6b', 'dni' => '99060004', 'nombre' => 'VendedorPaso6 Dos',    'rol' => 2, 'estado' => 1, 'email' => 'vendedor2.paso6@test.local'],
    'vendedor_inactivo' => ['username' => 'vndp6i', 'dni' => '99060005', 'nombre' => 'VendedorPaso6 Inact',  'rol' => 2, 'estado' => 0, 'email' => 'vendedor.inactivo@test.local'],
    'vendedor_e2'       => ['username' => 'vndp6e2','dni' => '99060006', 'nombre' => 'VendedorPaso6 HU18',   'rol' => 2, 'estado' => 1, 'email' => 'vendedor.e2.paso6@test.local'],
];

$fixtureIds = [];
foreach ($fixtures as $key => $u) {
    $stmtInsertUser->execute([
        ':rol'           => $u['rol'],
        ':username'      => $u['username'],
        ':password_hash' => $testPasswordHash,
        ':nombre'        => $u['nombre'],
        ':dni'           => $u['dni'],
        ':email'         => $u['email'],
        ':estado'        => $u['estado'],
    ]);
    $fixtureIds[$key] = (int)$pdo->lastInsertId();
}

$idAdminActivo      = $fixtureIds['admin_activo'];
$idAdminInactivo    = $fixtureIds['admin_inactivo'];
$idVendedorA        = $fixtureIds['vendedor_a'];
$idVendedorB        = $fixtureIds['vendedor_b'];
$idVendedorInactivo = $fixtureIds['vendedor_inactivo'];
$idVendedorE2       = $fixtureIds['vendedor_e2'];

// Auxiliar para iniciar sesión HTTP real y obtener cookie + token CSRF desde el servidor aislado
function loginHttpAndGetCsrf(string $baseUrl, string $username, string $password, string $tag): array
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'am_p6_' . $tag . '_');

    // 1. Obtener formulario GET /Auth para inicializar sesión y extraer csrf_token
    $authPage = httpRequest('GET', "{$baseUrl}/Auth", $cookieFile, null, [], 'NONE');
    $csrfToken = '';
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i', $authPage['raw_body'], $m)) {
        $csrfToken = $m[1];
    }

    // 2. Autenticar vía POST /Auth/login
    $loginRes = httpRequest(
        'POST',
        "{$baseUrl}/Auth/login",
        $cookieFile,
        [
            'username'   => $username,
            'password'   => $password,
            'csrf_token' => $csrfToken,
        ],
        [],
        'application/x-www-form-urlencoded'
    );

    $loggedIn = ($loginRes['status_code'] === 302 && str_contains($loginRes['headers'], '/Home'));

    return [
        'cookie_file' => $cookieFile,
        'logged_in'   => $loggedIn,
        'csrf_token'  => $csrfToken,
    ];
}

$sessAdmin      = loginHttpAndGetCsrf($baseUrl, 'admp6a', $testPasswordPlain, 'adm');
$sessAdminInact = loginHttpAndGetCsrf($baseUrl, 'admp6i', $testPasswordPlain, 'adminact');
$sessVendA      = loginHttpAndGetCsrf($baseUrl, 'vndp6a', $testPasswordPlain, 'vnda');

// Desactivar en BD a admin_inactivo DESPUÉS de que ya tiene sesión iniciada (para probar sesión con usuario desactivado en BD)
$pdo->prepare("UPDATE usuario SET estado = 0 WHERE id_usuario = :id")->execute([':id' => $idAdminInactivo]);

assertCond(
    $sessAdmin['logged_in'] === true && strlen($sessAdmin['csrf_token']) === 64,
    'PRE-03: Login HTTP real y obtención de token CSRF para Administrador activo (id=' . $idAdminActivo . ')'
);
assertCond(
    $sessVendA['logged_in'] === true && strlen($sessVendA['csrf_token']) === 64,
    'PRE-04: Login HTTP real y obtención de token CSRF para Vendedor activo (id=' . $idVendedorA . ')'
);

// ================================================================================
// CATEGORÍA A: AUTENTICACIÓN, ROLES, PERMISOS Y TRANSPORTE (EP-01 .. EP-06)
// ================================================================================
$currentCategory = 'A';
echo "\n--- CATEGORÍA A: Autenticación, roles, permisos y transporte ---\n";

$endpointsAuthCheck = [
    ['GET',    '/api/turnos',                    null],
    ['POST',   '/api/turnos',                    ['referencia_solicitud' => 'am_test_noauth_00001', 'nombre' => 'T1', 'inicio' => '08:00', 'fin' => '12:00']],
    ['PUT',    '/api/turnos/1',                  ['referencia_solicitud' => 'am_test_noauth_00002', 'nombre' => 'T1', 'inicio' => '08:00', 'fin' => '12:00']],
    ['DELETE', '/api/turnos/1',                  null],
    ['GET',    '/api/programaciones?usuario_id=' . $idVendedorA . '&semana_inicio=2026-11-09', null],
    ['PUT',    '/api/programaciones',            ['referencia_solicitud' => 'am_test_noauth_00003', 'usuario_id' => $idVendedorA, 'semana_inicio' => '2026-11-09', 'alcance' => 'desde', 'turnos' => [['turno_id' => 1, 'dias' => [0]]]]],
];

foreach ($endpointsAuthCheck as $idx => [$verb, $path, $payload]) {
    // A.1 Sin sesión -> 401 AUTH_REQUIRED
    $resNoAuth = httpRequest($verb, $baseUrl . $path, null, $payload);
    assertCond(
        $resNoAuth['status_code'] === 401 && ($resNoAuth['json']['code'] ?? '') === 'AUTH_REQUIRED',
        "A-01." . ($idx + 1) . ": Sin sesión {$verb} {$path} devuelve 401 AUTH_REQUIRED",
        "HTTP={$resNoAuth['status_code']} body={$resNoAuth['raw_body']}"
    );

    // A.2 Sesión con usuario inactivo en BD -> 401 AUTH_REQUIRED
    $resInact = httpRequest(
        $verb,
        $baseUrl . $path,
        $sessAdminInact['cookie_file'],
        $payload,
        ['X-CSRF-Token' => $sessAdminInact['csrf_token'], 'X-Request-Reference' => 'am_test_inact_ref_000' . $idx]
    );
    assertCond(
        $resInact['status_code'] === 401 && ($resInact['json']['code'] ?? '') === 'AUTH_REQUIRED',
        "A-02." . ($idx + 1) . ": Usuario inactivo en BD {$verb} {$path} devuelve 401 AUTH_REQUIRED",
        "HTTP={$resInact['status_code']} body={$resInact['raw_body']}"
    );

    // A.3 Vendedor activo -> 403 FORBIDDEN_ADMIN_ONLY
    $resVend = httpRequest(
        $verb,
        $baseUrl . $path,
        $sessVendA['cookie_file'],
        $payload,
        ['X-CSRF-Token' => $sessVendA['csrf_token'], 'X-Request-Reference' => 'am_test_vend_ref_000' . $idx]
    );
    assertCond(
        $resVend['status_code'] === 403 && ($resVend['json']['code'] ?? '') === 'FORBIDDEN_ADMIN_ONLY',
        "A-03." . ($idx + 1) . ": Vendedor activo en {$verb} {$path} devuelve 403 FORBIDDEN_ADMIN_ONLY",
        "HTTP={$resVend['status_code']} body={$resVend['raw_body']}"
    );
}

// A.4 Mutaciones sin CSRF o con CSRF inválido -> 403 CSRF_INVALID
$mutacionesCsrf = [
    ['POST',   '/api/turnos',         ['referencia_solicitud' => 'am_test_csrf_000001', 'nombre' => 'T1', 'inicio' => '08:00', 'fin' => '12:00']],
    ['PUT',    '/api/turnos/1',       ['referencia_solicitud' => 'am_test_csrf_000002', 'nombre' => 'T1', 'inicio' => '08:00', 'fin' => '12:00']],
    ['DELETE', '/api/turnos/1',       null],
    ['PUT',    '/api/programaciones', ['referencia_solicitud' => 'am_test_csrf_000003', 'usuario_id' => $idVendedorA, 'semana_inicio' => '2026-11-09', 'alcance' => 'desde', 'turnos' => [['turno_id' => 1, 'dias' => [0]]]]],
];

foreach ($mutacionesCsrf as $idx => [$verb, $path, $payload]) {
    $resNoCsrf = httpRequest($verb, $baseUrl . $path, $sessAdmin['cookie_file'], $payload, ['X-Request-Reference' => 'am_test_nocsrf_0000' . $idx]);
    assertCond(
        $resNoCsrf['status_code'] === 403 && ($resNoCsrf['json']['code'] ?? '') === 'CSRF_INVALID',
        "A-04." . ($idx + 1) . ": Mutación {$verb} {$path} sin X-CSRF-Token devuelve 403 CSRF_INVALID",
        "HTTP={$resNoCsrf['status_code']} body={$resNoCsrf['raw_body']}"
    );

    $resBadCsrf = httpRequest(
        $verb,
        $baseUrl . $path,
        $sessAdmin['cookie_file'],
        $payload,
        ['X-CSRF-Token' => 'token_invalido_12345', 'X-Request-Reference' => 'am_test_badcsrf_000' . $idx]
    );
    assertCond(
        $resBadCsrf['status_code'] === 403 && ($resBadCsrf['json']['code'] ?? '') === 'CSRF_INVALID',
        "A-05." . ($idx + 1) . ": Mutación {$verb} {$path} con X-CSRF-Token inválido devuelve 403 CSRF_INVALID",
        "HTTP={$resBadCsrf['status_code']} body={$resBadCsrf['raw_body']}"
    );
}

// A.6 Verificación de permiso exclusivo Admin turnos.programar: incluso si se asignara a id_rol=2 en rol_permiso, Vendedor sigue recibiendo 403 FORBIDDEN_ADMIN_ONLY
$idPermisoProgramar = (int)$pdo->query("SELECT id_permiso FROM permiso WHERE slug = 'turnos.programar'")->fetchColumn();
$pdo->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (2, :idp)")->execute([':idp' => $idPermisoProgramar]);
try {
    $resVendedorConPermiso = httpRequest('GET', "{$baseUrl}/api/turnos", $sessVendA['cookie_file']);
    assertCond(
        $resVendedorConPermiso['status_code'] === 403 && ($resVendedorConPermiso['json']['code'] ?? '') === 'FORBIDDEN_ADMIN_ONLY',
        'A-06: Vendedor recibe 403 FORBIDDEN_ADMIN_ONLY en /api/turnos incluso si turnos.programar existiera en rol_permiso para id_rol=2',
        "HTTP={$resVendedorConPermiso['status_code']} body={$resVendedorConPermiso['raw_body']}"
    );
} finally {
    $pdo->prepare("DELETE FROM rol_permiso WHERE id_rol = 2 AND id_permiso = :idp")->execute([':idp' => $idPermisoProgramar]);
}

// A.7 JSON malformado -> 400 INVALID_JSON
$resBadJson = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    '{"nombre": "Mañana", "inicio": "08:00"',
    ['X-CSRF-Token' => $sessAdmin['csrf_token']],
    'application/json'
);
assertCond(
    $resBadJson['status_code'] === 400 && ($resBadJson['json']['code'] ?? '') === 'INVALID_JSON',
    'A-07: JSON malformado en POST /api/turnos devuelve 400 INVALID_JSON',
    "HTTP={$resBadJson['status_code']} body={$resBadJson['raw_body']}"
);

// A.8 Content-Type no soportado (text/plain y multipart/form-data) en nuevas escrituras -> 415 UNSUPPORTED_MEDIA_TYPE
$resTextPlain = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    'nombre=Manana',
    ['X-CSRF-Token' => $sessAdmin['csrf_token']],
    'text/plain'
);
assertCond(
    $resTextPlain['status_code'] === 415 && ($resTextPlain['json']['code'] ?? '') === 'UNSUPPORTED_MEDIA_TYPE',
    'A-08: Content-Type text/plain en POST /api/turnos devuelve 415 UNSUPPORTED_MEDIA_TYPE',
    "HTTP={$resTextPlain['status_code']} body={$resTextPlain['raw_body']}"
);

$resMultipart = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    '---boundary',
    ['X-CSRF-Token' => $sessAdmin['csrf_token']],
    'multipart/form-data; boundary=---boundary'
);
assertCond(
    $resMultipart['status_code'] === 415 && ($resMultipart['json']['code'] ?? '') === 'UNSUPPORTED_MEDIA_TYPE',
    'A-09: Content-Type multipart/form-data en PUT /api/programaciones devuelve 415 UNSUPPORTED_MEDIA_TYPE',
    "HTTP={$resMultipart['status_code']} body={$resMultipart['raw_body']}"
);

// A.9 Campos desconocidos en escrituras y lecturas -> 422 VALIDATION_ERROR
$resUnknownFieldTurno = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_test_unk_field_001',
        'nombre'               => 'Turno X',
        'inicio'               => '08:00',
        'fin'                  => '12:00',
        'campo_inventado'      => 'no_permitido',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resUnknownFieldTurno['status_code'] === 422
    && ($resUnknownFieldTurno['json']['code'] ?? '') === 'VALIDATION_ERROR'
    && isset($resUnknownFieldTurno['json']['errors']['campo_inventado']),
    'A-10: Campo desconocido en POST /api/turnos devuelve 422 VALIDATION_ERROR',
    "HTTP={$resUnknownFieldTurno['status_code']} body={$resUnknownFieldTurno['raw_body']}"
);

$resUnknownFieldProg = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_test_unk_field_002',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => '2026-11-09',
        'alcance'              => 'desde',
        'turnos'               => [['turno_id' => 1, 'dias' => [0], 'extra_nested' => 1]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resUnknownFieldProg['status_code'] === 422
    && ($resUnknownFieldProg['json']['code'] ?? '') === 'VALIDATION_ERROR',
    'A-11: Campo desconocido anidado en PUT /api/programaciones devuelve 422 VALIDATION_ERROR',
    "HTTP={$resUnknownFieldProg['status_code']} body={$resUnknownFieldProg['raw_body']}"
);

// A.10 Verbos HTTP no admitidos -> 405 METHOD_NOT_ALLOWED y rutas no existentes -> 404 ENDPOINT_NOT_FOUND
$res405Turnos = httpRequest(
    'PATCH',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [' a' => 1],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $res405Turnos['status_code'] === 405 && ($res405Turnos['json']['code'] ?? '') === 'METHOD_NOT_ALLOWED',
    'A-12: PATCH /api/turnos devuelve 405 METHOD_NOT_ALLOWED con cabecera Allow',
    "HTTP={$res405Turnos['status_code']} body={$res405Turnos['raw_body']}"
);

$res405Prog = httpRequest(
    'POST',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    ['a' => 1],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $res405Prog['status_code'] === 405 && ($res405Prog['json']['code'] ?? '') === 'METHOD_NOT_ALLOWED',
    'A-13: POST /api/programaciones devuelve 405 METHOD_NOT_ALLOWED',
    "HTTP={$res405Prog['status_code']} body={$res405Prog['raw_body']}"
);

$res404Api = httpRequest('GET', "{$baseUrl}/api/endpoint-inexistente", $sessAdmin['cookie_file']);
assertCond(
    $res404Api['status_code'] === 404 && ($res404Api['json']['code'] ?? '') === 'ENDPOINT_NOT_FOUND',
    'A-14: GET /api/endpoint-inexistente devuelve 404 ENDPOINT_NOT_FOUND',
    "HTTP={$res404Api['status_code']} body={$res404Api['raw_body']}"
);

// A.11 Alias contradictorios -> 422 AMBIGUOUS_ALIAS_VALUES
$resAliasRef = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_ref_canonica_00001',
        'request_id'           => 'am_ref_distinta_00002',
        'nombre'               => 'Turno Alias',
        'inicio'               => '08:00',
        'fin'                  => '12:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resAliasRef['status_code'] === 422 && ($resAliasRef['json']['code'] ?? '') === 'AMBIGUOUS_ALIAS_VALUES',
    'A-15: referencia_solicitud y request_id distintos en POST /api/turnos devuelve 422 AMBIGUOUS_ALIAS_VALUES',
    "HTTP={$resAliasRef['status_code']} body={$resAliasRef['raw_body']}"
);

$resAliasHeaderRef = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_ref_canonica_00003',
        'nombre'               => 'Turno Alias Header',
        'inicio'               => '08:00',
        'fin'                  => '12:00',
    ],
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_ref_header_diff_004',
    ]
);
assertCond(
    $resAliasHeaderRef['status_code'] === 422 && ($resAliasHeaderRef['json']['code'] ?? '') === 'AMBIGUOUS_ALIAS_VALUES',
    'A-16: referencia_solicitud y X-Request-Reference distintos devuelve 422 AMBIGUOUS_ALIAS_VALUES',
    "HTTP={$resAliasHeaderRef['status_code']} body={$resAliasHeaderRef['raw_body']}"
);

$resAliasGetProg = httpRequest(
    'GET',
    "{$baseUrl}/api/programaciones?usuario_id={$idVendedorA}&usuarioId={$idVendedorB}&semana_inicio=2026-11-09",
    $sessAdmin['cookie_file']
);
assertCond(
    $resAliasGetProg['status_code'] === 422 && ($resAliasGetProg['json']['code'] ?? '') === 'AMBIGUOUS_ALIAS_VALUES',
    'A-17: usuario_id y usuarioId contradictorios en GET /api/programaciones devuelve 422 AMBIGUOUS_ALIAS_VALUES',
    "HTTP={$resAliasGetProg['status_code']} body={$resAliasGetProg['raw_body']}"
);

$resAliasPutProg = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_ref_alias_put_0001',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => '2026-11-09',
        'semanaInicio'         => '2026-11-16',
        'alcance'              => 'desde',
        'turnos'               => [['turno_id' => 1, 'dias' => [0]]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resAliasPutProg['status_code'] === 422 && ($resAliasPutProg['json']['code'] ?? '') === 'AMBIGUOUS_ALIAS_VALUES',
    'A-18: semana_inicio y semanaInicio contradictorios en PUT /api/programaciones devuelve 422 AMBIGUOUS_ALIAS_VALUES',
    "HTTP={$resAliasPutProg['status_code']} body={$resAliasPutProg['raw_body']}"
);

// ================================================================================
// CATEGORÍA B: CATÁLOGO DE TURNOS (EP-01, EP-02, EP-03, EP-04)
// ================================================================================
$currentCategory = 'B';
echo "\n--- CATEGORÍA B: Catálogo de turnos (EP-01..EP-04) ---\n";

// B.1 EP-01 inicialmente vacío
$resListEmpty = httpRequest('GET', "{$baseUrl}/api/turnos", $sessAdmin['cookie_file']);
assertCond(
    $resListEmpty['status_code'] === 200
    && ($resListEmpty['json']['status'] ?? false) === true
    && ($resListEmpty['json']['code'] ?? '') === 'OK'
    && is_array($resListEmpty['json']['data'] ?? null)
    && count($resListEmpty['json']['data']) === 0,
    'B-01: EP-01 GET /api/turnos devuelve 200 OK con arreglo vacío inicial',
    "HTTP={$resListEmpty['status_code']} body={$resListEmpty['raw_body']}"
);

// B.2 EP-02 Crear Turno Mañana (07:00 - 15:00) y Turno Tarde (15:00 - 22:00) y Turno Auxiliar (18:00 - 23:00)
$resCrearManana = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_crear_manana_0001',
        'nombre'               => '  Mañana  ',
        'inicio'               => '07:00',
        'fin'                  => '15:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$idTurnoManana = (int)($resCrearManana['json']['data']['id'] ?? 0);
assertCond(
    $resCrearManana['status_code'] === 201
    && ($resCrearManana['json']['code'] ?? '') === 'SHIFT_CREATED'
    && ($resCrearManana['json']['idempotent_replay'] ?? null) === false
    && $idTurnoManana > 0
    && ($resCrearManana['json']['data']['nombre'] ?? '') === 'Mañana'
    && ($resCrearManana['json']['data']['inicio'] ?? '') === '07:00'
    && ($resCrearManana['json']['data']['fin'] ?? '') === '15:00'
    && ($resCrearManana['json']['data']['estado'] ?? null) === 1
    && ($resCrearManana['json']['data']['vendedores_asignados'] ?? null) === 0,
    'B-02: EP-02 POST /api/turnos crea turno Mañana (201 SHIFT_CREATED) aplicando trim() a nombre',
    "HTTP={$resCrearManana['status_code']} body={$resCrearManana['raw_body']}"
);

// Verificar autoría real en BD (id_usuario_creacion === $idAdminActivo)
$autorTurnoBd = (int)$pdo->query("SELECT id_usuario_creacion FROM turno WHERE id_turno = {$idTurnoManana}")->fetchColumn();
assertCond(
    $autorTurnoBd === $idAdminActivo,
    'B-03: EP-02 persiste en BD id_usuario_creacion obtenido de la sesión autenticada del servidor'
);

// Crear Turno Tarde usando alias request_id y cabecera X-Request-Reference coincidente
$resCrearTarde = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'request_id' => 'am_turno_crear_tarde_00002',
        'nombre'     => 'Tarde',
        'inicio'     => '15:00',
        'fin'        => '22:00',
    ],
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_turno_crear_tarde_00002',
    ]
);
$idTurnoTarde = (int)($resCrearTarde['json']['data']['id'] ?? 0);
assertCond(
    $resCrearTarde['status_code'] === 201 && $idTurnoTarde > 0,
    'B-04: EP-02 POST /api/turnos crea turno Tarde con request_id y X-Request-Reference coincidentes',
    "HTTP={$resCrearTarde['status_code']} body={$resCrearTarde['raw_body']}"
);

// Crear Turno Temporal (06:00 - 10:00) para verificar orden por hora_inicio ASC en EP-01
$resCrearTemp = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_crear_temp_000003',
        'nombre'               => 'Madrugada',
        'inicio'               => '06:00',
        'fin'                  => '10:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$idTurnoTemp = (int)($resCrearTemp['json']['data']['id'] ?? 0);
assertCond(
    $resCrearTemp['status_code'] === 201 && $idTurnoTemp > 0,
    'B-05: EP-02 POST /api/turnos crea turno Madrugada (06:00-10:00)',
    "HTTP={$resCrearTemp['status_code']} body={$resCrearTemp['raw_body']}"
);

// B.3 EP-01 Listar turnos activos y comprobar orden (06:00, 07:00, 15:00) y estructura
$resList3 = httpRequest('GET', "{$baseUrl}/api/turnos", $sessAdmin['cookie_file']);
$listaTurnos = $resList3['json']['data'] ?? [];
assertCond(
    $resList3['status_code'] === 200
    && count($listaTurnos) === 3
    && (int)$listaTurnos[0]['id'] === $idTurnoTemp
    && (int)$listaTurnos[1]['id'] === $idTurnoManana
    && (int)$listaTurnos[2]['id'] === $idTurnoTarde
    && array_keys($listaTurnos[0]) === ['id', 'nombre', 'inicio', 'fin', 'estado', 'vendedores_asignados'],
    'B-06: EP-01 lista los 3 turnos activos ordenados por hora_inicio ASC con las claves exactas del contrato',
    "HTTP={$resList3['status_code']} body={$resList3['raw_body']}"
);

// B.4 Validaciones de rechazo en EP-02 (nombre duplicado activo, horas inválidas, fin <= inicio, cruce de medianoche, longitud nombre)
$resDupNombre = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_dup_nombre_00001',
        'nombre'               => '  mAñAnA ',
        'inicio'               => '08:00',
        'fin'                  => '14:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resDupNombre['status_code'] === 409 && ($resDupNombre['json']['code'] ?? '') === 'SHIFT_NAME_ALREADY_EXISTS',
    'B-07: EP-02 rechaza nombre activo duplicado insensible a mayúsculas/espacios con 409 SHIFT_NAME_ALREADY_EXISTS',
    "HTTP={$resDupNombre['status_code']} body={$resDupNombre['raw_body']}"
);

$casosHorasInvalidas = [
    ['08:00', '08:00', 'horas iguales'],
    ['15:00', '14:00', 'fin menor que inicio'],
    ['22:00', '06:00', 'cruce de medianoche'],
    ['25:00', '26:00', 'hora fuera de 00..23'],
    ['08:60', '12:00', 'minutos fuera de 00..59'],
    ['8:00',  '12:00', 'sin cero a la izquierda'],
];
foreach ($casosHorasInvalidas as $idx => [$hIni, $hFin, $desc]) {
    $resHoraInv = httpRequest(
        'POST',
        "{$baseUrl}/api/turnos",
        $sessAdmin['cookie_file'],
        [
            'referencia_solicitud' => sprintf('am_turno_bad_hr_%06d', $idx + 1),
            'nombre'               => "Turno Inv {$idx}",
            'inicio'               => $hIni,
            'fin'                  => $hFin,
        ],
        ['X-CSRF-Token' => $sessAdmin['csrf_token']]
    );
    assertCond(
        $resHoraInv['status_code'] === 422 && ($resHoraInv['json']['code'] ?? '') === 'INVALID_SHIFT_HOURS',
        "B-08." . ($idx + 1) . ": EP-02 rechaza {$desc} ({$hIni}-{$hFin}) con 422 INVALID_SHIFT_HOURS",
        "HTTP={$resHoraInv['status_code']} body={$resHoraInv['raw_body']}"
    );
}

$resNombreVacio = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_nom_vacio_00001',
        'nombre'               => '    ',
        'inicio'               => '08:00',
        'fin'                  => '12:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resNombreVacio['status_code'] === 422 && ($resNombreVacio['json']['code'] ?? '') === 'VALIDATION_ERROR',
    'B-09: EP-02 rechaza nombre vacío tras trim con 422 VALIDATION_ERROR',
    "HTTP={$resNombreVacio['status_code']} body={$resNombreVacio['raw_body']}"
);

$resNombreLargo = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_nom_largo_00001',
        'nombre'               => str_repeat('A', 61),
        'inicio'               => '08:00',
        'fin'                  => '12:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resNombreLargo['status_code'] === 422 && ($resNombreLargo['json']['code'] ?? '') === 'VALIDATION_ERROR',
    'B-10: EP-02 rechaza nombre > 60 caracteres con 422 VALIDATION_ERROR',
    "HTTP={$resNombreLargo['status_code']} body={$resNombreLargo['raw_body']}"
);

// B.5 EP-03 Editar turno conservando relaciones y sin alterar snapshots de sesion_caja
// Insertar una sesión de caja y cerrarla formalmente asociada a $idTurnoManana con snapshot 'Mañana', '07:00:00', '15:00:00'
$pdo->prepare("
    INSERT INTO sesion_caja (
        id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
        hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
        fecha_hora_apertura, tiene_cierre_anterior, saldo_inicial, efectivo_esperado_actual,
        estado, version_caja
    ) VALUES (
        1, :id_vendedor, :id_turno, 'Mañana',
        '07:00:00', '15:00:00', '2026-11-10 15:00:00',
        '2026-11-10 07:02:00', 0, 100.00, 100.00,
        'ABIERTA', 1
    )
")->execute([
    ':id_vendedor' => $idVendedorA,
    ':id_turno'    => $idTurnoManana,
]);
$idSesionHist = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO arqueo_caja (
        id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
        monto_esperado, monto_contado, diferencia, observacion, estado
    ) VALUES (:id_sesion, :id_vendedor, 1, 100.00, 100.00, 0.00, NULL, 'VIGENTE')
")->execute([
    ':id_sesion'   => $idSesionHist,
    ':id_vendedor' => $idVendedorA,
]);
$idArqHist = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE sesion_caja SET id_ultimo_arqueo = :id_arq WHERE id_sesion_caja = :id_ses")
    ->execute([':id_arq' => $idArqHist, ':id_ses' => $idSesionHist]);
$pdo->prepare("
    UPDATE sesion_caja
    SET estado = 'CERRADA',
        version_caja = 2,
        id_arqueo_cierre = :id_arq,
        fecha_hora_cierre = '2026-11-10 15:05:00',
        efectivo_esperado_cierre = 100.00,
        efectivo_contado_cierre = 100.00,
        diferencia_cierre = 0.00,
        observacion_cierre = NULL
    WHERE id_sesion_caja = :id_ses
")->execute([':id_arq' => $idArqHist, ':id_ses' => $idSesionHist]);

$resEditManana = httpRequest(
    'PUT',
    "{$baseUrl}/api/turnos/{$idTurnoManana}",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_edit_manana_0001',
        'nombre'               => 'Mañana Comercial',
        'inicio'               => '07:30',
        'fin'                  => '14:30',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resEditManana['status_code'] === 200
    && ($resEditManana['json']['code'] ?? '') === 'SHIFT_UPDATED'
    && ($resEditManana['json']['data']['id'] ?? 0) === $idTurnoManana
    && ($resEditManana['json']['data']['nombre'] ?? '') === 'Mañana Comercial'
    && ($resEditManana['json']['data']['inicio'] ?? '') === '07:30'
    && ($resEditManana['json']['data']['fin'] ?? '') === '14:30',
    'B-11: EP-03 PUT /api/turnos/{id} actualiza turno existente devolviendo 200 SHIFT_UPDATED',
    "HTTP={$resEditManana['status_code']} body={$resEditManana['raw_body']}"
);

$snapRow = $pdo->query("SELECT nombre_turno_referencia, hora_inicio_referencia, hora_fin_referencia FROM sesion_caja WHERE id_sesion_caja = {$idSesionHist}")->fetch(\PDO::FETCH_ASSOC);
assertCond(
    $snapRow['nombre_turno_referencia'] === 'Mañana'
    && $snapRow['hora_inicio_referencia'] === '07:00:00'
    && $snapRow['hora_fin_referencia'] === '15:00:00',
    'B-12: EP-03 conserva inalterados los snapshots (nombre_turno_referencia, hora_inicio_referencia, hora_fin_referencia) de sesiones de caja existentes'
);

// Restaurar nombre 'Mañana' y horario 07:00-15:00 con EP-03
$resRestoreManana = httpRequest(
    'PUT',
    "{$baseUrl}/api/turnos/{$idTurnoManana}",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_edit_manana_0002',
        'nombre'               => 'Mañana',
        'inicio'               => '07:00',
        'fin'                  => '15:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond($resRestoreManana['status_code'] === 200, 'B-13: EP-03 restaura Mañana (07:00-15:00)');

// B.6 EP-03 sobre turno inexistente (404) y colisión de nombre con otro turno activo (409)
$resEdit404 = httpRequest(
    'PUT',
    "{$baseUrl}/api/turnos/999999",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_edit_404_000001',
        'nombre'               => 'No Existe',
        'inicio'               => '08:00',
        'fin'                  => '12:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resEdit404['status_code'] === 404 && ($resEdit404['json']['code'] ?? '') === 'SHIFT_NOT_FOUND',
    'B-14: EP-03 sobre turno inexistente devuelve 404 SHIFT_NOT_FOUND',
    "HTTP={$resEdit404['status_code']} body={$resEdit404['raw_body']}"
);

$resEditColision = httpRequest(
    'PUT',
    "{$baseUrl}/api/turnos/{$idTurnoManana}",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_edit_col_000001',
        'nombre'               => 'Tarde',
        'inicio'               => '07:00',
        'fin'                  => '15:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resEditColision['status_code'] === 409 && ($resEditColision['json']['code'] ?? '') === 'SHIFT_NAME_ALREADY_EXISTS',
    'B-15: EP-03 rechaza renombrar turno a un nombre usado por otro turno activo con 409 SHIFT_NAME_ALREADY_EXISTS',
    "HTTP={$resEditColision['status_code']} body={$resEditColision['raw_body']}"
);

// B.7 EP-04 Bloqueo por sesión ABIERTA (409 SHIFT_IN_USE)
$pdo->prepare("
    INSERT INTO sesion_caja (
        id_caja_fisica, id_usuario_vendedor, id_turno, nombre_turno_referencia,
        hora_inicio_referencia, hora_fin_referencia, fecha_hora_salida_referencia,
        fecha_hora_apertura, tiene_cierre_anterior, id_sesion_cierre_anterior,
        monto_referencia_cierre_anterior, fecha_hora_cierre_anterior_ref, nombre_turno_cierre_anterior_ref,
        saldo_inicial, diferencia_apertura, efectivo_esperado_actual,
        estado, version_caja
    ) VALUES (
        1, :id_vendedor, :id_turno, 'Madrugada',
        '06:00:00', '10:00:00', '2026-11-12 10:00:00',
        '2026-11-12 06:00:00', 1, :id_prev,
        100.00, '2026-11-10 15:05:00', 'Mañana',
        50.00, -50.00, 50.00,
        'ABIERTA', 1
    )
")->execute([
    ':id_vendedor' => $idVendedorA,
    ':id_turno'    => $idTurnoTemp,
    ':id_prev'     => $idSesionHist,
]);
$idSesionAbiertaTemp = (int)$pdo->lastInsertId();

$resDelInUse = httpRequest(
    'DELETE',
    "{$baseUrl}/api/turnos/{$idTurnoTemp}",
    $sessAdmin['cookie_file'],
    null,
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_turno_del_inuse_00001',
    ],
    'NONE'
);
assertCond(
    $resDelInUse['status_code'] === 409 && ($resDelInUse['json']['code'] ?? '') === 'SHIFT_IN_USE',
    'B-16: EP-04 bloquea baja lógica cuando existe sesión ABIERTA asociada al turno (409 SHIFT_IN_USE)',
    "HTTP={$resDelInUse['status_code']} body={$resDelInUse['raw_body']}"
);

// Cerrar formalmente la sesión en BD con arqueo VIGENTE y dar de baja lógica el turno Madrugada (sin cuerpo y sin Content-Type)
$pdo->prepare("
    INSERT INTO arqueo_caja (
        id_sesion_caja, id_usuario_vendedor, version_caja_evaluada,
        monto_esperado, monto_contado, diferencia, observacion, estado
    ) VALUES (:id_sesion, :id_vendedor, 1, 50.00, 50.00, 0.00, NULL, 'VIGENTE')
")->execute([
    ':id_sesion'   => $idSesionAbiertaTemp,
    ':id_vendedor' => $idVendedorA,
]);
$idArqTemp = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE sesion_caja SET id_ultimo_arqueo = :id_arq WHERE id_sesion_caja = :id_ses")
    ->execute([':id_arq' => $idArqTemp, ':id_ses' => $idSesionAbiertaTemp]);
$pdo->prepare("
    UPDATE sesion_caja
    SET estado = 'CERRADA',
        version_caja = 2,
        id_arqueo_cierre = :id_arq,
        fecha_hora_cierre = '2026-11-12 10:05:00',
        efectivo_esperado_cierre = 50.00,
        efectivo_contado_cierre = 50.00,
        diferencia_cierre = 0.00,
        observacion_cierre = NULL
    WHERE id_sesion_caja = :id_ses
")->execute([':id_arq' => $idArqTemp, ':id_ses' => $idSesionAbiertaTemp]);

$resDelTempOk = httpRequest(
    'DELETE',
    "{$baseUrl}/api/turnos/{$idTurnoTemp}",
    $sessAdmin['cookie_file'],
    null,
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_turno_del_ok_00000001',
    ],
    'NONE'
);
assertCond(
    $resDelTempOk['status_code'] === 200
    && ($resDelTempOk['json']['code'] ?? '') === 'SHIFT_DELETED'
    && ($resDelTempOk['json']['data']['eliminado'] ?? false) === true
    && (int)($resDelTempOk['json']['data']['id'] ?? 0) === $idTurnoTemp,
    'B-17: EP-04 realiza baja lógica (200 SHIFT_DELETED) sin requerir Content-Type cuando solo tiene sesiones cerradas',
    "HTTP={$resDelTempOk['status_code']} body={$resDelTempOk['raw_body']}"
);

// Verificar en BD que la fila sigue existiendo con estado = 0 (nunca DELETE físico)
$filaTempBd = $pdo->query("SELECT id_turno, estado, nombre_activo_normalizado FROM turno WHERE id_turno = {$idTurnoTemp}")->fetch(\PDO::FETCH_ASSOC);
assertCond(
    is_array($filaTempBd) && (int)$filaTempBd['estado'] === 0 && $filaTempBd['nombre_activo_normalizado'] === null,
    'B-18: EP-04 conserva la fila física en tabla turno con estado = 0 y libera nombre_activo_normalizado'
);

// Intentar eliminar otra vez con otra referencia -> 404 SHIFT_NOT_FOUND
$resDelTempAgain = httpRequest(
    'DELETE',
    "{$baseUrl}/api/turnos/{$idTurnoTemp}",
    $sessAdmin['cookie_file'],
    null,
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_turno_del_again_00002',
    ],
    'NONE'
);
assertCond(
    $resDelTempAgain['status_code'] === 404 && ($resDelTempAgain['json']['code'] ?? '') === 'SHIFT_NOT_FOUND',
    'B-19: EP-04 con nueva referencia sobre turno ya inactivo devuelve 404 SHIFT_NOT_FOUND',
    "HTTP={$resDelTempAgain['status_code']} body={$resDelTempAgain['raw_body']}"
);

// ================================================================================
// CATEGORÍA C: PROGRAMACIÓN SEMANAL (EP-05, EP-06)
// ================================================================================
$currentCategory = 'C';
echo "\n--- CATEGORÍA C: Programación semanal de vendedores (EP-05..EP-06) ---\n";

// C.1 Calcular lunes de la semana actual (W), anterior (W-1) y siguientes (W+1, W+2)
$hoy = new \DateTimeImmutable('today');
$diaIso = (int)$hoy->format('N');
$lunesW   = $hoy->modify('-' . ($diaIso - 1) . ' days')->format('Y-m-d');
$lunesWm1 = (new \DateTimeImmutable($lunesW))->modify('-7 days')->format('Y-m-d');
$lunesWp1 = (new \DateTimeImmutable($lunesW))->modify('+7 days')->format('Y-m-d');
$lunesWp2 = (new \DateTimeImmutable($lunesW))->modify('+14 days')->format('Y-m-d');
$miercolesInvalido = (new \DateTimeImmutable($lunesW))->modify('+2 days')->format('Y-m-d');

// C.2 EP-05 para vendedor sin programación -> data: null
$resProgNull = httpRequest(
    'GET',
    "{$baseUrl}/api/programaciones?usuario_id={$idVendedorA}&semana_inicio={$lunesW}",
    $sessAdmin['cookie_file']
);
assertCond(
    $resProgNull['status_code'] === 200
    && ($resProgNull['json']['status'] ?? false) === true
    && ($resProgNull['json']['code'] ?? '') === 'OK'
    && array_key_exists('data', $resProgNull['json'])
    && $resProgNull['json']['data'] === null,
    'C-01: EP-05 devuelve 200 OK con data: null cuando el vendedor no tiene programación aplicable',
    "HTTP={$resProgNull['status_code']} body={$resProgNull['raw_body']}"
);

// C.3 Validaciones de fecha (no lunes y fecha inexistente) en EP-05 y EP-06 -> 422 INVALID_WEEK_START_DATE
$resGetNoLunes = httpRequest(
    'GET',
    "{$baseUrl}/api/programaciones?usuario_id={$idVendedorA}&semana_inicio={$miercolesInvalido}",
    $sessAdmin['cookie_file']
);
assertCond(
    $resGetNoLunes['status_code'] === 422 && ($resGetNoLunes['json']['code'] ?? '') === 'INVALID_WEEK_START_DATE',
    'C-02: EP-05 rechaza fecha que no es lunes (miércoles ' . $miercolesInvalido . ') con 422 INVALID_WEEK_START_DATE',
    "HTTP={$resGetNoLunes['status_code']} body={$resGetNoLunes['raw_body']}"
);

$resPutNoLunes = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_nolunes_00000001',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $miercolesInvalido,
        'alcance'              => 'desde',
        'turnos'               => [['turno_id' => $idTurnoManana, 'dias' => [0, 1, 2]]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resPutNoLunes['status_code'] === 422 && ($resPutNoLunes['json']['code'] ?? '') === 'INVALID_WEEK_START_DATE',
    'C-03: EP-06 rechaza fecha que no es lunes sin normalizarla silenciosamente (422 INVALID_WEEK_START_DATE)',
    "HTTP={$resPutNoLunes['status_code']} body={$resPutNoLunes['raw_body']}"
);

$resFechaInexistente = httpRequest(
    'GET',
    "{$baseUrl}/api/programaciones?usuario_id={$idVendedorA}&semana_inicio=2026-02-30",
    $sessAdmin['cookie_file']
);
assertCond(
    $resFechaInexistente['status_code'] === 422 && ($resFechaInexistente['json']['code'] ?? '') === 'INVALID_WEEK_START_DATE',
    'C-04: EP-05 rechaza fecha calendario inexistente (2026-02-30) con 422 INVALID_WEEK_START_DATE',
    "HTTP={$resFechaInexistente['status_code']} body={$resFechaInexistente['raw_body']}"
);

// C.4 Validaciones de usuario objetivo (404 USER_NOT_FOUND, 422 TARGET_NOT_SELLER para Admin o Vendedor inactivo)
$resUser404 = httpRequest(
    'GET',
    "{$baseUrl}/api/programaciones?usuario_id=999999&semana_inicio={$lunesW}",
    $sessAdmin['cookie_file']
);
assertCond(
    $resUser404['status_code'] === 404 && ($resUser404['json']['code'] ?? '') === 'USER_NOT_FOUND',
    'C-05: EP-05 con usuario inexistente devuelve 404 USER_NOT_FOUND',
    "HTTP={$resUser404['status_code']} body={$resUser404['raw_body']}"
);

$resTargetAdmin = httpRequest(
    'GET',
    "{$baseUrl}/api/programaciones?usuario_id={$idAdminActivo}&semana_inicio={$lunesW}",
    $sessAdmin['cookie_file']
);
assertCond(
    $resTargetAdmin['status_code'] === 422 && ($resTargetAdmin['json']['code'] ?? '') === 'TARGET_NOT_SELLER',
    'C-06: EP-05 sobre usuario Administrador devuelve 422 TARGET_NOT_SELLER',
    "HTTP={$resTargetAdmin['status_code']} body={$resTargetAdmin['raw_body']}"
);

$resPutVendInactivo = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_vend_inact_00001',
        'usuario_id'           => $idVendedorInactivo,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [['turno_id' => $idTurnoManana, 'dias' => [0]]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resPutVendInactivo['status_code'] === 422 && ($resPutVendInactivo['json']['code'] ?? '') === 'TARGET_NOT_SELLER',
    'C-07: EP-06 sobre vendedor inactivo devuelve 422 TARGET_NOT_SELLER',
    "HTTP={$resPutVendInactivo['status_code']} body={$resPutVendInactivo['raw_body']}"
);

// C.5 Excepción semanal ('semana') sin programación base aplicable -> 422 VALIDATION_ERROR
$resSemanaSinBase = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_sin_base_0000001',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'semana',
        'turnos'               => [['turno_id' => $idTurnoManana, 'dias' => [0, 1, 2]]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resSemanaSinBase['status_code'] === 422 && ($resSemanaSinBase['json']['code'] ?? '') === 'VALIDATION_ERROR',
    'C-08: EP-06 rechaza primera programación con alcance="semana" sin base previa aplicable (422 VALIDATION_ERROR)',
    "HTTP={$resSemanaSinBase['status_code']} body={$resSemanaSinBase['raw_body']}"
);

// C.6 Validaciones de turnos repetidos, días inválidos y turno inactivo en EP-06
$resTurnoRepetido = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_dup_turno_000001',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [
            ['turno_id' => $idTurnoManana, 'dias' => [0, 1]],
            ['turno_id' => $idTurnoManana, 'dias' => [2, 3]],
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resTurnoRepetido['status_code'] === 422 && ($resTurnoRepetido['json']['code'] ?? '') === 'DUPLICATE_SHIFT_IN_SCHEDULE',
    'C-09: EP-06 rechaza turno_id repetido en el arreglo turnos con 422 DUPLICATE_SHIFT_IN_SCHEDULE',
    "HTTP={$resTurnoRepetido['status_code']} body={$resTurnoRepetido['raw_body']}"
);

$resDiasInvalidos = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_bad_dias_0000001',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [
            ['turno_id' => $idTurnoManana, 'dias' => [0, 7]],
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resDiasInvalidos['status_code'] === 422 && ($resDiasInvalidos['json']['code'] ?? '') === 'INVALID_SCHEDULE_DAYS',
    'C-10: EP-06 rechaza días fuera de 0..6 con 422 INVALID_SCHEDULE_DAYS',
    "HTTP={$resDiasInvalidos['status_code']} body={$resDiasInvalidos['raw_body']}"
);

$resDiasRepetidos = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_rep_dias_0000001',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [
            ['turno_id' => $idTurnoManana, 'dias' => [1, 1]],
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resDiasRepetidos['status_code'] === 422 && ($resDiasRepetidos['json']['code'] ?? '') === 'INVALID_SCHEDULE_DAYS',
    'C-11: EP-06 rechaza días repetidos dentro del mismo turno con 422 INVALID_SCHEDULE_DAYS',
    "HTTP={$resDiasRepetidos['status_code']} body={$resDiasRepetidos['raw_body']}"
);

$resTurnoInactivo = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_turno_inact_0001',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [
            ['turno_id' => $idTurnoTemp, 'dias' => [0, 1]], // $idTurnoTemp fue dado de baja en B-17
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resTurnoInactivo['status_code'] === 422 && ($resTurnoInactivo['json']['code'] ?? '') === 'INVALID_SHIFT',
    'C-12: EP-06 rechaza turno inactivo con 422 INVALID_SHIFT',
    "HTTP={$resTurnoInactivo['status_code']} body={$resTurnoInactivo['raw_body']}"
);

// C.7 ESCENARIO OBLIGATORIO DE PRECEDENCIA (Sección 7 del prompt / Sección 13.1 del Contrato):
// - Paso 1: Base inicial en W: Mañana (días [0, 1, 2, 3, 4])
// - Paso 2: Excepción en W+1: Tarde (días [0, 1, 2, 3, 4])
// - Paso 3: Nueva base desde W: Mañana y Tarde el mismo día (días [0, 1, 2, 3, 4])
// Resultado esperado:
//   * W:   Mañana y Tarde (alcance = 'desde', origen = W)
//   * W+1: Tarde          (alcance = 'semana', origen = W+1, conserva su prioridad)
//   * W+2: Mañana y Tarde (alcance = 'desde', origen = W, vuelve a la nueva base)

// Paso 1: Base inicial en W (Mañana)
$resBaseInicialW = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_sec7_paso1_basew',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [
            ['turno_id' => $idTurnoManana, 'dias' => [0, 1, 2, 3, 4]],
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resBaseInicialW['status_code'] === 200
    && ($resBaseInicialW['json']['code'] ?? '') === 'SCHEDULE_SAVED'
    && count($resBaseInicialW['json']['data']['turnos'] ?? []) === 1,
    'C-13: Escenario obligatorio Paso 1 -> Guarda base inicial en W con turno Mañana (200 SCHEDULE_SAVED)',
    "HTTP={$resBaseInicialW['status_code']} body={$resBaseInicialW['raw_body']}"
);

// Paso 2: Excepción en W+1 (Tarde)
$resExcepcionWp1 = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_sec7_paso2_excw1',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesWp1,
        'alcance'              => 'semana',
        'turnos'               => [
            ['turno_id' => $idTurnoTarde, 'dias' => [0, 1, 2, 3, 4]],
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resExcepcionWp1['status_code'] === 200
    && ($resExcepcionWp1['json']['code'] ?? '') === 'SCHEDULE_SAVED'
    && ($resExcepcionWp1['json']['data']['alcance'] ?? '') === 'semana',
    'C-14: Escenario obligatorio Paso 2 -> Guarda excepción semanal en W+1 con turno Tarde (200 SCHEDULE_SAVED)',
    "HTTP={$resExcepcionWp1['status_code']} body={$resExcepcionWp1['raw_body']}"
);

// Paso 3: Nueva base desde W con Mañana y Tarde compartiendo los mismos días [0, 1, 2, 3, 4]
$resNuevaBaseW = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_sec7_paso3_nbase',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [
            ['turno_id' => $idTurnoManana, 'dias' => [0, 1, 2, 3, 4]],
            ['turno_id' => $idTurnoTarde,  'dias' => [0, 1, 2, 3, 4]],
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resNuevaBaseW['status_code'] === 200
    && ($resNuevaBaseW['json']['code'] ?? '') === 'SCHEDULE_SAVED'
    && count($resNuevaBaseW['json']['data']['turnos'] ?? []) === 2,
    'C-15: Escenario obligatorio Paso 3 -> Guarda nueva base desde W con Mañana y Tarde el mismo día',
    "HTTP={$resNuevaBaseW['status_code']} body={$resNuevaBaseW['raw_body']}"
);

// Verificar resolución en W, W+1 y W+2 mediante EP-05
$resGetW   = httpRequest('GET', "{$baseUrl}/api/programaciones?usuario_id={$idVendedorA}&semana_inicio={$lunesW}", $sessAdmin['cookie_file']);
$resGetWp1 = httpRequest('GET', "{$baseUrl}/api/programaciones?usuario_id={$idVendedorA}&semana_inicio={$lunesWp1}", $sessAdmin['cookie_file']);
$resGetWp2 = httpRequest('GET', "{$baseUrl}/api/programaciones?usuario_id={$idVendedorA}&semana_inicio={$lunesWp2}", $sessAdmin['cookie_file']);

$turnosW   = array_column($resGetW['json']['data']['turnos'] ?? [], 'turno_id');
$turnosWp1 = array_column($resGetWp1['json']['data']['turnos'] ?? [], 'turno_id');
$turnosWp2 = array_column($resGetWp2['json']['data']['turnos'] ?? [], 'turno_id');

assertCond(
    $turnosW === [$idTurnoManana, $idTurnoTarde]
    && ($resGetW['json']['data']['alcance'] ?? '') === 'desde'
    && ($resGetW['json']['data']['origen_semana_inicio'] ?? '') === $lunesW
    && ($resGetW['json']['data']['tiene_programacion_base'] ?? false) === true
    && ($resGetW['json']['data']['tiene_semana_anterior'] ?? true) === false,
    'C-16: Verificación EP-05 en W -> Resuelve Mañana y Tarde (alcance="desde", origen=W, tiene_semana_anterior=false)',
    "body={$resGetW['raw_body']}"
);

assertCond(
    $turnosWp1 === [$idTurnoTarde]
    && ($resGetWp1['json']['data']['alcance'] ?? '') === 'semana'
    && ($resGetWp1['json']['data']['origen_semana_inicio'] ?? '') === $lunesWp1
    && ($resGetWp1['json']['data']['tiene_programacion_base'] ?? false) === true
    && ($resGetWp1['json']['data']['tiene_semana_anterior'] ?? false) === true,
    'C-17: Verificación EP-05 en W+1 -> Excepción futura conserva prioridad y devuelve solo Tarde (alcance="semana")',
    "body={$resGetWp1['raw_body']}"
);

assertCond(
    $turnosWp2 === [$idTurnoManana, $idTurnoTarde]
    && ($resGetWp2['json']['data']['alcance'] ?? '') === 'desde'
    && ($resGetWp2['json']['data']['origen_semana_inicio'] ?? '') === $lunesW
    && ($resGetWp2['json']['data']['tiene_programacion_base'] ?? false) === true
    && ($resGetWp2['json']['data']['tiene_semana_anterior'] ?? false) === true,
    'C-18: Verificación EP-05 en W+2 -> Vuelve a aplicar la nueva base Mañana y Tarde (alcance="desde", origen=W)',
    "body={$resGetWp2['raw_body']}"
);

// Verificar en BD que la versión 1 reemplazada de W sigue existiendo con es_vigente=0, reemplazada_en NOT NULL y sus detalles intactos
$versionesW = $pdo->query("
    SELECT id_programacion, version_programacion, es_vigente, reemplazada_en, id_programacion_previa,
           (SELECT COUNT(*) FROM programacion_semanal_detalle d WHERE d.id_programacion = programacion_semanal.id_programacion) AS num_detalles
    FROM programacion_semanal
    WHERE id_usuario_vendedor = {$idVendedorA}
      AND semana_inicio = '{$lunesW}'
      AND alcance = 'desde'
    ORDER BY version_programacion ASC
")->fetchAll(\PDO::FETCH_ASSOC);

assertCond(
    count($versionesW) === 2
    && (int)$versionesW[0]['version_programacion'] === 1
    && (int)$versionesW[0]['es_vigente'] === 0
    && $versionesW[0]['reemplazada_en'] !== null
    && (int)$versionesW[0]['num_detalles'] === 5
    && (int)$versionesW[1]['version_programacion'] === 2
    && (int)$versionesW[1]['es_vigente'] === 1
    && (int)$versionesW[1]['id_programacion_previa'] === (int)$versionesW[0]['id_programacion']
    && (int)$versionesW[1]['num_detalles'] === 10,
    'C-19: EP-06 conserva en BD la versión anterior (version_programacion=1, es_vigente=0, 5 detalles intactos) y encadena version_programacion=2 vigente'
);

// C.8 Flujo de duplicación de semana origen (W+1) hacia destino (W+2) como excepción puntual reutilizando EP-05 y EP-06
$turnosOrigenDuplicar = [];
foreach ($resGetWp1['json']['data']['turnos'] as $tOrig) {
    $turnosOrigenDuplicar[] = [
        'turno_id' => (int)$tOrig['turno_id'],
        'dias'     => $tOrig['dias'],
    ];
}
$resDuplicarWp2 = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_duplicar_wp1_wp2',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesWp2,
        'alcance'              => 'semana',
        'turnos'               => $turnosOrigenDuplicar,
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resDuplicarWp2['status_code'] === 200
    && ($resDuplicarWp2['json']['code'] ?? '') === 'SCHEDULE_SAVED'
    && array_column($resDuplicarWp2['json']['data']['turnos'] ?? [], 'turno_id') === [$idTurnoTarde],
    'C-20: Duplicación semanal vía EP-05 -> EP-06 copia turnos/días a semana destino con referencia nueva y alcance elegido',
    "HTTP={$resDuplicarWp2['status_code']} body={$resDuplicarWp2['raw_body']}"
);

// ================================================================================
// CATEGORÍA D: IDEMPOTENCIA Y CONCURRENCIA REAL
// ================================================================================
$currentCategory = 'D';
echo "\n--- CATEGORÍA D: Idempotencia y concurrencia real ---\n";

// D.1 Replay idempotente en EP-02 (TURNO_CREAR)
$resReplayCrear = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_crear_manana_0001',
        'nombre'               => 'Mañana',
        'inicio'               => '07:00',
        'fin'                  => '15:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resReplayCrear['status_code'] === 200
    && ($resReplayCrear['json']['code'] ?? '') === 'OPERATION_ALREADY_CONFIRMED'
    && ($resReplayCrear['json']['idempotent_replay'] ?? false) === true
    && ($resReplayCrear['json']['data']['result_code'] ?? '') === 'SHIFT_CREATED'
    && (int)($resReplayCrear['json']['data']['id'] ?? 0) === $idTurnoManana,
    'D-01: Replay idempotente de EP-02 (TURNO_CREAR) devuelve 200 OPERATION_ALREADY_CONFIRMED con idempotent_replay=true',
    "HTTP={$resReplayCrear['status_code']} body={$resReplayCrear['raw_body']}"
);

// D.2 Replay idempotente en EP-03 (TURNO_EDITAR) incluso después de otro cambio posterior
$resReplayEdit = httpRequest(
    'PUT',
    "{$baseUrl}/api/turnos/{$idTurnoManana}",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_edit_manana_0001',
        'nombre'               => 'Mañana Comercial',
        'inicio'               => '07:30',
        'fin'                  => '14:30',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resReplayEdit['status_code'] === 200
    && ($resReplayEdit['json']['code'] ?? '') === 'OPERATION_ALREADY_CONFIRMED'
    && ($resReplayEdit['json']['idempotent_replay'] ?? false) === true
    && ($resReplayEdit['json']['data']['result_code'] ?? '') === 'SHIFT_UPDATED'
    && ($resReplayEdit['json']['data']['nombre'] ?? '') === 'Mañana Comercial',
    'D-02: Replay idempotente de EP-03 (TURNO_EDITAR) devuelve el resultado original sin re-mutar el turno',
    "HTTP={$resReplayEdit['status_code']} body={$resReplayEdit['raw_body']}"
);

// D.3 Replay idempotente en EP-04 (TURNO_BAJA) sobre un turno que ya está en estado=0
$resReplayDelete = httpRequest(
    'DELETE',
    "{$baseUrl}/api/turnos/{$idTurnoTemp}",
    $sessAdmin['cookie_file'],
    null,
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_turno_del_ok_00000001',
    ],
    'NONE'
);
assertCond(
    $resReplayDelete['status_code'] === 200
    && ($resReplayDelete['json']['code'] ?? '') === 'OPERATION_ALREADY_CONFIRMED'
    && ($resReplayDelete['json']['idempotent_replay'] ?? false) === true
    && ($resReplayDelete['json']['data']['result_code'] ?? '') === 'SHIFT_DELETED'
    && ($resReplayDelete['json']['data']['eliminado'] ?? false) === true,
    'D-03: Replay idempotente de EP-04 (TURNO_BAJA) con misma X-Request-Reference devuelve 200 OPERATION_ALREADY_CONFIRMED',
    "HTTP={$resReplayDelete['status_code']} body={$resReplayDelete['raw_body']}"
);

// D.4 Replay idempotente en EP-06 (PROGRAMACION_HORARIO) enviando los turnos y días en distinto orden y con alias
$resReplayProg = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'request_id'   => 'am_prog_sec7_paso3_nbase',
        'usuarioId'    => $idVendedorA,
        'semanaInicio' => $lunesW,
        'alcance'      => 'desde',
        'turnos'       => [
            ['turnoId' => $idTurnoTarde,  'dias' => [4, 2, 0, 3, 1]],
            ['turnoId' => $idTurnoManana, 'dias' => [3, 1, 4, 0, 2]],
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resReplayProg['status_code'] === 200
    && ($resReplayProg['json']['code'] ?? '') === 'OPERATION_ALREADY_CONFIRMED'
    && ($resReplayProg['json']['idempotent_replay'] ?? false) === true
    && ($resReplayProg['json']['data']['result_code'] ?? '') === 'SCHEDULE_SAVED',
    'D-04: Replay idempotente de EP-06 con alias y orden distinto de turnos/días coincide tras normalización canónica',
    "HTTP={$resReplayProg['status_code']} body={$resReplayProg['raw_body']}"
);

// D.5 Misma referencia con distinto payload funcional -> 409 IDEMPOTENCY_PAYLOAD_MISMATCH en las 4 operaciones
$resMismatchCrear = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_crear_manana_0001',
        'nombre'               => 'Mañana Distinta',
        'inicio'               => '07:00',
        'fin'                  => '15:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resMismatchCrear['status_code'] === 409 && ($resMismatchCrear['json']['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH',
    'D-05: EP-02 con misma referencia y datos distintos devuelve 409 IDEMPOTENCY_PAYLOAD_MISMATCH'
);

$resMismatchEdit = httpRequest(
    'PUT',
    "{$baseUrl}/api/turnos/{$idTurnoManana}",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_turno_edit_manana_0001',
        'nombre'               => 'Mañana Comercial',
        'inicio'               => '08:00',
        'fin'                  => '14:30',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resMismatchEdit['status_code'] === 409 && ($resMismatchEdit['json']['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH',
    'D-06: EP-03 con misma referencia y horas distintas devuelve 409 IDEMPOTENCY_PAYLOAD_MISMATCH'
);

$resMismatchDel = httpRequest(
    'DELETE',
    "{$baseUrl}/api/turnos/{$idTurnoManana}",
    $sessAdmin['cookie_file'],
    null,
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_turno_del_ok_00000001', // Usada antes para $idTurnoTemp
    ],
    'NONE'
);
assertCond(
    $resMismatchDel['status_code'] === 409 && ($resMismatchDel['json']['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH',
    'D-07: EP-04 reutilizando referencia de otro ID de turno devuelve 409 IDEMPOTENCY_PAYLOAD_MISMATCH'
);

$resMismatchProg = httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_prog_sec7_paso3_nbase',
        'usuario_id'           => $idVendedorA,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [
            ['turno_id' => $idTurnoManana, 'dias' => [0, 1]],
        ],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resMismatchProg['status_code'] === 409 && ($resMismatchProg['json']['code'] ?? '') === 'IDEMPOTENCY_PAYLOAD_MISMATCH',
    'D-08: EP-06 con misma referencia y días distintos devuelve 409 IDEMPOTENCY_PAYLOAD_MISMATCH'
);

// D.6 Solicitud simultánea en estado EN_PROCESO -> 409 REQUEST_IN_PROGRESS
$refEnProceso = 'am_turno_en_proceso_0001';
$hashEnProceso = ControlSolicitudService::calcularHashPayload([
    'operacion' => 'TURNO_CREAR',
    'nombre'    => 'Turno En Proceso',
    'inicio'    => '10:00',
    'fin'       => '14:00',
]);
$pdo->prepare("
    INSERT INTO control_solicitud_idempotencia (
        id_usuario, tipo_operacion, referencia_solicitud, hash_payload, estado_solicitud
    ) VALUES (
        :id_user, 'TURNO_CREAR', :ref, :hash, 'EN_PROCESO'
    )
")->execute([
    ':id_user' => $idAdminActivo,
    ':ref'     => $refEnProceso,
    ':hash'    => $hashEnProceso,
]);

$resInProgress = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => $refEnProceso,
        'nombre'               => 'Turno En Proceso',
        'inicio'               => '10:00',
        'fin'                  => '14:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
assertCond(
    $resInProgress['status_code'] === 409 && ($resInProgress['json']['code'] ?? '') === 'REQUEST_IN_PROGRESS',
    'D-09: Solicitud concurrente mientras estado_solicitud=EN_PROCESO devuelve 409 REQUEST_IN_PROGRESS',
    "HTTP={$resInProgress['status_code']} body={$resInProgress['raw_body']}"
);

// D.7 Concurrencia real con procesos paralelos (worker_concurrencia_paso6.php):
// Carrera 1: Dos procesos paralelos intentando crear el mismo nombre de turno activo con distinta referencia
function runParallelWorkers(array $tasks, string $phpBin, string $projectRoot, string $testDbName): array
{
    $workerScript = $projectRoot . '/tests/sprint3/worker_concurrencia_paso6.php';
    $procs = [];
    $env = array_merge($_ENV, [
        'DB_NAME'    => $testDbName,
        'PHPRC' => getenv('PHPRC') ?: '',
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\Windows',
        'PATH'       => getenv('PATH') ?: '',
        'TEMP'       => getenv('TEMP') ?: sys_get_temp_dir(),
        'TMP'        => getenv('TMP') ?: sys_get_temp_dir(),
    ]);

    foreach ($tasks as $i => $task) {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $p = proc_open("\"{$phpBin}\" \"{$workerScript}\"", $desc, $pipes, $projectRoot, $env);
        fwrite($pipes[0], json_encode($task, JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        $procs[$i] = ['proc' => $p, 'pipes' => $pipes];
    }

    $results = [];
    foreach ($procs as $i => $item) {
        $stdout = stream_get_contents($item['pipes'][1]);
        $stderr = stream_get_contents($item['pipes'][2]);
        fclose($item['pipes'][1]);
        fclose($item['pipes'][2]);
        $code = proc_close($item['proc']);
        $results[$i] = [
            'exit_code' => $code,
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'json'      => json_decode((string)$stdout, true),
        ];
    }
    return $results;
}

$raceShiftTasks = [
    [
        'action'   => 'crear_turno',
        'admin_id' => $idAdminActivo,
        'input'    => [
            'referencia_solicitud' => 'am_race_shift_name_00001',
            'nombre'               => 'Turno Carrera Paralela',
            'inicio'               => '11:00',
            'fin'                  => '15:00',
        ],
    ],
    [
        'action'   => 'crear_turno',
        'admin_id' => $idAdminActivo,
        'input'    => [
            'referencia_solicitud' => 'am_race_shift_name_00002',
            'nombre'               => 'Turno Carrera Paralela',
            'inicio'               => '11:00',
            'fin'                  => '15:00',
        ],
    ],
];
$raceShiftResults = runParallelWorkers($raceShiftTasks, $phpBin, $projectRoot, $testDbName);
$codesRaceShift = [
    (int)($raceShiftResults[0]['json']['http_code'] ?? 0),
    (int)($raceShiftResults[1]['json']['http_code'] ?? 0),
];
sort($codesRaceShift);
assertCond(
    $codesRaceShift === [201, 409],
    'D-10: Carrera paralela EP-02 mismo nombre de turno -> exactamente uno 201 SHIFT_CREATED y otro 409 SHIFT_NAME_ALREADY_EXISTS',
    json_encode($raceShiftResults)
);

// Carrera 2: Dos procesos paralelos guardando programación (EP-06) sobre el mismo vendedor ($idVendedorB) y semana ($lunesW)
$raceProgTasks = [
    [
        'action'   => 'guardar_programacion',
        'admin_id' => $idAdminActivo,
        'input'    => [
            'referencia_solicitud' => 'am_race_prog_vendedor_01',
            'usuario_id'           => $idVendedorB,
            'semana_inicio'        => $lunesW,
            'alcance'              => 'desde',
            'turnos'               => [['turno_id' => $idTurnoManana, 'dias' => [0, 1, 2]]],
        ],
    ],
    [
        'action'   => 'guardar_programacion',
        'admin_id' => $idAdminActivo,
        'input'    => [
            'referencia_solicitud' => 'am_race_prog_vendedor_02',
            'usuario_id'           => $idVendedorB,
            'semana_inicio'        => $lunesW,
            'alcance'              => 'desde',
            'turnos'               => [['turno_id' => $idTurnoTarde, 'dias' => [3, 4]]],
        ],
    ],
];
$raceProgResults = runParallelWorkers($raceProgTasks, $phpBin, $projectRoot, $testDbName);
$versionesVendB = $pdo->query("
    SELECT version_programacion, es_vigente
    FROM programacion_semanal
    WHERE id_usuario_vendedor = {$idVendedorB}
      AND semana_inicio = '{$lunesW}'
      AND alcance = 'desde'
    ORDER BY version_programacion ASC
")->fetchAll(\PDO::FETCH_ASSOC);

assertCond(
    (int)($raceProgResults[0]['json']['http_code'] ?? 0) === 200
    && (int)($raceProgResults[1]['json']['http_code'] ?? 0) === 200
    && count($versionesVendB) === 2
    && (int)$versionesVendB[0]['version_programacion'] === 1
    && (int)$versionesVendB[0]['es_vigente'] === 0
    && (int)$versionesVendB[1]['version_programacion'] === 2
    && (int)$versionesVendB[1]['es_vigente'] === 1,
    'D-11: Carrera paralela EP-06 sobre el mismo vendedor serializa con usuario FOR UPDATE generando version 1 (es_vigente=0) y version 2 (es_vigente=1)',
    json_encode($versionesVendB)
);

// Carrera 3: Proceso paralelo asignando un turno en EP-06 vs proceso paralelo eliminando ese mismo turno en EP-04
$idTurnoCarrera = (int)$pdo->query("SELECT id_turno FROM turno WHERE nombre = 'Turno Carrera Paralela' AND estado = 1")->fetchColumn();
$raceDelVsAssignTasks = [
    [
        'action'     => 'eliminar_turno',
        'admin_id'   => $idAdminActivo,
        'id_turno'   => $idTurnoCarrera,
        'header_ref' => 'am_race_del_vs_asig_del1',
    ],
    [
        'action'   => 'guardar_programacion',
        'admin_id' => $idAdminActivo,
        'input'    => [
            'referencia_solicitud' => 'am_race_del_vs_asig_prg1',
            'usuario_id'           => $idVendedorB,
            'semana_inicio'        => $lunesWp1,
            'alcance'              => 'semana',
            'turnos'               => [['turno_id' => $idTurnoCarrera, 'dias' => [0, 1]]],
        ],
    ],
];
$raceDelVsAssignRes = runParallelWorkers($raceDelVsAssignTasks, $phpBin, $projectRoot, $testDbName);
$delHttp  = (int)($raceDelVsAssignRes[0]['json']['http_code'] ?? 0);
$progHttp = (int)($raceDelVsAssignRes[1]['json']['http_code'] ?? 0);

// En cualquier orden de serialización:
// - Si eliminar_turno entró primero: delHttp=200 (SHIFT_DELETED) y progHttp=422 (INVALID_SHIFT).
// - Si guardar_programacion entró primero: progHttp=200 (SCHEDULE_SAVED) y delHttp=409 (SHIFT_HAS_ASSIGNED_SELLERS).
$serializacionCoherente = (
    ($delHttp === 200 && $progHttp === 422)
    || ($progHttp === 200 && $delHttp === 409)
);
assertCond(
    $serializacionCoherente,
    "D-12: Carrera paralela EP-04 (DELETE turno) vs EP-06 (asignar turno) se serializa sin estado inconsistente (DELETE={$delHttp}, PUT={$progHttp})",
    json_encode($raceDelVsAssignRes)
);

// D.8 Simulación de traducción de deadlock / lock timeout (1213 / 1205) en ControlSolicitudService -> 409 CONCURRENT_OPERATION_CONFLICT + rollback íntegro
$ctrlSvc = new ControlSolicitudService($pdo);
$exDeadlock = null;
$refDeadlock = 'am_test_deadlock_sim_001';
try {
    $ctrlSvc->ejecutarConIdempotencia(
        $idAdminActivo,
        'TURNO_CREAR',
        $refDeadlock,
        ['operacion' => 'TURNO_CREAR', 'nombre' => 'DeadlockSim', 'inicio' => '09:00', 'fin' => '11:00'],
        static function () {
            $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 40001);
            $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
            throw $e;
        }
    );
} catch (InventarioException $e) {
    $exDeadlock = $e;
}
$filasDeadlock = (int)$pdo->query("SELECT COUNT(*) FROM control_solicitud_idempotencia WHERE referencia_solicitud = '{$refDeadlock}'")->fetchColumn();
assertCond(
    $exDeadlock !== null
    && $exDeadlock->getHttpCode() === 409
    && $exDeadlock->getErrorCode() === 'CONCURRENT_OPERATION_CONFLICT'
    && $filasDeadlock === 0
    && !$pdo->inTransaction(),
    'D-13 (Simulación): Deadlock InnoDB (1213/40001) revierte la transacción sin confirmar falsamente (0 filas persistidas) y traduce a 409 CONCURRENT_OPERATION_CONFLICT'
);

// ================================================================================
// CATEGORÍA E: REGRESIÓN DE LA CORRECCIÓN HU 18 (11 CASOS + VENDEDORES_ASIGNADOS)
// ================================================================================
$currentCategory = 'E';
echo "\n--- CATEGORÍA E: Regresión de la corrección HU 18 (11 casos y coherencia API/Trigger) ---\n";

// E.1 Ejecutar la herramienta CLI oficial del Paso 5 sobre su copia dedicada
$cmdHu18Cli = sprintf(
    '"%s" "%s/database/tercerSprint/tools/verificar_baja_turnos_programacion_sprint3.php" --test-db=%s',
    $phpBin,
    $projectRoot,
    $hu18DbName
);
$outHu18Cli = [];
$exitHu18Cli = 0;
exec($cmdHu18Cli . ' 2>&1', $outHu18Cli, $exitHu18Cli);
$strOutHu18 = implode("\n", $outHu18Cli);
$jsonHu18 = json_decode($strOutHu18, true);
assertCond(
    $exitHu18Cli === 0
    && is_array($jsonHu18)
    && (int)($jsonHu18['cases'] ?? 0) === 11
    && (int)($jsonHu18['passed'] ?? 0) === 11
    && (int)($jsonHu18['failed'] ?? -1) === 0,
    'E-01: Runner oficial verificar_baja_turnos_programacion_sprint3.php supera los 11/11 casos JSON sobre ' . $hu18DbName,
    "exit={$exitHu18Cli} output={$strOutHu18}"
);

// E.2 Verificar coherencia entre EP-01 (vendedores_asignados), EP-04 (DELETE /api/turnos/{id}) y la vigencia efectiva por intervalos en HTTP real
// Usamos un vendedor y turnos dedicados exclusivamente a E.2 (sin DELETE FROM sobre programacion_semanal)
$resCrearTurnoEMan = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_hu18_crear_turnoem_01',
        'nombre'               => 'Turno E2 Manana',
        'inicio'               => '07:15',
        'fin'                  => '12:15',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$idTurnoEMan = (int)($resCrearTurnoEMan['json']['data']['id'] ?? 0);

$resCrearTurnoETar = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_hu18_crear_turnoet_01',
        'nombre'               => 'Turno E2 Tarde',
        'inicio'               => '16:15',
        'fin'                  => '21:15',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$idTurnoETar = (int)($resCrearTurnoETar['json']['data']['id'] ?? 0);

$resCrearTurnoC = httpRequest(
    'POST',
    "{$baseUrl}/api/turnos",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_hu18_crear_turnoc_001',
        'nombre'               => 'Turno C Intervalos',
        'inicio'               => '12:00',
        'fin'                  => '16:00',
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$idTurnoC = (int)($resCrearTurnoC['json']['data']['id'] ?? 0);

function getVendedoresAsignadosMap(string $baseUrl, string $cookieFile): array
{
    $res = httpRequest('GET', "{$baseUrl}/api/turnos", $cookieFile);
    $map = [];
    foreach ($res['json']['data'] ?? [] as $t) {
        $map[(int)$t['id']] = (int)$t['vendedores_asignados'];
    }
    return $map;
}

// Escenario E.2.1: Base antigua en W-1 con Turno C y nueva base en W con Turno E2 Manana.
// -> Turno C fue desplazado desde W: vendedores_asignados(Turno C) debe ser 0 y EP-04 debe permitir su baja.
httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_hu18_http_base_wm1_01',
        'usuario_id'           => $idVendedorE2,
        'semana_inicio'        => $lunesWm1,
        'alcance'              => 'desde',
        'turnos'               => [['turno_id' => $idTurnoC, 'dias' => [0, 1, 2, 3, 4]]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$mapAfterWm1 = getVendedoresAsignadosMap($baseUrl, $sessAdmin['cookie_file']);
assertCond(
    ($mapAfterWm1[$idTurnoC] ?? -1) === 1,
    'E-02: Cuando la única base del vendedor está en W-1 con Turno C, EP-01 reporta vendedores_asignados=1'
);

// Ahora registramos una nueva base en W con Turno E2 Manana para el mismo vendedor
httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_hu18_http_base_w_0002',
        'usuario_id'           => $idVendedorE2,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'desde',
        'turnos'               => [['turno_id' => $idTurnoEMan, 'dias' => [0, 1, 2, 3, 4]]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$mapAfterW = getVendedoresAsignadosMap($baseUrl, $sessAdmin['cookie_file']);
assertCond(
    ($mapAfterW[$idTurnoC] ?? -1) === 0 && ($mapAfterW[$idTurnoEMan] ?? -1) === 1,
    'E-03: Al registrar nueva base desde W con Turno E2 Manana, la base histórica de W-1 (Turno C) deja de contar como asignación actual (vendedores_asignados=0)'
);

// Y la consulta histórica EP-05 de W-1 sigue devolviendo Turno C
$resHistWm1 = httpRequest('GET', "{$baseUrl}/api/programaciones?usuario_id={$idVendedorE2}&semana_inicio={$lunesWm1}", $sessAdmin['cookie_file']);
assertCond(
    array_column($resHistWm1['json']['data']['turnos'] ?? [], 'turno_id') === [$idTurnoC],
    'E-04: EP-05 conserva intacta la resolución histórica de la semana pasada W-1 (Turno C)'
);

// Escenario E.2.2: Base en W tiene Turno E2 Manana, y se crea una nueva base futura en W+1 con Turno E2 Tarde.
// En este momento la base de W solo rige el intervalo [W, W+1) (1 sola semana: W).
// -> Turno E2 Manana sigue rigiendo en W, así que vendedores_asignados=1 y EP-04 bloquea su baja.
httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_hu18_http_base_wp1_03',
        'usuario_id'           => $idVendedorE2,
        'semana_inicio'        => $lunesWp1,
        'alcance'              => 'desde',
        'turnos'               => [['turno_id' => $idTurnoETar, 'dias' => [0, 1, 2, 3, 4]]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$mapIntervaloAbierto = getVendedoresAsignadosMap($baseUrl, $sessAdmin['cookie_file']);
assertCond(
    ($mapIntervaloAbierto[$idTurnoEMan] ?? -1) === 1 && ($mapIntervaloAbierto[$idTurnoETar] ?? -1) === 1,
    'E-05: Con base en W (E2 Manana) y siguiente base en W+1 (E2 Tarde) sin excepción en W, ambos turnos tienen vendedores_asignados=1'
);

$resDelMananaBloq = httpRequest(
    'DELETE',
    "{$baseUrl}/api/turnos/{$idTurnoEMan}",
    $sessAdmin['cookie_file'],
    null,
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_hu18_del_man_bloq_001',
    ],
    'NONE'
);
assertCond(
    $resDelMananaBloq['status_code'] === 409 && ($resDelMananaBloq['json']['code'] ?? '') === 'SHIFT_HAS_ASSIGNED_SELLERS',
    'E-06: EP-04 bloquea baja de Turno E2 Manana mientras la semana W de su intervalo [W, W+1) no está cubierta por excepción (409 SHIFT_HAS_ASSIGNED_SELLERS)'
);

// Escenario E.2.3: Ahora registramos una excepción semanal en W con Turno E2 Tarde.
// El intervalo [W, W+1) de la base de W queda 100% cubierto por la excepción de W (con E2 Tarde), y desde W+1 rige la base de W+1 (con E2 Tarde).
// -> Turno E2 Manana ya no tiene ninguna semana efectiva actual ni futura: vendedores_asignados pasa a 0!
httpRequest(
    'PUT',
    "{$baseUrl}/api/programaciones",
    $sessAdmin['cookie_file'],
    [
        'referencia_solicitud' => 'am_hu18_http_exc_w_00004',
        'usuario_id'           => $idVendedorE2,
        'semana_inicio'        => $lunesW,
        'alcance'              => 'semana',
        'turnos'               => [['turno_id' => $idTurnoETar, 'dias' => [0, 1, 2, 3, 4]]],
    ],
    ['X-CSRF-Token' => $sessAdmin['csrf_token']]
);
$mapIntervaloCubierto = getVendedoresAsignadosMap($baseUrl, $sessAdmin['cookie_file']);
assertCond(
    ($mapIntervaloCubierto[$idTurnoEMan] ?? -1) === 0 && ($mapIntervaloCubierto[$idTurnoETar] ?? -1) === 1,
    'E-07: Cuando todas las semanas restantes del intervalo [W, W+1) están cubiertas por excepciones con otro turno, vendedores_asignados(E2 Manana)=0'
);

// Escenario E.2.4: Dar de baja lógica Turno C (que solo figura en la base histórica desplazada de W-1) -> 200 SHIFT_DELETED,
// y verificar que al consultar EP-05 en W-1 sigue apareciendo Turno C en la historia aunque hoy esté inactivo (estado = 0)!
$resDelTurnoCOk = httpRequest(
    'DELETE',
    "{$baseUrl}/api/turnos/{$idTurnoC}",
    $sessAdmin['cookie_file'],
    null,
    [
        'X-CSRF-Token'        => $sessAdmin['csrf_token'],
        'X-Request-Reference' => 'am_hu18_del_turnoc_ok_01',
    ],
    'NONE'
);
assertCond(
    $resDelTurnoCOk['status_code'] === 200 && ($resDelTurnoCOk['json']['code'] ?? '') === 'SHIFT_DELETED',
    'E-08: EP-04 permite dar de baja lógica Turno C que solo figuraba en la base histórica desplazada de W-1 (200 SHIFT_DELETED)'
);

$resHistWm1AfterBaja = httpRequest('GET', "{$baseUrl}/api/programaciones?usuario_id={$idVendedorE2}&semana_inicio={$lunesWm1}", $sessAdmin['cookie_file']);
assertCond(
    array_column($resHistWm1AfterBaja['json']['data']['turnos'] ?? [], 'turno_id') === [$idTurnoC],
    'E-09: EP-05 no filtra por estado actual del turno y sigue mostrando Turno C en la consulta histórica de W-1 tras su baja lógica'
);

// ================================================================================
// CATEGORÍA F: REGRESIÓN GENERAL Y CONSERVACIÓN DE DATOS DE AMAZON_MARKET
// ================================================================================
$currentCategory = 'F';
echo "\n--- CATEGORÍA F: Regresión general de módulos existentes y conservación de datos ---\n";

// F.1 Rutas API existentes (Sprint 2) funcionan sin regresión
$resApiUsuarios = httpRequest('GET', "{$baseUrl}/api/usuarios", $sessAdmin['cookie_file']);
assertCond(
    $resApiUsuarios['status_code'] === 200 && ($resApiUsuarios['json']['status'] ?? false) === true,
    'F-01: Regresión GET /api/usuarios responde 200 OK correctamente',
    "HTTP={$resApiUsuarios['status_code']}"
);

$resApiProductos = httpRequest('GET', "{$baseUrl}/api/productos", $sessAdmin['cookie_file']);
assertCond(
    $resApiProductos['status_code'] === 200 && ($resApiProductos['json']['status'] ?? false) === true,
    'F-02: Regresión GET /api/productos responde 200 OK correctamente',
    "HTTP={$resApiProductos['status_code']}"
);

$resApiReportesKardex = httpRequest('GET', "{$baseUrl}/api/reportes/kardex", $sessAdmin['cookie_file']);
assertCond(
    $resApiReportesKardex['status_code'] === 200 && ($resApiReportesKardex['json']['status'] ?? false) === true,
    'F-03: Regresión GET /api/reportes/kardex responde 200 OK correctamente',
    "HTTP={$resApiReportesKardex['status_code']}"
);

$resApiVentas = httpRequest('GET', "{$baseUrl}/api/ventas", $sessAdmin['cookie_file']);
assertCond(
    $resApiVentas['status_code'] === 200 && ($resApiVentas['json']['status'] ?? false) === true,
    'F-03b: Regresión GET /api/ventas responde 200 OK correctamente',
    "HTTP={$resApiVentas['status_code']}"
);

$resApiCompras = httpRequest('GET', "{$baseUrl}/api/compras", $sessAdmin['cookie_file']);
assertCond(
    $resApiCompras['status_code'] === 200 && ($resApiCompras['json']['status'] ?? false) === true,
    'F-03c: Regresión GET /api/compras responde 200 OK correctamente',
    "HTTP={$resApiCompras['status_code']}"
);

// F.2 Conservación de datos comerciales anteriores en la copia de pruebas (250 ventas, 80 compras, stock 2497, 470 kardex, 75 op_inv)
// Nota: reporte_consulta_snapshot es tabla de caché que GET /api/reportes/kardex refresca al ejecutarse en F-03.
$conteosCopia = [
    'producto'             => (int)$pdo->query("SELECT COUNT(*) FROM producto")->fetchColumn(),
    'suma_stock'           => (int)$pdo->query("SELECT COALESCE(SUM(stock_actual), 0) FROM producto")->fetchColumn(),
    'venta'                => (int)$pdo->query("SELECT COUNT(*) FROM venta")->fetchColumn(),
    'detalle_venta'        => (int)$pdo->query("SELECT COUNT(*) FROM detalle_venta")->fetchColumn(),
    'compra'               => (int)$pdo->query("SELECT COUNT(*) FROM compra")->fetchColumn(),
    'detalle_compra'       => (int)$pdo->query("SELECT COUNT(*) FROM detalle_compra")->fetchColumn(),
    'kardex'               => (int)$pdo->query("SELECT COUNT(*) FROM kardex")->fetchColumn(),
    'operacion_inventario' => (int)$pdo->query("SELECT COUNT(*) FROM operacion_inventario")->fetchColumn(),
];
assertCond(
    $conteosCopia['producto'] === 108
    && $conteosCopia['suma_stock'] === 2497
    && $conteosCopia['venta'] === 250
    && $conteosCopia['detalle_venta'] === 295
    && $conteosCopia['compra'] === 80
    && $conteosCopia['detalle_compra'] === 100
    && $conteosCopia['kardex'] === 470
    && $conteosCopia['operacion_inventario'] === 75,
    'F-04: Los datos comerciales históricos en la base de pruebas (productos=108, stock=2497, ventas=250, compras=80, kardex=470, op_inv=75) permanecen intactos'
);

// F.3 La suite no debe cambiar la principal, esté o no desplegado el Sprint 3.
$principalAfter = \DespliegueSprint3::snapshot('amazon_market');
assertCond($principalBaseline === $principalAfter,
    'F-05: La base principal conserva exactamente sus tablas, filas y triggers respecto al inicio de la suite');

// Limpiar archivos de cookies temporales
@unlink($sessAdmin['cookie_file']);
@unlink($sessAdminInact['cookie_file']);
@unlink($sessVendA['cookie_file']);

echo "\n================================================================================\n";
echo " RESUMEN DE RESULTADOS POR CATEGORÍA\n";
echo "================================================================================\n";
foreach ($categoryStats as $cat => $st) {
    echo sprintf("  Categoría %-4s : %3d superadas | %3d fallidas\n", $cat, $st['passed'], $st['failed']);
}
echo "--------------------------------------------------------------------------------\n";
echo sprintf("  TOTAL          : %3d / %3d aserciones superadas\n", $passedAssertions, $totalAssertions);
echo "================================================================================\n";

if (!empty($failedAssertions)) {
    fwrite(STDERR, "\nASERCIONES FALLIDAS:\n" . implode("\n", $failedAssertions) . "\n");
    exit(1);
}

exit(0);
