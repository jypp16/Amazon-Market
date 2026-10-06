<?php

namespace Models;

use Libraries\Core\Model;

class ReporteModel extends Model {

    private $ventaModel;
    private $detalleVentaModel;
    private $productoModel;

    public function __construct() {
        parent::__construct();
        $this->table = 'venta';
        $this->primaryKey = 'id_venta';
        $this->ventaModel = new VentaModel();
        $this->detalleVentaModel = new DetalleVentaModel();
        $this->productoModel = new ProductoModel();
    }

    public function ventasPorPeriodo(array $filtros): array {
        return $this->ventaModel->as('v')->select([
                'v.id_venta', 'v.fecha_venta', 'v.serie', 'v.numero', 'v.total',
                'c.nombre AS cliente', 'tc.nombre AS comprobante',
                'u.nombre AS vendedor', 'mp.nombre AS metodo_pago'
            ])
            ->join('cliente c', 'v.id_cliente = c.id_cliente')
            ->join('tipo_comprobante tc', 'v.id_tipo_comprobante = tc.id_tipo_comprobante')
            ->join('usuario u', 'v.id_usuario = u.id_usuario')
            ->join('metodo_pago mp', 'v.id_metodo_pago = mp.id_metodo_pago')
            ->where(['v.estado' => 1, 'c.estado' => 1, 'u.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $filtros['desde'], $filtros['hasta'])
            ->when(!empty($filtros['id_tipo_comprobante']), function($q) use ($filtros) {
                return $q->where(['v.id_tipo_comprobante' => intval($filtros['id_tipo_comprobante'])]);
            })
            ->when(!empty($filtros['id_metodo_pago']), function($q) use ($filtros) {
                return $q->where(['v.id_metodo_pago' => intval($filtros['id_metodo_pago'])]);
            })
            ->when(!empty($filtros['id_usuario']), function($q) use ($filtros) {
                return $q->where(['v.id_usuario' => intval($filtros['id_usuario'])]);
            })
            ->when(!empty($filtros['id_cliente']), function($q) use ($filtros) {
                return $q->where(['v.id_cliente' => intval($filtros['id_cliente'])]);
            })
            ->orderBy('v.fecha_venta', 'DESC')
            ->get();
    }

    public function resumenVentasPeriodo(string $desde, string $hasta): array {
        return $this->ventaModel->as('v')->selectRaw(
                'COUNT(*) AS total_ventas, COALESCE(SUM(v.total), 0) AS total_ingresos, COALESCE(AVG(v.total), 0) AS ticket_promedio'
            )
            ->where(['v.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $desde, $hasta)
            ->first() ?? ['total_ventas' => 0, 'total_ingresos' => 0, 'ticket_promedio' => 0];
    }

    public function ventasPorDia(string $desde, string $hasta): array {
        return $this->ventaModel->as('v')->selectRaw(
                'DATE(v.fecha_venta) AS dia, COUNT(*) AS cantidad, COALESCE(SUM(v.total), 0) AS ingresos'
            )
            ->where(['v.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $desde, $hasta)
            ->groupByRaw('DATE(v.fecha_venta)')
            ->orderBy('dia', 'ASC')
            ->get();
    }

    public function comprobantesPorTipo(string $desde, string $hasta): array {
        return $this->ventaModel->as('v')->selectRaw(
                'tc.nombre AS comprobante, COUNT(*) AS cantidad, COALESCE(SUM(v.total), 0) AS total'
            )
            ->join('tipo_comprobante tc', 'v.id_tipo_comprobante = tc.id_tipo_comprobante')
            ->where(['v.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $desde, $hasta)
            ->groupBy('v.id_tipo_comprobante', 'tc.nombre')
            ->get();
    }

    public function productosMasVendidos(array $filtros): array {
        return $this->detalleVentaModel->as('dv')->select([
                'p.id_producto', 'p.nombre', 'cat.nombre AS categoria',
            ])
            ->selectRaw('SUM(dv.cantidad) AS unidades_vendidas, SUM(dv.subtotal) AS ingresos')
            ->join('venta v', 'dv.id_venta = v.id_venta')
            ->join('producto p', 'dv.id_producto = p.id_producto')
            ->join('categoria cat', 'p.id_categoria = cat.id_categoria')
            ->where(['v.estado' => 1, 'p.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $filtros['desde'], $filtros['hasta'])
            ->when(!empty($filtros['id_categoria']), function($q) use ($filtros) {
                return $q->where(['p.id_categoria' => intval($filtros['id_categoria'])]);
            })
            ->groupBy('p.id_producto', 'p.nombre', 'cat.nombre')
            ->orderBy('ingresos', 'DESC')
            ->limit(intval($filtros['topN'] ?? 10))
            ->get();
    }

    public function productosMenosVendidos(array $filtros): array {
        return $this->productoModel->as('p')->select([
                'p.id_producto', 'p.nombre', 'cat.nombre AS categoria',
                'p.stock_actual', 'p.stock_minimo', 'p.precio_venta',
            ])
            ->selectRaw('COALESCE(SUM(dv.cantidad), 0) AS unidades_vendidas, COALESCE(SUM(dv.subtotal), 0) AS ingresos')
            ->join('categoria cat', 'p.id_categoria = cat.id_categoria')
            ->leftJoin('detalle_venta dv', 'p.id_producto = dv.id_producto')
            ->leftJoin('venta v', 'dv.id_venta = v.id_venta AND v.estado = 1 AND DATE(v.fecha_venta) BETWEEN :desde AND :hasta', ['desde' => $filtros['desde'], 'hasta' => $filtros['hasta']])
            ->where(['p.estado' => 1])
            ->when(!empty($filtros['id_categoria']), function($q) use ($filtros) {
                return $q->where(['p.id_categoria' => intval($filtros['id_categoria'])]);
            })
            ->groupBy('p.id_producto', 'p.nombre', 'cat.nombre', 'p.stock_actual', 'p.stock_minimo', 'p.precio_venta')
            ->havingRaw('unidades_vendidas <= :umbral', ['umbral' => intval($filtros['umbral'] ?? 5)])
            ->orderBy('unidades_vendidas', 'ASC')
            ->orderBy('p.nombre', 'ASC')
            ->limit(100)
            ->get();
    }

    public function inventario(): array {
        return $this->productoModel->as('p')->select([
                'p.id_producto', 'p.codigo_barra', 'p.nombre', 'cat.nombre AS categoria',
                'p.stock_actual', 'p.stock_minimo', 'p.precio_venta',
            ])
            ->selectRaw('(p.stock_actual * p.precio_venta) AS valor_stock, CASE WHEN p.stock_actual <= p.stock_minimo THEN 1 ELSE 0 END AS stock_bajo')
            ->join('categoria cat', 'p.id_categoria = cat.id_categoria')
            ->where(['p.estado' => 1])
            ->orderBy('stock_bajo', 'DESC')
            ->orderBy('p.nombre', 'ASC')
            ->get();
    }

    public function resumenInventario(): array {
        return $this->productoModel->selectRaw('
                COUNT(*) AS total_skus,
                COALESCE(SUM(stock_actual * precio_venta), 0) AS valor_total,
                SUM(CASE WHEN stock_actual <= stock_minimo THEN 1 ELSE 0 END) AS productos_stock_bajo,
                COALESCE(SUM(CASE WHEN stock_actual <= stock_minimo THEN stock_actual * precio_venta ELSE 0 END), 0) AS valor_stock_riesgo
            ')
            ->where(['estado' => 1])
            ->first() ?? ['total_skus' => 0, 'valor_total' => 0, 'productos_stock_bajo' => 0, 'valor_stock_riesgo' => 0];
    }

    public function topClientes(array $filtros): array {
        return $this->ventaModel->table('cliente c')->selectRaw('
                c.id_cliente, c.nombre, td.nombre AS tipo_doc, c.nro_documento,
                COUNT(v.id_venta) AS num_compras,
                COALESCE(SUM(v.total), 0) AS total_gastado,
                COALESCE(AVG(v.total), 0) AS ticket_promedio,
                MAX(v.fecha_venta) AS ultima_compra
            ')
            ->join('tipo_documento td', 'c.id_tipo_documento = td.id_tipo_documento')
            ->join('venta v', 'c.id_cliente = v.id_cliente AND v.estado = 1')
            ->where(['c.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $filtros['desde'], $filtros['hasta'])
            ->groupBy('c.id_cliente', 'c.nombre', 'td.nombre', 'c.nro_documento')
            ->orderBy('total_gastado', 'DESC')
            ->limit(intval($filtros['topN'] ?? 20))
            ->get();
    }

    public function clientesNuevosVsRecurrentes(string $desde, string $hasta): array {
        return $this->ventaModel->table('cliente c')->selectRaw("
                CASE WHEN pc.primer_fecha BETWEEN :desde_gc AND :hasta_gc THEN 'Nuevos' ELSE 'Recurrentes' END AS tipo,
                COUNT(DISTINCT c.id_cliente) AS cantidad
            ", ['desde_gc' => $desde, 'hasta_gc' => $hasta])
            ->join('venta v', 'c.id_cliente = v.id_cliente AND v.estado = 1')
            ->join("(SELECT id_cliente, MIN(fecha_venta) AS primer_fecha FROM venta WHERE estado = 1 GROUP BY id_cliente) pc", 'c.id_cliente = pc.id_cliente', 'LEFT')
            ->where(['c.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $desde, $hasta)
            ->groupByRaw('tipo')
            ->orderBy('cantidad', 'DESC')
            ->get();
    }

    public function ventasPorVendedor(array $filtros): array {
        return $this->ventaModel->as('v')->select([
                'u.id_usuario', 'u.nombre AS vendedor',
            ])
            ->selectRaw('COUNT(v.id_venta) AS num_ventas, COALESCE(SUM(v.total), 0) AS ingreso_total, COALESCE(AVG(v.total), 0) AS ticket_promedio')
            ->join('usuario u', 'v.id_usuario = u.id_usuario')
            ->where(['v.estado' => 1, 'u.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $filtros['desde'], $filtros['hasta'])
            ->groupBy('u.id_usuario', 'u.nombre')
            ->orderBy('ingreso_total', 'DESC')
            ->get();
    }

    public function ventasPorCategoria(array $filtros): array {
        return $this->detalleVentaModel->as('dv')->select([
                'cat.id_categoria', 'cat.nombre AS categoria',
            ])
            ->selectRaw('SUM(dv.cantidad) AS unidades, SUM(dv.subtotal) AS ingresos')
            ->join('venta v', 'dv.id_venta = v.id_venta')
            ->join('producto p', 'dv.id_producto = p.id_producto')
            ->join('categoria cat', 'p.id_categoria = cat.id_categoria')
            ->where(['v.estado' => 1, 'p.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $filtros['desde'], $filtros['hasta'])
            ->groupBy('cat.id_categoria', 'cat.nombre')
            ->orderBy('ingresos', 'DESC')
            ->get();
    }

    public function comprobantesDetallado(array $filtros): array {
        return $this->ventaModel->as('v')->selectRaw(
                'tc.nombre AS comprobante, COUNT(*) AS cantidad, COALESCE(SUM(v.total), 0) AS total, v.serie'
            )
            ->join('tipo_comprobante tc', 'v.id_tipo_comprobante = tc.id_tipo_comprobante')
            ->where(['v.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $filtros['desde'], $filtros['hasta'])
            ->when(!empty($filtros['id_tipo_comprobante']), function($q) use ($filtros) {
                return $q->where(['v.id_tipo_comprobante' => intval($filtros['id_tipo_comprobante'])]);
            })
            ->groupBy('v.id_tipo_comprobante', 'tc.nombre', 'v.serie')
            ->orderBy('tc.nombre', 'ASC')
            ->orderBy('v.serie', 'ASC')
            ->get();
    }

    public function metodosDePago(array $filtros): array {
        return $this->ventaModel->as('v')->select([
                'mp.nombre AS metodo_pago',
            ])
            ->selectRaw('COUNT(v.id_venta) AS num_transacciones, COALESCE(SUM(v.total), 0) AS monto_total')
            ->join('metodo_pago mp', 'v.id_metodo_pago = mp.id_metodo_pago')
            ->where(['v.estado' => 1, 'mp.estado' => 1])
            ->whereBetween('DATE(v.fecha_venta)', $filtros['desde'], $filtros['hasta'])
            ->groupBy('mp.id_metodo_pago', 'mp.nombre')
            ->orderBy('monto_total', 'DESC')
            ->get();
    }

    public function obtenerActivos(string $tabla, string $pk, string $campoNombre): array {
        $allowed = ['categoria', 'tipo_comprobante', 'metodo_pago', 'unidad_medida', 'usuario', 'cliente'];
        if (!in_array($tabla, $allowed)) {
            return [];
        }
        $safePk = preg_replace('/[^a-zA-Z0-9_]/', '', $pk);
        $safeCampo = preg_replace('/[^a-zA-Z0-9_]/', '', $campoNombre);
        $sql = "SELECT `$safePk`, `$safeCampo` FROM `$tabla` WHERE `estado` = 1 ORDER BY `$safeCampo` ASC";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
