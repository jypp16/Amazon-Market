<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Libraries\Core\InventarioException;
use Services\TurnoService;
use Throwable;

class TurnoApiController extends ApiController
{
    protected bool $allowMultipartFormData = false;
    private TurnoService $turnoService;

    public function __construct()
    {
        parent::__construct();
        $this->turnoService = new TurnoService();
    }

    protected function authorizeController(): void
    {
        $this->requireAdminActivo('turnos.programar');
    }

    /**
     * EP-01: GET /api/turnos
     */
    public function get(?string $params = ''): void
    {
        $this->requireAdminActivo('turnos.programar');

        $segmento = trim((string)$params, '/');
        if ($segmento !== '') {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'ENDPOINT_NOT_FOUND',
                'message' => 'Subruta de turnos no reconocida.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 404);
        }

        try {
            $turnos = $this->turnoService->listarTurnosActivos($_GET);
            $this->sendJsonResponse([
                'status'  => true,
                'code'    => 'OK',
                'message' => 'Turnos obtenidos correctamente.',
                'data'    => $turnos,
            ], 200);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
        } catch (Throwable $e) {
            error_log('[TurnoApiController::get] ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno al consultar el catálogo de turnos.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * EP-02: POST /api/turnos
     */
    public function post(?string $params = ''): void
    {
        $admin = $this->requireAdminActivo('turnos.programar');

        $segmento = trim((string)$params, '/');
        if ($segmento !== '') {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'ENDPOINT_NOT_FOUND',
                'message' => 'Subruta de turnos no reconocida.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 404);
        }

        try {
            $resultado = $this->turnoService->crearTurno(
                $this->getInput(),
                $this->getHeader('X-Request-Reference'),
                (int)$admin['id_usuario']
            );
            $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
        } catch (Throwable $e) {
            error_log('[TurnoApiController::post] ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno al registrar el turno.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * EP-03: PUT /api/turnos/{id}
     */
    public function put(?string $params = ''): void
    {
        $admin = $this->requireAdminActivo('turnos.programar');

        try {
            $idTurno = $this->extraerIdRuta($params);
            $resultado = $this->turnoService->editarTurno(
                $idTurno,
                $this->getInput(),
                $this->getHeader('X-Request-Reference'),
                (int)$admin['id_usuario']
            );
            $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
        } catch (Throwable $e) {
            error_log('[TurnoApiController::put] ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno al actualizar el turno.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * EP-04: DELETE /api/turnos/{id}
     */
    public function delete(?string $params = ''): void
    {
        $admin = $this->requireAdminActivo('turnos.programar');

        try {
            $idTurno = $this->extraerIdRuta($params);
            $resultado = $this->turnoService->eliminarTurno(
                $idTurno,
                $this->getInput(),
                $this->getHeader('X-Request-Reference'),
                (int)$admin['id_usuario']
            );
            $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
        } catch (Throwable $e) {
            error_log('[TurnoApiController::delete] ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno al eliminar el turno.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * Valida y extrae el parámetro {id} de la ruta /api/turnos/{id}.
     */
    private function extraerIdRuta(?string $params): int
    {
        $limpio = trim((string)$params, '/');
        if ($limpio === '') {
            throw new InventarioException(
                'El identificador del turno en la ruta es obligatorio.',
                'VALIDATION_ERROR',
                422,
                ['id' => ['Debe especificar un identificador entero positivo en la ruta (/api/turnos/{id}).']]
            );
        }

        if (str_contains($limpio, '/')) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'ENDPOINT_NOT_FOUND',
                'message' => 'Subruta de turnos no reconocida.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 404);
        }

        if (!preg_match('/^[1-9]\d*$/', $limpio)) {
            throw new InventarioException(
                'El identificador del turno debe ser un entero positivo.',
                'VALIDATION_ERROR',
                422,
                ['id' => ['El parámetro de ruta id debe ser un número entero mayor o igual a 1.']]
            );
        }

        return (int)$limpio;
    }
}
