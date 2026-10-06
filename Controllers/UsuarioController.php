<?php

namespace Controllers;

use Libraries\Core\Controller;
use Services\UsuarioService;
use Models\RolModel;

class UsuarioController extends Controller {

    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new UsuarioService();
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
        $usuarios = $this->service->obtenerTodos();

        if (isset($_GET['json']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            $this->respond(['status' => true, 'data' => $usuarios], 200);
        }

        $data = [
            'usuarios' => $usuarios,
            'page_title' => 'Listado de Usuarios - Amazon Market'
        ];
        $this->views->render($this, "index", $data);
    }

    public function crear() {
        $rolModel = new RolModel();
        $data = [
            'roles' => $rolModel->obtenerActivos(),
            'page_title' => 'Nuevo Usuario - Amazon Market'
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
                header("Location: " . BASE_URL . "/Usuario");
            } else {
                $_SESSION['error'] = implode("<br>", $resultado['errors'] ?? [$resultado['message']]);
                header("Location: " . BASE_URL . "/Usuario/crear");
            }
            exit;
        }
    }

    public function editar($params) {
        $id = intval($params);
        if ($id <= 0) {
            header("Location: " . BASE_URL . "/Usuario");
            exit;
        }

        $usuario = $this->service->obtenerPorId($id);
        if (!$usuario) {
            $_SESSION['error'] = "Usuario no encontrado.";
            header("Location: " . BASE_URL . "/Usuario");
            exit;
        }

        $rolModel = new RolModel();
        $data = [
            'usuario' => $usuario,
            'roles' => $rolModel->obtenerActivos(),
            'page_title' => 'Editar Usuario - Amazon Market'
        ];
        $this->views->render($this, "editar", $data);
    }

    public function actualizar($params) {
        $id = intval($params);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
            $isJson = isset($_GET['json']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
            $input = $isJson ? json_decode(file_get_contents('php://input'), true) : $_POST;
            if (!$input) $input = $_POST;

            $resultado = $this->service->actualizar(
                $id,
                $input,
                intval($_SESSION['rol'] ?? 0)
            );

            if ($isJson) {
                $statusCode = $resultado['status'] ? 200 : 400;
                $this->respond($resultado, $statusCode);
            }

            if ($resultado['status']) {
                $_SESSION['success'] = $resultado['message'];
                header("Location: " . BASE_URL . "/Usuario");
            } else {
                $_SESSION['error'] = implode("<br>", $resultado['errors'] ?? [$resultado['message']]);
                header("Location: " . BASE_URL . "/Usuario/editar/" . $id);
            }
            exit;
        }
    }

    public function eliminar($params) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: " . BASE_URL . "/Usuario");
            exit;
        }
        $id = intval($params);
        if ($id > 0) {
            $isJson = isset($_GET['json']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            $resultado = $this->service->eliminar(
                $id,
                intval($_SESSION['idUser'] ?? 0),
                intval($_SESSION['rol'] ?? 0)
            );

            if ($isJson) {
                $statusCode = $resultado['status'] ? 200 : 500;
                $this->respond($resultado, $statusCode);
            }

            if ($resultado['status']) {
                $_SESSION['success'] = $resultado['message'];
            } else {
                $_SESSION['error'] = $resultado['message'];
            }
            header("Location: " . BASE_URL . "/Usuario");
            exit;
        }
    }
}
