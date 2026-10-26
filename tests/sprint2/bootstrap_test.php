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

define('SPRINT2_TEST_DB', 'amazon_market_sprint2_test');

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

