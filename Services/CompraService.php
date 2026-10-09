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
}

