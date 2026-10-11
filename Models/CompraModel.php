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

    public function obtenerComprasPaginadas(string $busqueda = '', int $pagina = 1, int $limite = 10, array $filtros = []): array {
        $pagina = max(1, $pagina);
        $limite = max(1, min(100, $limite));
        $offset = ($pagina - 1) * $limite;
        $condiciones = [];
        $valores = [];

        if ($busqueda !== '') {
            $condiciones[] = '(compra.serie LIKE :busqueda_serie OR compra.numero LIKE :busqueda_numero OR proveedor.razon_social LIKE :busqueda_proveedor OR proveedor.numero_documento LIKE :busqueda_documento)';
            $valorBusqueda = '%' . $busqueda . '%';
            $valores[':busqueda_serie'] = $valorBusqueda;
            $valores[':busqueda_numero'] = $valorBusqueda;
            $valores[':busqueda_proveedor'] = $valorBusqueda;
            $valores[':busqueda_documento'] = $valorBusqueda;
        }
        if (!empty($filtros['proveedor'])) {
            $condiciones[] = 'compra.id_proveedor = :proveedor';
            $valores[':proveedor'] = (int)$filtros['proveedor'];
        }
        if (!empty($filtros['fecha_desde'])) {
            $condiciones[] = 'compra.fecha_recepcion >= :fecha_desde';
            $valores[':fecha_desde'] = $filtros['fecha_desde'];
        }
        if (!empty($filtros['fecha_hasta'])) {
            $condiciones[] = 'compra.fecha_recepcion <= :fecha_hasta';
            $valores[':fecha_hasta'] = $filtros['fecha_hasta'];
        }
        if (!empty($filtros['estado'])) {
            $condiciones[] = 'compra.estado = :estado';
            $valores[':estado'] = $filtros['estado'];
        }

        $from = ' FROM compra
            INNER JOIN proveedor ON compra.id_proveedor = proveedor.id_proveedor
            INNER JOIN tipo_documento ON proveedor.id_tipo_documento = tipo_documento.id_tipo_documento
            INNER JOIN tipo_comprobante ON compra.id_tipo_comprobante = tipo_comprobante.id_tipo_comprobante
            INNER JOIN usuario ON compra.id_usuario = usuario.id_usuario';
        $where = $condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '';
        $pdo = $this->conect();

        $countStatement = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
        foreach ($valores as $key => $value) {
            $countStatement->bindValue($key, $value);
        }
        $countStatement->execute();
        $total = (int)$countStatement->fetchColumn();

        $summarySql = 'SELECT
                COALESCE(SUM(CASE WHEN compra.estado <> \'Anulada\' THEN compra.total_documento ELSE 0 END), 0) AS total_validas,
                COALESCE(SUM(CASE WHEN compra.estado <> \'Anulada\' THEN 1 ELSE 0 END), 0) AS compras_validas,
                COALESCE(SUM(CASE WHEN compra.estado = \'Anulada\' THEN 1 ELSE 0 END), 0) AS compras_anuladas
            ' . $from . $where;
        $summaryStatement = $pdo->prepare($summarySql);
        foreach ($valores as $key => $value) {
            $summaryStatement->bindValue($key, $value);
        }
        $summaryStatement->execute();
        $resumen = $summaryStatement->fetch(\PDO::FETCH_ASSOC);

        $unitsSql = 'SELECT COALESCE(SUM(detalle_compra.cantidad_base), 0)
            FROM detalle_compra
            INNER JOIN compra ON compra.id_compra = detalle_compra.id_compra
            INNER JOIN proveedor ON compra.id_proveedor = proveedor.id_proveedor
            INNER JOIN tipo_comprobante ON compra.id_tipo_comprobante = tipo_comprobante.id_tipo_comprobante
            INNER JOIN usuario ON compra.id_usuario = usuario.id_usuario' . $where;
        $unitsStatement = $pdo->prepare($unitsSql);
        foreach ($valores as $key => $value) {
            $unitsStatement->bindValue($key, $value);
        }
        $unitsStatement->execute();
        $resumen['unidades_ingresadas'] = (int)$unitsStatement->fetchColumn();

        $dataSql = 'SELECT compra.*, proveedor.razon_social, proveedor.numero_documento,
                tipo_documento.nombre AS nombre_tipo_documento,
                tipo_comprobante.nombre AS tipo_comprobante, usuario.nombre AS nombre_usuario' .
            $from . $where . ' ORDER BY compra.created_at DESC LIMIT :limite OFFSET :offset';
        $dataStatement = $pdo->prepare($dataSql);
        foreach ($valores as $key => $value) {
            $dataStatement->bindValue($key, $value);
        }
        $dataStatement->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $dataStatement->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $dataStatement->execute();
        $data = $dataStatement->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'total' => $total,
            'paginas' => max(1, (int)ceil($total / $limite)),
            'pagina_actual' => $pagina,
            'por_pagina' => $limite,
            'resumen' => $resumen
        ];
    }

    public function obtenerCompraPorId($id_compra) {
        return $this->select([
            'compra.*',
            'proveedor.razon_social',
            'proveedor.numero_documento',
            'tipo_documento.nombre as nombre_tipo_documento',
            'tipo_comprobante.nombre as tipo_comprobante',
            'usuario.nombre as nombre_usuario'
        ])
        ->join('proveedor', 'compra.id_proveedor = proveedor.id_proveedor')
        ->join('tipo_comprobante', 'compra.id_tipo_comprobante = tipo_comprobante.id_tipo_comprobante')
        ->join('tipo_documento', 'proveedor.id_tipo_documento = tipo_documento.id_tipo_documento')
        ->join('usuario', 'compra.id_usuario = usuario.id_usuario')
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
        $sql = "SELECT h.*, u.nombre, u.username AS usuario
                FROM compra_historial h
                JOIN usuario u ON h.id_usuario = u.id_usuario
                WHERE h.id_compra = ?
                ORDER BY h.created_at ASC";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([$id_compra]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
