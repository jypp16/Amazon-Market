<?php

namespace Models;

use Libraries\Core\Model;

class TipoComprobanteModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'tipo_comprobante';
        $this->primaryKey = 'id_tipo_comprobante';
    }

    public function obtenerActivos() {
        return $this->where(['estado = 1'])
            ->orderBy('nombre', 'ASC')
            ->get();
    }
}
