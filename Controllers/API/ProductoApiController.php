<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Libraries\Middleware\RBACMiddleware;
use Models\SubcategoriaModel;
use Services\ProductoService;
use Services\StorageService;
use PDOException;

class ProductoApiController extends ApiController {

    private SubcategoriaModel $subcategoriaModel;
    private ProductoService $productoService;
    private StorageService $storageService;

    public function __construct() {
        parent::__construct();
        $this->subcategoriaModel = new SubcategoriaModel();
        $this->storageService = new StorageService();
        $this->productoService = new ProductoService($this->model, $this->subcategoriaModel, $this->storageService);
    }

    public function get(?string $params = ""): void {
        $trimmedParams = trim((string)$params, '/');
        if (preg_match('#^codigo(?:/(.*))?$#i', $trimmedParams, $matches)) {
            $this->getProductoPorCodigoExacto($matches[1] ?? '');
            return;
        }

        $modo = $this->getParam('modo', '');
        if ($modo === 'reporte') {
            $this->requireAdminActivo('reportes.ver');
        } else {
            $this->requirePermission('productos.listar');
        }
        $this->getProductos($params);
    }

    /**
     * GET /api/productos/codigo/{codigo}
     * Consulta exacta compartida de solo lectura para POS (HU-21), Compras y Ajustes (HU-22).
     */
    private function getProductoPorCodigoExacto(string $rawCodigo): void {
        $rol = (int)($this->authenticatedUser['id_rol'] ?? ($_SESSION['rol'] ?? 0));
        $permisosHabilitados = [
            'productos.listar',
            'productos.crear',
            'productos.editar',
            'ventas.acceder',
            'compras.crear',
            'compras.editar',
            'inventario.ajustes',
        ];
        if (!RBACMiddleware::tieneAlgunPermiso($rol, $permisosHabilitados)) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'FORBIDDEN',
                'message' => 'No tienes permisos para consultar productos por código de barras.',
                'errors'  => new \stdClass(),
                'data'    => null,
            ], 403);
        }

        $codigo = trim(rawurldecode($rawCodigo));
        if ($codigo === '' || preg_match('/\s/', $codigo)) {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'INVALID_BARCODE',
                'message' => 'Debe proporcionar un código de barras válido sin espacios.',
                'errors'  => ['codigo_barra' => 'El código de barras es obligatorio y no debe contener espacios.'],
                'data'    => null,
            ], 400);
        }

        $producto = $this->productoService->buscarPorCodigoExacto($codigo);

        if ($producto === null) {
            $this->sendJsonResponse([
                'status'  => true,
                'code'    => 'PRODUCT_NOT_FOUND',
                'message' => 'No se encontró ningún producto con el código indicado.',
                'data'    => [
                    'estado_busqueda' => 'no_existe',
                    'producto'        => null,
                ],
            ], 200);
        }

        if ((int)($producto['estado'] ?? 0) !== 1) {
            $this->sendJsonResponse([
                'status'  => true,
                'code'    => 'PRODUCT_INACTIVE',
                'message' => 'El producto se encuentra inactivo.',
                'data'    => [
                    'estado_busqueda' => 'inactivo',
                    'producto'        => [
                        'id_producto'  => (int)$producto['id_producto'],
                        'codigo_barra' => (string)$producto['codigo_barra'],
                        'nombre'       => (string)$producto['nombre'],
                        'estado'       => 0,
                    ],
                ],
            ], 200);
        }

        $productoConImagen = $this->adjuntarMetadatosImagen($producto);
        $this->sendJsonResponse([
            'status' => true,
            'code'   => 'PRODUCT_FOUND',
            'data'   => [
                'estado_busqueda' => 'encontrado',
                'producto'        => $productoConImagen,
            ],
        ], 200);
    }

    public function post(?string $params = ""): void {
        // ApiController convierte POST multipart con _method=PUT en $this->requestMethod === 'PUT'
        if ($this->requestMethod === 'PUT' && !empty($params)) {
            $this->updateProducto($params);
            return;
        }
        if (!empty($params)) {
            $this->uploadImage($params);
        } else {
            $this->createProducto();
        }
    }

    public function put(?string $params = ""): void {
        $this->updateProducto($params);
    }

    public function delete(?string $params = ""): void {
        $this->deleteProducto($params);
    }

    private function adjuntarMetadatosImagen(array $producto): array {
        $id = (int)($producto['id_producto'] ?? 0);
        if ($id > 0) {
            $producto['tiene_imagen'] = $this->storageService->existeImagenProducto($id);
            $producto['imagen_url'] = $producto['tiene_imagen'] ? (API_URL . '/imagenes/producto/' . $id) : null;
        }
        return $producto;
    }

    private function getProductos(?string $id): void {
        $modo = $this->getParam('modo', '');
        if (!empty($id)) {
            $idVal = intval($id);
            $query = $this->model
                ->select([
                    'producto.*',
                    'categoria.nombre as categoria',
                    'subcategoria.nombre as subcategoria',
                    'unidad_medida.abreviatura as unidad'
                ])
                ->join('categoria', 'producto.id_categoria = categoria.id_categoria', 'INNER')
                ->join('subcategoria', 'producto.id_subcategoria = subcategoria.id_subcategoria', 'LEFT')
                ->join('unidad_medida', 'producto.id_unidad = unidad_medida.id_unidad', 'INNER')
                ->where(['producto.id_producto' => $idVal]);

            if ($modo === 'reporte') {
                $query->where(['(producto.estado = 1 OR EXISTS (SELECT 1 FROM kardex k WHERE k.id_producto = producto.id_producto))']);
            } else {
                $query->where(['producto.estado = 1']);
            }

            $producto = $query->first();

            if (!$producto) {
                $this->sendJsonResponse(['status' => false, 'message' => 'El producto solicitado no existe'], 404);
            }
            $this->sendJsonResponse(['status' => true, 'data' => $this->adjuntarMetadatosImagen($producto)]);
        } else {
            $search = $this->getParam('q', '');
            $categoria = $this->getParam('categoria', '');
            $subcategoria = $this->getParam('subcategoria', '');
            $stock = $this->getParam('stock', '');
            $page = max(1, intval($this->getParam('page', 1)));
            $limit = min(500, max(1, intval($this->getParam('limit', 10))));

            $query = $this->model
                ->select([
                    'producto.*',
                    'categoria.nombre as categoria',
                    'subcategoria.nombre as subcategoria',
                    'unidad_medida.abreviatura as unidad'
                ])
                ->join('categoria', 'producto.id_categoria = categoria.id_categoria', 'INNER')
                ->join('subcategoria', 'producto.id_subcategoria = subcategoria.id_subcategoria', 'LEFT')
                ->join('unidad_medida', 'producto.id_unidad = unidad_medida.id_unidad', 'INNER');

            if ($modo === 'reporte') {
                $query->where(['(producto.estado = 1 OR EXISTS (SELECT 1 FROM kardex k WHERE k.id_producto = producto.id_producto))']);
            } else {
                $query->where(['producto.estado = 1']);
            }

            if (!empty($search)) {
                $query->orLikeWhere([
                    'producto.nombre' => $search,
                    'producto.codigo_barra' => $search
                ], 'prod_search_');
            }
            if (!empty($categoria)) {
                $query->where(['producto.id_categoria' => intval($categoria)]);
            }
            if (!empty($subcategoria)) {
                $query->where(['producto.id_subcategoria' => intval($subcategoria)]);
            }
            if ($stock === 'bajo') {
                $query->where(['producto.stock_actual <= producto.stock_minimo']);
            }

            $total = $query->countWithQuery();
            $totalPaginas = max(1, (int)ceil($total / $limit));
            if ($page > $totalPaginas) $page = $totalPaginas;

            $offset = ($page - 1) * $limit;
            $productos = $query->orderBy('producto.nombre', 'ASC')->limit($limit)->offset($offset)->get();
            $productos = array_map(fn(array $p) => $this->adjuntarMetadatosImagen($p), $productos);

            $this->sendJsonResponse([
                'status' => true,
                'data' => $productos,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => $totalPaginas
                ]
            ]);
        }
    }

    private function createProducto(): void {
        $this->requirePermission('productos.crear');

        $data = $this->getInput();

        if (empty($data['nombre']) || !isset($data['precio_venta']) || $data['precio_venta'] === '') {
            $this->sendJsonResponse([
                'status' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'Faltan campos obligatorios: nombre, precio_venta',
                'errors' => [
                    'nombre' => empty($data['nombre']) ? 'El campo nombre es obligatorio.' : null,
                    'precio_venta' => (!isset($data['precio_venta']) || $data['precio_venta'] === '') ? 'El campo precio_venta es obligatorio.' : null,
                ],
            ], 400);
        }

        $resultado = $this->productoService->crear($data);
        if (!$resultado['status']) {
            $httpCode = !empty($resultado['errors']) ? 422 : 500;
            $this->sendJsonResponse($resultado, $httpCode);
        }

        $idInsertado = (int)$resultado['id_producto'];
        $respuestaData = [
            'id_producto'  => $idInsertado,
            'codigo_barra' => $resultado['codigo_barra'] ?? null,
            'imagen_url'   => null,
        ];

        if (!empty($_FILES['imagen']) && ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $img = $this->storageService->guardarImagenPorProducto($_FILES['imagen'], $idInsertado);
            if (!$img['ok'] && $img['message'] !== 'No se envió archivo.') {
                $this->sendJsonResponse([
                    'status'  => true,
                    'warning' => 'IMAGE_SAVE_FAILED',
                    'message' => 'Producto registrado, pero la imagen no se guardó: ' . $img['message'],
                    'data'    => $respuestaData,
                ], 201);
            }
            if ($img['ok']) {
                $respuestaData['imagen_url'] = API_URL . '/imagenes/producto/' . $idInsertado;
            }
        }

        $this->sendJsonResponse([
            'status'  => true,
            'message' => 'Producto registrado exitosamente',
            'data'    => $respuestaData,
        ], 201);
    }

    private function updateProducto(?string $id): void {
        $this->requirePermission('productos.editar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador del producto es requerido'], 400);
        }

        $idVal = intval($id);
        $productoExistente = $this->model->find($idVal);

        if (!$productoExistente || (int)$productoExistente['estado'] !== 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El recurso a modificar no existe'], 404);
        }

        $data = $this->getInput();
        $datosActualizar = [];

        if (array_key_exists('nombre', $data)) $datosActualizar['nombre'] = trim((string)$data['nombre']);
        if (array_key_exists('descripcion', $data)) $datosActualizar['descripcion'] = trim((string)$data['descripcion']);
        if (array_key_exists('id_categoria', $data)) $datosActualizar['id_categoria'] = intval($data['id_categoria']);
        if (array_key_exists('id_subcategoria', $data) && $data['id_subcategoria'] !== '') $datosActualizar['id_subcategoria'] = intval($data['id_subcategoria']);
        if (array_key_exists('id_unidad', $data)) $datosActualizar['id_unidad'] = intval($data['id_unidad']);
        if (array_key_exists('precio_venta', $data)) $datosActualizar['precio_venta'] = floatval($data['precio_venta']);
        if (array_key_exists('stock_minimo', $data)) $datosActualizar['stock_minimo'] = intval($data['stock_minimo']);

        // Validar código de barras con la misma regla unificada de ProductoService
        $codigoCheck = $this->productoService->validarYNormalizarCodigoBarra($data, $productoExistente);
        if ($codigoCheck['present']) {
            $datosActualizar['codigo_barra'] = $codigoCheck['value'];
        }

        $hasFile = !empty($_FILES['imagen']) && ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (empty($datosActualizar) && !$hasFile) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se proporcionaron datos para actualizar'], 400);
        }

        $validator = new \Libraries\Core\Validation($datosActualizar);
        if (isset($datosActualizar['nombre'])) {
            $validator->minLength('nombre', 3)->maxLength('nombre', 150);
        }
        if (isset($datosActualizar['precio_venta'])) {
            $validator->positiveNumber('precio_venta');
        }
        if (isset($datosActualizar['stock_minimo'])) {
            $validator->nonNegativeNumber('stock_minimo');
        }
        if ($codigoCheck['error'] !== null) {
            $validator->addError('codigo_barra', $codigoCheck['error']);
        }

        if ($validator->fails()) {
            $code = $codigoCheck['code'] ?? 'VALIDATION_ERROR';
            $message = $code === 'BARCODE_DUPLICATE' ? 'El código de barras ya está en uso.' : 'Error de validación';
            $this->sendJsonResponse([
                'status' => false,
                'code' => $code,
                'message' => $message,
                'errors' => $validator->errors(),
            ], 422);
        }

        $catEfectiva = $datosActualizar['id_categoria'] ?? intval($productoExistente['id_categoria']);
        if (isset($datosActualizar['id_subcategoria']) && $datosActualizar['id_subcategoria'] > 0) {
            if (!$this->subcategoriaModel->perteneceACategoria($datosActualizar['id_subcategoria'], $catEfectiva)) {
                $this->sendJsonResponse([
                    'status' => false,
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'La subcategoría no corresponde a la categoría seleccionada.',
                    'errors' => ['id_subcategoria' => 'La subcategoría no pertenece a la categoría.']
                ], 422);
            }
        } elseif (isset($datosActualizar['id_categoria']) && $datosActualizar['id_categoria'] !== intval($productoExistente['id_categoria'])) {
            $subcats = $this->subcategoriaModel->obtenerPorCategoria($catEfectiva, true);
            if (!empty($subcats)) {
                $datosActualizar['id_subcategoria'] = (int)$subcats[0]['id_subcategoria'];
            }
        }

        // Antes de liberar o cambiar el código en BD, asegurar que la foto legada quede asociada a prod_{id}.
        // Si existe una foto heredada y no puede preservarse, impedir el cambio/retiro y conservar el código actual.
        if ($codigoCheck['changed'] && !empty($productoExistente['codigo_barra'])) {
            $prom = $this->storageService->promoverImagenAClaveProducto($idVal, (string)$productoExistente['codigo_barra']);
            if (!$prom['ok']) {
                $this->sendJsonResponse([
                    'status'  => false,
                    'code'    => 'LEGACY_IMAGE_PRESERVATION_FAILED',
                    'message' => 'No se pudo preservar la foto asociada al producto antes de modificar el código de barras: ' . $prom['message'],
                    'errors'  => ['codigo_barra' => 'No se pudo preservar la foto actual del producto (' . $prom['message'] . '). El código no fue modificado.'],
                ], 409);
            }
        }

        try {
            $exito = empty($datosActualizar) ? true : (bool)$this->model->update($idVal, $datosActualizar);
        } catch (PDOException $e) {
            if (ProductoService::esColisionCodigoBarraUnico($e)) {
                $this->sendJsonResponse([
                    'status' => false,
                    'code' => 'BARCODE_DUPLICATE',
                    'message' => 'El código de barras ya está en uso.',
                    'errors' => ['codigo_barra' => ProductoService::MSG_DUPLICADO_GENERICO],
                ], 422);
            }
            throw $e;
        }

        if (!$exito) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se pudo actualizar el registro en el servidor'], 500);
        }

        $codigoFinal = $codigoCheck['present'] ? $codigoCheck['value'] : $productoExistente['codigo_barra'];
        $tieneImagen = $this->storageService->existeImagenProducto($idVal);
        $respuestaData = [
            'id_producto'  => $idVal,
            'codigo_barra' => $codigoFinal,
            'imagen_url'   => $tieneImagen ? (API_URL . '/imagenes/producto/' . $idVal) : null,
        ];

        if ($hasFile) {
            $img = $this->storageService->guardarImagenPorProducto($_FILES['imagen'], $idVal);
            if (!$img['ok'] && $img['message'] !== 'No se envió archivo.') {
                $this->sendJsonResponse([
                    'status'  => true,
                    'warning' => 'IMAGE_SAVE_FAILED',
                    'message' => 'Producto actualizado, pero la imagen no se guardó: ' . $img['message'],
                    'data'    => $respuestaData,
                ], 200);
            }
            if ($img['ok']) {
                $respuestaData['imagen_url'] = API_URL . '/imagenes/producto/' . $idVal;
            }
        }

        $this->sendJsonResponse([
            'status'  => true,
            'message' => 'Producto actualizado de forma exitosa',
            'data'    => $respuestaData,
        ], 200);
    }

    private function uploadImage(?string $id): void {
        $this->requirePermission('productos.editar');
        $idVal = intval($id);
        $producto = $this->model->find($idVal);

        if (!$producto || (int)$producto['estado'] !== 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El producto no existe'], 404);
        }

        if (empty($_FILES['imagen']) || ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se envió ninguna imagen'], 400);
        }

        $img = $this->storageService->guardarImagenPorProducto($_FILES['imagen'], $idVal);
        if ($img['ok']) {
            // Solo tras confirmar la nueva imagen bajo prod_{id}, limpiar restos legados del código si existían
            if (!empty($producto['codigo_barra'])) {
                $this->storageService->limpiarRestosLegadosCodigo($idVal, (string)$producto['codigo_barra']);
            }
            $this->sendJsonResponse([
                'status'  => true,
                'message' => 'Imagen actualizada correctamente',
                'data'    => [
                    'id_producto' => $idVal,
                    'imagen_url'  => API_URL . '/imagenes/producto/' . $idVal,
                ],
            ]);
        } else {
            $this->sendJsonResponse([
                'status'  => false,
                'code'    => 'IMAGE_SAVE_FAILED',
                'message' => $img['message'],
            ], 400);
        }
    }

    private function deleteProducto(?string $id): void {
        $this->requirePermission('productos.eliminar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador es requerido'], 400);
        }

        $idVal = intval($id);
        $productoExistente = $this->model->find($idVal);

        if (!$productoExistente || (int)$productoExistente['estado'] !== 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El recurso no fue encontrado'], 404);
        }

        $exito = $this->model->update($idVal, ['estado' => 0]);

        if ($exito) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Producto eliminado correctamente']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Falló la eliminación interna del recurso'], 500);
        }
    }
}
