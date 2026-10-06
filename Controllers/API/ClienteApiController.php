<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Models\ClienteModel;

class ClienteApiController extends ApiController
{
    private ClienteModel $clienteModel;

    public function __construct()
    {
        parent::__construct();
        $this->clienteModel = new ClienteModel();
    }

public function get(?string $params = ''): void
    {
        $this->requirePermission('clientes.listar');
        $this->getClientes($params);
    }

    public function post(?string $params = ''): void
    {
        $this->store();
    }

    public function put(?string $params = ''): void
    {
        $this->update($params);
    }

    public function delete(?string $params = ''): void
    {
        $this->destroy($params);
    }

    private function getClientes(?string $id): void
    {
        if (!empty($id)) {
            $cliente = $this->clienteModel
                ->select(['cliente.*', 'tipo_documento.nombre as tipo_documento'])
                ->join('tipo_documento', 'cliente.id_tipo_documento = tipo_documento.id_tipo_documento', 'INNER')
                ->where(['id_cliente' => (int)$id, 'cliente.estado = 1'])
                ->first();

            if (!$cliente) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Cliente no encontrado'], 404);
            }

            $this->sendJsonResponse(['status' => true, 'data' => $cliente]);
        } else {
            $search = $this->getParam('q', '');

            $query = $this->clienteModel
                ->select(['cliente.*', 'tipo_documento.nombre as tipo_documento'])
                ->join('tipo_documento', 'cliente.id_tipo_documento = tipo_documento.id_tipo_documento', 'INNER')
                ->where(['cliente.estado = 1']);

            if (!empty($search)) {
                $query->orLikeWhere([
                    'cliente.nombre' => $search,
                    'cliente.nro_documento' => $search,
                    'cliente.telefono' => $search,
                    'cliente.email' => $search
                ], 'cli_search_');
            }

            $clientes = $query->orderBy('cliente.nombre', 'ASC')->get();

            $this->sendJsonResponse(['status' => true, 'data' => $clientes]);
        }
    }

    private function store(): void
    {
        $this->requirePermission('clientes.crear');

        $data = $this->getInput();

        $validator = new \Libraries\Core\Validation($data);
        $validator->required(['nombre', 'nro_documento', 'id_tipo_documento'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->maxLength('direccion', 150)
            ->email('email')
            ->phone('telefono')
            ->unique('nro_documento', $this->clienteModel);

        $errorDoc = $this->validarNroDocumento($data);
        if ($errorDoc) {
            $validator->addError('nro_documento', $errorDoc);
        }

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        $clienteData = [
            'id_tipo_documento' => intval($data['id_tipo_documento'] ?? 1),
            'nro_documento' => trim($data['nro_documento']),
            'nombre' => trim($data['nombre']),
            'direccion' => trim($data['direccion'] ?? ''),
            'telefono' => trim($data['telefono'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'estado' => 1
        ];

        if ($this->clienteModel->insert($clienteData)) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Cliente registrado exitosamente'], 201);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error al registrar el cliente'], 500);
        }
    }

    private function update(?string $id): void
    {
        $this->requirePermission('clientes.editar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador es requerido'], 400);
        }

        $idVal = (int)$id;
        $clienteExistente = $this->clienteModel->find($idVal);

        if (!$clienteExistente || $clienteExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Cliente no encontrado'], 404);
        }

        $data = $this->getInput();
        $datosActualizar = [];

        if (isset($data['nombre'])) $datosActualizar['nombre'] = trim($data['nombre']);
        if (isset($data['nro_documento'])) $datosActualizar['nro_documento'] = trim($data['nro_documento']);
        if (isset($data['id_tipo_documento'])) $datosActualizar['id_tipo_documento'] = intval($data['id_tipo_documento']);
        if (isset($data['direccion'])) $datosActualizar['direccion'] = trim($data['direccion']);
        if (isset($data['telefono'])) $datosActualizar['telefono'] = trim($data['telefono']);
        if (isset($data['email'])) $datosActualizar['email'] = trim($data['email']);

        if (empty($datosActualizar)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se proporcionaron datos para actualizar'], 400);
        }

        $validator = new \Libraries\Core\Validation($datosActualizar);

        if (isset($datosActualizar['nombre'])) {
            $validator->minLength('nombre', 3)
                ->maxLength('nombre', 100);
        }
        if (isset($datosActualizar['nro_documento'])) {
            $validator->maxLength('nro_documento', 20)
                ->unique('nro_documento', $this->clienteModel, $idVal);
        }

        $docData = [
            'id_tipo_documento' => $datosActualizar['id_tipo_documento'] ?? $clienteExistente['id_tipo_documento'],
            'nro_documento' => $datosActualizar['nro_documento'] ?? $clienteExistente['nro_documento']
        ];
        $errorDoc = $this->validarNroDocumento($docData);
        if ($errorDoc) {
            $validator->addError('nro_documento', $errorDoc);
        }

        if (isset($datosActualizar['email']) && $datosActualizar['email'] !== '') {
            $validator->email('email');
        }
        if (isset($datosActualizar['telefono']) && $datosActualizar['telefono'] !== '') {
            $validator->phone('telefono');
        }
        if (isset($datosActualizar['direccion'])) {
            $validator->maxLength('direccion', 150);
        }

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        if ($this->clienteModel->update($idVal, $datosActualizar)) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Cliente actualizado exitosamente']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error al actualizar el cliente'], 500);
        }
    }

    private function validarNroDocumento(array $data): ?string {
        $tipoId = intval($data['id_tipo_documento'] ?? 0);
        $nro = trim($data['nro_documento'] ?? '');

        if ($nro === '') return null;

        switch ($tipoId) {
            case 1:
                if (!preg_match('/^\d{8}$/', $nro)) {
                    return 'El DNI debe tener exactamente 8 dígitos.';
                }
                break;
            case 2:
                if (!preg_match('/^\d{11}$/', $nro)) {
                    return 'El RUC debe tener exactamente 11 dígitos.';
                }
                break;
            case 3:
                if (!preg_match('/^[A-Za-z0-9]{5,20}$/', $nro)) {
                    return 'El pasaporte debe tener entre 5 y 20 caracteres alfanuméricos.';
                }
                break;
            case 4:
                if (!preg_match('/^[A-Za-z0-9]{5,20}$/', $nro)) {
                    return 'El carnet de extranjería debe tener entre 5 y 20 caracteres alfanuméricos.';
                }
                break;
            default:
                if (strlen($nro) < 3 || strlen($nro) > 20) {
                    return 'El número de documento debe tener entre 3 y 20 caracteres.';
                }
        }
        return null;
    }

    private function destroy(?string $id): void
    {
        $this->requirePermission('clientes.eliminar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador es requerido'], 400);
        }

        $idVal = (int)$id;
        $clienteExistente = $this->clienteModel->find($idVal);

        if (!$clienteExistente || $clienteExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Cliente no encontrado'], 404);
        }

        if ($this->clienteModel->update($idVal, ['estado' => 0])) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Cliente eliminado correctamente']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error al eliminar el cliente'], 500);
        }
    }
}