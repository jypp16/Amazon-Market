<?php

namespace Controllers;

use Libraries\Core\Controller;
use Libraries\Core\InventarioException;
use Libraries\Middleware\AuthMiddleware;
use Libraries\Middleware\RBACMiddleware;
use Services\InventarioService;
use Services\AlertaService;
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
        $usuario = AuthMiddleware::verificarUsuarioActivoEnSesion();
        if ($usuario === null) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode(['status' => false, 'code' => 'AUTH_REQUIRED', 'message' => 'Sesión no válida.']);
                exit;
            }
            header('Location: ' . BASE_URL . '/Auth');
            exit;
        }

        $rol = (int)$usuario['id_rol'];
        $esAdmin = ($rol === 1 && RBACMiddleware::tienePermiso(1, 'inventario.ajustes'));
        $puedeBajaVenc = RBACMiddleware::tienePermisoAsignado($rol, 'inventario.bajas_vencimiento');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            if (!$esAdmin) {
                http_response_code(403);
                echo json_encode([
                    'status' => false,
                    'code' => 'FORBIDDEN_ADMIN_ONLY',
                    'message' => 'Solo el Administrador puede registrar ajustes de stock.'
                ]);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $refHeader = $_SERVER['HTTP_X_REQUEST_REFERENCE'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
            $idUsuario = (int)$usuario['id_usuario'];

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

        if (!$esAdmin && !$puedeBajaVenc) {
            $_SESSION['error'] = 'No tienes permisos para acceder a esta sección.';
            header('Location: ' . BASE_URL . '/Home');
            exit;
        }

        $data = [
            'page_title' => $esAdmin ? 'Ajustes de inventario - Amazon Market' : 'Baja por vencimiento - Amazon Market',
            'solo_baja_vencimiento' => !$esAdmin,
        ];
        $this->views->render($this, 'ajustes', $data);
    }

    public function bajas() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            $usuario = AuthMiddleware::verificarUsuarioActivoEnSesion();
            if ($usuario === null) {
                http_response_code(401);
                echo json_encode(['status' => false, 'code' => 'AUTH_REQUIRED', 'message' => 'Sesión no válida.']);
                exit;
            }
            $rol = (int)$usuario['id_rol'];
            $esAdmin = ($rol === 1 && RBACMiddleware::tienePermiso(1, 'inventario.ajustes'));
            $puedeBajaVenc = RBACMiddleware::tienePermisoAsignado($rol, 'inventario.bajas_vencimiento');
            if (!$esAdmin && !$puedeBajaVenc) {
                http_response_code(403);
                echo json_encode(['status' => false, 'code' => 'FORBIDDEN', 'message' => 'No tienes permisos para registrar bajas.']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $refHeader = $_SERVER['HTTP_X_REQUEST_REFERENCE'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
            $idUsuario = (int)$usuario['id_usuario'];

            try {
                $resultado = $this->service->registrarBaja($input, $refHeader, $idUsuario, !$esAdmin);
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

    public function vencimientos() {
        $usuario = AuthMiddleware::verificarUsuarioActivoEnSesion();
        if ($usuario === null) {
            header('Location: ' . BASE_URL . '/Auth');
            exit;
        }
        $rol = (int)$usuario['id_rol'];
        if (!RBACMiddleware::tienePermiso($rol, 'inventario.vencimientos')) {
            $_SESSION['error'] = 'No tienes permisos para consultar los avisos de vencimiento.';
            header('Location: ' . BASE_URL . '/Home');
            exit;
        }

        $data = [
            'page_title' => 'Avisos de vencimiento - Amazon Market',
        ];
        $this->views->render($this, 'vencimientos', $data);
    }

    private function exigirAdministrador(): void {
        $usuario = AuthMiddleware::verificarUsuarioActivoEnSesion();
        if ($usuario === null) {
            header('Location: ' . BASE_URL . '/Auth');
            exit;
        }
        if ((int)$usuario['id_rol'] !== 1 || !RBACMiddleware::tienePermiso(1, 'inventario.ajustes')) {
            $_SESSION['error'] = 'Acceso denegado. Esta sección está reservada al Administrador con permisos de Inventario.';
            header('Location: ' . BASE_URL . '/Home');
            exit;
        }
    }

    public function movimientos() {
        $this->exigirAdministrador();
        $this->views->render($this, 'movimientos', [
            'page_title' => 'Movimientos de inventario - Amazon Market',
        ]);
    }

    public function movimiento($id = '') {
        $this->exigirAdministrador();
        $idMovimiento = ctype_digit((string)$id) ? (int)$id : 0;
        $this->views->render($this, 'movimiento', [
            'page_title' => 'Movimiento N.° ' . $idMovimiento . ' - Amazon Market',
            'id_movimiento' => $idMovimiento,
        ]);
    }

    public function editarMovimiento($id = '') {
        $this->exigirAdministrador();
        $idMovimiento = ctype_digit((string)$id) ? (int)$id : 0;
        $this->views->render($this, 'editarMovimiento', [
            'page_title' => 'Editar movimiento N.° ' . $idMovimiento . ' - Amazon Market',
            'id_movimiento' => $idMovimiento,
        ]);
    }
}