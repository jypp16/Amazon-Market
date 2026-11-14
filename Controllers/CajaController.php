<?php

namespace Controllers;

use Libraries\Core\Controller;

class CajaController extends Controller {
    public function abrir() {
        $this->views->render($this, 'abrir', [
            'page_title' => 'Abrir caja - Amazon Market'
        ]);
    }

    public function movimientos() {
        $this->views->render($this, 'movimientos', [
            'expense_types' => ['Salida de caja', 'Retiro del administrador', 'Retiro omitido'],
            'page_title' => 'Movimientos de caja - Amazon Market'
        ]);
    }

    public function arqueo() {
        $this->views->render($this, 'arqueo', [
            'page_title' => 'Arqueo y cierre - Amazon Market'
        ]);
    }

    public function consultar() {
        $role = (int)($_SESSION['rol'] ?? 0);
        $isAdministrator = $role === 1;
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('America/Lima')))->format('Y-m-d');

        $this->views->render($this, 'consultar', [
            'is_administrator' => $isAdministrator,
            'page_size' => 10,
            'default_date' => $today,
            'page_title' => 'Consultar caja - Amazon Market'
        ]);
    }
}
