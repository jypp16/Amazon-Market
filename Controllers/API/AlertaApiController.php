<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Libraries\Core\InventarioException;
use Libraries\Middleware\RBACMiddleware;
use Services\AlertaService;
use Throwable;

class AlertaApiController extends ApiController {
    private AlertaService $alertaService;

    public function __construct() {
        parent::__construct();
        $this->alertaService = new AlertaService();
    }

    /**
     * GET /api/alertas/stock
     * GET /api/alertas/vencimientos
     * GET /api/alertas/vencimientos/resumen
     */
    public function get(?string $params = ''): void {
        $ruta = strtolower(trim((string)$params, '/'));

        try {
            if ($ruta === 'stock') {
                $alertas = $this->alertaService->obtenerAlertasStock();
                $this->sendJsonResponse(['status' => true, 'message' => 'Alertas de Stock Bajo', 'data' => $alertas], 200);
                return;
            }

            if ($ruta === 'vencimientos') {
                $this->exigirPermisoVencimientos();
                $data = $this->alertaService->obtenerAlertasVencimiento(15);
                $this->sendJsonResponse([
                    'status' => true,
                    'code' => 'EXPIRATION_ALERTS_LISTED',
                    'message' => 'Avisos de vencimiento activos obtenidos correctamente.',
                    'data' => $data,
                ], 200);
                return;
            }

            if ($ruta === 'vencimientos/resumen') {
                $this->exigirPermisoVencimientos();
                $data = $this->alertaService->obtenerResumenAvisosVencimiento(15);
                $this->sendJsonResponse([
                    'status' => true,
                    'code' => 'EXPIRATION_ALERTS_SUMMARY',
                    'message' => 'Resumen de avisos de vencimiento obtenido correctamente.',
                    'data' => $data,
                ], 200);
                return;
            }

            if ($ruta === '') {
                $stock = $this->alertaService->obtenerAlertasStock();
                $vencimientos = $this->tienePermisoVencimientos()
                    ? $this->alertaService->obtenerAlertasVencimiento(15)['items']
                    : [];
                $this->sendJsonResponse([
                    'status' => true,
                    'message' => 'Resumen de Alertas',
                    'data' => [
                        'stock_bajo' => $stock,
                        'vencimientos' => $vencimientos,
                    ],
                ], 200);
                return;
            }

            $this->sendJsonResponse([
                'status' => false,
                'code' => 'RESOURCE_NOT_FOUND',
                'message' => "Recurso de alertas desconocido: '{$params}'.",
                'errors' => new \stdClass(),
                'data' => null,
            ], 404);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpStatus());
        } catch (Throwable $e) {
            error_log('Error en AlertaApiController::get: ' . $e->getMessage());
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'INTERNAL_ERROR',
                'message' => 'No se pudieron consultar las alertas.',
                'errors' => new \stdClass(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * POST /api/alertas/vencimientos/{id}/revisiones
     */
    public function post(?string $params = ''): void {
        $this->exigirPermisoVencimientos();

        $ruta = trim((string)$params, '/');
        if (!preg_match('#^vencimientos/([^/]+)/revisiones$#i', $ruta, $m)) {
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'RESOURCE_NOT_FOUND',
                'message' => "Operación de alertas desconocida: '{$params}'.",
                'errors' => new \stdClass(),
                'data' => null,
            ], 404);
        }

        $idRaw = trim((string)$m[1]);
        if ($idRaw === '' || !ctype_digit($idRaw) || (int)$idRaw <= 0) {
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'INVALID_ID',
                'message' => 'El identificador del aviso debe ser un entero positivo.',
                'errors' => ['id' => 'Identificador inválido.'],
                'data' => null,
            ], 400);
        }

        $data = $this->getInput();
        if (empty($data)) {
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'EMPTY_PAYLOAD',
                'message' => 'Cuerpo de solicitud JSON vacío o no válido.',
                'errors' => new \stdClass(),
                'data' => null,
            ], 400);
        }

        $refHeader = $_SERVER['HTTP_X_REQUEST_REFERENCE'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;

        try {
            $resultado = $this->alertaService->registrarRevisionAviso(
                (int)$idRaw,
                $data,
                is_string($refHeader) ? $refHeader : null,
                (int)$this->authenticatedUserId
            );
            $httpCode = !empty($resultado['idempotent_replay']) ? 200 : 201;
            $this->sendJsonResponse($resultado, $httpCode);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpStatus());
        } catch (Throwable $e) {
            error_log('Error en AlertaApiController::post: ' . $e->getMessage());
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'INTERNAL_ERROR',
                'message' => 'No se pudo registrar la revisión del aviso.',
                'errors' => new \stdClass(),
                'data' => null,
            ], 500);
        }
    }

    private function tienePermisoVencimientos(): bool {
        $rol = (int)($this->authenticatedUser['id_rol'] ?? ($_SESSION['rol'] ?? 0));
        return RBACMiddleware::tienePermiso($rol, 'inventario.vencimientos');
    }

    private function exigirPermisoVencimientos(): void {
        if (!$this->tienePermisoVencimientos()) {
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'FORBIDDEN',
                'message' => 'No tienes permisos para consultar ni confirmar avisos de vencimiento.',
                'errors' => new \stdClass(),
                'data' => null,
            ], 403);
        }
    }
}
