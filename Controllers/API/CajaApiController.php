<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Libraries\Core\InventarioException;
use Services\CajaService;
use Throwable;

class CajaApiController extends ApiController
{
    protected bool $allowMultipartFormData = false;
    private CajaService $cajaService;

    public function __construct()
    {
        parent::__construct();
        $this->cajaService = new CajaService();
    }

    protected function authorizeController(): void
    {
        $segmento = $this->extraerSegmentoCaja();
        if (in_array($segmento, ['consulta/vendedores', 'consulta/sesiones', 'consulta'], true)) {
            $this->requireAdminActivo('turnos.consultar_historico');
            return;
        }
        $permiso = $this->resolverPermisoPorSegmento($segmento);
        $this->requireVendedorActivo($permiso);
    }

    private function extraerSegmentoCaja(): string
    {
        $url = trim((string)($_GET['url'] ?? ''), '/');
        $partes = $url === '' ? [] : explode('/', $url);
        if (isset($partes[0]) && strtolower($partes[0]) === 'api') {
            array_shift($partes);
        }
        if (isset($partes[0]) && strtolower($partes[0]) === 'caja') {
            array_shift($partes);
        }
        return implode('/', array_filter($partes, static fn (string $s): bool => $s !== ''));
    }

    private function resolverPermisoPorSegmento(string $segmento): ?string
    {
        if (in_array($segmento, ['turno-hoy', 'estado', 'abrir'], true)) {
            return 'turnos.abrir';
        }
        if (in_array($segmento, [
            'sesion-actual',
            'administradores',
            'turnos-anteriores',
            'ingresos',
            'egresos',
            'movimientos/corregibles',
            'movimientos/correcciones',
        ], true)) {
            return 'turnos.movimientos';
        }
        if (in_array($segmento, [
            'arqueo/estado',
            'arqueos',
            'arqueo',
        ], true)) {
            return 'turnos.arqueo';
        }
        if (in_array($segmento, [
            'cierre',
            'cerrar',
        ], true)) {
            return 'turnos.cerrar';
        }
        if ($segmento === 'consulta/activa') {
            return 'turnos.acceder';
        }
        return null;
    }

    /**
     * EP-07: GET /api/caja/turno-hoy
     * EP-08: GET /api/caja/estado
     * EP-10: GET /api/caja/sesion-actual
     * EP-11: GET /api/caja/administradores
     * EP-12: GET /api/caja/turnos-anteriores
     * EP-15: GET /api/caja/movimientos/corregibles
     */
    public function get(?string $params = ''): void
    {
        $segmento = trim((string)$params, '/');
        if (in_array($segmento, ['consulta/vendedores', 'consulta/sesiones', 'consulta'], true)) {
            $this->requireAdminActivo('turnos.consultar_historico');
            try {
                if ($segmento === 'consulta/vendedores') {
                    $res = $this->cajaService->listarVendedoresConsulta($_GET);
                    $this->sendJsonResponse($res, 200);
                }
                if ($segmento === 'consulta/sesiones') {
                    $res = $this->cajaService->listarSesionesConsulta($_GET);
                    $this->sendJsonResponse($res, 200);
                }
                if ($segmento === 'consulta') {
                    $res = $this->cajaService->consultarDetalleSesionAdmin($_GET);
                    $this->sendJsonResponse($res, 200);
                }
            } catch (InventarioException $e) {
                $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
            } catch (Throwable $e) {
                error_log('[CajaApiController::get] ' . $e->getMessage());
                $this->sendJsonResponse([
                    'status'  => false,
                    'code'    => 'INTERNAL_ERROR',
                    'message' => 'Ocurrió un error interno al consultar la caja.',
                    'errors'  => new \stdClass(),
                    'data'    => null,
                ], 500);
            }
            return;
        }

        $permiso = $this->resolverPermisoPorSegmento($segmento);
        $vendedor = $this->requireVendedorActivo($permiso);

        try {
            if ($segmento === 'turno-hoy') {
                $res = $this->cajaService->consultarTurnoHoy((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'estado') {
                $res = $this->cajaService->consultarEstadoCaja((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'sesion-actual') {
                $res = $this->cajaService->consultarSesionActual((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'administradores') {
                $res = $this->cajaService->listarAdministradoresActivos((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'turnos-anteriores') {
                $res = $this->cajaService->listarTurnosAnteriores((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'movimientos/corregibles') {
                $res = $this->cajaService->listarMovimientosCorregibles((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'arqueo/estado') {
                $res = $this->cajaService->consultarEstadoArqueo((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'arqueos') {
                $res = $this->cajaService->listarArqueos((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'cierre') {
                $res = $this->cajaService->consultarCierre((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            if ($segmento === 'consulta/activa') {
                $res = $this->cajaService->consultarActivaVendedor((int)$vendedor['id_usuario'], $_GET);
                $this->sendJsonResponse($res, 200);
            }

            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'ENDPOINT_NOT_FOUND',
                'message' => 'Subruta de caja no reconocida.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 404);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
        } catch (Throwable $e) {
            error_log('[CajaApiController::get] ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno al consultar la caja.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * EP-09: POST /api/caja/abrir
     * EP-13: POST /api/caja/ingresos
     * EP-14: POST /api/caja/egresos
     * EP-16: POST /api/caja/movimientos/correcciones
     */
    public function post(?string $params = ''): void
    {
        $segmento = trim((string)$params, '/');
        $permiso = $this->resolverPermisoPorSegmento($segmento);
        $vendedor = $this->requireVendedorActivo($permiso);

        try {
            if ($segmento === 'abrir') {
                $cashInspection = $this->inspectRawJsonField('cash_counted');
                $resultado = $this->cajaService->abrirCaja(
                    $this->getInput(),
                    $this->getHeader('X-Request-Reference'),
                    $vendedor,
                    $cashInspection['number_literal'],
                    $cashInspection['count'] > 1
                );
                $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
            }

            if ($segmento === 'ingresos') {
                $amountInspection = $this->inspectRawJsonField('amount');
                $resultado = $this->cajaService->registrarIngreso(
                    $this->getInput(),
                    $this->getHeader('X-Request-Reference'),
                    $vendedor,
                    $amountInspection['number_literal'],
                    $amountInspection['count'] > 1
                );
                $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
            }

            if ($segmento === 'egresos') {
                $amountInspection = $this->inspectRawJsonField('amount');
                $resultado = $this->cajaService->registrarEgreso(
                    $this->getInput(),
                    $this->getHeader('X-Request-Reference'),
                    $vendedor,
                    $amountInspection['number_literal'],
                    $amountInspection['count'] > 1
                );
                $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
            }

            if ($segmento === 'movimientos/correcciones') {
                $amountInspection = $this->inspectRawJsonField('corrected_amount');
                $resultado = $this->cajaService->registrarCorreccion(
                    $this->getInput(),
                    $this->getHeader('X-Request-Reference'),
                    $vendedor,
                    $amountInspection['number_literal'],
                    $amountInspection['count'] > 1
                );
                $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
            }

            if ($segmento === 'arqueo') {
                $countedInspection = $this->inspectRawJsonField('counted');
                $resultado = $this->cajaService->guardarArqueo(
                    $this->getInput(),
                    $this->getHeader('X-Request-Reference'),
                    $vendedor,
                    $countedInspection['number_literal'],
                    $countedInspection['count'] > 1
                );
                $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
            }

            if ($segmento === 'cerrar') {
                $resultado = $this->cajaService->cerrarCaja(
                    $this->getInput(),
                    $this->getHeader('X-Request-Reference'),
                    $vendedor
                );
                $this->sendJsonResponse($resultado['response'], (int)$resultado['http_code']);
            }

            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'ENDPOINT_NOT_FOUND',
                'message' => 'Subruta de caja no reconocida.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 404);
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->toApiResponse(), $e->getHttpCode());
        } catch (Throwable $e) {
            error_log('[CajaApiController::post] ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Ocurrió un error interno al procesar la operación de caja.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }
}
