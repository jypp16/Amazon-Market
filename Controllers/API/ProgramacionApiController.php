<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Libraries\Core\InventarioException;
use Services\ProgramacionService;
use Throwable;

class ProgramacionApiController extends ApiController
{
    protected bool $allowMultipartFormData = false;
    private ProgramacionService $programacionService;

    public function __construct()
    {
        parent::__construct();
        $this->programacionService = new ProgramacionService();
    }

    protected function authorizeController(): void
    {
        $this->requireAdminActivo('turnos.programar');
    }

    /**
     * EP-05: GET /api/programaciones?usuario_id={id}&semana_inicio={YYYY-MM-DD}
     */
    public function get(?string $params = ''): void
    {
        $this->requireAdminActivo('turnos.programar');

        $segmento = trim((string)$params, '/');
        if ($segmento !== '') {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'ENDPOINT_NOT_FOUND',
                'message' => 'Subruta de programaciones no reconocida.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 404);
        }

        try {
            $respuesta = $this->programacionService->consultarProgramacionSemanal($_GET);
            $this->sendJsonResponse($respuesta, 200);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
        } catch (Throwable $e) {
            error_log('[ProgramacionApiController::get] ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno al consultar la programación semanal.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * EP-06: PUT /api/programaciones
     */
    public function put(?string $params = ''): void
    {
        $admin = $this->requireAdminActivo('turnos.programar');

        $segmento = trim((string)$params, '/');
        if ($segmento !== '') {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'ENDPOINT_NOT_FOUND',
                'message' => 'Subruta de programaciones no reconocida.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 404);
        }

        try {
            $resultado = $this->programacionService->guardarProgramacionSemanal(
                $this->getInput(),
                $this->getHeader('X-Request-Reference'),
                (int)$admin['id_usuario']
            );
            $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
        } catch (Throwable $e) {
            error_log('[ProgramacionApiController::put] ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno al guardar la programación semanal.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }
}
