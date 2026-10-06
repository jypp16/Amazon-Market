<?php

namespace Models;

use Libraries\Core\Model;

class VentaModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'venta';
        $this->primaryKey = 'id_venta';
    }
}
