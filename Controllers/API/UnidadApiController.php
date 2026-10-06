<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Models\UnidadMedidaModel;

class UnidadApiController extends ApiController {

    public function __construct() {
        parent::__construct();
        $this->model = new UnidadMedidaModel();
    }

    public function get(?string $params = ""): void {
        $this->requirePermission('productos.listar');
        $this->getUnidades($params);
    }

    public function post(?string $params = ""): void {
        $this->createUnidad();
    }

    public function put(?string $params = ""): void {
        $this->updateUnidad($params);
    }

    public function delete(?string $params = ""): void {
        $this->deleteUnidad($params);
    }

    private function getUnidades(?string $id): void {
        if (!empty($id)) {
            $idVal = intval($id);
            $unidad = $this->model->find($idVal);

            if (!$unidad || $unidad['estado'] != 1) {
                $this->sendJsonResponse(['status' => false, 'message' => 'La unidad solicitada no existe'], 404);
            }
            $this->sendJsonResponse(['status' => true, 'data' => $unidad]);
        } else {
            $unidades = $this->model
                ->where(['estado = 1'])
                ->orderBy('nombre', 'ASC')
                ->get();
            $this->sendJsonResponse(['status' => true, 'data' => $unidades]);
        }
    }

    private function createUnidad(): void {
        $this->requirePermission('productos.crear');

        $data = $this->getInput();

        if (empty($data['nombre']) || empty($data['abreviatura'])) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Nombre y abreviatura son obligatorios'], 400);
        }

        $validator = new \Libraries\Core\Validation($data);
        $validator->required(['nombre', 'abreviatura'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->maxLength('abreviatura', 10)
            ->unique('nombre', $this->model);

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        $unidadData = [
            'nombre' => trim($data['nombre']),
            'abreviatura' => trim($data['abreviatura']),
            'estado' => 1
        ];

        $exito = $this->model->insert($unidadData);

        if ($exito) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Unidad de medida registrada exitosamente'], 201);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Ocurrió un error interno en el servidor'], 500);
        }
    }

    private function updateUnidad(?string $id): void {
        $this->requirePermission('productos.editar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador de la unidad es requerido'], 400);
        }

        $idVal = intval($id);
        $unidadExistente = $this->model->find($idVal);

        if (!$unidadExistente || $unidadExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El recurso a modificar no existe'], 404);
        }

        $data = $this->getInput();
        $datosActualizar = [];

        if (isset($data['nombre'])) $datosActualizar['nombre'] = trim($data['nombre']);
        if (isset($data['abreviatura'])) $datosActualizar['abreviatura'] = trim($data['abreviatura']);

        if (empty($datosActualizar)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se proporcionaron datos para actualizar'], 400);
        }

        if (isset($data['nombre'])) {
            $validator = new \Libraries\Core\Validation($data);
            $validator->unique('nombre', $this->model, $idVal);
            if ($validator->fails()) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
            }
        }

        $exito = $this->model->update($idVal, $datosActualizar);

        if ($exito) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Unidad de medida actualizada de forma exitosa']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se pudo actualizar el registro en el servidor'], 500);
        }
    }

    private function deleteUnidad(?string $id): void {
        $this->requirePermission('productos.eliminar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador es requerido'], 400);
        }

        $idVal = intval($id);
        $unidadExistente = $this->model->find($idVal);

        if (!$unidadExistente || $unidadExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El recurso no fue encontrado'], 404);
        }

        $exito = $this->model->update($idVal, ['estado' => 0]);

        if ($exito) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Unidad de medida eliminada correctamente']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Falló la eliminación interna del recurso'], 500);
        }
    }
}
