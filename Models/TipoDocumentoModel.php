<?php

namespace Models;

use Libraries\Core\Model;

class TipoDocumentoModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'tipo_documento';
        $this->primaryKey = 'id_tipo_documento';
    }

    public function obtenerActivos() {
        return $this->where(['estado = 1'])
            ->orderBy('id_tipo_documento', 'ASC')
            ->get();
    }
}
