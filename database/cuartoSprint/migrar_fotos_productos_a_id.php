<?php
/**
 * Procedimiento reproducible (Paso 3 - HU-20) para vincular fotos heredadas
 * (indexadas por codigo_barra) a su clave estable por ID (`prod_{id_producto}`)
 * ANTES de habilitar la lectura exclusivamente por ID.
 *
 * Uso en entorno aislado:
 *   $env:DB_NAME = 'amazon_market_sprint4_test_...'
 *   $env:STORAGE_PATH = 'C:\ruta\aislada\storage'
 *   $env:PUBLIC_PRODUCT_IMAGES_PATH = 'C:\ruta\aislada\public_img'
 *   & 'C:\xampp\php\php.exe' 'database/cuartoSprint/migrar_fotos_productos_a_id.php'
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = realpath(__DIR__ . '/../..');
chdir($projectRoot);

require_once 'Config/Config.php';
require_once 'Libraries/Core/Autoload.php';
require_once 'Helpers/helpers.php';

$pdo = \Libraries\Core\Conexion::getInstance()->conect();
$dbActual = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

$storage = new \Services\StorageService();

echo "=== MIGRACIÓN DE FOTOS HEREDADAS A CLAVE POR ID (HU-20) ===\n";
echo "SELECT DATABASE()          : {$dbActual}\n";
echo "STORAGE_PATH               : {$storage->getStoragePath()}\n";
echo "PUBLIC_PRODUCT_IMAGES_PATH : {$storage->getPublicPath()}\n";

$filas = $pdo->query("
    SELECT id_producto, codigo_barra
    FROM producto
    WHERE codigo_barra IS NOT NULL AND TRIM(codigo_barra) <> ''
    ORDER BY id_producto ASC
")->fetchAll(\PDO::FETCH_ASSOC);

$mapa = [];
foreach ($filas as $f) {
    $mapa[(int)$f['id_producto']] = (string)$f['codigo_barra'];
}

$resultado = $storage->migrarFotosExistentesPorMapa($mapa);

echo "Productos con código evaluados : " . count($mapa) . "\n";
echo "Promovidas a prod_{id}         : " . count($resultado['promovidas']) . (empty($resultado['promovidas']) ? '' : ' [' . implode(', ', $resultado['promovidas']) . ']') . "\n";
echo "Ya vinculadas por ID           : " . count($resultado['ya_vinculadas']) . "\n";
echo "Sin foto heredada              : " . count($resultado['sin_foto']) . "\n";
echo "Errores                        : " . count($resultado['errores']) . "\n";

if (!empty($resultado['errores'])) {
    foreach ($resultado['errores'] as $idProd => $errMsg) {
        fwrite(STDERR, "  [ERROR] id_producto={$idProd}: {$errMsg}\n");
    }
    exit(1);
}

echo "RESULTADO: OK\n";
exit(0);
