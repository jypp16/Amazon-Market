<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Models\TipoComprobanteModel;

class TipoComprobanteApiController extends ApiController
{
    public function get(?string $params = ''): void
    {
        $this->requirePermission('ventas.acceder');
        $comprobantes = $this->model->obtenerActivos();
        $this->sendJsonResponse(['status' => true, 'data' => $comprobantes]);
    }
}
