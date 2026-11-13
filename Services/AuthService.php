<?php

namespace Services;

use Libraries\Core\Conexion;
use Libraries\Core\InventarioException;
use Models\UsuarioModel;
use PDO;

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

    /**
     * Valida credenciales de un Administrador activo para autorizar el cierre anticipado
     * sin alterar, regenerar ni destruir la sesión PHP del vendedor autenticado.
     */
    public function verificarCredencialesAdministradorPorEmail(
        string $email,
        string $plainPassword,
        int $idVendedorSolicitante,
        ?PDO $pdo = null
    ): array {
        $emailNorm = mb_strtolower(trim($email), 'UTF-8');
        if ($emailNorm === '' || mb_strlen($emailNorm, 'UTF-8') > 150 || filter_var($emailNorm, FILTER_VALIDATE_EMAIL) === false) {
            throw new InventarioException(
                'El correo electrónico del administrador autorizador no tiene un formato válido.',
                'INVALID_ADMIN_EMAIL',
                422,
                ['admin_email' => ['Debe ingresar un correo electrónico válido de hasta 150 caracteres.']]
            );
        }

        if ($plainPassword === '' || strlen($plainPassword) > 128) {
            throw new InventarioException(
                'Las credenciales del administrador autorizador son inválidas.',
                'INVALID_ADMIN_CREDENTIALS',
                403
            );
        }

        $conn = $pdo ?? Conexion::getInstance()->conect();
        $stmt = $conn->prepare(
            "SELECT id_usuario, id_rol, nombre, email, password_hash, estado
               FROM usuario
              WHERE LOWER(TRIM(COALESCE(email, ''))) = :email_norm
              LIMIT 2"
        );
        $stmt->execute([':email_norm' => $emailNorm]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== 1) {
            throw new InventarioException(
                'Las credenciales del administrador autorizador son inválidas o el correo es ambiguo.',
                'INVALID_ADMIN_CREDENTIALS',
                403
            );
        }

        $admin = $rows[0];
        if (
            (int)$admin['estado'] !== 1
            || (int)$admin['id_rol'] !== 1
            || (int)$admin['id_usuario'] === $idVendedorSolicitante
            || !password_verify($plainPassword, (string)$admin['password_hash'])
        ) {
            throw new InventarioException(
                'Las credenciales del administrador autorizador son inválidas.',
                'INVALID_ADMIN_CREDENTIALS',
                403
            );
        }

        return [
            'id_usuario' => (int)$admin['id_usuario'],
            'nombre'     => (string)$admin['nombre'],
            'email'      => (string)$admin['email'],
        ];
    }
}
