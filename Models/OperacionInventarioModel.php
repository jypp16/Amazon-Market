<?php

namespace Models;

use Libraries\Core\Model;

class OperacionInventarioModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'operacion_inventario';
        $this->primaryKey = 'id_operacion';
    }
}

