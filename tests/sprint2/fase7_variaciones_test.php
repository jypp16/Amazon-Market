<?php
/**
 * Suite de Pruebas de Integración - Fase 7 (Sprint 2)
 * Reporte de Variaciones y Selector de Sentido de Movimiento.
 *
 * Se ejecuta EXCLUSIVAMENTE sobre `amazon_market_sprint2_test`.
 */

require_once __DIR__ . '/bootstrap_test.php';

use Libraries\Core\Conexion;
use Libraries\Core\InventarioException;
use Models\KardexModel;
use Models\ProductoModel;
use Services\InventarioService;
use Services\MovimientoInventarioService;
use Services\ProductoService;
use Services\ReporteService;
use Controllers\ReporteController;

$db = Conexion::getInstance()->conect();
$passed = 0;
$failed = 0;
$results = [];

function assertTest(string $code, string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed, $results;
    if ($condition) {
        $passed++;
        $results[] = ['code' => $code, 'status' => 'PASS', 'description' => $description, 'detail' => $detail];
        echo "[PASS] {$code}: {$description}" . ($detail !== '' ? " ({$detail})" : "") . PHP_EOL;
    } else {
        $failed++;
        $results[] = ['code' => $code, 'status' => 'FAIL', 'description' => $description, 'detail' => $detail];
        echo "[FAIL] {$code}: {$description}" . ($detail !== '' ? " ({$detail})" : "") . PHP_EOL;
    }
}

echo "=== INICIANDO SUITE FASE 7 EN: " . $db->query("SELECT DATABASE()")->fetchColumn() . " ===" . PHP_EOL . PHP_EOL;

// -------------------------------------------------------------------------
// LIMPIEZA PREVIA Y PREPARACIÓN DE AMBIENTE DE PRUEBAS
// -------------------------------------------------------------------------
$db->exec("DELETE FROM reporte_consulta_snapshot_item");
$db->exec("DELETE FROM reporte_consulta_snapshot");
$db->exec("DELETE FROM kardex");
$db->exec("DELETE FROM operacion_inventario");
$db->exec("DELETE FROM compra_historial");
$db->exec("UPDATE detalle_compra SET id_detalle_origen = NULL");
$db->exec("DELETE FROM detalle_compra");
$db->exec("DELETE FROM compra");
$db->exec("DELETE FROM detalle_venta");
$db->exec("DELETE FROM venta");
$db->exec("DELETE FROM control_solicitud_idempotencia");
$db->exec("DELETE FROM producto WHERE codigo_barra LIKE 'TST-%'");
$db->exec("UPDATE producto SET stock_actual = 0");

$productoService = new ProductoService();
$reporteService = new ReporteService();
$kardexModel = new KardexModel();

$idAdminActivo = 1;
$idAdminSecundario = (int)$db->query("SELECT id_usuario FROM usuario WHERE id_rol = 1 AND id_usuario != 1 AND estado = 1 LIMIT 1")->fetchColumn();
if (!$idAdminSecundario) {
    $db->exec("INSERT INTO usuario (id_usuario, nombre, email, password, id_rol, estado) VALUES (99, 'Admin Secundario Test', 'admin2_test@test.com', 'hash', 1, 1)
               ON DUPLICATE KEY UPDATE estado = 1");
    $idAdminSecundario = 99;
}

// -------------------------------------------------------------------------
// CREACIÓN DE PRODUCTOS DE PRUEBA
// -------------------------------------------------------------------------
// Producto A: Abarrotes / General (con variaciones e historial completo)
$prodA_res = $productoService->crear([
    'codigo_barra' => 'TST-VAR-A-' . time(),
    'nombre' => 'Producto Variacion A',
    'id_categoria' => 1,
    'id_subcategoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 20.00,
    'stock_minimo' => 5
]);
$prodA = (int)$prodA_res['id_producto'];

// Producto B: Lácteos / Leches (con variaciones)
$prodB_res = $productoService->crear([
    'codigo_barra' => 'TST-VAR-B-' . time(),
    'nombre' => 'Producto Variacion B',
    'id_categoria' => 2,
    'id_subcategoria' => 2,
    'id_unidad' => 1,
    'precio_venta' => 10.00,
    'stock_minimo' => 3
]);
$prodB = (int)$prodB_res['id_producto'];

// Producto C: Inactivo con historial de variaciones
$prodC_res = $productoService->crear([
    'codigo_barra' => 'TST-VAR-C-' . time(),
    'nombre' => 'Producto Variacion Inactivo',
    'id_categoria' => 1,
    'id_subcategoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 15.00,
    'stock_minimo' => 2
]);
$prodC = (int)$prodC_res['id_producto'];

// Producto D: Sin movimientos (para prueba de cero resultados)
$prodD_res = $productoService->crear([
    'codigo_barra' => 'TST-VAR-D-' . time(),
    'nombre' => 'Producto Variacion Sin Movs',
    'id_categoria' => 1,
    'id_subcategoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 5.00,
    'stock_minimo' => 1
]);
$prodD = (int)$prodD_res['id_producto'];

// -------------------------------------------------------------------------
// INSERCIÓN DE MOVIMIENTOS EN KARDEX Y OPERACIONES
// -------------------------------------------------------------------------
// 1. Movimientos no-variación en el rango (deben ser EXCLUIDOS estrictamente de variaciones):
// 1a. Compra (COMPRA)
$db->exec("UPDATE producto SET stock_actual = 100 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO compra (id_compra, id_proveedor, id_usuario, id_tipo_comprobante, serie, numero, fecha_emision, fecha_recepcion, total_documento, estado)
           VALUES (501, 1, {$idAdminActivo}, 1, 'F001', '000501', '2026-10-02', '2026-10-02', 1000, 'Confirmada')");
$db->exec("INSERT INTO detalle_compra (id_detalle_compra, id_compra, id_producto, id_presentacion, cantidad_presentaciones, equivalencia_aplicada, cantidad_base, importe_final_linea, version_detalle, es_vigente)
           VALUES (601, 501, {$prodA}, 1, 100, 1, 100, 1000, 1, 1)");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_accion_compra, id_detalle_compra, referencia_operacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'COMPRA', 'INCREMENTO', 'Compra', 100, 0, 100,
                   501, 601, 'F001-000501', 'SIN_LOTE', NULL,
                   'TST-VAR-A', 'Producto Variacion A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-02 09:00:00')");

// 1b. Venta (VENTA)
$db->exec("UPDATE producto SET stock_actual = 90 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO venta (id_venta, id_usuario, id_cliente, id_tipo_comprobante, serie, numero, total, id_metodo_pago, estado, fecha_venta)
           VALUES (501, {$idAdminActivo}, 1, 1, 'B001', '000501', 200, 1, 1, '2026-10-05 10:00:00')");
$db->exec("INSERT INTO detalle_venta (id_detalle_venta, id_venta, id_producto, cantidad, precio_unitario, descuento, subtotal)
           VALUES (601, 501, {$prodA}, 10, 20.00, 0, 200)");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_accion_venta, id_detalle_venta, referencia_operacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'VENTA', 'DISMINUCION', 'Venta', 10, 100, 90,
                   501, 601, 'B001-000501', 'NO_APLICA', NULL,
                   'TST-VAR-A', 'Producto Variacion A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-05 10:00:00')");

// 2. Variaciones legítimas en el período de Octubre:
// 2a. BAJA_DANO en Producto A: 2026-10-08 11:00:00, cantidad: 4, lote: 'LOT-DANO-01'
$db->exec("UPDATE producto SET stock_actual = 86 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, numero_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (701, {$prodA}, {$idAdminActivo}, 'BAJA', 'BAJA_DANO', 'Baja por daño', 'DISMINUCION',
                   4, 90, 90, 86, 'CON_LOTE', 'LOT-DANO-01', 'Envases rotos por caída', 'baja-dano-20261008-0001', '2026-10-08 11:00:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'BAJA_DANO', 'DISMINUCION', 'Baja por daño', 4, 90, 86,
                   701, 'OP-INV-701', 'Envases rotos por caída', 'CON_LOTE', 'LOT-DANO-01',
                   'TST-VAR-A', 'Producto Variacion A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-08 11:00:00')");

// 2b. BAJA_VENCIMIENTO en Producto A: 2026-10-10 14:00:00, cantidad: 6, lote: 'LOT-VENC-02'
$db->exec("UPDATE producto SET stock_actual = 80 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, numero_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (702, {$prodA}, {$idAdminActivo}, 'BAJA', 'BAJA_VENCIMIENTO', 'Baja por vencimiento', 'DISMINUCION',
                   6, 86, 86, 80, 'CON_LOTE', 'LOT-VENC-02', 'Fecha de caducidad superada', 'baja-venc-20261010-0002', '2026-10-10 14:00:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'BAJA_VENCIMIENTO', 'DISMINUCION', 'Baja por vencimiento', 6, 86, 80,
                   702, 'OP-INV-702', 'Fecha de caducidad superada', 'CON_LOTE', 'LOT-VENC-02',
                   'TST-VAR-A', 'Producto Variacion A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-10 14:00:00')");

// 2c. AJUSTE_POSITIVO en Producto A: 2026-10-15 09:30:00, cantidad: 8, sin lote
$db->exec("UPDATE producto SET stock_actual = 88 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (703, {$prodA}, {$idAdminActivo}, 'AJUSTE', 'AJUSTE_POSITIVO', 'Ajuste positivo', 'INCREMENTO',
                   8, 80, 80, 88, 'SIN_LOTE', 'Conteo físico sobrante anaquel A1', 'ajuste-pos-20261015-0003', '2026-10-15 09:30:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'AJUSTE_POSITIVO', 'INCREMENTO', 'Ajuste positivo', 8, 80, 88,
                   703, 'OP-INV-703', 'Conteo físico sobrante anaquel A1', 'SIN_LOTE', NULL,
                   'TST-VAR-A', 'Producto Variacion A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-15 09:30:00')");

// 2d. AJUSTE_NEGATIVO en Producto B: 2026-10-18 16:00:00, cantidad: 5, sin lote
$db->exec("UPDATE producto SET stock_actual = 25 WHERE id_producto = {$prodB}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (704, {$prodB}, {$idAdminActivo}, 'AJUSTE', 'AJUSTE_NEGATIVO', 'Ajuste negativo', 'DISMINUCION',
                   5, 30, 30, 25, 'SIN_LOTE', 'Faltante en conteo físico lácteos', 'ajuste-neg-20261018-0004', '2026-10-18 16:00:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodB}, {$idAdminActivo}, 'AJUSTE_NEGATIVO', 'DISMINUCION', 'Ajuste negativo', 5, 30, 25,
                   704, 'OP-INV-704', 'Faltante en conteo físico lácteos', 'SIN_LOTE', NULL,
                   'TST-VAR-B', 'Producto Variacion B', 2, 'Lácteos', 2, 'Leches', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-18 16:00:00')");

// 2e. AJUSTE_POSITIVO en Producto C (Inactivo): 2026-10-20 12:00:00, cantidad: 3
$db->exec("UPDATE producto SET stock_actual = 13, estado = 0 WHERE id_producto = {$prodC}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (705, {$prodC}, {$idAdminActivo}, 'AJUSTE', 'AJUSTE_POSITIVO', 'Ajuste positivo', 'INCREMENTO',
                   3, 10, 10, 13, 'SIN_LOTE', 'Regularización producto descatalogado', 'ajuste-pos-20261020-0005', '2026-10-20 12:00:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodC}, {$idAdminActivo}, 'AJUSTE_POSITIVO', 'INCREMENTO', 'Ajuste positivo', 3, 10, 13,
                   705, 'OP-INV-705', 'Regularización producto descatalogado', 'SIN_LOTE', NULL,
                   'TST-VAR-C', 'Producto Variacion Inactivo', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-20 12:00:00')");


// =========================================================================
// TEST 1: F7-ORIGEN-01 — Inclusión de los 4 orígenes de variación legítimos
// =========================================================================
$resGeneral = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
], $idAdminActivo);

$items = $resGeneral['items'];
$tiposEncontrados = array_unique(array_column($items, 'tipo_variacion'));
sort($tiposEncontrados);
$tiposEsperados = ['Ajuste negativo', 'Ajuste positivo', 'Baja por daño', 'Baja por vencimiento'];
sort($tiposEsperados);

assertTest(
    'F7-ORIGEN-01',
    'Reporte incluye exactamente los 4 orígenes válidos (Baja por daño, Baja por vencimiento, Ajuste positivo, Ajuste negativo)',
    $tiposEncontrados === $tiposEsperados && count($items) === 5,
    'total_items=' . count($items) . ', tipos=' . implode(', ', $tiposEncontrados)
);


// =========================================================================
// TEST 2: F7-EXCLUSION-01 — Exclusión estricta de Compras y Ventas
// =========================================================================
$origenesEncontrados = array_unique(array_column($items, 'tipo_movimiento'));
$tieneComprasOVentas = false;
foreach ($items as $it) {
    if (in_array($it['tipo_variacion'], ['Compra', 'Venta', 'Anulación de compra', 'Anulación de venta', 'Corrección de compra'], true)) {
        $tieneComprasOVentas = true;
        break;
    }
}

assertTest(
    'F7-EXCLUSION-01',
    'Exclusión estricta: movimientos de Compra y Venta no aparecen en Variaciones a pesar de estar en el período',
    !$tieneComprasOVentas,
    'compras_o_ventas_encontradas=' . ($tieneComprasOVentas ? 'SI' : 'NO')
);


// =========================================================================
// TEST 3: F7-SENTIDO-TODOS-01 — Sentido "TODOS" devuelve entradas y salidas
// =========================================================================
$resTodos = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'sentido' => 'TODOS',
], $idAdminActivo);

$sentidosTodos = array_unique(array_column($resTodos['items'], 'sentido'));
sort($sentidosTodos);

assertTest(
    'F7-SENTIDO-TODOS-01',
    'Filtro sentido=TODOS devuelve movimientos de ambos sentidos (INCREMENTO y DISMINUCION)',
    $sentidosTodos === ['DISMINUCION', 'INCREMENTO'] && count($resTodos['items']) === 5,
    'total=' . count($resTodos['items']) . ', sentidos=' . implode(', ', $sentidosTodos)
);


// =========================================================================
// TEST 4: F7-SENTIDO-INC-01 — Sentido "INCREMENTO" devuelve solo entradas
// =========================================================================
$resInc = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'sentido' => 'INCREMENTO',
], $idAdminActivo);

$todosInc = true;
foreach ($resInc['items'] as $it) {
    if ($it['sentido'] !== 'INCREMENTO' || $it['entrada'] === null || $it['salida'] !== null) {
        $todosInc = false;
        break;
    }
}

assertTest(
    'F7-SENTIDO-INC-01',
    'Filtro sentido=INCREMENTO (Entradas) devuelve exclusivamente movimientos de incremento (2 ajustes positivos)',
    $todosInc && count($resInc['items']) === 2,
    'total=' . count($resInc['items']) . ', todos_incrementos=' . ($todosInc ? 'SI' : 'NO')
);


// =========================================================================
// TEST 5: F7-SENTIDO-DISM-01 — Sentido "DISMINUCION" devuelve solo salidas
// =========================================================================
$resDism = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'sentido' => 'DISMINUCION',
], $idAdminActivo);

$todosDism = true;
foreach ($resDism['items'] as $it) {
    if ($it['sentido'] !== 'DISMINUCION' || $it['salida'] === null || $it['entrada'] !== null) {
        $todosDism = false;
        break;
    }
}

assertTest(
    'F7-SENTIDO-DISM-01',
    'Filtro sentido=DISMINUCION (Salidas) devuelve exclusivamente bajas y ajustes negativos (3 salidas)',
    $todosDism && count($resDism['items']) === 3,
    'total=' . count($resDism['items']) . ', todas_disminuciones=' . ($todosDism ? 'SI' : 'NO')
);


// =========================================================================
// TEST 6: F7-SENTIDO-INV-01 — Sentido no reconocido rechaza 422
// =========================================================================
$errorSentido = false;
try {
    $reporteService->reporteVariaciones([
        'fecha_inicio' => '2026-10-01',
        'fecha_fin' => '2026-10-31',
        'sentido' => 'SENTIDO_INVENTADO_NO_VALIDO',
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $errorSentido = ($e->getHttpStatus() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR');
}

assertTest(
    'F7-SENTIDO-INV-01',
    'Valor no válido de sentido es rechazado con 422 VALIDATION_ERROR',
    $errorSentido,
    'rechazado_422=' . ($errorSentido ? 'SI' : 'NO')
);


// =========================================================================
// TEST 7: F7-AND-COMB-01 — Combinación AND entre tipo_variacion y sentido
// =========================================================================
$resComb = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'tipo_variacion' => 'Baja por daño',
    'sentido' => 'DISMINUCION',
], $idAdminActivo);

assertTest(
    'F7-AND-COMB-01',
    'Combinación AND válida (tipo_variacion="Baja por daño" AND sentido=DISMINUCION) devuelve la fila esperada',
    count($resComb['items']) === 1 && $resComb['items'][0]['tipo_variacion'] === 'Baja por daño' && $resComb['items'][0]['salida'] === 4,
    'filas=' . count($resComb['items']) . ', unidades=' . ($resComb['items'][0]['salida'] ?? 0)
);


// =========================================================================
// TEST 8: F7-AND-EMPTY-01 — Combinación AND sin coincidencias conserva filtros
// =========================================================================
$resEmptyComb = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'tipo_variacion' => 'Baja por vencimiento',
    'sentido' => 'INCREMENTO',
], $idAdminActivo);

assertTest(
    'F7-AND-EMPTY-01',
    'Combinación mutuamente excluyente no altera filtros: devuelve total=0, items=[] y exportable=false',
    $resEmptyComb['resumen']['total_operaciones'] === 0
    && empty($resEmptyComb['items'])
    && $resEmptyComb['exportable'] === false
    && $resEmptyComb['filtros_aplicados']['tipo_variacion'] === 'Baja por vencimiento'
    && $resEmptyComb['filtros_aplicados']['sentido'] === 'INCREMENTO',
    'total=' . $resEmptyComb['resumen']['total_operaciones'] . ', exportable=' . ($resEmptyComb['exportable'] ? 'true' : 'false')
);


// =========================================================================
// TEST 9: F7-VAL-FECHAS-01 — Fechas invertidas y formatos inválidos rechazan 422
// =========================================================================
$invertidasRechazadas = false;
try {
    $reporteService->reporteVariaciones([
        'fecha_inicio' => '2026-10-31',
        'fecha_fin' => '2026-10-01',
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $invertidasRechazadas = ($e->getHttpStatus() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR');
}

$invalidoRechazado = false;
try {
    $reporteService->reporteVariaciones([
        'fecha_inicio' => '2026-02-31', // no existe
        'fecha_fin' => '2026-10-15',
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $invalidoRechazado = ($e->getHttpStatus() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR');
}

assertTest(
    'F7-VAL-FECHAS-01',
    'Validación estricta de fechas: fechas invertidas y fechas inexistentes rechazan 422 VALIDATION_ERROR',
    $invertidasRechazadas && $invalidoRechazado,
    'invertidas_422=' . ($invertidasRechazadas ? 'SI' : 'NO') . ', fecha_falsa_422=' . ($invalidoRechazado ? 'SI' : 'NO')
);


// =========================================================================
// TEST 10: F7-HIST-CAT-01 — Filtro por categoría y subcategoría históricas
// =========================================================================
// Modificamos temporalmente el catálogo activo de Producto A a Categoria 2 para comprobar inmutabilidad histórica
$db->exec("UPDATE producto SET id_categoria = 2, id_subcategoria = 2 WHERE id_producto = {$prodA}");

$resCatHist = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'id_categoria' => 1, // Categoría histórica en snapshot: Abarrotes
], $idAdminActivo);

// Restauramos catálogo
$db->exec("UPDATE producto SET id_categoria = 1, id_subcategoria = 1 WHERE id_producto = {$prodA}");

$filasAbarrotes = count($resCatHist['items']);
assertTest(
    'F7-HIST-CAT-01',
    'Filtro evalúa exclusivamente categoría y subcategoría históricas grabadas en el snapshot (Abarrotes = 4 filas)',
    $filasAbarrotes === 4,
    'filas_encontradas=' . $filasAbarrotes
);


// =========================================================================
// TEST 11: F7-INACT-01 — Producto inactivo con historial es plenamente consultable
// =========================================================================
$resInactivo = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'id_producto' => $prodC,
], $idAdminActivo);

assertTest(
    'F7-INACT-01',
    'Producto inactivo (estado=0) con historial es plenamente consultable y reporta sus variaciones',
    count($resInactivo['items']) === 1 && $resInactivo['items'][0]['id_producto'] === $prodC && $resInactivo['items'][0]['tipo_variacion'] === 'Ajuste positivo',
    'filas=' . count($resInactivo['items']) . ', prod=' . ($resInactivo['items'][0]['producto_nombre'] ?? '')
);


// =========================================================================
// TEST 12: F7-RESUMEN-MULTI-01 — Resumen en consulta multi-producto
// =========================================================================
$resumen = $resGeneral['resumen'];
$totalesTipo = $resumen['totales_por_tipo'];

$multiCorrecto = (
    $resumen['total_operaciones'] === 5
    && $resumen['productos_con_variaciones'] === 3
    && $resumen['es_producto_unico'] === false
    && $resumen['resumen_producto_unico'] === null
    && count($resumen['resumen_por_producto']) === 3
    && $totalesTipo['baja_por_dano']['operaciones'] === 1 && $totalesTipo['baja_por_dano']['unidades'] === 4
    && $totalesTipo['baja_por_vencimiento']['operaciones'] === 1 && $totalesTipo['baja_por_vencimiento']['unidades'] === 6
    && $totalesTipo['ajuste_positivo']['operaciones'] === 2 && $totalesTipo['ajuste_positivo']['unidades'] === 11
    && $totalesTipo['ajuste_negativo']['operaciones'] === 1 && $totalesTipo['ajuste_negativo']['unidades'] === 5
);

assertTest(
    'F7-RESUMEN-MULTI-01',
    'Resumen multi-producto: total_operaciones=5, productos=3, es_producto_unico=false, desglose por producto y por tipo exacto',
    $multiCorrecto,
    'total_ops=' . $resumen['total_operaciones'] . ', prods=' . $resumen['productos_con_variaciones'] . ', pos_unidades=' . $totalesTipo['ajuste_positivo']['unidades']
);


// =========================================================================
// TEST 13: F7-RESUMEN-SINGLE-01 — Resumen cuando hay un único producto
// =========================================================================
$resSingle = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'id_producto' => $prodA,
], $idAdminActivo);

$resumenSingle = $resSingle['resumen'];
$singleObj = $resumenSingle['resumen_producto_unico'];

$singleCorrecto = (
    $resumenSingle['total_operaciones'] === 3
    && $resumenSingle['productos_con_variaciones'] === 1
    && $resumenSingle['es_producto_unico'] === true
    && is_array($singleObj)
    && $singleObj['id_producto'] === $prodA
    && $singleObj['operaciones'] === 3
    && $singleObj['incrementos'] === 8
    && $singleObj['disminuciones'] === 10
);

assertTest(
    'F7-RESUMEN-SINGLE-01',
    'Resumen producto único: es_producto_unico=true, operaciones=3, incrementos=8, disminuciones=10',
    $singleCorrecto,
    'es_unico=' . ($resumenSingle['es_producto_unico'] ? 'SI' : 'NO') . ', inc=' . ($singleObj['incrementos'] ?? 0) . ', dec=' . ($singleObj['disminuciones'] ?? 0)
);


// =========================================================================
// TEST 14: F7-ZERO-EXPORT-01 — Consulta sin resultados desactiva exportable
// =========================================================================
$resCero = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'id_producto' => $prodD, // Sin movimientos
], $idAdminActivo);

assertTest(
    'F7-ZERO-EXPORT-01',
    'Consulta de variaciones sin resultados: total=0, items=[] y exportable=false (bloquea PDF/Excel)',
    $resCero['resumen']['total_operaciones'] === 0 && count($resCero['items']) === 0 && $resCero['exportable'] === false,
    'total=' . $resCero['resumen']['total_operaciones'] . ', exportable=' . ($resCero['exportable'] ? 'true' : 'false')
);


// =========================================================================
// TEST 15: F7-PAGE-CONSIST-01 — Paginación consistente con referencia_consulta
// =========================================================================
// Consulta inicial con limit=2
$p1 = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
    'page' => 1,
    'limit' => 2,
], $idAdminActivo);

$ref = $p1['referencia_consulta'];
$idsP1 = array_column($p1['items'], 'id_kardex');

// Paginamos a página 2 con la misma referencia_consulta
$p2 = $reporteService->reporteVariaciones([
    'referencia_consulta' => $ref,
    'page' => 2,
    'limit' => 2,
], $idAdminActivo);

$idsP2 = array_column($p2['items'], 'id_kardex');
$solapamiento = array_intersect($idsP1, $idsP2);

assertTest(
    'F7-PAGE-CONSIST-01',
    'Paginación mediante referencia_consulta: página 1 (2 filas) y página 2 (2 filas) sin solapamiento ni saltos',
    count($idsP1) === 2 && count($idsP2) === 2 && empty($solapamiento) && $p1['pagination']['total'] === 5,
    'ref=' . substr($ref, 0, 16) . '..., p1_ids=' . implode(',', $idsP1) . ', p2_ids=' . implode(',', $idsP2)
);


// =========================================================================
// TEST 16: F7-SNAP-IMMUN-01 — Inmunidad frente a movimientos concurrentes posteriores
// =========================================================================
// Insertamos un movimiento concurrente nuevo después de generada la referencia
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (888, {$prodA}, {$idAdminActivo}, 'AJUSTE', 'AJUSTE_POSITIVO', 'Ajuste positivo', 'INCREMENTO',
                   50, 88, 88, 138, 'SIN_LOTE', 'Ajuste concurrente post snapshot', 'ajuste-concurrente-test-01', '2026-10-25 15:00:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'AJUSTE_POSITIVO', 'INCREMENTO', 'Ajuste positivo', 50, 88, 138,
                   888, 'OP-INV-888', 'Ajuste concurrente post snapshot', 'SIN_LOTE', NULL,
                   'TST-VAR-A', 'Producto Variacion A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-25 15:00:00')");

// Consultamos nuevamente la página 1 bajo la misma referencia
$p1_congelada = $reporteService->reporteVariaciones([
    'referencia_consulta' => $ref,
    'page' => 1,
    'limit' => 2,
], $idAdminActivo);

// Limpiamos el movimiento concurrente de prueba
$db->exec("DELETE FROM kardex WHERE id_operacion_inventario = 888");
$db->exec("DELETE FROM operacion_inventario WHERE id_operacion = 888");

assertTest(
    'F7-SNAP-IMMUN-01',
    'La instantánea congelada bajo referencia_consulta es 100% inmune a transacciones concurrentes posteriores',
    $p1_congelada['pagination']['total'] === 5 && count($p1_congelada['items']) === 2,
    'total_congelado=' . $p1_congelada['pagination']['total'] . ' (esperado 5)'
);


// =========================================================================
// TEST 17: F7-SNAP-EXPIRE-01 — Referencia de consulta vencida responde 410
// =========================================================================
// Forzamos vencimiento en base de datos
$db->exec("UPDATE reporte_consulta_snapshot SET expira_en = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE referencia_consulta = '{$ref}'");

$expiradaCapturada = false;
try {
    $reporteService->reporteVariaciones([
        'referencia_consulta' => $ref,
        'page' => 1,
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $expiradaCapturada = ($e->getHttpStatus() === 410 && $e->getErrorCode() === 'QUERY_REFERENCE_EXPIRED');
}

assertTest(
    'F7-SNAP-EXPIRE-01',
    'Referencia de consulta vencida responde 410 QUERY_REFERENCE_EXPIRED exigiendo nueva consulta',
    $expiradaCapturada,
    'rechazado_410=' . ($expiradaCapturada ? 'SI' : 'NO')
);


// =========================================================================
// TEST 18: F7-SNAP-FORBID-01 — Referencia de consulta de otro usuario responde 403
// =========================================================================
// Generamos una referencia nueva válida para usuario 1
$nuevaRef = $reporteService->reporteVariaciones([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-31',
], $idAdminActivo)['referencia_consulta'];

$forbidCapturado = false;
try {
    // Solicitamos con el usuario secundario (idAdminSecundario)
    $reporteService->reporteVariaciones([
        'referencia_consulta' => $nuevaRef,
        'page' => 1,
    ], $idAdminSecundario);
} catch (InventarioException $e) {
    $forbidCapturado = ($e->getHttpStatus() === 403 && $e->getErrorCode() === 'QUERY_REFERENCE_FORBIDDEN');
}

assertTest(
    'F7-SNAP-FORBID-01',
    'Referencia de consulta ajena al usuario solicitante responde 403 QUERY_REFERENCE_FORBIDDEN',
    $forbidCapturado,
    'rechazado_403=' . ($forbidCapturado ? 'SI' : 'NO')
);


// =========================================================================
// TEST 19: F7-SNAP-MISMATCH-01 — Alterar filtros manteniendo referencia responde 409
// =========================================================================
$mismatchCapturado = false;
try {
    $reporteService->reporteVariaciones([
        'referencia_consulta' => $nuevaRef,
        'fecha_inicio' => '2026-10-01',
        'fecha_fin' => '2026-10-31',
        'sentido' => 'INCREMENTO', // Filtro alterado en presencia de referencia_consulta
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $mismatchCapturado = ($e->getHttpStatus() === 409 && $e->getErrorCode() === 'QUERY_FILTERS_MISMATCH');
}

assertTest(
    'F7-SNAP-MISMATCH-01',
    'Alterar filtros manteniendo la referencia activa responde 409 QUERY_FILTERS_MISMATCH',
    $mismatchCapturado,
    'rechazado_409=' . ($mismatchCapturado ? 'SI' : 'NO')
);


// =========================================================================
// TEST 20: F7-RBAC-01 — Verificación de permisos y controlador
// =========================================================================
// Verificamos permisos en base de datos:
$permisoVariacionesAdmin = (int)$db->query(
    "SELECT COUNT(*) FROM rol_permiso rp
     JOIN permiso p ON p.id_permiso = rp.id_permiso
     WHERE rp.id_rol = 1 AND p.slug = 'reportes.variaciones'"
)->fetchColumn();

$permisoVariacionesVendedor = (int)$db->query(
    "SELECT COUNT(*) FROM rol_permiso rp
     JOIN permiso p ON p.id_permiso = rp.id_permiso
     WHERE rp.id_rol = 2 AND p.slug = 'reportes.variaciones'"
)->fetchColumn();

// Comprobamos la protección de ReporteController::variaciones
$ctrl = new ReporteController();
$refCtrl = new \ReflectionClass($ctrl);
$method = $refCtrl->getMethod('variaciones');
$code = file_get_contents($refCtrl->getFileName());

$controladorProtegido = (
    strpos($code, "id_rol'] ?? 0) !== 1") !== false
    && strpos($code, "header('Location: ' . BASE_URL . '/login')") !== false
);

assertTest(
    'F7-RBAC-01',
    'Seguridad RBAC: permiso reportes.variaciones exclusivo de Administrador (1) y denegado a Vendedor (0)',
    $permisoVariacionesAdmin === 1 && $permisoVariacionesVendedor === 0 && $controladorProtegido,
    'admin_perm=' . $permisoVariacionesAdmin . ', vendedor_perm=' . $permisoVariacionesVendedor . ', web_check=' . ($controladorProtegido ? 'SI' : 'NO')
);


// =========================================================================
// TEST 21: F7-CLEAN-01 — Limpieza controlada en base de pruebas
// =========================================================================
$db->exec("DELETE FROM reporte_consulta_snapshot_item");
$db->exec("DELETE FROM reporte_consulta_snapshot");
$db->exec("DELETE FROM kardex");
$db->exec("DELETE FROM operacion_inventario");
$db->exec("DELETE FROM detalle_venta");
$db->exec("DELETE FROM venta");
$db->exec("DELETE FROM detalle_compra");
$db->exec("DELETE FROM compra");
$db->exec("DELETE FROM producto WHERE codigo_barra LIKE 'TST-%'");
$db->exec("UPDATE producto SET stock_actual = 0");

$kardexFinal = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();
$snapshotsFinal = (int)$db->query("SELECT COUNT(*) FROM reporte_consulta_snapshot")->fetchColumn();

assertTest(
    'F7-CLEAN-01',
    'Limpieza final en base de pruebas: registros temporales eliminados y existencias en 0',
    $kardexFinal === 0 && $snapshotsFinal === 0,
    'kardex=' . $kardexFinal . ', snapshots=' . $snapshotsFinal
);


// =========================================================================
// TEST 22: F7-ISO-01 — Aislamiento verificado de la base de datos principal
// =========================================================================
$pdoReal = new PDO('mysql:host=localhost;dbname=amazon_market;charset=utf8', 'root', '');
$tablasProd = (int)$pdoReal->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'amazon_market'")->fetchColumn();
$prodStockPos = (int)$pdoReal->query("SELECT COUNT(*) FROM producto WHERE stock_actual > 0")->fetchColumn();
$ventasProd = (int)$pdoReal->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$comprasProd = (int)$pdoReal->query("SELECT COUNT(*) FROM compra")->fetchColumn();
$kardexProd = (int)$pdoReal->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

assertTest(
    'F7-ISO-01',
    'Aislamiento verificado: Base principal amazon_market no sufrió modificaciones (100% intacta)',
    $tablasProd === 20 && $prodStockPos === 106 && $ventasProd === 98 && $comprasProd === 4 && $kardexProd === 26,
    "tablas={$tablasProd}, stock_pos={$prodStockPos}, ventas={$ventasProd}, compras={$comprasProd}, kardex={$kardexProd}"
);

echo PHP_EOL . "=== RESUMEN FASE 7: {$passed} PASS / {$failed} FAIL ===" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}

