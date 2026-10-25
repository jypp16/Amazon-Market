<?php

namespace Controllers;

use Libraries\Core\Controller;
use Libraries\Core\InventarioException;
use Services\VentaService;
use Models\ClienteModel;
use Models\ProductoModel;
use Models\TipoComprobanteModel;
use Models\MetodoPagoModel;
use Models\CategoriaModel;
use Throwable;

class VentaController extends Controller {

    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new VentaService();
    }

    public function index() {
        $clienteM = new ClienteModel();
        $productoM = new ProductoModel();
        $compM = new TipoComprobanteModel();
        $pagoM = new MetodoPagoModel();
        $catM = new CategoriaModel();

        $clientes = $clienteM->select(['cliente.*', 'tipo_documento.nombre as tipo_documento'])
            ->join('tipo_documento', 'cliente.id_tipo_documento = tipo_documento.id_tipo_documento', 'INNER')
            ->where(['cliente.estado = 1'])
            ->orderBy('cliente.nombre', 'ASC')
            ->get();
        $productos = $productoM->select(['producto.id_producto', 'producto.nombre', 'unidad_medida.abreviatura as unidad', 'categoria.nombre as categoria'])
            ->join('unidad_medida', 'producto.id_unidad = unidad_medida.id_unidad', 'INNER')
            ->join('categoria', 'producto.id_categoria = categoria.id_categoria', 'INNER')
            ->where(['producto.estado = 1', 'producto.stock_actual > 0'])
            ->orderBy('producto.nombre', 'ASC')
            ->get();

        $data = [
            'clientes' => $clientes,
            'productos' => $productos,
            'comprobantes' => $compM->obtenerActivos(),
            'pagos' => $pagoM->obtenerActivos(),
            'categorias' => $catM->obtenerActivas(),
            'page_title' => 'Punto de Venta - Amazon Market'
        ];
        $this->views->render($this, "index", $data);
    }

    public function guardar() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }

            // Vincular venta al inquilino autenticado (defensa BOLA/IDOR - Task 8)
            $input['id_usuario'] = intval($_SESSION['idUser'] ?? 0);

            header('Content-Type: application/json');
            try {
                $resultado = $this->service->procesarVenta($input);
                http_response_code($resultado['status'] ? 201 : 400);
                echo json_encode($resultado);
            } catch (InventarioException $e) {
                http_response_code($e->getStatusCode());
                echo json_encode($e->asResponseArray());
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }
    }
}

