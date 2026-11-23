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
    public function registrarBaja(
        array $input,
        ?string $referencia_solicitud = null,
        ?int $authenticatedUserId = null,
        bool $soloBajaVencimiento = false
    ): array {
        // 1. Extraer o validar referencia de solicitud
        if ($referencia_solicitud === null && !empty($input['referencia_solicitud']) && is_string($input['referencia_solicitud'])) {
            $referencia_solicitud = trim($input['referencia_solicitud']);
        }
        $refValidada = $this->validarReferenciaSolicitud($referencia_solicitud);

        // 2. Validar responsable activo (Administrador, o Vendedor autorizado únicamente para baja por vencimiento)
        $idUsuario = $authenticatedUserId !== null ? $authenticatedUserId : filter_var($input['id_usuario'] ?? null, FILTER_VALIDATE_INT);
        if ($idUsuario === false || $idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }
        $responsable = $this->movimientoService->verificarResponsableActivo((int)$idUsuario, !$soloBajaVencimiento);

        // 3. Validar identificador de producto
        $idProducto = filter_var($input['id_producto'] ?? null, FILTER_VALIDATE_INT);
        if ($idProducto === false || $idProducto <= 0) {
            throw InventarioException::validacion([
                'id_producto' => 'Debe seleccionar un producto válido.'
            ]);
        }
        $idProductoEntero = (int)$idProducto;

        // 4. Validar motivo de baja (restringido exclusivamente a Vencimiento si el operador no es Administrador)
        $motivoData = $this->normalizarMotivoBaja($input['motivo_baja'] ?? null);
        $origen = $motivoData['origen'];
        $etiquetaMotivo = $motivoData['etiqueta'];
        if ($soloBajaVencimiento && $origen !== DefinicionMovimiento::ORIGEN_BAJA_VENCIMIENTO) {
            throw InventarioException::accesoDenegadoAdmin(
                'Los vendedores autorizados únicamente pueden registrar bajas manuales por motivo de vencimiento.'
            );
        }

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
            $cantidad, $justificacion, $loteInfo, $refValidada, $soloBajaVencimiento
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

            // 4. Insertar registro en operacion_inventario (original + vigente inicial)
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
                fecha_registro,
                categoria_vigente,
                origen_vigente,
                tipo_movimiento_vigente,
                sentido_vigente,
                cantidad_vigente,
                justificacion_vigente,
                numero_lote_vigente,
                condicion_lote_vigente,
                version,
                total_correcciones
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
                NOW(),
                :categoria_vigente,
                :origen_vigente,
                :tipo_movimiento_vigente,
                :sentido_vigente,
                :cantidad_vigente,
                :justificacion_vigente,
                :numero_lote_vigente,
                :condicion_lote_vigente,
                1,
                0
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
                ':referencia_solicitud' => $refValidada,
                ':categoria_vigente' => DefinicionMovimiento::CATEGORIA_BAJA,
                ':origen_vigente' => $origen,
                ':tipo_movimiento_vigente' => $etiquetaMotivo,
                ':sentido_vigente' => DefinicionMovimiento::SENTIDO_DISMINUCION,
                ':cantidad_vigente' => $cantidad,
                ':justificacion_vigente' => $justificacion,
                ':numero_lote_vigente' => $loteInfo['numero_lote'],
                ':condicion_lote_vigente' => $loteInfo['condicion_lote']
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
                'exigir_admin' => !$soloBajaVencimiento
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

            // 5. Insertar registro en operacion_inventario (original + vigente inicial)
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
                fecha_registro,
                categoria_vigente,
                origen_vigente,
                tipo_movimiento_vigente,
                sentido_vigente,
                cantidad_vigente,
                justificacion_vigente,
                numero_lote_vigente,
                condicion_lote_vigente,
                version,
                total_correcciones
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
                NOW(),
                :categoria_vigente,
                :origen_vigente,
                :tipo_movimiento_vigente,
                :sentido_vigente,
                :cantidad_vigente,
                :justificacion_vigente,
                NULL,
                :condicion_lote_vigente,
                1,
                0
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
                ':referencia_solicitud' => $refValidada,
                ':categoria_vigente' => DefinicionMovimiento::CATEGORIA_AJUSTE,
                ':origen_vigente' => $origen,
                ':tipo_movimiento_vigente' => $etiquetaMotivo,
                ':sentido_vigente' => $sentido,
                ':cantidad_vigente' => $cantidad,
                ':justificacion_vigente' => $justificacion,
                ':condicion_lote_vigente' => DefinicionMovimiento::LOTE_NO_APLICA
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

    /**
     * Corrige un movimiento de inventario (HU-24) preservando el registro original inmutable,
     * registrando auditoría cronológica, actualizando stock y emitiendo asiento diferencial
     * en Kardex solo cuando el delta != 0. Protegido por bloqueos FOR UPDATE e idempotencia.
     */
    public function corregirMovimiento(
        int $idOperacion,
        array $input,
        ?string $referencia_solicitud = null,
        ?int $authenticatedUserId = null,
        ?callable $afterKardexHook = null
    ): array {
        if ($idOperacion <= 0) {
            throw new InventarioException('El identificador del movimiento debe ser un entero positivo.', 'INVALID_ID', 400);
        }

        // 1. Validar referencia de solicitud (cuerpo o cabecera)
        $refValidada = ControlSolicitudService::resolverReferenciaCuerpoOCabecera($input, $referencia_solicitud);

        // 2. Validar responsable administrador activo en BD
        $idUsuario = $authenticatedUserId !== null ? $authenticatedUserId : filter_var($input['id_usuario'] ?? null, FILTER_VALIDATE_INT);
        if ($idUsuario === false || $idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }
        $this->movimientoService->verificarResponsableActivo((int)$idUsuario, true);

        // 3. Validar tipo de movimiento resultante
        $tipoSlug = is_string($input['tipo'] ?? null) ? trim($input['tipo']) : '';
        if (!isset(OperacionInventarioModel::TIPO_A_ORIGEN[$tipoSlug])) {
            throw InventarioException::validacion([
                'tipo' => 'El tipo de movimiento debe ser baja_danio, baja_vencimiento, ajuste_positivo o ajuste_negativo.'
            ]);
        }
        $origenNuevo = OperacionInventarioModel::TIPO_A_ORIGEN[$tipoSlug];
        $esBajaNueva = in_array($origenNuevo, [DefinicionMovimiento::ORIGEN_BAJA_DANO, DefinicionMovimiento::ORIGEN_BAJA_VENCIMIENTO], true);
        $categoriaNueva = $esBajaNueva ? DefinicionMovimiento::CATEGORIA_BAJA : DefinicionMovimiento::CATEGORIA_AJUSTE;
        $sentidoNuevo = ($origenNuevo === DefinicionMovimiento::ORIGEN_AJUSTE_POSITIVO)
            ? DefinicionMovimiento::SENTIDO_INCREMENTO
            : DefinicionMovimiento::SENTIDO_DISMINUCION;
        $etiquetaNueva = DefinicionMovimiento::obtenerEtiquetaTipoMovimiento($origenNuevo);

        // 4. Validar cantidad entera positiva
        $cantidadNueva = DefinicionMovimiento::validarCantidadEnteraPositiva($input['cantidad'] ?? null, 'cantidad');

        // 5. Validar justificación obligatoria (1..255)
        $justificacionNueva = is_string($input['justificacion'] ?? null) ? trim($input['justificacion']) : '';
        if ($justificacionNueva === '') {
            throw InventarioException::validacion([
                'justificacion' => 'La justificación del movimiento es obligatoria.'
            ]);
        }
        if (mb_strlen($justificacionNueva, 'UTF-8') > 255) {
            throw InventarioException::validacion([
                'justificacion' => 'La justificación no puede exceder los 255 caracteres.'
            ]);
        }

        // 6. Validar lote (solo permitido cuando el tipo resultante es una baja)
        $loteRaw = array_key_exists('lote', $input) ? $input['lote'] : ($input['numero_lote'] ?? null);
        if ($loteRaw !== null && !is_string($loteRaw)) {
            throw InventarioException::validacion([
                'lote' => 'El código de lote debe ser texto.'
            ]);
        }
        $loteTrim = $loteRaw !== null ? trim($loteRaw) : '';
        if (!$esBajaNueva && $loteTrim !== '') {
            throw InventarioException::validacion([
                'lote' => 'El lote solo aplica cuando el tipo de movimiento resultante es una baja.'
            ]);
        }
        $loteInfo = DefinicionMovimiento::normalizarLote($esBajaNueva ? $loteTrim : null, $origenNuevo);
        $loteNuevo = $loteInfo['numero_lote'];

        // 7. Validar motivo obligatorio (1..250)
        $motivo = is_string($input['motivo'] ?? null) ? trim($input['motivo']) : '';
        if ($motivo === '') {
            throw InventarioException::validacion([
                'motivo' => 'El motivo de la corrección es obligatorio.'
            ]);
        }
        if (mb_strlen($motivo, 'UTF-8') > 250) {
            throw InventarioException::validacion([
                'motivo' => 'El motivo de la corrección no puede superar los 250 caracteres.'
            ]);
        }

        // 8. Validar version y stock_actual_esperado obligatorios
        $versionEsperada = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT);
        if ($versionEsperada === false || (int)$versionEsperada < 1) {
            throw InventarioException::validacion([
                'version' => 'Debe enviar la versión vigente del movimiento (entero mayor o igual a 1).'
            ]);
        }
        $versionEsperada = (int)$versionEsperada;

        if (!array_key_exists('stock_actual_esperado', $input) || $input['stock_actual_esperado'] === null || $input['stock_actual_esperado'] === '') {
            throw InventarioException::validacion([
                'stock_actual_esperado' => 'Debe proporcionar el stock actual esperado del producto.'
            ]);
        }
        $stockEsperado = filter_var($input['stock_actual_esperado'], FILTER_VALIDATE_INT);
        if ($stockEsperado === false || (int)$stockEsperado < 0) {
            throw InventarioException::validacion([
                'stock_actual_esperado' => 'El stock actual esperado debe ser un número entero mayor o igual a 0.'
            ]);
        }
        $stockEsperado = (int)$stockEsperado;

        $payloadFuncional = [
            'id_operacion' => $idOperacion,
            'tipo' => $tipoSlug,
            'cantidad' => $cantidadNueva,
            'justificacion' => $justificacionNueva,
            'lote' => $loteNuevo,
            'motivo' => $motivo,
            'version' => $versionEsperada,
            'stock_actual_esperado' => $stockEsperado,
        ];

        $operacion = function (PDO $pdo) use (
            $idOperacion, $idUsuario, $tipoSlug, $origenNuevo, $categoriaNueva, $sentidoNuevo,
            $etiquetaNueva, $cantidadNueva, $justificacionNueva, $loteInfo, $loteNuevo,
            $motivo, $versionEsperada, $stockEsperado, $refValidada, $afterKardexHook
        ) {
            // 1. Bloquear la fila de operacion_inventario
            $stmtLockOp = $pdo->prepare(
                "SELECT * FROM operacion_inventario
                 WHERE id_operacion = :id
                   AND origen_operacion IN ('BAJA_DANO', 'BAJA_VENCIMIENTO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO')
                 FOR UPDATE"
            );
            $stmtLockOp->execute([':id' => $idOperacion]);
            $op = $stmtLockOp->fetch(PDO::FETCH_ASSOC);
            if (!$op) {
                throw new InventarioException('El movimiento solicitado no existe.', 'MOVEMENT_NOT_FOUND', 404);
            }

            // 2. Bloquear el producto asociado en orden determinístico
            $idProducto = (int)$op['id_producto'];
            $productosBloqueados = $this->movimientoService->bloquearProductosOrdenados([$idProducto]);
            $producto = $productosBloqueados[$idProducto];

            // 3. Validar concurrencia (versión y stock actual bloqueado)
            $versionActual = (int)$op['version'];
            $stockActual = (int)$producto['stock_actual'];
            if ($versionActual !== $versionEsperada) {
                throw new InventarioException(
                    "El movimiento fue modificado por otra operación (versión actual {$versionActual}). Revisa los datos actualizados antes de confirmar.",
                    'VERSION_CONFLICT',
                    409,
                    ['version' => 'La versión del movimiento cambió.'],
                    ['version_actual' => $versionActual, 'stock_actual' => $stockActual, 'requiere_revision' => true]
                );
            }
            if ($stockActual !== $stockEsperado) {
                throw new InventarioException(
                    "El stock del producto cambió de {$stockEsperado} a {$stockActual} unidades mientras revisabas la corrección. Revisa la vista previa actualizada antes de confirmar.",
                    'STOCK_CHANGED_CONFLICT',
                    409,
                    ['stock_actual_esperado' => "El stock actual cambió a {$stockActual} unidades."],
                    ['version_actual' => $versionActual, 'stock_actual' => $stockActual, 'requiere_revision' => true]
                );
            }

            // 4. Detectar cambios respecto al estado vigente
            $origenAnterior = (string)$op['origen_vigente'];
            $tipoAnterior = OperacionInventarioModel::ORIGEN_A_TIPO[$origenAnterior] ?? strtolower($origenAnterior);
            $cantidadAnterior = (int)$op['cantidad_vigente'];
            $justificacionAnterior = (string)$op['justificacion_vigente'];
            $loteAnterior = ($op['numero_lote_vigente'] !== null && trim((string)$op['numero_lote_vigente']) !== '')
                ? (string)$op['numero_lote_vigente']
                : null;

            $cambios = [];
            if ($tipoAnterior !== $tipoSlug) {
                $cambios[] = ['campo' => 'tipo', 'anterior' => $tipoAnterior, 'nuevo' => $tipoSlug];
            }
            if ($cantidadAnterior !== $cantidadNueva) {
                $cambios[] = ['campo' => 'cantidad', 'anterior' => $cantidadAnterior, 'nuevo' => $cantidadNueva];
            }
            if ($justificacionAnterior !== $justificacionNueva) {
                $cambios[] = ['campo' => 'justificacion', 'anterior' => $justificacionAnterior, 'nuevo' => $justificacionNueva];
            }
            if ($loteAnterior !== $loteNuevo) {
                $cambios[] = ['campo' => 'lote', 'anterior' => $loteAnterior, 'nuevo' => $loteNuevo];
            }

            if (empty($cambios)) {
                throw new InventarioException(
                    'No se modificó ningún dato del movimiento. Realiza al menos un cambio para guardar la corrección.',
                    'NO_CHANGES',
                    422,
                    ['general' => 'No se detectaron cambios respecto a los valores vigentes.']
                );
            }

            // 5. Calcular efecto diferencial y saldo resultante sobre el stock actual bloqueado
            $signoAnterior = ($op['sentido_vigente'] === DefinicionMovimiento::SENTIDO_INCREMENTO) ? 1 : -1;
            $signoNuevo = ($sentidoNuevo === DefinicionMovimiento::SENTIDO_INCREMENTO) ? 1 : -1;
            $efectoAnterior = $signoAnterior * $cantidadAnterior;
            $efectoNuevo = $signoNuevo * $cantidadNueva;
            $deltaStock = $efectoNuevo - $efectoAnterior;
            $saldoResultante = $stockActual + $deltaStock;

            if ($saldoResultante < 0) {
                throw new InventarioException(
                    "La corrección produciría un saldo resultante negativo ({$saldoResultante} unidades). Ajusta la cantidad o el tipo.",
                    'NEGATIVE_STOCK_RESULT',
                    422,
                    ['cantidad' => "El saldo resultante sería {$saldoResultante} unidades."],
                    [
                        'stock_actual' => $stockActual,
                        'efecto_anterior' => $efectoAnterior,
                        'efecto_nuevo' => $efectoNuevo,
                        'delta_stock' => $deltaStock,
                        'saldo_resultante' => $saldoResultante,
                    ]
                );
            }

            $numeroCorreccion = ((int)$op['total_correcciones']) + 1;
            $versionNueva = $versionActual + 1;
            $idKardex = null;

            // 6. Emitir asiento diferencial en Kardex y actualizar stock SOLO si delta != 0
            if ($deltaStock !== 0) {
                $sentidoDelta = $deltaStock > 0
                    ? DefinicionMovimiento::SENTIDO_INCREMENTO
                    : DefinicionMovimiento::SENTIDO_DISMINUCION;
                $resMov = $this->movimientoService->aplicarMovimiento([
                    'id_producto' => $idProducto,
                    'id_usuario' => (int)$idUsuario,
                    'origen_operacion' => DefinicionMovimiento::ORIGEN_CORRECCION_INVENTARIO,
                    'sentido_movimiento' => $sentidoDelta,
                    'tipo_movimiento' => 'Corrección de inventario',
                    'cantidad' => abs($deltaStock),
                    'id_operacion_inventario' => $idOperacion,
                    'referencia_operacion' => "Corrección #{$numeroCorreccion} de Mov #{$idOperacion}",
                    'justificacion' => mb_substr("Corrección #{$numeroCorreccion}: {$motivo}", 0, 255, 'UTF-8'),
                    'lote' => $loteNuevo,
                    'exigir_producto_activo' => false,
                    'exigir_admin' => true,
                ]);
                $idKardex = (int)$resMov['id_kardex'];
            }

            // Hook opcional solo para pruebas controladas de fallo intermedio / rollback atómico
            if ($afterKardexHook !== null) {
                $afterKardexHook($pdo, $idOperacion, $idKardex);
            }

            // 7. Actualizar únicamente el estado vigente y versión (originales permanecen inmutables)
            $stmtUpd = $pdo->prepare(
                'UPDATE operacion_inventario
                    SET categoria_vigente = :cat,
                        origen_vigente = :orig,
                        tipo_movimiento_vigente = :tipo_mov,
                        sentido_vigente = :sent,
                        cantidad_vigente = :cant,
                        justificacion_vigente = :just,
                        numero_lote_vigente = :lote,
                        condicion_lote_vigente = :cond_lote,
                        version = :ver_nueva,
                        total_correcciones = :tot_corr,
                        ultima_correccion_en = NOW(),
                        ultima_correccion_por = :id_usr
                  WHERE id_operacion = :id'
            );
            $stmtUpd->execute([
                ':cat' => $categoriaNueva,
                ':orig' => $origenNuevo,
                ':tipo_mov' => $etiquetaNueva,
                ':sent' => $sentidoNuevo,
                ':cant' => $cantidadNueva,
                ':just' => $justificacionNueva,
                ':lote' => $loteNuevo,
                ':cond_lote' => $loteInfo['condicion_lote'],
                ':ver_nueva' => $versionNueva,
                ':tot_corr' => $numeroCorreccion,
                ':id_usr' => (int)$idUsuario,
                ':id' => $idOperacion,
            ]);

            // 8. Registrar entrada auditable en correccion_operacion_inventario
            $stmtCorr = $pdo->prepare(
                'INSERT INTO correccion_operacion_inventario (
                    id_operacion,
                    numero_correccion,
                    version_anterior,
                    version_nueva,
                    id_usuario,
                    motivo,
                    origen_anterior,
                    origen_nuevo,
                    cantidad_anterior,
                    cantidad_nueva,
                    justificacion_anterior,
                    justificacion_nueva,
                    lote_anterior,
                    lote_nuevo,
                    efecto_anterior,
                    efecto_nuevo,
                    delta_stock,
                    stock_anterior,
                    stock_resultante,
                    id_kardex,
                    cambios_json,
                    referencia_solicitud,
                    fecha_correccion
                ) VALUES (
                    :id_operacion,
                    :numero_correccion,
                    :version_anterior,
                    :version_nueva,
                    :id_usuario,
                    :motivo,
                    :origen_anterior,
                    :origen_nuevo,
                    :cantidad_anterior,
                    :cantidad_nueva,
                    :justificacion_anterior,
                    :justificacion_nueva,
                    :lote_anterior,
                    :lote_nuevo,
                    :efecto_anterior,
                    :efecto_nuevo,
                    :delta_stock,
                    :stock_anterior,
                    :stock_resultante,
                    :id_kardex,
                    :cambios_json,
                    :referencia_solicitud,
                    NOW()
                )'
            );
            $stmtCorr->execute([
                ':id_operacion' => $idOperacion,
                ':numero_correccion' => $numeroCorreccion,
                ':version_anterior' => $versionActual,
                ':version_nueva' => $versionNueva,
                ':id_usuario' => (int)$idUsuario,
                ':motivo' => $motivo,
                ':origen_anterior' => $origenAnterior,
                ':origen_nuevo' => $origenNuevo,
                ':cantidad_anterior' => $cantidadAnterior,
                ':cantidad_nueva' => $cantidadNueva,
                ':justificacion_anterior' => $justificacionAnterior,
                ':justificacion_nueva' => $justificacionNueva,
                ':lote_anterior' => $loteAnterior,
                ':lote_nuevo' => $loteNuevo,
                ':efecto_anterior' => $efectoAnterior,
                ':efecto_nuevo' => $efectoNuevo,
                ':delta_stock' => $deltaStock,
                ':stock_anterior' => $stockActual,
                ':stock_resultante' => $saldoResultante,
                ':id_kardex' => $idKardex,
                ':cambios_json' => json_encode($cambios, JSON_UNESCAPED_UNICODE),
                ':referencia_solicitud' => $refValidada,
            ]);
            $idCorreccion = (int)$pdo->lastInsertId();

            $detalleActualizado = $this->operacionModel->obtenerMovimientoPorId($idOperacion);

            return [
                'id_registro' => $idCorreccion,
                'http_code' => 201,
                'response' => [
                    'status' => true,
                    'code' => 'MOVEMENT_CORRECTED',
                    'idempotent_replay' => false,
                    'message' => 'Corrección de movimiento registrada correctamente.',
                    'data' => [
                        'id_correccion' => $idCorreccion,
                        'numero_correccion' => $numeroCorreccion,
                        'delta_stock' => $deltaStock,
                        'stock_anterior' => $stockActual,
                        'stock_resultante' => $saldoResultante,
                        'id_kardex' => $idKardex,
                        'referencia_solicitud' => $refValidada,
                        'movimiento' => $detalleActualizado,
                    ],
                ],
            ];
        };

        $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
            (int)$idUsuario,
            DefinicionMovimiento::SOLICITUD_CORRECCION_INVENTARIO,
            $refValidada,
            $payloadFuncional,
            $operacion
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

