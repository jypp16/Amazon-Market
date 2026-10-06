<?php

namespace Services;

use Models\ReporteModel;
use Models\CategoriaModel;
use Models\UsuarioModel;
use Models\ClienteModel;
use Exception;

class ReporteService {

    private $model;

    public function __construct() {
        $this->model = new ReporteModel();
    }

    public function validarFechas(array $input): array {
        $desde = $input['desde'] ?? date('Y-m-01');
        $hasta = $input['hasta'] ?? date('Y-m-d');

        $desde = date('Y-m-d', strtotime($desde));
        $hasta = date('Y-m-d', strtotime($hasta));

        if ($desde > date('Y-m-d')) $desde = date('Y-m-d');
        if ($hasta > date('Y-m-d')) $hasta = date('Y-m-d');
        if ($desde > $hasta) $desde = $hasta;

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    public function reporteVentas(array $input): array {
        $fechas = $this->validarFechas($input);
        $filtros = array_merge($fechas, [
            'id_tipo_comprobante' => intval($input['id_tipo_comprobante'] ?? 0),
            'id_metodo_pago' => intval($input['id_metodo_pago'] ?? 0),
            'id_usuario' => intval($input['id_usuario'] ?? 0),
            'id_cliente' => intval($input['id_cliente'] ?? 0),
        ]);

        $ventas = $this->model->ventasPorPeriodo($filtros);
        $resumen = $this->model->resumenVentasPeriodo($fechas['desde'], $fechas['hasta']);
        $ventasPorDia = $this->model->ventasPorDia($fechas['desde'], $fechas['hasta']);
        $comprobantes = $this->model->comprobantesPorTipo($fechas['desde'], $fechas['hasta']);

        $diasAntes = intval(abs((strtotime($fechas['desde']) - strtotime($fechas['hasta'])) / 86400));
        $desdeAnterior = date('Y-m-d', strtotime($fechas['desde']) - ($diasAntes + 1) * 86400);
        $hastaAnterior = date('Y-m-d', strtotime($fechas['desde']) - 86400);
        $resumenAnterior = $this->model->resumenVentasPeriodo($desdeAnterior, $hastaAnterior);

        $varIngresos = 0;
        if (floatval($resumenAnterior['total_ingresos'] ?? 0) > 0) {
            $varIngresos = round((($resumen['total_ingresos'] - $resumenAnterior['total_ingresos']) / $resumenAnterior['total_ingresos']) * 100, 1);
        }

        $boletas = 0;
        $facturas = 0;
        foreach ($comprobantes as $c) {
            if (stripos($c['comprobante'], 'Boleta') !== false) $boletas = intval($c['cantidad']);
            if (stripos($c['comprobante'], 'Factura') !== false) $facturas = intval($c['cantidad']);
        }

        return [
            'ventas' => $ventas,
            'resumen' => $resumen,
            'ventasPorDia' => $ventasPorDia,
            'comprobantes' => $comprobantes,
            'varIngresos' => $varIngresos,
            'boletas' => $boletas,
            'facturas' => $facturas,
            'filtros' => $filtros,
        ];
    }

    public function reporteProductosMasVendidos(array $input): array {
        $fechas = $this->validarFechas($input);
        $filtros = array_merge($fechas, [
            'id_categoria' => intval($input['id_categoria'] ?? 0),
            'topN' => min(500, max(10, intval($input['topN'] ?? 10))),
        ]);

        $productos = $this->model->productosMasVendidos($filtros);
        $totalIngresos = 0;
        $totalUnidades = 0;
        foreach ($productos as $p) {
            $totalIngresos += floatval($p['ingresos']);
            $totalUnidades += intval($p['unidades_vendidas']);
        }

        $resultado = [];
        foreach ($productos as $p) {
            $p['porcentaje_ingresos'] = $totalIngresos > 0 ? round((floatval($p['ingresos']) / $totalIngresos) * 100, 1) : 0;
            $p['porcentaje_unidades'] = $totalUnidades > 0 ? round((intval($p['unidades_vendidas']) / $totalUnidades) * 100, 1) : 0;
            $resultado[] = $p;
        }

        return [
            'productos' => $resultado,
            'totalIngresos' => $totalIngresos,
            'totalUnidades' => $totalUnidades,
            'filtros' => $filtros,
        ];
    }

    public function reporteProductosMenosVendidos(array $input): array {
        $fechas = $this->validarFechas($input);
        $filtros = array_merge($fechas, [
            'id_categoria' => intval($input['id_categoria'] ?? 0),
            'umbral' => max(0, intval($input['umbral'] ?? 5)),
        ]);

        $productos = $this->model->productosMenosVendidos($filtros);

        return [
            'productos' => $productos,
            'filtros' => $filtros,
        ];
    }

    public function reporteInventario(): array {
        $productos = $this->model->inventario();
        $resumen = $this->model->resumenInventario();

        return [
            'productos' => $productos,
            'resumen' => $resumen,
        ];
    }

    public function reporteClientes(array $input): array {
        $fechas = $this->validarFechas($input);
        $topN = min(500, max(10, intval($input['topN'] ?? 20)));

        $clientes = $this->model->topClientes(['desde' => $fechas['desde'], 'hasta' => $fechas['hasta'], 'topN' => $topN]);
        $distribucion = $this->model->clientesNuevosVsRecurrentes($fechas['desde'], $fechas['hasta']);

        return [
            'clientes' => $clientes,
            'distribucion' => $distribucion,
            'filtros' => array_merge($fechas, ['topN' => $topN]),
        ];
    }

    public function reporteVendedores(array $input): array {
        $fechas = $this->validarFechas($input);
        $vendedores = $this->model->ventasPorVendedor($fechas);

        $totalIngresos = 0;
        foreach ($vendedores as $v) {
            $totalIngresos += floatval($v['ingreso_total']);
        }

        $resultado = [];
        foreach ($vendedores as $v) {
            $v['porcentaje'] = $totalIngresos > 0 ? round((floatval($v['ingreso_total']) / $totalIngresos) * 100, 1) : 0;
            $resultado[] = $v;
        }

        return [
            'vendedores' => $resultado,
            'totalIngresos' => $totalIngresos,
            'filtros' => $fechas,
        ];
    }

    public function reporteCategorias(array $input): array {
        $fechas = $this->validarFechas($input);
        $categorias = $this->model->ventasPorCategoria($fechas);

        $totalIngresos = 0;
        $totalUnidades = 0;
        foreach ($categorias as $c) {
            $totalIngresos += floatval($c['ingresos']);
            $totalUnidades += intval($c['unidades']);
        }

        $resultado = [];
        foreach ($categorias as $c) {
            $c['porcentaje'] = $totalIngresos > 0 ? round((floatval($c['ingresos']) / $totalIngresos) * 100, 1) : 0;
            $resultado[] = $c;
        }

        return [
            'categorias' => $resultado,
            'totalIngresos' => $totalIngresos,
            'totalUnidades' => $totalUnidades,
            'filtros' => $fechas,
        ];
    }

    public function reporteComprobantes(array $input): array {
        $fechas = $this->validarFechas($input);
        $filtros = array_merge($fechas, [
            'id_tipo_comprobante' => intval($input['id_tipo_comprobante'] ?? 0),
        ]);

        $comprobantes = $this->model->comprobantesDetallado($filtros);

        $totalBoletas = 0;
        $totalFacturas = 0;
        $montoBoletas = 0;
        $montoFacturas = 0;

        foreach ($comprobantes as $c) {
            if (stripos($c['comprobante'], 'Boleta') !== false) {
                $totalBoletas += intval($c['cantidad']);
                $montoBoletas += floatval($c['total']);
            } else {
                $totalFacturas += intval($c['cantidad']);
                $montoFacturas += floatval($c['total']);
            }
        }

        $igvFacturas = round($montoFacturas * 0.18, 2);

        return [
            'comprobantes' => $comprobantes,
            'totalBoletas' => $totalBoletas,
            'totalFacturas' => $totalFacturas,
            'montoBoletas' => $montoBoletas,
            'montoFacturas' => $montoFacturas,
            'igvFacturas' => $igvFacturas,
            'filtros' => $filtros,
        ];
    }

    public function reporteMetodosPago(array $input): array {
        $fechas = $this->validarFechas($input);
        $metodos = $this->model->metodosDePago($fechas);

        $totalTransacciones = 0;
        $totalMontos = 0;
        foreach ($metodos as $m) {
            $totalTransacciones += intval($m['num_transacciones']);
            $totalMontos += floatval($m['monto_total']);
        }

        $resultado = [];
        foreach ($metodos as $m) {
            $m['porcentaje'] = $totalMontos > 0 ? round((floatval($m['monto_total']) / $totalMontos) * 100, 1) : 0;
            $resultado[] = $m;
        }

        return [
            'metodos' => $resultado,
            'totalTransacciones' => $totalTransacciones,
            'totalMontos' => $totalMontos,
            'filtros' => $fechas,
        ];
    }

    public function reporteResumen(array $input): array {
        $fechas = $this->validarFechas($input);
        $ventas = $this->reporteVentas($input);
        $masVendidos = $this->reporteProductosMasVendidos(array_merge($input, ['topN' => 5]));
        $topClientes = $this->reporteClientes(array_merge($input, ['topN' => 3]));
        $inventario = $this->reporteInventario();
        $metodos = $this->reporteMetodosPago($input);
        $vendedores = $this->reporteVendedores($input);

        return [
            'ventas' => $ventas,
            'masVendidos' => $masVendidos,
            'topClientes' => $topClientes,
            'inventario' => $inventario,
            'metodos' => $metodos,
            'vendedores' => $vendedores,
            'filtros' => $fechas,
        ];
    }

    public function obtenerCategorias(): array {
        return $this->model->obtenerActivos('categoria', 'id_categoria', 'nombre');
    }

    public function obtenerUsuarios(): array {
        return $this->model->obtenerActivos('usuario', 'id_usuario', 'nombre');
    }

    public function obtenerClientes(): array {
        return $this->model->obtenerActivos('cliente', 'id_cliente', 'nombre');
    }

    public function obtenerComprobantes(): array {
        return $this->model->obtenerActivos('tipo_comprobante', 'id_tipo_comprobante', 'nombre');
    }

    public function obtenerMetodosPago(): array {
        return $this->model->obtenerActivos('metodo_pago', 'id_metodo_pago', 'nombre');
    }
}
