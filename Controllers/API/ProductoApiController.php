<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Models\CategoriaModel;
use Models\SubcategoriaModel;
use Models\UnidadMedidaModel;

class ProductoApiController extends ApiController {

    private SubcategoriaModel $subcategoriaModel;

    public function __construct() {
        parent::__construct();
        $this->subcategoriaModel = new SubcategoriaModel();
    }

    public function get(?string $params = ""): void {
        $modo = $this->getParam('modo', '');
        if ($modo === 'reporte') {
            $this->requireAdminActivo('reportes.ver');
        } else {
            $this->requirePermission('productos.listar');
        }
        $this->getProductos($params);
    }

    public function post(?string $params = ""): void {
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
            $this->sendJsonResponse(['status' => true, 'data' => $producto]);
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
            $totalPaginas = max(1, ceil($total / $limit));
            if ($page > $totalPaginas) $page = $totalPaginas;

            $offset = ($page - 1) * $limit;
            $productos = $query->orderBy('producto.nombre', 'ASC')->limit($limit)->offset($offset)->get();

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

        if (empty($data['nombre']) || empty($data['codigo_barra']) || empty($data['precio_venta'])) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Faltan campos obligatorios: nombre, codigo_barra, precio_venta'], 400);
        }

        $validator = new \Libraries\Core\Validation($data);
        $validator->required(['nombre', 'codigo_barra', 'precio_venta'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->minLength('codigo_barra', 3)
            ->maxLength('codigo_barra', 50)
            ->positiveNumber('precio_venta')
            ->unique('codigo_barra', $this->model);

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        $idCategoria = intval($data['id_categoria'] ?? 1);
        $idSubcategoria = isset($data['id_subcategoria']) && $data['id_subcategoria'] !== ''
            ? intval($data['id_subcategoria'])
            : null;

        // Validar correspondencia estricta entre subcategoría y categoría
        if ($idSubcategoria !== null) {
            if (!$this->subcategoriaModel->perteneceACategoria($idSubcategoria, $idCategoria)) {
                $this->sendJsonResponse([
                    'status' => false,
                    'message' => 'La subcategoría seleccionada no pertenece a la categoría elegida.',
                    'errors' => ['id_subcategoria' => 'La subcategoría no corresponde a la categoría.']
                ], 422);
            }
        } else {
            // Asignar automáticamente la subcategoría General de la categoría
            $subcats = $this->subcategoriaModel->obtenerPorCategoria($idCategoria, true);
            if (!empty($subcats)) {
                $idSubcategoria = (int)$subcats[0]['id_subcategoria'];
            }
        }

        $stockMinimo = isset($data['stock_minimo']) ? intval($data['stock_minimo']) : 1;
        if ($stockMinimo < 0) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El stock mínimo debe ser un número entero mayor o igual a cero.'], 422);
        }

        // REGLA FUNDAMENTAL DEL SPRINT 2:
        // Los productos nuevos comienzan estrictamente con stock cero.
        $productoData = [
            'codigo_barra' => trim($data['codigo_barra']),
            'nombre' => trim($data['nombre']),
            'descripcion' => trim($data['descripcion'] ?? ''),
            'id_categoria' => $idCategoria,
            'id_subcategoria' => $idSubcategoria,
            'id_unidad' => intval($data['id_unidad'] ?? 1),
            'precio_venta' => floatval($data['precio_venta']),
            'stock_actual' => 0, // Siempre cero en creación
            'stock_minimo' => $stockMinimo,
            'estado' => 1
        ];

        $exito = $this->model->insert($productoData);

        if ($exito) {
            if (!empty($_FILES['imagen']) && $_FILES['imagen']['error'] !== UPLOAD_ERR_NO_FILE) {
                $img = guardar_imagen_producto($_FILES['imagen'], trim($data['codigo_barra']));
                if (!$img['ok'] && $img['message'] !== 'No se envió archivo.') {
                    $this->sendJsonResponse(['status' => true, 'message' => 'Producto registrado, pero la imagen no se guardó: ' . $img['message']], 201);
                }
            }
            $this->sendJsonResponse(['status' => true, 'message' => 'Producto registrado exitosamente'], 201);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Ocurrió un error interno en el servidor'], 500);
        }
    }

    private function updateProducto(?string $id): void {
        $this->requirePermission('productos.editar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador del producto es requerido'], 400);
        }

        $idVal = intval($id);
        $productoExistente = $this->model->find($idVal);

        if (!$productoExistente || $productoExistente['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El recurso a modificar no existe'], 404);
        }

        $data = $this->getInput();
        $datosActualizar = [];

        if (isset($data['nombre'])) $datosActualizar['nombre'] = trim($data['nombre']);
        if (isset($data['codigo_barra'])) $datosActualizar['codigo_barra'] = trim($data['codigo_barra']);
        if (isset($data['descripcion'])) $datosActualizar['descripcion'] = trim($data['descripcion']);
        if (isset($data['id_categoria'])) $datosActualizar['id_categoria'] = intval($data['id_categoria']);
        if (isset($data['id_subcategoria'])) $datosActualizar['id_subcategoria'] = intval($data['id_subcategoria']);
        if (isset($data['id_unidad'])) $datosActualizar['id_unidad'] = intval($data['id_unidad']);
        if (isset($data['precio_venta'])) $datosActualizar['precio_venta'] = floatval($data['precio_venta']);
        if (isset($data['stock_minimo'])) $datosActualizar['stock_minimo'] = intval($data['stock_minimo']);

        // REGLA FUNDAMENTAL DEL SPRINT 2:
        // No se permite modificar el stock_actual desde la edición del catálogo.
        // Se ignora intencionalmente $data['stock_actual'].

        if (empty($datosActualizar)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se proporcionaron datos para actualizar'], 400);
        }

        $validator = new \Libraries\Core\Validation($datosActualizar);

        if (isset($datosActualizar['nombre'])) {
            $validator->minLength('nombre', 3)
                ->maxLength('nombre', 100);
        }
        if (isset($datosActualizar['codigo_barra'])) {
            $validator->minLength('codigo_barra', 3)
                ->maxLength('codigo_barra', 50)
                ->unique('codigo_barra', $this->model, $idVal);
        }
        if (isset($datosActualizar['precio_venta'])) {
            $validator->positiveNumber('precio_venta');
        }
        if (isset($datosActualizar['stock_minimo'])) {
            $validator->nonNegativeNumber('stock_minimo');
        }

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        $catEfectiva = $datosActualizar['id_categoria'] ?? intval($productoExistente['id_categoria']);
        if (isset($datosActualizar['id_subcategoria']) && $datosActualizar['id_subcategoria'] > 0) {
            if (!$this->subcategoriaModel->perteneceACategoria($datosActualizar['id_subcategoria'], $catEfectiva)) {
                $this->sendJsonResponse([
                    'status' => false,
                    'message' => 'La subcategoría no corresponde a la categoría seleccionada.',
                    'errors' => ['id_subcategoria' => 'La subcategoría no pertenece a la categoría.']
                ], 422);
            }
        } elseif (isset($datosActualizar['id_categoria']) && $datosActualizar['id_categoria'] !== intval($productoExistente['id_categoria'])) {
            // Si cambió la categoría pero no se envió subcategoría, asignar General de la nueva
            $subcats = $this->subcategoriaModel->obtenerPorCategoria($catEfectiva, true);
            if (!empty($subcats)) {
                $datosActualizar['id_subcategoria'] = (int)$subcats[0]['id_subcategoria'];
            }
        }

        $exito = $this->model->update($idVal, $datosActualizar);

        if ($exito) {
            if (isset($data['codigo_barra']) && $productoExistente['codigo_barra'] !== trim($data['codigo_barra'])) {
                renombrar_imagen_producto($productoExistente['codigo_barra'], trim($data['codigo_barra']));
            }
            if (!empty($_FILES['imagen']) && $_FILES['imagen']['error'] !== UPLOAD_ERR_NO_FILE) {
                $codigo = trim($data['codigo_barra'] ?? $productoExistente['codigo_barra']);
                $img = guardar_imagen_producto($_FILES['imagen'], $codigo);
                if (!$img['ok'] && $img['message'] !== 'No se envió archivo.') {
                    $this->sendJsonResponse(['status' => true, 'message' => 'Producto actualizado, pero la imagen no se guardó: ' . $img['message']]);
                }
            }
            $this->sendJsonResponse(['status' => true, 'message' => 'Producto actualizado de forma exitosa']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se pudo actualizar el registro en el servidor'], 500);
        }
    }

    private function uploadImage(?string $id): void {
        $this->requirePermission('productos.editar');
        $idVal = intval($id);
        $producto = $this->model->find($idVal);

        if (!$producto || $producto['estado'] != 1) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El producto no existe'], 404);
        }

        if (empty($_FILES['imagen']) || $_FILES['imagen']['error'] === UPLOAD_ERR_NO_FILE) {
            $this->sendJsonResponse(['status' => false, 'message' => 'No se envió ninguna imagen'], 400);
        }

        $img = guardar_imagen_producto($_FILES['imagen'], trim($producto['codigo_barra']));
        if ($img['ok']) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Imagen actualizada correctamente']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => $img['message']], 400);
        }
    }

    private function deleteProducto(?string $id): void {
        $this->requirePermission('productos.eliminar');

        if (empty($id)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El identificador es requerido'], 400);
        }

        $idVal = intval($id);
        $productoExistente = $this->model->find($idVal);

        if (!$productoExistente || $productoExistente['estado'] != 1) {
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
