<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Models\MetodoPagoModel;

class MetodoPagoApiController extends ApiController
{
    public function get(?string $params = ''): void
    {
        $this->requirePermission('ventas.acceder');
        $pagos = $this->model->obtenerActivos();
        $this->sendJsonResponse(['status' => true, 'data' => $pagos]);
    }
}
