<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Models\VentaModel;

class VentaApiController extends ApiController
{
    private VentaModel $ventaModel;

    public function __construct()
    {
        parent::__construct();
        $this->ventaModel = new VentaModel();
    }

public function get(?string $params = ''): void
    {
        $this->requirePermission('ventas.acceder');
        $this->getVentas($params);
    }

    public function post(?string $params = ''): void
    {
        $this->store();
    }

    private function getVentas(?string $id): void
    {
        if (!empty($id)) {
            $venta = $this->ventaModel
                ->where(['id_venta' => (int)$id, 'id_usuario' => $this->authenticatedUserId, 'estado = 1'])
                ->first();

            if (!$venta) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Venta no encontrada.'], 404);
            }

            $this->sendJsonResponse(['status' => true, 'data' => $venta]);
        } else {
            $ventas = $this->ventaModel
                ->where(['id_usuario' => $this->authenticatedUserId, 'estado = 1'])
                ->orderBy('id_venta', 'DESC')
                ->get();

            $this->sendJsonResponse(['status' => true, 'data' => $ventas]);
        }
    }

    private function store(): void
    {
        $this->requirePermission('ventas.procesar');

        $data = $this->getInput();
        $data['id_usuario'] = $this->authenticatedUserId;

        $validator = new \Libraries\Core\Validation($data);
        $validator->required(['id_cliente', 'productos', 'id_tipo_comprobante', 'id_metodo_pago']);

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        if (!is_array($data['productos']) || empty($data['productos'])) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Debe proporcionar un array de productos no vacío.'], 422);
        }

        $service = new \Services\VentaService();
        $resultado = $service->procesarVenta($data);

        $this->sendJsonResponse($resultado, $resultado['status'] ? 201 : 400);
    }
}