<?php

namespace Services;

use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Models\CajaModel;
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
    private CajaModel $cajaModel;
    private PDO $pdo;

    public function __construct(
        ?VentaModel $ventaModel = null,
        ?DetalleVentaModel $detalleModel = null,
        ?ProductoModel $productoModel = null,
        ?ClienteModel $clienteModel = null,
        ?MovimientoInventarioService $movimientoService = null,
        ?ControlSolicitudService $controlSolicitudService = null,
        ?CajaModel $cajaModel = null
    ) {
        $this->ventaModel = $ventaModel ?? new VentaModel();
        $this->detalleModel = $detalleModel ?? new DetalleVentaModel();
        $this->productoModel = $productoModel ?? new ProductoModel();
        $this->clienteModel = $clienteModel ?? new ClienteModel();
        $this->movimientoService = $movimientoService ?? new MovimientoInventarioService();
        $this->pdo = $this->movimientoService->getPdo();
        $this->controlSolicitudService = $controlSolicitudService ?? new ControlSolicitudService($this->pdo);
        $this->cajaModel = $cajaModel ?? new CajaModel($this->pdo);
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
        $referenciaValida = null;
        if ($referencia_solicitud !== null) {
            $referenciaValida = ControlSolicitudService::validarReferenciaSolicitud($referencia_solicitud);
        } elseif (array_key_exists('referencia_solicitud', $input) || array_key_exists('request_id', $input)) {
            $referenciaValida = ControlSolicitudService::resolverReferenciaCuerpoOCabecera($input);
        }

        // 1. Validar responsable autenticado en BD y rol Vendedor (id_rol = 2)
        $rawUsuario = $input['id_usuario'] ?? null;
        $idUsuario = is_bool($rawUsuario) ? false : filter_var($rawUsuario, FILTER_VALIDATE_INT);
        if ($idUsuario === false || $idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }
        $responsable = $this->movimientoService->verificarResponsableActivo((int)$idUsuario, false);
        if ((int)($responsable['id_rol'] ?? 0) !== 2) {
            throw new InventarioException(
                'Solo un usuario con rol Vendedor puede registrar ventas en POS.',
                'FORBIDDEN',
                403
            );
        }

        // 2. Validación sintáctica previa a idempotencia (no consultar estado mutable aún)
        $rawCliente = $input['id_cliente'] ?? null;
        $idCliente = is_bool($rawCliente) ? false : filter_var($rawCliente, FILTER_VALIDATE_INT);
        if ($idCliente === false || $idCliente <= 0) {
            throw InventarioException::validacion(['id_cliente' => 'Debe seleccionar un cliente válido.']);
        }
        $idCliente = (int)$idCliente;

        $rawTipoComp = $input['id_tipo_comprobante'] ?? null;
        $idTipoComp = is_bool($rawTipoComp) ? false : filter_var($rawTipoComp, FILTER_VALIDATE_INT);
        if ($idTipoComp === false || $idTipoComp <= 0) {
            throw InventarioException::validacion(['id_tipo_comprobante' => 'Debe seleccionar un tipo de comprobante válido.']);
        }
        $idTipoComp = (int)$idTipoComp;

        if (!array_key_exists('id_metodo_pago', $input) || $input['id_metodo_pago'] === null || $input['id_metodo_pago'] === '') {
            throw InventarioException::validacion(['id_metodo_pago' => 'Debe seleccionar un método de pago válido.']);
        }
        $rawMetodoPago = $input['id_metodo_pago'];
        $idMetodoPago = is_bool($rawMetodoPago) ? false : filter_var($rawMetodoPago, FILTER_VALIDATE_INT);
        if ($idMetodoPago === false || $idMetodoPago <= 0) {
            throw InventarioException::validacion(['id_metodo_pago' => 'Debe seleccionar un método de pago válido.']);
        }
        $idMetodoPago = (int)$idMetodoPago;

        // 3. Validar estructura de productos y cantidades enteras positivas
        $productos = $input['productos'] ?? null;
        if (!is_array($productos) || empty($productos)) {
            throw InventarioException::validacion(['productos' => 'Debe agregar al menos un producto a la venta.']);
        }

        $itemsNormalizados = [];
        $demandaPorProducto = [];

        foreach ($productos as $index => $item) {
            $numLinea = $index + 1;
            if (!is_array($item)) {
                throw InventarioException::validacion(["producto_{$numLinea}" => "El producto en la línea {$numLinea} no tiene un formato válido."]);
            }

            $rawProd = $item['id_producto'] ?? null;
            $idProd = is_bool($rawProd) ? false : filter_var($rawProd, FILTER_VALIDATE_INT);
            if ($idProd === false || $idProd <= 0) {
                throw InventarioException::validacion(["producto_{$numLinea}" => "El producto en la línea {$numLinea} no tiene un identificador válido."]);
            }

            $rawCant = $item['cantidad'] ?? null;
            if (is_bool($rawCant)) {
                throw InventarioException::validacion(["cantidad_linea_{$numLinea}" => 'La cantidad debe ser un entero positivo.']);
            }
            $cantEntera = DefinicionMovimiento::validarCantidadEnteraPositiva(
                $rawCant,
                "cantidad_linea_{$numLinea}"
            );

            $idProdEntero = (int)$idProd;
            $itemsNormalizados[] = [
                'id_producto' => $idProdEntero,
                'cantidad' => $cantEntera,
                'numero_linea' => $numLinea
            ];

            $demandaPorProducto[$idProdEntero] = ($demandaPorProducto[$idProdEntero] ?? 0) + $cantEntera;
        }

        // Operación atómica de negocio (solo se ejecuta si la solicitud es nueva)
        $operacion = function () use (
            $idCliente, $idTipoComp, $idMetodoPago, $idUsuario,
            $itemsNormalizados, $demandaPorProducto
        ) {
            return $this->movimientoService->ejecutarEnTransaccion(function (PDO $pdo) use (
                $idCliente, $idTipoComp, $idMetodoPago, $idUsuario,
                $itemsNormalizados, $demandaPorProducto
            ) {
                // Orden de bloqueos: (1) control_solicitud (previo), (2) sesion_caja FOR UPDATE
                $sesionAbierta = $this->cajaModel->obtenerSesionAbiertaActual(true);
                if ($sesionAbierta === null) {
                    throw new InventarioException(
                        'No existe una sesión de caja abierta para registrar la venta.',
                        'CASH_SESSION_CLOSED',
                        409
                    );
                }
                if ((int)$sesionAbierta['id_usuario_vendedor'] !== (int)$idUsuario) {
                    throw new InventarioException(
                        'La sesión de caja abierta pertenece a otro vendedor.',
                        'SESSION_NOT_OWNED',
                        403
                    );
                }
                $idSesion = (int)$sesionAbierta['id_sesion_caja'];

                // Validar cliente activo en BD
                $stmtCli = $pdo->prepare('SELECT id_cliente, id_tipo_documento, estado FROM cliente WHERE id_cliente = :id LIMIT 1');
                $stmtCli->execute([':id' => $idCliente]);
                $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);
                if (!$cliente || (int)($cliente['estado'] ?? 0) !== 1) {
                    throw InventarioException::validacion(['id_cliente' => 'El cliente seleccionado no existe o está inactivo.']);
                }

                // Validar tipo de comprobante activo y regla de Factura con RUC
                $stmtComp = $pdo->prepare('SELECT id_tipo_comprobante, nombre FROM tipo_comprobante WHERE id_tipo_comprobante = :id AND estado = 1 LIMIT 1');
                $stmtComp->execute([':id' => $idTipoComp]);
                $tipoComp = $stmtComp->fetch(PDO::FETCH_ASSOC);
                if (!$tipoComp) {
                    throw InventarioException::validacion(['id_tipo_comprobante' => 'El tipo de comprobante seleccionado no es válido o está inactivo.']);
                }
                if (stripos((string)$tipoComp['nombre'], 'factura') !== false && (int)($cliente['id_tipo_documento'] ?? 0) !== 2) {
                    throw InventarioException::validacion([
                        'id_tipo_comprobante' => 'Para emitir Factura, el cliente debe tener RUC. Seleccione un cliente con RUC.'
                    ]);
                }

                // Validar método de pago activo y permitido (1=Efectivo, 2=Tarjeta, 3=Yape/Plin)
                $stmtMetodo = $pdo->prepare('SELECT id_metodo_pago, nombre FROM metodo_pago WHERE id_metodo_pago = :id AND estado = 1 LIMIT 1');
                $stmtMetodo->execute([':id' => $idMetodoPago]);
                $metodoPagoRow = $stmtMetodo->fetch(PDO::FETCH_ASSOC);
                if (!$metodoPagoRow || !in_array((int)$metodoPagoRow['id_metodo_pago'], [1, 2, 3], true)) {
                    throw InventarioException::validacion(['id_metodo_pago' => 'Debe seleccionar un método de pago válido.']);
                }

                // (3) Bloquear productos involucrados en orden ascendente
                $idsProductos = array_keys($demandaPorProducto);
                $productosBloqueados = $this->movimientoService->bloquearProductosOrdenados($idsProductos);

                foreach ($idsProductos as $idProd) {
                    $prod = $productosBloqueados[$idProd];
                    if ((int)$prod['estado'] !== 1) {
                        throw InventarioException::validacion([
                            'id_producto' => "El producto \"{$prod['nombre']}\" se encuentra inactivo y no admite ventas."
                        ]);
                    }
                }

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

                // Calcular subtotales y total en céntimos exactos (enteros 64-bit) y verificar límites
                $maxCentimos = 9999999999; // 99999999.99 DECIMAL(10,2)
                $detallesProcesados = [];
                $totalCentimos = 0;

                foreach ($itemsNormalizados as $item) {
                    $idProd = $item['id_producto'];
                    $cant = $item['cantidad'];
                    $precioCentimos = CajaService::parseDecimalACentimos((string)$productosBloqueados[$idProd]['precio_venta']);

                    if ($precioCentimos > 0 && $cant > intdiv($maxCentimos, $precioCentimos)) {
                        throw new InventarioException(
                            'El subtotal de la línea supera el máximo permitido por el sistema.',
                            'BALANCE_OVERFLOW',
                            409
                        );
                    }
                    $subtotalCentimos = $precioCentimos * $cant;
                    if ($totalCentimos > $maxCentimos - $subtotalCentimos) {
                        throw new InventarioException(
                            'El total de la venta supera el máximo permitido por el sistema.',
                            'BALANCE_OVERFLOW',
                            409
                        );
                    }
                    $totalCentimos += $subtotalCentimos;

                    $detallesProcesados[] = [
                        'id_producto' => $idProd,
                        'cantidad' => $cant,
                        'precio_unitario' => CajaService::centimosADecimal($precioCentimos),
                        'subtotal' => CajaService::centimosADecimal($subtotalCentimos),
                    ];
                }

                // Verificar límites de acumulados de sesión antes de persistir
                $ventasEfectivoCent = CajaService::parseDecimalACentimos((string)$sesionAbierta['total_ventas_efectivo']);
                $ventasTarjetaCent = CajaService::parseDecimalACentimos((string)$sesionAbierta['total_ventas_tarjeta']);
                $ventasYapeCent = CajaService::parseDecimalACentimos((string)$sesionAbierta['total_ventas_yape_plin']);
                $efectivoEsperadoCent = CajaService::parseDecimalACentimos((string)$sesionAbierta['efectivo_esperado_actual']);

                if ($idMetodoPago === 1) {
                    if ($ventasEfectivoCent > $maxCentimos - $totalCentimos || $efectivoEsperadoCent > $maxCentimos - $totalCentimos) {
                        throw new InventarioException(
                            'El acumulado de ventas en efectivo supera el máximo permitido por el sistema.',
                            'BALANCE_OVERFLOW',
                            409
                        );
                    }
                    $ventasEfectivoCent += $totalCentimos;
                    $efectivoEsperadoCent += $totalCentimos;
                } elseif ($idMetodoPago === 2) {
                    if ($ventasTarjetaCent > $maxCentimos - $totalCentimos) {
                        throw new InventarioException(
                            'El acumulado de ventas con tarjeta supera el máximo permitido por el sistema.',
                            'BALANCE_OVERFLOW',
                            409
                        );
                    }
                    $ventasTarjetaCent += $totalCentimos;
                } elseif ($idMetodoPago === 3) {
                    if ($ventasYapeCent > $maxCentimos - $totalCentimos) {
                        throw new InventarioException(
                            'El acumulado de ventas con Yape / Plin supera el máximo permitido por el sistema.',
                            'BALANCE_OVERFLOW',
                            409
                        );
                    }
                    $ventasYapeCent += $totalCentimos;
                }

                $totalStr = CajaService::centimosADecimal($totalCentimos);

                // (4) Bloquear y avanzar serie y correlativo de comprobante
                $stmtSerie = $pdo->prepare(
                    'SELECT id_serie, serie, correlativo_actual
                       FROM serie_comprobante
                      WHERE id_tipo_comprobante = :id AND estado = 1
                      LIMIT 1 FOR UPDATE'
                );
                $stmtSerie->execute([':id' => $idTipoComp]);
                $serieRow = $stmtSerie->fetch(PDO::FETCH_ASSOC);

                if (!$serieRow) {
                    throw new InventarioException(
                        'No hay una serie configurada para este tipo de comprobante.',
                        'VALIDATION_ERROR',
                        422,
                        ['id_tipo_comprobante' => 'No hay una serie configurada para este tipo de comprobante.']
                    );
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

                $fechaHoraActual = date('Y-m-d H:i:s');

                // Insertar cabecera de venta ligada a la sesión de caja
                $stmtVenta = $pdo->prepare(
                    'INSERT INTO venta (id_cliente, id_usuario, id_sesion_caja, id_tipo_comprobante, serie, numero, fecha_venta, total, id_metodo_pago, estado)
                     VALUES (:id_cliente, :id_usuario, :id_sesion_caja, :id_tipo_comprobante, :serie, :numero, :fecha_venta, :total, :id_metodo_pago, 1)'
                );
                $stmtVenta->execute([
                    ':id_cliente' => $idCliente,
                    ':id_usuario' => (int)$idUsuario,
                    ':id_sesion_caja' => $idSesion,
                    ':id_tipo_comprobante' => $idTipoComp,
                    ':serie' => $serie,
                    ':numero' => $numero,
                    ':fecha_venta' => $fechaHoraActual,
                    ':total' => $totalStr,
                    ':id_metodo_pago' => $idMetodoPago
                ]);
                $idVenta = (int)$pdo->lastInsertId();

                // Insertar detalles y aplicar movimientos en Kardex
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

                    $this->movimientoService->aplicarMovimiento([
                        'id_producto' => $det['id_producto'],
                        'id_usuario' => (int)$idUsuario,
                        'origen_operacion' => DefinicionMovimiento::ORIGEN_VENTA,
                        'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                        'cantidad' => $det['cantidad'],
                        'id_accion_venta' => $idVenta,
                        'id_detalle_venta' => $idDetalleVenta,
                        'referencia_operacion' => "Venta #{$idVenta} - {$serie}-{$numero}",
                        'exigir_producto_activo' => true
                    ]);
                }

                // Solo efectivo genera fila VENTA_EFECTIVO en movimiento_caja
                if ($idMetodoPago === 1) {
                    $this->cajaModel->insertarMovimientoCaja([
                        'id_sesion_caja' => $idSesion,
                        'tipo_movimiento' => 'VENTA_EFECTIVO',
                        'monto' => $totalStr,
                        'efecto_saldo' => $totalStr,
                        'motivo' => "Venta en efectivo {$serie}-{$numero}",
                        'referencia_visible' => "{$serie}-{$numero}",
                        'id_usuario_registro' => (int)$idUsuario,
                        'id_usuario_admin' => null,
                        'id_venta' => $idVenta,
                        'id_sesion_origen_omitido' => null,
                        'declarado_ocurrio_en_sesion_origen_abierta' => null,
                        'confirmado_reflejado_en_apertura' => null,
                        'id_movimiento_original' => null,
                        'id_solicitud_idempotencia' => null,
                        'fecha_hora_registro' => $fechaHoraActual,
                    ]);
                }

                // Actualizar acumulados de sesión e incrementar version_caja una sola vez
                $this->cajaModel->actualizarAcumuladosVentaYVersionSesion(
                    $idSesion,
                    CajaService::centimosADecimal($ventasEfectivoCent),
                    CajaService::centimosADecimal($ventasTarjetaCent),
                    CajaService::centimosADecimal($ventasYapeCent),
                    CajaService::centimosADecimal($efectivoEsperadoCent)
                );
                $nuevaVersionCaja = (int)$sesionAbierta['version_caja'] + 1;

                // Desactualizar cualquier arqueo vigente de esta sesión
                $this->cajaModel->desactualizarArqueoVigenteSesion($idSesion, $fechaHoraActual);

                return [
                    'status' => true,
                    'code' => 'SALE_CREATED',
                    'idempotent_replay' => false,
                    'message' => 'Venta registrada con éxito.',
                    'data' => [
                        'result_code' => 'SALE_CREATED',
                        'id_venta' => $idVenta,
                        'id_sesion_caja' => $idSesion,
                        'serie' => $serie,
                        'numero' => $numero,
                        'id_metodo_pago' => $idMetodoPago,
                        'metodo_pago' => (string)$metodoPagoRow['nombre'],
                        'total' => round($totalCentimos / 100, 2),
                        'current_cash_balance' => round($efectivoEsperadoCent / 100, 2),
                        'version_caja' => $nuevaVersionCaja,
                    ]
                ];
            });
        };

        // Soporte de idempotencia previo a validar estado mutable
        if ($referenciaValida !== null) {
            $payloadFuncional = [
                'id_cliente' => $idCliente,
                'id_tipo_comprobante' => $idTipoComp,
                'id_metodo_pago' => $idMetodoPago,
                'productos' => $itemsNormalizados
            ];

            $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
                (int)$idUsuario,
                DefinicionMovimiento::SOLICITUD_VENTA,
                $referenciaValida,
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
            if (!empty($resIdemp['idempotent_replay']) && isset($respuestaFinal['data']['id_venta'])) {
                $respuestaFinal['status'] = true;
                $respuestaFinal['code'] = 'OPERATION_ALREADY_CONFIRMED';
                $respuestaFinal['idempotent_replay'] = true;
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
