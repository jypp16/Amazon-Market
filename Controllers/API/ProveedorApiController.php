<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Services\ProveedorService;

class ProveedorApiController extends ApiController {
    private $proveedorService;

    public function __construct() {
        parent::__construct();
        $this->proveedorService = new ProveedorService();
    }

    /**
     * GET /api/proveedores
     * GET /api/proveedores/{id}
     */
    public function get(?string $id = ''): void {
        try {
            if (!empty($id)) {
                $proveedor = $this->proveedorService->obtenerProveedor((int)$id);
                $this->sendJsonResponse(['status' => true, 'message' => 'Proveedor encontrado', 'data' => $proveedor], 200);
            } else {
                $busqueda = trim($_GET['search'] ?? '');
                $pagina = max(1, (int)($_GET['page'] ?? 1));
                $porPagina = max(1, (int)($_GET['limit'] ?? 10));

                $resultado = $this->proveedorService->obtenerProveedoresPaginado($busqueda, $pagina, $porPagina);
                $this->sendJsonResponse([
                    'status' => true,
                    'message' => 'Lista de proveedores',
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
     * POST /api/proveedores
     */
    public function post(?string $id = ''): void {
        try {
            $datos = $this->getInput();
            $newId = $this->proveedorService->registrarProveedor($datos);
            
            $this->sendJsonResponse(['status' => true, 'message' => 'Proveedor registrado exitosamente', 'data' => ['id_proveedor' => $newId]], 201);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * PUT /api/proveedores/{id}
     */
    public function put(?string $id = ''): void {
        try {
            if (empty($id)) {
                throw new \Exception("ID del proveedor es requerido para actualizar.");
            }
            
            $datos = $this->getInput();
            $this->proveedorService->actualizarProveedor((int)$id, $datos);
            
            $this->sendJsonResponse(['status' => true, 'message' => 'Proveedor actualizado exitosamente'], 200);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * DELETE /api/proveedores/{id}
     */
    public function delete(?string $id = ''): void {
        try {
            if (empty($id)) {
                throw new \Exception("ID del proveedor es requerido para desactivar.");
            }
            
            $this->proveedorService->desactivarProveedor((int)$id);
            
            $this->sendJsonResponse(['status' => true, 'message' => 'Proveedor desactivado exitosamente'], 200);
        } catch (\Exception $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
