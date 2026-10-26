<?php

namespace Models;

use Libraries\Core\Model;

class DetalleCompraModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'detalle_compra';
        $this->primaryKey = 'id_detalle_compra';
    }

    public function obtenerDetallesPorCompra($id_compra, bool $soloVigentes = true) {
        $query = $this->select([
            'detalle_compra.*',
            'producto.nombre as producto',
            'producto.codigo_barra',
            'presentacion.nombre as presentacion'
        ])
        ->join('producto', 'detalle_compra.id_producto = producto.id_producto')
        ->leftJoin('presentacion', 'detalle_compra.id_presentacion = presentacion.id_presentacion')
        ->where(['detalle_compra.id_compra' => $id_compra]);

        if ($soloVigentes) {
            $query->where(['detalle_compra.es_vigente' => 1]);
        }

        return $query->get();
    }

    public function obtenerMaxVersion($id_compra): int {
        $sql = "SELECT COALESCE(MAX(version_detalle), 0) AS max_v FROM detalle_compra WHERE id_compra = ?";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([(int)$id_compra]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['max_v'] ?? 0);
    }
}
