<?php

namespace Controllers\API;

use Services\StorageService;

class ImagenApiController {

    public string $requestMethod;
    private StorageService $storage;

    public function __construct() {
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->storage = new StorageService();
    }

    public function get(?string $params = ""): void {
        $params = trim((string)$params, '/');
        if ($params === '') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['status' => false, 'message' => 'Identificador de imagen requerido']);
            return;
        }

        $filePath = null;

        // Ruta estable por ID de producto: GET /api/imagenes/producto/{id_producto}
        if (preg_match('#^producto/(\d+)$#', $params, $matches)) {
            $idProducto = (int)$matches[1];
            $filePath = $this->storage->getRutaImagenProducto($idProducto);
            if (!$filePath) {
                $this->servirFallbackONotFound();
                return;
            }
            $this->servirArchivo($filePath);
            return;
        }

        // Compatibilidad con rutas legadas por clave/código (sin fallback cruzado entre productos)
        $codigoBarra = urldecode($params);
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $codigoBarra)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['status' => false, 'message' => 'Código de barras inválido']);
            return;
        }

        $filePath = $this->storage->getRutaImagen($codigoBarra);
        if (!$filePath) {
            foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                $candidate = $this->storage->getStoragePath() . '/' . $codigoBarra . '.' . $ext;
                if (is_file($candidate)) {
                    $filePath = $candidate;
                    break;
                }
            }
        }

        if (!$filePath) {
            $this->servirFallbackONotFound();
            return;
        }

        $this->servirArchivo($filePath);
    }

    private function servirFallbackONotFound(): void {
        $candidates = [
            $this->storage->getPublicPath() . '/no-image.svg',
            $this->storage->getPublicPath() . '/no-image.jpg',
            __DIR__ . '/../../../Assets/img/productos/no-image.svg',
            __DIR__ . '/../../../Assets/img/productos/no-image.jpg',
        ];
        foreach ($candidates as $fallback) {
            if (is_file($fallback)) {
                $this->servirArchivo($fallback, false);
                return;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'Imagen no encontrada']);
    }

    private function servirArchivo(string $filePath, bool $cacheable = true): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $mimeTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
        ];

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header($cacheable ? 'Cache-Control: no-cache, must-revalidate' : 'Cache-Control: no-store');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}
