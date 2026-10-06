<?php

namespace Controllers\API;

class ImagenApiController {

    private string $storagePath;

    public function __construct() {
        $this->storagePath = defined('STORAGE_PATH') ? STORAGE_PATH : 'C:/xampp/storage/amazon_market/productos';
    }

    public function get(?string $params = ""): void {
        if (empty($params)) {
            http_response_code(400);
            echo json_encode(['status' => false, 'message' => 'Código de barras requerido']);
            return;
        }

        $codigoBarra = urldecode($params);

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $codigoBarra)) {
            http_response_code(400);
            echo json_encode(['status' => false, 'message' => 'Código de barras inválido']);
            return;
        }

        $filePath = $this->buscarEnStorage($codigoBarra);

        if (!$filePath) {
            $fallback = __DIR__ . '/../../../Assets/img/productos/no-image.jpg';
            if (file_exists($fallback)) {
                $filePath = $fallback;
            } else {
                http_response_code(404);
                echo json_encode(['status' => false, 'message' => 'Imagen no encontrada']);
                return;
            }
        }

        $mimeTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    private function buscarEnStorage(string $codigoBarra): ?string {
        $metaFile = $this->storagePath . '/.meta.json';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true) ?: [];
            if (isset($meta[$codigoBarra])) {
                $ext = $meta[$codigoBarra]['ext'] ?? '';
                $hash = $meta[$codigoBarra]['hash'] ?? '';
                if (!preg_match('/^[a-f0-9]{16,128}$/', $hash) || !in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                    return null;
                }
                $path = $this->storagePath . '/' . $hash . '.' . $ext;
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        foreach ($extensions as $ext) {
            $candidate = $this->storagePath . '/' . $codigoBarra . '.' . $ext;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
