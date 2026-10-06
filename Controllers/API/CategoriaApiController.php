<?php

namespace Controllers\API;

use Libraries\Core\ApiController;

class CategoriaApiController extends ApiController {

    public function get(?string $params = ""): void {
        $this->requirePermission('productos.listar');
        $this->getCategorias($params);
    }

    public function post(?string $params = ""): void {
        $this->createCategoria();
    }

    public function put(?string $params = ""): void {
        $this->updateCategoria($params);
    }

    public function delete(?string $params = ""): void {
        $this->deleteCategoria($params);
    }

    private function getCategorias(?string $id): void {
        if (!empty($id)) {
            $idVal = intval($id);
            $categoria = $this->model->find($idVal);

            if (!$categoria || $categoria['estado'] != 1) {
                $this->sendJsonResponse(['status' => false, 'message' => 'La categoría solicitada no existe'], 404);
            }
            $this->sendJsonResponse(['status' => true, 'data' => $categoria]);
        } else {
            $categorias = $this->model
                ->where(['estado = 1'])
                ->orderBy('nombre', 'ASC')
                ->get();
            $this->sendJsonResponse(['status' => true, 'data' => $categorias]);
        }
    }

    private function createCategoria(): void {
        $this->requirePermission('productos.crear');

        $data = $this->getInput();

        if (empty($data['nombre'])) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El nombre es obligatorio'], 400);
        }

        $validator = new \Libraries\Core\Validation($data);
        $validator->required(['nombre'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->unique('nombre', $this->model);

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        $categoriaData = [
            'nombre' => trim($data['nombre']),
            'estado' => 1
        ];

        $exito = $this->model->insert($categoriaData);

        if ($exito) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Categoría registrada exitosamente'], 201);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Ocurrió un error interno en el servidor'], 500);
        }
    }

    private function updateCategoria(?string $id): void {
        $this->requirePermission('productos.editar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador de la categoría es requerido'], 400);
        }

        $idVal = intval($id);
        $categoriaExistente = $this->model->find($idVal);

        if (!$categoriaExistente || $categoriaExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El recurso a modificar no existe'], 404);
        }

        $data = $this->getInput();
        $datosActualizar = [];

        if (isset($data['nombre'])) $datosActualizar['nombre'] = trim($data['nombre']);

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
            $this->sendJsonResponse(['status' => true, 'message' => 'Categoría actualizada de forma exitosa']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se pudo actualizar el registro en el servidor'], 500);
        }
    }

    private function deleteCategoria(?string $id): void {
        $this->requirePermission('productos.eliminar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador es requerido'], 400);
        }

        $idVal = intval($id);
        $categoriaExistente = $this->model->find($idVal);

        if (!$categoriaExistente || $categoriaExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El recurso no fue encontrado'], 404);
        }

        $exito = $this->model->update($idVal, ['estado' => 0]);

        if ($exito) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Categoría eliminada correctamente']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Falló la eliminación interna del recurso'], 500);
        }
    }
}
