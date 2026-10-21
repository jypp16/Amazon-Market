<?php

namespace Controllers;

use Libraries\Core\Controller;

class InventarioController extends Controller {

    public function ajustes() {
        $data = ['page_title' => 'Ajustes de inventario - Amazon Market'];
        $this->views->render($this, 'ajustes', $data);
    }
}
