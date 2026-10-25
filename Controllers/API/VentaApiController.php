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

    private function extraerReferenciaSolicitud(array $input): ?string {
        if (!empty($input['referencia_solicitud']) && is_string($input['referencia_solicitud'])) {
            return ControlSolicitudService::validarReferenciaSolicitud($input['referencia_solicitud']);
        }
        $headerRef = $_SERVER['HTTP_X_REQUEST_REFERENCE'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
        if (!empty($headerRef) && is_string($headerRef)) {
            return ControlSolicitudService::validarReferenciaSolicitud($headerRef);
        }
        throw InventarioException::validacion(['referencia_solicitud' => 'La referencia de solicitud es obligatoria para evitar operaciones duplicadas.']);
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

            $data = $this->getInput();
            if (empty($data)) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Cuerpo JSON inválido o vacío.'], 400);
            }

            // Seguridad de inquilino (Task 8): El cliente jamás puede suplantar al responsable
            $data['id_usuario'] = (int)$this->authenticatedUserId;

            $referenciaSolicitud = $this->extraerReferenciaSolicitud($data);

            $resultado = $this->ventaService->procesarVenta($data, $referenciaSolicitud);

            $this->sendJsonResponse($resultado, !empty($resultado['status']) ? (!empty($resultado['idempotent_replay']) ? 200 : 201) : 400);

        } catch (InventarioException $e) {
            $this->sendJsonResponse($e->asResponseArray(), $e->getStatusCode());
        } catch (Throwable $e) {
            $this->sendJsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
