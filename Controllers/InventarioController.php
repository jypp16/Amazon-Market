<?php

namespace Controllers;

use Libraries\Core\Controller;
use Libraries\Core\InventarioException;
use Services\InventarioService;
use Throwable;

class InventarioController extends Controller {

    private InventarioService $service;

    public function __construct() {
        parent::__construct();
        $this->service = new InventarioService();
    }

    public function index() {
        $this->ajustes();
    }

    public function ajustes() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $refHeader = $_SERVER['HTTP_X_REQUEST_REFERENCE'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
            $idUsuario = (int)($_SESSION['idUser'] ?? 0);

            header('Content-Type: application/json');
            try {
                $resultado = $this->service->registrarAjuste($input, $refHeader, $idUsuario);
                http_response_code(!empty($resultado['idempotent_replay']) ? 200 : 201);
                echo json_encode($resultado);
            } catch (InventarioException $e) {
                http_response_code($e->getHttpStatus());
                echo json_encode($e->toApiResponse());
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        $data = ['page_title' => 'Ajustes de inventario - Amazon Market'];
        $this->views->render($this, 'ajustes', $data);
    }

    public function bajas() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $refHeader = $_SERVER['HTTP_X_REQUEST_REFERENCE'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
            $idUsuario = (int)($_SESSION['idUser'] ?? 0);

            header('Content-Type: application/json');
            try {
                $resultado = $this->service->registrarBaja($input, $refHeader, $idUsuario);
                http_response_code(!empty($resultado['idempotent_replay']) ? 200 : 201);
                echo json_encode($resultado);
            } catch (InventarioException $e) {
                http_response_code($e->getHttpStatus());
                echo json_encode($e->toApiResponse());
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }
    }
}
