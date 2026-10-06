<?php

namespace Services;

class StorageService {

    private string $storagePath;
    private string $publicPath;
    private array $allowedMimes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];
    private array $magicNumbers = [
        'image/jpeg' => "\xFF\xD8\xFF",
        'image/png'  => "\x89PNG\r\n\x1A\n",
        'image/gif'  => "GIF87a",
        'image/gif2'=> "GIF89a",
        'image/webp' => "RIFF",
    ];
    private int $maxSize = 5 * 1024 * 1024;

    public function __construct() {
        $configuredPath = defined('STORAGE_PATH') ? STORAGE_PATH : null;
        if ($configuredPath && is_dir($configuredPath)) {
            $this->storagePath = $configuredPath;
        } else {
            $path = __DIR__ . '/../../storage/productos';
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            $this->storagePath = realpath($path) ?: $path;
        }
        $this->publicPath = __DIR__ . '/../Assets/img/productos';
        if (!is_dir($this->publicPath)) {
            mkdir($this->publicPath, 0755, true);
        }
    }

    public function guardarImagen(array $file, string $codigoBarra): array {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'message' => 'No se envió archivo.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Error del servidor al subir el archivo (código ' . $file['error'] . ').'];
        }
        if ($file['size'] > $this->maxSize) {
            $maxMb = $this->maxSize / (1024 * 1024);
            return ['ok' => false, 'message' => "El archivo excede el tamaño máximo de {$maxMb} MB."];
        }

        $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigoBarra);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return ['ok' => false, 'message' => 'Tipo de archivo no permitido. Solo se aceptan JPG, PNG, GIF y WebP.'];
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return ['ok' => false, 'message' => 'No se pudo leer el archivo subido.'];
        }

        $mimeType = $this->detectarMimeType($content, $ext);
        if ($mimeType === null) {
            return ['ok' => false, 'message' => 'El contenido del archivo no coincide con su extensión. Archivo rechazado por seguridad.'];
        }
        if (!in_array($mimeType, $this->allowedMimes)) {
            return ['ok' => false, 'message' => 'Tipo MIME no permitido (' . $mimeType . ').'];
        }

        $hash = hash('sha256', $content);
        $hashName = $hash . '.' . $ext;
        $secureDestino = $this->storagePath . '/' . $hashName;

        if (!move_uploaded_file($file['tmp_name'], $secureDestino)) {
            return ['ok' => false, 'message' => 'No se pudo mover el archivo al almacenamiento.'];
        }

        $this->eliminarImagen($codigoBarra);

        $publicFile = $this->publicPath . '/' . $safeCode . '.' . $ext;
        file_put_contents($publicFile, $content);

        $this->registrarHash($codigoBarra, $hash, $ext);

        return ['ok' => true, 'message' => 'Imagen guardada correctamente.', 'filename' => $hashName, 'hash' => $hash];
    }

    public function renombrarImagen(string $oldCodigo, string $newCodigo): void {
        $meta = $this->cargarMeta();
        if (isset($meta[$oldCodigo])) {
            $ext = $meta[$oldCodigo]['ext'];
            $hash = $meta[$oldCodigo]['hash'];
            $safeOld = preg_replace('/[^a-zA-Z0-9_-]/', '_', $oldCodigo);
            $safeNew = preg_replace('/[^a-zA-Z0-9_-]/', '_', $newCodigo);

            $oldPublic = $this->publicPath . '/' . $safeOld . '.' . $ext;
            if (file_exists($oldPublic)) {
                rename($oldPublic, $this->publicPath . '/' . $safeNew . '.' . $ext);
            }

            $meta[$newCodigo] = $meta[$oldCodigo];
            unset($meta[$oldCodigo]);
            file_put_contents($this->storagePath . '/.meta.json', json_encode($meta, JSON_PRETTY_PRINT));
        }
    }

    public function eliminarImagen(string $codigoBarra): bool {
        $meta = $this->cargarMeta();
        $deleted = false;

        if (isset($meta[$codigoBarra])) {
            $ext = $meta[$codigoBarra]['ext'];
            $hash = $meta[$codigoBarra]['hash'];
            $secureFile = $this->storagePath . '/' . $hash . '.' . $ext;
            if (file_exists($secureFile) && unlink($secureFile)) {
                $deleted = true;
            }
        }

        $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigoBarra);
        $publicFiles = glob($this->publicPath . '/' . $safeCode . '.*');
        if ($publicFiles) {
            foreach ($publicFiles as $file) {
                unlink($file);
            }
        }
        return $deleted;
    }

    public function existeImagen(string $codigoBarra): bool {
        $meta = $this->cargarMeta();
        if (!isset($meta[$codigoBarra])) {
            return false;
        }
        $ext = $meta[$codigoBarra]['ext'];
        $hash = $meta[$codigoBarra]['hash'];
        return file_exists($this->storagePath . '/' . $hash . '.' . $ext);
    }

    public function getRutaImagen(string $codigoBarra): ?string {
        $meta = $this->cargarMeta();
        if (!isset($meta[$codigoBarra])) {
            return null;
        }
        $ext = $meta[$codigoBarra]['ext'];
        $hash = $meta[$codigoBarra]['hash'];
        $path = $this->storagePath . '/' . $hash . '.' . $ext;
        return file_exists($path) ? $path : null;
    }

    public function verificarIntegridad(string $codigoBarra): array {
        $meta = $this->cargarMeta();
        if (!isset($meta[$codigoBarra])) {
            return ['ok' => false, 'integridad_ok' => false, 'message' => 'No se encontró registro de hash para este código.'];
        }
        $ext = $meta[$codigoBarra]['ext'];
        $hash = $meta[$codigoBarra]['hash'];
        $archivo = $this->storagePath . '/' . $hash . '.' . $ext;
        if (!file_exists($archivo)) {
            return ['ok' => false, 'integridad_ok' => false, 'message' => 'El archivo de imagen no existe.'];
        }
        $hashActual = hash('sha256', file_get_contents($archivo));
        $integro = ($hashActual === $hash);
        return [
            'ok' => true,
            'integridad_ok' => $integro,
            'hash_esperado' => $hash,
            'hash_actual' => $hashActual,
        ];
    }

    private function cargarMeta(): array {
        $metaFile = $this->storagePath . '/.meta.json';
        if (!file_exists($metaFile)) {
            return [];
        }
        return json_decode(file_get_contents($metaFile), true) ?: [];
    }

    private function registrarHash(string $codigoBarra, string $hash, string $ext): void {
        $metaFile = $this->storagePath . '/.meta.json';
        $fp = fopen($metaFile, 'c');
        if ($fp && flock($fp, LOCK_EX)) {
            $meta = [];
            if (filesize($metaFile) > 0) {
                rewind($fp);
                $content = stream_get_contents($fp);
                $meta = json_decode($content, true) ?: [];
            }
            $meta[$codigoBarra] = ['hash' => $hash, 'ext' => $ext, 'fecha' => date('c')];
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($meta, JSON_PRETTY_PRINT));
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function detectarMimeType(string $content, string $ext): ?string {
        if (isset($this->magicNumbers['image/jpeg']) && str_starts_with($content, $this->magicNumbers['image/jpeg'])) {
            return 'image/jpeg';
        }
        if (str_starts_with($content, $this->magicNumbers['image/png'])) {
            return 'image/png';
        }
        if (str_starts_with($content, $this->magicNumbers['image/gif'])) {
            return 'image/gif';
        }
        if (str_starts_with($content, $this->magicNumbers['image/webp'])) {
            return 'image/webp';
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($content);
        if ($detected && $detected !== 'application/octet-stream') {
            return $detected;
        }
        return null;
    }
}