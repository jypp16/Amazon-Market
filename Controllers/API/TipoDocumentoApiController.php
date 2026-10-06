<?php

namespace Controllers\API;

use Libraries\Core\ApiController;

class TipoDocumentoApiController extends ApiController
{
    public function get(?string $params = ''): void
    {
        $this->requirePermission('clientes.listar');
        $tipos = $this->model->obtenerActivos();
        $this->sendJsonResponse(['status' => true, 'data' => $tipos]);
    }
}
