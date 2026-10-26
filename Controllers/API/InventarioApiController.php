<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Libraries\Core\InventarioException;
use Services\InventarioService;
use Throwable;

class InventarioApiController extends ApiController
{
    private InventarioService $inventarioService;

    public function __construct()
    {
        parent::__construct();
        $this->inventarioService = new InventarioService();
    }

    public function post(?string $params = ''): void
    {
        $this->requireAdminActivo('inventario.ajustes');

        $recurso = strtolower(trim((string)$params));
        $data = $this->getInput();

        if (empty($data)) {
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'EMPTY_PAYLOAD',
                'message' => 'Cuerpo de solicitud JSON vacío o no válido.'
            ], 400);
        }

        $referenciaSolicitud = $this->extraerReferenciaSolicitud($data);
        $usuarioAutenticado = (int)$this->authenticatedUserId;

        try {
            switch ($recurso) {
                case 'bajas':
                case 'baja':
                    $resultado = $this->inventarioService->registrarBaja(
                        $data,
                        $referenciaSolicitud,
                        $usuarioAutenticado
                    );
                    $codigoHttp = !empty($resultado['idempotent_replay']) ? 200 : 201;
                    $this->sendJsonResponse($resultado, $codigoHttp);
                    break;

                case 'ajustes':
                case 'ajuste':
                    $resultado = $this->inventarioService->registrarAjuste(
                        $data,
                        $referenciaSolicitud,
                        $usuarioAutenticado
                    );
                    $codigoHttp = !empty($resultado['idempotent_replay']) ? 200 : 201;
                    $this->sendJsonResponse($resultado, $codigoHttp);
                    break;

                default:
                    $this->sendJsonResponse([
                        'status' => false,
                        'code' => 'RESOURCE_NOT_FOUND',
                        'message' => "Operación de inventario desconocida: '{$params}'."
                    ], 404);
                    break;
            }
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpStatus());
        } catch (Throwable $e) {
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'INTERNAL_ERROR',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function extraerReferenciaSolicitud(array $input): ?string
    {
        if (!empty($input['referencia_solicitud']) && is_string($input['referencia_solicitud'])) {
            return trim($input['referencia_solicitud']);
        }
        $headerRef = $_SERVER['HTTP_X_REQUEST_REFERENCE'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
        if (!empty($headerRef) && is_string($headerRef)) {
            return trim($headerRef);
        }
        return null;
    }
}

