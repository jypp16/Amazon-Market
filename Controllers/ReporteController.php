<?php

namespace Controllers;

use Libraries\Core\Controller;
use Services\ReporteService;
use Exception;

class ReporteController extends Controller {

    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new ReporteService();
    }

    public function index() {
        $data = ['page_title' => 'Reportes - Amazon Market'];
        $this->views->render($this, "index", $data);
    }

    public function ventas() {
        $filtros = $_GET;
        $resultado = $this->service->reporteVentas($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Reporte de Ventas por Período - Amazon Market',
            'listaComprobantes' => $this->service->obtenerComprobantes(),
            'listaMetodosPago' => $this->service->obtenerMetodosPago(),
            'listaUsuarios' => $this->service->obtenerUsuarios(),
            'listaClientes' => $this->service->obtenerClientes(),
        ]);

        $this->views->render($this, "ventas", $data);
    }

    public function productosMasVendidos() {
        $filtros = $_GET;
        $resultado = $this->service->reporteProductosMasVendidos($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Productos Más Vendidos - Amazon Market',
            'categorias' => $this->service->obtenerCategorias(),
        ]);

        $this->views->render($this, "productosMasVendidos", $data);
    }

    public function productosMenosVendidos() {
        $filtros = $_GET;
        $resultado = $this->service->reporteProductosMenosVendidos($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Productos Menos Vendidos - Amazon Market',
            'categorias' => $this->service->obtenerCategorias(),
        ]);

        $this->views->render($this, "productosMenosVendidos", $data);
    }

    public function inventario() {
        $resultado = $this->service->reporteInventario();

        $data = array_merge($resultado, [
            'page_title' => 'Valor de Inventario y Stock Bajo - Amazon Market',
        ]);

        $this->views->render($this, "inventario", $data);
    }

    public function clientes() {
        $filtros = $_GET;
        $resultado = $this->service->reporteClientes($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Reporte de Clientes - Amazon Market',
        ]);

        $this->views->render($this, "clientes", $data);
    }

    public function vendedores() {
        $filtros = $_GET;
        $resultado = $this->service->reporteVendedores($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Ventas por Vendedor - Amazon Market',
        ]);

        $this->views->render($this, "vendedores", $data);
    }

    public function categorias() {
        $filtros = $_GET;
        $resultado = $this->service->reporteCategorias($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Ventas por Categoría - Amazon Market',
        ]);

        $this->views->render($this, "categorias", $data);
    }

    public function comprobantes() {
        $filtros = $_GET;
        $resultado = $this->service->reporteComprobantes($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Reporte de Comprobantes - Amazon Market',
            'listaComprobantes' => $this->service->obtenerComprobantes(),
        ]);

        $this->views->render($this, "comprobantes", $data);
    }

    public function metodosPago() {
        $filtros = $_GET;
        $resultado = $this->service->reporteMetodosPago($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Métodos de Pago - Amazon Market',
        ]);

        $this->views->render($this, "metodosPago", $data);
    }

    public function resumen() {
        $filtros = $_GET;
        $resultado = $this->service->reporteResumen($filtros);

        $data = array_merge($resultado, [
            'page_title' => 'Resumen Ejecutivo - Amazon Market',
        ]);

        $this->views->render($this, "resumen", $data);
    }
}
