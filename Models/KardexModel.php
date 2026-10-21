<?php

namespace Models;

use Libraries\Core\Model;

class KardexModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'kardex';
        $this->primaryKey = 'id_kardex';
    }

    /**
     * Registra un movimiento en el kardex
     * CA-02 de HU05
     */
    public function registrarMovimiento(array $datos) {
        // Se asume que $datos trae las claves mapeadas con las columnas de la BD
        return $this->insert($datos);
    }

    public function obtenerMovimientos(array $filtros): array {
        $desde = $this->validarFecha($filtros['desde'] ?? '', 'desde');
        $hasta = $this->validarFecha($filtros['hasta'] ?? date('Y-m-d'), 'hasta');
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            throw new \InvalidArgumentException('La fecha inicial no puede ser posterior a la fecha final.');
        }

        $productoIngresado = $filtros['id_producto'] ?? '';
        $categoriaIngresada = $filtros['id_categoria'] ?? '';
        $idProducto = $productoIngresado === '' ? null : filter_var($productoIngresado, FILTER_VALIDATE_INT);
        $idCategoria = $categoriaIngresada === '' ? null : filter_var($categoriaIngresada, FILTER_VALIDATE_INT);
        if (($productoIngresado !== '' && ($idProducto === false || $idProducto < 1))
            || ($categoriaIngresada !== '' && ($idCategoria === false || $idCategoria < 1))) {
            throw new \InvalidArgumentException('El producto o la categoría no son válidos.');
        }

        $pagina = filter_var($filtros['pagina'] ?? 1, FILTER_VALIDATE_INT);
        if ($pagina === false || $pagina < 1) {
            throw new \InvalidArgumentException('El número de página no es válido.');
        }
        $porPagina = 10;

        $movimientosRegistrados = "
            SELECT
                CONCAT('kardex-', k.id_kardex) AS clave_movimiento,
                k.id_producto,
                k.id_usuario,
                k.tipo_movimiento,
                (k.stock_resultante - k.stock_anterior) AS cantidad_firmada,
                COALESCE(k.fecha_movimiento, compra.fecha_recepcion) AS fecha_movimiento,
                producto.codigo_barra,
                producto.nombre AS producto,
                categoria.nombre AS categoria,
                usuario.nombre AS responsable,
                detalle_compra.numero_lote AS lote,
                CASE
                    WHEN k.id_accion_compra IS NOT NULL AND k.tipo_movimiento = 'Ingreso' THEN 'Compra'
                    WHEN k.id_accion_compra IS NOT NULL AND k.tipo_movimiento = 'Anulacion' THEN 'Anulación de compra'
                    WHEN k.id_accion_compra IS NOT NULL THEN 'Corrección de compra'
                    WHEN k.id_accion_venta IS NOT NULL THEN 'Venta'
                    ELSE 'Ajuste de inventario'
                END AS origen
            FROM kardex k
            INNER JOIN producto ON producto.id_producto = k.id_producto
            LEFT JOIN categoria ON categoria.id_categoria = producto.id_categoria
            LEFT JOIN usuario ON usuario.id_usuario = k.id_usuario
            LEFT JOIN compra ON compra.id_compra = k.id_accion_compra
            LEFT JOIN detalle_compra ON detalle_compra.id_detalle_compra = k.id_detalle_compra
            WHERE
                (k.id_accion_compra IS NOT NULL AND k.tipo_movimiento IN ('Ingreso', 'Correccion', 'Anulacion'))
                OR k.id_accion_venta IS NOT NULL
                OR (k.id_accion_compra IS NULL AND k.id_accion_venta IS NULL AND k.tipo_movimiento = 'Ajuste')
        ";

        $comprasSinKardex = "
            SELECT
                CONCAT('compra-', detalle_compra.id_detalle_compra) AS clave_movimiento,
                detalle_compra.id_producto,
                compra.id_usuario,
                'Ingreso' AS tipo_movimiento,
                detalle_compra.cantidad_base AS cantidad_firmada,
                CONCAT(compra.fecha_recepcion, ' 00:00:00') AS fecha_movimiento,
                producto.codigo_barra,
                producto.nombre AS producto,
                categoria.nombre AS categoria,
                usuario.nombre AS responsable,
                detalle_compra.numero_lote AS lote,
                'Compra' AS origen
            FROM detalle_compra
            INNER JOIN compra ON compra.id_compra = detalle_compra.id_compra
            INNER JOIN producto ON producto.id_producto = detalle_compra.id_producto
            LEFT JOIN categoria ON categoria.id_categoria = producto.id_categoria
            LEFT JOIN usuario ON usuario.id_usuario = compra.id_usuario
            WHERE compra.estado <> 'Anulada'
                AND NOT EXISTS (
                    SELECT 1 FROM kardex
                    WHERE kardex.id_detalle_compra = detalle_compra.id_detalle_compra
                )
        ";

        $ventasSinKardex = "
            SELECT
                CONCAT('venta-', detalle_venta.id_detalle_venta) AS clave_movimiento,
                detalle_venta.id_producto,
                venta.id_usuario,
                'Egreso' AS tipo_movimiento,
                -detalle_venta.cantidad AS cantidad_firmada,
                venta.fecha_venta AS fecha_movimiento,
                producto.codigo_barra,
                producto.nombre AS producto,
                categoria.nombre AS categoria,
                usuario.nombre AS responsable,
                NULL AS lote,
                'Venta' AS origen
            FROM detalle_venta
            INNER JOIN venta ON venta.id_venta = detalle_venta.id_venta
            INNER JOIN producto ON producto.id_producto = detalle_venta.id_producto
            LEFT JOIN categoria ON categoria.id_categoria = producto.id_categoria
            LEFT JOIN usuario ON usuario.id_usuario = venta.id_usuario
            WHERE venta.estado = 1
                AND NOT EXISTS (
                    SELECT 1 FROM kardex
                    WHERE kardex.id_accion_venta = venta.id_venta
                        AND kardex.id_producto = detalle_venta.id_producto
                )
        ";

        $sql = "SELECT movimientos.*, producto.stock_actual
            FROM (($movimientosRegistrados)
                UNION ALL ($comprasSinKardex)
                UNION ALL ($ventasSinKardex)
            ) movimientos
            INNER JOIN producto ON producto.id_producto = movimientos.id_producto
            WHERE 1 = 1";
        $valores = [];

        if ($idProducto !== false && $idProducto !== null) {
            $sql .= ' AND movimientos.id_producto = :id_producto';
            $valores[':id_producto'] = $idProducto;
        }
        if ($idCategoria !== false && $idCategoria !== null) {
            $sql .= ' AND producto.id_categoria = :id_categoria';
            $valores[':id_categoria'] = $idCategoria;
        }
        $sql .= ' ORDER BY movimientos.fecha_movimiento DESC, movimientos.clave_movimiento DESC';

        $consulta = $this->conect()->prepare($sql);
        $consulta->execute($valores);
        $filas = $consulta->fetchAll(\PDO::FETCH_ASSOC);

        $saldosPorProducto = [];
        $resultado = [];
        foreach ($filas as $fila) {
            $id = (int)$fila['id_producto'];
            if (!array_key_exists($id, $saldosPorProducto)) {
                $saldosPorProducto[$id] = (float)$fila['stock_actual'];
            }
            $saldoResultante = $saldosPorProducto[$id];
            $cantidadFirmada = (float)$fila['cantidad_firmada'];
            $saldoAnterior = $saldoResultante - $cantidadFirmada;
            $saldosPorProducto[$id] = $saldoAnterior;

            $fecha = substr((string)$fila['fecha_movimiento'], 0, 10);
            if (($desde !== null && $fecha < $desde) || ($hasta !== null && $fecha > $hasta)) {
                continue;
            }

            $resultado[] = [
                'fecha_movimiento' => $fila['fecha_movimiento'],
                'codigo_barra' => $fila['codigo_barra'],
                'producto' => $fila['producto'],
                'categoria' => $fila['categoria'] ?? 'Sin categoría',
                'origen' => $fila['origen'],
                'sentido' => $cantidadFirmada >= 0 ? 'Entrada' : 'Salida',
                'cantidad' => abs($cantidadFirmada),
                'saldo_anterior' => $saldoAnterior,
                'saldo_resultante' => $saldoResultante,
                'responsable' => $fila['responsable'] ?? 'No disponible',
                'lote' => $fila['lote'] ?? '',
            ];
        }

        $total = count($resultado);
        $totalPaginas = max(1, (int)ceil($total / $porPagina));
        $pagina = min($pagina, $totalPaginas);

        return [
            'movimientos' => array_slice($resultado, ($pagina - 1) * $porPagina, $porPagina),
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ];
    }

    private function validarFecha(string $fecha, string $campo): ?string {
        if ($fecha === '') {
            return null;
        }
        $fechaValidada = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if (!$fechaValidada || $fechaValidada->format('Y-m-d') !== $fecha) {
            throw new \InvalidArgumentException("La fecha '$campo' no es válida.");
        }
        return $fecha;
    }
}
