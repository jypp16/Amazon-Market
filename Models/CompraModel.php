<?php

namespace Models;

use Libraries\Core\Model;

class CompraModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'compra';
        $this->primaryKey = 'id_compra';
    }

    public function existeComprobante($id_proveedor, $id_tipo_comprobante, $serie, $numero) {
        $result = $this->select(['id_compra'])
                       ->where([
                           'id_proveedor' => $id_proveedor,
                           'id_tipo_comprobante' => $id_tipo_comprobante,
                           'serie' => $serie,
                           'numero' => $numero,
                           'estado' => 'Confirmada'
                       ])
                       ->first();
        return !empty($result);
    }

    public function obtenerComprasPaginadas($busqueda = '', $pagina = 1, $limite = 10) {
        $offset = ($pagina - 1) * $limite;

        $this->select([
            'compra.*',
            'proveedor.razon_social',
            'proveedor.ruc',
            'tipo_comprobante.nombre as tipo_comprobante'
        ])
        ->join('proveedor', 'compra.id_proveedor = proveedor.id_proveedor')
        ->join('tipo_comprobante', 'compra.id_tipo_comprobante = tipo_comprobante.id_tipo_comprobante');

        if (!empty($busqueda)) {
            $this->orLikeWhere([
                'compra.serie' => $busqueda,
                'compra.numero' => $busqueda,
                'proveedor.razon_social' => $busqueda,
                'proveedor.ruc' => $busqueda
            ]);
        }

        $total = $this->countWithQuery();

        $data = $this->orderBy('compra.created_at', 'DESC')
                     ->limit($limite)
                     ->offset($offset)
                     ->get();

        return [
            'data' => $data,
            'total' => $total,
            'paginas' => ceil($total / $limite),
            'pagina_actual' => $pagina,
            'por_pagina' => $limite
        ];
    }

    public function obtenerCompraPorId($id_compra) {
        return $this->select([
            'compra.*',
            'proveedor.razon_social',
            'proveedor.ruc',
            'tipo_comprobante.nombre as tipo_comprobante'
        ])
        ->join('proveedor', 'compra.id_proveedor = proveedor.id_proveedor')
        ->join('tipo_comprobante', 'compra.id_tipo_comprobante = tipo_comprobante.id_tipo_comprobante')
        ->where(['compra.id_compra' => $id_compra])
        ->first();
    }

    public function actualizarEstado($id_compra, $estado) {
        return $this->update($id_compra, ['estado' => $estado]);
    }
}

