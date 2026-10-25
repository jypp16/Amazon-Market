<?php

namespace Libraries\Middleware;

use Libraries\Core\Conexion;
use PDO;

class AuthMiddleware extends Middleware {

    /**
     * Comprueba en base de datos que la sesión activa pertenezca a un usuario
     * existente con `estado = 1`. Sincroniza rol y nombre reales desde el servidor.
     * Si el usuario no existe o fue desactivado, destruye la sesión y devuelve null.
     */
    public static function verificarUsuarioActivoEnSesion(): ?array {
        if (empty($_SESSION['login']) || empty($_SESSION['idUser'])) {
            return null;
        }

        $idUsuario = (int)$_SESSION['idUser'];
        if ($idUsuario <= 0) {
            self::invalidarSesion();
            return null;
        }

        $pdo = Conexion::getInstance()->conect();
        $stmt = $pdo->prepare(
            'SELECT u.id_usuario, u.id_rol, u.username, u.nombre, u.estado, r.nombre AS rol_nombre
               FROM usuario u
              INNER JOIN rol r ON r.id_rol = u.id_rol
              WHERE u.id_usuario = :id_usuario
              LIMIT 1'
        );
        $stmt->execute([':id_usuario' => $idUsuario]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario || (int)$usuario['estado'] !== 1) {
            self::invalidarSesion();
            return null;
        }

        $_SESSION['idUser'] = (int)$usuario['id_usuario'];
        $_SESSION['rol'] = (int)$usuario['id_rol'];
        $_SESSION['nombre'] = (string)$usuario['nombre'];
        $_SESSION['username'] = (string)$usuario['username'];

        return [
            'id_usuario' => (int)$usuario['id_usuario'],
            'id_rol' => (int)$usuario['id_rol'],
            'username' => (string)$usuario['username'],
            'nombre' => (string)$usuario['nombre'],
            'rol_nombre' => (string)$usuario['rol_nombre'],
            'estado' => (int)$usuario['estado'],
        ];
    }

    public static function invalidarSesion(): void {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    public function handle(): void {
        $usuario = self::verificarUsuarioActivoEnSesion();
        if ($usuario === null) {
            if ($this->isApiRequest()) {
                $this->jsonResponse([
                    'status' => false,
                    'code' => 'AUTH_REQUIRED',
                    'message' => 'Su sesión ha expirado o el usuario ya no se encuentra activo.',
                    'data' => null,
                ], 401);
            } else {
                $this->redirect('/Auth');
            }
        }
    }

    public static function check(): void {
        $usuario = self::verificarUsuarioActivoEnSesion();
        if ($usuario === null) {
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(401);
                echo json_encode([
                    'status' => false,
                    'code' => 'AUTH_REQUIRED',
                    'message' => 'Su sesión ha expirado o el usuario ya no se encuentra activo.',
                    'data' => null,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: ' . BASE_URL . '/Auth');
            exit;
        }
    }

    public static function guest(): void {
        if (self::verificarUsuarioActivoEnSesion() !== null) {
            header('Location: ' . BASE_URL . '/Home');
            exit;
        }
    }
}
