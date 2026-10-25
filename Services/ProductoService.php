<?php

namespace Services;

use Models\ProductoModel;
use Models\SubcategoriaModel;
use Libraries\Core\Validation;
use Libraries\Core\InventarioException;

class ProductoService {

    private $model;
    private $subcategoriaModel;

    public function __construct() {
        $this->model = new ProductoModel();
        $this->subcategoriaModel = new SubcategoriaModel();
    }

    public function crear(array $input): array {
        $validator = new Validation($input);
        $validator->required(['nombre', 'codigo_barra', 'precio_venta'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->minLength('codigo_barra', 3)
            ->maxLength('codigo_barra', 50)
            ->positiveNumber('precio_venta')
            ->unique('codigo_barra', $this->model);

        if ($validator->fails()) {
            return ['status' => false, 'errors' => $validator->errors()];
        }

        $idCategoria = intval($input['id_categoria'] ?? 1);
        $idSubcategoria = isset($input['id_subcategoria']) && $input['id_subcategoria'] !== ''
            ? intval($input['id_subcategoria'])
            : null;

        // Validar correspondencia estricta entre subcategoría y categoría
        if ($idSubcategoria !== null) {
            if (!$this->subcategoriaModel->perteneceACategoria($idSubcategoria, $idCategoria)) {
                return [
                    'status' => false,
                    'message' => 'La subcategoría seleccionada no pertenece a la categoría elegida.',
                    'errors' => ['id_subcategoria' => 'La subcategoría no corresponde a la categoría.']
                ];
            }
        } else {
            // Asignar automáticamente la subcategoría General de la categoría
            $subcats = $this->subcategoriaModel->obtenerPorCategoria($idCategoria, true);
            if (!empty($subcats)) {
                $idSubcategoria = (int)$subcats[0]['id_subcategoria'];
            }
        }

        // El stock mínimo debe ser entero mayor o igual a 0
        $stockMinimo = isset($input['stock_minimo']) ? intval($input['stock_minimo']) : 1;
        if ($stockMinimo < 0) {
            return [
                'status' => false,
                'message' => 'El stock mínimo debe ser un número entero mayor o igual a cero.',
                'errors' => ['stock_minimo' => 'El stock mínimo no puede ser negativo.']
            ];
        }

        // REGLA FUNDAMENTAL DEL SPRINT 2:
        // Los productos nuevos comienzan estrictamente con stock cero.
        // Las existencias se explican exclusivamente mediante operaciones de abastecimiento.
        $productoData = [
            'codigo_barra' => trim($input['codigo_barra']),
            'nombre' => trim($input['nombre']),
            'descripcion' => trim($input['descripcion'] ?? ''),
            'id_categoria' => $idCategoria,
            'id_subcategoria' => $idSubcategoria,
            'id_unidad' => intval($input['id_unidad'] ?? 1),
            'precio_venta' => floatval($input['precio_venta']),
            'stock_actual' => 0, // Siempre cero en creación
            'stock_minimo' => $stockMinimo,
            'estado' => 1
        ];

        if ($this->model->insert($productoData)) {
            $idInsertado = (int)$this->model->conect()->lastInsertId();
            return ['status' => true, 'message' => 'Producto creado con éxito.', 'id_producto' => $idInsertado];
        }
        return ['status' => false, 'message' => 'Error al guardar el producto.'];
    }

    public function actualizar(int $id, array $input): array {
        $productoExistente = $this->model->find($id);
        if (!$productoExistente || (int)$productoExistente['estado'] !== 1) {
            return ['status' => false, 'message' => 'El producto no existe o se encuentra inactivo.'];
        }

        $validator = new Validation($input);
        $validator->required(['nombre', 'codigo_barra', 'precio_venta'])
            ->minLength('nombre', 3)
            ->maxLength('nombre', 100)
            ->minLength('codigo_barra', 3)
            ->maxLength('codigo_barra', 50)
            ->positiveNumber('precio_venta')
            ->unique('codigo_barra', $this->model, $id);

        if ($validator->fails()) {
            return ['status' => false, 'errors' => $validator->errors()];
        }

        $idCategoria = isset($input['id_categoria'])
            ? intval($input['id_categoria'])
            : intval($productoExistente['id_categoria']);

        $idSubcategoria = isset($input['id_subcategoria']) && $input['id_subcategoria'] !== ''
            ? intval($input['id_subcategoria'])
            : (int)$productoExistente['id_subcategoria'];

        if ($idSubcategoria) {
            if (!$this->subcategoriaModel->perteneceACategoria($idSubcategoria, $idCategoria)) {
                // Si la categoría cambió y la subcategoría previa ya no coincide, reasignar a General de la nueva categoría
                $subcats = $this->subcategoriaModel->obtenerPorCategoria($idCategoria, true);
                if (!empty($subcats)) {
                    $idSubcategoria = (int)$subcats[0]['id_subcategoria'];
                } else {
                    return [
                        'status' => false,
                        'message' => 'La subcategoría seleccionada no pertenece a la categoría elegida.',
                        'errors' => ['id_subcategoria' => 'La subcategoría no corresponde a la categoría.']
                    ];
                }
            }
        }

        // El stock mínimo debe ser entero mayor o igual a 0
        $stockMinimo = isset($input['stock_minimo'])
            ? intval($input['stock_minimo'])
            : intval($productoExistente['stock_minimo']);

        if ($stockMinimo < 0) {
            return [
                'status' => false,
                'message' => 'El stock mínimo debe ser un número entero mayor o igual a cero.',
                'errors' => ['stock_minimo' => 'El stock mínimo no puede ser negativo.']
            ];
        }

        // REGLA FUNDAMENTAL DEL SPRINT 2:
        // No se permite modificar el stock_actual desde formularios ni endpoints de catálogo.
        // Se excluye intencionalmente stock_actual de la actualización.
        $productoData = [
            'codigo_barra' => trim($input['codigo_barra']),
            'nombre' => trim($input['nombre']),
            'descripcion' => trim($input['descripcion'] ?? ''),
            'id_categoria' => $idCategoria,
            'id_subcategoria' => $idSubcategoria,
            'id_unidad' => intval($input['id_unidad'] ?? $productoExistente['id_unidad']),
            'precio_venta' => floatval($input['precio_venta']),
            'stock_minimo' => $stockMinimo
        ];

        if ($this->model->update($id, $productoData)) {
            return ['status' => true, 'message' => 'Producto actualizado con éxito.'];
        }
        return ['status' => false, 'message' => 'Error al actualizar el producto.'];
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
}
