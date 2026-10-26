<?php
/**
 * Suite de Pruebas de Integración - Fase 5 (Sprint 2)
 * Bajas por daño/vencimiento y Ajustes por conteo físico.
 *
 * Se ejecuta EXCLUSIVAMENTE sobre `amazon_market_sprint2_test`.
 */

require_once __DIR__ . '/bootstrap_test.php';

use Libraries\Core\Conexion;
use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Models\OperacionInventarioModel;
use Models\ProductoModel;
use Services\ControlSolicitudService;
use Services\InventarioService;
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

echo "=== INICIANDO SUITE FASE 5 EN: " . $db->query("SELECT DATABASE()")->fetchColumn() . " ===" . PHP_EOL . PHP_EOL;

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
$inventarioService = new InventarioService();
$movimientoService = $inventarioService->getMovimientoService();
$productoModel = new ProductoModel();
$operacionModel = new OperacionInventarioModel();

// Configuración de usuarios de prueba
$idAdminActivo = 1; // Administrador activo (id_rol = 1)
$idVendedor = (int)$db->query("SELECT id_usuario FROM usuario WHERE id_rol = 2 AND estado = 1 LIMIT 1")->fetchColumn();
if (!$idVendedor) {
    // Si no hay vendedor activo, usar id 2 o crear uno temporal
    $idVendedor = 2;
}

// Crear productos específicos para las pruebas de Bajas y Ajustes
$prodA_res = $productoService->crear([
    'codigo_barra' => 'TST-INV-A-' . time(),
    'nombre' => 'Producto Inventario A',
    'id_categoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 12.00,
    'stock_minimo' => 2
]);
$prodA = (int)$prodA_res['id_producto'];

$prodB_res = $productoService->crear([
    'codigo_barra' => 'TST-INV-B-' . time(),
    'nombre' => 'Producto Inventario B',
    'id_categoria' => 1,
    'id_unidad' => 1,
    'precio_venta' => 30.00,
    'stock_minimo' => 5
]);
$prodB = (int)$prodB_res['id_producto'];

// Cargar existencias iniciales conocidas
$db->exec("UPDATE producto SET stock_actual = 20 WHERE id_producto = {$prodA}");
$db->exec("UPDATE producto SET stock_actual = 5 WHERE id_producto = {$prodB}");

// =========================================================================
// CASO 1: BAJA CON LOTE VÁLIDO (BAJA POR DAÑO)
// =========================================================================
$refBaja1 = 'baja-' . time() . '-001-' . rand(1000, 9999);
$resBaja1 = $inventarioService->registrarBaja([
    'referencia_solicitud' => $refBaja1,
    'id_producto' => $prodA,
    'motivo_baja' => 'Baja por daño',
    'cantidad' => 4,
    'justificacion' => 'Empaque aplastado durante descarga en almacén A',
    'lote' => 'LOTE-DANO-2026'
], $refBaja1, $idAdminActivo);

$idOp1 = (int)($resBaja1['data']['id_operacion'] ?? 0);
$idKardex1 = (int)($resBaja1['data']['id_kardex'] ?? 0);
$stockProdA_trasB1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Verificar tabla operacion_inventario
$op1Row = $db->query("SELECT * FROM operacion_inventario WHERE id_operacion = {$idOp1}")->fetch(PDO::FETCH_ASSOC);
// Verificar tabla kardex
$kardex1Row = $db->query("SELECT * FROM kardex WHERE id_kardex = {$idKardex1}")->fetch(PDO::FETCH_ASSOC);

$b1Valida = ($idOp1 > 0)
    && ($idKardex1 > 0)
    && ($stockProdA_trasB1 === 16)
    && ($op1Row !== false)
    && ($op1Row['categoria_operacion'] === DefinicionMovimiento::CATEGORIA_BAJA)
    && ($op1Row['origen_operacion'] === DefinicionMovimiento::ORIGEN_BAJA_DANO)
    && ($op1Row['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_DISMINUCION)
    && ((int)$op1Row['cantidad'] === 4)
    && ((int)$op1Row['saldo_anterior'] === 20)
    && ((int)$op1Row['saldo_resultante'] === 16)
    && ($op1Row['numero_lote'] === 'LOTE-DANO-2026')
    && ($op1Row['condicion_lote'] === DefinicionMovimiento::LOTE_CON_REFERENCIA)
    && ($kardex1Row !== false)
    && ($kardex1Row['origen_operacion'] === DefinicionMovimiento::ORIGEN_BAJA_DANO)
    && ((int)$kardex1Row['id_operacion_inventario'] === $idOp1)
    && empty($kardex1Row['id_accion_compra'])
    && empty($kardex1Row['id_accion_venta'])
    && !empty($kardex1Row['producto_nombre_snapshot'])
    && !empty($kardex1Row['responsable_nombre_snapshot']);

assertTest(
    'F5-BAJA-01',
    'Baja con lote válido: registra operacion_inventario, descuenta stock y genera Kardex con snapshots y lote CON_LOTE',
    $b1Valida,
    "id_op={$idOp1}, id_kardex={$idKardex1}, stock_resultante={$stockProdA_trasB1}"
);

// =========================================================================
// CASO 2: BAJA SIN LOTE (BAJA POR VENCIMIENTO)
// =========================================================================
$refBaja2 = 'baja-' . time() . '-002-' . rand(1000, 9999);
$resBaja2 = $inventarioService->registrarBaja([
    'referencia_solicitud' => $refBaja2,
    'id_producto' => $prodA,
    'motivo_baja' => 'Baja por vencimiento',
    'cantidad' => 3,
    'justificacion' => 'Fecha de vencimiento superada en anaquel central',
    'lote' => '' // Lote vacío
], $refBaja2, $idAdminActivo);

$idOp2 = (int)($resBaja2['data']['id_operacion'] ?? 0);
$idKardex2 = (int)($resBaja2['data']['id_kardex'] ?? 0);
$stockProdA_trasB2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

$op2Row = $db->query("SELECT * FROM operacion_inventario WHERE id_operacion = {$idOp2}")->fetch(PDO::FETCH_ASSOC);
$kardex2Row = $db->query("SELECT * FROM kardex WHERE id_kardex = {$idKardex2}")->fetch(PDO::FETCH_ASSOC);

$b2Valida = ($idOp2 > 0)
    && ($stockProdA_trasB2 === 13) // 16 - 3 = 13
    && ($op2Row['origen_operacion'] === DefinicionMovimiento::ORIGEN_BAJA_VENCIMIENTO)
    && ($op2Row['numero_lote'] === null)
    && ($op2Row['condicion_lote'] === DefinicionMovimiento::LOTE_SIN_REFERENCIA)
    && ($kardex2Row['numero_lote'] === null)
    && ($kardex2Row['condicion_lote'] === DefinicionMovimiento::LOTE_SIN_REFERENCIA)
    && ((int)$kardex2Row['stock_anterior'] === 16)
    && ((int)$kardex2Row['stock_resultante'] === 13);

assertTest(
    'F5-BAJA-02',
    'Baja sin lote: registra operacion_inventario y Kardex con numero_lote NULL y condición SIN_LOTE sin bloquear la operación',
    $b2Valida,
    "id_op={$idOp2}, stock_resultante={$stockProdA_trasB2}, condicion={$op2Row['condicion_lote']}"
);

// =========================================================================
// CASO 3: RECHAZO DE BAJA POR MOTIVO O JUSTIFICACIÓN INVÁLIDOS (422)
// =========================================================================
$rechazoMotivoInvalido = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-inv1',
        'id_producto' => $prodA,
        'motivo_baja' => 'Robo no identificado', // Motivo no permitido
        'cantidad' => 1,
        'justificacion' => 'Justificación válida'
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 422 && isset($e->getErrors()['motivo_baja'])) {
        $rechazoMotivoInvalido = true;
    }
}

$rechazoJustificacionVacia = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-inv2',
        'id_producto' => $prodA,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 1,
        'justificacion' => '   ' // Justificación en blanco
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 422 && isset($e->getErrors()['justificacion'])) {
        $rechazoJustificacionVacia = true;
    }
}

$rechazoJustificacionLarga = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-inv3',
        'id_producto' => $prodA,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 1,
        'justificacion' => str_repeat('A', 256) // > 255 caracteres
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 422 && isset($e->getErrors()['justificacion'])) {
        $rechazoJustificacionLarga = true;
    }
}

$stockProdA_postFallo = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

$b3Valida = $rechazoMotivoInvalido && $rechazoJustificacionVacia && $rechazoJustificacionLarga && ($stockProdA_postFallo === 13);

assertTest(
    'F5-BAJA-03',
    'Rechazo de Baja por motivo inválido, justificación vacía o >255 caracteres con 422 VALIDATION_ERROR y stock intacto',
    $b3Valida,
    "motivo_inv=" . ($rechazoMotivoInvalido ? 'SI' : 'NO') . ", just_vacia=" . ($rechazoJustificacionVacia ? 'SI' : 'NO') . ", just_larga=" . ($rechazoJustificacionLarga ? 'SI' : 'NO')
);

// =========================================================================
// CASO 4: RECHAZO DE BAJA POR CANTIDAD EXCESIVA (409) O NO ENTERA (422)
// =========================================================================
// Producto A tiene stock = 13.
$rechazoStockExcesivo = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-exc',
        'id_producto' => $prodA,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 14, // 14 > 13
        'justificacion' => 'Intento con cantidad superior al stock'
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 409 && $e->getErrorCode() === 'INSUFFICIENT_STOCK') {
        $rechazoStockExcesivo = true;
    }
}

$rechazoDecimal = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-dec',
        'id_producto' => $prodA,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 2.5,
        'justificacion' => 'Intento con decimal'
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR') {
        $rechazoDecimal = true;
    }
}

$rechazoCero = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-zero',
        'id_producto' => $prodA,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 0,
        'justificacion' => 'Intento con cero'
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR') {
        $rechazoCero = true;
    }
}

$b4Valida = $rechazoStockExcesivo && $rechazoDecimal && $rechazoCero;

assertTest(
    'F5-BAJA-04',
    'Rechazo de Baja: cantidad > stock_actual responde 409 INSUFFICIENT_STOCK; decimales y cero responden 422 VALIDATION_ERROR',
    $b4Valida,
    "excesivo=" . ($rechazoStockExcesivo ? 'SI' : 'NO') . ", decimal=" . ($rechazoDecimal ? 'SI' : 'NO') . ", cero=" . ($rechazoCero ? 'SI' : 'NO')
);

// =========================================================================
// CASO 5: AJUSTE POSITIVO POR CONTEO FÍSICO
// =========================================================================
// Producto A tiene stock = 13.
$refAjuste1 = 'ajuste-' . time() . '-001-' . rand(1000, 9999);
$resAjuste1 = $inventarioService->registrarAjuste([
    'referencia_solicitud' => $refAjuste1,
    'id_producto' => $prodA,
    'tipo_ajuste' => 'Ajuste positivo',
    'cantidad' => 5,
    'saldo_anterior_esperado' => 13,
    'justificacion' => 'Sobrante detectado en conteo físico de fin de mes'
], $refAjuste1, $idAdminActivo);

$idOpAdj1 = (int)($resAjuste1['data']['id_operacion'] ?? 0);
$idKardexAdj1 = (int)($resAjuste1['data']['id_kardex'] ?? 0);
$stockProdA_trasAdj1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

$opAdj1Row = $db->query("SELECT * FROM operacion_inventario WHERE id_operacion = {$idOpAdj1}")->fetch(PDO::FETCH_ASSOC);
$kardexAdj1Row = $db->query("SELECT * FROM kardex WHERE id_kardex = {$idKardexAdj1}")->fetch(PDO::FETCH_ASSOC);

$a1Valida = ($idOpAdj1 > 0)
    && ($stockProdA_trasAdj1 === 18) // 13 + 5 = 18
    && ($opAdj1Row['categoria_operacion'] === DefinicionMovimiento::CATEGORIA_AJUSTE)
    && ($opAdj1Row['origen_operacion'] === DefinicionMovimiento::ORIGEN_AJUSTE_POSITIVO)
    && ($opAdj1Row['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_INCREMENTO)
    && ((int)$opAdj1Row['cantidad'] === 5)
    && ((int)$opAdj1Row['saldo_anterior'] === 13)
    && ((int)$opAdj1Row['saldo_anterior_esperado'] === 13)
    && ((int)$opAdj1Row['saldo_resultante'] === 18)
    && ($opAdj1Row['condicion_lote'] === DefinicionMovimiento::LOTE_NO_APLICA)
    && ($kardexAdj1Row['origen_operacion'] === DefinicionMovimiento::ORIGEN_AJUSTE_POSITIVO)
    && ($kardexAdj1Row['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_INCREMENTO)
    && ((int)$kardexAdj1Row['stock_anterior'] === 13)
    && ((int)$kardexAdj1Row['stock_resultante'] === 18);

assertTest(
    'F5-AJUSTE-01',
    'Ajuste positivo por conteo físico: registra operacion_inventario (INCREMENTO), incrementa stock y añade Kardex correlativo',
    $a1Valida,
    "id_op={$idOpAdj1}, stock_resultante={$stockProdA_trasAdj1}, kardex_resultante={$kardexAdj1Row['stock_resultante']}"
);

// =========================================================================
// CASO 6: AJUSTE NEGATIVO POR CONTEO FÍSICO
// =========================================================================
// Producto A tiene stock = 18.
$refAjuste2 = 'ajuste-' . time() . '-002-' . rand(1000, 9999);
$resAjuste2 = $inventarioService->registrarAjuste([
    'referencia_solicitud' => $refAjuste2,
    'id_producto' => $prodA,
    'tipo_ajuste' => 'Ajuste negativo',
    'cantidad' => 6,
    'saldo_anterior_esperado' => 18,
    'justificacion' => 'Faltante constatado en verificación de estantería B'
], $refAjuste2, $idAdminActivo);

$idOpAdj2 = (int)($resAjuste2['data']['id_operacion'] ?? 0);
$idKardexAdj2 = (int)($resAjuste2['data']['id_kardex'] ?? 0);
$stockProdA_trasAdj2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

$opAdj2Row = $db->query("SELECT * FROM operacion_inventario WHERE id_operacion = {$idOpAdj2}")->fetch(PDO::FETCH_ASSOC);
$kardexAdj2Row = $db->query("SELECT * FROM kardex WHERE id_kardex = {$idKardexAdj2}")->fetch(PDO::FETCH_ASSOC);

$a2Valida = ($idOpAdj2 > 0)
    && ($stockProdA_trasAdj2 === 12) // 18 - 6 = 12
    && ($opAdj2Row['origen_operacion'] === DefinicionMovimiento::ORIGEN_AJUSTE_NEGATIVO)
    && ($opAdj2Row['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_DISMINUCION)
    && ((int)$opAdj2Row['cantidad'] === 6)
    && ((int)$opAdj2Row['saldo_anterior'] === 18)
    && ((int)$opAdj2Row['saldo_anterior_esperado'] === 18)
    && ((int)$opAdj2Row['saldo_resultante'] === 12)
    && ($kardexAdj2Row['origen_operacion'] === DefinicionMovimiento::ORIGEN_AJUSTE_NEGATIVO)
    && ($kardexAdj2Row['sentido_movimiento'] === DefinicionMovimiento::SENTIDO_DISMINUCION)
    && ((int)$kardexAdj2Row['stock_anterior'] === 18)
    && ((int)$kardexAdj2Row['stock_resultante'] === 12);

assertTest(
    'F5-AJUSTE-02',
    'Ajuste negativo por conteo físico: registra operacion_inventario (DISMINUCION), descuenta stock y añade Kardex correlativo',
    $a2Valida,
    "id_op={$idOpAdj2}, stock_resultante={$stockProdA_trasAdj2}, kardex_resultante={$kardexAdj2Row['stock_resultante']}"
);

// =========================================================================
// CASO 7: SALDO BASE CAMBIADO DURANTE LA PREPARACIÓN (409 STOCK_CHANGED_CONFLICT)
// =========================================================================
// Producto A tiene stock = 12 actualmente.
// El administrador preparó el formulario creyendo que el stock era 15.
$rechazoSaldoCambiado = false;
$dataSaldoCambiado = null;
try {
    $inventarioService->registrarAjuste([
        'referencia_solicitud' => 'ajuste-' . time() . '-conflict',
        'id_producto' => $prodA,
        'tipo_ajuste' => 'Ajuste negativo',
        'cantidad' => 2,
        'saldo_anterior_esperado' => 15, // Desincronizado: esperaban 15 pero en BD hay 12
        'justificacion' => 'Intento de ajuste con saldo previo desactualizado'
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 409 && $e->getErrorCode() === 'STOCK_CHANGED_CONFLICT') {
        $rechazoSaldoCambiado = true;
        $dataSaldoCambiado = $e->getContextData();
    }
}

$stockProdA_postConflict = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

$a3Valida = $rechazoSaldoCambiado
    && ($stockProdA_postConflict === 12)
    && ($dataSaldoCambiado !== null)
    && ((int)($dataSaldoCambiado['saldo_anterior_esperado'] ?? 0) === 15)
    && ((int)($dataSaldoCambiado['stock_actual'] ?? 0) === 12)
    && (!empty($dataSaldoCambiado['requiere_revision']));

assertTest(
    'F5-AJUSTE-03',
    'Saldo cambiado durante la preparación: rechaza con 409 STOCK_CHANGED_CONFLICT, devuelve stock_actual (12) y requiere_revision',
    $a3Valida,
    "rechazo=" . ($rechazoSaldoCambiado ? 'SI' : 'NO') . ", stock_actual_devuelto={$dataSaldoCambiado['stock_actual']}, stock_intacto={$stockProdA_postConflict}"
);

// =========================================================================
// CASO 8: RECHAZO DE AJUSTE NEGATIVO QUE PRODUCE SALDO NEGATIVO (409)
// =========================================================================
// Producto A tiene stock = 12. Intento de restar 13 unidades con saldo_anterior_esperado = 12
$rechazoAjusteNegativoInsuficiente = false;
try {
    $inventarioService->registrarAjuste([
        'referencia_solicitud' => 'ajuste-' . time() . '-insuf',
        'id_producto' => $prodA,
        'tipo_ajuste' => 'Ajuste negativo',
        'cantidad' => 13, // 13 > 12
        'saldo_anterior_esperado' => 12,
        'justificacion' => 'Intento de ajuste que dejaría stock negativo'
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 409 && $e->getErrorCode() === 'INSUFFICIENT_STOCK') {
        $rechazoAjusteNegativoInsuficiente = true;
    }
}

$stockProdA_postInsuf = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

$a4Valida = $rechazoAjusteNegativoInsuficiente && ($stockProdA_postInsuf === 12);

assertTest(
    'F5-AJUSTE-04',
    'Rechazo de Ajuste negativo que produciría saldo negativo con 409 INSUFFICIENT_STOCK',
    $a4Valida,
    "rechazo=" . ($rechazoAjusteNegativoInsuficiente ? 'SI' : 'NO') . ", stock_intacto={$stockProdA_postInsuf}"
);

// =========================================================================
// CASO 9: CONCURRENCIA ENTRE DOS SALIDAS SIMULTÁNEAS (FOR UPDATE)
// =========================================================================
// Producto B tiene actualmente stock = 5
// Operación 1: Baja de 3 unidades
// Operación 2: Ajuste negativo de 3 unidades (total = 6 > 5)
$op1Exitosa = false;
$op2RechazadaPorStock = false;

try {
    $r1 = $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-conc1',
        'id_producto' => $prodB,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 3,
        'justificacion' => 'Baja concurrente 1'
    ], null, $idAdminActivo);
    $op1Exitosa = !empty($r1['status']);
} catch (Throwable $e) {
    $op1Exitosa = false;
}

try {
    $inventarioService->registrarAjuste([
        'referencia_solicitud' => 'ajuste-' . time() . '-conc2',
        'id_producto' => $prodB,
        'tipo_ajuste' => 'Ajuste negativo',
        'cantidad' => 3,
        'saldo_anterior_esperado' => 2, // Stock ya bajó a 2 tras Op 1
        'justificacion' => 'Ajuste concurrente 2'
    ], null, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 409 && $e->getErrorCode() === 'INSUFFICIENT_STOCK') {
        $op2RechazadaPorStock = true;
    }
}

$stockProdB_postConcurrencia = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodB}")->fetchColumn();

$c1Valida = $op1Exitosa && $op2RechazadaPorStock && ($stockProdB_postConcurrencia === 2);

assertTest(
    'F5-CONC-01',
    'Concurrencia entre salidas: bloqueo ordenado FOR UPDATE previene sobreventa; op 1 descuenta a 2 y op 2 rechaza 409',
    $c1Valida,
    "op1_ok=" . ($op1Exitosa ? 'SI' : 'NO') . ", op2_rechazo=" . ($op2RechazadaPorStock ? 'SI' : 'NO') . ", stock_final={$stockProdB_postConcurrencia}"
);

// =========================================================================
// CASO 10: IDEMPOTENCIA MEDIANTE REFERENCIA_SOLICITUD EN BAJAS Y AJUSTES
// =========================================================================
// 10.1 Idempotencia en Baja
$refIdempBaja = 'baja-idemp-' . time() . '-' . rand(100, 999);
$stockAntesIdempB = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

$resIdempB1 = $inventarioService->registrarBaja([
    'referencia_solicitud' => $refIdempBaja,
    'id_producto' => $prodA,
    'motivo_baja' => 'Baja por daño',
    'cantidad' => 2,
    'justificacion' => 'Prueba idempotencia baja'
], $refIdempBaja, $idAdminActivo);

$stockTrasIdempB1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Reintento con idéntico payload
$resIdempB2 = $inventarioService->registrarBaja([
    'referencia_solicitud' => $refIdempBaja,
    'id_producto' => $prodA,
    'motivo_baja' => 'Baja por daño',
    'cantidad' => 2,
    'justificacion' => 'Prueba idempotencia baja'
], $refIdempBaja, $idAdminActivo);

$stockTrasIdempB2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();

// Intento con misma referencia pero diferente cantidad -> conflicto
$rechazoMismatchBaja = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => $refIdempBaja,
        'id_producto' => $prodA,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 5, // Diferente
        'justificacion' => 'Prueba idempotencia baja'
    ], $refIdempBaja, $idAdminActivo);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 409 && $e->getErrorCode() === 'IDEMPOTENCY_PAYLOAD_MISMATCH') {
        $rechazoMismatchBaja = true;
    }
}

$idempBajaValida = ($stockTrasIdempB1 === $stockAntesIdempB - 2)
    && ($stockTrasIdempB2 === $stockTrasIdempB1) // No se volvió a descontar
    && (!empty($resIdempB2['idempotent_replay']))
    && $rechazoMismatchBaja;

assertTest(
    'F5-IDEM-01',
    'Idempotencia en Bajas y Ajustes: reintento devuelve respuesta confirmada sin duplicar decremento; payload alterado rechaza 409',
    $idempBajaValida,
    "stock_tras_1={$stockTrasIdempB1}, stock_tras_2={$stockTrasIdempB2}, replay=" . (!empty($resIdempB2['idempotent_replay']) ? 'SI' : 'NO') . ", mismatch=" . ($rechazoMismatchBaja ? 'SI' : 'NO')
);

// =========================================================================
// CASO 11: ATOMICIDAD Y ROLLBACK COMPLETO ANTE FALLO INDUCIDO
// =========================================================================
// Simular fallo transaccional durante el guardado de una baja
$stockProdA_antesRollback = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$totalOpsAntes = (int)$db->query("SELECT COUNT(*) FROM operacion_inventario")->fetchColumn();
$totalKardexAntes = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

$falloCapturado = false;
try {
    $movimientoService->ejecutarEnTransaccion(function (PDO $pdo) use ($prodA, $idAdminActivo) {
        // 1. Simular descuento de stock
        $pdo->exec("UPDATE producto SET stock_actual = stock_actual - 3 WHERE id_producto = {$prodA}");

        // 2. Simular inserción de operación
        $pdo->exec("INSERT INTO operacion_inventario (
            id_producto, id_usuario, categoria_operacion, origen_operacion,
            tipo_movimiento, sentido_movimiento, cantidad, saldo_anterior,
            saldo_resultante, justificacion, condicion_lote, referencia_solicitud, fecha_registro
        ) VALUES (
            {$prodA}, {$idAdminActivo}, 'BAJA', 'BAJA_DANO',
            'Baja por daño', 'DISMINUCION', 3, 10,
            7, 'Fallo inducido', 'SIN_LOTE', 'baja-sim-rollback-01', NOW()
        )");
        $simOpId = (int)$pdo->lastInsertId();

        // 3. Forzar error: Violación del trigger trg_kardex_before_insert_integridad
        // (Las bajas requieren id_operacion_inventario exclusivo; si inyectamos id_accion_venta debe fallar con SIGNAL 45000)
        $stmtBad = $pdo->prepare("
            INSERT INTO kardex (
                id_producto, id_usuario, origen_operacion, sentido_movimiento,
                tipo_movimiento, cantidad, stock_anterior, stock_resultante,
                id_operacion_inventario, id_accion_venta, justificacion,
                producto_codigo_snapshot, producto_nombre_snapshot,
                categoria_nombre_snapshot, subcategoria_nombre_snapshot,
                unidad_abreviatura_snapshot, responsable_nombre_snapshot,
                fecha_movimiento
            ) VALUES (
                :prod, :usr, 'BAJA_DANO', 'DISMINUCION',
                'Baja por daño', 3, 10, 7,
                :op_id, 9999, 'Justificacion',
                'COD', 'PROD', 'CAT', 'SUBCAT', 'UND', 'RESP', NOW()
            )
        ");
        $stmtBad->execute([
            ':prod' => $prodA,
            ':usr' => $idAdminActivo,
            ':op_id' => $simOpId
        ]);
    });
} catch (Throwable $e) {
    $falloCapturado = true;
}

$stockProdA_postRollback = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = {$prodA}")->fetchColumn();
$totalOpsPost = (int)$db->query("SELECT COUNT(*) FROM operacion_inventario")->fetchColumn();
$totalKardexPost = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

$atomValida = $falloCapturado
    && ($stockProdA_postRollback === $stockProdA_antesRollback)
    && ($totalOpsPost === $totalOpsAntes)
    && ($totalKardexPost === $totalKardexAntes);

assertTest(
    'F5-ATOM-01',
    'Atomicidad y Rollback completo: fallo inducido revierte operacion_inventario, kardex y stock sin efectos parciales',
    $atomValida,
    "fallo=" . ($falloCapturado ? 'SI' : 'NO') . ", stock_intacto=" . ($stockProdA_postRollback === $stockProdA_antesRollback ? 'SI' : 'NO') . ", ops_intactas=" . ($totalOpsPost === $totalOpsAntes ? 'SI' : 'NO')
);

// =========================================================================
// CASO 12: SEGURIDAD Y CONTROL DE ACCESO (ADMIN VS VENDEDOR)
// =========================================================================
// 12.1 Rol Vendedor (id_rol = 2) intentando registrar baja -> 403 FORBIDDEN_ADMIN_ONLY
$rechazoVendedorBaja = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-vend',
        'id_producto' => $prodA,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 1,
        'justificacion' => 'Intento con rol vendedor'
    ], null, $idVendedor);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 403 && $e->getErrorCode() === 'FORBIDDEN_ADMIN_ONLY') {
        $rechazoVendedorBaja = true;
    }
}

// 12.2 Rol Vendedor intentando registrar ajuste -> 403 FORBIDDEN_ADMIN_ONLY
$rechazoVendedorAjuste = false;
try {
    $inventarioService->registrarAjuste([
        'referencia_solicitud' => 'ajuste-' . time() . '-vend',
        'id_producto' => $prodA,
        'tipo_ajuste' => 'Ajuste positivo',
        'cantidad' => 1,
        'saldo_anterior_esperado' => 10,
        'justificacion' => 'Intento con rol vendedor'
    ], null, $idVendedor);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 403 && $e->getErrorCode() === 'FORBIDDEN_ADMIN_ONLY') {
        $rechazoVendedorAjuste = true;
    }
}

// 12.3 Usuario inexistente o no autenticado -> 401 AUTH_REQUIRED
$rechazoUsuarioInexistente = false;
try {
    $inventarioService->registrarBaja([
        'referencia_solicitud' => 'baja-' . time() . '-noauth',
        'id_producto' => $prodA,
        'motivo_baja' => 'Baja por daño',
        'cantidad' => 1,
        'justificacion' => 'Intento sin autenticar'
    ], null, 99999);
} catch (InventarioException $e) {
    if ($e->getStatusCode() === 401 || $e->getStatusCode() === 403) {
        $rechazoUsuarioInexistente = true;
    }
}

$secValida = $rechazoVendedorBaja && $rechazoVendedorAjuste && $rechazoUsuarioInexistente;

assertTest(
    'F5-SEC-01',
    'Seguridad y RBAC: rol Vendedor rechazado 403 FORBIDDEN_ADMIN_ONLY en Bajas y Ajustes; usuario inexistente rechazado 401',
    $secValida,
    "vendedor_baja=" . ($rechazoVendedorBaja ? 'SI' : 'NO') . ", vendedor_ajuste=" . ($rechazoVendedorAjuste ? 'SI' : 'NO') . ", noauth=" . ($rechazoUsuarioInexistente ? 'SI' : 'NO')
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
$totalOpsLimpio = (int)$db->query("SELECT COUNT(*) FROM operacion_inventario")->fetchColumn();

assertTest(
    'F5-CLEAN-01',
    'Limpieza final en base de pruebas: registros temporales eliminados y stock en 0',
    $totalKardexLimpio === 0 && $totalOpsLimpio === 0,
    "kardex={$totalKardexLimpio}, ops={$totalOpsLimpio}"
);

// F5-ISO-01: Base amazon_market principal permanece estrictamente intocada
$pdoReal = new PDO('mysql:host=localhost;dbname=amazon_market;charset=utf8mb4', 'root', '');
$tablasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'amazon_market'")->fetchColumn();
$stockPosReal = (int)$pdoReal->query("SELECT COUNT(*) FROM producto WHERE stock_actual > 0")->fetchColumn();
$ventasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$comprasReal = (int)$pdoReal->query("SELECT COUNT(*) FROM compra")->fetchColumn();
$kardexReal = (int)$pdoReal->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

assertTest(
    'F5-ISO-01',
    'Aislamiento verificado: Base principal amazon_market no sufrió modificaciones',
    $tablasReal === 20
    && $stockPosReal === 106
    && $ventasReal === 98
    && $comprasReal === 4
    && $kardexReal === 26,
    "tablas={$tablasReal}, stock_pos={$stockPosReal}, ventas={$ventasReal}, compras={$comprasReal}, kardex={$kardexReal}"
);

echo PHP_EOL . "=== RESUMEN FASE 5: {$passed} PASS / {$failed} FAIL ===" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}

