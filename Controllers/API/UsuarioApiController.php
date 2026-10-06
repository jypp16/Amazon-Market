<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Models\UsuarioModel;

class UsuarioApiController extends ApiController
{
    private UsuarioModel $usuarioModel;

    public function __construct()
    {
        parent::__construct();
        $this->usuarioModel = new UsuarioModel();
    }

public function get(?string $params = ''): void
    {
        $this->requirePermission('usuarios.listar');
        $this->getUsuarios($params);
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

    private function getUsuarios(?string $id): void
    {
        if (!empty($id)) {
            $idVal = (int)$id;

            if ($_SESSION['rol'] != 1 && $idVal !== $this->authenticatedUserId) {
                $this->sendJsonResponse(['status' => false, 'message' => 'No tienes permisos para ver este usuario.'], 403);
            }

            $usuario = $this->usuarioModel
                ->select(['usuario.id_usuario', 'usuario.username', 'usuario.nombre', 'usuario.dni', 'usuario.telefono', 'usuario.direccion', 'usuario.email', 'rol.nombre as rol'])
                ->join('rol', 'usuario.id_rol = rol.id_rol', 'INNER')
                ->where(['usuario.id_usuario' => $idVal, 'usuario.estado = 1'])
                ->first();

            if (!$usuario) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Usuario no encontrado'], 404);
            }

            $this->sendJsonResponse(['status' => true, 'data' => $usuario]);
        } else {
            $search = $this->getParam('q', '');

            $query = $this->usuarioModel
                ->select(['usuario.id_usuario', 'usuario.username', 'usuario.nombre', 'usuario.dni', 'usuario.telefono', 'usuario.direccion', 'usuario.email', 'rol.nombre as rol'])
                ->join('rol', 'usuario.id_rol = rol.id_rol', 'INNER')
                ->where(['usuario.estado = 1']);

            if (!empty($search)) {
                $query->orLikeWhere([
                    'usuario.username' => $search,
                    'usuario.nombre' => $search,
                    'usuario.dni' => $search,
                    'usuario.email' => $search
                ], 'usr_search_');
            }

            $usuarios = $query->orderBy('usuario.id_usuario', 'ASC')->get();

            $this->sendJsonResponse(['status' => true, 'data' => $usuarios]);
        }
    }

    private function store(): void
    {
        $this->requirePermission('usuarios.crear');

        $data = $this->getInput();

        $validator = new \Libraries\Core\Validation($data);
        $validator->required(['username', 'password', 'nombre', 'dni', 'id_rol'])
            ->minLength('username', 3)
            ->maxLength('username', 10)
            ->minLength('password', 6)
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->minLength('dni', 8)
            ->maxLength('dni', 8)
            ->maxLength('direccion', 100)
            ->email('email')
            ->phone('telefono')
            ->unique('username', $this->usuarioModel)
            ->unique('dni', $this->usuarioModel);

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        $usuarioData = [
            'id_rol' => intval($data['id_rol']),
            'username' => trim($data['username']),
            'password_hash' => password_hash(trim($data['password']), PASSWORD_DEFAULT),
            'nombre' => trim($data['nombre']),
            'dni' => trim($data['dni']),
            'telefono' => trim($data['telefono'] ?? ''),
            'direccion' => trim($data['direccion'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'estado' => 1
        ];

        if ($this->usuarioModel->insert($usuarioData)) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Usuario registrado exitosamente'], 201);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error al registrar el usuario'], 500);
        }
    }

    private function update(?string $id): void
    {
        $this->requirePermission('usuarios.editar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador es requerido'], 400);
        }

        $idVal = (int)$id;

        if ($_SESSION['rol'] != 1 && $idVal !== $this->authenticatedUserId) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No tienes permisos para modificar este usuario.'], 403);
        }

        $usuarioExistente = $this->usuarioModel->find($idVal);

        if (!$usuarioExistente || $usuarioExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        $data = $this->getInput();
        $datosActualizar = [];

        if (isset($data['username'])) $datosActualizar['username'] = trim($data['username']);
        if (isset($data['nombre'])) $datosActualizar['nombre'] = trim($data['nombre']);
        if (isset($data['dni'])) $datosActualizar['dni'] = trim($data['dni']);
        // Sólo admin puede cambiar el rol (privilege escalation)
        if (isset($data['id_rol'])) {
            if (intval($_SESSION['rol']) !== 1) {
                $this->sendJsonResponse(['status' => false, 'message' => 'No tienes permisos para cambiar el rol de un usuario.'], 403);
            }
            $datosActualizar['id_rol'] = intval($data['id_rol']);
        }
        if (isset($data['telefono'])) $datosActualizar['telefono'] = trim($data['telefono']);
        if (isset($data['direccion'])) $datosActualizar['direccion'] = trim($data['direccion']);
        if (isset($data['email'])) $datosActualizar['email'] = trim($data['email']);
        if (!empty($data['password'])) {
            $datosActualizar['password_hash'] = password_hash(trim($data['password']), PASSWORD_DEFAULT);
        }

        if (empty($datosActualizar)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se proporcionaron datos para actualizar'], 400);
        }

        if (isset($data['username'])) {
            $validator = new \Libraries\Core\Validation($data);
            $validator->unique('username', $this->usuarioModel, $idVal);
            if ($validator->fails()) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
            }
        }

        if (isset($data['dni'])) {
            $validator = new \Libraries\Core\Validation($data);
            $validator->unique('dni', $this->usuarioModel, $idVal);
            if ($validator->fails()) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
            }
        }

        if (isset($data['email']) || isset($data['telefono'])) {
            $validator = new \Libraries\Core\Validation($data);
            if (isset($data['email'])) $validator->email('email');
            if (isset($data['telefono'])) $validator->phone('telefono');
            if ($validator->fails()) {
                $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
            }
        }

        if ($this->usuarioModel->update($idVal, $datosActualizar)) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Usuario actualizado exitosamente']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error al actualizar el usuario'], 500);
        }
    }

    private function destroy(?string $id): void
    {
        $this->requirePermission('usuarios.eliminar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador es requerido'], 400);
        }

        $idVal = (int)$id;

        if ($_SESSION['rol'] != 1 && $idVal !== $this->authenticatedUserId) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No tienes permisos para eliminar este usuario.'], 403);
        }

        // No permitir eliminarse a uno mismo (incluso si es admin)
        if ($idVal === $this->authenticatedUserId) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No puede eliminar su propia cuenta.'], 403);
        }

        $usuarioExistente = $this->usuarioModel->find($idVal);

        if (!$usuarioExistente || $usuarioExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        // Evitar perder el último administrador activo
        if (intval($usuarioExistente['id_rol']) === 1) {
            $adminsActivos = $this->usuarioModel
                ->where(['id_rol' => 1, 'estado = 1'])
                ->get();
            if (count($adminsActivos) <= 1) {
                $this->sendJsonResponse(['status' => false, 'message' => 'No se puede eliminar al último administrador activo.'], 422);
            }
        }

        if ($this->usuarioModel->update($idVal, ['estado' => 0])) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Usuario eliminado correctamente']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error al eliminar el usuario'], 500);
        }
    }
}