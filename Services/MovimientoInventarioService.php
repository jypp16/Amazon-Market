<?php

namespace Services;

use Libraries\Core\Conexion;
use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use PDO;

/**
 * Infraestructura común y transaccional para modificar existencias (`producto.stock_actual`)
 * y registrar movimientos en `kardex` con bloqueo pesimista ordenado e instantáneas históricas.
 */
class MovimientoInventarioService {

    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Conexion::getInstance()->conect();
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    /**
     * Ejecuta una operación completa dentro de una transacción atómica.
     * Si ya existe una transacción abierta en la conexión, participa en ella
     * sin confirmarla prematuramente. Solo confirma cuando es propietaria
     * de la transacción y toda la operación finaliza sin errores.
     */
    public function ejecutarEnTransaccion(callable $operacion): mixed {
        $esTransaccionPropia = !$this->pdo->inTransaction();

        if ($esTransaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            $resultado = $operacion($this->pdo, $this);

            if ($esTransaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $resultado;
        } catch (\Throwable $error) {
            if ($esTransaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Verifica en la base de datos que el usuario responsable exista, esté activo (estado = 1)
     * y, cuando corresponda, posea rol Administrador (id_rol = 1).
     */
    public function verificarResponsableActivo(int $idUsuario, bool $exigirAdministrador = false): array {
        if ($idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }

        $sql = 'SELECT u.id_usuario, u.id_rol, u.username, u.nombre, u.estado, r.nombre AS rol_nombre
                  FROM usuario u
                 INNER JOIN rol r ON r.id_rol = u.id_rol
                 WHERE u.id_usuario = :id_usuario
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id_usuario' => $idUsuario]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario || (int)$usuario['estado'] !== 1) {
            throw InventarioException::noAutenticado();
        }

        if ($exigirAdministrador && (int)$usuario['id_rol'] !== 1) {
            throw InventarioException::accesoDenegadoAdmin();
        }

        return [
            'id_usuario' => (int)$usuario['id_usuario'],
            'id_rol' => (int)$usuario['id_rol'],
            'username' => (string)$usuario['username'],
            'nombre' => (string)$usuario['nombre'],
            'rol_nombre' => (string)$usuario['rol_nombre'],
            'estado' => (int)$usuario['estado'],
        ];
    }

    /**
     * Bloquea con `SELECT ... FOR UPDATE` un conjunto de productos ordenados
     * ascendentemente por `id_producto` para impedir bloqueos cruzados (deadlocks).
     *
     * @param int[] $idsProductos
     * @return array<int, array<string, mixed>> Mapa indexado por id_producto
     */
    public function bloquearProductosOrdenados(array $idsProductos): array {
        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException(
                'El bloqueo de productos (SELECT ... FOR UPDATE) requiere una transacción activa.'
            );
        }

        $idsLimpios = [];
        foreach ($idsProductos as $id) {
            $idEntero = filter_var($id, FILTER_VALIDATE_INT);
            if ($idEntero === false || $idEntero <= 0) {
                throw InventarioException::validacion([
                    'id_producto' => 'El identificador del producto debe ser un entero positivo.',
                ]);
            }
            $idsLimpios[(int)$idEntero] = (int)$idEntero;
        }

        if (empty($idsLimpios)) {
            throw InventarioException::validacion([
                'id_producto' => 'Debe indicar al menos un producto para la operación.',
            ]);
        }

        $idsOrdenados = array_values($idsLimpios);
        sort($idsOrdenados, SORT_NUMERIC);

        $placeholders = implode(', ', array_fill(0, count($idsOrdenados), '?'));
        $sql = "SELECT p.id_producto,
                       p.codigo_barra,
                       p.nombre,
                       p.id_categoria,
                       c.nombre AS categoria_nombre,
                       p.id_subcategoria,
                       COALESCE(s.nombre, 'General') AS subcategoria_nombre,
                       p.id_unidad,
                       u.nombre AS unidad_nombre,
                       u.abreviatura AS unidad_abreviatura,
                       p.precio_venta,
                       p.stock_actual,
                       p.stock_minimo,
                       p.estado
                  FROM producto p
                 INNER JOIN categoria c ON c.id_categoria = p.id_categoria
                  LEFT JOIN subcategoria s ON s.id_subcategoria = p.id_subcategoria AND s.id_categoria = p.id_categoria
                 INNER JOIN unidad_medida u ON u.id_unidad = p.id_unidad
                 WHERE p.id_producto IN ({$placeholders})
                 ORDER BY p.id_producto ASC
                   FOR UPDATE";

        $stmt = $this->pdo->prepare($sql);
        foreach ($idsOrdenados as $idx => $idProd) {
            $stmt->bindValue($idx + 1, $idProd, PDO::PARAM_INT);
        }
        $stmt->execute();

        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mapa = [];
        foreach ($filas as $fila) {
            $idProd = (int)$fila['id_producto'];
            $mapa[$idProd] = [
                'id_producto' => $idProd,
                'codigo_barra' => (string)$fila['codigo_barra'],
                'nombre' => (string)$fila['nombre'],
                'id_categoria' => (int)$fila['id_categoria'],
                'categoria_nombre' => (string)$fila['categoria_nombre'],
                'id_subcategoria' => (int)($fila['id_subcategoria'] ?: 1),
                'subcategoria_nombre' => (string)$fila['subcategoria_nombre'],
                'id_unidad' => (int)$fila['id_unidad'],
                'unidad_nombre' => (string)$fila['unidad_nombre'],
                'unidad_abreviatura' => (string)$fila['unidad_abreviatura'],
                'precio_venta' => (string)$fila['precio_venta'],
                'stock_actual' => (int)$fila['stock_actual'],
                'stock_minimo' => (int)$fila['stock_minimo'],
                'estado' => (int)$fila['estado'],
            ];
        }

        foreach ($idsOrdenados as $idEsperado) {
            if (!isset($mapa[$idEsperado])) {
                throw InventarioException::validacion([
                    'id_producto' => "El producto solicitado (ID {$idEsperado}) no existe.",
                ]);
            }
        }

        return $mapa;
    }

    /**
     * Aplica un lote de movimientos de stock dentro de la transacción activa,
     * bloqueando previamente todos los productos involucrados en orden ascendente de ID.
     *
     * @param array<int, array<string, mixed>> $comandos
     * @return array<int, array<string, mixed>>
     */
    public function aplicarLoteMovimientos(array $comandos): array {
        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException(
                'aplicarLoteMovimientos requiere una transacción activa de la operación de origen.'
            );
        }

        if (empty($comandos)) {
            throw InventarioException::validacion([
                'movimientos' => 'Debe proporcionar al menos un movimiento de inventario.',
            ]);
        }

        $idsProductos = array_map(static fn(array $cmd) => $cmd['id_producto'] ?? null, $comandos);
        $this->bloquearProductosOrdenados($idsProductos);

        $resultados = [];
        foreach ($comandos as $comando) {
            $resultados[] = $this->aplicarMovimiento($comando);
        }

        return $resultados;
    }

    /**
     * Aplica un movimiento individual sobre `producto.stock_actual` e inserta
     * su registro en `kardex` dentro de la transacción activa.
     * Jamás hace commit por su cuenta.
     */
    public function aplicarMovimiento(array $comando): array {
        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException(
                'aplicarMovimiento requiere una transacción activa de la operación de origen.'
            );
        }

        $idProducto = filter_var($comando['id_producto'] ?? null, FILTER_VALIDATE_INT);
        if ($idProducto === false || $idProducto <= 0) {
            throw InventarioException::validacion([
                'id_producto' => 'Debe seleccionar un producto válido.',
            ]);
        }

        $idUsuario = filter_var($comando['id_usuario'] ?? null, FILTER_VALIDATE_INT);
        if ($idUsuario === false || $idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }

        $origen = (string)($comando['origen_operacion'] ?? '');
        $sentido = (string)($comando['sentido_movimiento'] ?? '');
        DefinicionMovimiento::validarCombinacionOrigenSentido($origen, $sentido);

        $exigirAdmin = DefinicionMovimiento::esOrigenDeVariaciones($origen)
            || !empty($comando['exigir_admin']);
        $responsable = $this->verificarResponsableActivo((int)$idUsuario, $exigirAdmin);

        $cantidad = DefinicionMovimiento::validarCantidadEnteraPositiva(
            $comando['cantidad'] ?? null,
            'cantidad'
        );

        // Bloquear fila del producto y obtener su estado real vigente
        $productosBloqueados = $this->bloquearProductosOrdenados([(int)$idProducto]);
        $producto = $productosBloqueados[(int)$idProducto];

        $exigirProductoActivo = $comando['exigir_producto_activo'] ?? true;
        if ($exigirProductoActivo && $producto['estado'] !== 1) {
            throw InventarioException::validacion([
                'id_producto' => "El producto \"{$producto['nombre']}\" se encuentra inactivo y no admite nuevas operaciones.",
            ]);
        }

        $saldoAnterior = $producto['stock_actual'];

        // Validar saldo anterior esperado si fue provisto (p. ej. en ajustes por conteo físico)
        if (array_key_exists('saldo_anterior_esperado', $comando) && $comando['saldo_anterior_esperado'] !== null && $comando['saldo_anterior_esperado'] !== '') {
            $saldoEsperado = filter_var($comando['saldo_anterior_esperado'], FILTER_VALIDATE_INT);
            if ($saldoEsperado === false || $saldoEsperado < 0) {
                throw InventarioException::validacion([
                    'saldo_anterior_esperado' => 'El saldo anterior esperado debe ser un número entero mayor o igual a 0.',
                ]);
            }
            if ((int)$saldoEsperado !== $saldoAnterior) {
                throw InventarioException::saldoCambiado((int)$idProducto, (int)$saldoEsperado, $saldoAnterior);
            }
        }

        $saldoResultante = DefinicionMovimiento::calcularSaldoResultante($saldoAnterior, $sentido, $cantidad);

        if ($saldoResultante < 0) {
            throw InventarioException::stockInsuficiente(
                (int)$idProducto,
                $saldoAnterior,
                $cantidad,
                $producto['nombre']
            );
        }

        // Validar referencias según el origen
        $idAccionCompra = isset($comando['id_accion_compra']) ? (int)$comando['id_accion_compra'] : null;
        $idDetalleCompra = isset($comando['id_detalle_compra']) ? (int)$comando['id_detalle_compra'] : null;
        $idAccionVenta = isset($comando['id_accion_venta']) ? (int)$comando['id_accion_venta'] : null;
        $idDetalleVenta = isset($comando['id_detalle_venta']) ? (int)$comando['id_detalle_venta'] : null;
        $idOperacionInventario = isset($comando['id_operacion_inventario']) ? (int)$comando['id_operacion_inventario'] : null;

        $tipoReferencia = DefinicionMovimiento::obtenerTipoReferenciaRequerida($origen);
        if ($tipoReferencia === 'COMPRA' && ($idAccionCompra === null || $idAccionCompra <= 0 || $idDetalleCompra === null || $idDetalleCompra <= 0)) {
            throw InventarioException::validacion([
                'id_accion_compra' => 'Los movimientos de compra requieren referencia válida de compra y detalle de compra.',
            ]);
        }
        if ($tipoReferencia === 'VENTA' && ($idAccionVenta === null || $idAccionVenta <= 0)) {
            throw InventarioException::validacion([
                'id_accion_venta' => 'Los movimientos de venta requieren referencia válida de venta.',
            ]);
        }
        if ($tipoReferencia === 'OPERACION_INVENTARIO' && ($idOperacionInventario === null || $idOperacionInventario <= 0)) {
            throw InventarioException::validacion([
                'id_operacion_inventario' => 'Las bajas y ajustes requieren referencia válida de operación de inventario.',
            ]);
        }

        $justificacion = isset($comando['justificacion']) ? trim((string)$comando['justificacion']) : null;
        if (DefinicionMovimiento::esOrigenDeVariaciones($origen) && ($justificacion === null || $justificacion === '')) {
            throw InventarioException::validacion([
                'justificacion' => 'Debe ingresar la justificación del movimiento.',
            ]);
        }

        $loteNormalizado = DefinicionMovimiento::normalizarLote(
            isset($comando['lote']) ? (string)$comando['lote'] : ($comando['numero_lote'] ?? null),
            $origen
        );

        $tipoMovimientoEtiqueta = $comando['tipo_movimiento']
            ?? DefinicionMovimiento::obtenerEtiquetaTipoMovimiento($origen);

        // 1. Actualizar stock_actual del producto
        $stmtUpdate = $this->pdo->prepare(
            'UPDATE producto SET stock_actual = :nuevo_stock WHERE id_producto = :id_producto'
        );
        $stmtUpdate->execute([
            ':nuevo_stock' => $saldoResultante,
            ':id_producto' => (int)$idProducto,
        ]);

        // 2. Insertar movimiento en kardex con instantáneas históricas completas
        $sqlKardex = 'INSERT INTO kardex (
            id_producto,
            id_usuario,
            origen_operacion,
            sentido_movimiento,
            tipo_movimiento,
            cantidad,
            stock_anterior,
            stock_resultante,
            id_accion_compra,
            id_detalle_compra,
            id_accion_venta,
            id_detalle_venta,
            id_operacion_inventario,
            referencia_operacion,
            justificacion,
            numero_lote,
            condicion_lote,
            producto_codigo_snapshot,
            producto_nombre_snapshot,
            id_categoria_snapshot,
            categoria_nombre_snapshot,
            id_subcategoria_snapshot,
            subcategoria_nombre_snapshot,
            id_unidad_snapshot,
            unidad_abreviatura_snapshot,
            responsable_nombre_snapshot,
            responsable_rol_snapshot
        ) VALUES (
            :id_producto,
            :id_usuario,
            :origen_operacion,
            :sentido_movimiento,
            :tipo_movimiento,
            :cantidad,
            :stock_anterior,
            :stock_resultante,
            :id_accion_compra,
            :id_detalle_compra,
            :id_accion_venta,
            :id_detalle_venta,
            :id_operacion_inventario,
            :referencia_operacion,
            :justificacion,
            :numero_lote,
            :condicion_lote,
            :producto_codigo_snapshot,
            :producto_nombre_snapshot,
            :id_categoria_snapshot,
            :categoria_nombre_snapshot,
            :id_subcategoria_snapshot,
            :subcategoria_nombre_snapshot,
            :id_unidad_snapshot,
            :unidad_abreviatura_snapshot,
            :responsable_nombre_snapshot,
            :responsable_rol_snapshot
        )';

        $stmtKardex = $this->pdo->prepare($sqlKardex);
        $stmtKardex->execute([
            ':id_producto' => (int)$idProducto,
            ':id_usuario' => $responsable['id_usuario'],
            ':origen_operacion' => $origen,
            ':sentido_movimiento' => $sentido,
            ':tipo_movimiento' => $tipoMovimientoEtiqueta,
            ':cantidad' => $cantidad,
            ':stock_anterior' => $saldoAnterior,
            ':stock_resultante' => $saldoResultante,
            ':id_accion_compra' => $idAccionCompra,
            ':id_detalle_compra' => $idDetalleCompra,
            ':id_accion_venta' => $idAccionVenta,
            ':id_detalle_venta' => $idDetalleVenta,
            ':id_operacion_inventario' => $idOperacionInventario,
            ':referencia_operacion' => $comando['referencia_operacion'] ?? null,
            ':justificacion' => $justificacion !== '' ? $justificacion : null,
            ':numero_lote' => $loteNormalizado['numero_lote'],
            ':condicion_lote' => $loteNormalizado['condicion_lote'],
            ':producto_codigo_snapshot' => $producto['codigo_barra'],
            ':producto_nombre_snapshot' => $producto['nombre'],
            ':id_categoria_snapshot' => $producto['id_categoria'],
            ':categoria_nombre_snapshot' => $producto['categoria_nombre'],
            ':id_subcategoria_snapshot' => $producto['id_subcategoria'],
            ':subcategoria_nombre_snapshot' => $producto['subcategoria_nombre'],
            ':id_unidad_snapshot' => $producto['id_unidad'],
            ':unidad_abreviatura_snapshot' => $producto['unidad_abreviatura'],
            ':responsable_nombre_snapshot' => $responsable['nombre'],
            ':responsable_rol_snapshot' => $responsable['rol_nombre'],
        ]);

        $idKardex = (int)$this->pdo->lastInsertId();

        $stmtFecha = $this->pdo->prepare('SELECT fecha_movimiento FROM kardex WHERE id_kardex = :id');
        $stmtFecha->execute([':id' => $idKardex]);
        $fechaMovimiento = (string)$stmtFecha->fetchColumn();

        return [
            'id_kardex' => $idKardex,
            'fecha_hora' => $fechaMovimiento,
            'id_producto' => $producto['id_producto'],
            'producto' => $producto['nombre'],
            'codigo_barra' => $producto['codigo_barra'],
            'id_categoria' => $producto['id_categoria'],
            'categoria' => $producto['categoria_nombre'],
            'id_subcategoria' => $producto['id_subcategoria'],
            'subcategoria' => $producto['subcategoria_nombre'],
            'id_unidad' => $producto['id_unidad'],
            'unidad' => $producto['unidad_abreviatura'],
            'origen_operacion' => $origen,
            'tipo_movimiento' => $tipoMovimientoEtiqueta,
            'sentido' => $sentido,
            'cantidad' => $cantidad,
            'saldo_anterior' => $saldoAnterior,
            'saldo_resultante' => $saldoResultante,
            'lote' => $loteNormalizado['texto_visible'],
            'numero_lote' => $loteNormalizado['numero_lote'],
            'condicion_lote' => $loteNormalizado['condicion_lote'],
            'justificacion' => $justificacion,
            'responsable' => $responsable['nombre'],
            'id_usuario' => $responsable['id_usuario'],
        ];
    }
}

