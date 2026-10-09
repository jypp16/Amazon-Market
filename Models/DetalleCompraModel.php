<?php

namespace Models;

use Libraries\Core\Model;

class DetalleCompraModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'detalle_compra';
        $this->primaryKey = 'id_detalle_compra';
    }
}

