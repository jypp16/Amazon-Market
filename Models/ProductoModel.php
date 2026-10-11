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

    public function obtenerStocksPorIds(array $ids) {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static function ($id) { return $id > 0; }
        )));
        if (!$ids) return [];

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conect()->prepare(
            "SELECT id_producto, stock_actual FROM producto WHERE id_producto IN ($marcadores)"
        );
        $stmt->execute($ids);

        $stocks = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $producto) {
            $stocks[(int)$producto['id_producto']] = (int)$producto['stock_actual'];
        }
        return $stocks;
    }

    public function actualizarStock($id_producto, $cantidad, $operacion = 'sumar') {
        $producto = $this->find($id_producto);
        if (!$producto) {
            return false;
        }

        $nuevoStock = $producto['stock_actual'];
        if ($operacion === 'sumar') {
            $nuevoStock += $cantidad;
        } elseif ($operacion === 'restar') {
            $nuevoStock -= $cantidad;
        }

        return $this->update($id_producto, ['stock_actual' => $nuevoStock]);
    }
}
