<?php
/**
 * Bootstrap de pruebas para el Sprint 2.
 * Fuerza de manera inequívoca la conexión hacia la base de datos aislada de pruebas
 * (amazon_market_sprint2_test) sin alterar el archivo .env ni la conexión habitual.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Las suites solo pueden ejecutarse desde CLI.');
}

$targetSprint2Db = getenv('TEST_DB_NAME') ?: 'amazon_market_sprint2_test';
if ($targetSprint2Db === 'amazon_market') {
    throw new RuntimeException('Abortado por seguridad: nunca ejecutar pruebas sobre amazon_market.');
}
define('SPRINT2_TEST_DB', $targetSprint2Db);

$_ENV['DB_NAME'] = SPRINT2_TEST_DB;
putenv('DB_NAME=' . SPRINT2_TEST_DB);

// Las pruebas no comparten los archivos de sesión del Apache habitual.
if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
}

require_once __DIR__ . '/../../Config/Config.php';
require_once __DIR__ . '/../../Libraries/Core/Autoload.php';

if (!defined('DB_NAME') || DB_NAME !== SPRINT2_TEST_DB) {
    throw new RuntimeException(
        'Abortado por seguridad: DB_NAME (' . (defined('DB_NAME') ? DB_NAME : 'indefinido') . ') ' .
        'no coincide con la base de pruebas (' . SPRINT2_TEST_DB . ').'
    );
}

$pdoTest = \Libraries\Core\Conexion::getInstance()->conect();
$dbActiva = $pdoTest ? $pdoTest->query('SELECT DATABASE()')->fetchColumn() : null;

if ($dbActiva !== SPRINT2_TEST_DB) {
    throw new RuntimeException(
        'Abortado por seguridad: la sesión PDO activa apunta a "' . (string)$dbActiva .
        '" en lugar de "' . SPRINT2_TEST_DB . '".'
    );
}

/**
 * Garantiza una sesión de caja abierta de Vendedor (id_rol = 2) para pruebas Sprint 2
 * ejecutadas sobre el esquema Sprint 3 (Paso 9: POST /api/ventas exige Vendedor y caja abierta).
 */
function prepararSesionCajaPruebaSprint3(\PDO $db): int
{
    $idVendedor = (int)$db->query('SELECT id_usuario FROM usuario WHERE id_rol = 2 AND estado = 1 ORDER BY id_usuario ASC LIMIT 1')->fetchColumn();
    if ($idVendedor <= 0) {
        throw new RuntimeException('No existe un vendedor activo (id_rol = 2) en la base de pruebas.');
    }

    $abierta = $db->query("SELECT id_sesion_caja, id_usuario_vendedor FROM sesion_caja WHERE estado = 'ABIERTA' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($abierta) {
        return (int)$abierta['id_usuario_vendedor'];
    }

    $turno = $db->query('SELECT id_turno, nombre, hora_inicio, hora_fin FROM turno WHERE estado = 1 ORDER BY id_turno ASC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
    if (!$turno) {
        $db->exec("INSERT INTO turno (nombre, hora_inicio, hora_fin, estado) VALUES ('Turno Test S2', '07:00:00', '23:00:00', 1)");
        $turno = $db->query('SELECT id_turno, nombre, hora_inicio, hora_fin FROM turno WHERE estado = 1 ORDER BY id_turno DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
    }

    $idCajaFisica = 1;
    $ahora = date('Y-m-d H:i:s');
    $salida = date('Y-m-d') . ' ' . $turno['hora_fin'];

    $stmt = $db->prepare(
        "INSERT INTO sesion_caja (
            id_caja_fisica, id_usuario_vendedor, id_turno,
            nombre_turno_referencia, hora_inicio_referencia, hora_fin_referencia,
            fecha_hora_salida_referencia, es_suplencia, abierta_fuera_horario,
            fecha_hora_apertura, tiene_cierre_anterior, saldo_inicial,
            efectivo_esperado_actual, version_caja, estado
        ) VALUES (
            :caja, :vend, :turno,
            :nom, :ini, :fin,
            :sal, 0, 1,
            :ahora, 0, 100.00,
            100.00, 1, 'ABIERTA'
        )"
    );
    $stmt->execute([
        ':caja' => $idCajaFisica,
        ':vend' => $idVendedor,
        ':turno' => (int)$turno['id_turno'],
        ':nom' => (string)$turno['nombre'],
        ':ini' => (string)$turno['hora_inicio'],
        ':fin' => (string)$turno['hora_fin'],
        ':sal' => $salida,
        ':ahora' => $ahora,
    ]);

    return $idVendedor;
}

