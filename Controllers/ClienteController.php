<?php

namespace Controllers;

use Libraries\Core\Controller;
use Services\ClienteService;
use Models\TipoDocumentoModel;

class ClienteController extends Controller {

    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new ClienteService();
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
        $clientes = $this->service->obtenerTodos();

        if (isset($_GET['json']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            $this->respond(['status' => true, 'data' => $clientes], 200);
        }

        $data = [
            'clientes' => $clientes,
            'page_title' => 'Listado de Clientes - Amazon Market'
        ];
        $this->views->render($this, "index", $data);
    }

    public function crear() {
        $tdModel = new TipoDocumentoModel();
        $data = [
            'tipos_documento' => $tdModel->obtenerActivos(),
            'page_title' => 'Nuevo Cliente - Amazon Market'
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
                header("Location: " . BASE_URL . "/Cliente");
            } else {
                $_SESSION['error'] = implode("<br>", $resultado['errors'] ?? [$resultado['message']]);
                header("Location: " . BASE_URL . "/Cliente/crear");
            }
            exit;
        }
    }

    public function editar($params) {
        $id = intval($params);
        if ($id <= 0) {
            header("Location: " . BASE_URL . "/Cliente");
            exit;
        }

        $cliente = $this->service->obtenerPorId($id);
        if (!$cliente) {
            $_SESSION['error'] = "Cliente no encontrado.";
            header("Location: " . BASE_URL . "/Cliente");
            exit;
        }

        $tdModel = new TipoDocumentoModel();
        $data = [
            'cliente' => $cliente,
            'tipos_documento' => $tdModel->obtenerActivos(),
            'page_title' => 'Editar Cliente - Amazon Market'
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
                header("Location: " . BASE_URL . "/Cliente");
            } else {
                $_SESSION['error'] = implode("<br>", $resultado['errors'] ?? [$resultado['message']]);
                header("Location: " . BASE_URL . "/Cliente/editar/" . $id);
            }
            exit;
        }
    }

    public function eliminar($params) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: " . BASE_URL . "/Cliente");
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
            header("Location: " . BASE_URL . "/Cliente");
            exit;
        }
    }
}
