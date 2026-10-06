<?php

namespace Models;

use Libraries\Core\Model;

class RolModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'rol';
        $this->primaryKey = 'id_rol';
    }

    public function obtenerActivos() {
        return $this->where(['estado = 1'])
            ->orderBy('id_rol', 'ASC')
            ->get();
    }
}
