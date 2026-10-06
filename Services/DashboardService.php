<?php

namespace Services;

use Models\ProductoModel;
use Models\ClienteModel;
use Models\VentaModel;
use Exception;

class DashboardService {

    private $productoModel;
    private $clienteModel;
    private $ventaModel;

    public function __construct() {
        $this->productoModel = new ProductoModel();
        $this->clienteModel = new ClienteModel();
        $this->ventaModel = new VentaModel();
    }

    public function obtenerEstadisticas(int $rol, ?int $idUsuario = null): array {
        $esAdmin = ($rol === 1);
        $hoy = date('Y-m-d');
        $inicioMes = date('Y-m-01');

        $results = [
            'es_admin' => $esAdmin,
            'productos' => 0,
            'clientes' => 0,
            'ventas' => 0,
            'ingresos' => 0,
            'ventas_hoy' => 0,
            'ingresos_hoy' => 0,
            'ventas_mes' => 0,
            'ingresos_mes' => 0,
            'ticket_promedio' => 0,
            'productos_bajo_stock' => 0,
            'ventas_recientes' => [],
            'productos_top' => [],
            'ingresos_mensuales' => [],
            'horas_pico' => [],
            'mis_ventas_hoy' => 0,
            'mis_ingresos_hoy' => 0,
            'mis_ventas_mes' => 0,
            'mis_ingresos_mes' => 0,
        ];

        if ($esAdmin) {
            $results = $this->cargarDatosAdmin($results, $hoy, $inicioMes);
        } else {
            $results = $this->cargarDatosVendedor($results, $hoy, $inicioMes, $idUsuario);
        }

        return $results;
    }

    private function cargarDatosAdmin(array $results, string $hoy, string $inicioMes): array {
        try { $results['productos'] = $this->productoModel->where(['estado' => 1])->count(); } catch (Exception $e) { error_log('Dash admin productos: ' . $e->getMessage()); }
        try { $results['clientes'] = $this->clienteModel->where(['estado' => 1])->count(); } catch (Exception $e) { error_log('Dash admin clientes: ' . $e->getMessage()); }
        try { $results['ventas'] = $this->ventaModel->where(['estado' => 1])->count(); } catch (Exception $e) { error_log('Dash admin ventas: ' . $e->getMessage()); }
        try { $results['ingresos'] = $this->ventaModel->where(['estado' => 1])->sum('total'); } catch (Exception $e) { error_log('Dash admin ingresos: ' . $e->getMessage()); }
        try { $results['ventas_hoy'] = $this->ventaModel->where(['estado' => 1])->whereRaw('DATE(`fecha_venta`) = :where_fh', ['fh' => $hoy])->count(); } catch (Exception $e) { error_log('Dash admin ventas_hoy: ' . $e->getMessage()); }
        try { $results['ingresos_hoy'] = $this->ventaModel->where(['estado' => 1])->whereRaw('DATE(`fecha_venta`) = :where_fh', ['fh' => $hoy])->sum('total'); } catch (Exception $e) { error_log('Dash admin ingresos_hoy: ' . $e->getMessage()); }
        try { $results['ventas_mes'] = $this->ventaModel->where(['estado' => 1])->whereRaw('`fecha_venta` >= :where_im', ['im' => $inicioMes])->count(); } catch (Exception $e) { error_log('Dash admin ventas_mes: ' . $e->getMessage()); }
        try { $results['ingresos_mes'] = $this->ventaModel->where(['estado' => 1])->whereRaw('`fecha_venta` >= :where_im', ['im' => $inicioMes])->sum('total'); } catch (Exception $e) { error_log('Dash admin ingresos_mes: ' . $e->getMessage()); }
        try { $results['ticket_promedio'] = $this->ventaModel->where(['estado' => 1])->whereRaw('`fecha_venta` >= :where_im', ['im' => $inicioMes])->avg('total'); } catch (Exception $e) { error_log('Dash admin ticket: ' . $e->getMessage()); }
        try { $results['productos_bajo_stock'] = $this->productoModel->where(['estado' => 1])->whereRaw('`stock_actual` <= `stock_minimo`')->count(); } catch (Exception $e) { error_log('Dash admin bajo_stock: ' . $e->getMessage()); }
        try { $results['ventas_recientes'] = $this->obtenerVentasRecientes(5); } catch (Exception $e) { error_log('Dash admin recientes: ' . $e->getMessage()); }
        try { $results['productos_top'] = $this->obtenerProductosTop(5); } catch (Exception $e) { error_log('Dash admin top: ' . $e->getMessage()); }
        try { $results['ingresos_mensuales'] = $this->obtenerIngresosMensuales(6); } catch (Exception $e) { error_log('Dash admin mensual: ' . $e->getMessage()); }
        try { $results['horas_pico'] = $this->obtenerHorasPico(); } catch (Exception $e) { error_log('Dash admin horas: ' . $e->getMessage()); }

        return $results;
    }

    private function cargarDatosVendedor(array $results, string $hoy, string $inicioMes, ?int $idUsuario): array {
        try { $results['ventas'] = $this->ventaModel->where(['estado' => 1])->count(); } catch (Exception $e) { error_log('Dash vend ventas: ' . $e->getMessage()); }

        try { $results['ventas_hoy'] = $this->ventaModel->where(['estado' => 1])->whereRaw('DATE(`fecha_venta`) = :where_fh', ['fh' => $hoy])->count(); } catch (Exception $e) { error_log('Dash vend ventas_hoy: ' . $e->getMessage()); }
        try { $results['ingresos_hoy'] = $this->ventaModel->where(['estado' => 1])->whereRaw('DATE(`fecha_venta`) = :where_fh', ['fh' => $hoy])->sum('total'); } catch (Exception $e) { error_log('Dash vend ingresos_hoy: ' . $e->getMessage()); }
        try { $results['ventas_mes'] = $this->ventaModel->where(['estado' => 1])->whereRaw('`fecha_venta` >= :where_im', ['im' => $inicioMes])->count(); } catch (Exception $e) { error_log('Dash vend ventas_mes: ' . $e->getMessage()); }
        try { $results['ingresos_mes'] = $this->ventaModel->where(['estado' => 1])->whereRaw('`fecha_venta` >= :where_im', ['im' => $inicioMes])->sum('total'); } catch (Exception $e) { error_log('Dash vend ingresos_mes: ' . $e->getMessage()); }

        if ($idUsuario) {
            try { $results['mis_ventas_hoy'] = $this->ventaModel->where(['estado' => 1])->whereRaw('DATE(`fecha_venta`) = :where_fh AND `id_usuario` = :where_uh', ['fh' => $hoy, 'uh' => $idUsuario])->count(); } catch (Exception $e) { error_log('Dash vend mis_hoy: ' . $e->getMessage()); }
            try { $results['mis_ingresos_hoy'] = $this->ventaModel->where(['estado' => 1])->whereRaw('DATE(`fecha_venta`) = :where_fh AND `id_usuario` = :where_uh', ['fh' => $hoy, 'uh' => $idUsuario])->sum('total'); } catch (Exception $e) { error_log('Dash vend mis_ingresos_hoy: ' . $e->getMessage()); }
            try { $results['mis_ventas_mes'] = $this->ventaModel->where(['estado' => 1])->whereRaw('`fecha_venta` >= :where_im AND `id_usuario` = :where_um', ['im' => $inicioMes, 'um' => $idUsuario])->count(); } catch (Exception $e) { error_log('Dash vend mis_mes: ' . $e->getMessage()); }
            try { $results['mis_ingresos_mes'] = $this->ventaModel->where(['estado' => 1])->whereRaw('`fecha_venta` >= :where_im AND `id_usuario` = :where_um', ['im' => $inicioMes, 'um' => $idUsuario])->sum('total'); } catch (Exception $e) { error_log('Dash vend mis_ingresos_mes: ' . $e->getMessage()); }
            try { $results['ventas_recientes'] = $this->obtenerVentasRecientesVendedor(5, $idUsuario); } catch (Exception $e) { error_log('Dash vend recientes: ' . $e->getMessage()); }
        }

        try { $results['productos_top'] = $this->obtenerProductosTop(5); } catch (Exception $e) { error_log('Dash vend top: ' . $e->getMessage()); }

        return $results;
    }

    private function obtenerVentasRecientes(int $limite): array {
        $sql = "SELECT v.id_venta, v.serie, v.numero, v.total, v.fecha_venta,
                       c.nombre AS cliente,
                       mp.nombre AS metodo_pago
                FROM venta v
                LEFT JOIN cliente c ON v.id_cliente = c.id_cliente
                LEFT JOIN metodo_pago mp ON v.id_metodo_pago = mp.id_metodo_pago
                WHERE v.estado = 1
                ORDER BY v.fecha_venta DESC
                LIMIT :limite";

        $stmt = $this->ventaModel->conect()->prepare($sql);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function obtenerVentasRecientesVendedor(int $limite, int $idUsuario): array {
        $sql = "SELECT v.id_venta, v.serie, v.numero, v.total, v.fecha_venta,
                       c.nombre AS cliente,
                       mp.nombre AS metodo_pago
                FROM venta v
                LEFT JOIN cliente c ON v.id_cliente = c.id_cliente
                LEFT JOIN metodo_pago mp ON v.id_metodo_pago = mp.id_metodo_pago
                WHERE v.estado = 1 AND v.id_usuario = :usuario
                ORDER BY v.fecha_venta DESC
                LIMIT :limite";

        $stmt = $this->ventaModel->conect()->prepare($sql);
        $stmt->bindValue(':usuario', $idUsuario, \PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function obtenerProductosTop(int $limite): array {
        $sql = "SELECT p.nombre, p.precio_venta,
                       SUM(dv.cantidad) AS total_vendido,
                       SUM(dv.subtotal) AS total_ingreso
                FROM detalle_venta dv
                INNER JOIN venta v ON dv.id_venta = v.id_venta
                INNER JOIN producto p ON dv.id_producto = p.id_producto
                WHERE v.estado = 1
                GROUP BY p.id_producto, p.nombre, p.precio_venta
                ORDER BY total_vendido DESC
                LIMIT :limite";

        $stmt = $this->ventaModel->conect()->prepare($sql);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function obtenerIngresosMensuales(int $meses): array {
        $sql = "SELECT DATE_FORMAT(fecha_venta, '%Y-%m') AS mes,
                       SUM(total) AS ingresos,
                       COUNT(*) AS num_ventas
                FROM venta
                WHERE estado = 1
                  AND fecha_venta >= DATE_SUB(CURDATE(), INTERVAL :meses MONTH)
                GROUP BY DATE_FORMAT(fecha_venta, '%Y-%m')
                ORDER BY mes ASC";

        $stmt = $this->ventaModel->conect()->prepare($sql);
        $stmt->bindValue(':meses', $meses, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function obtenerHorasPico(): array {
        $sql = "SELECT HOUR(fecha_venta) AS hora, COUNT(*) AS total
                FROM venta
                WHERE estado = 1
                  AND fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY HOUR(fecha_venta)
                ORDER BY total DESC
                LIMIT 5";

        $stmt = $this->ventaModel->conect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
