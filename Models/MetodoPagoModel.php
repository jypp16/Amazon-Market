<?php

namespace Models;

use Libraries\Core\Model;

class MetodoPagoModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'metodo_pago';
        $this->primaryKey = 'id_metodo_pago';
    }

    public function obtenerActivos() {
        return $this->where(['estado = 1'])
            ->orderBy('nombre', 'ASC')
            ->get();
    }
}
