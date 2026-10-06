<?php

namespace Services;

use Models\UsuarioModel;

class AuthService {

    private $usuarioModel;

    public function __construct() {
        $this->usuarioModel = new UsuarioModel();
    }

    public function login(string $username, string $password): array {
        $username = trim($username);

        if (empty($username) || empty($password)) {
            return ['status' => false, 'message' => 'Todos los campos son obligatorios.'];
        }

        $user = $this->usuarioModel->obtenerUsuarioPorUsername($username);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['status' => false, 'message' => 'Usuario o contraseña incorrectos.'];
        }

        $_SESSION['login'] = true;
        $_SESSION['idUser'] = $user['id_usuario'];
        $_SESSION['user'] = $user['username'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol'] = $user['id_rol'];

        // Prevenir session fixation: regenerar el ID de sesión post-login
        session_regenerate_id(true);

        return ['status' => true];
    }

    public function logout() {
        session_unset();
        session_destroy();
    }
}
