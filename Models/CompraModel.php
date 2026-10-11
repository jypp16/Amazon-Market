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
            'proveedor.numero_documento',
            'tipo_comprobante.nombre as tipo_comprobante'
        ])
        ->join('proveedor', 'compra.id_proveedor = proveedor.id_proveedor')
        ->join('tipo_comprobante', 'compra.id_tipo_comprobante = tipo_comprobante.id_tipo_comprobante');

        if (!empty($busqueda)) {
            $this->orLikeWhere([
                'compra.serie' => $busqueda,
                'compra.numero' => $busqueda,
                'proveedor.razon_social' => $busqueda,
                'proveedor.numero_documento' => $busqueda
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
            'proveedor.numero_documento',
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

    public function anularCompraConMotivo($id_compra, $motivo) {
        return $this->update($id_compra, [
            'estado' => 'Anulada',
            'motivo_anulacion' => $motivo,
            'fecha_anulacion' => date('Y-m-d H:i:s')
        ]);
    }

    public function registrarHistorial($id_compra, $id_usuario, $tipo_accion, $motivo, $datos_anteriores = null) {
        $sql = "INSERT INTO compra_historial (id_compra, id_usuario, tipo_accion, motivo, datos_anteriores, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $this->conect()->prepare($sql);
        return $stmt->execute([
            $id_compra,
            $id_usuario,
            $tipo_accion,
            $motivo,
            $datos_anteriores !== null ? json_encode($datos_anteriores, JSON_UNESCAPED_UNICODE) : null
        ]);
    }

    public function obtenerHistorialPorCompra($id_compra) {
        $sql = "SELECT h.*, u.nombre, u.apellido, u.usuario
                FROM compra_historial h
                JOIN usuario u ON h.id_usuario = u.id_usuario
                WHERE h.id_compra = ?
                ORDER BY h.created_at ASC";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([$id_compra]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

