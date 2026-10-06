<?php

namespace Models;

use Libraries\Core\Model;

class CategoriaModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'categoria';
        $this->primaryKey = 'id_categoria';
    }

    public function obtenerActivas() {
        return $this->where(['estado = 1'])
            ->orderBy('nombre', 'ASC')
            ->get();
    }
}
