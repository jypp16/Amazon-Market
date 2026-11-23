<?php

namespace Services;

use Models\ProductoModel;
use Models\SubcategoriaModel;
use Libraries\Core\Validation;
use PDO;
use PDOException;

class ProductoService {

    public const REGEX_CODIGO_BARRA_NUEVO = '/^\d{8,14}$/';
    public const MSG_FORMATO_CODIGO = 'Ingresa o escanea un código válido: solo números, de 8 a 14 dígitos.';
    public const MSG_DUPLICADO_GENERICO = 'Este código ya está asociado a otro producto. Usa un código distinto.';

    private ProductoModel $model;
    private SubcategoriaModel $subcategoriaModel;
    private StorageService $storageService;

    public function __construct(?ProductoModel $model = null, ?SubcategoriaModel $subcategoriaModel = null, ?StorageService $storageService = null) {
        $this->model = $model ?? new ProductoModel();
        $this->subcategoriaModel = $subcategoriaModel ?? new SubcategoriaModel();
        $this->storageService = $storageService ?? new StorageService();
    }

    /**
     * Regla única de validación y normalización de `codigo_barra` (HU-20) para rutas API y web.
     *
     * @return array{present:bool, value:?string, changed:bool, error:?string, code:?string}
     */
    public function validarYNormalizarCodigoBarra(array $input, ?array $productoExistente = null): array {
        $esEdicion = $productoExistente !== null;
        $actualRaw = $esEdicion ? ($productoExistente['codigo_barra'] ?? null) : null;
        $actual = ($actualRaw !== null && trim((string)$actualRaw) !== '') ? trim((string)$actualRaw) : null;

        // En edición, si la clave se omite por completo, conservar el valor actual intacto
        if ($esEdicion && !array_key_exists('codigo_barra', $input)) {
            return [
                'present' => false,
                'value'   => $actual,
                'changed' => false,
                'error'   => null,
                'code'    => null,
            ];
        }

        $raw = $input['codigo_barra'] ?? null;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [
                'present' => true,
                'value'   => null,
                'changed' => $esEdicion && ($actual !== null),
                'error'   => null,
                'code'    => null,
            ];
        }

        if (!is_scalar($raw) || is_bool($raw)) {
            return [
                'present' => true,
                'value'   => null,
                'changed' => true,
                'error'   => self::MSG_FORMATO_CODIGO,
                'code'    => 'VALIDATION_ERROR',
            ];
        }

        $normalizado = trim((string)$raw);
        if ($normalizado === '') {
            return [
                'present' => true,
                'value'   => null,
                'changed' => $esEdicion && ($actual !== null),
                'error'   => null,
                'code'    => null,
            ];
        }

        // En edición, reenviar exactamente el mismo código actual (incluido heredado de 6 dígitos) se acepta sin cambio
        if ($esEdicion && $actual !== null && $normalizado === $actual) {
            return [
                'present' => true,
                'value'   => $actual,
                'changed' => false,
                'error'   => null,
                'code'    => null,
            ];
        }

        // Asignación nueva o cambio a un código no vacío: exige 8 a 14 dígitos, conservando ceros iniciales
        if (!preg_match(self::REGEX_CODIGO_BARRA_NUEVO, $normalizado)) {
            return [
                'present' => true,
                'value'   => $normalizado,
                'changed' => true,
                'error'   => self::MSG_FORMATO_CODIGO,
                'code'    => 'VALIDATION_ERROR',
            ];
        }

        // Comprobar unicidad global (incluyendo productos inactivos con estado = 0)
        $excludeId = $esEdicion ? (int)$productoExistente['id_producto'] : null;
        $duplicado = $this->buscarOtroProductoPorCodigo($normalizado, $excludeId);
        if ($duplicado !== null) {
            $nombreOtro = trim((string)($duplicado['nombre'] ?? ''));
            $msg = $nombreOtro !== ''
                ? "Este código ya está asociado a otro producto ({$nombreOtro}). Usa un código distinto."
                : self::MSG_DUPLICADO_GENERICO;
            return [
                'present' => true,
                'value'   => $normalizado,
                'changed' => true,
                'error'   => $msg,
                'code'    => 'BARCODE_DUPLICATE',
            ];
        }

        return [
            'present' => true,
            'value'   => $normalizado,
            'changed' => true,
            'error'   => null,
            'code'    => null,
        ];
    }

    public function buscarOtroProductoPorCodigo(string $codigo, ?int $excludeId = null): ?array {
        $sql = 'SELECT id_producto, nombre, estado FROM producto WHERE codigo_barra = :codigo';
        if ($excludeId !== null) {
            $sql .= ' AND id_producto <> :exclude_id';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->model->conect()->prepare($sql);
        $stmt->bindValue(':codigo', $codigo, PDO::PARAM_STR);
        if ($excludeId !== null) {
            $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function esColisionCodigoBarraUnico(PDOException $e): bool {
        $sqlState = (string)$e->getCode();
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($sqlState === '23000' || $driverCode === 1062) {
            return str_contains($e->getMessage(), 'uq_codigo_barra') || str_contains($e->getMessage(), 'codigo_barra');
        }
        return false;
    }

    public function crear(array $input): array {
        $validator = new Validation($input);
        $validator->required(['nombre', 'precio_venta'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 150)
            ->positiveNumber('precio_venta');

        $codigoCheck = $this->validarYNormalizarCodigoBarra($input, null);
        if ($codigoCheck['error'] !== null) {
            $validator->addError('codigo_barra', $codigoCheck['error']);
        }

        if ($validator->fails()) {
            $code = $codigoCheck['code'] ?? 'VALIDATION_ERROR';
            $message = $code === 'BARCODE_DUPLICATE' ? 'El código de barras ya está en uso.' : 'Error de validación';
            return ['status' => false, 'code' => $code, 'message' => $message, 'errors' => $validator->errors()];
        }

        $idCategoria = intval($input['id_categoria'] ?? 1);
        $idSubcategoria = isset($input['id_subcategoria']) && $input['id_subcategoria'] !== ''
            ? intval($input['id_subcategoria'])
            : null;

        if ($idSubcategoria !== null) {
            if (!$this->subcategoriaModel->perteneceACategoria($idSubcategoria, $idCategoria)) {
                return [
                    'status' => false,
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'La subcategoría seleccionada no pertenece a la categoría elegida.',
                    'errors' => ['id_subcategoria' => 'La subcategoría no corresponde a la categoría.']
                ];
            }
        } else {
            $subcats = $this->subcategoriaModel->obtenerPorCategoria($idCategoria, true);
            if (!empty($subcats)) {
                $idSubcategoria = (int)$subcats[0]['id_subcategoria'];
            }
        }

        $stockMinimo = isset($input['stock_minimo']) && $input['stock_minimo'] !== '' ? intval($input['stock_minimo']) : 1;
        if ($stockMinimo < 0) {
            return [
                'status' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'El stock mínimo debe ser un número entero mayor o igual a cero.',
                'errors' => ['stock_minimo' => 'El stock mínimo no puede ser negativo.']
            ];
        }

        // Los productos nuevos comienzan estrictamente con stock cero.
        $productoData = [
            'codigo_barra'    => $codigoCheck['value'],
            'nombre'          => trim((string)$input['nombre']),
            'descripcion'     => trim((string)($input['descripcion'] ?? '')),
            'id_categoria'    => $idCategoria,
            'id_subcategoria' => $idSubcategoria,
            'id_unidad'       => intval($input['id_unidad'] ?? 1),
            'precio_venta'    => floatval($input['precio_venta']),
            'stock_actual'    => 0,
            'stock_minimo'    => $stockMinimo,
            'estado'          => 1,
        ];

        try {
            if ($this->model->insert($productoData)) {
                $idInsertado = (int)$this->model->conect()->lastInsertId();
                return [
                    'status'       => true,
                    'message'      => 'Producto creado con éxito.',
                    'id_producto'  => $idInsertado,
                    'codigo_barra' => $codigoCheck['value'],
                ];
            }
        } catch (PDOException $e) {
            if (self::esColisionCodigoBarraUnico($e)) {
                return [
                    'status'  => false,
                    'code'    => 'BARCODE_DUPLICATE',
                    'message' => 'El código de barras ya está en uso.',
                    'errors'  => ['codigo_barra' => self::MSG_DUPLICADO_GENERICO],
                ];
            }
            throw $e;
        }

        return ['status' => false, 'code' => 'INTERNAL_ERROR', 'message' => 'Error al guardar el producto.'];
    }

    public function actualizar(int $id, array $input): array {
        $productoExistente = $this->model->find($id);
        if (!$productoExistente || (int)$productoExistente['estado'] !== 1) {
            return ['status' => false, 'code' => 'NOT_FOUND', 'message' => 'El producto no existe o se encuentra inactivo.'];
        }

        $validator = new Validation($input);
        $validator->required(['nombre', 'precio_venta'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 150)
            ->positiveNumber('precio_venta');

        $codigoCheck = $this->validarYNormalizarCodigoBarra($input, $productoExistente);
        if ($codigoCheck['error'] !== null) {
            $validator->addError('codigo_barra', $codigoCheck['error']);
        }

        if ($validator->fails()) {
            $code = $codigoCheck['code'] ?? 'VALIDATION_ERROR';
            $message = $code === 'BARCODE_DUPLICATE' ? 'El código de barras ya está en uso.' : 'Error de validación';
            return ['status' => false, 'code' => $code, 'message' => $message, 'errors' => $validator->errors()];
        }

        $idCategoria = isset($input['id_categoria'])
            ? intval($input['id_categoria'])
            : intval($productoExistente['id_categoria']);

        $idSubcategoria = isset($input['id_subcategoria']) && $input['id_subcategoria'] !== ''
            ? intval($input['id_subcategoria'])
            : (int)$productoExistente['id_subcategoria'];

        if ($idSubcategoria) {
            if (!$this->subcategoriaModel->perteneceACategoria($idSubcategoria, $idCategoria)) {
                $subcats = $this->subcategoriaModel->obtenerPorCategoria($idCategoria, true);
                if (!empty($subcats)) {
                    $idSubcategoria = (int)$subcats[0]['id_subcategoria'];
                } else {
                    return [
                        'status' => false,
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'La subcategoría seleccionada no pertenece a la categoría elegida.',
                        'errors' => ['id_subcategoria' => 'La subcategoría no corresponde a la categoría.']
                    ];
                }
            }
        }

        $stockMinimo = isset($input['stock_minimo']) && $input['stock_minimo'] !== ''
            ? intval($input['stock_minimo'])
            : intval($productoExistente['stock_minimo']);

        if ($stockMinimo < 0) {
            return [
                'status' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'El stock mínimo debe ser un número entero mayor o igual a cero.',
                'errors' => ['stock_minimo' => 'El stock mínimo no puede ser negativo.']
            ];
        }

        // Promover foto legada por código a prod_{id} antes de liberar o cambiar el código en BD.
        // Si existe una foto heredada y no puede preservarse, impedir el cambio/retiro del código.
        if ($codigoCheck['changed'] && !empty($productoExistente['codigo_barra'])) {
            $prom = $this->storageService->promoverImagenAClaveProducto($id, (string)$productoExistente['codigo_barra']);
            if (!$prom['ok']) {
                return [
                    'status'  => false,
                    'code'    => 'LEGACY_IMAGE_PRESERVATION_FAILED',
                    'message' => 'No se pudo preservar la foto asociada al producto antes de modificar el código de barras: ' . $prom['message'],
                    'errors'  => ['codigo_barra' => 'No se pudo preservar la foto actual del producto (' . $prom['message'] . '). El código no fue modificado.'],
                ];
            }
        }

        // No se permite modificar stock_actual desde el catálogo.
        $productoData = [
            'nombre'          => trim((string)$input['nombre']),
            'descripcion'     => trim((string)($input['descripcion'] ?? ($productoExistente['descripcion'] ?? ''))),
            'id_categoria'    => $idCategoria,
            'id_subcategoria' => $idSubcategoria,
            'id_unidad'       => intval($input['id_unidad'] ?? $productoExistente['id_unidad']),
            'precio_venta'    => floatval($input['precio_venta']),
            'stock_minimo'    => $stockMinimo,
        ];

        if ($codigoCheck['present']) {
            $productoData['codigo_barra'] = $codigoCheck['value'];
        }

        try {
            if ($this->model->update($id, $productoData)) {
                return [
                    'status'       => true,
                    'message'      => 'Producto actualizado con éxito.',
                    'id_producto'  => $id,
                    'codigo_barra' => $codigoCheck['present'] ? $codigoCheck['value'] : $productoExistente['codigo_barra'],
                ];
            }
        } catch (PDOException $e) {
            if (self::esColisionCodigoBarraUnico($e)) {
                return [
                    'status'  => false,
                    'code'    => 'BARCODE_DUPLICATE',
                    'message' => 'El código de barras ya está en uso.',
                    'errors'  => ['codigo_barra' => self::MSG_DUPLICADO_GENERICO],
                ];
            }
            throw $e;
        }

        return ['status' => false, 'code' => 'INTERNAL_ERROR', 'message' => 'Error al actualizar el producto.'];
    }

    public function eliminar(int $id): array {
        if ($this->model->update($id, ['estado' => 0])) {
            return ['status' => true, 'message' => 'Producto eliminado con éxito.'];
        }
        return ['status' => false, 'message' => 'Error al eliminar el producto.'];
    }

    public function obtenerTodos(): array {
        return $this->model->obtenerProductosConCategoria();
    }

    public function obtenerPorId(int $id) {
        return $this->model->find($id);
    }

    public function obtenerDetalle(int $id): ?array {
        $producto = $this->model->select([
            'producto.*',
            'categoria.nombre as categoria',
            'subcategoria.nombre as subcategoria',
            'unidad_medida.abreviatura as unidad'
        ])
            ->join('categoria', 'producto.id_categoria = categoria.id_categoria', 'INNER')
            ->join('subcategoria', 'producto.id_subcategoria = subcategoria.id_subcategoria', 'LEFT')
            ->join('unidad_medida', 'producto.id_unidad = unidad_medida.id_unidad', 'INNER')
            ->where(['producto.id_producto' => $id])
            ->get();

        return !empty($producto) ? $producto[0] : null;
    }

    /**
     * Consulta exacta por código de barras como texto (HU-21 / HU-22).
     * Conserva ceros iniciales, admite códigos heredados (p. ej. 6 dígitos) y
     * no filtra por estado para distinguir producto encontrado, inactivo o inexistente.
     */
    public function buscarPorCodigoExacto(string $codigo): ?array {
        $sql = 'SELECT p.*,
                       c.nombre AS categoria,
                       s.nombre AS subcategoria,
                       u.abreviatura AS unidad
                  FROM producto p
                 INNER JOIN categoria c ON c.id_categoria = p.id_categoria
                  LEFT JOIN subcategoria s ON s.id_subcategoria = p.id_subcategoria
                 INNER JOIN unidad_medida u ON u.id_unidad = p.id_unidad
                 WHERE p.codigo_barra = :codigo
                 LIMIT 1';

        $stmt = $this->model->conect()->prepare($sql);
        $stmt->bindValue(':codigo', $codigo, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
