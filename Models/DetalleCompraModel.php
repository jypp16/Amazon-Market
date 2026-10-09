<?php

namespace Models;

use Libraries\Core\Model;

class DetalleCompraModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'detalle_compra';
        $this->primaryKey = 'id_detalle_compra';
    }

    public function obtenerDetallesPorCompra($id_compra) {
        return $this->select([
            'detalle_compra.*',
            'producto.nombre as producto',
            'producto.codigo_barra',
            'presentacion.nombre as presentacion'
        ])
        ->join('producto', 'detalle_compra.id_producto = producto.id_producto')
        ->leftJoin('presentacion', 'detalle_compra.id_presentacion = presentacion.id_presentacion')
        ->where(['detalle_compra.id_compra' => $id_compra])
        ->get();
    }
}

