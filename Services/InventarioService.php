<?php

namespace Services;

use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Models\OperacionInventarioModel;
use Models\ProductoModel;
use PDO;
use Exception;
use Throwable;

class InventarioService {
    private OperacionInventarioModel $operacionModel;
    private ProductoModel $productoModel;
    private MovimientoInventarioService $movimientoService;
    private ControlSolicitudService $controlSolicitudService;
    private PDO $pdo;

    public function __construct(
        ?OperacionInventarioModel $operacionModel = null,
        ?ProductoModel $productoModel = null,
        ?MovimientoInventarioService $movimientoService = null,
        ?ControlSolicitudService $controlSolicitudService = null
    ) {
        $this->operacionModel = $operacionModel ?? new OperacionInventarioModel();
        $this->productoModel = $productoModel ?? new ProductoModel();
        $this->movimientoService = $movimientoService ?? new MovimientoInventarioService();
        $this->pdo = $this->movimientoService->getPdo();
        $this->controlSolicitudService = $controlSolicitudService ?? new ControlSolicitudService($this->pdo);
    }

    public function getMovimientoService(): MovimientoInventarioService {
        return $this->movimientoService;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    /**
     * Normaliza el motivo de baja recibido a la constante de origen canónica y etiqueta legible.
     */
    private function normalizarMotivoBaja(mixed $motivo): array {
        if (!is_string($motivo)) {
            throw InventarioException::validacion([
                'motivo_baja' => 'Debe seleccionar un motivo de baja válido ("Baja por daño" o "Baja por vencimiento").'
            ]);
        }

        $limpio = trim(mb_strtolower($motivo, 'UTF-8'));
        if (in_array($limpio, ['daño', 'dano', 'baja por daño', 'baja por dano', 'baja_dano'], true)) {
            return [
                'origen' => DefinicionMovimiento::ORIGEN_BAJA_DANO,
                'etiqueta' => 'Baja por daño'
            ];
        }

        if (in_array($limpio, ['vencimiento', 'baja por vencimiento', 'baja_vencimiento'], true)) {
            return [
                'origen' => DefinicionMovimiento::ORIGEN_BAJA_VENCIMIENTO,
                'etiqueta' => 'Baja por vencimiento'
            ];
        }

        throw InventarioException::validacion([
            'motivo_baja' => 'El motivo de baja debe ser "Baja por daño" o "Baja por vencimiento".'
        ]);
    }

    /**
     * Normaliza el tipo/sentido de ajuste recibido a constante de origen, sentido canónico y etiqueta.
     */
    private function normalizarTipoAjuste(mixed $tipoAjuste): array {
        if (!is_string($tipoAjuste)) {
            throw InventarioException::validacion([
                'tipo_ajuste' => 'Debe seleccionar un tipo de ajuste válido ("Ajuste positivo" o "Ajuste negativo").'
            ]);
        }

        $limpio = trim(mb_strtolower($tipoAjuste, 'UTF-8'));
        if (in_array($limpio, ['ajuste positivo', 'entry', 'entrada', 'ajuste_positivo', '+'], true)) {
            return [
                'origen' => DefinicionMovimiento::ORIGEN_AJUSTE_POSITIVO,
                'sentido' => DefinicionMovimiento::SENTIDO_INCREMENTO,
                'etiqueta' => 'Ajuste positivo'
            ];
        }

        if (in_array($limpio, ['ajuste negativo', 'exit', 'salida', 'ajuste_negativo', '-'], true)) {
            return [
                'origen' => DefinicionMovimiento::ORIGEN_AJUSTE_NEGATIVO,
                'sentido' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                'etiqueta' => 'Ajuste negativo'
            ];
        }

        throw InventarioException::validacion([
            'tipo_ajuste' => 'El tipo de ajuste debe ser "Ajuste positivo" o "Ajuste negativo".'
        ]);
    }

    /**
     * Valida el formato de referencia_solicitud conforme a la expresión regular acordada.
     */
    private function validarReferenciaSolicitud(?string $ref): string {
        $refLimpia = $ref !== null ? trim($ref) : '';
        if ($refLimpia === '') {
            throw InventarioException::validacion([
                'referencia_solicitud' => 'La referencia de solicitud es obligatoria.'
            ]);
        }

        if (!preg_match('/^[A-Za-z0-9\-_]{16,64}$/', $refLimpia)) {
            throw InventarioException::validacion([
                'referencia_solicitud' => 'La referencia de solicitud debe ser una cadena alfanumérica de 16 a 64 caracteres.'
            ]);
        }

        return $refLimpia;
    }

    /**
     * Registra una Baja de Inventario (por daño o vencimiento) de forma transaccional y atómica:
     * - Inserta en operacion_inventario
     * - Descuenta stock_actual en producto
     * - Inserta movimiento en kardex con snapshots históricos
     * - Controla idempotencia con ControlSolicitudService
     */
    public function registrarBaja(array $input, ?string $referencia_solicitud = null, ?int $authenticatedUserId = null): array {
        // 1. Extraer o validar referencia de solicitud
        if ($referencia_solicitud === null && !empty($input['referencia_solicitud']) && is_string($input['referencia_solicitud'])) {
            $referencia_solicitud = trim($input['referencia_solicitud']);
        }
        $refValidada = $this->validarReferenciaSolicitud($referencia_solicitud);

        // 2. Validar responsable activo con rol Administrador (defensa BOLA/IDOR)
        $idUsuario = $authenticatedUserId !== null ? $authenticatedUserId : filter_var($input['id_usuario'] ?? null, FILTER_VALIDATE_INT);
        if ($idUsuario === false || $idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }
        $responsable = $this->movimientoService->verificarResponsableActivo((int)$idUsuario, true);

        // 3. Validar identificador de producto
        $idProducto = filter_var($input['id_producto'] ?? null, FILTER_VALIDATE_INT);
        if ($idProducto === false || $idProducto <= 0) {
            throw InventarioException::validacion([
                'id_producto' => 'Debe seleccionar un producto válido.'
            ]);
        }
        $idProductoEntero = (int)$idProducto;

        // 4. Validar motivo de baja
        $motivoData = $this->normalizarMotivoBaja($input['motivo_baja'] ?? null);
        $origen = $motivoData['origen'];
        $etiquetaMotivo = $motivoData['etiqueta'];

        // 5. Validar cantidad entera positiva
        $cantidad = DefinicionMovimiento::validarCantidadEnteraPositiva(
            $input['cantidad'] ?? null,
            'cantidad'
        );

        // 6. Validar justificación obligatoria
        $justificacionRaw = $input['justificacion'] ?? $input['observacion'] ?? null;
        $justificacion = is_string($justificacionRaw) ? trim($justificacionRaw) : '';
        if ($justificacion === '') {
            throw InventarioException::validacion([
                'justificacion' => 'Debe ingresar la justificación del movimiento.'
            ]);
        }
        if (mb_strlen($justificacion, 'UTF-8') > 255) {
            throw InventarioException::validacion([
                'justificacion' => 'La justificación no puede exceder los 255 caracteres.'
            ]);
        }

        // 7. Normalizar lote opcional
        $loteRaw = isset($input['lote']) ? $input['lote'] : ($input['numero_lote'] ?? null);
        $loteInfo = DefinicionMovimiento::normalizarLote(
            is_string($loteRaw) ? $loteRaw : null,
            $origen
        );

        // Payload funcional normalizado para hash de idempotencia
        $payloadFuncional = [
            'id_producto' => $idProductoEntero,
            'motivo_baja' => $origen,
            'cantidad' => $cantidad,
            'justificacion' => $justificacion,
            'lote' => $loteInfo['numero_lote']
        ];

        // Operación atómica de negocio
        $operacion = function (PDO $pdo) use (
            $idProductoEntero, $idUsuario, $responsable, $origen, $etiquetaMotivo,
            $cantidad, $justificacion, $loteInfo, $refValidada
        ) {
            // 1. Bloquear producto en orden determinístico
            $productosBloqueados = $this->movimientoService->bloquearProductosOrdenados([$idProductoEntero]);
            $producto = $productosBloqueados[$idProductoEntero];

            // 2. Validar que el producto se encuentre activo
            if ((int)$producto['estado'] !== 1) {
                throw InventarioException::validacion([
                    'id_producto' => "El producto \"{$producto['nombre']}\" se encuentra inactivo y no admite nuevas operaciones."
                ]);
            }

            // 3. Validar disponibilidad de stock
            $saldoAnterior = (int)$producto['stock_actual'];
            if ($saldoAnterior < $cantidad) {
                throw InventarioException::stockInsuficiente(
                    $idProductoEntero,
                    $saldoAnterior,
                    $cantidad,
                    $producto['nombre']
                );
            }

            $saldoResultante = $saldoAnterior - $cantidad;

            // 4. Insertar registro en operacion_inventario
            $sqlOp = 'INSERT INTO operacion_inventario (
                id_producto,
                id_usuario,
                categoria_operacion,
                origen_operacion,
                tipo_movimiento,
                sentido_movimiento,
                cantidad,
                saldo_anterior,
                saldo_anterior_esperado,
                saldo_resultante,
                justificacion,
                numero_lote,
                condicion_lote,
                referencia_solicitud,
                fecha_registro
            ) VALUES (
                :id_producto,
                :id_usuario,
                :categoria_operacion,
                :origen_operacion,
                :tipo_movimiento,
                :sentido_movimiento,
                :cantidad,
                :saldo_anterior,
                NULL,
                :saldo_resultante,
                :justificacion,
                :numero_lote,
                :condicion_lote,
                :referencia_solicitud,
                NOW()
            )';

            $stmtOp = $pdo->prepare($sqlOp);
            $stmtOp->execute([
                ':id_producto' => $idProductoEntero,
                ':id_usuario' => (int)$idUsuario,
                ':categoria_operacion' => DefinicionMovimiento::CATEGORIA_BAJA,
                ':origen_operacion' => $origen,
                ':tipo_movimiento' => $etiquetaMotivo,
                ':sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                ':cantidad' => $cantidad,
                ':saldo_anterior' => $saldoAnterior,
                ':saldo_resultante' => $saldoResultante,
                ':justificacion' => $justificacion,
                ':numero_lote' => $loteInfo['numero_lote'],
                ':condicion_lote' => $loteInfo['condicion_lote'],
                ':referencia_solicitud' => $refValidada
            ]);
            $idOperacion = (int)$pdo->lastInsertId();

            // 5. Aplicar movimiento canónico en Kardex y actualizar producto.stock_actual
            $resMov = $this->movimientoService->aplicarMovimiento([
                'id_producto' => $idProductoEntero,
                'id_usuario' => (int)$idUsuario,
                'origen_operacion' => $origen,
                'sentido_movimiento' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                'tipo_movimiento' => $etiquetaMotivo,
                'cantidad' => $cantidad,
                'id_operacion_inventario' => $idOperacion,
                'referencia_operacion' => "Baja #{$idOperacion} - {$etiquetaMotivo}",
                'justificacion' => $justificacion,
                'lote' => $loteInfo['numero_lote'],
                'exigir_producto_activo' => true,
                'exigir_admin' => true
            ]);

            return [
                'status' => true,
                'code' => 'WRITEOFF_CREATED',
                'idempotent_replay' => false,
                'message' => 'Baja de inventario registrada correctamente.',
                'data' => [
                    'id_operacion' => $idOperacion,
                    'id_kardex' => $resMov['id_kardex'],
                    'referencia_solicitud' => $refValidada,
                    'fecha_hora' => $resMov['fecha_hora'],
                    'id_producto' => $idProductoEntero,
                    'producto' => $resMov['producto'],
                    'codigo_barra' => $resMov['codigo_barra'],
                    'categoria' => $resMov['categoria'],
                    'subcategoria' => $resMov['subcategoria'],
                    'unidad' => $resMov['unidad'],
                    'tipo_movimiento' => $etiquetaMotivo,
                    'sentido' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                    'cantidad' => $cantidad,
                    'saldo_anterior' => $saldoAnterior,
                    'saldo_resultante' => $saldoResultante,
                    'lote' => $resMov['lote'],
                    'justificacion' => $justificacion,
                    'responsable' => $resMov['responsable']
                ]
            ];
        };

        // Ejecución con control de idempotencia
        $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
            (int)$idUsuario,
            DefinicionMovimiento::SOLICITUD_BAJA,
            $refValidada,
            $payloadFuncional,
            function (PDO $pdo) use ($operacion) {
                $resultado = $operacion($pdo);
                return [
                    'id_registro' => (int)($resultado['data']['id_operacion'] ?? 0),
                    'response' => $resultado
                ];
            }
        );

        $respuestaFinal = $resIdemp['response'];
        if (!empty($resIdemp['idempotent_replay'])) {
            $respuestaFinal['idempotent_replay'] = true;
            $respuestaFinal['status'] = true;
            $respuestaFinal['code'] = 'OPERATION_ALREADY_CONFIRMED';
        }

        return $respuestaFinal;
    }

    /**
     * Registra un Ajuste de Stock por Conteo Físico (positivo o negativo) de forma transaccional y atómica:
     * - Valida saldo_anterior_esperado (si cambió, rechaza con 409 STOCK_CHANGED_CONFLICT)
     * - Inserta en operacion_inventario
     * - Actualiza stock_actual en producto
     * - Inserta movimiento en kardex con snapshots históricos
     * - Controla idempotencia con ControlSolicitudService
     */
    public function registrarAjuste(array $input, ?string $referencia_solicitud = null, ?int $authenticatedUserId = null): array {
        // 1. Extraer o validar referencia de solicitud
        if ($referencia_solicitud === null && !empty($input['referencia_solicitud']) && is_string($input['referencia_solicitud'])) {
            $referencia_solicitud = trim($input['referencia_solicitud']);
        }
        $refValidada = $this->validarReferenciaSolicitud($referencia_solicitud);

        // 2. Validar responsable activo con rol Administrador (defensa BOLA/IDOR)
        $idUsuario = $authenticatedUserId !== null ? $authenticatedUserId : filter_var($input['id_usuario'] ?? null, FILTER_VALIDATE_INT);
        if ($idUsuario === false || $idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }
        $responsable = $this->movimientoService->verificarResponsableActivo((int)$idUsuario, true);

        // 3. Validar identificador de producto
        $idProducto = filter_var($input['id_producto'] ?? null, FILTER_VALIDATE_INT);
        if ($idProducto === false || $idProducto <= 0) {
            throw InventarioException::validacion([
                'id_producto' => 'Debe seleccionar un producto válido.'
            ]);
        }
        $idProductoEntero = (int)$idProducto;

        // 4. Validar tipo de ajuste y sentido
        $ajusteData = $this->normalizarTipoAjuste($input['tipo_ajuste'] ?? ($input['stock_adjustment_direction'] ?? null));
        $origen = $ajusteData['origen'];
        $sentido = $ajusteData['sentido'];
        $etiquetaMotivo = $ajusteData['etiqueta'];

        // 5. Validar cantidad entera positiva
        $cantidad = DefinicionMovimiento::validarCantidadEnteraPositiva(
            $input['cantidad'] ?? null,
            'cantidad'
        );

        // 6. Validar saldo_anterior_esperado obligatorio
        if (!array_key_exists('saldo_anterior_esperado', $input) || $input['saldo_anterior_esperado'] === null || $input['saldo_anterior_esperado'] === '') {
            throw InventarioException::validacion([
                'saldo_anterior_esperado' => 'Debe proporcionar el saldo anterior esperado verificado en la preparación del ajuste.'
            ]);
        }
        $saldoEsperadoVal = filter_var($input['saldo_anterior_esperado'], FILTER_VALIDATE_INT);
        if ($saldoEsperadoVal === false || $saldoEsperadoVal < 0) {
            throw InventarioException::validacion([
                'saldo_anterior_esperado' => 'El saldo anterior esperado debe ser un número entero mayor o igual a 0.'
            ]);
        }
        $saldoEsperado = (int)$saldoEsperadoVal;

        // 7. Validar justificación / motivo obligatorio
        $justificacionRaw = $input['justificacion'] ?? $input['motivo'] ?? null;
        $justificacion = is_string($justificacionRaw) ? trim($justificacionRaw) : '';
        if ($justificacion === '') {
            throw InventarioException::validacion([
                'justificacion' => 'Debe ingresar la justificación del movimiento.'
            ]);
        }
        if (mb_strlen($justificacion, 'UTF-8') > 255) {
            throw InventarioException::validacion([
                'justificacion' => 'La justificación no puede exceder los 255 caracteres.'
            ]);
        }

        // Payload funcional normalizado para hash de idempotencia
        $payloadFuncional = [
            'id_producto' => $idProductoEntero,
            'tipo_ajuste' => $origen,
            'cantidad' => $cantidad,
            'saldo_anterior_esperado' => $saldoEsperado,
            'justificacion' => $justificacion
        ];

        // Operación atómica de negocio
        $operacion = function (PDO $pdo) use (
            $idProductoEntero, $idUsuario, $responsable, $origen, $sentido, $etiquetaMotivo,
            $cantidad, $saldoEsperado, $justificacion, $refValidada
        ) {
            // 1. Bloquear producto en orden determinístico
            $productosBloqueados = $this->movimientoService->bloquearProductosOrdenados([$idProductoEntero]);
            $producto = $productosBloqueados[$idProductoEntero];

            // 2. Validar que el producto se encuentre activo
            if ((int)$producto['estado'] !== 1) {
                throw InventarioException::validacion([
                    'id_producto' => "El producto \"{$producto['nombre']}\" se encuentra inactivo y no admite nuevas operaciones."
                ]);
            }

            // 3. Comprobar concordancia estricta entre saldo anterior esperado y saldo actual real en BD
            $saldoAnteriorReal = (int)$producto['stock_actual'];
            if ($saldoAnteriorReal !== $saldoEsperado) {
                throw InventarioException::saldoCambiado($idProductoEntero, $saldoEsperado, $saldoAnteriorReal);
            }

            // 4. Validar suficiencia de existencias si es salida (Ajuste negativo)
            if ($sentido === DefinicionMovimiento::SENTIDO_DISMINUCION && $saldoAnteriorReal < $cantidad) {
                throw InventarioException::stockInsuficiente(
                    $idProductoEntero,
                    $saldoAnteriorReal,
                    $cantidad,
                    $producto['nombre']
                );
            }

            $saldoResultante = DefinicionMovimiento::calcularSaldoResultante($saldoAnteriorReal, $sentido, $cantidad);

            // 5. Insertar registro en operacion_inventario
            $sqlOp = 'INSERT INTO operacion_inventario (
                id_producto,
                id_usuario,
                categoria_operacion,
                origen_operacion,
                tipo_movimiento,
                sentido_movimiento,
                cantidad,
                saldo_anterior,
                saldo_anterior_esperado,
                saldo_resultante,
                justificacion,
                numero_lote,
                condicion_lote,
                referencia_solicitud,
                fecha_registro
            ) VALUES (
                :id_producto,
                :id_usuario,
                :categoria_operacion,
                :origen_operacion,
                :tipo_movimiento,
                :sentido_movimiento,
                :cantidad,
                :saldo_anterior,
                :saldo_anterior_esperado,
                :saldo_resultante,
                :justificacion,
                NULL,
                :condicion_lote,
                :referencia_solicitud,
                NOW()
            )';

            $stmtOp = $pdo->prepare($sqlOp);
            $stmtOp->execute([
                ':id_producto' => $idProductoEntero,
                ':id_usuario' => (int)$idUsuario,
                ':categoria_operacion' => DefinicionMovimiento::CATEGORIA_AJUSTE,
                ':origen_operacion' => $origen,
                ':tipo_movimiento' => $etiquetaMotivo,
                ':sentido_movimiento' => $sentido,
                ':cantidad' => $cantidad,
                ':saldo_anterior' => $saldoAnteriorReal,
                ':saldo_anterior_esperado' => $saldoEsperado,
                ':saldo_resultante' => $saldoResultante,
                ':justificacion' => $justificacion,
                ':condicion_lote' => DefinicionMovimiento::LOTE_NO_APLICA,
                ':referencia_solicitud' => $refValidada
            ]);
            $idOperacion = (int)$pdo->lastInsertId();

            // 6. Aplicar movimiento canónico en Kardex y actualizar producto.stock_actual
            $resMov = $this->movimientoService->aplicarMovimiento([
                'id_producto' => $idProductoEntero,
                'id_usuario' => (int)$idUsuario,
                'origen_operacion' => $origen,
                'sentido_movimiento' => $sentido,
                'tipo_movimiento' => $etiquetaMotivo,
                'cantidad' => $cantidad,
                'saldo_anterior_esperado' => $saldoEsperado,
                'id_operacion_inventario' => $idOperacion,
                'referencia_operacion' => "Ajuste #{$idOperacion} - {$etiquetaMotivo}",
                'justificacion' => $justificacion,
                'exigir_producto_activo' => true,
                'exigir_admin' => true
            ]);

            return [
                'status' => true,
                'code' => 'ADJUSTMENT_CREATED',
                'idempotent_replay' => false,
                'message' => 'Ajuste de stock registrado correctamente.',
                'data' => [
                    'id_operacion' => $idOperacion,
                    'id_kardex' => $resMov['id_kardex'],
                    'referencia_solicitud' => $refValidada,
                    'fecha_hora' => $resMov['fecha_hora'],
                    'id_producto' => $idProductoEntero,
                    'producto' => $resMov['producto'],
                    'codigo_barra' => $resMov['codigo_barra'],
                    'categoria' => $resMov['categoria'],
                    'subcategoria' => $resMov['subcategoria'],
                    'unidad' => $resMov['unidad'],
                    'tipo_movimiento' => $etiquetaMotivo,
                    'sentido' => $sentido,
                    'cantidad' => $cantidad,
                    'saldo_anterior' => $saldoAnteriorReal,
                    'saldo_resultante' => $saldoResultante,
                    'lote' => 'Sin lote',
                    'justificacion' => $justificacion,
                    'responsable' => $resMov['responsable']
                ]
            ];
        };

        // Ejecución con control de idempotencia
        $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
            (int)$idUsuario,
            DefinicionMovimiento::SOLICITUD_AJUSTE,
            $refValidada,
            $payloadFuncional,
            function (PDO $pdo) use ($operacion) {
                $resultado = $operacion($pdo);
                return [
                    'id_registro' => (int)($resultado['data']['id_operacion'] ?? 0),
                    'response' => $resultado
                ];
            }
        );

        $respuestaFinal = $resIdemp['response'];
        if (!empty($resIdemp['idempotent_replay'])) {
            $respuestaFinal['idempotent_replay'] = true;
            $respuestaFinal['status'] = true;
            $respuestaFinal['code'] = 'OPERATION_ALREADY_CONFIRMED';
        }

        return $respuestaFinal;
    }
}
