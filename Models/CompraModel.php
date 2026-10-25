<?php

namespace Models;

use Libraries\Core\Model;

class CompraModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'compra';
        $this->primaryKey = 'id_compra';
    }

    public function existeComprobante($id_proveedor, $id_tipo_comprobante, $serie, $numero, $id_compra_excluida = null) {
        $consulta = $this->select(['id_compra'])
                         ->where([
                             'id_proveedor' => $id_proveedor,
                             'id_tipo_comprobante' => $id_tipo_comprobante,
                             'serie' => $serie,
                             'numero' => $numero
                         ])
                         ->whereIn('estado', ['Confirmada', 'Corregida']);
        if ($id_compra_excluida !== null) {
            $consulta->whereNotIn('id_compra', [$id_compra_excluida]);
        }
        return !empty($consulta->first());
    }

    private function construirConsultaCompras(string $busqueda, array $filtros): array {
        $condiciones = [];
        $valores = [];
        if ($busqueda !== '') {
            $condiciones[] = '(compra.serie LIKE :busqueda_serie OR compra.numero LIKE :busqueda_numero OR proveedor.razon_social LIKE :busqueda_proveedor OR proveedor.numero_documento LIKE :busqueda_documento)';
            foreach (['serie', 'numero', 'proveedor', 'documento'] as $campo) {
                $valores[':busqueda_' . $campo] = '%' . $busqueda . '%';
            }
        }
        foreach (['proveedor' => 'compra.id_proveedor =', 'fecha_desde' => 'compra.fecha_recepcion >=', 'fecha_hasta' => 'compra.fecha_recepcion <='] as $filtro => $comparacion) {
            if ($filtros[$filtro] !== null) {
                $condiciones[] = $comparacion . ' :' . $filtro;
                $valores[':' . $filtro] = $filtros[$filtro];
            }
        }
        $parametrosEstados = [];
        foreach ($filtros['estados'] as $indice => $estado) {
            $parametro = ':estado_' . $indice;
            $parametrosEstados[] = $parametro;
            $valores[$parametro] = $estado;
        }
        $condiciones[] = 'compra.estado IN (' . implode(', ', $parametrosEstados) . ')';
        $desde = ' FROM compra
            INNER JOIN proveedor ON compra.id_proveedor = proveedor.id_proveedor
            INNER JOIN tipo_documento ON proveedor.id_tipo_documento = tipo_documento.id_tipo_documento
            INNER JOIN tipo_comprobante ON compra.id_tipo_comprobante = tipo_comprobante.id_tipo_comprobante
            INNER JOIN usuario ON compra.id_usuario = usuario.id_usuario';
        return [$desde, ' WHERE ' . implode(' AND ', $condiciones), $valores];
    }

    public function obtenerResumenCompras(string $busqueda, array $filtros): array {
        [$desde, $donde, $valores] = $this->construirConsultaCompras($busqueda, $filtros);
        $sql = "SELECT COUNT(*) AS total_registros,
            COALESCE(SUM(CASE WHEN compra.estado <> 'Anulada' THEN 1 ELSE 0 END), 0) AS compras_validas,
            COALESCE(SUM(CASE WHEN compra.estado = 'Anulada' THEN 1 ELSE 0 END), 0) AS compras_anuladas,
            COALESCE(SUM(CASE WHEN compra.estado <> 'Anulada' THEN compra.total_documento ELSE 0 END), 0) AS total_validas,
            COALESCE(SUM(CASE WHEN compra.estado = 'Anulada' THEN compra.total_documento ELSE 0 END), 0) AS total_anuladas,
            COALESCE(SUM(CASE WHEN compra.estado <> 'Anulada' THEN resumen_detalle.unidades ELSE 0 END), 0) AS unidades_ingresadas,
            COALESCE(SUM(CASE WHEN resumen_detalle.lineas IS NULL OR resumen_detalle.importe <> compra.total_documento THEN 1 ELSE 0 END), 0) AS compras_con_diferencias"
            . $desde . ' LEFT JOIN (
                SELECT id_compra, COUNT(*) AS lineas, SUM(importe_final_linea) AS importe, SUM(cantidad_base) AS unidades
                FROM detalle_compra
                WHERE es_vigente = 1
                GROUP BY id_compra
            ) resumen_detalle ON resumen_detalle.id_compra = compra.id_compra' . $donde;
        $consulta = $this->conect()->prepare($sql);
        $consulta->execute($valores);
        $resumen = $consulta->fetch(\PDO::FETCH_ASSOC);
        foreach (['total_registros', 'compras_validas', 'compras_anuladas', 'unidades_ingresadas', 'compras_con_diferencias'] as $campo) {
            $resumen[$campo] = (int)$resumen[$campo];
        }
        $consulta = $this->conect()->prepare('SELECT detalle_compra.id_producto, producto.nombre AS producto,
                SUM(detalle_compra.cantidad_base) AS cantidad_base' . $desde . '
            INNER JOIN detalle_compra ON detalle_compra.id_compra = compra.id_compra AND detalle_compra.es_vigente = 1
            LEFT JOIN producto ON producto.id_producto = detalle_compra.id_producto'
            . $donde . " AND compra.estado <> 'Anulada'
            GROUP BY detalle_compra.id_producto, producto.nombre
            HAVING SUM(detalle_compra.cantidad_base) > 0
            ORDER BY cantidad_base DESC, detalle_compra.id_producto ASC");
        $consulta->execute($valores);
        $productos = [];
        $maximo = 0;
        while ($producto = $consulta->fetch(\PDO::FETCH_ASSOC)) {
            $cantidad = (int)$producto['cantidad_base'];
            if ($productos && $cantidad !== $maximo) break;
            $maximo = $cantidad;
            $productos[] = ['id_producto' => (int)$producto['id_producto'], 'producto' => $producto['producto'] ?? 'Producto #' . $producto['id_producto'], 'cantidad_base' => $cantidad];
        }
        $resumen['productos_mas_comprados'] = $productos;
        $resumen['producto_mas_comprado'] = $productos ? implode(', ', array_column($productos, 'producto')) : 'No aplica';
        $resumen['cantidad_mas_comprada'] = $maximo;
        return $resumen;
    }

    public function obtenerComprasFiltradas(string $busqueda, array $filtros, ?int $limite = null, int $pagina = 1): array {
        [$desde, $donde, $valores] = $this->construirConsultaCompras($busqueda, $filtros);
        $sql = 'SELECT compra.*, proveedor.razon_social, proveedor.numero_documento,
                tipo_documento.nombre AS nombre_tipo_documento,
                tipo_comprobante.nombre AS tipo_comprobante, usuario.nombre AS nombre_usuario,
                CAST(compra.total_documento * 100 AS SIGNED) AS total_centimos'
            . $desde . $donde . ' ORDER BY compra.created_at DESC, compra.id_compra DESC';
        if ($limite !== null) $sql .= ' LIMIT :limite OFFSET :offset';
        $consulta = $this->conect()->prepare($sql);
        foreach ($valores as $clave => $valor) $consulta->bindValue($clave, $valor);
        if ($limite !== null) {
            $consulta->bindValue(':limite', $limite, \PDO::PARAM_INT);
            $consulta->bindValue(':offset', (max(1, $pagina) - 1) * $limite, \PDO::PARAM_INT);
        }
        $consulta->execute();
        return $consulta->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function obtenerDetallesFiltrados(array $filtros): array {
        [$desde, $donde, $valores] = $this->construirConsultaCompras('', $filtros);
        $consulta = $this->conect()->prepare('SELECT detalle_compra.*, producto.nombre AS producto,
                presentacion.nombre AS presentacion,
                CAST(detalle_compra.importe_final_linea * 100 AS SIGNED) AS importe_centimos,
                CAST(detalle_compra.importe_final_linea / NULLIF(detalle_compra.cantidad_presentaciones, 0) AS DECIMAL(20,8)) AS costo_por_presentacion'
            . $desde . '
            INNER JOIN detalle_compra ON detalle_compra.id_compra = compra.id_compra AND detalle_compra.es_vigente = 1
            LEFT JOIN producto ON producto.id_producto = detalle_compra.id_producto
            LEFT JOIN presentacion ON presentacion.id_presentacion = detalle_compra.id_presentacion'
            . $donde . ' ORDER BY detalle_compra.id_compra, detalle_compra.id_detalle_compra');
        $consulta->execute($valores);
        return $consulta->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function obtenerComprasPaginadas(string $busqueda = '', int $pagina = 1, int $limite = 10, array $filtros = []): array {
        $pagina = max(1, $pagina);
        $limite = max(1, min(100, $limite));
        $resumen = $this->obtenerResumenCompras($busqueda, $filtros);
        return [
            'data' => $this->obtenerComprasFiltradas($busqueda, $filtros, $limite, $pagina),
            'total' => $resumen['total_registros'],
            'paginas' => max(1, (int)ceil($resumen['total_registros'] / $limite)),
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

    public function corregirCompraConMotivo($id_compra, $motivo, array $datosCabecera = []) {
        $actualizaciones = array_merge($datosCabecera, [
            'estado' => 'Corregida',
            'motivo_correccion' => $motivo,
            'fecha_correccion' => date('Y-m-d H:i:s')
        ]);
        return $this->update($id_compra, $actualizaciones);
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
