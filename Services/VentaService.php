<?php

namespace Services;

use Models\ProductoModel;
use Models\ClienteModel;
use Models\VentaModel;
use Models\DetalleVentaModel;
use Models\UsuarioModel;
use Libraries\Core\Validation;
use PDO;

class VentaService {

    private $ventaModel;
    private $detalleModel;
    private $productoModel;
    private $clienteModel;
    private $pdo;

    public function __construct() {
        $this->ventaModel = new VentaModel();
        $this->detalleModel = new DetalleVentaModel();
        $this->productoModel = new ProductoModel();
        $this->clienteModel = new ClienteModel();
        $this->pdo = \Libraries\Core\Conexion::getInstance()->conect();
    }

    public function procesarVenta(array $input): array {
        // 1. Validar datos de entrada
        $validator = new Validation($input);
        $validator->required(['id_cliente', 'productos', 'id_tipo_comprobante'])
            ->integer('id_cliente')
            ->integer('id_tipo_comprobante');

        if ($validator->fails()) {
            return ['status' => false, 'message' => $validator->firstError()];
        }

        if (!is_array($input['productos']) || empty($input['productos'])) {
            return ['status' => false, 'message' => 'Debe agregar al menos un producto a la venta.'];
        }

        // 2. Validar cada producto
        foreach ($input['productos'] as $index => $item) {
            $validation = $this->validarItemProducto($item, $index);
            if ($validation !== true) {
                return ['status' => false, 'message' => $validation];
            }
        }

        // 3. Verificar existencia de cliente y comprobante
        $id_cliente = intval($input['id_cliente']);
        $id_tipo_comprobante = intval($input['id_tipo_comprobante']);

        $cliente = $this->clienteModel->find($id_cliente);
        if (!$cliente || $cliente['estado'] != 1) {
            return ['status' => false, 'message' => 'El cliente seleccionado no existe o está inactivo.'];
        }

        // Validar que Factura requiera cliente con RUC (buscar ID por nombre, no hardcodear)
        $facturaStmt = $this->pdo->prepare("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE LOWER(nombre) LIKE '%factura%' AND estado = 1 LIMIT 1");
        $facturaStmt->execute();
        $facturaId = (int) $facturaStmt->fetchColumn();

        if ($facturaId > 0 && $id_tipo_comprobante == $facturaId && intval($cliente['id_tipo_documento']) != 2) {
            return ['status' => false, 'message' => 'Para emitir Factura, el cliente debe tener RUC. Seleccione un cliente con RUC.'];
        }

        $comprobanteSql = "SELECT COUNT(*) FROM tipo_comprobante WHERE id_tipo_comprobante = :id AND estado = 1";
        $comprobanteStmt = $this->pdo->prepare($comprobanteSql);
        $comprobanteStmt->execute([':id' => $id_tipo_comprobante]);
        if ($comprobanteStmt->fetchColumn() == 0) {
            return ['status' => false, 'message' => 'El tipo de comprobante seleccionado no es válido.'];
        }

        // 4. Validar inquilino autenticado (defensa BOLA/IDOR)
        $id_usuario = intval($input['id_usuario'] ?? 0);
        if ($id_usuario <= 0) {
            return ['status' => false, 'message' => 'Sesión inválida: no se pudo identificar al usuario autenticado.'];
        }

        $usrStmt = $this->pdo->prepare("SELECT id_usuario, estado FROM usuario WHERE id_usuario = :id LIMIT 1");
        $usrStmt->execute([':id' => $id_usuario]);
        $usrRow = $usrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$usrRow || intval($usrRow['estado']) !== 1) {
            return ['status' => false, 'message' => 'El usuario autenticado no existe o está inactivo.'];
        }

        // 5. Ejecutar transacción vinculada al inquilino validado
        return $this->ejecutarTransaccion($input, $id_cliente, $id_tipo_comprobante, $id_usuario);
    }

    private function validarItemProducto(array $item, int $index): string|true {
        if (empty($item['id_producto'])) {
            return "El producto #" . ($index + 1) . " no tiene ID válido.";
        }
        if (!isset($item['cantidad']) || $item['cantidad'] === '' || $item['cantidad'] === null) {
            return "La cantidad del producto #" . ($index + 1) . " es obligatoria.";
        }
        if (!is_numeric($item['cantidad'])) {
            return "La cantidad del producto #" . ($index + 1) . " debe ser un número válido.";
        }
        if (floatval($item['cantidad']) <= 0) {
            return "La cantidad del producto #" . ($index + 1) . " debe ser mayor a cero.";
        }
        return true;
    }

    private function ejecutarTransaccion(array $input, int $id_cliente, int $id_tipo_comprobante, int $id_usuario): array {
        $this->pdo->beginTransaction();

        try {
            $total_general = 0.00;
            $detalles = [];
            $productos = $input['productos'];

            foreach ($productos as $item) {
                $resultado = $this->procesarItemVenta($item);
                if ($resultado['error']) {
                    $this->pdo->rollBack();
                    return ['status' => false, 'message' => $resultado['message']];
                }
                $total_general += $resultado['subtotal'];
                $detalles[] = $resultado['detalle'];
            }

            // Crear venta — la serie y el correlativo viven en serie_comprobante,
            // una fila por tipo de comprobante. SELECT ... FOR UPDATE bloquea
            // esa fila específica dentro de la transacción, evitando que dos
            // ventas concurrentes obtengan el mismo número (race condition).
            $serieStmt = $this->pdo->prepare(
                "SELECT id_serie, serie, correlativo_actual
                 FROM serie_comprobante
                 WHERE id_tipo_comprobante = :id AND estado = 1
                 LIMIT 1 FOR UPDATE"
            );
            $serieStmt->execute([':id' => $id_tipo_comprobante]);
            $serieRow = $serieStmt->fetch(PDO::FETCH_ASSOC);

            if (!$serieRow) {
                $this->pdo->rollBack();
                return ['status' => false, 'message' => 'No hay una serie configurada para este tipo de comprobante.'];
            }

            $serie = $serieRow['serie'];
            $siguienteCorrelativo = (int) $serieRow['correlativo_actual'] + 1;
            $numero = str_pad($siguienteCorrelativo, 8, "0", STR_PAD_LEFT);

            $updateSerieStmt = $this->pdo->prepare(
                "UPDATE serie_comprobante SET correlativo_actual = :nuevo WHERE id_serie = :id_serie"
            );
            $updateSerieStmt->execute([
                ':nuevo' => $siguienteCorrelativo,
                ':id_serie' => $serieRow['id_serie']
            ]);

            $ventaData = [
                'id_cliente' => $id_cliente,
                'id_usuario' => $id_usuario,
                'id_tipo_comprobante' => $id_tipo_comprobante,
                'serie' => $serie,
                'numero' => $numero,
                'total' => $total_general,
                'id_metodo_pago' => intval($input['id_metodo_pago'] ?? 1),
                'estado' => 1
            ];

            if (!$this->ventaModel->insert($ventaData)) {
                $this->pdo->rollBack();
                return ['status' => false, 'message' => 'Error al registrar la venta.'];
            }

            $id_venta = $this->pdo->lastInsertId();

            // Insertar detalles y actualizar stock
            foreach ($detalles as $det) {
                $detalleData = [
                    'id_venta' => $id_venta,
                    'id_producto' => $det['id_producto'],
                    'cantidad' => $det['cantidad'],
                    'precio_unitario' => $det['precio_unitario'],
                    'descuento' => 0.00,
                    'subtotal' => $det['subtotal']
                ];

                if (!$this->detalleModel->insert($detalleData)) {
                    $this->pdo->rollBack();
                    return ['status' => false, 'message' => 'Error al registrar el detalle de la venta.'];
                }

                $updateSql = "UPDATE producto SET stock_actual = stock_actual - :cantidad WHERE id_producto = :id_producto AND stock_actual >= :cantidad";
                $updateStmt = $this->pdo->prepare($updateSql);
                $updateStmt->execute([
                    ':cantidad' => $det['cantidad'],
                    ':id_producto' => $det['id_producto']
                ]);
                // Si ninguna fila fue afectada, el stock ya no alcanza (concurrencia)
                if ($updateStmt->rowCount() === 0) {
                    $this->pdo->rollBack();
                    $prodInfo = $this->productoModel->find($det['id_producto']);
                    $disponible = $prodInfo ? $prodInfo['stock_actual'] : 0;
                    return ['status' => false, 'message' => "Stock insuficiente para el producto ID {$det['id_producto']}. Disponible: {$disponible}"];
                }
            }

            $this->pdo->commit();

            return [
                'status' => true,
                'message' => 'Venta registrada con éxito.',
                'data' => [
                    'id_venta' => $id_venta,
                    'serie' => $serie,
                    'numero' => $numero,
                    'total' => $total_general
                ]
            ];
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // No filtrar detalles internos al cliente; loguear la excepción.
            error_log('Error en VentaService::ejecutarTransaccion -> ' . $e->getMessage());
            return ['status' => false, 'message' => 'Ocurrió un error interno al procesar la venta. Intente nuevamente.'];
        }
    }

    private function procesarItemVenta(array $item): array {
        $id_prod = intval($item['id_producto']);
        $cant = floatval($item['cantidad']);

        $producto = $this->productoModel
            ->select(['producto.*', 'unidad_medida.abreviatura as unidad'])
            ->join('unidad_medida', 'producto.id_unidad = unidad_medida.id_unidad', 'INNER')
            ->where(['producto.id_producto' => $id_prod])
            ->first();

        if (!$producto || $producto['estado'] != 1) {
            return ['error' => true, 'message' => "El producto con ID {$id_prod} no existe o está inactivo."];
        }

        if ($producto['stock_actual'] < $cant) {
            return ['error' => true, 'message' => "Stock insuficiente para: {$producto['nombre']}. Disponible: {$producto['stock_actual']}"];
        }

        // Validar decimales según unidad
        $unidad = strtolower(trim($producto['unidad'] ?? ''));
        $unidadesDecimales = ['kg', 'lt', 'lb', 'gal', 'm', 'cm', 'ml', 'g', 'oz'];
        $esDecimal = false;
        foreach ($unidadesDecimales as $ud) {
            if (strpos($unidad, $ud) !== false) {
                $esDecimal = true;
                break;
            }
        }

        if (!$esDecimal && intval($cant) != $cant) {
            return ['error' => true, 'message' => "El producto '{$producto['nombre']}' se vende por unidad. No se permiten decimales."];
        }

        $precio = floatval($producto['precio_venta']);
        $subtotal = $precio * $cant;

        return [
            'error' => false,
            'subtotal' => $subtotal,
            'detalle' => [
                'id_producto' => $id_prod,
                'cantidad' => $cant,
                'precio_unitario' => $precio,
                'subtotal' => $subtotal
            ]
        ];
    }
}
