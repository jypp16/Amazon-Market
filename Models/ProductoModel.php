<?php

namespace Models;

use Libraries\Core\Model;

class ProductoModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'producto';
        $this->primaryKey = 'id_producto';
    }

    public function obtenerProductosConCategoria() {
        return $this->select(['producto.*', 'categoria.nombre as categoria', 'unidad_medida.abreviatura as unidad'])
            ->join('categoria', 'producto.id_categoria = categoria.id_categoria', 'INNER')
            ->join('unidad_medida', 'producto.id_unidad = unidad_medida.id_unidad', 'INNER')
            ->where(['producto.estado = 1'])
            ->orderBy('producto.id_producto', 'ASC')
            ->get();
    }
}
