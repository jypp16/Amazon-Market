<?php

namespace Models;

use Libraries\Core\Model;

class KardexModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'kardex';
        $this->primaryKey = 'id_kardex';
    }

    /**
     * Registra un movimiento en el kardex
     * CA-02 de HU05
     */
    public function registrarMovimiento(array $datos) {
        // Se asume que $datos trae las claves mapeadas con las columnas de la BD
        return $this->insert($datos);
    }
}

