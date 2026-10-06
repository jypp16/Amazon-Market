<?php

namespace Models;

use Libraries\Core\Model;

class UnidadMedidaModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'unidad_medida';
        $this->primaryKey = 'id_unidad';
    }

    public function obtenerActivas() {
        return $this->where(['estado = 1'])
            ->orderBy('nombre', 'ASC')
            ->get();
    }
}
