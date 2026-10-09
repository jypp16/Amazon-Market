<?php

namespace Models;

use Libraries\Core\Model;

class CompraModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'compra';
        $this->primaryKey = 'id_compra';
    }

    public function existeComprobante($id_proveedor, $id_tipo_comprobante, $serie, $numero) {
        $result = $this->select(['id_compra'])
                       ->where([
                           'id_proveedor' => $id_proveedor,
                           'id_tipo_comprobante' => $id_tipo_comprobante,
                           'serie' => $serie,
                           'numero' => $numero,
                           'estado' => 'Confirmada'
                       ])
                       ->first();
        return !empty($result);
    }
}

