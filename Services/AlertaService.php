<?php

namespace Services;

use Models\ProductoModel;
use Models\DetalleCompraModel;
use Libraries\Core\Conexion;

class AlertaService {
    private $productoModel;
    private $detalleCompraModel;

    public function __construct() {
        $this->productoModel = new ProductoModel();
        $this->detalleCompraModel = new DetalleCompraModel();
    }

    public function obtenerAlertasStock() {
        // Traer productos cuyo stock_actual <= stock_minimo
        return $this->productoModel->select(['id_producto', 'codigo_barra', 'nombre', 'stock_actual', 'stock_minimo'])
                                   ->whereRaw('stock_actual <= stock_minimo')
                                   ->where(['estado' => 1])
                                   ->orderBy('stock_actual', 'ASC')
                                   ->get();
    }

    public function obtenerAlertasVencimiento($dias = 15) {
        // Traer lotes de detalle_compra cuya fecha_vencimiento esté entre hoy y dentro de $dias días
        $fechaHoy = date('Y-m-d');
        $fechaLimite = date('Y-m-d', strtotime("+$dias days"));

        return $this->detalleCompraModel->select([
            'detalle_compra.id_detalle_compra',
            'detalle_compra.numero_lote',
            'detalle_compra.fecha_vencimiento',
            'detalle_compra.cantidad_base as stock_lote_inicial',
            'producto.nombre as producto_nombre',
            'producto.codigo_barra'
        ])
        ->join('producto', 'detalle_compra.id_producto = producto.id_producto')
        ->whereNotNull('detalle_compra.fecha_vencimiento')
        ->whereBetween('detalle_compra.fecha_vencimiento', $fechaHoy, $fechaLimite)
        ->orderBy('detalle_compra.fecha_vencimiento', 'ASC')
        ->get();
    }
}

