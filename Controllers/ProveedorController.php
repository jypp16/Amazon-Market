<?php

namespace Controllers;

use Libraries\Core\Controller;

class ProveedorController extends Controller {

    public function index() {
        $data = [
            'page_title' => 'Listado de Proveedores - Amazon Market'
        ];

        $this->views->render($this, "index", $data);
    }

    public function crear() {
        $data = [
            'page_title' => 'Nuevo Proveedor - Amazon Market'
        ];

        $this->views->render($this, "nuevo", $data);
    }

}
