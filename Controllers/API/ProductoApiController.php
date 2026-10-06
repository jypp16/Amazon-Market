<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Models\CategoriaModel;
use Models\UnidadMedidaModel;

class ProductoApiController extends ApiController {

    public function get(?string $params = ""): void {
        $this->requirePermission('productos.listar');
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
        if (!empty($id)) {
            $idVal = intval($id);
            $producto = $this->model
                ->select(['producto.*', 'categoria.nombre as categoria', 'unidad_medida.abreviatura as unidad'])
                ->join('categoria', 'producto.id_categoria = categoria.id_categoria', 'INNER')
                ->join('unidad_medida', 'producto.id_unidad = unidad_medida.id_unidad', 'INNER')
                ->where(['producto.id_producto' => $idVal, 'producto.estado = 1'])
                ->first();

            if (!$producto) {
                $this->sendJsonResponse(['status' => false, 'message' => 'El producto solicitado no existe'], 404);
            }
            $this->sendJsonResponse(['status' => true, 'data' => $producto]);
        } else {
            $search = $this->getParam('q', '');
            $categoria = $this->getParam('categoria', '');
            $stock = $this->getParam('stock', '');
            $page = max(1, intval($this->getParam('page', 1)));
            $limit = min(500, max(1, intval($this->getParam('limit', 10))));

            $query = $this->model
                ->select(['producto.*', 'categoria.nombre as categoria', 'unidad_medida.abreviatura as unidad'])
                ->join('categoria', 'producto.id_categoria = categoria.id_categoria', 'INNER')
                ->join('unidad_medida', 'producto.id_unidad = unidad_medida.id_unidad', 'INNER')
                ->where(['producto.estado = 1']);

            if (!empty($search)) {
                $query->orLikeWhere([
                    'producto.nombre' => $search,
                    'producto.codigo_barra' => $search
                ], 'prod_search_');
            }
            if (!empty($categoria)) {
                $query->where(['producto.id_categoria' => intval($categoria)]);
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

        $unidadesDecimales = ['KG', 'LTR', 'LT', 'ML', 'G', 'OZ', 'LB', 'GAL', 'M', 'CM'];
        $unidadModel = new \Models\UnidadMedidaModel();
        $unidad = $unidadModel->find(intval($data['id_unidad'] ?? 1));
        $abrev = strtoupper(trim($unidad['abreviatura'] ?? ''));
        $aceptaDecimales = in_array($abrev, $unidadesDecimales);

        if (!$aceptaDecimales) {
            $stock = floatval($data['stock_actual'] ?? 0);
            $minimo = floatval($data['stock_minimo'] ?? 1);
            if ($stock != intval($stock) || $minimo != intval($minimo)) {
                $this->sendJsonResponse(['status' => false, 'message' => 'La unidad "' . ($unidad['nombre'] ?? '') . '" no acepta cantidades decimales. Use valores enteros.'], 422);
            }
        }

        $productoData = [
            'codigo_barra' => trim($data['codigo_barra']),
            'nombre' => trim($data['nombre']),
            'descripcion' => trim($data['descripcion'] ?? ''),
            'id_categoria' => intval($data['id_categoria'] ?? 1),
            'id_unidad' => intval($data['id_unidad'] ?? 1),
            'precio_venta' => floatval($data['precio_venta']),
            'stock_actual' => floatval($data['stock_actual'] ?? 0),
            'stock_minimo' => floatval($data['stock_minimo'] ?? 1),
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
        if (isset($data['id_unidad'])) $datosActualizar['id_unidad'] = intval($data['id_unidad']);
        if (isset($data['precio_venta'])) $datosActualizar['precio_venta'] = floatval($data['precio_venta']);
        if (isset($data['stock_actual'])) $datosActualizar['stock_actual'] = floatval($data['stock_actual']);
        if (isset($data['stock_minimo'])) $datosActualizar['stock_minimo'] = floatval($data['stock_minimo']);

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
        if (isset($datosActualizar['stock_actual'])) {
            $validator->nonNegativeNumber('stock_actual');
        }
        if (isset($datosActualizar['stock_minimo'])) {
            $validator->nonNegativeNumber('stock_minimo');
        }

        if ($validator->fails()) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        if (isset($datosActualizar['id_unidad']) || isset($datosActualizar['stock_actual']) || isset($datosActualizar['stock_minimo'])) {
            $unidadesDecimales = ['KG', 'LTR', 'LT', 'ML', 'G', 'OZ', 'LB', 'GAL', 'M', 'CM'];
            $unidadId = intval($data['id_unidad'] ?? $productoExistente['id_unidad']);
            $unidadModel = new \Models\UnidadMedidaModel();
            $unidad = $unidadModel->find($unidadId);
            $abrev = strtoupper(trim($unidad['abreviatura'] ?? ''));
            $aceptaDecimales = in_array($abrev, $unidadesDecimales);

            if (!$aceptaDecimales) {
                $stock = floatval($data['stock_actual'] ?? $productoExistente['stock_actual']);
                $minimo = floatval($data['stock_minimo'] ?? $productoExistente['stock_minimo']);
                if ($stock != intval($stock) || $minimo != intval($minimo)) {
                    $this->sendJsonResponse(['status' => false, 'message' => 'La unidad "' . ($unidad['nombre'] ?? '') . '" no acepta cantidades decimales. Use valores enteros.'], 422);
                }
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
