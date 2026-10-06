<?php

namespace Models;

use Libraries\Core\Model;

class ClienteModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'cliente';
        $this->primaryKey = 'id_cliente';
    }

    public function obtenerClientes() {
        return $this->select(['cliente.*', 'tipo_documento.nombre as tipo_documento'])
            ->join('tipo_documento', 'cliente.id_tipo_documento = tipo_documento.id_tipo_documento', 'INNER')
            ->where(['cliente.estado = 1'])
            ->orderBy('cliente.id_cliente', 'ASC')
            ->get();
    }
}
