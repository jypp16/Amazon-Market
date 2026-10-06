<?php

namespace Libraries\Core;

abstract class ApiController {

    protected $model;
    protected array $requestHeaders = [];
    public string $requestMethod;
    protected ?array $requestBody = null;
    protected ?int $authenticatedUserId = null;

    public function __construct() {
        $this->requestMethod = $_SERVER['REQUEST_METHOD'];
        $this->requestHeaders = getallheaders();
        $this->parseIncomingRequest();
        $this->loadModel();
        $this->enforceAuthentication();
    }

    private function loadModel(): void {
        $className = get_class($this);
        $parts = explode('\\', $className);
        $baseName = end($parts);
        $modelName = str_replace('ApiController', 'Model', $baseName);
        $fullModelName = 'Models\\' . $modelName;
        if (class_exists($fullModelName)) {
            $this->model = new $fullModelName();
        }
    }

    private function parseIncomingRequest(): void {
        if (in_array($this->requestMethod, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $contentType = $this->requestHeaders['Content-Type'] ?? $this->requestHeaders['content-type'] ?? '';

            if (str_contains($contentType, 'application/json')) {
                $rawInput = file_get_contents('php://input');
                $decoded = json_decode($rawInput, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->requestBody = $decoded;
                } else {
                    $this->sendJsonResponse(['status' => false, 'message' => 'La sintaxis JSON de la petición es inválida'], 400);
                }
            } elseif (str_contains($contentType, 'multipart/form-data')) {
                $this->requestBody = $_POST;
                $methodOverride = $_POST['_method'] ?? null;
                if ($methodOverride && in_array(strtoupper($methodOverride), ['PUT', 'PATCH', 'DELETE'])) {
                    $this->requestMethod = strtoupper($methodOverride);
                }
            } elseif ($this->requestMethod !== 'DELETE') {
                $this->sendJsonResponse(['status' => false, 'message' => 'El tipo de contenido debe ser application/json o multipart/form-data'], 415);
            }
        }
    }

    private function enforceAuthentication(): void {
        if (empty($_SESSION['login'])) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No autenticado.'], 401);
        }
        $this->authenticatedUserId = $_SESSION['idUser'] ?? null;
    }

    protected function requirePermission(string $slug): void {
        $rol = $_SESSION['rol'] ?? 0;
        if ($rol != 1) {
            if (!\Libraries\Middleware\RBACMiddleware::tienePermiso($rol, $slug)) {
                $this->sendJsonResponse(['status' => false, 'message' => 'No tienes permisos para acceder a este recurso.'], 403);
            }
        }
    }

    protected function sendJsonResponse(array $data, int $statusCode = 200): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    protected function getInput(): array {
        return $this->requestBody ?? [];
    }

    protected function getParam(string $key, $default = null) {
        return $_GET[$key] ?? $default;
    }

    /**
     * Métodos HTTP por defecto. Cada controlador sobrescribe los que soporta.
     * Los no soportados caen aquí y responden 405 con la cabecera Allow
     * derivada dinámicamente de los métodos que el controlador implementa.
     */
    public function get(?string $params = ''): void {
        $this->respondMethodNotAllowed();
    }

    public function post(?string $params = ''): void {
        $this->respondMethodNotAllowed();
    }

    public function put(?string $params = ''): void {
        $this->respondMethodNotAllowed();
    }

    public function delete(?string $params = ''): void {
        $this->respondMethodNotAllowed();
    }

    public function patch(?string $params = ''): void {
        $this->respondMethodNotAllowed();
    }

    private function respondMethodNotAllowed(): void {
        $verbs = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $allowed = [];
        foreach ($verbs as $verb) {
            if (method_exists($this, strtolower($verb))) {
                $ref = new \ReflectionMethod($this, strtolower($verb));
                if ($ref->getDeclaringClass()->getName() !== self::class) {
                    $allowed[] = $verb;
                }
            }
        }
        if (!empty($allowed)) {
            header('Allow: ' . implode(', ', $allowed));
        }
        $this->sendJsonResponse(['status' => false, 'message' => 'Método HTTP no permitido en este recurso'], 405);
    }

    private static function class_basename(string $class): string {
        $parts = explode('\\', $class);
        return end($parts);
    }
}
