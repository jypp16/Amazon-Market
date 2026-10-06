<?php

namespace Models;

use Libraries\Core\Model;

class DetalleVentaModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'detalle_venta';
        $this->primaryKey = 'id_detalle_venta';
    }
}
