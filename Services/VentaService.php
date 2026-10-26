<?php

namespace Services;

use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Models\ClienteModel;
use Models\DetalleVentaModel;
use Models\MetodoPagoModel;
use Models\ProductoModel;
use Models\TipoComprobanteModel;
use Models\UsuarioModel;
use Models\VentaModel;
use PDO;
use Exception;
use Throwable;

class VentaService {
    private VentaModel $ventaModel;
    private DetalleVentaModel $detalleModel;
    private ProductoModel $productoModel;
    private ClienteModel $clienteModel;
    private MovimientoInventarioService $movimientoService;
    private ControlSolicitudService $controlSolicitudService;
    private PDO $pdo;

    public function __construct(
        ?VentaModel $ventaModel = null,
        ?DetalleVentaModel $detalleModel = null,
        ?ProductoModel $productoModel = null,
        ?ClienteModel $clienteModel = null,
        ?MovimientoInventarioService $movimientoService = null,
        ?ControlSolicitudService $controlSolicitudService = null
    ) {
        $this->ventaModel = $ventaModel ?? new VentaModel();
        $this->detalleModel = $detalleModel ?? new DetalleVentaModel();
        $this->productoModel = $productoModel ?? new ProductoModel();
        $this->clienteModel = $clienteModel ?? new ClienteModel();
        $this->movimientoService = $movimientoService ?? new MovimientoInventarioService();
        $this->pdo = $this->movimientoService->getPdo();
        $this->controlSolicitudService = $controlSolicitudService ?? new ControlSolicitudService($this->pdo);
    }

    public function getMovimientoService(): MovimientoInventarioService {
        return $this->movimientoService;
    }

    /**
     * Procesa y confirma una venta de forma atómica:
     * valida cliente, comprobante, responsable y catálogo,
     * descuenta existencias mediante bloqueo pesimista ordenado,
     * registra cada salida en Kardex con FKs y snapshots históricos,
     * e implementa idempotencia ante reenvíos.
     */
    public function procesarVenta(array $input, ?string $referencia_solicitud = null): array {
        // Extraer referencia de solicitud si vino en el payload
        if ($referencia_solicitud === null && !empty($input['referencia_solicitud']) && is_string($input['referencia_solicitud'])) {
            $referencia_solicitud = trim($input['referencia_solicitud']);
        }

        // 1. Validar responsable autenticado en BD (defensa BOLA/IDOR)
        $idUsuario = filter_var($input['id_usuario'] ?? null, FILTER_VALIDATE_INT);
        if ($idUsuario === false || $idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }
        $responsable = $this->movimientoService->verificarResponsableActivo((int)$idUsuario, false);

        // 2. Validar cliente
        $idCliente = filter_var($input['id_cliente'] ?? null, FILTER_VALIDATE_INT);
        if ($idCliente === false || $idCliente <= 0) {
            throw InventarioException::validacion(['id_cliente' => 'Debe seleccionar un cliente válido.']);
        }
        $cliente = $this->clienteModel->find((int)$idCliente);
        if (!$cliente || (int)($cliente['estado'] ?? 0) !== 1) {
            throw InventarioException::validacion(['id_cliente' => 'El cliente seleccionado no existe o está inactivo.']);
        }

        // 3. Validar tipo de comprobante
        $idTipoComp = filter_var($input['id_tipo_comprobante'] ?? null, FILTER_VALIDATE_INT);
        if ($idTipoComp === false || $idTipoComp <= 0) {
            throw InventarioException::validacion(['id_tipo_comprobante' => 'Debe seleccionar un tipo de comprobante válido.']);
        }

        $stmtComp = $this->pdo->prepare('SELECT id_tipo_comprobante, nombre FROM tipo_comprobante WHERE id_tipo_comprobante = :id AND estado = 1');
        $stmtComp->execute([':id' => $idTipoComp]);
        $tipoComp = $stmtComp->fetch(PDO::FETCH_ASSOC);
        if (!$tipoComp) {
            throw InventarioException::validacion(['id_tipo_comprobante' => 'El tipo de comprobante seleccionado no es válido o está inactivo.']);
        }

        // Si es Factura, validar que el cliente posea RUC (id_tipo_documento = 2)
        if (stripos($tipoComp['nombre'], 'factura') !== false && (int)($cliente['id_tipo_documento'] ?? 0) !== 2) {
            throw InventarioException::validacion([
                'id_tipo_comprobante' => 'Para emitir Factura, el cliente debe tener RUC. Seleccione un cliente con RUC.'
            ]);
        }

        // 4. Validar método de pago
        $idMetodoPago = filter_var($input['id_metodo_pago'] ?? 1, FILTER_VALIDATE_INT);
        if ($idMetodoPago === false || $idMetodoPago <= 0) {
            throw InventarioException::validacion(['id_metodo_pago' => 'Debe seleccionar un método de pago válido.']);
        }

        // 5. Validar productos y cantidades enteras
        $productos = $input['productos'] ?? null;
        if (!is_array($productos) || empty($productos)) {
            throw InventarioException::validacion(['productos' => 'Debe agregar al menos un producto a la venta.']);
        }

        $itemsNormalizados = [];
        $demandaPorProducto = [];

        foreach ($productos as $index => $item) {
            $numLinea = $index + 1;
            $idProd = filter_var($item['id_producto'] ?? null, FILTER_VALIDATE_INT);
            if ($idProd === false || $idProd <= 0) {
                throw InventarioException::validacion(["producto_{$numLinea}" => "El producto en la línea {$numLinea} no tiene un identificador válido."]);
            }

            // Exigir cantidad entera positiva (Sprint 2: envases individuales enteros)
            $cantEntera = DefinicionMovimiento::validarCantidadEnteraPositiva(
                $item['cantidad'] ?? null,
                "cantidad_linea_{$numLinea}"
            );

            $idProdEntero = (int)$idProd;
            $itemsNormalizados[] = [
                'id_producto' => $idProdEntero,
                'cantidad' => $cantEntera,
                'numero_linea' => $numLinea
            ];

            // Acumular demanda para manejar múltiples líneas del mismo producto
            $demandaPorProducto[$idProdEntero] = ($demandaPorProducto[$idProdEntero] ?? 0) + $cantEntera;
        }

        // Operación atómica de negocio
        $operacion = function () use (
            $idCliente, $idTipoComp, $idMetodoPago, $idUsuario, $responsable,
            $itemsNormalizados, $demandaPorProducto
        ) {
            return $this->movimientoService->ejecutarEnTransaccion(function (PDO $pdo) use (
                $idCliente, $idTipoComp, $idMetodoPago, $idUsuario, $responsable,
                $itemsNormalizados, $demandaPorProducto
            ) {
                // 1. Bloquear productos involucrados en orden ascendente
                $idsProductos = array_keys($demandaPorProducto);
                $productosBloqueados = $this->movimientoService->bloquearProductosOrdenados($idsProductos);

                // 2. Validar que todos los productos estén activos
                foreach ($idsProductos as $idProd) {
                    $prod = $productosBloqueados[$idProd];
                    if ((int)$prod['estado'] !== 1) {
                        throw InventarioException::validacion([
                            'id_producto' => "El producto \"{$prod['nombre']}\" se encuentra inactivo y no admite ventas."
                        ]);
                    }
                }

                // 3. Validar disponibilidad de stock considerando la demanda acumulada
                foreach ($demandaPorProducto as $idProd => $demandaTotal) {
                    $stockActual = (int)$productosBloqueados[$idProd]['stock_actual'];
                    if ($stockActual < $demandaTotal) {
                        throw InventarioException::stockInsuficiente(
                            $idProd,
                            $stockActual,
                            $demandaTotal,
                            $productosBloqueados[$idProd]['nombre']
                        );
                    }
                }

                // 4. Bloquear y avanzar serie y correlativo de comprobante
                $stmtSerie = $pdo->prepare(
                    'SELECT id_serie, serie, correlativo_actual
                       FROM serie_comprobante
                      WHERE id_tipo_comprobante = :id AND estado = 1
                      LIMIT 1 FOR UPDATE'
                );
                $stmtSerie->execute([':id' => $idTipoComp]);
                $serieRow = $stmtSerie->fetch(PDO::FETCH_ASSOC);

                if (!$serieRow) {
                    throw new Exception('No hay una serie configurada para este tipo de comprobante.');
                }

                $serie = (string)$serieRow['serie'];
                $siguienteCorrelativo = (int)$serieRow['correlativo_actual'] + 1;
                $numero = str_pad((string)$siguienteCorrelativo, 8, '0', STR_PAD_LEFT);

                $stmtUpdateSerie = $pdo->prepare(
                    'UPDATE serie_comprobante SET correlativo_actual = :nuevo WHERE id_serie = :id_serie'
                );
                $stmtUpdateSerie->execute([
                    ':nuevo' => $siguienteCorrelativo,
                    ':id_serie' => (int)$serieRow['id_serie']
                ]);

                // 5. Calcular subtotales y total general usando precio oficial vigente del catálogo
                $detallesProcesados = [];
                $totalCentimos = 0;

                foreach ($itemsNormalizados as $item) {
                    $idProd = $item['id_producto'];
                    $cant = $item['cantidad'];
                    $precioUnitario = (float)$productosBloqueados[$idProd]['precio_venta'];
                    $subtotalLinea = round($precioUnitario * $cant, 2);
                    $totalCentimos += (int)round($subtotalLinea * 100);

                    $detallesProcesados[] = [
                        'id_producto' => $idProd,
                        'cantidad' => $cant,
                        'precio_unitario' => $precioUnitario,
                        'subtotal' => $subtotalLinea
                    ];
                }

                $totalGeneral = $totalCentimos / 100;

                // 6. Insertar cabecera de la venta
                $stmtVenta = $pdo->prepare(
                    'INSERT INTO venta (id_cliente, id_usuario, id_tipo_comprobante, serie, numero, fecha_venta, total, id_metodo_pago, estado)
                     VALUES (:id_cliente, :id_usuario, :id_tipo_comprobante, :serie, :numero, NOW(), :total, :id_metodo_pago, 1)'
                );
                $stmtVenta->execute([
                    ':id_cliente' => $idCliente,
                    ':id_usuario' => $idUsuario,
                    ':id_tipo_comprobante' => $idTipoComp,
                    ':serie' => $serie,
                    ':numero' => $numero,
                    ':total' => $totalGeneral,
                    ':id_metodo_pago' => $idMetodoPago
                ]);
                $idVenta = (int)$pdo->lastInsertId();

                // 7. Insertar detalles y aplicar movimientos en Kardex
                $stmtDetalle = $pdo->prepare(
                    'INSERT INTO detalle_venta (id_venta, id_producto, cantidad, precio_unitario, descuento, subtotal)
                     VALUES (:id_venta, :id_producto, :cantidad, :precio_unitario, 0.00, :subtotal)'
                );

                foreach ($detallesProcesados as $det) {
                    $stmtDetalle->execute([
                        ':id_venta' => $idVenta,
                        ':id_producto' => $det['id_producto'],
                        ':cantidad' => $det['cantidad'],
                        ':precio_unitario' => $det['precio_unitario'],
                        ':subtotal' => $det['subtotal']
                    ]);
                    $idDetalleVenta = (int)$pdo->lastInsertId();

                    // Aplicar movimiento canónico VENTA (DISMINUCION)
                    $this->movimientoService->aplicarMovimiento([
                        'id_producto' => $det['id_producto'],
                        'id_usuario' => $idUsuario,
                        'origen_operacion' => DefinicionMovimiento::ORIGEN_VENTA,
                        'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                        'cantidad' => $det['cantidad'],
                        'id_accion_venta' => $idVenta,
                        'id_detalle_venta' => $idDetalleVenta,
                        'referencia_operacion' => "Venta #{$idVenta} - {$serie}-{$numero}",
                        'exigir_producto_activo' => true
                    ]);
                }

                return [
                    'status' => true,
                    'message' => 'Venta registrada con éxito.',
                    'data' => [
                        'id_venta' => $idVenta,
                        'serie' => $serie,
                        'numero' => $numero,
                        'total' => $totalGeneral
                    ]
                ];
            });
        };

        // Soporte de idempotencia
        if ($referencia_solicitud !== null && trim($referencia_solicitud) !== '') {
            $payloadFuncional = [
                'id_cliente' => $idCliente,
                'id_tipo_comprobante' => $idTipoComp,
                'id_metodo_pago' => $idMetodoPago,
                'productos' => $itemsNormalizados
            ];

            $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
                $idUsuario,
                DefinicionMovimiento::SOLICITUD_VENTA,
                $referencia_solicitud,
                $payloadFuncional,
                function (PDO $pdo) use ($operacion) {
                    $resultado = $operacion();
                    return [
                        'id_registro' => (int)($resultado['data']['id_venta'] ?? 0),
                        'response' => $resultado
                    ];
                }
            );

            $respuestaFinal = $resIdemp['response'];
            // Si fue repetición idempotente, asegurar que la estructura data esté disponible
            if (!empty($resIdemp['idempotent_replay']) && isset($respuestaFinal['data']['id_venta'])) {
                $respuestaFinal['status'] = true;
            }
            return $respuestaFinal;
        }

        return $operacion();
    }

    /**
     * Anula una venta registrada, restituyendo las unidades a inventario
     * mediante ANULACION_VENTA (INCREMENTO).
     */
    public function anularVenta(int $id_venta, int $id_usuario, string $motivo = ''): array {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw InventarioException::validacion(['motivo' => 'El motivo de anulación es obligatorio.']);
        }

        $responsable = $this->movimientoService->verificarResponsableActivo($id_usuario, false);

        return $this->movimientoService->ejecutarEnTransaccion(function (PDO $pdo) use ($id_venta, $id_usuario, $motivo) {
            $stmt = $pdo->prepare('SELECT * FROM venta WHERE id_venta = ? FOR UPDATE');
            $stmt->execute([$id_venta]);
            $venta = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$venta) {
                throw new Exception("Venta no encontrada ID: {$id_venta}.");
            }
            if ((int)$venta['estado'] === 0) {
                throw new Exception("La venta ya se encuentra anulada.");
            }

            $stmtDet = $pdo->prepare('SELECT * FROM detalle_venta WHERE id_venta = ?');
            $stmtDet->execute([$id_venta]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            // El historial anterior puede contener ventas fraccionarias. No
            // truncar esas cantidades al restituir stock en el Sprint 2.
            foreach ($detalles as $det) {
                $cantidadHistorica = (float)$det['cantidad'];
                if (!is_finite($cantidadHistorica) || $cantidadHistorica < 1
                    || $cantidadHistorica > 2147483647
                    || floor($cantidadHistorica) !== $cantidadHistorica) {
                    throw new InventarioException(
                        'Esta venta contiene cantidades históricas incompatibles con unidades enteras. Se conserva sin cambios; su anulación requiere revisar el historial sin redondear cantidades.',
                        'LEGACY_FRACTIONAL_QUANTITY',
                        409,
                        [],
                        ['id_venta' => $id_venta, 'id_detalle_venta' => (int)$det['id_detalle_venta']]
                    );
                }
            }

            $idsProductos = array_values(array_unique(array_column($detalles, 'id_producto')));
            $this->movimientoService->bloquearProductosOrdenados($idsProductos);

            foreach ($detalles as $det) {
                $this->movimientoService->aplicarMovimiento([
                    'id_producto' => (int)$det['id_producto'],
                    'id_usuario' => $id_usuario,
                    'origen_operacion' => DefinicionMovimiento::ORIGEN_ANULACION_VENTA,
                    'sentido_movimiento' => DefinicionMovimiento::SENTIDO_INCREMENTO,
                    'cantidad' => (int)$det['cantidad'],
                    'id_accion_venta' => $id_venta,
                    'id_detalle_venta' => (int)$det['id_detalle_venta'],
                    'referencia_operacion' => "Anulación Venta #{$id_venta}",
                    'justificacion' => $motivo,
                    'exigir_producto_activo' => false
                ]);
            }

            $stmtUpd = $pdo->prepare('UPDATE venta SET estado = 0 WHERE id_venta = ?');
            $stmtUpd->execute([$id_venta]);

            return [
                'status' => true,
                'message' => 'Venta anulada correctamente.'
            ];
        });
    }
}
