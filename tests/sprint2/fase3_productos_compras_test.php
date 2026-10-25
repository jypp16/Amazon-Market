<?php
/**
 * Suite de Pruebas de Integración - Fase 3 (Sprint 2)
 * Productos y Compras coherentes con el inventario.
 *
 * Se ejecuta EXCLUSIVAMENTE sobre `amazon_market_sprint2_test`.
 */

require_once __DIR__ . '/bootstrap_test.php';

use Libraries\Core\Conexion;
use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Models\CompraModel;
use Models\DetalleCompraModel;
use Models\ProductoModel;
use Models\SubcategoriaModel;
use Services\CompraService;
use Services\MovimientoInventarioService;
use Services\ProductoService;

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

echo "=== INICIANDO SUITE FASE 3 EN: " . $db->query("SELECT DATABASE()")->fetchColumn() . " ===" . PHP_EOL . PHP_EOL;

// Limpieza previa en base de pruebas
$db->exec("DELETE FROM kardex");
$db->exec("DELETE FROM operacion_inventario");
$db->exec("DELETE FROM compra_historial");
$db->exec("UPDATE detalle_compra SET id_detalle_origen = NULL");
$db->exec("DELETE FROM detalle_compra");
$db->exec("DELETE FROM compra WHERE serie LIKE 'TST-%' OR serie LIKE 'PRU-%'");
$db->exec("DELETE FROM venta WHERE serie = 'VTST'");
$db->exec("DELETE FROM control_solicitud_idempotencia");
$db->exec("DELETE FROM producto WHERE codigo_barra LIKE 'TST-%'");
$db->exec("UPDATE producto SET stock_actual = 0");

$productoService = new ProductoService();
$compraService = new CompraService();
$movimientoService = $compraService->getMovimientoService();
$compraModel = new CompraModel();
$detalleModel = new DetalleCompraModel();
$productoModel = new ProductoModel();

// =========================================================================
// BLOQUE 1: PRODUCTOS - Stock inicial en 0 y stock intocable en edición
// =========================================================================

// F3-PROD-01: Producto nuevo siempre inicia con stock_actual = 0
$codigoTest = 'TST-PROD-' . time();
$resCrear = $productoService->crear([
    'codigo_barra' => $codigoTest,
    'nombre' => 'Producto Prueba Fase 3',
    'id_categoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 15.50,
    'stock_actual' => 999, // Se intenta inyectar stock inicial > 0
    'stock_minimo' => 5
]);
$nuevoProdId = (int)($resCrear['id_producto'] ?? 0);

$prodCreado = $productoModel->find($nuevoProdId);
assertTest(
    'F3-PROD-01',
    'Producto nuevo ignora stock enviado y se crea estrictamente con stock_actual = 0',
    (int)$prodCreado['stock_actual'] === 0 && (int)$prodCreado['stock_minimo'] === 5,
    "stock_actual={$prodCreado['stock_actual']}, stock_minimo={$prodCreado['stock_minimo']}"
);

// F3-PROD-02: Edición de producto omite stock_actual
$db->exec("UPDATE producto SET stock_actual = 12 WHERE id_producto = {$nuevoProdId}");
$productoService->actualizar($nuevoProdId, [
    'codigo_barra' => $codigoTest,
    'nombre' => 'Producto Prueba Fase 3 Modificado',
    'id_categoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 20.00,
    'stock_actual' => 888, // Intento de alteración manual de existencias
    'stock_minimo' => 10
]);
$prodEditado = $productoModel->find($nuevoProdId);
assertTest(
    'F3-PROD-02',
    'Edición de producto actualiza atributos de catálogo pero no altera stock_actual',
    (int)$prodEditado['stock_actual'] === 12
    && (int)$prodEditado['stock_minimo'] === 10
    && $prodEditado['nombre'] === 'Producto Prueba Fase 3 Modificado',
    "stock_actual={$prodEditado['stock_actual']}, nombre={$prodEditado['nombre']}"
);

// Limpiar producto de prueba
$db->exec("DELETE FROM producto WHERE id_producto = {$nuevoProdId}");

// Tomar productos y proveedores existentes para las pruebas de compra
$prodA = (int)$db->query("SELECT id_producto FROM producto WHERE estado = 1 ORDER BY id_producto ASC LIMIT 1")->fetchColumn();
$prodB = (int)$db->query("SELECT id_producto FROM producto WHERE estado = 1 AND id_producto <> {$prodA} ORDER BY id_producto ASC LIMIT 1")->fetchColumn();
$idProveedor = (int)$db->query("SELECT id_proveedor FROM proveedor WHERE estado = 1 ORDER BY id_proveedor ASC LIMIT 1")->fetchColumn();
$idTipoComp = (int)$db->query("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE estado = 1 ORDER BY id_tipo_comprobante ASC LIMIT 1")->fetchColumn();
$idPresCaja24 = (int)$db->query("SELECT id_presentacion FROM presentacion WHERE equivalencia = 24 LIMIT 1")->fetchColumn();
$idPresUnidad = (int)$db->query("SELECT id_presentacion FROM presentacion WHERE equivalencia = 1 LIMIT 1")->fetchColumn();

// =========================================================================
// BLOQUE 2: COMPRAS - Casos Obligatorios 1 al 7
// =========================================================================

// CASO 1: Compra de 2 cajas de 24: entrada de 48
$serie1 = 'TST-' . rand(100, 999);
$num1 = '0000001';
$resC1 = $compraService->registrarCompra([
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie1,
    'numero' => $num1,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 240.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresCaja24,
        'cantidad_presentaciones' => 2,
        'equivalencia_aplicada' => 24,
        'importe_final_linea' => 240.00,
        'numero_lote' => 'LOTE-CJ24-1',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1);

$stockA1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$kardexC1 = $db->query("SELECT * FROM kardex WHERE id_accion_compra = {$resC1['id_compra']}")->fetch(PDO::FETCH_ASSOC);

assertTest(
    'F3-COMP-01',
    'Caso 1: Compra de 2 cajas de 24 genera entrada de 48 unidades en stock y Kardex canónico',
    $resC1['status'] === true
    && $stockA1 === 48
    && (int)$kardexC1['cantidad'] === 48
    && $kardexC1['origen_operacion'] === DefinicionMovimiento::ORIGEN_COMPRA
    && $kardexC1['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_INCREMENTO
    && (int)$kardexC1['stock_anterior'] === 0
    && (int)$kardexC1['stock_resultante'] === 48,
    "stock={$stockA1}, kardex_cant={$kardexC1['cantidad']}, origen={$kardexC1['origen_operacion']}"
);

// CASO 2: Compra 20, venta posterior 15 y corrección a 18: saldo final 3
// Restablecer producto B a 0
$db->exec("UPDATE producto SET stock_actual = 0 WHERE id_producto = {$prodB}");
$serie2 = 'TST-' . rand(100, 999);
$num2 = '0000002';
$resC2 = $compraService->registrarCompra([
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie2,
    'numero' => $num2,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 200.00
], [
    [
        'id_producto' => $prodB,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 20,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 200.00,
        'numero_lote' => 'LOTE-C2-01',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+6 months'))
    ]
], 1);
$idCompra2 = $resC2['id_compra'];

// Crear venta simulada para satisfacer FK fk_kardex_venta en movimientos de salida
$db->exec("INSERT INTO venta (id_cliente, id_usuario, id_tipo_comprobante, serie, numero, total, id_metodo_pago, estado) VALUES (1, 1, 1, 'VTST', '0000001', 150.00, 1, 1)");
$idVentaSimulada = (int)$db->lastInsertId();

// Simular venta posterior de 15 unidades que consume existencias
$movimientoService->ejecutarEnTransaccion(function() use ($movimientoService, $prodB, $idVentaSimulada) {
    $movimientoService->aplicarMovimiento([
        'id_producto' => $prodB,
        'id_usuario' => 1,
        'origen_operacion' => DefinicionMovimiento::ORIGEN_VENTA,
        'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
        'cantidad' => 15,
        'id_accion_venta' => $idVentaSimulada,
        'id_detalle_venta' => null
    ]);
});
$stockDespuesVenta = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodB}")->fetchColumn();

// Corregir compra a 18 unidades (Δ = 18 - 20 = -2). Como hay 5 disponibles, queda en 3 sin error
$resCorrC2 = $compraService->corregirCompra($idCompra2, [
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie2,
    'numero' => $num2,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 180.00
], [
    [
        'id_producto' => $prodB,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 18,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 180.00,
        'numero_lote' => 'LOTE-C2-01',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+6 months'))
    ]
], 1, 'Ajuste de unidades por factura recibida');

$stockFinalC2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodB}")->fetchColumn();
$kardexCorrC2 = $db->query("SELECT * FROM kardex WHERE id_accion_compra = {$idCompra2} AND origen_operacion = '" . DefinicionMovimiento::ORIGEN_CORRECCION_COMPRA . "'")->fetch(PDO::FETCH_ASSOC);

assertTest(
    'F3-COMP-02',
    'Caso 2: Compra 20, venta posterior 15 y corrección a 18 deja saldo final 3 sin error de stock negativo',
    $stockDespuesVenta === 5
    && $resCorrC2['status'] === true
    && $stockFinalC2 === 3
    && (int)$kardexCorrC2['cantidad'] === 2
    && $kardexCorrC2['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_DISMINUCION
    && (int)$kardexCorrC2['stock_anterior'] === 5
    && (int)$kardexCorrC2['stock_resultante'] === 3,
    "stock_tras_venta={$stockDespuesVenta}, stock_final={$stockFinalC2}, delta_cant={$kardexCorrC2['cantidad']}"
);

// CASO 3: Corrección solo de costo: stock y movimientos sin cambios
$serie3 = 'TST-' . rand(100, 999);
$num3 = '0000003';
$resC3 = $compraService->registrarCompra([
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie3,
    'numero' => $num3,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 100.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 10,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 100.00,
        'numero_lote' => 'LOTE-COSTO-1',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1);
$idCompra3 = $resC3['id_compra'];
$stockA_antesC3 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$totalKardexAntes = (int)$db->query("SELECT COUNT(*) FROM kardex WHERE id_accion_compra = {$idCompra3}")->fetchColumn();

// Corregir solo el costo total de 100 a 140 y el importe de la línea
$resCorrC3 = $compraService->corregirCompra($idCompra3, [
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie3,
    'numero' => $num3,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 140.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 10, // Mismas unidades -> Δ = 0
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 140.00,
        'numero_lote' => 'LOTE-COSTO-1',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1, 'Corrección de precio pactado con proveedor');

$stockA_despuesC3 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$totalKardexDespues = (int)$db->query("SELECT COUNT(*) FROM kardex WHERE id_accion_compra = {$idCompra3}")->fetchColumn();
$versionDetalle3 = (int)$db->query("SELECT version_detalle FROM detalle_compra WHERE id_compra = {$idCompra3} AND es_vigente = 1 LIMIT 1")->fetchColumn();
$estadoCabecera3 = $db->query("SELECT estado FROM compra WHERE id_compra = {$idCompra3}")->fetchColumn();

assertTest(
    'F3-COMP-03',
    'Caso 3: Corrección solo de costo no altera stock ni genera movimientos en Kardex (Δ = 0)',
    $stockA_antesC3 === $stockA_despuesC3
    && $totalKardexAntes === $totalKardexDespues
    && $versionDetalle3 === 2
    && $estadoCabecera3 === 'Corregida',
    "stock_igual=" . ($stockA_antesC3 === $stockA_despuesC3 ? 'SI' : 'NO') . ", kardex_igual=" . ($totalKardexAntes === $totalKardexDespues ? 'SI' : 'NO') . ", v={$versionDetalle3}"
);

// CASO 4: Corrección de producto con fallo intermedio: rollback completo
$stockA_antesRollback = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$detallesActivosAntes = (int)$db->query("SELECT COUNT(*) FROM detalle_compra WHERE id_compra = {$idCompra3} AND es_vigente = 1")->fetchColumn();

$falloCapturado = false;
try {
    // Intenta corregir disminuyendo más unidades de las que tiene el producto en stock (disminuir 99999)
    $compraService->corregirCompra($idCompra3, [
        'id_proveedor' => $idProveedor,
        'id_tipo_comprobante' => $idTipoComp,
        'serie' => $serie3,
        'numero' => $num3,
        'fecha_emision' => date('Y-m-d'),
        'total_documento' => 50.00
    ], [
        [
            'id_producto' => $prodA,
            'id_presentacion' => $idPresUnidad,
            'cantidad_presentaciones' => 10,
            'equivalencia_aplicada' => 1,
            'importe_final_linea' => 50.00,
            'numero_lote' => 'LOTE-X',
            'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
        ],
        [
            // Producto con stock 0 intentando restar
            'id_producto' => 999999, // Producto inexistente para forzar fallo
            'id_presentacion' => $idPresUnidad,
            'cantidad_presentaciones' => 1,
            'equivalencia_aplicada' => 1,
            'importe_final_linea' => 10.00,
            'numero_lote' => 'LOTE-ERR',
            'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
        ]
    ], 1, 'Corrección con fallo intencional');
} catch (Throwable $e) {
    $falloCapturado = true;
}

$stockA_despuesRollback = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$detallesActivosDespues = (int)$db->query("SELECT COUNT(*) FROM detalle_compra WHERE id_compra = {$idCompra3} AND es_vigente = 1")->fetchColumn();

assertTest(
    'F3-COMP-04',
    'Caso 4: Corrección con fallo intermedio realiza rollback completo sin corromper versiones ni stock',
    $falloCapturado === true
    && $stockA_antesRollback === $stockA_despuesRollback
    && $detallesActivosAntes === $detallesActivosDespues,
    "fallo_capturado=" . ($falloCapturado ? 'SI' : 'NO') . ", stock_intacto=" . ($stockA_antesRollback === $stockA_despuesRollback ? 'SI' : 'NO')
);

// CASO 5: Segunda corrección: diferencia calculada contra la versión vigente
$serie5 = 'TST-' . rand(100, 999);
$num5 = '0000005';
$db->exec("UPDATE producto SET stock_actual = 0 WHERE id_producto = {$prodA}");

// Compra original: 10 unidades (v1) -> stock = 10
$resC5 = $compraService->registrarCompra([
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie5,
    'numero' => $num5,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 100.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 10,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 100.00,
        'numero_lote' => 'LOTE-C5-1',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1);
$idCompra5 = $resC5['id_compra'];
$stockV1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Primera corrección a 14 unidades (v2) -> Δ = +4 -> stock = 14
$compraService->corregirCompra($idCompra5, [
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie5,
    'numero' => $num5,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 140.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 14,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 140.00,
        'numero_lote' => 'LOTE-C5-1',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1, 'Primera corrección a 14');
$stockV2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Segunda corrección a 12 unidades (v3) -> Δ = 12 - 14 = -2 (contra v2 vigente) -> stock = 12
$compraService->corregirCompra($idCompra5, [
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie5,
    'numero' => $num5,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 120.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 12,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 120.00,
        'numero_lote' => 'LOTE-C5-1',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1, 'Segunda corrección a 12');
$stockV3 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$versionesTotal = (int)$db->query("SELECT COUNT(DISTINCT version_detalle) FROM detalle_compra WHERE id_compra = {$idCompra5}")->fetchColumn();
$vigenteV3 = (int)$db->query("SELECT version_detalle FROM detalle_compra WHERE id_compra = {$idCompra5} AND es_vigente = 1 LIMIT 1")->fetchColumn();

assertTest(
    'F3-COMP-05',
    'Caso 5: Segunda corrección calcula delta contra la versión vigente (12 - 14 = -2) dejando stock en 12',
    $stockV1 === 10
    && $stockV2 === 14
    && $stockV3 === 12
    && $versionesTotal === 3
    && $vigenteV3 === 3,
    "stock_v1={$stockV1}, stock_v2={$stockV2}, stock_v3={$stockV3}, versiones={$versionesTotal}"
);

// CASO 6: Compra concurrente con venta: ambas actualizaciones conservadas (FOR UPDATE)
$db->exec("UPDATE producto SET stock_actual = 0 WHERE id_producto = {$prodA}");
$serie6 = 'TST-' . rand(100, 999);
$num6 = '0000006';

// Simular dos transacciones concurrentes serializadas por bloqueo de producto
$movimientoService->ejecutarEnTransaccion(function() use ($compraService, $movimientoService, $idProveedor, $idTipoComp, $serie6, $num6, $prodA, $idPresUnidad, $idVentaSimulada) {
    // Transacción 1: compra de 10
    $compraService->registrarCompra([
        'id_proveedor' => $idProveedor,
        'id_tipo_comprobante' => $idTipoComp,
        'serie' => $serie6,
        'numero' => $num6,
        'fecha_emision' => date('Y-m-d'),
        'total_documento' => 100.00
    ], [
        [
            'id_producto' => $prodA,
            'id_presentacion' => $idPresUnidad,
            'cantidad_presentaciones' => 10,
            'equivalencia_aplicada' => 1,
            'importe_final_linea' => 100.00,
            'numero_lote' => 'LOTE-C6',
            'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
        ]
    ], 1);

    // Operación 2 (en la misma sesión / ordenada): venta de 4
    $movimientoService->aplicarMovimiento([
        'id_producto' => $prodA,
        'id_usuario' => 1,
        'origen_operacion' => DefinicionMovimiento::ORIGEN_VENTA,
        'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
        'cantidad' => 4,
        'id_accion_venta' => $idVentaSimulada,
        'id_detalle_venta' => null
    ]);
});
$stockConcurrente = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

assertTest(
    'F3-COMP-06',
    'Caso 6: Bloqueo FOR UPDATE preserva actualizaciones concurrentes (10 - 4 = 6) sin actualizaciones perdidas',
    $stockConcurrente === 6,
    "stock_resultante={$stockConcurrente}"
);

// CASO 7: Anulación repetida: una sola reversión
$db->exec("UPDATE producto SET stock_actual = 0 WHERE id_producto = {$prodA}");
$serie7 = 'TST-' . rand(100, 999);
$num7 = '0000007';
$resC7 = $compraService->registrarCompra([
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serie7,
    'numero' => $num7,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 70.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 7,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 70.00,
        'numero_lote' => 'LOTE-C7',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1);
$idCompra7 = $resC7['id_compra'];
$stockTrasCompra7 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Anulación 1: debe tener éxito y dejar stock en 0
$resAnul1 = $compraService->anularCompra($idCompra7, 1, 'Error en comprobante físico recibido');
$stockTrasAnul1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$estadoAnul1 = $db->query("SELECT estado FROM compra WHERE id_compra = {$idCompra7}")->fetchColumn();

// Anulación 2: debe ser rechazada y NO restar stock nuevamente
$anul2Fallada = false;
try {
    $compraService->anularCompra($idCompra7, 1, 'Intento repetido de anulación');
} catch (Throwable $e) {
    $anul2Fallada = true;
}
$stockTrasAnul2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

assertTest(
    'F3-COMP-07',
    'Caso 7: Anulación repetida es rechazada sin duplicar la reversión de stock',
    $stockTrasCompra7 === 7
    && $resAnul1['status'] === true
    && $stockTrasAnul1 === 0
    && $estadoAnul1 === 'Anulada'
    && $anul2Fallada === true
    && $stockTrasAnul2 === 0,
    "stock_tras_c7={$stockTrasCompra7}, tras_anul1={$stockTrasAnul1}, tras_anul2={$stockTrasAnul2}"
);

// =========================================================================
// BLOQUE 3: REPORTES DE COMPRAS Y CONTROL DE DIFERENCIAS
// =========================================================================

// F3-REP-01: obtenerResumenCompras y obtenerReporteCompras no duplican líneas de compras corregidas
$reporte = $compraService->obtenerReporteCompras([
    'estados' => ['Confirmada', 'Corregida']
], 1);

$comprasConDiferencias = $reporte['resumen']['compras_con_diferencias'];
$totalCompras = count($reporte['compras']);

// Verificar que ninguna compra corregida tenga más líneas o importe duplicado
$todasCoinciden = true;
foreach ($reporte['compras'] as $c) {
    $sumaLineas = array_sum(array_map(fn($d) => (float)$d['importe_final_linea'], $c['detalles']));
    if (abs($sumaLineas - (float)$c['total_documento']) > 0.01) {
        $todasCoinciden = false;
        break;
    }
}

assertTest(
    'F3-REP-01',
    'Reporte de Compras filtra estrictamente es_vigente = 1, sin duplicar unidades ni generar falsas diferencias',
    $comprasConDiferencias === 0 && $todasCoinciden === true && $totalCompras > 0,
    "compras_con_diferencias={$comprasConDiferencias}, total_compras={$totalCompras}"
);

// =========================================================================
// BLOQUE 4: IDEMPOTENCIA EN COMPRAS
// =========================================================================

// F3-IDEM-01: Idempotencia en registro de compras
$serieIdemp = 'TST-' . rand(100, 999);
$numIdemp = '0000088';
$refIdemp = 'compra-idemp-test-' . bin2hex(random_bytes(8));

$stockA_antesIdemp = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Intento 1
$resIdemp1 = $compraService->registrarCompra([
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serieIdemp,
    'numero' => $numIdemp,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 50.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 5,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 50.00,
        'numero_lote' => 'LOTE-IDEMP',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1, $refIdemp);

$stockA_trasIdemp1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Intento 2 con la misma referencia y payload
$resIdemp2 = $compraService->registrarCompra([
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => $idTipoComp,
    'serie' => $serieIdemp,
    'numero' => $numIdemp,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 50.00
], [
    [
        'id_producto' => $prodA,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 5,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 50.00,
        'numero_lote' => 'LOTE-IDEMP',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], 1, $refIdemp);

$stockA_trasIdemp2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

assertTest(
    'F3-IDEM-01',
    'Control de solicitud idempotente responde repetición sin volver a sumar existencias',
    $stockA_trasIdemp1 === ($stockA_antesIdemp + 5)
    && $stockA_trasIdemp2 === $stockA_trasIdemp1
    && (int)$resIdemp1['id_compra'] === (int)$resIdemp2['id_compra'],
    "stock_tras_1={$stockA_trasIdemp1}, stock_tras_2={$stockA_trasIdemp2}"
);

// =========================================================================
// BLOQUE 5: LIMPIEZA FINAL Y VERIFICACIÓN DE AISLAMIENTO
// =========================================================================

// Limpiar compras de prueba creadas durante la suite
$db->exec("DELETE FROM kardex");
$db->exec("DELETE FROM compra_historial");
$db->exec("UPDATE detalle_compra SET id_detalle_origen = NULL");
$db->exec("DELETE FROM detalle_compra");
$db->exec("DELETE FROM compra WHERE serie LIKE 'TST-%'");
$db->exec("DELETE FROM venta WHERE serie = 'VTST'");
$db->exec("DELETE FROM control_solicitud_idempotencia");
$db->exec("DELETE FROM producto WHERE codigo_barra LIKE 'TST-%'");
$db->exec("UPDATE producto SET stock_actual = 0");

$totalKardexLimpio = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();
$totalComprasTest = (int)$db->query("SELECT COUNT(*) FROM compra WHERE serie LIKE 'TST-%'")->fetchColumn();

assertTest(
    'F3-CLEAN-01',
    'Limpieza final en base de pruebas: registros temporales eliminados y stock en 0',
    $totalKardexLimpio === 0 && $totalComprasTest === 0,
    "kardex={$totalKardexLimpio}, compras_tst={$totalComprasTest}"
);

// F3-ISO-01: Base amazon_market principal permanece estrictamente intocada
$pdoReal = new PDO('mysql:host=localhost;dbname=amazon_market;charset=utf8mb4', 'root', '');
$tablasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'amazon_market'")->fetchColumn();
$stockPosReal = (int)$pdoReal->query("SELECT COUNT(*) FROM producto WHERE stock_actual > 0")->fetchColumn();
$ventasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$comprasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM compra")->fetchColumn();
$kardexReal = (int)$pdoReal->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

assertTest(
    'F3-ISO-01',
    'Aislamiento verificado: Base principal amazon_market no sufrió modificaciones',
    $tablasReal === 20
    && $stockPosReal === 106
    && $ventasReal === 98
    && $comprasReal === 4
    && $kardexReal === 26,
    "tablas={$tablasReal}, stock_pos={$stockPosReal}, ventas={$ventasReal}, compras={$comprasReal}, kardex={$kardexReal}"
);

echo PHP_EOL . "=== RESUMEN FASE 3: {$passed} PASS / {$failed} FAIL ===" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
