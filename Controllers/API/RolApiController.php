<?php

namespace Controllers\API;

use Libraries\Core\ApiController;

class RolApiController extends ApiController
{
    public function get(?string $params = ''): void
    {
        $this->requirePermission('usuarios.listar');
        $roles = $this->model->obtenerActivos();
        $this->sendJsonResponse(['status' => true, 'data' => $roles]);
    }
}
