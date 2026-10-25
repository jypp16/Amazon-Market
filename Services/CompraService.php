<?php

namespace Services;

use Libraries\Core\Conexion;
use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Models\CompraModel;
use Models\DetalleCompraModel;
use Models\ProductoModel;
use Models\KardexModel;
use Models\ProveedorModel;
use Models\UsuarioModel;
use PDO;
use Exception;
use Throwable;

class CompraService {
    private CompraModel $compraModel;
    private DetalleCompraModel $detalleCompraModel;
    private ProductoModel $productoModel;
    private KardexModel $kardexModel;
    private MovimientoInventarioService $movimientoService;
    private ControlSolicitudService $controlSolicitudService;

    public function __construct(
        ?CompraModel $compraModel = null,
        ?DetalleCompraModel $detalleCompraModel = null,
        ?ProductoModel $productoModel = null,
        ?KardexModel $kardexModel = null,
        ?MovimientoInventarioService $movimientoService = null,
        ?ControlSolicitudService $controlSolicitudService = null
    ) {
        $this->compraModel = $compraModel ?? new CompraModel();
        $this->detalleCompraModel = $detalleCompraModel ?? new DetalleCompraModel();
        $this->productoModel = $productoModel ?? new ProductoModel();
        $this->kardexModel = $kardexModel ?? new KardexModel();
        $this->movimientoService = $movimientoService ?? new MovimientoInventarioService();
        $this->controlSolicitudService = $controlSolicitudService ?? new ControlSolicitudService($this->movimientoService->getPdo());
    }

    public function getMovimientoService(): MovimientoInventarioService {
        return $this->movimientoService;
    }

    /**
     * Registra una compra asegurando atomicidad, verificación de catálogo,
     * bloqueo pesimista ordenado y generación canónica de movimientos en Kardex.
     */
    public function registrarCompra(array $cabecera, array $detalles, int $id_usuario, ?string $referencia_solicitud = null): array {
        $operacion = function () use ($cabecera, $detalles, $id_usuario) {
            // Validar responsable
            $responsable = $this->movimientoService->verificarResponsableActivo($id_usuario, false);

            // Validar proveedor
            $idProveedor = filter_var($cabecera['id_proveedor'] ?? null, FILTER_VALIDATE_INT);
            if (!$idProveedor || $idProveedor <= 0) {
                throw InventarioException::validacion(['id_proveedor' => 'Debe seleccionar un proveedor válido.']);
            }
            $proveedor = (new ProveedorModel())->find((int)$idProveedor);
            if (!$proveedor || (int)($proveedor['estado'] ?? 0) !== 1) {
                throw InventarioException::validacion(['id_proveedor' => 'El proveedor seleccionado no existe o se encuentra inactivo.']);
            }

            // Validar tipo comprobante
            $idTipoComp = filter_var($cabecera['id_tipo_comprobante'] ?? null, FILTER_VALIDATE_INT);
            if (!$idTipoComp || $idTipoComp <= 0) {
                throw InventarioException::validacion(['id_tipo_comprobante' => 'Debe seleccionar un tipo de comprobante válido.']);
            }

            $serie = trim((string)($cabecera['serie'] ?? ''));
            $numero = trim((string)($cabecera['numero'] ?? ''));
            if ($serie === '' || $numero === '') {
                throw InventarioException::validacion(['comprobante' => 'La serie y el número de comprobante son obligatorios.']);
            }

            // Validar duplicado previo de comprobante
            if ($this->compraModel->existeComprobante($idProveedor, $idTipoComp, $serie, $numero)) {
                throw new Exception("El comprobante {$serie}-{$numero} ya se encuentra registrado para este proveedor.");
            }

            if (empty($detalles) || !is_array($detalles)) {
                throw InventarioException::validacion(['detalles' => 'La compra debe incluir al menos un detalle de producto.']);
            }

            // Validar lotes y vencimientos
            $this->validarProductosYLotes($detalles);

            // Validar detalles y calcular cantidades base enteras
            $detallesNormalizados = [];
            $idsProductos = [];
            $totalCalculadoCentimos = 0;

            foreach ($detalles as $idx => $det) {
                $numLinea = $idx + 1;
                $idProd = filter_var($det['id_producto'] ?? null, FILTER_VALIDATE_INT);
                if (!$idProd || $idProd <= 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "El producto en la línea {$numLinea} no es válido."]);
                }

                $idPres = filter_var($det['id_presentacion'] ?? null, FILTER_VALIDATE_INT);
                if (!$idPres || $idPres <= 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "La presentación en la línea {$numLinea} no es válida."]);
                }

                $cantPres = filter_var($det['cantidad_presentaciones'] ?? null, FILTER_VALIDATE_INT);
                if ($cantPres === false || $cantPres <= 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "La cantidad de presentaciones en la línea {$numLinea} debe ser un entero mayor a 0."]);
                }

                $equiv = filter_var($det['equivalencia_aplicada'] ?? null, FILTER_VALIDATE_INT);
                if ($equiv === false || $equiv <= 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "La equivalencia aplicada en la línea {$numLinea} debe ser un entero mayor a 0."]);
                }

                $cantBase = $cantPres * $equiv;
                $importeLinea = (float)($det['importe_final_linea'] ?? 0);
                if ($importeLinea < 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "El importe en la línea {$numLinea} no puede ser negativo."]);
                }

                $totalCalculadoCentimos += (int)round($importeLinea * 100);
                $idsProductos[] = (int)$idProd;

                $detallesNormalizados[] = [
                    'id_producto' => (int)$idProd,
                    'id_presentacion' => (int)$idPres,
                    'cantidad_presentaciones' => $cantPres,
                    'equivalencia_aplicada' => $equiv,
                    'cantidad_base' => $cantBase,
                    'importe_final_linea' => $importeLinea,
                    'numero_lote' => !empty($det['numero_lote']) ? trim((string)$det['numero_lote']) : null,
                    'fecha_vencimiento' => !empty($det['fecha_vencimiento']) ? trim((string)$det['fecha_vencimiento']) : null,
                ];
            }

            $totalDoc = isset($cabecera['total_documento']) ? (float)$cabecera['total_documento'] : ($totalCalculadoCentimos / 100);

            return $this->movimientoService->ejecutarEnTransaccion(function (PDO $pdo) use (
                $cabecera, $detallesNormalizados, $idsProductos, $idProveedor, $idTipoComp, $serie, $numero, $totalDoc, $id_usuario
            ) {
                // Bloquear productos en orden ascendente
                $productosBloqueados = $this->movimientoService->bloquearProductosOrdenados($idsProductos);
                foreach ($detallesNormalizados as $dn) {
                    $prod = $productosBloqueados[$dn['id_producto']];
                    if ($prod['estado'] !== 1) {
                        throw InventarioException::validacion([
                            'id_producto' => "El producto '{$prod['nombre']}' se encuentra inactivo y no admite compras."
                        ]);
                    }
                }

                // Verificar comprobante nuevamente bajo bloqueo
                if ($this->compraModel->existeComprobante($idProveedor, $idTipoComp, $serie, $numero)) {
                    throw new Exception("El comprobante {$serie}-{$numero} ya se encuentra registrado para este proveedor.");
                }

                // Insertar cabecera de compra
                $datosCabecera = [
                    'id_proveedor' => $idProveedor,
                    'id_usuario' => $id_usuario,
                    'id_tipo_comprobante' => $idTipoComp,
                    'serie' => $serie,
                    'numero' => $numero,
                    'fecha_emision' => $cabecera['fecha_emision'] ?? date('Y-m-d'),
                    'fecha_recepcion' => $cabecera['fecha_recepcion'] ?? date('Y-m-d'),
                    'total_documento' => $totalDoc,
                    'estado' => 'Confirmada'
                ];

                $stmtCompra = $pdo->prepare('INSERT INTO compra (id_proveedor, id_usuario, id_tipo_comprobante, serie, numero, fecha_emision, fecha_recepcion, total_documento, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmtCompra->execute([
                    $datosCabecera['id_proveedor'],
                    $datosCabecera['id_usuario'],
                    $datosCabecera['id_tipo_comprobante'],
                    $datosCabecera['serie'],
                    $datosCabecera['numero'],
                    $datosCabecera['fecha_emision'],
                    $datosCabecera['fecha_recepcion'],
                    $datosCabecera['total_documento'],
                    $datosCabecera['estado']
                ]);
                $idCompra = (int)$pdo->lastInsertId();

                // Insertar detalles e invocar aplicarMovimiento
                $stmtDetalle = $pdo->prepare('INSERT INTO detalle_compra (id_compra, id_producto, id_presentacion, cantidad_presentaciones, equivalencia_aplicada, cantidad_base, importe_final_linea, numero_lote, fecha_vencimiento, version_detalle, es_vigente, id_detalle_origen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NULL)');

                foreach ($detallesNormalizados as $det) {
                    $stmtDetalle->execute([
                        $idCompra,
                        $det['id_producto'],
                        $det['id_presentacion'],
                        $det['cantidad_presentaciones'],
                        $det['equivalencia_aplicada'],
                        $det['cantidad_base'],
                        $det['importe_final_linea'],
                        $det['numero_lote'],
                        $det['fecha_vencimiento']
                    ]);
                    $idDetalle = (int)$pdo->lastInsertId();

                    // Aplicar movimiento de inventario (INCREMENTO canónico)
                    $this->movimientoService->aplicarMovimiento([
                        'id_producto' => $det['id_producto'],
                        'id_usuario' => $id_usuario,
                        'origen_operacion' => DefinicionMovimiento::ORIGEN_COMPRA,
                        'sentido_movimiento' => DefinicionMovimiento::SENTIDO_INCREMENTO,
                        'cantidad' => $det['cantidad_base'],
                        'id_accion_compra' => $idCompra,
                        'id_detalle_compra' => $idDetalle,
                        'lote' => $det['numero_lote'],
                        'referencia_operacion' => "Compra #{$idCompra} - {$serie}-{$numero}",
                        'exigir_producto_activo' => true
                    ]);
                }

                return [
                    'status' => true,
                    'message' => 'Compra registrada correctamente.',
                    'id_compra' => $idCompra
                ];
            });
        };

        if ($referencia_solicitud !== null && trim($referencia_solicitud) !== '') {
            $payload = [
                'cabecera' => $cabecera,
                'detalles' => $detalles
            ];
            $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
                $id_usuario,
                DefinicionMovimiento::SOLICITUD_COMPRA,
                $referencia_solicitud,
                $payload,
                function (PDO $pdo) use ($operacion) {
                    $res = $operacion();
                    return [
                        'id_registro' => (int)($res['id_compra'] ?? 0),
                        'response' => $res
                    ];
                }
            );
            $resp = $resIdemp['response'];
            if (!empty($resIdemp['idempotent_replay']) && isset($resp['data']['id_compra'])) {
                $resp['id_compra'] = $resp['data']['id_compra'];
            }
            return $resp;
        }

        return $operacion();
    }

    /**
     * Corrige una compra existente mediante versionamiento inmutable de detalles (version_detalle),
     * cálculo de diferencia neta por producto (Δ = nuevas - vigentes), validación estricta de stock
     * y emisión de movimientos Kardex únicamente cuando Δ ≠ 0.
     */
    public function corregirCompra(int $id_compra, array $cabecera, array $nuevosDetalles, int $id_usuario, string $motivo = '', ?string $referencia_solicitud = null): array {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw InventarioException::validacion(['motivo' => 'El motivo de corrección es obligatorio.']);
        }

        $operacion = function () use ($id_compra, $cabecera, $nuevosDetalles, $id_usuario, $motivo) {
            // Exigir rol Administrador activo
            $responsable = $this->movimientoService->verificarResponsableActivo($id_usuario, true);

            // Validar cabecera y comprobante
            $idProveedor = filter_var($cabecera['id_proveedor'] ?? null, FILTER_VALIDATE_INT);
            if (!$idProveedor || $idProveedor <= 0) {
                throw InventarioException::validacion(['id_proveedor' => 'Debe seleccionar un proveedor válido.']);
            }
            $idTipoComp = filter_var($cabecera['id_tipo_comprobante'] ?? null, FILTER_VALIDATE_INT);
            if (!$idTipoComp || $idTipoComp <= 0) {
                throw InventarioException::validacion(['id_tipo_comprobante' => 'Debe seleccionar un tipo de comprobante válido.']);
            }
            $serie = trim((string)($cabecera['serie'] ?? ''));
            $numero = trim((string)($cabecera['numero'] ?? ''));
            if ($serie === '' || $numero === '') {
                throw InventarioException::validacion(['comprobante' => 'La serie y el número son obligatorios.']);
            }

            if ($this->compraModel->existeComprobante($idProveedor, $idTipoComp, $serie, $numero, $id_compra)) {
                throw new Exception("El comprobante {$serie}-{$numero} ya se encuentra registrado para este proveedor en otra compra.");
            }

            if (empty($nuevosDetalles) || !is_array($nuevosDetalles)) {
                throw InventarioException::validacion(['detalles' => 'La corrección debe incluir al menos un detalle de producto.']);
            }

            $this->validarProductosYLotes($nuevosDetalles);

            // Normalizar nuevos detalles
            $detallesNormalizados = [];
            $idsProductosNuevos = [];
            $unidadesNuevasPorProducto = [];
            $totalCalculadoCentimos = 0;

            foreach ($nuevosDetalles as $idx => $det) {
                $numLinea = $idx + 1;
                $idProd = filter_var($det['id_producto'] ?? null, FILTER_VALIDATE_INT);
                if (!$idProd || $idProd <= 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "El producto en la línea {$numLinea} no es válido."]);
                }
                $idPres = filter_var($det['id_presentacion'] ?? null, FILTER_VALIDATE_INT);
                if (!$idPres || $idPres <= 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "La presentación en la línea {$numLinea} no es válida."]);
                }
                $cantPres = filter_var($det['cantidad_presentaciones'] ?? null, FILTER_VALIDATE_INT);
                if ($cantPres === false || $cantPres <= 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "La cantidad de presentaciones en la línea {$numLinea} debe ser un entero mayor a 0."]);
                }
                $equiv = filter_var($det['equivalencia_aplicada'] ?? null, FILTER_VALIDATE_INT);
                if ($equiv === false || $equiv <= 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "La equivalencia aplicada en la línea {$numLinea} debe ser un entero mayor a 0."]);
                }
                $cantBase = $cantPres * $equiv;
                $importeLinea = (float)($det['importe_final_linea'] ?? 0);
                if ($importeLinea < 0) {
                    throw InventarioException::validacion(["detalle_{$numLinea}" => "El importe en la línea {$numLinea} no puede ser negativo."]);
                }

                $totalCalculadoCentimos += (int)round($importeLinea * 100);
                $idsProductosNuevos[] = (int)$idProd;
                $unidadesNuevasPorProducto[(int)$idProd] = ($unidadesNuevasPorProducto[(int)$idProd] ?? 0) + $cantBase;

                $detallesNormalizados[] = [
                    'id_producto' => (int)$idProd,
                    'id_presentacion' => (int)$idPres,
                    'cantidad_presentaciones' => $cantPres,
                    'equivalencia_aplicada' => $equiv,
                    'cantidad_base' => $cantBase,
                    'importe_final_linea' => $importeLinea,
                    'numero_lote' => !empty($det['numero_lote']) ? trim((string)$det['numero_lote']) : null,
                    'fecha_vencimiento' => !empty($det['fecha_vencimiento']) ? trim((string)$det['fecha_vencimiento']) : null,
                ];
            }

            $totalDoc = isset($cabecera['total_documento']) ? (float)$cabecera['total_documento'] : ($totalCalculadoCentimos / 100);

            return $this->movimientoService->ejecutarEnTransaccion(function (PDO $pdo) use (
                $id_compra, $cabecera, $detallesNormalizados, $unidadesNuevasPorProducto,
                $idProveedor, $idTipoComp, $serie, $numero, $totalDoc, $id_usuario, $motivo
            ) {
                // Bloquear cabecera de compra
                $stmtLockCompra = $pdo->prepare('SELECT * FROM compra WHERE id_compra = ? FOR UPDATE');
                $stmtLockCompra->execute([$id_compra]);
                $compraActual = $stmtLockCompra->fetch(PDO::FETCH_ASSOC);

                if (!$compraActual) {
                    throw new Exception("Compra no encontrada ID: {$id_compra}.");
                }
                if ($compraActual['estado'] === 'Anulada') {
                    throw new Exception("No se puede corregir una compra anulada.");
                }

                // Obtener detalles vigentes (es_vigente = 1)
                $detallesVigentes = $this->detalleCompraModel->obtenerDetallesPorCompra($id_compra, true);
                if (empty($detallesVigentes)) {
                    throw new Exception("La compra no posee detalles vigentes para corregir.");
                }

                $unidadesVigentesPorProducto = [];
                $idsProductosVigentes = [];
                $mapaDetalleVigentePorProducto = [];
                foreach ($detallesVigentes as $dv) {
                    $idProd = (int)$dv['id_producto'];
                    $idsProductosVigentes[] = $idProd;
                    $unidadesVigentesPorProducto[$idProd] = ($unidadesVigentesPorProducto[$idProd] ?? 0) + (int)$dv['cantidad_base'];
                    $mapaDetalleVigentePorProducto[$idProd] = (int)$dv['id_detalle_compra'];
                }

                // Unir todos los IDs de productos involucrados (vigentes + nuevos)
                $todosIdsProductos = array_values(array_unique(array_merge($idsProductosVigentes, array_keys($unidadesNuevasPorProducto))));

                // Bloquear todos los productos en orden ascendente
                $productosBloqueados = $this->movimientoService->bloquearProductosOrdenados($todosIdsProductos);

                // Calcular diferencia neta por producto: Δ(P) = U_nueva(P) - U_vigente(P)
                $deltasPorProducto = [];
                foreach ($todosIdsProductos as $idProd) {
                    $uNueva = $unidadesNuevasPorProducto[$idProd] ?? 0;
                    $uVigente = $unidadesVigentesPorProducto[$idProd] ?? 0;
                    $delta = $uNueva - $uVigente;
                    $deltasPorProducto[$idProd] = $delta;

                    // Si la diferencia es negativa, verificar que el stock actual cubra la disminución
                    if ($delta < 0) {
                        $stockActual = (int)$productosBloqueados[$idProd]['stock_actual'];
                        $unidadesARestar = abs($delta);
                        if ($stockActual - $unidadesARestar < 0) {
                            $nombre = $productosBloqueados[$idProd]['nombre'];
                            throw InventarioException::stockInsuficiente(
                                $idProd,
                                $stockActual,
                                $unidadesARestar,
                                $nombre
                            );
                        }
                    }
                }

                // Obtener versión máxima y calcular la nueva versión de detalle
                $maxVersion = $this->detalleCompraModel->obtenerMaxVersion($id_compra);
                $nuevaVersion = $maxVersion + 1;

                // 1. Marcar detalles vigentes anteriores como reemplazados (es_vigente = 0)
                $stmtReemplazar = $pdo->prepare('UPDATE detalle_compra SET es_vigente = 0, reemplazado_en = NOW() WHERE id_compra = ? AND es_vigente = 1');
                $stmtReemplazar->execute([$id_compra]);

                // 2. Insertar nuevos detalles con versión incrementada y enlace a versión anterior
                $stmtNuevoDetalle = $pdo->prepare('INSERT INTO detalle_compra (id_compra, id_producto, id_presentacion, cantidad_presentaciones, equivalencia_aplicada, cantidad_base, importe_final_linea, numero_lote, fecha_vencimiento, version_detalle, es_vigente, id_detalle_origen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');

                $mapaNuevoDetallePorProducto = [];
                foreach ($detallesNormalizados as $det) {
                    $idProd = $det['id_producto'];
                    $idDetalleOrigen = $mapaDetalleVigentePorProducto[$idProd] ?? null;

                    $stmtNuevoDetalle->execute([
                        $id_compra,
                        $idProd,
                        $det['id_presentacion'],
                        $det['cantidad_presentaciones'],
                        $det['equivalencia_aplicada'],
                        $det['cantidad_base'],
                        $det['importe_final_linea'],
                        $det['numero_lote'],
                        $det['fecha_vencimiento'],
                        $nuevaVersion,
                        $idDetalleOrigen
                    ]);
                    $idNuevoDet = (int)$pdo->lastInsertId();
                    if (!isset($mapaNuevoDetallePorProducto[$idProd])) {
                        $mapaNuevoDetallePorProducto[$idProd] = $idNuevoDet;
                    }
                }

                // 3. Aplicar movimientos en Kardex solo si Δ ≠ 0
                foreach ($deltasPorProducto as $idProd => $delta) {
                    if ($delta === 0) {
                        // Cambio exclusivo de costo, lote o datos de cabecera: NO genera movimiento de stock
                        continue;
                    }

                    $idDetalleReferencia = $mapaNuevoDetallePorProducto[$idProd]
                        ?? $mapaDetalleVigentePorProducto[$idProd];

                    if ($delta > 0) {
                        // Entrada adicional por corrección
                        $this->movimientoService->aplicarMovimiento([
                            'id_producto' => $idProd,
                            'id_usuario' => $id_usuario,
                            'origen_operacion' => DefinicionMovimiento::ORIGEN_CORRECCION_COMPRA,
                            'sentido_movimiento' => DefinicionMovimiento::SENTIDO_INCREMENTO,
                            'cantidad' => $delta,
                            'id_accion_compra' => $id_compra,
                            'id_detalle_compra' => $idDetalleReferencia,
                            'referencia_operacion' => "Corrección Compra #{$id_compra} v{$nuevaVersion} (+{$delta})",
                            'justificacion' => $motivo,
                            'exigir_producto_activo' => false
                        ]);
                    } else {
                        // Salida por reducción en corrección
                        $unidadesDisminuir = abs($delta);
                        $this->movimientoService->aplicarMovimiento([
                            'id_producto' => $idProd,
                            'id_usuario' => $id_usuario,
                            'origen_operacion' => DefinicionMovimiento::ORIGEN_CORRECCION_COMPRA,
                            'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                            'cantidad' => $unidadesDisminuir,
                            'id_accion_compra' => $id_compra,
                            'id_detalle_compra' => $idDetalleReferencia,
                            'referencia_operacion' => "Corrección Compra #{$id_compra} v{$nuevaVersion} (-{$unidadesDisminuir})",
                            'justificacion' => $motivo,
                            'exigir_producto_activo' => false
                        ]);
                    }
                }

                // 4. Actualizar cabecera de la compra
                $stmtUpdateCabecera = $pdo->prepare('UPDATE compra SET id_proveedor = ?, id_tipo_comprobante = ?, serie = ?, numero = ?, fecha_emision = ?, fecha_recepcion = ?, total_documento = ?, estado = ?, motivo_correccion = ?, fecha_correccion = NOW() WHERE id_compra = ?');
                $stmtUpdateCabecera->execute([
                    $idProveedor,
                    $idTipoComp,
                    $serie,
                    $numero,
                    $cabecera['fecha_emision'] ?? $compraActual['fecha_emision'],
                    $cabecera['fecha_recepcion'] ?? $compraActual['fecha_recepcion'],
                    $totalDoc,
                    'Corregida',
                    $motivo,
                    $id_compra
                ]);

                // 5. Registrar en historial de auditoría
                $this->compraModel->registrarHistorial($id_compra, $id_usuario, 'Correccion', $motivo, [
                    'cabecera_anterior' => $compraActual,
                    'detalles_anteriores' => $detallesVigentes,
                    'version_generada' => $nuevaVersion,
                    'deltas' => $deltasPorProducto
                ]);

                return [
                    'status' => true,
                    'message' => 'Compra corregida correctamente.',
                    'id_compra' => $id_compra,
                    'version' => $nuevaVersion
                ];
            });
        };

        if ($referencia_solicitud !== null && trim($referencia_solicitud) !== '') {
            $payload = [
                'id_compra' => $id_compra,
                'cabecera' => $cabecera,
                'detalles' => $nuevosDetalles,
                'motivo' => $motivo
            ];
            $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
                $id_usuario,
                DefinicionMovimiento::SOLICITUD_CORRECCION_COMPRA,
                $referencia_solicitud,
                $payload,
                function (PDO $pdo) use ($operacion, $id_compra) {
                    $res = $operacion();
                    return [
                        'id_registro' => $id_compra,
                        'response' => $res
                    ];
                }
            );
            return $resIdemp['response'];
        }

        return $operacion();
    }

    /**
     * Anula una compra, restando las existencias ingresadas mediante ANULACION_COMPRA (DISMINUCION),
     * validando que ningún producto quede con stock negativo y previniendo anulaciones duplicadas.
     */
    public function anularCompra(int $id_compra, int $id_usuario, string $motivo = '', ?string $referencia_solicitud = null): array {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw InventarioException::validacion(['motivo' => 'El motivo de anulación es obligatorio.']);
        }

        $operacion = function () use ($id_compra, $id_usuario, $motivo) {
            // Exigir rol Administrador activo
            $responsable = $this->movimientoService->verificarResponsableActivo($id_usuario, true);

            return $this->movimientoService->ejecutarEnTransaccion(function (PDO $pdo) use ($id_compra, $id_usuario, $motivo) {
                // Bloquear cabecera de la compra
                $stmtLock = $pdo->prepare('SELECT * FROM compra WHERE id_compra = ? FOR UPDATE');
                $stmtLock->execute([$id_compra]);
                $compra = $stmtLock->fetch(PDO::FETCH_ASSOC);

                if (!$compra) {
                    throw new Exception("Compra no encontrada ID: {$id_compra}.");
                }
                if ($compra['estado'] === 'Anulada') {
                    throw new Exception("La compra ya se encuentra anulada.");
                }

                // Obtener detalles vigentes
                $detallesVigentes = $this->detalleCompraModel->obtenerDetallesPorCompra($id_compra, true);
                if (empty($detallesVigentes)) {
                    throw new Exception("La compra no tiene detalles vigentes para revertir.");
                }

                // Sumar unidades a revertir por producto y bloquear productos
                $unidadesRevertirPorProducto = [];
                $idsProductos = [];
                foreach ($detallesVigentes as $dv) {
                    $idProd = (int)$dv['id_producto'];
                    $idsProductos[] = $idProd;
                    $unidadesRevertirPorProducto[$idProd] = ($unidadesRevertirPorProducto[$idProd] ?? 0) + (int)$dv['cantidad_base'];
                }
                $idsProductos = array_values(array_unique($idsProductos));

                // Bloquear productos en orden ascendente
                $productosBloqueados = $this->movimientoService->bloquearProductosOrdenados($idsProductos);

                // Verificar suficiencia de stock para todos los productos antes de alterar nada
                foreach ($unidadesRevertirPorProducto as $idProd => $unidadesRevertir) {
                    $stockActual = (int)$productosBloqueados[$idProd]['stock_actual'];
                    if ($stockActual - $unidadesRevertir < 0) {
                        $nombre = $productosBloqueados[$idProd]['nombre'];
                        throw InventarioException::stockInsuficiente(
                            $idProd,
                            $stockActual,
                            $unidadesRevertir,
                            $nombre
                        );
                    }
                }

                // Aplicar movimiento de reversión canónica por cada detalle vigente
                foreach ($detallesVigentes as $dv) {
                    $cantBase = (int)$dv['cantidad_base'];
                    $this->movimientoService->aplicarMovimiento([
                        'id_producto' => (int)$dv['id_producto'],
                        'id_usuario' => $id_usuario,
                        'origen_operacion' => DefinicionMovimiento::ORIGEN_ANULACION_COMPRA,
                        'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                        'cantidad' => $cantBase,
                        'id_accion_compra' => $id_compra,
                        'id_detalle_compra' => (int)$dv['id_detalle_compra'],
                        'referencia_operacion' => "Anulación Compra #{$id_compra}",
                        'justificacion' => $motivo,
                        'exigir_producto_activo' => false
                    ]);
                }

                // Actualizar estado de la compra a Anulada
                $stmtUpdate = $pdo->prepare('UPDATE compra SET estado = ?, motivo_anulacion = ?, fecha_anulacion = NOW() WHERE id_compra = ?');
                $stmtUpdate->execute(['Anulada', $motivo, $id_compra]);

                // Registrar en historial de auditoría
                $this->compraModel->registrarHistorial($id_compra, $id_usuario, 'Anulacion', $motivo, [
                    'estado_anterior' => $compra['estado'],
                    'detalles_revertidos' => $detallesVigentes
                ]);

                return [
                    'status' => true,
                    'message' => 'Compra anulada correctamente.'
                ];
            });
        };

        if ($referencia_solicitud !== null && trim($referencia_solicitud) !== '') {
            $payload = [
                'id_compra' => $id_compra,
                'motivo' => $motivo
            ];
            $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
                $id_usuario,
                DefinicionMovimiento::SOLICITUD_ANULACION_COMPRA,
                $referencia_solicitud,
                $payload,
                function (PDO $pdo) use ($operacion, $id_compra) {
                    $res = $operacion();
                    return [
                        'id_registro' => $id_compra,
                        'response' => $res
                    ];
                }
            );
            return $resIdemp['response'];
        }

        return $operacion();
    }

    public function normalizarFiltrosCompras(array $filtros): array {
        $permitidos = ['Confirmada', 'Corregida', 'Anulada'];
        if (array_key_exists('estados', $filtros)) {
            if (array_key_exists('estado', $filtros)) {
                throw new \InvalidArgumentException('No combines los parámetros estado y estados.');
            }
            $estados = $filtros['estados'];
            if (!is_array($estados) || !array_is_list($estados) || !$estados) {
                throw new \InvalidArgumentException('Selecciona al menos un estado válido.');
            }
        } elseif (array_key_exists('estado', $filtros)) {
            if (!is_string($filtros['estado'])) throw new \InvalidArgumentException('El estado debe ser válido.');
            $estados = $filtros['estado'] === '' ? $permitidos : [$filtros['estado']];
        } else {
            $estados = ['Confirmada', 'Corregida'];
        }
        foreach ($estados as $estado) {
            if (!is_string($estado) || !in_array($estado, $permitidos, true)) {
                throw new \InvalidArgumentException('Selecciona únicamente Confirmada, Corregida o Anulada.');
            }
        }
        $resultado = ['estados' => array_values(array_intersect($permitidos, $estados)), 'proveedor' => null, 'proveedor_nombre' => 'Todos los proveedores'];
        $proveedor = $filtros['proveedor'] ?? '';
        if ($proveedor !== '') {
            if ((!is_string($proveedor) && !is_int($proveedor)) || !filter_var($proveedor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                throw new \InvalidArgumentException('Selecciona un proveedor válido.');
            }
            $registro = (new ProveedorModel())->find((int)$proveedor);
            if (!$registro) throw new \InvalidArgumentException('El proveedor seleccionado no existe.');
            $resultado['proveedor'] = (int)$proveedor;
            $resultado['proveedor_nombre'] = $registro['razon_social'];
        }
        foreach (['fecha_desde', 'fecha_hasta'] as $campo) {
            $fecha = $filtros[$campo] ?? '';
            if (!is_string($fecha)) throw new \InvalidArgumentException('Las fechas deben usar el formato AAAA-MM-DD.');
            if ($fecha === '') {
                $resultado[$campo] = null;
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)) {
                throw new \InvalidArgumentException('Las fechas deben usar el formato AAAA-MM-DD.');
            }
            $fechaValida = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha || $fecha < '1000-01-01') {
                throw new \InvalidArgumentException('Indica fechas reales con el formato AAAA-MM-DD.');
            }
            $resultado[$campo] = $fecha;
        }
        if ($resultado['fecha_desde'] !== null && $resultado['fecha_hasta'] !== null && $resultado['fecha_desde'] > $resultado['fecha_hasta']) {
            throw new \InvalidArgumentException('La fecha inicial no puede ser posterior a la fecha final.');
        }
        return $resultado;
    }

    public function obtenerComprasPaginado($busqueda, $pagina, $limite, array $filtros = []) {
        if (!is_string($busqueda)) throw new \InvalidArgumentException('La búsqueda debe ser un texto.');
        $filtros = $this->normalizarFiltrosCompras($filtros);
        $pdo = $this->compraModel->conect();
        $transaccionPropia = !$pdo->inTransaction();
        try {
            if ($transaccionPropia) $pdo->beginTransaction();
            $resultado = $this->compraModel->obtenerComprasPaginadas(trim($busqueda), $pagina, $limite, $filtros);
            $resultado['filtros'] = $filtros;
            if ($transaccionPropia) $pdo->commit();
            return $resultado;
        } catch (Throwable $error) {
            if ($transaccionPropia && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Datos comunes para PDF y Excel: importes originales y cantidades de los detalles vigentes (es_vigente = 1).
     */
    public function obtenerReporteCompras(array $filtros, int $id_usuario): array {
        $filtros = $this->normalizarFiltrosCompras($filtros);
        $pdo = $this->compraModel->conect();
        $transaccionPropia = !$pdo->inTransaction();
        try {
            if ($transaccionPropia) $pdo->beginTransaction();
            $usuario = (new UsuarioModel())->find($id_usuario);
            if (!$usuario) throw new \InvalidArgumentException('No se encontró el usuario que genera el reporte.');
            $resumen = $this->compraModel->obtenerResumenCompras('', $filtros);
            if ($resumen['compras_con_diferencias'] > 0) {
                throw new \DomainException('Hay compras cuyo total no coincide con sus detalles o que no tienen detalles. Revisa el historial antes de generar el reporte.');
            }
            $compras = $this->compraModel->obtenerComprasFiltradas('', $filtros);
            $detallesPorCompra = [];
            foreach ($this->compraModel->obtenerDetallesFiltrados($filtros) as $detalle) {
                if ((int)$detalle['cantidad_presentaciones'] <= 0 || $detalle['costo_por_presentacion'] === null) {
                    throw new \DomainException('Hay detalles con cantidades inválidas. Revisa las compras antes de generar el reporte.');
                }
                $detalle['producto'] = $detalle['producto'] ?? 'Producto #' . $detalle['id_producto'];
                $detalle['vencimiento'] = $detalle['fecha_vencimiento'] ?: 'No aplica';
                $detallesPorCompra[$detalle['id_compra']][] = $detalle;
            }
            foreach ($compras as &$compra) {
                $compra['detalles'] = $detallesPorCompra[$compra['id_compra']] ?? [];
                $centimos = array_sum(array_map(fn($detalle) => (int)$detalle['importe_centimos'], $compra['detalles']));
                if (!$compra['detalles'] || $centimos !== (int)$compra['total_centimos']) {
                    throw new \DomainException('El total de una compra no coincide con sus detalles.');
                }
                $compra['total_detalles'] = intdiv($centimos, 100) . '.' . str_pad((string)($centimos % 100), 2, '0', STR_PAD_LEFT);
                $compra['estado_reporte'] = $compra['estado'] === 'Anulada' ? 'Anulada' : 'Vigente';
            }
            unset($compra);
            $resultado = [
                'metadatos' => [
                    'negocio' => 'AMAZON MARKET', 'titulo' => 'COMPRAS POR PERIODO',
                    'fecha_desde' => $filtros['fecha_desde'], 'fecha_hasta' => $filtros['fecha_hasta'],
                    'proveedor' => $filtros['proveedor_nombre'], 'estados' => $filtros['estados'],
                    'usuario' => $usuario['nombre'],
                    'fecha_emision' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Lima')))->format('Y-m-d H:i:s')
                ],
                'filtros' => $filtros, 'resumen' => $resumen, 'compras' => $compras
            ];
            if ($transaccionPropia) $pdo->commit();
            return $resultado;
        } catch (Throwable $error) {
            if ($transaccionPropia && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function obtenerCompra($id_compra) {
        $compra = $this->compraModel->obtenerCompraPorId($id_compra);
        if ($compra) {
            $compra['detalles'] = $this->detalleCompraModel->obtenerDetallesPorCompra($id_compra, true);
            $idsProducto = array_column($compra['detalles'], 'id_producto');
            $stocks = $this->productoModel->obtenerStocksPorIds($idsProducto);
            foreach ($compra['detalles'] as &$detalle) {
                $idProducto = (int)$detalle['id_producto'];
                $detalle['stock_actual'] = $stocks[$idProducto] ?? null;
            }
            unset($detalle);
            $compra['historial'] = $this->compraModel->obtenerHistorialPorCompra($id_compra);
        }
        return $compra;
    }

    private function validarProductosYLotes(array $detalles): void {
        $lineasPorProductoYLote = [];

        foreach ($detalles as $indice => $detalle) {
            $fechaVencimiento = $detalle['fecha_vencimiento'] ?? null;
            if ($fechaVencimiento !== null && $fechaVencimiento !== '') {
                $fechaValidada = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaVencimiento);
                if (!$fechaValidada || $fechaValidada->format('Y-m-d') !== $fechaVencimiento) {
                    throw new Exception('La fecha de vencimiento no es válida en la línea ' . ($indice + 1) . '.');
                }
            }

            $idProducto = filter_var($detalle['id_producto'] ?? null, FILTER_VALIDATE_INT);
            $lote = trim((string)($detalle['numero_lote'] ?? ''));
            if ($idProducto === false || $lote === '') {
                continue;
            }

            $clave = $idProducto . ':' . strtolower($lote);
            $numeroLinea = $indice + 1;
            if (isset($lineasPorProductoYLote[$clave])) {
                throw new Exception(
                    "El producto ID {$idProducto} tiene el lote '{$lote}' repetido en las líneas "
                    . "{$lineasPorProductoYLote[$clave]} y {$numeroLinea}."
                );
            }

            $lineasPorProductoYLote[$clave] = $numeroLinea;
        }
    }
}
