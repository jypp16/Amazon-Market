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

    public function cierresCaja() {
        $cierres = $this->cierresCajaDemo();
        $data = [
            'page_title' => 'Cierres de caja - Amazon Market',
            'cierres' => $cierres
        ];
        $this->views->render($this, 'cierresCaja', $data);
    }

    public function detalleCierreCaja($id = '') {
        $cierres = $this->cierresCajaDemo();
        $cierre = null;
        foreach ($cierres as $item) {
            if ((string)$item['id'] === (string)$id) {
                $cierre = $item;
                break;
            }
        }

        if ($cierre === null) {
            http_response_code(404);
            $this->views->render($this, 'detalleCierreCaja', [
                'page_title' => 'Cierre no encontrado - Amazon Market',
                'cierre' => null
            ]);
            return;
        }

        $data = [
            'page_title' => 'Detalle de cierre de caja - Amazon Market',
            'cierre' => $cierre
        ];
        $this->views->render($this, 'detalleCierreCaja', $data);
    }

    private function cierresCajaDemo() {
        return [
            [
                'id' => 101,
                'closed_at' => '20/10/2026 13:30',
                'seller' => 'Luis',
                'shift' => 'Mañana',
                'shift_schedule' => 'Mañana · 08:00 – 14:00',
                'opening_at' => '20/10/2026 08:05',
                'closing_at' => '20/10/2026 13:30',
                'arqueo_at' => '13:25',
                'expected' => 300.00,
                'counted' => 295.00,
                'opening_fund' => 200.00,
                'closing_difference' => -5.00,
                'opening_difference' => 0.00,
                'observation' => 'Observación registrada.',
                'authorization' => 'Sin excepción',
                'cash_sales' => 80.00,
                'income' => 50.00,
                'expenses' => 30.00,
                'card_sales' => 90.00,
                'digital_sales' => 0.00,
                'has_omitted_withdrawal' => true,
                'movements' => [
                    ['date' => '20/10/2026', 'time' => '09:40', 'type' => 'Venta en efectivo', 'reference' => 'Boleta B001-000120', 'amount' => 80.00, 'seller' => 'Luis', 'administrator' => '—'],
                    ['date' => '20/10/2026', 'time' => '10:15', 'type' => 'Inyección', 'reference' => 'Cambio para caja', 'amount' => 50.00, 'seller' => 'Luis', 'administrator' => 'Admin (entregó)'],
                    ['date' => '20/10/2026', 'time' => '11:30', 'type' => 'Retiro', 'reference' => 'Pago a proveedor', 'amount' => -30.00, 'seller' => 'Luis', 'administrator' => 'Admin (recibió)'],
                    ['date' => '20/10/2026', 'time' => '11:45', 'type' => 'Retiro omitido', 'reference' => 'Vinculado al turno 19/10 tarde · cierre original conservado', 'amount' => 300.00, 'seller' => 'Luis', 'administrator' => 'Admin (recibió)']
                ]
            ],
            [
                'id' => 102,
                'closed_at' => '19/10/2026 21:05',
                'seller' => 'Marta',
                'shift' => 'Tarde',
                'shift_schedule' => 'Tarde · 14:00 – 21:00',
                'opening_at' => '19/10/2026 14:00',
                'closing_at' => '19/10/2026 21:05',
                'arqueo_at' => '20:55',
                'expected' => 500.00,
                'counted' => 200.00,
                'opening_fund' => 200.00,
                'closing_difference' => -300.00,
                'opening_difference' => -10.00,
                'observation' => 'Se registró el faltante del arqueo.',
                'authorization' => 'Sin excepción',
                'cash_sales' => 240.00,
                'income' => 100.00,
                'expenses' => 40.00,
                'card_sales' => 120.00,
                'digital_sales' => 40.00,
                'has_omitted_withdrawal' => true,
                'movements' => [
                    ['date' => '19/10/2026', 'time' => '20:50', 'type' => 'Retiro omitido', 'reference' => 'Vinculado al turno 18/10 mañana · cierre original conservado', 'amount' => 300.00, 'seller' => 'Marta', 'administrator' => 'Admin (recibió)']
                ]
            ],
            [
                'id' => 103,
                'closed_at' => '18/10/2026 21:00',
                'seller' => 'Marta',
                'shift' => 'Tarde',
                'shift_schedule' => 'Tarde · 14:00 – 21:00',
                'opening_at' => '18/10/2026 14:00',
                'closing_at' => '18/10/2026 21:00',
                'arqueo_at' => '20:50',
                'expected' => 450.00,
                'counted' => 450.00,
                'opening_fund' => 200.00,
                'closing_difference' => 0.00,
                'opening_difference' => 0.00,
                'observation' => 'Sin observación.',
                'authorization' => 'Sin excepción',
                'cash_sales' => 200.00,
                'income' => 80.00,
                'expenses' => 30.00,
                'card_sales' => 80.00,
                'digital_sales' => 20.00,
                'movements' => []
            ]
        ];
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

    public function kardex() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['login'])) {
            header('Location: ' . BASE_URL . '/Auth');
            exit;
        }
        if ((int)($_SESSION['rol'] ?? 0) !== 1) {
            header('Location: ' . BASE_URL . '/Home');
            exit;
        }
        $data = ['page_title' => 'Kardex de inventario - Amazon Market'];
        $this->views->render($this, "kardex", $data);
    }

    public function variaciones() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['login'])) {
            header('Location: ' . BASE_URL . '/Auth');
            exit;
        }
        if ((int)($_SESSION['rol'] ?? 0) !== 1) {
            header('Location: ' . BASE_URL . '/Home');
            exit;
        }
        $data = ['page_title' => 'Reporte de variaciones - Amazon Market'];
        $this->views->render($this, "variaciones", $data);
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
