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
        'image/gif2' => "GIF89a",
        'image/webp' => "RIFF",
    ];
    private int $maxSize = 5 * 1024 * 1024;

    public function __construct(?string $storagePathOverride = null, ?string $publicPathOverride = null) {
        $configuredPath = $storagePathOverride
            ?: (getenv('STORAGE_PATH') ?: (defined('STORAGE_PATH') ? STORAGE_PATH : null));

        if ($configuredPath && (is_dir($configuredPath) || ($storagePathOverride !== null) || getenv('STORAGE_PATH'))) {
            if (!is_dir($configuredPath)) {
                @mkdir($configuredPath, 0755, true);
            }
            $this->storagePath = realpath($configuredPath) ?: $configuredPath;
        } else {
            $path = __DIR__ . '/../../storage/productos';
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $this->storagePath = realpath($path) ?: $path;
        }

        $configuredPublic = $publicPathOverride
            ?: (getenv('PUBLIC_PRODUCT_IMAGES_PATH') ?: (defined('PUBLIC_PRODUCT_IMAGES_PATH') ? PUBLIC_PRODUCT_IMAGES_PATH : null));

        $this->publicPath = $configuredPublic ?: (__DIR__ . '/../Assets/img/productos');
        if (!is_dir($this->publicPath)) {
            @mkdir($this->publicPath, 0755, true);
        }
    }

    public static function claveProducto(int $idProducto): string {
        return 'prod_' . $idProducto;
    }

    public function getStoragePath(): string {
        return $this->storagePath;
    }

    public function getPublicPath(): string {
        return $this->publicPath;
    }

    public function guardarImagenPorProducto(array $file, int $idProducto): array {
        if ($idProducto <= 0) {
            return ['ok' => false, 'message' => 'Identificador de producto inválido para guardar imagen.'];
        }
        return $this->guardarImagen($file, self::claveProducto($idProducto));
    }

    public function guardarImagen(array $file, string $clave): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'message' => 'No se envió archivo.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Error del servidor al subir el archivo (código ' . $file['error'] . ').'];
        }
        if (($file['size'] ?? 0) > $this->maxSize) {
            $maxMb = $this->maxSize / (1024 * 1024);
            return ['ok' => false, 'message' => "El archivo excede el tamaño máximo de {$maxMb} MB."];
        }

        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clave);
        if ($safeKey === '' || $safeKey === null) {
            return ['ok' => false, 'message' => 'Clave de almacenamiento inválida.'];
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return ['ok' => false, 'message' => 'Tipo de archivo no permitido. Solo se aceptan JPG, PNG, GIF y WebP.'];
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            return ['ok' => false, 'message' => 'No se pudo leer el archivo subido.'];
        }

        $content = @file_get_contents($tmpName);
        if ($content === false) {
            return ['ok' => false, 'message' => 'No se pudo leer el archivo subido.'];
        }

        $mimeType = $this->detectarMimeType($content, $ext);
        if ($mimeType === null) {
            return ['ok' => false, 'message' => 'El contenido del archivo no coincide con su extensión. Archivo rechazado por seguridad.'];
        }
        if (!in_array($mimeType, $this->allowedMimes, true)) {
            return ['ok' => false, 'message' => 'Tipo MIME no permitido (' . $mimeType . ').'];
        }

        if (!is_dir($this->storagePath) || !is_writable($this->storagePath)) {
            return ['ok' => false, 'message' => 'El directorio de almacenamiento de imágenes no tiene permisos de escritura.'];
        }
        if (!is_dir($this->publicPath) || !is_writable($this->publicPath)) {
            return ['ok' => false, 'message' => 'El directorio público de imágenes no tiene permisos de escritura.'];
        }

        $hash = hash('sha256', $content);
        $hashName = $hash . '.' . $ext;
        $secureDestino = $this->storagePath . '/' . $hashName;
        $secureExistedBefore = is_file($secureDestino);

        // 1. Escribir y verificar el archivo en almacenamiento seguro antes de tocar nada previo
        if (!$secureExistedBefore) {
            if (!$this->escribirArchivoAtomico($secureDestino, $content, $hash)) {
                return ['ok' => false, 'message' => 'No se pudo escribir o verificar el archivo en el almacenamiento seguro.'];
            }
        }

        // 2. Escribir y verificar archivo público temporal
        $publicFile = $this->publicPath . '/' . $safeKey . '.' . $ext;
        $tmpPublic = $this->publicPath . '/' . $safeKey . '.tmp.' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (@file_put_contents($tmpPublic, $content, LOCK_EX) === false || @hash_file('sha256', $tmpPublic) !== $hash) {
            @unlink($tmpPublic);
            if (!$secureExistedBefore) {
                @unlink($secureDestino);
            }
            return ['ok' => false, 'message' => 'No se pudo escribir la copia pública de la imagen.'];
        }

        // 3. Actualizar .meta.json de forma atómica (sin truncar jamás la metadata existente)
        $metaResult = $this->actualizarMetaAtomico(function (array $meta) use ($clave, $hash, $ext): array {
            $meta[$clave] = ['hash' => $hash, 'ext' => $ext, 'fecha' => date('c')];
            return $meta;
        });
        if (!$metaResult['ok']) {
            @unlink($tmpPublic);
            if (!$secureExistedBefore) {
                @unlink($secureDestino);
            }
            return ['ok' => false, 'message' => $metaResult['message']];
        }

        // 4. Publicar archivo público reemplazando el destino; si falla, restaurar metadata anterior
        if (is_dir($publicFile) || !@rename($tmpPublic, $publicFile)) {
            @unlink($tmpPublic);
            $prevMeta = $metaResult['previous_meta'];
            $this->guardarMetaAtomicoDirecto($prevMeta);
            if (!$secureExistedBefore) {
                @unlink($secureDestino);
            }
            return ['ok' => false, 'message' => 'No se pudo publicar la nueva imagen; se conservó la imagen anterior.'];
        }

        // 5. Limpiar copias públicas antiguas de otras extensiones distintas
        $publicFiles = glob($this->publicPath . '/' . $safeKey . '.*') ?: [];
        foreach ($publicFiles as $existingPublic) {
            if (is_file($existingPublic) && realpath($existingPublic) !== realpath($publicFile)) {
                @unlink($existingPublic);
            }
        }

        return ['ok' => true, 'message' => 'Imagen guardada correctamente.', 'filename' => $hashName, 'hash' => $hash, 'key' => $clave];
    }

    /**
     * Promueve una foto heredada (indexada por codigo_barra) a la clave estable `prod_{idProducto}`.
     * Verifica contenido, escritura de archivo, metadata atómica y resolución por ID ANTES de retirar
     * la referencia y archivo antiguo por código. Si algo falla, conserva intactos el archivo y la metadata originales.
     *
     * @return array{ok:bool, status:string, message:string, hash?:string, ext?:string}
     */
    public function promoverImagenAClaveProducto(int $idProducto, ?string $codigoBarraAnterior): array {
        if ($idProducto <= 0) {
            return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'Identificador de producto inválido.'];
        }
        $oldKey = $codigoBarraAnterior !== null ? trim($codigoBarraAnterior) : '';
        if ($oldKey === '') {
            return ['ok' => true, 'status' => 'NO_LEGACY_PHOTO', 'message' => 'El producto no tenía código previo con foto heredada.'];
        }

        $newKey = self::claveProducto($idProducto);
        $safeOld = preg_replace('/[^a-zA-Z0-9_-]/', '_', $oldKey);
        $safeNew = preg_replace('/[^a-zA-Z0-9_-]/', '_', $newKey);

        $meta = $this->cargarMeta();
        if ($meta === null) {
            return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'No se pudo leer la metadata de imágenes (.meta.json).'];
        }

        $tieneViejaEnMeta = isset($meta[$oldKey]);
        $archivosPublicosViejos = array_values(array_filter(
            glob($this->publicPath . '/' . $safeOld . '.*') ?: [],
            static fn(string $p): bool => is_file($p)
        ));

        // Caso 1: Ya existe una foto válida y legible bajo prod_{id}
        $rutaActualPorId = $this->getRutaImagenProducto($idProducto);
        if ($rutaActualPorId !== null && is_file($rutaActualPorId) && is_readable($rutaActualPorId)) {
            if ($tieneViejaEnMeta) {
                $rmRes = $this->actualizarMetaAtomico(function (array $m) use ($oldKey): array {
                    unset($m[$oldKey]);
                    return $m;
                });
                if (!$rmRes['ok']) {
                    return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'La foto por ID existe, pero no se pudo limpiar la entrada legada en .meta.json: ' . $rmRes['message']];
                }
            }
            foreach ($archivosPublicosViejos as $oldFile) {
                @unlink($oldFile);
            }
            return ['ok' => true, 'status' => 'ALREADY_LINKED', 'message' => 'La foto ya estaba vinculada por ID de producto.'];
        }

        // Caso 2: No hay foto heredada ni en metadata ni en carpeta pública
        if (!$tieneViejaEnMeta && empty($archivosPublicosViejos)) {
            return ['ok' => true, 'status' => 'NO_LEGACY_PHOTO', 'message' => 'El producto no tiene foto heredada pendiente de migrar.'];
        }

        // Caso 3: Existe foto heredada -> localizar fuente y verificar contenido/hash antes de tocar nada
        $sourcePath = null;
        $ext = '';
        $expectedHash = '';

        if ($tieneViejaEnMeta) {
            $ext = strtolower((string)($meta[$oldKey]['ext'] ?? ''));
            $expectedHash = (string)($meta[$oldKey]['hash'] ?? '');
            $candidateSecure = ($ext !== '' && $expectedHash !== '') ? ($this->storagePath . '/' . $expectedHash . '.' . $ext) : '';
            if ($candidateSecure !== '' && is_file($candidateSecure) && is_readable($candidateSecure)) {
                $sourcePath = $candidateSecure;
            }
        }
        if ($sourcePath === null && !empty($archivosPublicosViejos)) {
            $candidatePub = $archivosPublicosViejos[0];
            $candidateExt = strtolower(pathinfo($candidatePub, PATHINFO_EXTENSION));
            if (in_array($candidateExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && is_readable($candidatePub)) {
                $sourcePath = $candidatePub;
                $ext = $candidateExt;
            }
        }

        if ($sourcePath === null || !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'Se detectó una referencia de foto heredada, pero el archivo fuente no es accesible o tiene extensión inválida.'];
        }

        $content = @file_get_contents($sourcePath);
        if ($content === false || $content === '') {
            return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'No se pudo leer el contenido de la foto heredada.'];
        }

        $actualHash = hash('sha256', $content);
        if ($expectedHash !== '' && !hash_equals($expectedHash, $actualHash)) {
            return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'La integridad SHA-256 de la foto heredada no coincide con la metadata registrada.'];
        }

        // 3a. Asegurar copia verificada en almacenamiento seguro (sin borrar el original)
        $secureDestino = $this->storagePath . '/' . $actualHash . '.' . $ext;
        $secureCreated = false;
        if (!is_file($secureDestino)) {
            if (!$this->escribirArchivoAtomico($secureDestino, $content, $actualHash)) {
                return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'No se pudo copiar la foto heredada al almacenamiento seguro.'];
            }
            $secureCreated = true;
        }

        // 3b. Escribir y verificar copia pública bajo prod_{id}.{ext} SIN tocar todavía el archivo antiguo {safeOld}.*
        $newPublic = $this->publicPath . '/' . $safeNew . '.' . $ext;
        if (is_dir($newPublic) || !$this->escribirArchivoAtomico($newPublic, $content, $actualHash)) {
            if ($secureCreated) {
                @unlink($secureDestino);
            }
            return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'No se pudo escribir la copia pública de la foto bajo el ID del producto.'];
        }

        // 3c. Actualizar .meta.json atómicamente: añadir prod_{id} y retirar oldKey en una sola escritura atómica
        //     (si falla, revertimos newPublic y dejamos intactos oldKey y {safeOld}.*)
        $metaRes = $this->actualizarMetaAtomico(function (array $m) use ($newKey, $oldKey, $actualHash, $ext): array {
            $m[$newKey] = ['hash' => $actualHash, 'ext' => $ext, 'fecha' => date('c')];
            unset($m[$oldKey]);
            return $m;
        });

        if (!$metaRes['ok']) {
            @unlink($newPublic);
            if ($secureCreated) {
                @unlink($secureDestino);
            }
            return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'No se pudo actualizar .meta.json al vincular la foto por ID: ' . $metaRes['message']];
        }

        // 3d. Verificación final obligatoria: confirmar que la imagen es resoluble por ID y conserva su SHA-256 exacto
        $rutaVerificada = $this->getRutaImagenProducto($idProducto);
        $hashVerificado = ($rutaVerificada !== null && is_file($rutaVerificada)) ? @hash_file('sha256', $rutaVerificada) : false;
        $hashPublico = is_file($newPublic) ? @hash_file('sha256', $newPublic) : false;

        if ($rutaVerificada === null || $hashVerificado !== $actualHash || $hashPublico !== $actualHash) {
            // Revertir metadata y archivo nuevo; conservar intactos la foto y referencia legadas
            $this->guardarMetaAtomicoDirecto($metaRes['previous_meta']);
            @unlink($newPublic);
            if ($secureCreated) {
                @unlink($secureDestino);
            }
            return ['ok' => false, 'status' => 'PROMOTION_FAILED', 'message' => 'La verificación de lectura por ID falló tras copiar la foto; se conservó la foto heredada original.'];
        }

        // 3e. Solo después de verificar la nueva foto y metadata, eliminar los archivos públicos antiguos por código
        foreach ($archivosPublicosViejos as $oldFile) {
            if (is_file($oldFile) && realpath($oldFile) !== realpath($newPublic)) {
                @unlink($oldFile);
            }
        }

        return [
            'ok'      => true,
            'status'  => 'PROMOTED',
            'message' => 'Foto heredada vinculada correctamente por ID de producto.',
            'hash'    => $actualHash,
            'ext'     => $ext,
        ];
    }

    /**
     * Limpia únicamente restos legados de un código después de que ya se guardó una foto nueva bajo prod_{id}.
     */
    public function limpiarRestosLegadosCodigo(int $idProducto, ?string $codigoBarra): void {
        $oldKey = $codigoBarra !== null ? trim($codigoBarra) : '';
        if ($idProducto <= 0 || $oldKey === '') {
            return;
        }
        if ($this->getRutaImagenProducto($idProducto) === null) {
            return;
        }
        $this->actualizarMetaAtomico(function (array $m) use ($oldKey): array {
            unset($m[$oldKey]);
            return $m;
        });
        $safeOld = preg_replace('/[^a-zA-Z0-9_-]/', '_', $oldKey);
        foreach (glob($this->publicPath . '/' . $safeOld . '.*') ?: [] as $oldFile) {
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
    }

    /**
     * Migra en bloque fotos legadas a partir de un mapa [id_producto => codigo_barra].
     *
     * @return array{ok:bool, promovidas:int[], ya_vinculadas:int[], sin_foto:int[], errores:array<int,string>}
     */
    public function migrarFotosExistentesPorMapa(array $mapaIdACodigo): array {
        $promovidas = [];
        $yaVinculadas = [];
        $sinFoto = [];
        $errores = [];

        foreach ($mapaIdACodigo as $idProd => $codigo) {
            $idInt = (int)$idProd;
            $res = $this->promoverImagenAClaveProducto($idInt, $codigo);
            if (!$res['ok']) {
                $errores[$idInt] = $res['message'];
            } elseif ($res['status'] === 'PROMOTED') {
                $promovidas[] = $idInt;
            } elseif ($res['status'] === 'ALREADY_LINKED') {
                $yaVinculadas[] = $idInt;
            } else {
                $sinFoto[] = $idInt;
            }
        }

        return [
            'ok'            => empty($errores),
            'promovidas'    => $promovidas,
            'ya_vinculadas' => $yaVinculadas,
            'sin_foto'      => $sinFoto,
            'errores'       => $errores,
        ];
    }

    public function renombrarImagen(string $oldCodigo, string $newCodigo): void {
        $meta = $this->cargarMeta();
        if (is_array($meta) && isset($meta[$oldCodigo])) {
            $ext = $meta[$oldCodigo]['ext'];
            $safeOld = preg_replace('/[^a-zA-Z0-9_-]/', '_', $oldCodigo);
            $safeNew = preg_replace('/[^a-zA-Z0-9_-]/', '_', $newCodigo);

            $oldPublic = $this->publicPath . '/' . $safeOld . '.' . $ext;
            if (is_file($oldPublic)) {
                @rename($oldPublic, $this->publicPath . '/' . $safeNew . '.' . $ext);
            }

            $entry = $meta[$oldCodigo];
            $this->actualizarMetaAtomico(function (array $m) use ($oldCodigo, $newCodigo, $entry): array {
                $m[$newCodigo] = $entry;
                unset($m[$oldCodigo]);
                return $m;
            });
        }
    }

    public function eliminarImagen(string $clave): bool {
        $meta = $this->cargarMeta();
        $deleted = false;

        if (is_array($meta) && isset($meta[$clave])) {
            $ext = $meta[$clave]['ext'];
            $hash = $meta[$clave]['hash'];
            $otherUses = false;
            foreach ($meta as $k => $entry) {
                if ($k !== $clave && ($entry['hash'] ?? '') === $hash && ($entry['ext'] ?? '') === $ext) {
                    $otherUses = true;
                    break;
                }
            }
            if (!$otherUses) {
                $secureFile = $this->storagePath . '/' . $hash . '.' . $ext;
                if (is_file($secureFile) && @unlink($secureFile)) {
                    $deleted = true;
                }
            }
            $this->actualizarMetaAtomico(function (array $m) use ($clave): array {
                unset($m[$clave]);
                return $m;
            });
        }

        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clave);
        $publicFiles = glob($this->publicPath . '/' . $safeKey . '.*') ?: [];
        foreach ($publicFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return $deleted;
    }

    public function existeImagenProducto(int $idProducto): bool {
        return $this->getRutaImagenProducto($idProducto) !== null;
    }

    public function getRutaImagenProducto(int $idProducto): ?string {
        if ($idProducto <= 0) {
            return null;
        }
        $clave = self::claveProducto($idProducto);
        $ruta = $this->getRutaImagen($clave);
        if ($ruta !== null) {
            return $ruta;
        }
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clave);
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            $candidate = $this->publicPath . '/' . $safeKey . '.' . $ext;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public function existeImagen(string $clave): bool {
        return $this->getRutaImagen($clave) !== null;
    }

    public function getRutaImagen(string $clave): ?string {
        $meta = $this->cargarMeta();
        if (is_array($meta) && isset($meta[$clave])) {
            $ext = (string)($meta[$clave]['ext'] ?? '');
            $hash = (string)($meta[$clave]['hash'] ?? '');
            if (preg_match('/^[a-f0-9]{16,128}$/', $hash) && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $path = $this->storagePath . '/' . $hash . '.' . $ext;
                if (is_file($path)) {
                    return $path;
                }
            }
        }
        return null;
    }

    public function verificarIntegridad(string $clave): array {
        $meta = $this->cargarMeta();
        if (!is_array($meta) || !isset($meta[$clave])) {
            return ['ok' => false, 'integridad_ok' => false, 'message' => 'No se encontró registro de hash para esta clave.'];
        }
        $ext = $meta[$clave]['ext'];
        $hash = $meta[$clave]['hash'];
        $archivo = $this->storagePath . '/' . $hash . '.' . $ext;
        if (!is_file($archivo)) {
            return ['ok' => false, 'integridad_ok' => false, 'message' => 'El archivo de imagen no existe.'];
        }
        $hashActual = hash('sha256', (string)file_get_contents($archivo));
        $integro = ($hashActual === $hash);
        return [
            'ok' => true,
            'integridad_ok' => $integro,
            'hash_esperado' => $hash,
            'hash_actual' => $hashActual,
        ];
    }

    private function escribirArchivoAtomico(string $destino, string $content, string $expectedHash): bool {
        $dir = dirname($destino);
        if (!is_dir($dir) || !is_writable($dir) || is_dir($destino)) {
            return false;
        }
        $tmp = $destino . '.tmp.' . bin2hex(random_bytes(4));
        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($content)) {
            @unlink($tmp);
            return false;
        }
        if (@hash_file('sha256', $tmp) !== $expectedHash) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $destino)) {
            @unlink($tmp);
            return false;
        }
        return is_file($destino) && @hash_file('sha256', $destino) === $expectedHash;
    }

    /**
     * @return array<string,mixed>|null null si .meta.json existe pero no es legible o está corrupto
     */
    private function cargarMeta(): ?array {
        $metaFile = $this->storagePath . '/.meta.json';
        if (is_dir($metaFile)) {
            return null;
        }
        if (!file_exists($metaFile)) {
            return [];
        }
        if (!is_readable($metaFile)) {
            return null;
        }
        $raw = @file_get_contents($metaFile);
        if ($raw === false) {
            return null;
        }
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lee, transforma y persiste `.meta.json` mediante archivo temporal + rename atómico,
     * bajo un lock separado (`.meta.lock`), evitando truncar o perder entradas si la escritura falla.
     *
     * @return array{ok:bool, message:string, previous_meta:array<string,mixed>}
     */
    private function actualizarMetaAtomico(callable $mutator): array {
        $metaFile = $this->storagePath . '/.meta.json';
        $lockFile = $this->storagePath . '/.meta.lock';

        if (is_dir($metaFile)) {
            return ['ok' => false, 'message' => 'La ruta de .meta.json no es un archivo válido.', 'previous_meta' => []];
        }
        if (file_exists($metaFile) && !is_writable($metaFile)) {
            return ['ok' => false, 'message' => 'El archivo .meta.json no tiene permisos de escritura.', 'previous_meta' => []];
        }
        if (!is_dir($this->storagePath) || !is_writable($this->storagePath)) {
            return ['ok' => false, 'message' => 'El directorio de almacenamiento no tiene permisos de escritura.', 'previous_meta' => []];
        }

        $lockFp = @fopen($lockFile, 'c+');
        if (!$lockFp || !flock($lockFp, LOCK_EX)) {
            if ($lockFp) fclose($lockFp);
            return ['ok' => false, 'message' => 'No se pudo adquirir bloqueo sobre .meta.json.', 'previous_meta' => []];
        }

        try {
            $actual = $this->cargarMeta();
            if ($actual === null) {
                return ['ok' => false, 'message' => 'No se pudo leer o parsear .meta.json existente.', 'previous_meta' => []];
            }
            $nuevo = $mutator($actual);
            if (!$this->guardarMetaAtomicoDirecto($nuevo)) {
                return ['ok' => false, 'message' => 'No se pudo escribir de forma atómica .meta.json.', 'previous_meta' => $actual];
            }
            return ['ok' => true, 'message' => 'OK', 'previous_meta' => $actual];
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    private function guardarMetaAtomicoDirecto(array $meta): bool {
        $metaFile = $this->storagePath . '/.meta.json';
        if (is_dir($metaFile) || (file_exists($metaFile) && !is_writable($metaFile))) {
            return false;
        }
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $tmpMeta = $this->storagePath . '/.meta.json.tmp.' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmpMeta, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            @unlink($tmpMeta);
            return false;
        }
        if (!@rename($tmpMeta, $metaFile)) {
            @unlink($tmpMeta);
            return false;
        }
        return true;
    }

    private function detectarMimeType(string $content, string $ext): ?string {
        if (isset($this->magicNumbers['image/jpeg']) && str_starts_with($content, $this->magicNumbers['image/jpeg'])) {
            return 'image/jpeg';
        }
        if (str_starts_with($content, $this->magicNumbers['image/png'])) {
            return 'image/png';
        }
        if (str_starts_with($content, $this->magicNumbers['image/gif']) || str_starts_with($content, $this->magicNumbers['image/gif2'])) {
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
