<?php

namespace Models;

use Libraries\Core\Model;

class VentaModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'venta';
        $this->primaryKey = 'id_venta';
    }

    public function obtenerVentaConCliente(int $idVenta, ?int $idUsuario = null): ?array {
        $sql = "SELECT v.*,
                       CASE WHEN v.id_cliente IS NULL THEN 'Consumidor final' ELSE COALESCE(c.nombre, 'Consumidor final') END AS cliente
                  FROM venta v
                  LEFT JOIN cliente c ON v.id_cliente = c.id_cliente
                 WHERE v.id_venta = :id_venta
                   AND v.estado = 1";
        $params = [':id_venta' => $idVenta];
        if ($idUsuario !== null) {
            $sql .= " AND v.id_usuario = :id_usuario";
            $params[':id_usuario'] = $idUsuario;
        }
        $sql .= " LIMIT 1";

        $stmt = $this->conect()->prepare($sql);
        $stmt->execute($params);
        $venta = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$venta) {
            return null;
        }
        $venta['consumidor_final'] = ($venta['id_cliente'] === null);
        return $venta;
    }

    public function listarVentasConCliente(?int $idUsuario = null): array {
        $sql = "SELECT v.*,
                       CASE WHEN v.id_cliente IS NULL THEN 'Consumidor final' ELSE COALESCE(c.nombre, 'Consumidor final') END AS cliente
                  FROM venta v
                  LEFT JOIN cliente c ON v.id_cliente = c.id_cliente
                 WHERE v.estado = 1";
        $params = [];
        if ($idUsuario !== null) {
            $sql .= " AND v.id_usuario = :id_usuario";
            $params[':id_usuario'] = $idUsuario;
        }
        $sql .= " ORDER BY v.id_venta DESC";

        $stmt = $this->conect()->prepare($sql);
        $stmt->execute($params);
        $ventas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($ventas as &$venta) {
            $venta['consumidor_final'] = ($venta['id_cliente'] === null);
        }
        unset($venta);
        return $ventas;
    }
}
