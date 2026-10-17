<?php

namespace Models;

use Libraries\Core\Model;

class ProveedorModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'proveedor';
        $this->primaryKey = 'id_proveedor';
    }

    private function consultaProveedores(string $busqueda, ?int $estado): self {
        $query = $this->select(['proveedor.*', 'tipo_documento.nombre as nombre_tipo_documento'])
            ->join('tipo_documento', 'proveedor.id_tipo_documento = tipo_documento.id_tipo_documento', 'INNER');

        if ($busqueda !== '') {
            $query->orLikeWhere([
                'proveedor.razon_social' => $busqueda,
                'proveedor.numero_documento' => $busqueda
            ]);
        }
        if ($estado !== null) {
            $query->where(['proveedor.estado' => $estado]);
        }
        return $query;
    }

    public function selectProveedores(string $busqueda = '', ?int $estado = null) {
        return $this->consultaProveedores($busqueda, $estado)
            ->orderBy('proveedor.id_proveedor', 'DESC')->get();
    }

    public function selectProveedoresPaginado(string $busqueda = '', int $pagina = 1, int $porPagina = 10, ?int $estado = null) {
        $query = $this->consultaProveedores($busqueda, $estado);
        $total = (int) $query->countWithQuery();
        $porPagina = $porPagina > 0 ? $porPagina : 10;
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $paginas);
        $offset = ($pagina - 1) * $porPagina;

        $data = $query->orderBy('proveedor.id_proveedor', 'DESC')
                      ->limit($porPagina)
                      ->offset($offset)
                      ->get();

        return [
            'data' => $data,
            'total' => $total,
            'paginas' => $paginas,
            'pagina_actual' => $pagina,
            'por_pagina' => $porPagina
        ];
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

