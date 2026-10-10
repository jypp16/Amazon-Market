<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Services\CompraService;

class CompraApiController extends ApiController {
    private $compraService;

    public function __construct() {
        parent::__construct();
        $this->compraService = new CompraService();
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
            $camposRequeridosCabecera = ['id_proveedor', 'id_tipo_comprobante', 'serie', 'numero', 'fecha_emision', 'total_documento', 'detalles'];
            foreach ($camposRequeridosCabecera as $campo) {
                if (!isset($input[$campo])) {
                    $this->sendJsonResponse(['status' => false, 'message' => "Falta el campo requerido: $campo"], 400);
                }
            }

            if (empty($input['detalles']) || !is_array($input['detalles'])) {
                $this->sendJsonResponse(['status' => false, 'message' => 'La compra debe tener al menos un detalle válido.'], 400);
            }

            // Validaciones básicas de detalles
            foreach ($input['detalles'] as $index => $detalle) {
                $camposRequeridosDetalle = ['id_producto', 'id_presentacion', 'cantidad_presentaciones', 'equivalencia_aplicada', 'importe_final_linea'];
                foreach ($camposRequeridosDetalle as $campo) {
                    if (!isset($detalle[$campo])) {
                        $this->sendJsonResponse(['status' => false, 'message' => "Falta el campo '$campo' en el detalle índice $index."], 400);
                    }
                }
                
                if ($detalle['cantidad_presentaciones'] <= 0 || $detalle['equivalencia_aplicada'] <= 0) {
                    $this->sendJsonResponse(['status' => false, 'message' => "Las cantidades y equivalencias deben ser mayores a cero en el detalle índice $index."], 400);
                }
                
                if ($detalle['importe_final_linea'] < 0) {
                    $this->sendJsonResponse(['status' => false, 'message' => "El importe no puede ser negativo en el detalle índice $index."], 400);
                }
            }

            // Usar el ID de usuario autenticado del ApiController
            $id_usuario = $this->authenticatedUserId ?? 1;

            $detalles = $input['detalles'];
            unset($input['detalles']);
            $cabecera = $input;

            $resultado = $this->compraService->registrarCompra($cabecera, $detalles, $id_usuario);

            if ($resultado['status']) {
                $this->sendJsonResponse($resultado, 201); // Created
            } else {
                $this->sendJsonResponse($resultado, 400); // Bad Request
            }

        } catch (\Exception $e) {
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
                $busqueda = trim($this->getParam('search', ''));
                $pagina = max(1, (int)$this->getParam('page', 1));
                $porPagina = max(1, (int)$this->getParam('limit', 10));

                $resultado = $this->compraService->obtenerComprasPaginado($busqueda, $pagina, $porPagina);
                $this->sendJsonResponse([
                    'status' => true,
                    'message' => 'Lista de compras',
                    'data' => $resultado['data'],
                    'total' => $resultado['total'],
                    'paginas' => $resultado['paginas'],
                    'pagina_actual' => $resultado['pagina_actual'],
                    'por_pagina' => $resultado['por_pagina']
                ], 200);
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * PUT /api/compras/{id}
     */
    public function put(?string $id = ''): void {
        try {
            $this->requirePermission('compras.editar');
            if (empty($id)) {
                throw new \Exception("ID de la compra es requerido para corregir.");
            }

            $input = $this->getInput();
            if (empty($input) || empty($input['detalles']) || !is_array($input['detalles'])) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Datos inválidos para la corrección.'], 400);
            }

            // Validaciones básicas de detalles para PUT
            foreach ($input['detalles'] as $index => $detalle) {
                if (isset($detalle['cantidad_presentaciones']) && $detalle['cantidad_presentaciones'] <= 0) {
                    $this->sendJsonResponse(['status' => false, 'message' => "Las cantidades deben ser mayores a cero en el detalle índice $index."], 400);
                }
                if (isset($detalle['equivalencia_aplicada']) && $detalle['equivalencia_aplicada'] <= 0) {
                    $this->sendJsonResponse(['status' => false, 'message' => "Las equivalencias deben ser mayores a cero en el detalle índice $index."], 400);
                }
                if (isset($detalle['importe_final_linea']) && $detalle['importe_final_linea'] < 0) {
                    $this->sendJsonResponse(['status' => false, 'message' => "El importe no puede ser negativo en el detalle índice $index."], 400);
                }
            }

            $id_usuario = $this->authenticatedUserId ?? 1;
            
            $detalles = $input['detalles'];
            unset($input['detalles']);
            $cabecera = $input;

            $resultado = $this->compraService->corregirCompra((int)$id, $cabecera, $detalles, $id_usuario);

            if ($resultado['status']) {
                $this->sendJsonResponse($resultado, 200);
            } else {
                $this->sendJsonResponse($resultado, 400);
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * DELETE /api/compras/{id}
     */
    public function delete(?string $id = ''): void {
        try {
            $this->requirePermission('compras.eliminar');
            if (empty($id)) {
                throw new \Exception("ID de la compra es requerido para anular.");
            }

            $id_usuario = $this->authenticatedUserId ?? 1;
            $resultado = $this->compraService->anularCompra((int)$id, $id_usuario);

            if ($resultado['status']) {
                $this->sendJsonResponse($resultado, 200);
            } else {
                $this->sendJsonResponse($resultado, 400);
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
