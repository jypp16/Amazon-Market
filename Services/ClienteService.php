<?php

namespace Services;

use Models\ClienteModel;
use Libraries\Core\Validation;

class ClienteService {

    private $model;

    public function __construct() {
        $this->model = new ClienteModel();
    }

    public function crear(array $input): array {
        $validator = new Validation($input);
        $validator->required(['nombre', 'nro_documento', 'id_tipo_documento'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->minLength('nro_documento', 3)
            ->maxLength('nro_documento', 20)
            ->maxLength('direccion', 150)
            ->maxLength('email', 100)
            ->email('email')
            ->phone('telefono')
            ->unique('nro_documento', $this->model);

        if ($validator->fails()) {
            return ['status' => false, 'errors' => $validator->errors()];
        }

        $clienteData = [
            'id_tipo_documento' => intval($input['id_tipo_documento'] ?? 1),
            'nro_documento' => trim($input['nro_documento']),
            'nombre' => trim($input['nombre']),
            'direccion' => trim($input['direccion'] ?? ''),
            'telefono' => trim($input['telefono'] ?? ''),
            'email' => trim($input['email'] ?? ''),
            'estado' => 1
        ];

        if ($this->model->insert($clienteData)) {
            return ['status' => true, 'message' => 'Cliente creado con éxito.'];
        }
        return ['status' => false, 'message' => 'Error al guardar el cliente.'];
    }

    public function actualizar(int $id, array $input): array {
        $validator = new Validation($input);
        $validator->required(['nombre', 'nro_documento', 'id_tipo_documento'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->minLength('nro_documento', 3)
            ->maxLength('nro_documento', 20)
            ->maxLength('direccion', 150)
            ->maxLength('email', 100)
            ->email('email')
            ->phone('telefono')
            ->unique('nro_documento', $this->model, $id);

        if ($validator->fails()) {
            return ['status' => false, 'errors' => $validator->errors()];
        }

        $clienteData = [
            'id_tipo_documento' => intval($input['id_tipo_documento'] ?? 1),
            'nro_documento' => trim($input['nro_documento']),
            'nombre' => trim($input['nombre']),
            'direccion' => trim($input['direccion'] ?? ''),
            'telefono' => trim($input['telefono'] ?? ''),
            'email' => trim($input['email'] ?? '')
        ];

        if ($this->model->update($id, $clienteData)) {
            return ['status' => true, 'message' => 'Cliente actualizado con éxito.'];
        }
        return ['status' => false, 'message' => 'Error al actualizar el cliente.'];
    }

    public function eliminar(int $id): array {
        if ($this->model->update($id, ['estado' => 0])) {
            return ['status' => true, 'message' => 'Cliente eliminado con éxito.'];
        }
        return ['status' => false, 'message' => 'Error al eliminar el cliente.'];
    }

    public function obtenerTodos(): array {
        return $this->model->obtenerClientes();
    }

    public function obtenerPorId(int $id) {
        return $this->model->find($id);
    }
}
