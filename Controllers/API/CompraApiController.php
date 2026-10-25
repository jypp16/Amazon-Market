<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Libraries\Core\InventarioException;
use Services\CompraService;
use Throwable;

class CompraApiController extends ApiController {
    private CompraService $compraService;

    public function __construct() {
        parent::__construct();
        $this->compraService = new CompraService();
    }

    private function extraerReferenciaSolicitud(array $input): ?string {
        if (!empty($input['referencia_solicitud']) && is_string($input['referencia_solicitud'])) {
            return trim($input['referencia_solicitud']);
        }
        $headerRef = $_SERVER['HTTP_X_REQUEST_REFERENCE'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
        if (!empty($headerRef) && is_string($headerRef)) {
            return trim($headerRef);
        }
        return null;
    }

    /**
     * POST /api/compraapi
     */
    public function post(?string $id = ''): void {
        try {
            $this->requirePermission('compras.crear');
            $input = $this->getInput();

            if (empty($input)) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Cuerpo JSON inválido o vacío.'], 400);
            }

            // Validaciones básicas de cabecera
            $camposRequeridosCabecera = ['id_proveedor', 'id_tipo_comprobante', 'serie', 'numero', 'detalles'];
            foreach ($camposRequeridosCabecera as $campo) {
                if (!isset($input[$campo])) {
                    $this->sendJsonResponse(['status' => false, 'message' => "Falta el campo requerido: {$campo}"], 400);
                }
            }

            if (empty($input['detalles']) || !is_array($input['detalles'])) {
                $this->sendJsonResponse(['status' => false, 'message' => 'La compra debe tener al menos un detalle válido.'], 400);
            }

            $referenciaSolicitud = $this->extraerReferenciaSolicitud($input);
            $id_usuario = (int)($this->authenticatedUserId ?? 1);

            $detalles = $input['detalles'];
            unset($input['detalles'], $input['referencia_solicitud']);
            $cabecera = $input;

            $resultado = $this->compraService->registrarCompra($cabecera, $detalles, $id_usuario, $referenciaSolicitud);

            if (!empty($resultado['status'])) {
                $this->sendJsonResponse($resultado, 201);
            } else {
                $this->sendJsonResponse($resultado, 400);
            }

        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->asResponseArray(), $e->getStatusCode());
        } catch (Throwable $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/compras
     * GET /api/compras/{id}
     */
    public function get(?string $id = ''): void {
        try {
            $this->requirePermission('compras.listar');
            if (!empty($id)) {
                $compra = $this->compraService->obtenerCompra((int)$id);
                if (!$compra) {
                    $this->sendJsonResponse(['status' => false, 'message' => 'Compra no encontrada'], 404);
                }
                $this->sendJsonResponse(['status' => true, 'message' => 'Detalle de compra', 'data' => $compra], 200);
            } else {
                $busqueda = $this->getParam('search', '');
                $pagina = max(1, (int)$this->getParam('page', 1));
                $porPagina = max(1, (int)$this->getParam('limit', 10));
                $filtros = array_intersect_key($_GET, array_flip(['proveedor', 'fecha_desde', 'fecha_hasta', 'estados', 'estado']));

                $resultado = $this->compraService->obtenerComprasPaginado($busqueda, $pagina, $porPagina, $filtros);
                $this->sendJsonResponse([
                    'status' => true,
                    'message' => 'Lista de compras',
                    'data' => $resultado['data'],
                    'resumen' => $resultado['resumen'],
                    'filtros' => $resultado['filtros'],
                    'total' => $resultado['total'],
                    'paginas' => $resultado['paginas'],
                    'pagina_actual' => $resultado['pagina_actual'],
                    'por_pagina' => $resultado['por_pagina']
                ], 200);
            }
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->asResponseArray(), $e->getStatusCode());
        } catch (Throwable $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * PUT /api/compras/{id}
     */
    public function put(?string $id = ''): void {
        try {
            $this->requireAdminActivo('compras.editar');
            if (empty($id)) {
                $this->sendJsonResponse(['status' => false, 'message' => 'ID de la compra es requerido para corregir.'], 400);
            }

            $input = $this->getInput();
            if (empty($input) || empty($input['detalles']) || !is_array($input['detalles'])) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Datos inválidos para la corrección.'], 400);
            }

            $motivo = trim($input['motivo'] ?? '');
            if (empty($motivo)) {
                $this->sendJsonResponse(['status' => false, 'message' => 'El motivo de corrección es obligatorio.'], 400);
            }

            $referenciaSolicitud = $this->extraerReferenciaSolicitud($input);
            $id_usuario = (int)($this->authenticatedUserId ?? 1);

            $detalles = $input['detalles'];
            unset($input['detalles'], $input['motivo'], $input['referencia_solicitud']);
            $cabecera = $input;

            $resultado = $this->compraService->corregirCompra((int)$id, $cabecera, $detalles, $id_usuario, $motivo, $referenciaSolicitud);

            if (!empty($resultado['status'])) {
                $this->sendJsonResponse($resultado, 200);
            } else {
                $this->sendJsonResponse($resultado, 400);
            }
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->asResponseArray(), $e->getStatusCode());
        } catch (Throwable $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * DELETE /api/compras/{id}
     */
    public function delete(?string $id = ''): void {
        try {
            $this->requireAdminActivo('compras.eliminar');
            if (empty($id)) {
                $this->sendJsonResponse(['status' => false, 'message' => 'ID de la compra es requerido para anular.'], 400);
            }

            $input = $this->getInput();
            $motivo = trim($input['motivo'] ?? '');
            if (empty($motivo)) {
                $this->sendJsonResponse(['status' => false, 'message' => 'El motivo de anulación es obligatorio.'], 400);
            }

            $referenciaSolicitud = $this->extraerReferenciaSolicitud($input);
            $id_usuario = (int)($this->authenticatedUserId ?? 1);

            $resultado = $this->compraService->anularCompra((int)$id, $id_usuario, $motivo, $referenciaSolicitud);

            if (!empty($resultado['status'])) {
                $this->sendJsonResponse($resultado, 200);
            } else {
                $this->sendJsonResponse($resultado, 400);
            }
        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->asResponseArray(), $e->getStatusCode());
        } catch (Throwable $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
