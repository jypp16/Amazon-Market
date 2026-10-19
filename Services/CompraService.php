<?php

namespace Services;

use Models\CompraModel;
use Models\DetalleCompraModel;
use Models\ProductoModel;
use Models\KardexModel;
use PDOException;
use Exception;

class CompraService {
    private $compraModel;
    private $detalleCompraModel;
    private $productoModel;
    private $kardexModel;

    public function __construct() {
        $this->compraModel = new CompraModel();
        $this->detalleCompraModel = new DetalleCompraModel();
        $this->productoModel = new ProductoModel();
        $this->kardexModel = new KardexModel();
    }

    /**
     * Registra una compra asegurando la atomicidad con una transacción
     */
    public function registrarCompra(array $cabecera, array $detalles, $id_usuario) {
        $pdo = $this->compraModel->conect();
        
        try {
            // Iniciar Transacción
            $pdo->beginTransaction();
            $this->validarProductosYLotes($detalles);

            // 1. Verificar si ya existe el comprobante (Evitar duplicados)
            if ($this->compraModel->existeComprobante(
                $cabecera['id_proveedor'],
                $cabecera['id_tipo_comprobante'],
                $cabecera['serie'],
                $cabecera['numero']
            )) {
                throw new Exception("El comprobante ya se encuentra registrado para este proveedor.");
            }

            // 2. Insertar Cabecera de Compra
            $datosCompra = [
                'id_proveedor' => $cabecera['id_proveedor'],
                'id_usuario' => $id_usuario,
                'id_tipo_comprobante' => $cabecera['id_tipo_comprobante'],
                'serie' => $cabecera['serie'],
                'numero' => $cabecera['numero'],
                'fecha_emision' => $cabecera['fecha_emision'],
                'fecha_recepcion' => $cabecera['fecha_recepcion'] ?? date('Y-m-d'),
                'total_documento' => $cabecera['total_documento'],
                'estado' => 'Confirmada'
            ];

            if (!$this->compraModel->insert($datosCompra)) {
                throw new Exception("Error al registrar la cabecera de la compra.");
            }

            // Obtener el ID de la compra insertada
            $id_compra = $pdo->lastInsertId();

            // 3. Procesar Detalles de Compra
            foreach ($detalles as $detalle) {
                // Calcular cantidad base (unidades)
                $cantidad_presentaciones = $detalle['cantidad_presentaciones'];
                $equivalencia = $detalle['equivalencia_aplicada'];
                $cantidad_base = $cantidad_presentaciones * $equivalencia;

                $datosDetalle = [
                    'id_compra' => $id_compra,
                    'id_producto' => $detalle['id_producto'],
                    'id_presentacion' => $detalle['id_presentacion'],
                    'cantidad_presentaciones' => $cantidad_presentaciones,
                    'equivalencia_aplicada' => $equivalencia,
                    'cantidad_base' => $cantidad_base,
                    'importe_final_linea' => $detalle['importe_final_linea'],
                    'numero_lote' => $detalle['numero_lote'] ?? null,
                    'fecha_vencimiento' => $detalle['fecha_vencimiento'] ?? null
                ];

                if (!$this->detalleCompraModel->insert($datosDetalle)) {
                    throw new Exception("Error al registrar el detalle del producto ID: " . $detalle['id_producto']);
                }
                
                $id_detalle = $pdo->lastInsertId();

                // 4. Actualizar Stock del Producto
                $producto = $this->productoModel->find($detalle['id_producto']);
                if (!$producto) {
                    throw new Exception("Producto no encontrado ID: " . $detalle['id_producto']);
                }

                $stock_anterior = (int) $producto['stock_actual'];
                $stock_resultante = $stock_anterior + $cantidad_base;

                if (!$this->productoModel->actualizarStock($detalle['id_producto'], $cantidad_base, 'sumar')) {
                    throw new Exception("Error al actualizar el stock del producto ID: " . $detalle['id_producto']);
                }

                // 5. Registrar Movimiento en Kardex
                $datosKardex = [
                    'id_producto' => $detalle['id_producto'],
                    'id_usuario' => $id_usuario,
                    'tipo_movimiento' => 'Ingreso',
                    'cantidad' => $cantidad_base,
                    'stock_anterior' => $stock_anterior,
                    'stock_resultante' => $stock_resultante,
                    'id_accion_compra' => $id_compra,
                    'id_detalle_compra' => $id_detalle
                ];

                if (!$this->kardexModel->registrarMovimiento($datosKardex)) {
                    throw new Exception("Error al registrar el movimiento en Kardex para el producto ID: " . $detalle['id_producto']);
                }
            }

            // Confirmar Transacción
            $pdo->commit();
            return ['status' => true, 'message' => 'Compra registrada correctamente.', 'id_compra' => $id_compra];

        } catch (Exception $e) {
            // Revertir cambios si hay error
            $pdo->rollBack();
            return ['status' => false, 'message' => $e->getMessage()];
        }
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
            $registro = (new \Models\ProveedorModel())->find((int)$proveedor);
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
        } catch (\Throwable $error) {
            if ($transaccionPropia && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** Datos comunes para PDF y Excel: importes originales y cantidades de los detalles actuales. */
    public function obtenerReporteCompras(array $filtros, int $id_usuario): array {
        $filtros = $this->normalizarFiltrosCompras($filtros);
        $pdo = $this->compraModel->conect();
        $transaccionPropia = !$pdo->inTransaction();
        try {
            if ($transaccionPropia) $pdo->beginTransaction();
            $usuario = (new \Models\UsuarioModel())->find($id_usuario);
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
        } catch (\Throwable $error) {
            if ($transaccionPropia && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function obtenerCompra($id_compra) {
        $compra = $this->compraModel->obtenerCompraPorId($id_compra);
        if ($compra) {
            $compra['detalles'] = $this->detalleCompraModel->obtenerDetallesPorCompra($id_compra);
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

    /**
     * Anula una compra, restando el stock ingresado y registrando el movimiento de anulación.
     */
    public function anularCompra($id_compra, $id_usuario, $motivo = '') {
        $motivo = trim($motivo ?? '');
        if (empty($motivo)) {
            throw new Exception("El motivo de anulación es obligatorio.");
        }

        $pdo = $this->compraModel->conect();
        
        try {
            $pdo->beginTransaction();

            $compra = $this->compraModel->find($id_compra);
            if (!$compra) {
                throw new Exception("Compra no encontrada.");
            }
            if ($compra['estado'] === 'Anulada') {
                throw new Exception("La compra ya se encuentra anulada.");
            }

            $detalles = $this->detalleCompraModel->obtenerDetallesPorCompra($id_compra);

            foreach ($detalles as $detalle) {
                $producto = $this->productoModel->find($detalle['id_producto']);
                if (!$producto) {
                    throw new Exception("Producto no encontrado ID: " . $detalle['id_producto']);
                }

                $stock_actual = (int) $producto['stock_actual'];
                $cantidad_restar = (int) $detalle['cantidad_base'];

                if ($stock_actual - $cantidad_restar < 0) {
                    throw new Exception("No se puede anular la compra. El producto '" . $producto['nombre'] . "' quedaría con stock negativo.");
                }

                // Restar stock
                if (!$this->productoModel->actualizarStock($detalle['id_producto'], $cantidad_restar, 'restar')) {
                    throw new Exception("Error al restar el stock del producto ID: " . $detalle['id_producto']);
                }

                $stock_resultante = $stock_actual - $cantidad_restar;

                // Registrar en Kardex
                $datosKardex = [
                    'id_producto' => $detalle['id_producto'],
                    'id_usuario' => $id_usuario,
                    'tipo_movimiento' => 'Anulacion',
                    'cantidad' => $cantidad_restar,
                    'stock_anterior' => $stock_actual,
                    'stock_resultante' => $stock_resultante,
                    'id_accion_compra' => $id_compra,
                    'id_detalle_compra' => $detalle['id_detalle_compra']
                ];

                if (!$this->kardexModel->registrarMovimiento($datosKardex)) {
                    throw new Exception("Error al registrar el movimiento de anulación en Kardex.");
                }
            }

            // Cambiar estado a Anulada guardando motivo y fecha
            if (!$this->compraModel->anularCompraConMotivo($id_compra, $motivo)) {
                throw new Exception("Error al cambiar el estado de la compra.");
            }

            // Registrar en historial de auditoría
            $this->compraModel->registrarHistorial($id_compra, $id_usuario, 'Anulacion', $motivo, [
                'estado_anterior' => $compra['estado'],
                'detalles' => $detalles
            ]);

            $pdo->commit();
            return ['status' => true, 'message' => 'Compra anulada correctamente.'];

        } catch (Exception $e) {
            $pdo->rollBack();
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Corrige una compra existente. Internamente anula los detalles anteriores y registra los nuevos.
     */
    public function corregirCompra($id_compra, array $cabecera, array $nuevosDetalles, $id_usuario, $motivo = '') {
        $motivo = trim($motivo ?? '');
        if (empty($motivo)) {
            throw new Exception("El motivo de corrección es obligatorio.");
        }

        $pdo = $this->compraModel->conect();
        
        try {
            $pdo->beginTransaction();
            $this->validarProductosYLotes($nuevosDetalles);

            $compra = $this->compraModel->find($id_compra);
            if (!$compra) {
                throw new Exception("Compra no encontrada.");
            }
            if ($compra['estado'] === 'Anulada') {
                throw new Exception("No se puede corregir una compra anulada.");
            }
            if ($this->compraModel->existeComprobante(
                $cabecera['id_proveedor'],
                $cabecera['id_tipo_comprobante'],
                $cabecera['serie'],
                $cabecera['numero'],
                $id_compra
            )) {
                throw new Exception("El comprobante ya se encuentra registrado para este proveedor.");
            }

            // 1. Revertir el stock de los detalles antiguos (Anulación parcial lógica)
            $detallesAntiguos = $this->detalleCompraModel->obtenerDetallesPorCompra($id_compra);

            $datosAnteriores = [
                'cabecera' => [
                    'id_proveedor' => $compra['id_proveedor'],
                    'id_tipo_comprobante' => $compra['id_tipo_comprobante'],
                    'serie' => $compra['serie'],
                    'numero' => $compra['numero'],
                    'fecha_emision' => $compra['fecha_emision'],
                    'fecha_recepcion' => $compra['fecha_recepcion'],
                    'total_documento' => $compra['total_documento'],
                    'estado' => $compra['estado']
                ],
                'detalles' => $detallesAntiguos
            ];
            
            foreach ($detallesAntiguos as $detalle) {
                $producto = $this->productoModel->find($detalle['id_producto']);
                if (!$producto) {
                    throw new Exception("Producto antiguo no encontrado ID: " . $detalle['id_producto']);
                }

                $stock_actual = (int) $producto['stock_actual'];
                $cantidad_restar = (int) $detalle['cantidad_base'];

                if ($stock_actual - $cantidad_restar < 0) {
                    throw new Exception("No se puede corregir la compra. Revertir el producto '" . $producto['nombre'] . "' dejaría el stock en negativo.");
                }

                if (!$this->productoModel->actualizarStock($detalle['id_producto'], $cantidad_restar, 'restar')) {
                    throw new Exception("Error al revertir stock del producto ID: " . $detalle['id_producto']);
                }

                // Registrar salida por corrección
                $datosKardex = [
                    'id_producto' => $detalle['id_producto'],
                    'id_usuario' => $id_usuario,
                    'tipo_movimiento' => 'Correccion',
                    'cantidad' => $cantidad_restar,
                    'stock_anterior' => $stock_actual,
                    'stock_resultante' => $stock_actual - $cantidad_restar,
                    'id_accion_compra' => $id_compra,
                    'id_detalle_compra' => $detalle['id_detalle_compra']
                ];
                if (!$this->kardexModel->registrarMovimiento($datosKardex)) {
                    throw new Exception("Error al registrar reversión en Kardex.");
                }

                // Eliminar el detalle antiguo (o se podría hacer un soft delete, pero para mantener integridad limpia los borramos)
                if (!$this->detalleCompraModel->delete($detalle['id_detalle_compra'])) {
                    throw new Exception("Error al eliminar el detalle antiguo ID: " . $detalle['id_detalle_compra']);
                }
            }

            // 2. Actualizar la cabecera
            $datosCabeceraUpdate = [
                'id_proveedor' => $cabecera['id_proveedor'],
                'id_tipo_comprobante' => $cabecera['id_tipo_comprobante'],
                'serie' => $cabecera['serie'],
                'numero' => $cabecera['numero'],
                'fecha_emision' => $cabecera['fecha_emision'],
                'fecha_recepcion' => $cabecera['fecha_recepcion'] ?? $compra['fecha_recepcion'],
                'total_documento' => $cabecera['total_documento'],
                'estado' => 'Corregida',
                'motivo_correccion' => $motivo,
                'fecha_correccion' => date('Y-m-d H:i:s')
            ];

            if (!$this->compraModel->update($id_compra, $datosCabeceraUpdate)) {
                throw new Exception("Error al actualizar la cabecera de la compra.");
            }

            // 3. Procesar e insertar los nuevos detalles
            foreach ($nuevosDetalles as $detalle) {
                $cantidad_presentaciones = $detalle['cantidad_presentaciones'];
                $equivalencia = $detalle['equivalencia_aplicada'];
                $cantidad_base = $cantidad_presentaciones * $equivalencia;

                $datosDetalle = [
                    'id_compra' => $id_compra,
                    'id_producto' => $detalle['id_producto'],
                    'id_presentacion' => $detalle['id_presentacion'],
                    'cantidad_presentaciones' => $cantidad_presentaciones,
                    'equivalencia_aplicada' => $equivalencia,
                    'cantidad_base' => $cantidad_base,
                    'importe_final_linea' => $detalle['importe_final_linea'],
                    'numero_lote' => $detalle['numero_lote'] ?? null,
                    'fecha_vencimiento' => $detalle['fecha_vencimiento'] ?? null
                ];

                if (!$this->detalleCompraModel->insert($datosDetalle)) {
                    throw new Exception("Error al registrar el nuevo detalle del producto ID: " . $detalle['id_producto']);
                }
                
                $id_detalle = $pdo->lastInsertId();

                $producto = $this->productoModel->find($detalle['id_producto']);
                if (!$producto) {
                    throw new Exception("Producto nuevo no encontrado ID: " . $detalle['id_producto']);
                }

                $stock_anterior = (int) $producto['stock_actual'];
                $stock_resultante = $stock_anterior + $cantidad_base;

                if (!$this->productoModel->actualizarStock($detalle['id_producto'], $cantidad_base, 'sumar')) {
                    throw new Exception("Error al sumar el stock del nuevo producto ID: " . $detalle['id_producto']);
                }

                // Registrar ingreso por corrección en Kardex
                $datosKardex = [
                    'id_producto' => $detalle['id_producto'],
                    'id_usuario' => $id_usuario,
                    'tipo_movimiento' => 'Correccion',
                    'cantidad' => $cantidad_base,
                    'stock_anterior' => $stock_anterior,
                    'stock_resultante' => $stock_resultante,
                    'id_accion_compra' => $id_compra,
                    'id_detalle_compra' => $id_detalle
                ];

                if (!$this->kardexModel->registrarMovimiento($datosKardex)) {
                    throw new Exception("Error al registrar el nuevo ingreso en Kardex.");
                }
            }

            // 4. Registrar en historial de auditoría
            $this->compraModel->registrarHistorial($id_compra, $id_usuario, 'Correccion', $motivo, $datosAnteriores);

            $pdo->commit();
            return ['status' => true, 'message' => 'Compra corregida correctamente.'];

        } catch (Exception $e) {
            $pdo->rollBack();
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    private function validarProductosYLotes(array $detalles) {
        $lineasPorProductoYLote = [];

        foreach ($detalles as $indice => $detalle) {
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
