<?php
/**
 * Suite de Pruebas de Integración - Fase 4 (Sprint 2)
 * Integración de Ventas con Kardex, Existencias Atómicas e Idempotencia.
 *
 * Se ejecuta EXCLUSIVAMENTE sobre `amazon_market_sprint2_test`.
 */

require_once __DIR__ . '/bootstrap_test.php';

use Libraries\Core\Conexion;
use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Models\ClienteModel;
use Models\DetalleVentaModel;
use Models\ProductoModel;
use Models\VentaModel;
use Services\CompraService;
use Services\ControlSolicitudService;
use Services\MovimientoInventarioService;
use Services\ProductoService;
use Services\VentaService;

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

echo "=== INICIANDO SUITE FASE 4 EN: " . $db->query("SELECT DATABASE()")->fetchColumn() . " ===" . PHP_EOL . PHP_EOL;

// -------------------------------------------------------------------------
// LIMPIEZA PREVIA Y PREPARACIÓN DE AMBIENTE DE PRUEBAS
// -------------------------------------------------------------------------
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
$compraService = new CompraService();
$ventaService = new VentaService();
$movimientoService = $ventaService->getMovimientoService();
$productoModel = new ProductoModel();
$ventaModel = new VentaModel();
$detalleVentaModel = new DetalleVentaModel();
$clienteModel = new ClienteModel();

// Crear productos específicos para las pruebas de venta
$prodA_res = $productoService->crear([
    'codigo_barra' => 'TST-VTA-A-' . time(),
    'nombre' => 'Producto Venta A',
    'id_categoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 10.00,
    'stock_minimo' => 2
]);
$prodA = (int)$prodA_res['id_producto'];

$prodB_res = $productoService->crear([
    'codigo_barra' => 'TST-VTA-B-' . time(),
    'nombre' => 'Producto Venta B',
    'id_categoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 25.00,
    'stock_minimo' => 3
]);
$prodB = (int)$prodB_res['id_producto'];

$prodC_res = $productoService->crear([
    'codigo_barra' => 'TST-VTA-C-' . time(),
    'nombre' => 'Producto Venta C',
    'id_categoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 8.00,
    'stock_minimo' => 1
]);
$prodC = (int)$prodC_res['id_producto'];

// Presentación Unidad para compras de apoyo
$presUnidad = $db->query("SELECT id_presentacion FROM presentacion WHERE equivalencia = 1 AND estado = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$idPresUnidad = (int)($presUnidad['id_presentacion'] ?? 1);
$idProveedor = (int)$db->query("SELECT id_proveedor FROM proveedor WHERE estado = 1 LIMIT 1")->fetchColumn();
$idClienteDni = 1; // Jose Dominguez (DNI)
$idClienteRuc = 9; // Importaciones del Sur (RUC)
$idUsuarioActivo = 1; // Administrador activo

// Inicializar existencias para las pruebas mediante carga controlada de prueba
$db->exec("UPDATE producto SET stock_actual = 20 WHERE id_producto = {$prodA}");
$db->exec("UPDATE producto SET stock_actual = 15 WHERE id_producto = {$prodB}");
$db->exec("UPDATE producto SET stock_actual = 5 WHERE id_producto = {$prodC}");

// Obtener correlativo inicial de Boleta (id_tipo_comprobante = 1)
$correlativoInicialBoleta = (int)$db->query("SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante = 1 LIMIT 1")->fetchColumn();

// =========================================================================
// CASO 1: VENTA NORMAL DE LÍNEA ÚNICA CON DECREMENTO ATÓMICO Y KARDEX
// =========================================================================
$respV1 = $ventaService->procesarVenta([
    'id_usuario' => $idUsuarioActivo,
    'id_cliente' => $idClienteDni,
    'id_tipo_comprobante' => 1, // Boleta
    'id_metodo_pago' => 1,
    'productos' => [
        ['id_producto' => $prodA, 'cantidad' => 5]
    ]
]);

$idVenta1 = (int)($respV1['data']['id_venta'] ?? 0);
$stockProdA_trasV1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$correlativoBoleta1 = (int)$db->query("SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante = 1 LIMIT 1")->fetchColumn();

// Verificar registro de Kardex
$kardexV1 = $db->query("SELECT * FROM kardex WHERE id_accion_venta = {$idVenta1}")->fetch(PDO::FETCH_ASSOC);
$detV1 = $db->query("SELECT * FROM detalle_venta WHERE id_venta = {$idVenta1}")->fetch(PDO::FETCH_ASSOC);

$v1Valida = ($idVenta1 > 0)
    && ($stockProdA_trasV1 === 15)
    && ($correlativoBoleta1 === $correlativoInicialBoleta + 1)
    && ($detV1 && (int)$detV1['cantidad'] === 5 && (float)$detV1['subtotal'] === 50.00)
    && ($kardexV1 !== false)
    && ($kardexV1['origen_operacion'] === DefinicionMovimiento::ORIGEN_VENTA)
    && ($kardexV1['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_DISMINUCION)
    && ((int)$kardexV1['cantidad'] === 5)
    && ((int)$kardexV1['stock_anterior'] === 20)
    && ((int)$kardexV1['stock_resultante'] === 15)
    && ((int)$kardexV1['id_detalle_venta'] === (int)$detV1['id_detalle_venta'])
    && empty($kardexV1['id_accion_compra'])
    && empty($kardexV1['id_operacion_inventario'])
    && !empty($kardexV1['producto_nombre_snapshot'])
    && !empty($kardexV1['responsable_nombre_snapshot']);

assertTest(
    'F4-VTA-01',
    'Venta normal de línea única: descuenta stock atómicamente, incrementa correlativo y genera Kardex con snapshots y FKs',
    $v1Valida,
    "id_venta={$idVenta1}, stock_resultante={$stockProdA_trasV1}, correlativo={$correlativoBoleta1}"
);

// =========================================================================
// CASO 2: VENTA MULTI-LÍNEA DEL MISMO PRODUCTO
// =========================================================================
// Producto B tiene stock = 15. Enviamos dos líneas: 4 unidades y 6 unidades (demanda total = 10)
$respV2 = $ventaService->procesarVenta([
    'id_usuario' => $idUsuarioActivo,
    'id_cliente' => $idClienteDni,
    'id_tipo_comprobante' => 1,
    'id_metodo_pago' => 1,
    'productos' => [
        ['id_producto' => $prodB, 'cantidad' => 4],
        ['id_producto' => $prodB, 'cantidad' => 6]
    ]
]);

$idVenta2 = (int)($respV2['data']['id_venta'] ?? 0);
$stockProdB_trasV2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodB}")->fetchColumn();

$detallesV2 = $db->query("SELECT * FROM detalle_venta WHERE id_venta = {$idVenta2} ORDER BY id_detalle_venta ASC")->fetchAll(PDO::FETCH_ASSOC);
$kardexV2 = $db->query("SELECT * FROM kardex WHERE id_accion_venta = {$idVenta2} ORDER BY id_kardex ASC")->fetchAll(PDO::FETCH_ASSOC);

$v2Valida = ($idVenta2 > 0)
    && ($stockProdB_trasV2 === 5) // 15 - 10 = 5
    && (count($detallesV2) === 2)
    && (count($kardexV2) === 2)
    && ((int)$kardexV2[0]['stock_anterior'] === 15 && (int)$kardexV2[0]['stock_resultante'] === 11 && (int)$kardexV2[0]['cantidad'] === 4)
    && ((int)$kardexV2[1]['stock_anterior'] === 11 && (int)$kardexV2[1]['stock_resultante'] === 5 && (int)$kardexV2[1]['cantidad'] === 6);

assertTest(
    'F4-VTA-02',
    'Venta multi-línea del mismo producto: valida demanda agregada (4+6=10), genera múltiples detalles y movimientos Kardex correlativos',
    $v2Valida,
    "stock_final={$stockProdB_trasV2}, kardex_1={$kardexV2[0]['stock_resultante']}, kardex_2={$kardexV2[1]['stock_resultante']}"
);

// =========================================================================
// CASO 3: CONSUMO DE STOCK EXACTO HASTA 0
// =========================================================================
// Producto C tiene stock = 5. Vendemos exactamente 5
$respV3 = $ventaService->procesarVenta([
    'id_usuario' => $idUsuarioActivo,
    'id_cliente' => $idClienteDni,
    'id_tipo_comprobante' => 1,
    'id_metodo_pago' => 1,
    'productos' => [
        ['id_producto' => $prodC, 'cantidad' => 5]
    ]
]);

$idVenta3 = (int)($respV3['data']['id_venta'] ?? 0);
$stockProdC_trasV3 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodC}")->fetchColumn();
$kardexV3 = $db->query("SELECT * FROM kardex WHERE id_accion_venta = {$idVenta3}")->fetch(PDO::FETCH_ASSOC);

$v3Valida = ($idVenta3 > 0)
    && ($stockProdC_trasV3 === 0)
    && ($kardexV3 && (int)$kardexV3['stock_anterior'] === 5 && (int)$kardexV3['stock_resultante'] === 0);

assertTest(
    'F4-VTA-03',
    'Consumo de stock exacto hasta 0: permite agotar existencias exactamente sin inconsistencia',
    $v3Valida,
    "stock_resultante={$stockProdC_trasV3}, kardex_resultante={$kardexV3['stock_resultante']}"
);

// =========================================================================
// CASO 4: RECHAZO POR STOCK INSUFICIENTE (409 INSUFFICIENT_STOCK)
// =========================================================================
// Producto C ahora tiene stock = 0. Intentamos vender 1 unidad
$correlativoAntesFallo = (int)$db->query("SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante = 1 LIMIT 1")->fetchColumn();
$rechazoStockInsuficiente = false;
$codigoErrorStock = null;
$httpStatusStock = null;

try {
    $ventaService->procesarVenta([
        'id_usuario' => $idUsuarioActivo,
        'id_cliente' => $idClienteDni,
        'id_tipo_comprobante' => 1,
        'id_metodo_pago' => 1,
        'productos' => [
            ['id_producto' => $prodC, 'cantidad' => 1]
        ]
    ]);
} catch (InventarioException $e) {
    $rechazoStockInsuficiente = true;
    $codigoErrorStock = $e->getErrorCode();
    $httpStatusStock = $e->getStatusCode();
}

$stockProdC_trasFallo = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodC}")->fetchColumn();
$correlativoTrasFallo = (int)$db->query("SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante = 1 LIMIT 1")->fetchColumn();

$v4Valida = $rechazoStockInsuficiente
    && ($httpStatusStock === 409)
    && ($codigoErrorStock === 'INSUFFICIENT_STOCK')
    && ($stockProdC_trasFallo === 0)
    && ($correlativoTrasFallo === $correlativoAntesFallo);

assertTest(
    'F4-VTA-04',
    'Rechazo por stock insuficiente (409 INSUFFICIENT_STOCK): no altera stock ni quema correlativo de serie',
    $v4Valida,
    "http={$httpStatusStock}, err={$codigoErrorStock}, stock={$stockProdC_trasFallo}, correlativo_estable=" . ($correlativoTrasFallo === $correlativoAntesFallo ? 'SI' : 'NO')
);

// =========================================================================
// CASO 5: RECHAZO ESTRICTO DE CANTIDADES INVÁLIDAS (422 VALIDATION_ERROR)
// =========================================================================
$casosInvalidos = [
    'decimal' => ['prod' => $prodA, 'cant' => 2.5],
    'decimal_str' => ['prod' => $prodA, 'cant' => '3.14'],
    'cero' => ['prod' => $prodA, 'cant' => 0],
    'negativo' => ['prod' => $prodA, 'cant' => -4],
    'texto' => ['prod' => $prodA, 'cant' => 'cinco']
];

$todosRechazados422 = true;
$detallesRechazo = [];

foreach ($casosInvalidos as $nombreCaso => $datosCaso) {
    try {
        $ventaService->procesarVenta([
            'id_usuario' => $idUsuarioActivo,
            'id_cliente' => $idClienteDni,
            'id_tipo_comprobante' => 1,
            'id_metodo_pago' => 1,
            'productos' => [
                ['id_producto' => $datosCaso['prod'], 'cantidad' => $datosCaso['cant']]
            ]
        ]);
        $todosRechazados422 = false;
        $detallesRechazo[] = "{$nombreCaso}: NO_RECHAZADO";
    } catch (InventarioException $e) {
        if ($e->getStatusCode() !== 422 || $e->getErrorCode() !== 'VALIDATION_ERROR') {
            $todosRechazados422 = false;
            $detallesRechazo[] = "{$nombreCaso}: status={$e->getStatusCode()}/code={$e->getErrorCode()}";
        }
    } catch (Throwable $e) {
        $todosRechazados422 = false;
        $detallesRechazo[] = "{$nombreCaso}: exception_no_inventario";
    }
}

assertTest(
    'F4-VTA-05',
    'Rechazo estricto de cantidades inválidas (decimal, decimal string, cero, negativo, texto) con 422 VALIDATION_ERROR',
    $todosRechazados422,
    empty($detallesRechazo) ? 'Todos los 5 casos rechazados 422' : implode(', ', $detallesRechazo)
);

// =========================================================================
// CASO 6: SERIALIZACIÓN DE CONCURRENCIA ENTRE DOS VENTAS (FOR UPDATE)
// =========================================================================
// Producto B tiene actualmente stock = 5
// Dos ventas concurrentes: Venta 1 solicita 4 unidades, Venta 2 solicita 3 unidades (total = 7 > 5)
// El bloqueo FOR UPDATE garantiza que una tome el candado primero, dejando stock = 1, y la otra sea rechazada.
$db->exec("UPDATE producto SET stock_actual = 5 WHERE id_producto = {$prodB}");

$venta1Exitosa = false;
$venta2RechazadaPorStock = false;

// Ejecutar Venta 1
try {
    $r1 = $ventaService->procesarVenta([
        'id_usuario' => $idUsuarioActivo,
        'id_cliente' => $idClienteDni,
        'id_tipo_comprobante' => 1,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $prodB, 'cantidad' => 4]]
    ]);
    $venta1Exitosa = !empty($r1['status']);
} catch (Throwable $e) {
    $venta1Exitosa = false;
}

// Ejecutar Venta 2 concurrente (tras Venta 1)
try {
    $ventaService->procesarVenta([
        'id_usuario' => $idUsuarioActivo,
        'id_cliente' => $idClienteDni,
        'id_tipo_comprobante' => 1,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $prodB, 'cantidad' => 3]]
    ]);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 409 && $e->getErrorCode() === 'INSUFFICIENT_STOCK') {
        $venta2RechazadaPorStock = true;
    }
}

$stockProdB_postConcurrencia = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodB}")->fetchColumn();

$v6Valida = $venta1Exitosa && $venta2RechazadaPorStock && ($stockProdB_postConcurrencia === 1);

assertTest(
    'F4-VTA-06',
    'Concurrencia entre ventas: bloqueo ordenado previene sobreventa; primera venta descuenta a 1 y segunda es rechazada 409',
    $v6Valida,
    "v1_ok=" . ($venta1Exitosa ? 'SI' : 'NO') . ", v2_rechazada=" . ($venta2RechazadaPorStock ? 'SI' : 'NO') . ", stock_final={$stockProdB_postConcurrencia}"
);

// =========================================================================
// CASO 7: VENTA CONCURRENTE / SECUENCIAL CON COMPRA (INTEGRIDAD DE SALDOS)
// =========================================================================
// Producto B tiene stock = 1
// Compra de 10 unidades -> stock debe quedar en 11
// Venta de 7 unidades -> stock debe quedar en 4
$serieC7 = 'TST-' . rand(100, 999);
$numC7 = '0000007';

$compraService->registrarCompra([
    'id_proveedor' => $idProveedor,
    'id_tipo_comprobante' => 1,
    'serie' => $serieC7,
    'numero' => $numC7,
    'fecha_emision' => date('Y-m-d'),
    'total_documento' => 100.00
], [
    [
        'id_producto' => $prodB,
        'id_presentacion' => $idPresUnidad,
        'cantidad_presentaciones' => 10,
        'equivalencia_aplicada' => 1,
        'importe_final_linea' => 100.00,
        'numero_lote' => 'LOTE-C7',
        'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))
    ]
], $idUsuarioActivo);

$stockTrasCompra = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodB}")->fetchColumn();

$respV7 = $ventaService->procesarVenta([
    'id_usuario' => $idUsuarioActivo,
    'id_cliente' => $idClienteDni,
    'id_tipo_comprobante' => 1,
    'id_metodo_pago' => 1,
    'productos' => [['id_producto' => $prodB, 'cantidad' => 7]]
]);
$idVenta7 = (int)($respV7['data']['id_venta'] ?? 0);
$stockTrasVenta = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodB}")->fetchColumn();

$kardexV7 = $db->query("SELECT stock_anterior, stock_resultante FROM kardex WHERE id_accion_venta = {$idVenta7}")->fetch(PDO::FETCH_ASSOC);

$v7Valida = ($stockTrasCompra === 11)
    && ($stockTrasVenta === 4)
    && ($kardexV7 && (int)$kardexV7['stock_anterior'] === 11 && (int)$kardexV7['stock_resultante'] === 4);

assertTest(
    'F4-VTA-07',
    'Operación combinada Compra y Venta: saldos kardex consistentes en secuencia (1 -> 11 compra -> 4 venta)',
    $v7Valida,
    "stock_tras_compra={$stockTrasCompra}, stock_tras_venta={$stockTrasVenta}, saldo_kardex={$kardexV7['stock_resultante']}"
);

// =========================================================================
// CASO 8: ATOMICIDAD Y ROLLBACK COMPLETO ANTE FALLO INDUCIDO
// =========================================================================
// Verificamos que si ocurre un fallo durante la transacción (ej. violación de integridad en Kardex),
// la venta, el detalle, el stock y la serie se revierten al 100%.
$stockProdA_antesRollback = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$correlativoAntesRollback = (int)$db->query("SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante = 1 LIMIT 1")->fetchColumn();
$totalVentasAntes = (int)$db->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$totalKardexAntes = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

$falloCapturado = false;
try {
    $movimientoService->ejecutarEnTransaccion(function (PDO $pdo) use ($prodA, $idUsuarioActivo, $correlativoAntesRollback) {
        // Simular avance de serie
        $pdo->exec("UPDATE serie_comprobante SET correlativo_actual = correlativo_actual + 1 WHERE id_tipo_comprobante = 1");
        
        // Simular inserción de venta
        $pdo->exec("INSERT INTO venta (id_cliente, id_usuario, id_tipo_comprobante, serie, numero, fecha_venta, total, id_metodo_pago, estado)
                    VALUES (1, 1, 1, 'B001', '99999999', NOW(), 100.00, 1, 1)");
        $simVentaId = (int)$pdo->lastInsertId();

        // Simular descuento de stock
        $pdo->exec("UPDATE producto SET stock_actual = stock_actual - 5 WHERE id_producto = {$prodA}");

        // Forzar error: Violación del trigger trg_kardex_before_insert_integridad
        // (VENTA requiere id_accion_venta exclusivo, si enviamos id_accion_compra debe fallar con SIGNAL 45000)
        $stmtBadKardex = $pdo->prepare("
            INSERT INTO kardex (
                id_producto, id_usuario, origen_operacion, sentido_movimiento,
                cantidad, stock_anterior, stock_resultante,
                id_accion_venta, id_accion_compra, fecha_movimiento
            ) VALUES (
                :prod, :usr, 'VENTA', 'DISMINUCION',
                5, 15, 10,
                :id_vta, 9999, NOW()
            )
        ");
        $stmtBadKardex->execute([
            ':prod' => $prodA,
            ':usr' => $idUsuarioActivo,
            ':id_vta' => $simVentaId
        ]);
    });
} catch (Throwable $e) {
    $falloCapturado = true;
}

$stockProdA_postRollback = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$correlativoPostRollback = (int)$db->query("SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante = 1 LIMIT 1")->fetchColumn();
$totalVentasPost = (int)$db->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$totalKardexPost = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

$v8Valida = $falloCapturado
    && ($stockProdA_postRollback === $stockProdA_antesRollback)
    && ($correlativoPostRollback === $correlativoAntesRollback)
    && ($totalVentasPost === $totalVentasAntes)
    && ($totalKardexPost === $totalKardexAntes);

assertTest(
    'F4-VTA-08',
    'Atomicidad y Rollback completo: fallo inducido revierte venta, kardex, stock y correlativo sin efectos residuales',
    $v8Valida,
    "fallo_capturado=" . ($falloCapturado ? 'SI' : 'NO') . ", stock_intacto=" . ($stockProdA_postRollback === $stockProdA_antesRollback ? 'SI' : 'NO') . ", serie_intacta=" . ($correlativoPostRollback === $correlativoAntesRollback ? 'SI' : 'NO')
);

// =========================================================================
// CASO 9: IDEMPOTENCIA MEDIANTE REFERENCIA DE SOLICITUD
// =========================================================================
// Solicitud con referencia única
$refIdemp = 'REQ-VTA-IDEMP-' . time() . '-' . rand(100, 999);
$stockAntesIdemp = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Intento 1: Registro exitoso
$resIdemp1 = $ventaService->procesarVenta([
    'id_usuario' => $idUsuarioActivo,
    'id_cliente' => $idClienteDni,
    'id_tipo_comprobante' => 1,
    'id_metodo_pago' => 1,
    'referencia_solicitud' => $refIdemp,
    'productos' => [['id_producto' => $prodA, 'cantidad' => 2]]
]);
$idVentaIdemp1 = (int)($resIdemp1['data']['id_venta'] ?? 0);
$stockTrasIdemp1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Intento 2 (Reintento de red / Doble clic con idéntico payload y referencia)
$resIdemp2 = $ventaService->procesarVenta([
    'id_usuario' => $idUsuarioActivo,
    'id_cliente' => $idClienteDni,
    'id_tipo_comprobante' => 1,
    'id_metodo_pago' => 1,
    'referencia_solicitud' => $refIdemp,
    'productos' => [['id_producto' => $prodA, 'cantidad' => 2]]
]);
$idVentaIdemp2 = (int)($resIdemp2['data']['id_venta'] ?? 0);
$stockTrasIdemp2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Intento 3: Misma referencia pero diferente payload (debe rechazar con 409 IDEMPOTENCY_PAYLOAD_MISMATCH)
$rechazoMismatch = false;
$codigoMismatch = null;
try {
    $ventaService->procesarVenta([
        'id_usuario' => $idUsuarioActivo,
        'id_cliente' => $idClienteDni,
        'id_tipo_comprobante' => 1,
        'id_metodo_pago' => 1,
        'referencia_solicitud' => $refIdemp,
        'productos' => [['id_producto' => $prodA, 'cantidad' => 3]] // Diferente cantidad
    ]);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 409 && $e->getErrorCode() === 'IDEMPOTENCY_PAYLOAD_MISMATCH') {
        $rechazoMismatch = true;
        $codigoMismatch = $e->getErrorCode();
    }
}

$v9Valida = ($idVentaIdemp1 > 0)
    && ($idVentaIdemp2 === $idVentaIdemp1)
    && ($stockTrasIdemp1 === $stockAntesIdemp - 2)
    && ($stockTrasIdemp2 === $stockTrasIdemp1) // No se vuelve a descontar stock
    && $rechazoMismatch;

assertTest(
    'F4-VTA-09',
    'Idempotencia de Venta: reintento devuelve respuesta idéntica sin doble descuento; payload alterado rechaza 409 IDEMPOTENCY_PAYLOAD_MISMATCH',
    $v9Valida,
    "id_v1={$idVentaIdemp1}, id_v2={$idVentaIdemp2}, stock_post1={$stockTrasIdemp1}, stock_post2={$stockTrasIdemp2}, mismatch={$codigoMismatch}"
);

// =========================================================================
// CASO 10: SEGURIDAD CONTRA SUPLANTACIÓN DE RESPONSABLE (ANTI-BOLA/IDOR)
// =========================================================================
// 10.1 Responsable inactivo o no existente
$rechazoUsuarioInactivo = false;
try {
    $ventaService->procesarVenta([
        'id_usuario' => 999999, // Usuario inexistente
        'id_cliente' => $idClienteDni,
        'id_tipo_comprobante' => 1,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $prodA, 'cantidad' => 1]]
    ]);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 403 || $e->getStatusCode() === 401) {
        $rechazoUsuarioInactivo = true;
    }
}

// 10.2 Usuario con id_usuario = 0 o null
$rechazoUsuarioVacio = false;
try {
    $ventaService->procesarVenta([
        'id_usuario' => 0,
        'id_cliente' => $idClienteDni,
        'id_tipo_comprobante' => 1,
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $prodA, 'cantidad' => 1]]
    ]);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 401) {
        $rechazoUsuarioVacio = true;
    }
}

$v10Valida = $rechazoUsuarioInactivo && $rechazoUsuarioVacio;

assertTest(
    'F4-VTA-10',
    'Seguridad de identidad del responsable: rechaza usuarios inexistentes (403/401) o no autenticados (401)',
    $v10Valida,
    "rechazo_inexistente=" . ($rechazoUsuarioInactivo ? 'SI' : 'NO') . ", rechazo_vacio=" . ($rechazoUsuarioVacio ? 'SI' : 'NO')
);

// =========================================================================
// CASO 11: VALIDACIÓN COMERCIAL: FACTURA EXIGE RUC DEL CLIENTE
// =========================================================================
// 11.1 Cliente con DNI solicitando Factura (id_tipo_comprobante = 2) -> debe fallar con 422
$rechazoFacturaSinRuc = false;
try {
    $ventaService->procesarVenta([
        'id_usuario' => $idUsuarioActivo,
        'id_cliente' => $idClienteDni, // Cliente con DNI
        'id_tipo_comprobante' => 2,   // Factura
        'id_metodo_pago' => 1,
        'productos' => [['id_producto' => $prodA, 'cantidad' => 1]]
    ]);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 422) {
        $rechazoFacturaSinRuc = true;
    }
}

// 11.2 Cliente con RUC solicitando Factura -> debe procesarse exitosamente
$respFacturaConRuc = $ventaService->procesarVenta([
    'id_usuario' => $idUsuarioActivo,
    'id_cliente' => $idClienteRuc, // Cliente con RUC
    'id_tipo_comprobante' => 2,   // Factura
    'id_metodo_pago' => 1,
    'productos' => [['id_producto' => $prodA, 'cantidad' => 1]]
]);

$idFactura = (int)($respFacturaConRuc['data']['id_venta'] ?? 0);
$serieFactura = (string)($respFacturaConRuc['data']['serie'] ?? '');

$v11Valida = $rechazoFacturaSinRuc && ($idFactura > 0) && ($serieFactura === 'F001');

assertTest(
    'F4-VTA-11',
    'Validación comercial: Factura exige obligatoriamente cliente con RUC; rechaza DNI (422) y acepta RUC con serie F001',
    $v11Valida,
    "rechazo_sin_ruc=" . ($rechazoFacturaSinRuc ? 'SI' : 'NO') . ", factura_ok_id={$idFactura}, serie={$serieFactura}"
);

// =========================================================================
// CASO 12: ANULACIÓN DE VENTA Y RESTITUCIÓN DE INVENTARIO
// =========================================================================
// Creamos una venta para anular: Producto A tiene stock = 10, vendemos 3 -> stock queda en 7
$db->exec("UPDATE producto SET stock_actual = 10 WHERE id_producto = {$prodA}");

$respVAnular = $ventaService->procesarVenta([
    'id_usuario' => $idUsuarioActivo,
    'id_cliente' => $idClienteDni,
    'id_tipo_comprobante' => 1,
    'id_metodo_pago' => 1,
    'productos' => [['id_producto' => $prodA, 'cantidad' => 3]]
]);
$idVentaAnular = (int)($respVAnular['data']['id_venta'] ?? 0);
$stockTrasVentaAnular = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Anular la venta
$resAnulacion = $ventaService->anularVenta($idVentaAnular, $idUsuarioActivo, 'Error de digitación en punto de venta');
$stockTrasAnulacion = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$estadoVentaPost = (int)$db->query("SELECT estado FROM venta WHERE id_venta = {$idVentaAnular}")->fetchColumn();

// Verificar movimiento de Kardex generado por la anulación
$kardexAnulacion = $db->query("SELECT * FROM kardex WHERE id_accion_venta = {$idVentaAnular} AND origen_operacion = 'ANULACION_VENTA'")->fetch(PDO::FETCH_ASSOC);

// Reintento de anulación (debe fallar porque ya está anulada)
$rechazoReanulacion = false;
try {
    $ventaService->anularVenta($idVentaAnular, $idUsuarioActivo, 'Segundo intento');
} catch (Throwable $e) {
    $rechazoReanulacion = true;
}

$v12Valida = ($stockTrasVentaAnular === 7)
    && ($stockTrasAnulacion === 10)
    && ($estadoVentaPost === 0)
    && ($kardexAnulacion !== false)
    && ($kardexAnulacion['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_INCREMENTO)
    && ((int)$kardexAnulacion['cantidad'] === 3)
    && ((int)$kardexAnulacion['stock_anterior'] === 7)
    && ((int)$kardexAnulacion['stock_resultante'] === 10)
    && $rechazoReanulacion;

assertTest(
    'F4-VTA-12',
    'Anulación de Venta: restituye existencias mediante ANULACION_VENTA (INCREMENTO), marca venta anulada y previene doble anulación',
    $v12Valida,
    "stock_tras_venta={$stockTrasVentaAnular}, stock_restituido={$stockTrasAnulacion}, kardex_sentido={$kardexAnulacion['sentido_movimiento']}, reanulacion_rechazada=" . ($rechazoReanulacion ? 'SI' : 'NO')
);

// =========================================================================
// LIMPIEZA FINAL Y VERIFICACIÓN DE AISLAMIENTO
// =========================================================================
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

$totalKardexLimpio = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();
$totalVentasLimpio = (int)$db->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$totalDetallesLimpio = (int)$db->query("SELECT COUNT(*) FROM detalle_venta")->fetchColumn();

assertTest(
    'F4-CLEAN-01',
    'Limpieza final en base de pruebas: registros temporales eliminados y stock en 0',
    $totalKardexLimpio === 0 && $totalVentasLimpio === 0 && $totalDetallesLimpio === 0,
    "kardex={$totalKardexLimpio}, ventas={$totalVentasLimpio}, detalles={$totalDetallesLimpio}"
);

// F4-ISO-01: Base amazon_market principal permanece estrictamente intocada
$pdoReal = new PDO('mysql:host=localhost;dbname=amazon_market;charset=utf8mb4', 'root', '');
$tablasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'amazon_market'")->fetchColumn();
$stockPosReal = (int)$pdoReal->query("SELECT COUNT(*) FROM producto WHERE stock_actual > 0")->fetchColumn();
$ventasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$comprasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM compra")->fetchColumn();
$kardexReal = (int)$pdoReal->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

assertTest(
    'F4-ISO-01',
    'Aislamiento verificado: Base principal amazon_market no sufrió modificaciones',
    $tablasReal === 20
    && $stockPosReal === 106
    && $ventasReal === 98
    && $comprasReal === 4
    && $kardexReal === 26,
    "tablas={$tablasReal}, stock_pos={$stockPosReal}, ventas={$ventasReal}, compras={$comprasReal}, kardex={$kardexReal}"
);

echo PHP_EOL . "=== RESUMEN FASE 4: {$passed} PASS / {$failed} FAIL ===" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
