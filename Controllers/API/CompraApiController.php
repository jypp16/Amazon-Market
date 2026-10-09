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
}
