<?php

namespace Controllers\API;

use Services\ControlSolicitudService;

use Libraries\Core\ApiController;
use Libraries\Core\InventarioException;
use Models\VentaModel;
use Services\VentaService;
use Throwable;

class VentaApiController extends ApiController
{
    private VentaModel $ventaModel;
    private VentaService $ventaService;

    public function __construct()
    {
        parent::__construct();
        $this->ventaModel = new VentaModel();
        $this->ventaService = new VentaService();
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

    private function extraerReferenciaSolicitud(array $input): string {
        $headerRef = isset($_SERVER['HTTP_X_REQUEST_REFERENCE']) && is_string($_SERVER['HTTP_X_REQUEST_REFERENCE'])
            ? $_SERVER['HTTP_X_REQUEST_REFERENCE']
            : null;

        return ControlSolicitudService::resolverReferenciaCuerpoOCabecera($input, $headerRef);
    }

    private function getVentas(?string $id): void
    {
        try {
            $esAdmin = (int)($_SESSION['rol'] ?? 0) === 1;

            if (!empty($id)) {
                $condiciones = ['id_venta' => (int)$id, 'estado = 1'];
                if (!$esAdmin) {
                    $condiciones['id_usuario'] = (int)$this->authenticatedUserId;
                }

                $venta = $this->ventaModel
                    ->where($condiciones)
                    ->first();

                if (!$venta) {
                    $this->sendJsonResponse(['status' => false, 'message' => 'Venta no encontrada.'], 404);
                }

                $this->sendJsonResponse(['status' => true, 'data' => $venta]);
            } else {
                $condiciones = ['estado = 1'];
                if (!$esAdmin) {
                    $condiciones['id_usuario'] = (int)$this->authenticatedUserId;
                }

                $ventas = $this->ventaModel
                    ->where($condiciones)
                    ->orderBy('id_venta', 'DESC')
                    ->get();

                $this->sendJsonResponse(['status' => true, 'data' => $ventas]);
            }
        } catch (Throwable $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }

    private function store(): void
    {
        try {
            $this->requirePermission('ventas.procesar');

            $idRol = (int)($this->authenticatedUser['id_rol'] ?? $_SESSION['rol'] ?? 0);
            if ($idRol !== 2) {
                $this->sendJsonResponse([
                    'status' => false,
                    'code' => 'FORBIDDEN',
                    'message' => 'Solo un usuario con rol Vendedor puede registrar ventas en POS.',
                    'errors' => new \stdClass(),
                    'data' => null,
                ], 403);
            }

            $data = $this->getInput();

            // Seguridad de inquilino (Task 8 / Paso 9): El cliente jamás puede suplantar al responsable ni la sesión de caja
            $data['id_usuario'] = (int)$this->authenticatedUserId;
            unset($data['id_sesion_caja']);

            $referenciaSolicitud = $this->extraerReferenciaSolicitud($data);

            $resultado = $this->ventaService->procesarVenta($data, $referenciaSolicitud);

            $this->sendJsonResponse($resultado, !empty($resultado['status']) ? (!empty($resultado['idempotent_replay']) ? 200 : 201) : 400);

        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->asResponseArray(), $e->getStatusCode());
        } catch (Throwable $e) {
            error_log('VentaApiController::store error: ' . $e->getMessage());
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'INTERNAL_ERROR',
                'message' => 'Error interno al procesar la venta.',
                'errors' => new \stdClass(),
                'data' => null,
            ], 500);
        }
    }
}
