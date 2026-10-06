<?php

namespace Controllers;

use Libraries\Core\Controller;
use Services\DashboardService;
use Exception;

class HomeController extends Controller {

    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new DashboardService();
    }

    public function index() {
        try {
            $rol = intval($_SESSION['rol'] ?? 0);
            $idUsuario = intval($_SESSION['idUser'] ?? 0);
            $estadisticas = $this->service->obtenerEstadisticas($rol, $idUsuario ?: null);

            $data = array_merge($estadisticas, [
                'page_title' => 'Dashboard - Amazon Market'
            ]);

            $this->views->render($this, "index", $data);
        } catch (Exception $e) {
            error_log('Error en Dashboard: ' . $e->getMessage());
            $_SESSION['error'] = 'Ocurrió un problema al cargar el panel. Intente nuevamente.';
            header("Location: " . BASE_URL . "/Home");
            exit;
        }
    }
}
