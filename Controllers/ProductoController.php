<?php

namespace Controllers;

use Libraries\Core\Controller;
use Services\ProductoService;
use Models\CategoriaModel;
use Models\UnidadMedidaModel;

class ProductoController extends Controller {

    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new ProductoService();
    }

    private function respond($data, $statusCode = 200) {
        error_reporting(0);
        ini_set('display_errors', 0);
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }

    public function index() {
        $productos = $this->service->obtenerTodos();

        if (isset($_GET['json']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            $this->respond(['status' => true, 'data' => $productos], 200);
        }

        $data = [
            'productos' => $productos,
            'page_title' => 'Listado de Productos - Amazon Market'
        ];
        $this->views->render($this, "index", $data);
    }

    public function crear() {
        $catModel = new CategoriaModel();
        $unidadModel = new \Models\UnidadMedidaModel();
        $data = [
            'categorias' => $catModel->obtenerActivas(),
            'unidades' => $unidadModel->obtenerActivas(),
            'page_title' => 'Nuevo Producto - Amazon Market'
        ];
        $this->views->render($this, "nuevo", $data);
    }

    public function guardar() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $isJson = isset($_GET['json']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
            $input = $isJson ? json_decode(file_get_contents('php://input'), true) : $_POST;
            if (!$input) $input = $_POST;

            $resultado = $this->service->crear($input);

            if ($isJson) {
                $statusCode = $resultado['status'] ? 201 : 400;
                $this->respond($resultado, $statusCode);
            }

            if ($resultado['status']) {
                $_SESSION['success'] = $resultado['message'];
                header("Location: " . BASE_URL . "/Producto");
            } else {
                $_SESSION['error'] = implode("<br>", $resultado['errors'] ?? [$resultado['message']]);
                header("Location: " . BASE_URL . "/Producto/crear");
            }
            exit;
        }
    }

    public function editar($params) {
        $id = intval($params);
        if ($id <= 0) {
            header("Location: " . BASE_URL . "/Producto");
            exit;
        }

        $producto = $this->service->obtenerPorId($id);
        if (!$producto) {
            $_SESSION['error'] = "Producto no encontrado.";
            header("Location: " . BASE_URL . "/Producto");
            exit;
        }

        $catModel = new CategoriaModel();
        $unidadModel = new \Models\UnidadMedidaModel();
        $data = [
            'producto' => $producto,
            'categorias' => $catModel->obtenerActivas(),
            'unidades' => $unidadModel->obtenerActivas(),
            'page_title' => 'Editar Producto - Amazon Market'
        ];
        $this->views->render($this, "editar", $data);
    }

    public function actualizar($params) {
        $id = intval($params);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
            $isJson = isset($_GET['json']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
            $input = $isJson ? json_decode(file_get_contents('php://input'), true) : $_POST;
            if (!$input) $input = $_POST;

            $resultado = $this->service->actualizar($id, $input);

            if ($isJson) {
                $statusCode = $resultado['status'] ? 200 : 400;
                $this->respond($resultado, $statusCode);
            }

            if ($resultado['status']) {
                $_SESSION['success'] = $resultado['message'];
                header("Location: " . BASE_URL . "/Producto");
            } else {
                $_SESSION['error'] = implode("<br>", $resultado['errors'] ?? [$resultado['message']]);
                header("Location: " . BASE_URL . "/Producto/editar/" . $id);
            }
            exit;
        }
    }

    public function eliminar($params) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: " . BASE_URL . "/Producto");
            exit;
        }
        $id = intval($params);
        if ($id > 0) {
            $isJson = isset($_GET['json']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            $resultado = $this->service->eliminar($id);

            if ($isJson) {
                $statusCode = $resultado['status'] ? 200 : 500;
                $this->respond($resultado, $statusCode);
            }

            if ($resultado['status']) {
                $_SESSION['success'] = $resultado['message'];
            } else {
                $_SESSION['error'] = $resultado['message'];
            }
            header("Location: " . BASE_URL . "/Producto");
            exit;
        }
    }

    public function detalle($params) {
        $id = intval($params);
        if ($id > 0) {
            $producto = $this->service->obtenerDetalle($id);
            
            if ($producto) {
                $this->respond(['status' => true, 'data' => $producto], 200);
            } else {
                $this->respond(['status' => false, 'message' => 'Producto no encontrado.'], 404);
            }
        } else {
            $this->respond(['status' => false, 'message' => 'ID inválido.'], 400);
        }
    }
}
