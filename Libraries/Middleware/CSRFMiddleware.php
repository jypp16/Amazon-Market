<?php

namespace Libraries\Middleware;

class CSRFMiddleware extends Middleware {

    public function handle(): void {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function generateToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function getTokenField(): string {
        return '<input type="hidden" name="csrf_token" value="' . self::generateToken() . '">';
    }

    public static function getTokenMeta(): string {
        return '<meta name="csrf-token" content="' . self::generateToken() . '">';
    }

    public static function validate(): bool {
        // Sólo los métodos que modifican estado requieren token CSRF
        $metodosProtegidos = ['POST', 'PUT', 'PATCH', 'DELETE'];
        $metodoActual = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($metodoActual, $metodosProtegidos, true)) {
            return true;
        }

        // El token puede venir en cabecera (API) o en form-data (web).
        // Se evita leer php://input para no consumir el stream que el
        // ApiController necesita para parsear el body JSON.
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;

        if ($token === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? null;
        }

        if (!$token || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$token)) {
            return false;
        }

        return true;
    }

    public static function verifyOrFail(): void {
        if (self::validate()) {
            return;
        }

        $url = strtolower($_GET['url'] ?? '');
        $esApi = (strpos($url, 'api/') === 0 || $url === 'api')
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($esApi) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'status' => false,
                'code' => 'CSRF_INVALID',
                'message' => 'Token CSRF inválido. Recargue la página e intente de nuevo.',
                'data' => null,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Ruta solicitada (para derivar el fallback). Si es pública (p.ej. Auth)
        // o no hay referer, redirigir a /Auth en lugar de /Home (evita bucle auth).
        $esRutaPublica = (strpos($url, 'auth') === 0);
        $fallback = $esRutaPublica ? '/Auth' : '/Home';

        $_SESSION['error'] = 'Token de seguridad inválido. Intente de nuevo.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . $fallback)));
        exit;
    }
}
