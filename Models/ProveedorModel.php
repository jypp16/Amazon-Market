<?php

namespace Models;

use Libraries\Core\Model;

class ProveedorModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'proveedor';
        $this->primaryKey = 'id_proveedor';
    }

    public function selectProveedores(string $busqueda = '') {
        $query = $this->select(['proveedor.*', 'tipo_documento.nombre as nombre_tipo_documento'])
            ->join('tipo_documento', 'proveedor.id_tipo_documento = tipo_documento.id_tipo_documento', 'INNER');

        if (!empty($busqueda)) {
            $query->whereRaw("proveedor.razon_social LIKE :where_busqueda1 OR proveedor.numero_documento LIKE :where_busqueda2", [
                'busqueda1' => "%{$busqueda}%",
                'busqueda2' => "%{$busqueda}%"
            ]);
        }

        return $query->orderBy('proveedor.id_proveedor', 'DESC')->get();
    }

    public function selectProveedor(int $id) {
        return $this->select(['proveedor.*', 'tipo_documento.nombre as nombre_tipo_documento'])
            ->join('tipo_documento', 'proveedor.id_tipo_documento = tipo_documento.id_tipo_documento', 'INNER')
            ->where(['proveedor.id_proveedor' => $id])
            ->first();
    }

    public function selectProveedorPorDocumento(string $numeroDocumento, int $idIgnorar = 0) {
        $query = $this->select(['id_proveedor'])
                      ->where(['numero_documento' => $numeroDocumento]);

        if ($idIgnorar > 0) {
            $query->whereRaw('id_proveedor != :where_id', ['id' => $idIgnorar]);
        }

        return $query->first();
    }
}

