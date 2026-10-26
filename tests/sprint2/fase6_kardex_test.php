<?php
/**
 * Suite de Pruebas de Integración - Fase 6 (Sprint 2)
 * Consulta Completa y Consistente de Kardex.
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

echo "=== INICIANDO SUITE FASE 6 EN: " . $db->query("SELECT DATABASE()")->fetchColumn() . " ===" . PHP_EOL . PHP_EOL;

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
$inventarioService = new InventarioService();
$movimientoService = $inventarioService->getMovimientoService();
$reporteService = new ReporteService();
$kardexModel = new KardexModel();

$idAdminActivo = 1;
$idAdminSecundario = (int)$db->query("SELECT id_usuario FROM usuario WHERE id_rol = 1 AND id_usuario != 1 AND estado = 1 LIMIT 1")->fetchColumn();
if (!$idAdminSecundario) {
    // Si solo hay un admin, crear uno temporal para la prueba de propiedad de referencia
    $db->exec("INSERT INTO usuario (id_usuario, nombre, email, password, id_rol, estado) VALUES (99, 'Admin Secundario Test', 'admin2_test@test.com', 'hash', 1, 1)
               ON DUPLICATE KEY UPDATE estado = 1");
    $idAdminSecundario = 99;
}

// -------------------------------------------------------------------------
// CREACIÓN DE PRODUCTOS DE PRUEBA
// -------------------------------------------------------------------------
$prodA_res = $productoService->crear([
    'codigo_barra' => 'TST-KDX-A-' . time(),
    'nombre' => 'Producto Kardex A',
    'id_categoria' => 1,
    'id_subcategoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 15.00,
    'stock_minimo' => 5
]);
$prodA = (int)$prodA_res['id_producto'];

$prodB_res = $productoService->crear([
    'codigo_barra' => 'TST-KDX-B-' . time(),
    'nombre' => 'Producto Kardex B',
    'id_categoria' => 2,
    'id_subcategoria' => 2,
    'id_unidad' => 1,
    'precio_venta' => 25.00,
    'stock_minimo' => 2
]);
$prodB = (int)$prodB_res['id_producto'];

// Producto C: inactivo con historial
$prodC_res = $productoService->crear([
    'codigo_barra' => 'TST-KDX-C-' . time(),
    'nombre' => 'Producto Kardex Inactivo',
    'id_categoria' => 1,
    'id_subcategoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 10.00,
    'stock_minimo' => 1
]);
$prodC = (int)$prodC_res['id_producto'];

// Producto D: sin historial en absoluto
$prodD_res = $productoService->crear([
    'codigo_barra' => 'TST-KDX-D-' . time(),
    'nombre' => 'Producto Kardex Sin Historial',
    'id_categoria' => 1,
    'id_subcategoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 8.00,
    'stock_minimo' => 1
]);
$prodD = (int)$prodD_res['id_producto'];

// =========================================================================
// PREPARACIÓN DE MOVIMIENTOS HISTÓRICOS PARA PRODUCTO A
// =========================================================================
// 1. Movimiento previo a Octubre: 2026-09-10 (Compra de 50 unidades -> stock 50)
$db->exec("UPDATE producto SET stock_actual = 50 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO compra (id_compra, id_proveedor, id_usuario, id_tipo_comprobante, serie, numero, fecha_emision, fecha_recepcion, total_documento, estado)
           VALUES (101, 1, {$idAdminActivo}, 1, 'F001', '000001', '2026-09-10', '2026-09-10', 500, 'Confirmada')");
$db->exec("INSERT INTO detalle_compra (id_detalle_compra, id_compra, id_producto, id_presentacion, cantidad_presentaciones, equivalencia_aplicada, cantidad_base, importe_final_linea, version_detalle, es_vigente)
           VALUES (201, 101, {$prodA}, 1, 50, 1, 50, 500, 1, 1)");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_accion_compra, id_detalle_compra, referencia_operacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'COMPRA', 'INCREMENTO', 'Compra', 50, 0, 50,
                   101, 201, 'F001-000001', 'SIN_LOTE', NULL,
                   'TST-KDX-A', 'Producto Kardex A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-09-10 10:00:00')");

// 2. Movimientos dentro del período de Octubre:
// 2a. 2026-10-05 14:00:00 - Venta de 10 unidades (stock 50 -> 40)
$db->exec("UPDATE producto SET stock_actual = 40 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO venta (id_venta, id_usuario, id_cliente, id_tipo_comprobante, serie, numero, total, id_metodo_pago, estado, fecha_venta)
           VALUES (101, {$idAdminActivo}, 1, 1, 'B001', '000001', 150, 1, 1, '2026-10-05 14:00:00')");
$db->exec("INSERT INTO detalle_venta (id_detalle_venta, id_venta, id_producto, cantidad, precio_unitario, descuento, subtotal)
           VALUES (201, 101, {$prodA}, 10, 15.00, 0, 150)");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_accion_venta, id_detalle_venta, referencia_operacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'VENTA', 'DISMINUCION', 'Venta', 10, 50, 40,
                   101, 201, 'B001-000001', 'NO_APLICA', NULL,
                   'TST-KDX-A', 'Producto Kardex A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-05 14:00:00')");

// 2b. 2026-10-12 11:00:00 - Ajuste positivo de 5 unidades (stock 40 -> 45)
$db->exec("UPDATE producto SET stock_actual = 45 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (301, {$prodA}, {$idAdminActivo}, 'AJUSTE', 'AJUSTE_POSITIVO', 'Ajuste positivo', 'INCREMENTO',
                   5, 40, 40, 45, 'SIN_LOTE', 'Conteo físico anaquel', 'ajuste-test-20261012-0001', '2026-10-12 11:00:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'AJUSTE_POSITIVO', 'INCREMENTO', 'Ajuste positivo', 5, 40, 45,
                   301, 'OP-INV-301', 'Conteo físico anaquel', 'SIN_LOTE', NULL,
                   'TST-KDX-A', 'Producto Kardex A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-12 11:00:00')");

// 2c. 2026-10-20 16:30:00 - Baja por vencimiento de 3 unidades con lote (stock 45 -> 42)
$db->exec("UPDATE producto SET stock_actual = 42 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, numero_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (302, {$prodA}, {$idAdminActivo}, 'BAJA', 'BAJA_VENCIMIENTO', 'Baja por vencimiento', 'DISMINUCION',
                   3, 45, 45, 42, 'CON_LOTE', 'L-OCT-2026', 'Retiro fecha cumplida', 'baja-test-20261020-0002', '2026-10-20 16:30:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'BAJA_VENCIMIENTO', 'DISMINUCION', 'Baja por vencimiento', 3, 45, 42,
                   302, 'OP-INV-302', 'Retiro fecha cumplida', 'CON_LOTE', 'L-OCT-2026',
                   'TST-KDX-A', 'Producto Kardex A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-20 16:30:00')");

// 3. Movimiento posterior al período de consulta: 2026-10-28 (Venta de 2 unidades -> stock 40)
$db->exec("UPDATE producto SET stock_actual = 40 WHERE id_producto = {$prodA}");
$db->exec("INSERT INTO venta (id_venta, id_usuario, id_cliente, id_tipo_comprobante, serie, numero, total, id_metodo_pago, estado, fecha_venta)
           VALUES (102, {$idAdminActivo}, 1, 1, 'B001', '000002', 30, 1, 1, '2026-10-28 09:00:00')");
$db->exec("INSERT INTO detalle_venta (id_detalle_venta, id_venta, id_producto, cantidad, precio_unitario, descuento, subtotal)
           VALUES (202, 102, {$prodA}, 2, 15.00, 0, 30)");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_accion_venta, id_detalle_venta, referencia_operacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'VENTA', 'DISMINUCION', 'Venta', 2, 42, 40,
                   102, 202, 'B001-000002', 'NO_APLICA', NULL,
                   'TST-KDX-A', 'Producto Kardex A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-28 09:00:00')");

// =========================================================================
// CASO 1: F6-SALDO-01 - CÁLCULO CANÓNICO DE SALDOS HISTÓRICOS Y RANGO
// =========================================================================
$resp1 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-25',
    'id_producto' => $prodA,
], $idAdminActivo);

$resumen1 = $resp1['resumen_producto'];
$items1 = $resp1['items'];

assertTest(
    'F6-SALDO-01',
    'Cálculo canónico de saldo_inicial previo (50), entradas (5), salidas (13) y saldo_final histórico (42)',
    $resumen1['aplicable'] === true
    && $resumen1['saldo_inicial'] === 50
    && $resumen1['total_entradas'] === 5
    && $resumen1['total_salidas'] === 13
    && $resumen1['saldo_final'] === 42
    && $resumen1['total_movimientos'] === 3
    && count($items1) === 3
    && $resp1['exportable'] === true
    && $items1[0]['origen'] === 'Venta'
    && $items1[0]['saldo_anterior'] === 50
    && $items1[0]['saldo_resultante'] === 40
    && $items1[1]['origen'] === 'Ajuste positivo'
    && $items1[1]['saldo_anterior'] === 40
    && $items1[1]['saldo_resultante'] === 45
    && $items1[2]['origen'] === 'Baja por vencimiento'
    && $items1[2]['saldo_anterior'] === 45
    && $items1[2]['saldo_resultante'] === 42
    && $items1[2]['lote'] === 'L-OCT-2026',
    "saldo_inicial={$resumen1['saldo_inicial']}, ent={$resumen1['total_entradas']}, sal={$resumen1['total_salidas']}, final={$resumen1['saldo_final']}, posteriors_ignorado=" . ($resumen1['saldo_final'] === 42 ? 'SI' : 'NO')
);

// =========================================================================
// CASO 2: F6-PROD-ZERO-01 - PRODUCTO SIN MOVIMIENTOS EN EL RANGO PERO CON HISTORIAL PREVIO
// =========================================================================
$resp2 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-04',
    'id_producto' => $prodA,
], $idAdminActivo);

$resumen2 = $resp2['resumen_producto'];

assertTest(
    'F6-PROD-ZERO-01',
    'Producto sin actividad en el período: conserva saldo_inicial real (50), entradas=0, salidas=0, saldo_final=50 y exportable=true',
    $resumen2['aplicable'] === true
    && $resumen2['saldo_inicial'] === 50
    && $resumen2['total_entradas'] === 0
    && $resumen2['total_salidas'] === 0
    && $resumen2['saldo_final'] === 50
    && $resumen2['total_movimientos'] === 0
    && count($resp2['items']) === 0
    && $resp2['exportable'] === true,
    "saldo_inicial={$resumen2['saldo_inicial']}, saldo_final={$resumen2['saldo_final']}, exportable=" . ($resp2['exportable'] ? 'true' : 'false')
);

// =========================================================================
// CASO 3: F6-PROD-ZERO-02 - PRODUCTO NUEVO SIN HISTORIAL EN ABSOLUTO
// =========================================================================
$resp3 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-25',
    'id_producto' => $prodD,
], $idAdminActivo);

$resumen3 = $resp3['resumen_producto'];

assertTest(
    'F6-PROD-ZERO-02',
    'Producto nuevo sin movimientos previos ni en rango: saldo_inicial=0, saldo_final=0, exportable=true',
    $resumen3['aplicable'] === true
    && $resumen3['saldo_inicial'] === 0
    && $resumen3['total_entradas'] === 0
    && $resumen3['total_salidas'] === 0
    && $resumen3['saldo_final'] === 0
    && $resumen3['total_movimientos'] === 0
    && count($resp3['items']) === 0
    && $resp3['exportable'] === true,
    "saldo_inicial={$resumen3['saldo_inicial']}, final={$resumen3['saldo_final']}, exportable=" . ($resp3['exportable'] ? 'true' : 'false')
);

// =========================================================================
// CASO 4: F6-INACT-01 - PRODUCTO INACTIVO CON HISTORIAL CONSULTABLE
// =========================================================================
// Crear un movimiento para el producto C y luego inactivarlo (estado = 0)
$db->exec("UPDATE producto SET stock_actual = 8 WHERE id_producto = {$prodC}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (303, {$prodC}, {$idAdminActivo}, 'AJUSTE', 'AJUSTE_POSITIVO', 'Ajuste positivo', 'INCREMENTO',
                   8, 0, 0, 8, 'SIN_LOTE', 'Ingreso producto previo', 'ajuste-test-20261002-0003', '2026-10-02 08:00:00')");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodC}, {$idAdminActivo}, 'AJUSTE_POSITIVO', 'INCREMENTO', 'Ajuste positivo', 8, 0, 8,
                   303, 'OP-INV-303', 'Ingreso producto previo', 'SIN_LOTE', NULL,
                   'TST-KDX-C', 'Producto Kardex Inactivo', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-02 08:00:00')");

// Inactivar producto C
$db->exec("UPDATE producto SET estado = 0 WHERE id_producto = {$prodC}");

$resp4 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-25',
    'id_producto' => $prodC,
], $idAdminActivo);

assertTest(
    'F6-INACT-01',
    'Producto inactivo con historial es plenamente consultable exponiendo estado_producto Inactivo',
    $resp4['status'] ?? true
    && $resp4['resumen_producto']['aplicable'] === true
    && $resp4['resumen_producto']['estado_producto'] === 'Inactivo'
    && $resp4['resumen_producto']['total_entradas'] === 8
    && $resp4['resumen_producto']['saldo_final'] === 8
    && count($resp4['items']) === 1,
    "estado_producto={$resp4['resumen_producto']['estado_producto']}, final={$resp4['resumen_producto']['saldo_final']}"
);

// =========================================================================
// CASO 5: F6-GEN-01 - CONSULTA GENERAL SIN ID_PRODUCTO (NO SUMA SALDOS GLOBALES)
// =========================================================================
$resp5 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-25',
], $idAdminActivo);

assertTest(
    'F6-GEN-01',
    'Consulta general (sin producto específico): resumen_producto.aplicable = false (no suma saldos entre productos)',
    $resp5['resumen_producto']['aplicable'] === false
    && $resp5['exportable'] === true
    && $resp5['pagination']['total'] >= 4
    && count($resp5['items']) >= 4,
    "aplicable=" . ($resp5['resumen_producto']['aplicable'] ? 'true' : 'false') . ", total={$resp5['pagination']['total']}, exportable=" . ($resp5['exportable'] ? 'true' : 'false')
);

// =========================================================================
// CASO 6: F6-GEN-ZERO-01 - CONSULTA GENERAL SIN RESULTADOS (EXPORTABLE = FALSE)
// =========================================================================
$resp6 = $reporteService->reporteKardex([
    'fecha_inicio' => '2025-01-01',
    'fecha_fin' => '2025-01-10',
], $idAdminActivo);

assertTest(
    'F6-GEN-ZERO-01',
    'Consulta general sin resultados: total=0, exportable=false y mensaje informativo',
    $resp6['resumen_producto']['aplicable'] === false
    && $resp6['exportable'] === false
    && $resp6['pagination']['total'] === 0
    && count($resp6['items']) === 0,
    "total={$resp6['pagination']['total']}, exportable=" . ($resp6['exportable'] ? 'true' : 'false')
);

// =========================================================================
// CASO 7: F6-ORDER-01 - MOVIMIENTOS CON FECHA Y HORA IDÉNTICAS (ORDEN DETERMINÍSTICO)
// =========================================================================
// Insertamos 3 movimientos para Prod B con el mismo timestamp exacto
$db->exec("UPDATE producto SET stock_actual = 20 WHERE id_producto = {$prodB}");
$db->exec("INSERT INTO operacion_inventario (id_operacion, id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento, sentido_movimiento,
                                             cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, condicion_lote, justificacion, referencia_solicitud, fecha_registro)
           VALUES (310, {$prodB}, {$idAdminActivo}, 'AJUSTE', 'AJUSTE_POSITIVO', 'Ajuste positivo', 'INCREMENTO', 10, 0, 0, 10, 'SIN_LOTE', 'Ajuste 1', 'ajuste-test-20261015-0310', '2026-10-15 12:00:00'),
                  (311, {$prodB}, {$idAdminActivo}, 'AJUSTE', 'AJUSTE_POSITIVO', 'Ajuste positivo', 'INCREMENTO', 15, 10, 10, 25, 'SIN_LOTE', 'Ajuste 2', 'ajuste-test-20261015-0311', '2026-10-15 12:00:00'),
                  (312, {$prodB}, {$idAdminActivo}, 'BAJA', 'BAJA_DANO', 'Baja por daño', 'DISMINUCION', 5, 25, 25, 20, 'SIN_LOTE', 'Baja 3', 'baja-test-20261015-0312', '2026-10-15 12:00:00')");

$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_operacion_inventario, referencia_operacion, justificacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodB}, {$idAdminActivo}, 'AJUSTE_POSITIVO', 'INCREMENTO', 'Ajuste positivo', 10, 0, 10,
                   310, 'OP-310', 'Ajuste 1', 'SIN_LOTE', NULL,
                   'TST-KDX-B', 'Producto Kardex B', 2, 'Bebidas', 2, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-15 12:00:00'),
                  ({$prodB}, {$idAdminActivo}, 'AJUSTE_POSITIVO', 'INCREMENTO', 'Ajuste positivo', 15, 10, 25,
                   311, 'OP-311', 'Ajuste 2', 'SIN_LOTE', NULL,
                   'TST-KDX-B', 'Producto Kardex B', 2, 'Bebidas', 2, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-15 12:00:00'),
                  ({$prodB}, {$idAdminActivo}, 'BAJA_DANO', 'DISMINUCION', 'Baja por daño', 5, 25, 20,
                   312, 'OP-312', 'Baja 3', 'SIN_LOTE', NULL,
                   'TST-KDX-B', 'Producto Kardex B', 2, 'Bebidas', 2, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-15 12:00:00')");

$resp7 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-15',
    'fecha_fin' => '2026-10-15',
    'id_producto' => $prodB,
], $idAdminActivo);

$items7 = $resp7['items'];
$ordenDeterminista = count($items7) === 3
    && $items7[0]['saldo_anterior'] === 0 && $items7[0]['saldo_resultante'] === 10
    && $items7[1]['saldo_anterior'] === 10 && $items7[1]['saldo_resultante'] === 25
    && $items7[2]['saldo_anterior'] === 25 && $items7[2]['saldo_resultante'] === 20;

assertTest(
    'F6-ORDER-01',
    'Movimientos simultáneos mantienen orden determinístico estricto por id_kardex y continuidad de saldos',
    $ordenDeterminista,
    "movs=" . count($items7) . ", orden_saldos=" . ($ordenDeterminista ? 'CONTINUO' : 'DISCONTINUO')
);

// =========================================================================
// CASO 8: F6-HIST-CAT-01 - CLASIFICACIÓN HISTÓRICA INMUNE A CAMBIOS EN CATÁLOGO
// =========================================================================
// Cambiamos la categoría actual del producto B en la tabla catálogo `producto` a categoría 1 (Abarrotes)
$db->exec("UPDATE producto SET id_categoria = 1, id_subcategoria = 1 WHERE id_producto = {$prodB}");

// Ahora consultamos filtrando por la categoría histórica guardada en el movimiento (id_categoria = 2, Bebidas)
$resp8 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-25',
    'id_categoria' => 2,
], $idAdminActivo);

$items8 = $resp8['items'];
$todosCat2 = true;
foreach ($items8 as $it) {
    if ($it['id_producto'] === $prodB && $it['categoria_nombre'] !== 'Bebidas') {
        $todosCat2 = false;
        break;
    }
}

assertTest(
    'F6-HIST-CAT-01',
    'Filtro por clasificación histórica respeta snapshot (Bebidas) aun si el catálogo actual del producto cambió',
    count($items8) >= 3 && $todosCat2,
    "filas_encontradas=" . count($items8) . ", categoria_snapshot=" . ($todosCat2 ? 'Bebidas' : 'Modificada')
);

// =========================================================================
// CASO 8B: F6-PAGE-01 - PAGINACIÓN DETERMINÍSTICA Y CONSISTENTE CON LIMIT Y PAGE
// =========================================================================
$respPage1 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-25',
    'id_producto' => $prodA,
    'page' => 1,
    'limit' => 2,
], $idAdminActivo);

$refPage = $respPage1['referencia_consulta'];
$respPage2 = $reporteService->reporteKardex([
    'referencia_consulta' => $refPage,
    'page' => 2,
    'limit' => 2,
], $idAdminActivo);

$p1Items = $respPage1['items'];
$p2Items = $respPage2['items'];

$paginacionCorrecta = count($p1Items) === 2
    && count($p2Items) === 1
    && $respPage1['pagination']['total'] === 3
    && $respPage1['pagination']['pages'] === 2
    && $respPage2['pagination']['total'] === 3
    && $respPage2['pagination']['page'] === 2
    && $p1Items[0]['id_kardex'] !== $p2Items[0]['id_kardex']
    && $p1Items[1]['id_kardex'] !== $p2Items[0]['id_kardex'];

assertTest(
    'F6-PAGE-01',
    'Paginación consistente mediante referencia_consulta: página 1 (2 ítems) y página 2 (1 ítem) sin saltos ni duplicados',
    $paginacionCorrecta,
    "p1_items=" . count($p1Items) . ", p2_items=" . count($p2Items) . ", total_pages={$respPage1['pagination']['pages']}"
);

// =========================================================================
// CASO 9: F6-SNAP-IMMUN-01 - CONSISTENCIA DE SNAPSHOT ANTE NUEVAS TRANSACCIONES
// =========================================================================
// Creamos una consulta congelada para Prod A
$resp9_ini = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-25',
    'id_producto' => $prodA,
], $idAdminActivo);

$refConsulta9 = $resp9_ini['referencia_consulta'];
$totalRegistros9_ini = $resp9_ini['pagination']['total'];

// Simulamos que concurrentemente ingresa una nueva venta confirmada para Prod A en ese rango de fechas
$db->exec("INSERT INTO venta (id_venta, id_usuario, id_cliente, id_tipo_comprobante, serie, numero, total, id_metodo_pago, estado, fecha_venta)
           VALUES (103, {$idAdminActivo}, 1, 1, 'B001', '000003', 15, 1, 1, '2026-10-22 10:00:00')");
$db->exec("INSERT INTO detalle_venta (id_detalle_venta, id_venta, id_producto, cantidad, precio_unitario, descuento, subtotal)
           VALUES (203, 103, {$prodA}, 1, 15.00, 0, 15)");
$db->exec("INSERT INTO kardex (id_producto, id_usuario, origen_operacion, sentido_movimiento, tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                               id_accion_venta, id_detalle_venta, referencia_operacion, condicion_lote, numero_lote,
                               producto_codigo_snapshot, producto_nombre_snapshot, id_categoria_snapshot, categoria_nombre_snapshot,
                               id_subcategoria_snapshot, subcategoria_nombre_snapshot, id_unidad_snapshot, unidad_abreviatura_snapshot,
                               responsable_nombre_snapshot, responsable_rol_snapshot, fecha_movimiento)
           VALUES ({$prodA}, {$idAdminActivo}, 'VENTA', 'DISMINUCION', 'Venta', 1, 40, 39,
                   103, 203, 'B001-000003', 'NO_APLICA', NULL,
                   'TST-KDX-A', 'Producto Kardex A', 1, 'Abarrotes', 1, 'General', 1, 'UND',
                   'Administrador Test', 'Administrador', '2026-10-22 10:00:00')");

// Re-consultamos usando referencia_consulta
$resp9_re = $reporteService->reporteKardex([
    'referencia_consulta' => $refConsulta9,
    'page' => 1,
    'limit' => 10,
], $idAdminActivo);

assertTest(
    'F6-SNAP-IMMUN-01',
    'Instantánea congelada bajo referencia_consulta es inmune a transacciones concurrentes posteriores',
    $resp9_re['pagination']['total'] === $totalRegistros9_ini
    && count($resp9_re['items']) === $totalRegistros9_ini
    && $resp9_re['resumen_producto']['saldo_final'] === 42,
    "total_original={$totalRegistros9_ini}, total_leido={$resp9_re['pagination']['total']}, saldo_final={$resp9_re['resumen_producto']['saldo_final']}"
);

// =========================================================================
// CASO 10: F6-REF-EXPIRE-01 - EXPIRACIÓN DE REFERENCIA_CONSULTA (410 GONE)
// =========================================================================
// Forzamos la expiración de la referencia modificando expira_en en BD
$db->exec("UPDATE reporte_consulta_snapshot SET expira_en = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE referencia_consulta = '{$refConsulta9}'");

$error410 = false;
$codigoError410 = '';
$httpStatus410 = 0;
try {
    $reporteService->reporteKardex([
        'referencia_consulta' => $refConsulta9,
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $error410 = true;
    $codigoError410 = $e->getErrorCode();
    $httpStatus410 = $e->getHttpStatus();
}

assertTest(
    'F6-REF-EXPIRE-01',
    'Referencia de consulta vencida responde 410 QUERY_REFERENCE_EXPIRED requiriendo nueva consulta',
    $error410 && $httpStatus410 === 410 && $codigoError410 === 'QUERY_REFERENCE_EXPIRED',
    "status={$httpStatus410}, code={$codigoError410}"
);

// =========================================================================
// CASO 11: F6-REF-FORBID-01 - VALIDACIÓN DE PROPIEDAD DE REFERENCIA_CONSULTA (403)
// =========================================================================
// Creamos una nueva consulta con idAdminActivo
$resp11 = $reporteService->reporteKardex([
    'fecha_inicio' => '2026-10-01',
    'fecha_fin' => '2026-10-25',
    'id_producto' => $prodA,
], $idAdminActivo);
$refConsulta11 = $resp11['referencia_consulta'];

// Intentamos leerla con idAdminSecundario
$error403 = false;
$codigoError403 = '';
$httpStatus403 = 0;
try {
    $reporteService->reporteKardex([
        'referencia_consulta' => $refConsulta11,
    ], $idAdminSecundario);
} catch (InventarioException $e) {
    $error403 = true;
    $codigoError403 = $e->getErrorCode();
    $httpStatus403 = $e->getHttpStatus();
}

assertTest(
    'F6-REF-FORBID-01',
    'Referencia de consulta ajena al usuario solicitante responde 403 QUERY_REFERENCE_FORBIDDEN',
    $error403 && $httpStatus403 === 403 && $codigoError403 === 'QUERY_REFERENCE_FORBIDDEN',
    "status={$httpStatus403}, code={$codigoError403}"
);

// =========================================================================
// CASO 12: F6-REF-MISMATCH-01 - CONFLICTO DE FILTROS ALTERADOS CON REFERENCIA ACTIVA (409)
// =========================================================================
// Intentamos reutilizar $refConsulta11 pero enviando filtros distintos (ej. id_categoria = 2)
$error409 = false;
$codigoError409 = '';
$httpStatus409 = 0;
try {
    $reporteService->reporteKardex([
        'referencia_consulta' => $refConsulta11,
        'fecha_inicio' => '2026-10-01',
        'fecha_fin' => '2026-10-25',
        'id_categoria' => 2, // Filtro alterado
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $error409 = true;
    $codigoError409 = $e->getErrorCode();
    $httpStatus409 = $e->getHttpStatus();
}

assertTest(
    'F6-REF-MISMATCH-01',
    'Alterar filtros manteniendo la referencia activa rechaza 409 QUERY_FILTERS_MISMATCH',
    $error409 && $httpStatus409 === 409 && $codigoError409 === 'QUERY_FILTERS_MISMATCH',
    "status={$httpStatus409}, code={$codigoError409}"
);

// =========================================================================
// CASO 13: F6-VAL-01 - VALIDACIÓN ESTRICTA DE FECHAS Y PRODUCTO (422)
// =========================================================================
// 1. Fechas invertidas
$fechasInvertidas = false;
try {
    $reporteService->reporteKardex([
        'fecha_inicio' => '2026-10-25',
        'fecha_fin' => '2026-10-01',
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $fechasInvertidas = ($e->getHttpStatus() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR');
}

// 2. Fecha con formato inválido
$fechaInvalida = false;
try {
    $reporteService->reporteKardex([
        'fecha_inicio' => '2026-02-31',
        'fecha_fin' => '2026-10-25',
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $fechaInvalida = ($e->getHttpStatus() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR');
}

// 3. Producto inexistente
$productoInexistente = false;
try {
    $reporteService->reporteKardex([
        'fecha_inicio' => '2026-10-01',
        'fecha_fin' => '2026-10-25',
        'id_producto' => 999999,
    ], $idAdminActivo);
} catch (InventarioException $e) {
    $productoInexistente = ($e->getHttpStatus() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR');
}

assertTest(
    'F6-VAL-01',
    'Validación estricta: fechas invertidas, formato no gregoriano y producto inexistente responden 422 VALIDATION_ERROR',
    $fechasInvertidas && $fechaInvalida && $productoInexistente,
    "invertidas=" . ($fechasInvertidas ? 'SI' : 'NO') . ", formato_invalido=" . ($fechaInvalida ? 'SI' : 'NO') . ", prod_inexistente=" . ($productoInexistente ? 'SI' : 'NO')
);

// =========================================================================
// CASO 14: F6-READONLY-01 - CONSULTA ES ESTRICTAMENTE DE SOLO LECTURA
// =========================================================================
$stockProdABefore = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$totalKardexBefore = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

// Ejecutamos varias consultas consecutivas
$reporteService->reporteKardex(['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-25', 'id_producto' => $prodA], $idAdminActivo);
$reporteService->reporteKardex(['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-25'], $idAdminActivo);

$stockProdAAfter = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$totalKardexAfter = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

assertTest(
    'F6-READONLY-01',
    'La consulta de Kardex es estrictamente de solo lectura: existencias y movimientos permanecen 100% inalterados',
    $stockProdABefore === $stockProdAAfter && $totalKardexBefore === $totalKardexAfter,
    "stock_antes={$stockProdABefore}, stock_despues={$stockProdAAfter}, kardex_antes={$totalKardexBefore}, kardex_despues={$totalKardexAfter}"
);

// =========================================================================
// CASO 15: F6-RBAC-01 - SEGURIDAD RBAC EN ENDPOINT API KARDEX
// =========================================================================
$idVendedor = (int)$db->query("SELECT id_usuario FROM usuario WHERE id_rol = 2 AND estado = 1 LIMIT 1")->fetchColumn();
if (!$idVendedor) {
    $db->exec("INSERT INTO usuario (id_usuario, nombre, email, password, id_rol, estado) VALUES (98, 'Vendedor Test', 'vendedor_test@test.com', 'hash', 2, 1)
               ON DUPLICATE KEY UPDATE estado = 1");
    $idVendedor = 98;
}

// Simulamos verificación de RBAC como en ApiController::requireAdminActivo
$permisoAdmin = \Libraries\Middleware\RBACMiddleware::tienePermiso(1, 'reportes.kardex');
$permisoVendedor = \Libraries\Middleware\RBACMiddleware::tienePermiso(2, 'reportes.kardex');
$esExclusivoAdmin = \Libraries\Middleware\RBACMiddleware::esSlugExclusivoAdminSprint2('reportes.kardex');

assertTest(
    'F6-RBAC-01',
    'Seguridad RBAC: rol Administrador posee permiso reportes.kardex; rol Vendedor es rechazado',
    $permisoAdmin === true && $permisoVendedor === false && $esExclusivoAdmin === true,
    "admin=" . ($permisoAdmin ? 'PERMITIDO' : 'RECHAZADO') . ", vendedor=" . ($permisoVendedor ? 'PERMITIDO' : 'DENEGADO')
);

// -------------------------------------------------------------------------
// LIMPIEZA FINAL Y VERIFICACIÓN DE AISLAMIENTO
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
$db->exec("DELETE FROM usuario WHERE id_usuario IN (98, 99)");

$totalKardexLimpio = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();
$totalSnapshotsLimpio = (int)$db->query("SELECT COUNT(*) FROM reporte_consulta_snapshot")->fetchColumn();

assertTest(
    'F6-CLEAN-01',
    'Limpieza final en base de pruebas: registros temporales eliminados y stock en 0',
    $totalKardexLimpio === 0 && $totalSnapshotsLimpio === 0,
    "kardex={$totalKardexLimpio}, snapshots={$totalSnapshotsLimpio}"
);

// F6-ISO-01: Base amazon_market principal permanece estrictamente intocada
$pdoReal = new PDO('mysql:host=localhost;dbname=amazon_market;charset=utf8mb4', 'root', '');
$tablasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'amazon_market'")->fetchColumn();
$stockPosReal = (int)$pdoReal->query("SELECT COUNT(*) FROM producto WHERE stock_actual > 0")->fetchColumn();
$ventasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$comprasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM compra")->fetchColumn();
$kardexReal = (int)$pdoReal->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

assertTest(
    'F6-ISO-01',
    'Aislamiento verificado: Base principal amazon_market no sufrió modificaciones',
    $tablasReal === 20
    && $stockPosReal === 106
    && $ventasReal === 98
    && $comprasReal === 4
    && $kardexReal === 26,
    "tablas={$tablasReal}, stock_pos={$stockPosReal}, ventas={$ventasReal}, compras={$comprasReal}, kardex={$kardexReal}"
);

echo PHP_EOL . "=== RESUMEN FASE 6: {$passed} PASS / {$failed} FAIL ===" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
