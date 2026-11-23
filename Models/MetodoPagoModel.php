<?php

namespace Models;

use Libraries\Core\Model;

class MetodoPagoModel extends Model {
    /**
     * Métodos de pago permitidos en el POS (HU-26):
     * 1 = Efectivo, 2 = Tarjeta, 3 = Yape / Plin.
     */
    public const IDS_PERMITIDOS_POS = [1, 2, 3];

    public function __construct() {
        parent::__construct();
        $this->table = 'metodo_pago';
        $this->primaryKey = 'id_metodo_pago';
    }

    public function obtenerActivos() {
        $ids = implode(',', self::IDS_PERMITIDOS_POS);
        return $this->where(['estado = 1'])
            ->whereRaw("id_metodo_pago IN ({$ids})")
            ->orderBy('id_metodo_pago', 'ASC')
            ->get();
    }
}
