<?php

namespace Controllers;

use Libraries\Core\Controller;
use Models\PresentacionModel;
use Models\ProveedorModel;
use Models\ProductoModel;
use Models\TipoComprobanteModel;

class CompraController extends Controller {
    public function index() {
        $proveedorModel = new ProveedorModel();
        $fechaHoy = new \DateTimeImmutable('today');
        $fechaDesde = $fechaHoy->modify('first day of previous month');

        $this->views->render($this, 'index', [
            'proveedores' => $proveedorModel->where(['estado = 1'])
                ->orderBy('razon_social', 'ASC')
                ->get(),
            'fecha_desde' => $fechaDesde->format('Y-m-d'),
            'fecha_hasta' => $fechaHoy->format('Y-m-d'),
            'page_title' => 'Historial de compras - Amazon Market'
        ]);
    }

    public function registrar() {
        $this->renderFormularioCompra();
    }

    public function corregir($id = '') {
        $idCompra = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($idCompra === false) {
            http_response_code(404);
            return;
        }

        $this->renderFormularioCompra($idCompra);
    }

    private function renderFormularioCompra($idCompra = null) {
        $proveedorModel = new ProveedorModel();
        $productoModel = new ProductoModel();
        $presentacionModel = new PresentacionModel();
        $tipoComprobanteModel = new TipoComprobanteModel();

        $this->views->render($this, 'registrar', [
            'proveedores' => $proveedorModel->where(['estado = 1'])
                ->orderBy('razon_social', 'ASC')
                ->get(),
            'productos' => $productoModel->obtenerProductosConCategoria(),
            'presentaciones' => $presentacionModel->where(['estado = 1'])
                ->orderBy('nombre', 'ASC')
                ->get(),
            'tipos_comprobante' => $tipoComprobanteModel->obtenerActivos(),
            'fecha_hoy' => date('Y-m-d'),
            'id_compra_correccion' => $idCompra,
            'page_title' => ($idCompra ? 'Corregir compra' : 'Registrar Compra') . ' - Amazon Market'
        ]);
    }

    public function detalle($id = '') {
        $idCompra = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($idCompra === false) {
            http_response_code(404);
        }

        $this->views->render($this, 'detalle', [
            'id_compra' => $idCompra === false ? 0 : $idCompra,
            'page_title' => 'Detalle de compra - Amazon Market'
        ]);
    }

}
