<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Services\AlertaService;

class AlertaApiController extends ApiController {
    private $alertaService;

    public function __construct() {
        parent::__construct();
        $this->alertaService = new AlertaService();
    }

    /**
     * GET /api/alertas/stock
     * GET /api/alertas/vencimientos
     */
    public function get(?string $tipo = ''): void {
        try {
            if ($tipo === 'stock') {
                $alertas = $this->alertaService->obtenerAlertasStock();
                $this->sendJsonResponse(['status' => true, 'message' => 'Alertas de Stock Bajo', 'data' => $alertas], 200);
            } elseif ($tipo === 'vencimientos') {
                $alertas = $this->alertaService->obtenerAlertasVencimiento(15);
                $this->sendJsonResponse(['status' => true, 'message' => 'Alertas de Vencimiento Próximo (15 días)', 'data' => $alertas], 200);
            } else {
                // Si no especifica o especifica algo inválido, retornar ambas
                $stock = $this->alertaService->obtenerAlertasStock();
                $vencimientos = $this->alertaService->obtenerAlertasVencimiento(15);
                $this->sendJsonResponse([
                    'status' => true, 
                    'message' => 'Resumen de Alertas', 
                    'data' => [
                        'stock_bajo' => $stock,
                        'vencimientos' => $vencimientos
                    ]
                ], 200);
            }
        } catch (\Exception $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }
}

