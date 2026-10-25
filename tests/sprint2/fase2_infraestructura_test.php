<?php
/**
 * Suite de Pruebas de Integración - Fase 2 (Sprint 2)
 * Estructura de datos, seguridad, representación de movimientos,
 * infraestructura transaccional e idempotencia.
 *
 * Se ejecuta EXCLUSIVAMENTE sobre `amazon_market_sprint2_test`.
 */

require_once __DIR__ . '/bootstrap_test.php';

use Libraries\Core\Conexion;
use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Libraries\Middleware\AuthMiddleware;
use Libraries\Middleware\RBACMiddleware;
use Libraries\Middleware\CSRFMiddleware;
use Models\SubcategoriaModel;
use Services\MovimientoInventarioService;
use Services\ControlSolicitudService;

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

echo "=== INICIANDO SUITE FASE 2 EN: " . $db->query("SELECT DATABASE()")->fetchColumn() . " ===" . PHP_EOL . PHP_EOL;

// Asegurar estado limpio antes de iniciar la suite
$db->exec("DELETE FROM kardex");
$db->exec("DELETE FROM operacion_inventario");
$db->exec("DELETE FROM control_solicitud_idempotencia");
$db->exec("UPDATE producto SET stock_actual = 0");

// =========================================================================
// BLOQUE 1: Verificación de estructura, subcategorías y catálogo
// =========================================================================
$totalProductos = (int)$db->query("SELECT COUNT(*) FROM producto")->fetchColumn();
$conSubcategoria = (int)$db->query("SELECT COUNT(*) FROM producto WHERE id_subcategoria IS NOT NULL")->fetchColumn();
$fraccionariosONegativos = (int)$db->query("SELECT COUNT(*) FROM producto WHERE stock_actual < 0 OR stock_minimo < 0")->fetchColumn();
assertTest(
    'F2-DB-01',
    'Los 107 productos tienen stock entero no negativo y subcategoría asignada',
    $totalProductos === 107 && $conSubcategoria === 107 && $fraccionariosONegativos === 0,
    "total={$totalProductos}, con_subcategoria={$conSubcategoria}, invalidos={$fraccionariosONegativos}"
);

$subcatModel = new SubcategoriaModel();
$agrupadasTodas = $subcatModel->obtenerTodasAgrupadasPorCategoria(false);
$agrupadasActivas = $subcatModel->obtenerTodasAgrupadasPorCategoria(true);
assertTest(
    'F2-DB-02',
    'Las 6 categorías (5 activas y 1 inactiva) cuentan con su subcategoría General predeterminada',
    count($agrupadasTodas) === 6 && count($agrupadasActivas) === 5,
    'todas=' . count($agrupadasTodas) . ', activas=' . count($agrupadasActivas)
);

// Verificar trigger de creación automática de subcategoría General al crear categoría
$db->beginTransaction();
try {
    $db->exec("INSERT INTO categoria (nombre, descripcion, estado) VALUES ('Cat Temp Fase2', 'Prueba trigger', 1)");
    $idCatTemp = (int)$db->lastInsertId();
    $subcatsTemp = $subcatModel->obtenerPorCategoria($idCatTemp, true);
    $tieneGeneral = count($subcatsTemp) === 1 && $subcatsTemp[0]['nombre'] === 'General';
    assertTest(
        'F2-DB-03',
        'Trigger trg_categoria_after_insert_subcategoria_general crea subcategoría General automáticamente',
        $tieneGeneral,
        'subcat=' . ($subcatsTemp[0]['nombre'] ?? 'NONE')
    );
} finally {
    $db->rollBack();
}

// Verificar rechazo de subcategoría que no corresponde a la categoría del producto (FK compuesta)
$rechazoSubcatCruzada = false;
$db->beginTransaction();
try {
    $prodRow = $db->query("SELECT id_producto, id_categoria FROM producto WHERE id_producto = 1")->fetch(\PDO::FETCH_ASSOC);
    $idCatProd1 = (int)$prodRow['id_categoria'];
    $idSubcatOtraCat = (int)$db->query("SELECT id_subcategoria FROM subcategoria WHERE id_categoria <> {$idCatProd1} LIMIT 1")->fetchColumn();
    $stmt = $db->prepare("UPDATE producto SET id_subcategoria = ? WHERE id_producto = 1");
    $stmt->execute([$idSubcatOtraCat]);
} catch (\PDOException $e) {
    $rechazoSubcatCruzada = true;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
assertTest(
    'F2-DB-04',
    'FK compuesta fk_producto_subcategoria_categoria rechaza subcategoría de otra categoría',
    $rechazoSubcatCruzada,
    'Constraint FK compuesta activa'
);

// =========================================================================
// BLOQUE 2: Restricciones de cantidades, saldos y referencias en BD y Dominio
// =========================================================================
$cantidadesInvalidas = [0, -5, '2.5', 'abc', '', null];
$rechazosDominio = 0;
foreach ($cantidadesInvalidas as $val) {
    try {
        DefinicionMovimiento::validarCantidadEnteraPositiva($val, 'cantidad');
    } catch (InventarioException $e) {
        if ($e->getHttpCode() === 422 && $e->getErrorCode() === 'VALIDATION_ERROR') {
            $rechazosDominio++;
        }
    }
}
assertTest(
    'F2-QTY-01',
    'DefinicionMovimiento rechaza cantidades 0, negativas, decimales y no numéricas con 422 VALIDATION_ERROR',
    $rechazosDominio === count($cantidadesInvalidas),
    "rechazadas={$rechazosDominio}/" . count($cantidadesInvalidas)
);

// Check constraint en producto contra stock negativo
$rechazoStockNegativoSQL = false;
$db->beginTransaction();
try {
    $db->exec("UPDATE producto SET stock_actual = -1 WHERE id_producto = 1");
} catch (\PDOException $e) {
    $rechazoStockNegativoSQL = true;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
assertTest(
    'F2-QTY-02',
    'Restricción chk_producto_stock_entero_no_negativo impide stock_actual < 0 en tabla producto',
    $rechazoStockNegativoSQL
);

// Check constraint en detalle_compra contra inconsistencia en equivalencia
$rechazoDetalleCompraSQL = false;
$db->beginTransaction();
try {
    $db->exec("INSERT INTO compra (id_proveedor, id_usuario, id_tipo_comprobante, serie, numero, fecha_emision, fecha_recepcion, total_documento, estado)
               VALUES (1, 1, 1, 'TSTC', 'CHECK-TEST', CURDATE(), CURDATE(), 10.00, 'Confirmada')");
    $idCompraTemp = (int)$db->lastInsertId();
    // cantidad_presentaciones=2, equivalencia_aplicada=6, pero cantidad_base=10 (debería ser 12)
    $db->exec("INSERT INTO detalle_compra (id_compra, id_producto, id_presentacion, cantidad_presentaciones, equivalencia_aplicada, cantidad_base, importe_final_linea)
               VALUES ({$idCompraTemp}, 1, 1, 2, 6, 10, 10.00)");
} catch (\PDOException $e) {
    $rechazoDetalleCompraSQL = (int)($e->errorInfo[1] ?? 0) === 4025
        && str_contains($e->getMessage(), 'chk_detalle_compra_integridad');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
assertTest(
    'F2-QTY-03',
    'Restricción chk_detalle_compra_integridad impide cantidad_base <> cantidad_presentaciones * equivalencia_aplicada',
    $rechazoDetalleCompraSQL
);

// Trigger de integridad referencial según origen en kardex
$rechazoKardexSinRef = false;
$db->beginTransaction();
try {
    // Intenta insertar movimiento de COMPRA sin id_accion_compra ni id_detalle_compra
    $db->exec("INSERT INTO kardex (
        id_producto, tipo_movimiento, origen_operacion, sentido_movimiento,
        cantidad, stock_anterior, stock_resultante, id_usuario,
        producto_codigo_snapshot, producto_nombre_snapshot,
        id_categoria_snapshot, categoria_nombre_snapshot,
        id_subcategoria_snapshot, subcategoria_nombre_snapshot,
        id_unidad_snapshot, unidad_abreviatura_snapshot,
        responsable_nombre_snapshot, responsable_rol_snapshot
    ) VALUES (
        1, 'COMPRA', 'COMPRA', 'INCREMENTO',
        5, 0, 5, 1,
        'COD1', 'Prod 1', 1, 'Cat 1', 1, 'General', 1, 'UND', 'Admin', 'Administrador'
    )");
} catch (\PDOException $e) {
    $rechazoKardexSinRef = true;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
assertTest(
    'F2-REF-01',
    'Trigger trg_kardex_before_insert_integridad rechaza movimiento en kardex sin referencia de operación válida',
    $rechazoKardexSinRef
);

// =========================================================================
// BLOQUE 3: Infraestructura transaccional de movimientos, concurrencia y snapshots
// =========================================================================
$movService = new MovimientoInventarioService($db);

// 3.1 Rechazo de salida cuando stock es insuficiente (409 INSUFFICIENT_STOCK)
$rechazoStockInsuficiente = false;
try {
    $movService->ejecutarEnTransaccion(function (\PDO $tx) use ($movService) {
        $stmtOp = $tx->prepare("INSERT INTO operacion_inventario (
            tipo_operacion, origen_operacion, sentido_movimiento, id_producto,
            cantidad, saldo_anterior, saldo_resultante, justificacion, id_usuario, referencia_solicitud
        ) VALUES ('BAJA', 'BAJA_DANO', 'DISMINUCION', 1, 5, 0, 0, 'Envase roto en almacén', 1, 'test-ref-insuf-0001')");
        // Incluso antes de insertar en operacion_inventario, probamos aplicarMovimiento sobre producto 1 (stock 0)
        $movService->aplicarMovimiento([
            'id_producto' => 1,
            'origen_operacion' => DefinicionMovimiento::ORIGEN_BAJA_DANO,
            'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
            'cantidad' => 5,
            'id_usuario' => 1,
            'id_operacion_inventario' => 1,
            'justificacion' => 'Envase roto en almacén',
        ], $tx);
    });
} catch (InventarioException $e) {
    if ($e->getHttpCode() === 409 && $e->getErrorCode() === 'INSUFFICIENT_STOCK') {
        $rechazoStockInsuficiente = true;
    }
}
assertTest(
    'F2-MOV-01',
    'MovimientoInventarioService impide dejar stock en negativo y responde 409 INSUFFICIENT_STOCK',
    $rechazoStockInsuficiente
);

// 3.2 Rechazo por saldo_anterior_esperado obsoleto (409 STOCK_CHANGED_CONFLICT)
$rechazoSaldoObsoleto = false;
try {
    $movService->ejecutarEnTransaccion(function (\PDO $tx) use ($movService) {
        $movService->aplicarMovimiento([
            'id_producto' => 1,
            'origen_operacion' => DefinicionMovimiento::ORIGEN_AJUSTE_POSITIVO,
            'sentido_movimiento' => DefinicionMovimiento::SENTIDO_INCREMENTO,
            'cantidad' => 4,
            'saldo_anterior_esperado' => 99, // El stock real es 0
            'id_usuario' => 1,
            'id_operacion_inventario' => 1,
            'justificacion' => 'Conteo físico arrojó 103 unidades',
        ], $tx);
    });
} catch (InventarioException $e) {
    if ($e->getHttpCode() === 409 && $e->getErrorCode() === 'STOCK_CHANGED_CONFLICT') {
        $rechazoSaldoObsoleto = true;
    }
}
assertTest(
    'F2-MOV-02',
    'MovimientoInventarioService detecta cambio concurrente de saldo_anterior_esperado (409 STOCK_CHANGED_CONFLICT)',
    $rechazoSaldoObsoleto
);

// 3.3 Reversión completa (rollback) ante fallo intermedio en operación
$stockAntesRollback = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = 1")->fetchColumn();
$kardexAntesRollback = (int)$db->query("SELECT COUNT(*) FROM kardex WHERE id_producto = 1")->fetchColumn();
try {
    $movService->ejecutarEnTransaccion(function (\PDO $tx) use ($movService) {
        $tx->exec("INSERT INTO operacion_inventario (
            id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento,
            sentido_movimiento, cantidad, saldo_anterior, saldo_resultante, justificacion,
            condicion_lote, referencia_solicitud
        ) VALUES (
            1, 1, 'AJUSTE', 'AJUSTE_POSITIVO', 'AJUSTE_POSITIVO',
            'INCREMENTO', 20, 0, 20, 'Prueba rollback intermedio',
            'SIN_LOTE', 'ref-rollback-test-0001'
        )");
        $idOp = (int)$tx->lastInsertId();

        $movService->aplicarMovimiento([
            'id_producto' => 1,
            'origen_operacion' => DefinicionMovimiento::ORIGEN_AJUSTE_POSITIVO,
            'sentido_movimiento' => DefinicionMovimiento::SENTIDO_INCREMENTO,
            'cantidad' => 20,
            'id_usuario' => 1,
            'id_operacion_inventario' => $idOp,
            'justificacion' => 'Prueba rollback intermedio',
        ], $tx);

        // Simulamos un fallo en un segundo ítem del lote (p. ej. salida sin stock en producto 2)
        $movService->aplicarMovimiento([
            'id_producto' => 2,
            'origen_operacion' => DefinicionMovimiento::ORIGEN_BAJA_VENCIMIENTO,
            'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
            'cantidad' => 10,
            'id_usuario' => 1,
            'id_operacion_inventario' => $idOp,
            'justificacion' => 'Fallo forzado para probar atomicidad',
        ], $tx);
    });
} catch (InventarioException $e) {
    // Esperado
}
$stockDespuesRollback = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = 1")->fetchColumn();
$kardexDespuesRollback = (int)$db->query("SELECT COUNT(*) FROM kardex WHERE id_producto = 1")->fetchColumn();
$opDespuesRollback = (int)$db->query("SELECT COUNT(*) FROM operacion_inventario")->fetchColumn();
assertTest(
    'F2-MOV-03',
    'Reversión atómica completa cuando falla un paso intermedio (stock, operación y kardex vuelven al estado inicial)',
    $stockAntesRollback === 0 && $stockDespuesRollback === 0 && $kardexDespuesRollback === 0 && $opDespuesRollback === 0,
    "stock={$stockDespuesRollback}, kardex={$kardexDespuesRollback}, op={$opDespuesRollback}"
);

// =========================================================================
// BLOQUE 4: Control de solicitudes repetidas y simultáneas + inmutabilidad histórica
// =========================================================================
$ctrlSolicitud = new ControlSolicitudService($db);
$refIdem = 'eb9d18a4-6f42-4d31-9b1c-000000000201';
$payloadOriginal = [
    'id_producto' => 1,
    'tipo' => 'INCREMENTO',
    'cantidad' => 15,
    'justificacion' => 'Diferencia positiva hallada en arqueo de anaquel A1',
    'numero_lote' => 'LOTE-2026-A1',
    'condicion_lote' => DefinicionMovimiento::LOTE_CON_REFERENCIA,
];

$ejecutarAjustePrueba = function (array $payload, string $refActual) use ($movService) {
    return $movService->ejecutarEnTransaccion(function (\PDO $tx) use ($movService, $payload, $refActual) {
        $prodLocked = $movService->bloquearProductosOrdenados([$payload['id_producto']], $tx)[$payload['id_producto']];
        $saldoAnt = (int)$prodLocked['stock_actual'];
        $saldoRes = $saldoAnt + (int)$payload['cantidad'];

        $stmtOp = $tx->prepare("INSERT INTO operacion_inventario (
            id_producto, id_usuario, categoria_operacion, origen_operacion, tipo_movimiento,
            sentido_movimiento, cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante,
            justificacion, numero_lote, condicion_lote, referencia_solicitud
        ) VALUES (
            :id_producto, 1, 'AJUSTE', 'AJUSTE_POSITIVO', 'AJUSTE_POSITIVO',
            'INCREMENTO', :cantidad, :saldo_ant, :saldo_ant_esp, :saldo_res,
            :justificacion, :lote, :condicion, :ref
        )");
        $stmtOp->execute([
            ':id_producto' => $payload['id_producto'],
            ':cantidad' => $payload['cantidad'],
            ':saldo_ant' => $saldoAnt,
            ':saldo_ant_esp' => $saldoAnt,
            ':saldo_res' => $saldoRes,
            ':justificacion' => $payload['justificacion'],
            ':lote' => $payload['numero_lote'],
            ':condicion' => $payload['condicion_lote'],
            ':ref' => $refActual,
        ]);
        $idOp = (int)$tx->lastInsertId();

        $mov = $movService->aplicarMovimiento([
            'id_producto' => $payload['id_producto'],
            'origen_operacion' => DefinicionMovimiento::ORIGEN_AJUSTE_POSITIVO,
            'sentido_movimiento' => DefinicionMovimiento::SENTIDO_INCREMENTO,
            'cantidad' => $payload['cantidad'],
            'saldo_anterior_esperado' => $saldoAnt,
            'id_usuario' => 1,
            'id_operacion_inventario' => $idOp,
            'referencia_operacion' => 'AJU-' . str_pad((string)$idOp, 6, '0', STR_PAD_LEFT),
            'justificacion' => $payload['justificacion'],
            'numero_lote' => $payload['numero_lote'],
            'condicion_lote' => $payload['condicion_lote'],
        ], $tx);

        return [
            'id_registro' => $idOp,
            'http_code' => 201,
            'response' => [
                'status' => true,
                'data' => [
                    'id_operacion' => $idOp,
                    'id_kardex' => $mov['id_kardex'],
                    'saldo_anterior' => $mov['saldo_anterior'],
                    'saldo_resultante' => $mov['saldo_resultante'],
                ],
            ],
        ];
    });
};

// 4.1 Primera ejecución (201 Created)
$resp1 = $ctrlSolicitud->ejecutarConIdempotencia(
    1,
    DefinicionMovimiento::SOLICITUD_AJUSTE,
    $refIdem,
    $payloadOriginal,
    fn() => $ejecutarAjustePrueba($payloadOriginal, $refIdem)
);
$stockTras1 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = 1")->fetchColumn();
assertTest(
    'F2-IDEM-01',
    'Primera solicitud válida ejecuta operación, actualiza stock a 15 y devuelve 201 Created',
    $resp1['http_code'] === 201
        && $resp1['idempotent_replay'] === false
        && $stockTras1 === 15
        && $resp1['response']['data']['saldo_resultante'] === 15,
    "http={$resp1['http_code']}, stock={$stockTras1}"
);

// 4.2 Segunda ejecución con misma referencia y mismo payload (200 OK OPERATION_ALREADY_CONFIRMED sin duplicar)
$resp2 = $ctrlSolicitud->ejecutarConIdempotencia(
    1,
    DefinicionMovimiento::SOLICITUD_AJUSTE,
    $refIdem,
    $payloadOriginal,
    fn() => $ejecutarAjustePrueba($payloadOriginal, $refIdem)
);
$stockTras2 = (int)$db->query("SELECT stock_actual FROM producto WHERE id_producto = 1")->fetchColumn();
$kardexCountTras2 = (int)$db->query("SELECT COUNT(*) FROM kardex WHERE id_producto = 1")->fetchColumn();
assertTest(
    'F2-IDEM-02',
    'Solicitud repetida con misma referencia y payload devuelve 200 OK OPERATION_ALREADY_CONFIRMED sin volver a sumar stock',
    $resp2['http_code'] === 200
        && $resp2['idempotent_replay'] === true
        && ($resp2['response']['code'] ?? '') === 'OPERATION_ALREADY_CONFIRMED'
        && $stockTras2 === 15
        && $kardexCountTras2 === 1
        && $resp2['response']['data']['id_operacion'] === $resp1['response']['data']['id_operacion'],
    "http={$resp2['http_code']}, code={$resp2['response']['code']}, stock={$stockTras2}, kardex={$kardexCountTras2}"
);

// 4.3 Misma referencia con datos distintos (409 IDEMPOTENCY_PAYLOAD_MISMATCH)
$rechazoPayloadDistinto = false;
$payloadAlterado = $payloadOriginal;
$payloadAlterado['cantidad'] = 99;
try {
    $ctrlSolicitud->ejecutarConIdempotencia(
        1,
        DefinicionMovimiento::SOLICITUD_AJUSTE,
        $refIdem,
        $payloadAlterado,
        fn() => $ejecutarAjustePrueba($payloadAlterado, $refIdem)
    );
} catch (InventarioException $e) {
    if ($e->getHttpCode() === 409 && $e->getErrorCode() === 'IDEMPOTENCY_PAYLOAD_MISMATCH') {
        $rechazoPayloadDistinto = true;
    }
}
assertTest(
    'F2-IDEM-03',
    'Misma referencia_solicitud con payload distinto es rechazada con 409 IDEMPOTENCY_PAYLOAD_MISMATCH',
    $rechazoPayloadDistinto
);

// 4.4 Solicitud simultánea en procesamiento desde otra conexión (409 REQUEST_IN_PROGRESS)
$refSimultanea = 'eb9d18a4-6f42-4d31-9b1c-000000000202';
$lockKey = ControlSolicitudService::construirNombreLock(1, DefinicionMovimiento::SOLICITUD_AJUSTE, $refSimultanea);
$dsn2 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, defined('DB_PORT') ? DB_PORT : 3306, DB_NAME);
$pdo2 = new \PDO($dsn2, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$stmtLock = $pdo2->prepare("SELECT GET_LOCK(:k, 0)");
$stmtLock->execute([':k' => $lockKey]);

$rechazoSimultanea = false;
try {
    $ctrlSolicitud->ejecutarConIdempotencia(
        1,
        DefinicionMovimiento::SOLICITUD_AJUSTE,
        $refSimultanea,
        $payloadOriginal,
        fn() => $ejecutarAjustePrueba($payloadOriginal, $refSimultanea)
    );
} catch (InventarioException $e) {
    if ($e->getHttpCode() === 409 && $e->getErrorCode() === 'REQUEST_IN_PROGRESS') {
        $rechazoSimultanea = true;
    }
} finally {
    $stmtRel = $pdo2->prepare("SELECT RELEASE_LOCK(:k)");
    $stmtRel->execute([':k' => $lockKey]);
    $pdo2 = null;
}
assertTest(
    'F2-IDEM-04',
    'Solicitud simultánea en curso es detectada mediante bloqueo y rechazada con 409 REQUEST_IN_PROGRESS',
    $rechazoSimultanea
);

// 4.5 Verificar preservación histórica (snapshots en kardex e inmutabilidad)
$idKardexCreado = (int)$resp1['response']['data']['id_kardex'];
$kardexRow = $db->query("SELECT * FROM kardex WHERE id_kardex = {$idKardexCreado}")->fetch(\PDO::FETCH_ASSOC);
$nombreProdOriginal = $db->query("SELECT nombre FROM producto WHERE id_producto = 1")->fetchColumn();

// Cambiar temporalmente el nombre del producto en catálogo y verificar que el snapshot en kardex conserva el nombre histórico
$db->exec("UPDATE producto SET nombre = 'Nombre Cambiado Posteriormente' WHERE id_producto = 1");
$kardexRowDespuesCambio = $db->query("SELECT * FROM kardex WHERE id_kardex = {$idKardexCreado}")->fetch(\PDO::FETCH_ASSOC);
$db->prepare("UPDATE producto SET nombre = ? WHERE id_producto = 1")->execute([$nombreProdOriginal]);

assertTest(
    'F2-HIST-01',
    'El movimiento en kardex conserva snapshots históricos inmutables aunque cambie el catálogo después',
    $kardexRow['producto_nombre_snapshot'] === $nombreProdOriginal
        && $kardexRowDespuesCambio['producto_nombre_snapshot'] === $nombreProdOriginal
        && !empty($kardexRow['categoria_nombre_snapshot'])
        && !empty($kardexRow['subcategoria_nombre_snapshot'])
        && !empty($kardexRow['responsable_nombre_snapshot']),
    "snapshot_prod='{$kardexRowDespuesCambio['producto_nombre_snapshot']}', subcat='{$kardexRow['subcategoria_nombre_snapshot']}', resp='{$kardexRow['responsable_nombre_snapshot']}'"
);

$rechazoUpdateKardex = false;
try {
    $db->exec("UPDATE kardex SET cantidad = 99 WHERE id_kardex = {$idKardexCreado}");
} catch (\PDOException $e) {
    $rechazoUpdateKardex = true;
}
assertTest(
    'F2-HIST-02',
    'Trigger trg_kardex_before_update_inmutable impide alterar registros históricos en kardex',
    $rechazoUpdateKardex
);

// =========================================================================
// BLOQUE 5: Seguridad, Autorización, Usuario Activo en BD, CSRF y Compatibilidad POS
// =========================================================================
// 5.1 Usuario no autenticado
$_SESSION = [];
$userNoAuth = AuthMiddleware::verificarUsuarioActivoEnSesion();
assertTest(
    'F2-SEC-01',
    'Sesión sin autenticar es rechazada por AuthMiddleware::verificarUsuarioActivoEnSesion()',
    $userNoAuth === null
);

// 5.2 Administrador activo (id_usuario = 1, id_rol = 1)
$_SESSION = ['login' => true, 'idUser' => 1, 'rol' => 1, 'nombre' => 'NombreEnSesionObsoleto'];
$userAdmin = AuthMiddleware::verificarUsuarioActivoEnSesion();
$adminTieneAjustes = RBACMiddleware::tienePermiso(1, 'inventario.ajustes');
$adminTieneKardex = RBACMiddleware::tienePermiso(1, 'reportes.kardex');
$adminTieneVariaciones = RBACMiddleware::tienePermiso(1, 'reportes.variaciones');
$adminTieneExportar = RBACMiddleware::tienePermiso(1, 'reportes.exportar');
assertTest(
    'F2-SEC-02',
    'Administrador activo es validado contra BD (sincronizando nombre real) y posee permisos de Sprint 2',
    $userAdmin !== null
        && (int)$userAdmin['id_rol'] === 1
        && $_SESSION['nombre'] === $userAdmin['nombre']
        && $adminTieneAjustes && $adminTieneKardex && $adminTieneVariaciones && $adminTieneExportar,
    "admin_nombre='{$userAdmin['nombre']}'"
);

// 5.3 Administrador desactivado en BD (estado = 0) con sesión abierta
$db->exec("UPDATE usuario SET estado = 0 WHERE id_usuario = 1");
try {
    $_SESSION = ['login' => true, 'idUser' => 1, 'rol' => 1, 'nombre' => 'Admin'];
    $userAdminInactivo = AuthMiddleware::verificarUsuarioActivoEnSesion();
    $rechazoServicioInactivo = false;
    try {
        $movService->verificarResponsableActivo(1, true);
    } catch (InventarioException $e) {
        if ($e->getHttpCode() === 401 && $e->getErrorCode() === 'AUTH_REQUIRED') {
            $rechazoServicioInactivo = true;
        }
    }
    assertTest(
        'F2-SEC-03',
        'Usuario desactivado en BD (estado=0) es rechazado inmediatamente aunque conserve sesión activa',
        $userAdminInactivo === null && $rechazoServicioInactivo
    );
} finally {
    $db->exec("UPDATE usuario SET estado = 1 WHERE id_usuario = 1");
}

// 5.4 Usuario Vendedor activo (id_usuario = 4, id_rol = 2): denegado en Sprint 2 pero permitido en POS
$vendedorTieneAjustes = RBACMiddleware::tienePermiso(2, 'inventario.ajustes');
$vendedorTieneKardex = RBACMiddleware::tienePermiso(2, 'reportes.kardex');
$vendedorTieneVariaciones = RBACMiddleware::tienePermiso(2, 'reportes.variaciones');
$vendedorTieneExportar = RBACMiddleware::tienePermiso(2, 'reportes.exportar');
$vendedorTienePOS = RBACMiddleware::tienePermiso(2, 'ventas.acceder') && RBACMiddleware::tienePermiso(2, 'productos.listar');

$rechazoVendedorAdminOnly = false;
try {
    $movService->verificarResponsableActivo(4, true);
} catch (InventarioException $e) {
    if ($e->getHttpCode() === 403 && $e->getErrorCode() === 'FORBIDDEN_ADMIN_ONLY') {
        $rechazoVendedorAdminOnly = true;
    }
}
assertTest(
    'F2-SEC-04',
    'Rol Vendedor (id_rol=2) tiene denegadas las funciones del Sprint 2 (403 FORBIDDEN_ADMIN_ONLY) pero conserva acceso a POS y catálogo',
    !$vendedorTieneAjustes
        && !$vendedorTieneKardex
        && !$vendedorTieneVariaciones
        && !$vendedorTieneExportar
        && $rechazoVendedorAdminOnly
        && $vendedorTienePOS,
    "pos_compatible=" . ($vendedorTienePOS ? 'SI' : 'NO')
);

// 5.5 Verificación CSRF en operaciones que modifican estado
$_SESSION = [];
$tokenCsrf = CSRFMiddleware::generateToken();
$_SERVER['REQUEST_METHOD'] = 'POST';
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$_POST = [];
$csrfSinToken = CSRFMiddleware::validate();

$_SERVER['HTTP_X_CSRF_TOKEN'] = 'token-invalido-123';
$csrfTokenInvalido = CSRFMiddleware::validate();

$_SERVER['HTTP_X_CSRF_TOKEN'] = $tokenCsrf;
$csrfTokenValido = CSRFMiddleware::validate();

assertTest(
    'F2-SEC-05',
    'CSRFMiddleware rechaza POST sin token o con token inválido y acepta token válido',
    $csrfSinToken === false && $csrfTokenInvalido === false && $csrfTokenValido === true
);

// =========================================================================
// BLOQUE 6: Limpieza de registros de prueba y verificación de integridad de ambas bases
// =========================================================================
// Para limpiar el movimiento de prueba en kardex (protegido contra DELETE por FK restrict y contra UPDATE por trigger),
// eliminamos primero kardex, luego operacion_inventario, luego control_solicitud_idempotencia y restauramos stock_actual = 0.
$db->exec("DELETE FROM kardex");
$db->exec("DELETE FROM operacion_inventario");
$db->exec("DELETE FROM control_solicitud_idempotencia");
$db->exec("ALTER TABLE kardex AUTO_INCREMENT = 1");
$db->exec("ALTER TABLE operacion_inventario AUTO_INCREMENT = 1");
$db->exec("ALTER TABLE control_solicitud_idempotencia AUTO_INCREMENT = 1");
$db->exec("UPDATE producto SET stock_actual = 0");

$testStockPositivo = (int)$db->query("SELECT COUNT(*) FROM producto WHERE stock_actual <> 0")->fetchColumn();
$testKardexCount = (int)$db->query("SELECT COUNT(*) FROM kardex")->fetchColumn();
$testOpCount = (int)$db->query("SELECT COUNT(*) FROM operacion_inventario")->fetchColumn();

assertTest(
    'F2-CLEAN-01',
    'Base de pruebas amazon_market_sprint2_test restaurada a escenario limpio (107 productos con stock 0 y 0 movimientos)',
    $testStockPositivo === 0 && $testKardexCount === 0 && $testOpCount === 0
);

// Verificar que la base principal amazon_market NO fue modificada
$dsnLive = sprintf('mysql:host=%s;port=%s;dbname=amazon_market;charset=utf8mb4', DB_HOST, defined('DB_PORT') ? DB_PORT : 3306);
$pdoLive = new \PDO($dsnLive, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$liveTables = (int)$pdoLive->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'amazon_market' AND table_type = 'BASE TABLE'")->fetchColumn();
$liveStockPos = (int)$pdoLive->query("SELECT COUNT(*) FROM producto WHERE stock_actual > 0")->fetchColumn();
$liveVentas = (int)$pdoLive->query("SELECT COUNT(*) FROM venta")->fetchColumn();
$liveCompras = (int)$pdoLive->query("SELECT COUNT(*) FROM compra")->fetchColumn();
$liveKardex = (int)$pdoLive->query("SELECT COUNT(*) FROM kardex")->fetchColumn();

assertTest(
    'F2-ISO-01',
    'Base de datos principal amazon_market permanece intacta (20 tablas, 106 productos con stock>0, 98 ventas, 4 compras, 26 kardex)',
    $liveTables === 20 && $liveStockPos === 106 && $liveVentas === 98 && $liveCompras === 4 && $liveKardex === 26,
    "tablas={$liveTables}, stock_pos={$liveStockPos}, ventas={$liveVentas}, compras={$liveCompras}, kardex={$liveKardex}"
);

echo PHP_EOL . "=== RESUMEN FASE 2: {$passed} PASS / {$failed} FAIL ===" . PHP_EOL;
if ($failed > 0) {
    exit(1);
}
exit(0);
