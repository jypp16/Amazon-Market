<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Libraries\Core\InventarioException;
use Libraries\Middleware\RBACMiddleware;
use Models\OperacionInventarioModel;
use Services\InventarioService;
use Throwable;

class InventarioApiController extends ApiController
{
    private InventarioService $inventarioService;
    private OperacionInventarioModel $operacionModel;

    public function __construct()
    {
        parent::__construct();
        $this->inventarioService = new InventarioService();
        $this->operacionModel = new OperacionInventarioModel();
    }

    public function get(?string $params = ''): void
    {
        $this->requireAdminActivo('inventario.ajustes');

        $ruta = trim((string)$params, '/');
        if ($ruta === 'movimientos') {
            $this->listarMovimientos();
            return;
        }

        if (str_starts_with($ruta, 'movimientos/')) {
            $subruta = substr($ruta, strlen('movimientos/'));
            $this->obtenerMovimientoDetalle($subruta);
            return;
        }

        $this->sendJsonResponse([
            'status'  => false,
            'code'    => 'RESOURCE_NOT_FOUND',
            'message' => "Recurso de inventario desconocido: '{$params}'.",
            'errors'  => new \stdClass(),
            'data'    => null,
        ], 404);
    }

    private function listarMovimientos(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) > 120) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_FILTER',
                'message' => 'El texto de búsqueda no puede superar los 120 caracteres.',
                'errors'  => ['q' => 'Supera el límite de 120 caracteres.'],
                'data'    => null,
            ], 400);
        }

        $tipo = trim((string)($_GET['tipo'] ?? ''));
        if ($tipo !== '' && !isset(OperacionInventarioModel::TIPO_A_ORIGEN[$tipo])) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_FILTER',
                'message' => 'El tipo de movimiento indicado no es válido.',
                'errors'  => ['tipo' => 'Tipo no admitido.'],
                'data'    => null,
            ], 400);
        }

        $desde = trim((string)($_GET['desde'] ?? ''));
        $hasta = trim((string)($_GET['hasta'] ?? ''));
        if ($desde !== '' && !$this->esFechaIsoValida($desde)) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_DATE',
                'message' => 'La fecha inicial no tiene un formato válido (YYYY-MM-DD).',
                'errors'  => ['desde' => 'Formato de fecha inválido.'],
                'data'    => null,
            ], 400);
        }
        if ($hasta !== '' && !$this->esFechaIsoValida($hasta)) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_DATE',
                'message' => 'La fecha final no tiene un formato válido (YYYY-MM-DD).',
                'errors'  => ['hasta' => 'Formato de fecha inválido.'],
                'data'    => null,
            ], 400);
        }
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_DATE_RANGE',
                'message' => 'La fecha inicial no puede ser posterior a la fecha final.',
                'errors'  => ['desde' => 'La fecha inicial es posterior a la final.'],
                'data'    => null,
            ], 400);
        }

        $paginaRaw = isset($_GET['pagina']) ? trim((string)$_GET['pagina']) : '1';
        if ($paginaRaw === '' || !ctype_digit($paginaRaw) || (int)$paginaRaw < 1) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_PAGINATION',
                'message' => 'El número de página debe ser un entero positivo.',
                'errors'  => ['pagina' => 'Página inválida.'],
                'data'    => null,
            ], 400);
        }

        $porPaginaRaw = isset($_GET['por_pagina']) ? trim((string)$_GET['por_pagina']) : '10';
        if ($porPaginaRaw === '' || !ctype_digit($porPaginaRaw) || (int)$porPaginaRaw < 1 || (int)$porPaginaRaw > 50) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_PAGINATION',
                'message' => 'El tamaño de página debe ser un entero entre 1 y 50.',
                'errors'  => ['por_pagina' => 'Tamaño de página inválido.'],
                'data'    => null,
            ], 400);
        }

        try {
            $resultado = $this->operacionModel->listarMovimientos([
                'q'          => $q,
                'tipo'       => $tipo,
                'desde'      => $desde,
                'hasta'      => $hasta,
                'pagina'     => (int)$paginaRaw,
                'por_pagina' => (int)$porPaginaRaw,
            ]);

            $this->sendJsonResponse([
                'status'  => true,
                'code'    => 'MOVEMENTS_LISTED',
                'message' => 'Movimientos obtenidos correctamente.',
                'data'    => $resultado,
            ], 200);
        } catch (Throwable $e) {
            error_log('Error al listar movimientos de inventario: ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'No se pudieron consultar los movimientos de inventario.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }

    private function obtenerMovimientoDetalle(string $idRaw): void
    {
        $idLimpio = trim($idRaw);
        if ($idLimpio === '' || !ctype_digit($idLimpio) || (int)$idLimpio <= 0) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_ID',
                'message' => 'El identificador del movimiento debe ser un número entero positivo.',
                'errors'  => ['id' => 'Identificador inválido.'],
                'data'    => null,
            ], 400);
        }

        try {
            $detalle = $this->operacionModel->obtenerMovimientoPorId((int)$idLimpio);
            if ($detalle === null) {
                $this->sendJsonResponse([
                    'status'  => false,
                    'code'    => 'MOVEMENT_NOT_FOUND',
                    'message' => 'El movimiento solicitado no existe.',
                    'errors'  => new \stdClass(),
                    'data'    => null,
                ], 404);
            }

            $this->sendJsonResponse([
                'status'  => true,
                'code'    => 'MOVEMENT_FOUND',
                'message' => 'Detalle del movimiento obtenido correctamente.',
                'data'    => $detalle,
            ], 200);
        } catch (Throwable $e) {
            error_log('Error al obtener detalle de movimiento de inventario: ' . $e->getMessage());
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'No se pudo cargar el detalle del movimiento.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 500);
        }
    }

    private function esFechaIsoValida(string $fecha): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }
        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));
        return checkdate($mes, $dia, $anio);
    }

    public function post(?string $params = ''): void
    {
        $recurso = strtolower(trim((string)$params));
        $soloBajaVencimiento = false;

        if ($recurso === 'bajas' || $recurso === 'baja') {
            $rol = (int)($this->authenticatedUser['id_rol'] ?? ($_SESSION['rol'] ?? 0));
            $esAdmin = ($rol === 1 && RBACMiddleware::tienePermiso(1, 'inventario.ajustes'));
            $puedeBajaVenc = RBACMiddleware::tienePermisoAsignado($rol, 'inventario.bajas_vencimiento');
            if (!$esAdmin && !$puedeBajaVenc) {
                $this->sendJsonResponse([
                    'status' => false,
                    'code' => 'FORBIDDEN',
                    'message' => 'No tienes permisos para registrar bajas de inventario.',
                    'errors' => new \stdClass(),
                    'data' => null,
                ], 403);
            }
            $soloBajaVencimiento = !$esAdmin;
        } else {
            $this->requireAdminActivo('inventario.ajustes');
        }

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
            if (preg_match('#^movimientos/([^/]+)/correcciones$#i', $recurso, $matches)) {
                $idRaw = trim((string)$matches[1]);
                if ($idRaw === '' || !ctype_digit($idRaw) || (int)$idRaw <= 0) {
                    $this->sendJsonResponse([
                        'status' => false,
                        'code' => 'INVALID_ID',
                        'message' => 'El identificador del movimiento debe ser un número entero positivo.',
                        'errors' => ['id' => 'Identificador inválido.'],
                        'data' => null,
                    ], 400);
                }

                $resultado = $this->inventarioService->corregirMovimiento(
                    (int)$idRaw,
                    $data,
                    $referenciaSolicitud,
                    $usuarioAutenticado
                );
                $codigoHttp = !empty($resultado['idempotent_replay']) ? 200 : 201;
                $this->sendJsonResponse($resultado, $codigoHttp);
                return;
            }

            switch ($recurso) {
                case 'bajas':
                case 'baja':
                    $resultado = $this->inventarioService->registrarBaja(
                        $data,
                        $referenciaSolicitud,
                        $usuarioAutenticado,
                        $soloBajaVencimiento
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

