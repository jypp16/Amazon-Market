<?php

namespace Libraries\Core;

/**
 * Representación canónica e inequívoca de origen, sentido, condición de lote y
 * cantidades enteras de movimientos de inventario.
 *
 * El efecto matemático sobre el stock depende exclusivamente de las constantes
 * canónicas de `origen_operacion` y `sentido_movimiento`, jamás de textos visibles.
 */
final class DefinicionMovimiento {

    // Orígenes canónicos de operación en kardex
    public const ORIGEN_COMPRA            = 'COMPRA';
    public const ORIGEN_CORRECCION_COMPRA = 'CORRECCION_COMPRA';
    public const ORIGEN_ANULACION_COMPRA  = 'ANULACION_COMPRA';
    public const ORIGEN_VENTA             = 'VENTA';
    public const ORIGEN_ANULACION_VENTA   = 'ANULACION_VENTA';
    public const ORIGEN_BAJA_DANO         = 'BAJA_DANO';
    public const ORIGEN_BAJA_VENCIMIENTO  = 'BAJA_VENCIMIENTO';
    public const ORIGEN_AJUSTE_POSITIVO   = 'AJUSTE_POSITIVO';
    public const ORIGEN_AJUSTE_NEGATIVO   = 'AJUSTE_NEGATIVO';

    // Sentidos canónicos del movimiento
    public const SENTIDO_INCREMENTO  = 'INCREMENTO';
    public const SENTIDO_DISMINUCION = 'DISMINUCION';
    public const SENTIDO_TODOS       = 'TODOS';

    // Categorías de operación de inventario (tabla operacion_inventario)
    public const CATEGORIA_BAJA   = 'BAJA';
    public const CATEGORIA_AJUSTE = 'AJUSTE';

    // Condiciones canónicas de lote
    public const LOTE_CON_REFERENCIA = 'CON_LOTE';
    public const LOTE_SIN_REFERENCIA = 'SIN_LOTE';
    public const LOTE_NO_APLICA      = 'NO_APLICA';

    // Tipos de operación para control_solicitud_idempotencia
    public const SOLICITUD_BAJA              = 'BAJA';
    public const SOLICITUD_AJUSTE            = 'AJUSTE';
    public const SOLICITUD_COMPRA            = 'COMPRA';
    public const SOLICITUD_CORRECCION_COMPRA = 'CORRECCION_COMPRA';
    public const SOLICITUD_ANULACION_COMPRA  = 'ANULACION_COMPRA';
    public const SOLICITUD_VENTA             = 'VENTA';
    public const SOLICITUD_ANULACION_VENTA   = 'ANULACION_VENTA';

    /**
     * Mapa determinístico de cada origen con sus sentidos permitidos,
     * etiqueta legible para reportes, pertenencia a Variaciones y tipo de referencia.
     */
    private const REGLAS_ORIGEN = [
        self::ORIGEN_COMPRA => [
            'sentidos_permitidos' => [self::SENTIDO_INCREMENTO],
            'etiqueta' => 'Compra',
            'es_variacion' => false,
            'referencia_requerida' => 'COMPRA',
        ],
        self::ORIGEN_CORRECCION_COMPRA => [
            'sentidos_permitidos' => [self::SENTIDO_INCREMENTO, self::SENTIDO_DISMINUCION],
            'etiqueta' => 'Corrección de compra',
            'es_variacion' => false,
            'referencia_requerida' => 'COMPRA',
        ],
        self::ORIGEN_ANULACION_COMPRA => [
            'sentidos_permitidos' => [self::SENTIDO_DISMINUCION],
            'etiqueta' => 'Anulación de compra',
            'es_variacion' => false,
            'referencia_requerida' => 'COMPRA',
        ],
        self::ORIGEN_VENTA => [
            'sentidos_permitidos' => [self::SENTIDO_DISMINUCION],
            'etiqueta' => 'Venta',
            'es_variacion' => false,
            'referencia_requerida' => 'VENTA',
        ],
        self::ORIGEN_ANULACION_VENTA => [
            'sentidos_permitidos' => [self::SENTIDO_INCREMENTO],
            'etiqueta' => 'Anulación de venta',
            'es_variacion' => false,
            'referencia_requerida' => 'VENTA',
        ],
        self::ORIGEN_BAJA_DANO => [
            'sentidos_permitidos' => [self::SENTIDO_DISMINUCION],
            'etiqueta' => 'Baja por daño',
            'es_variacion' => true,
            'categoria_operacion' => self::CATEGORIA_BAJA,
            'referencia_requerida' => 'OPERACION_INVENTARIO',
        ],
        self::ORIGEN_BAJA_VENCIMIENTO => [
            'sentidos_permitidos' => [self::SENTIDO_DISMINUCION],
            'etiqueta' => 'Baja por vencimiento',
            'es_variacion' => true,
            'categoria_operacion' => self::CATEGORIA_BAJA,
            'referencia_requerida' => 'OPERACION_INVENTARIO',
        ],
        self::ORIGEN_AJUSTE_POSITIVO => [
            'sentidos_permitidos' => [self::SENTIDO_INCREMENTO],
            'etiqueta' => 'Ajuste positivo',
            'es_variacion' => true,
            'categoria_operacion' => self::CATEGORIA_AJUSTE,
            'referencia_requerida' => 'OPERACION_INVENTARIO',
        ],
        self::ORIGEN_AJUSTE_NEGATIVO => [
            'sentidos_permitidos' => [self::SENTIDO_DISMINUCION],
            'etiqueta' => 'Ajuste negativo',
            'es_variacion' => true,
            'categoria_operacion' => self::CATEGORIA_AJUSTE,
            'referencia_requerida' => 'OPERACION_INVENTARIO',
        ],
    ];

    public static function esOrigenValido(string $origen): bool {
        return isset(self::REGLAS_ORIGEN[$origen]);
    }

    public static function esSentidoValido(string $sentido): bool {
        return in_array($sentido, [self::SENTIDO_INCREMENTO, self::SENTIDO_DISMINUCION], true);
    }

    public static function validarCombinacionOrigenSentido(string $origen, string $sentido): void {
        if (!self::esOrigenValido($origen)) {
            throw InventarioException::validacion(
                ['origen_operacion' => "El origen de operación '{$origen}' no es válido."]
            );
        }

        if (!self::esSentidoValido($sentido)) {
            throw InventarioException::validacion(
                ['sentido_movimiento' => "El sentido de movimiento '{$sentido}' no es válido."]
            );
        }

        $permitidos = self::REGLAS_ORIGEN[$origen]['sentidos_permitidos'];
        if (!in_array($sentido, $permitidos, true)) {
            throw InventarioException::validacion([
                'sentido_movimiento' => "El origen '{$origen}' no admite el sentido '{$sentido}'.",
            ]);
        }
    }

    public static function obtenerSignoMatematico(string $sentido): int {
        if ($sentido === self::SENTIDO_INCREMENTO) {
            return 1;
        }
        if ($sentido === self::SENTIDO_DISMINUCION) {
            return -1;
        }
        throw InventarioException::validacion([
            'sentido_movimiento' => "Sentido de movimiento desconocido: '{$sentido}'.",
        ]);
    }

    /**
     * Valida de manera estricta que una cantidad de movimiento sea un entero >= 1.
     */
    public static function validarCantidadEnteraPositiva(mixed $valor, string $campo = 'cantidad'): int {
        if (is_bool($valor) || is_array($valor) || is_object($valor) || $valor === null || $valor === '') {
            throw InventarioException::validacion([
                $campo => 'La cantidad debe ser un número entero mayor o igual a 1.',
            ]);
        }

        if (is_int($valor)) {
            $entero = $valor;
        } elseif (is_string($valor)) {
            $limpio = trim($valor);
            if (!preg_match('/^\d+$/', $limpio)) {
                throw InventarioException::validacion([
                    $campo => 'La cantidad debe ser un número entero mayor o igual a 1 (no se permiten decimales ni signos negativos).',
                ]);
            }
            $entero = (int)$limpio;
        } elseif (is_float($valor)) {
            if (floor($valor) !== $valor || is_nan($valor) || is_infinite($valor)) {
                throw InventarioException::validacion([
                    $campo => 'La cantidad debe ser un número entero mayor o igual a 1 (no se permiten decimales).',
                ]);
            }
            $entero = (int)$valor;
        } else {
            throw InventarioException::validacion([
                $campo => 'La cantidad debe ser un número entero mayor o igual a 1.',
            ]);
        }

        if ($entero < 1) {
            throw InventarioException::validacion([
                $campo => 'La cantidad debe ser un número entero mayor o igual a 1.',
            ]);
        }

        return $entero;
    }

    public static function calcularSaldoResultante(int $saldoAnterior, string $sentido, int $cantidad): int {
        if ($saldoAnterior < 0) {
            throw InventarioException::validacion([
                'saldo_anterior' => 'El saldo anterior no puede ser negativo.',
            ]);
        }
        $cantidadValidada = self::validarCantidadEnteraPositiva($cantidad);
        $signo = self::obtenerSignoMatematico($sentido);
        return $saldoAnterior + ($signo * $cantidadValidada);
    }

    public static function obtenerEtiquetaTipoMovimiento(string $origen): string {
        if (!self::esOrigenValido($origen)) {
            throw InventarioException::validacion([
                'origen_operacion' => "El origen de operación '{$origen}' no es válido.",
            ]);
        }
        return self::REGLAS_ORIGEN[$origen]['etiqueta'];
    }

    public static function obtenerCategoriaOperacionInventario(string $origen): string {
        if (!isset(self::REGLAS_ORIGEN[$origen]['categoria_operacion'])) {
            throw InventarioException::validacion([
                'origen_operacion' => "El origen '{$origen}' no corresponde a una baja o ajuste de inventario.",
            ]);
        }
        return self::REGLAS_ORIGEN[$origen]['categoria_operacion'];
    }

    public static function obtenerTipoReferenciaRequerida(string $origen): string {
        if (!self::esOrigenValido($origen)) {
            throw InventarioException::validacion([
                'origen_operacion' => "El origen de operación '{$origen}' no es válido.",
            ]);
        }
        return self::REGLAS_ORIGEN[$origen]['referencia_requerida'];
    }

    public static function esOrigenDeVariaciones(string $origen): bool {
        return self::REGLAS_ORIGEN[$origen]['es_variacion'] ?? false;
    }

    /**
     * Normaliza el lote opcional y determina su condición registrada.
     */
    public static function normalizarLote(?string $lote, string $origen): array {
        $texto = $lote !== null ? trim($lote) : '';

        if ($texto !== '') {
            if (mb_strlen($texto) > 50) {
                throw InventarioException::validacion([
                    'lote' => 'El código de lote no puede exceder los 50 caracteres.',
                ]);
            }
            return [
                'numero_lote' => $texto,
                'condicion_lote' => self::LOTE_CON_REFERENCIA,
                'texto_visible' => $texto,
            ];
        }

        if (in_array($origen, [self::ORIGEN_BAJA_DANO, self::ORIGEN_BAJA_VENCIMIENTO], true)) {
            return [
                'numero_lote' => null,
                'condicion_lote' => self::LOTE_SIN_REFERENCIA,
                'texto_visible' => 'Sin lote',
            ];
        }

        if (in_array($origen, [self::ORIGEN_COMPRA, self::ORIGEN_CORRECCION_COMPRA, self::ORIGEN_ANULACION_COMPRA], true)) {
            return [
                'numero_lote' => null,
                'condicion_lote' => self::LOTE_SIN_REFERENCIA,
                'texto_visible' => 'Sin lote',
            ];
        }

        return [
            'numero_lote' => null,
            'condicion_lote' => self::LOTE_NO_APLICA,
            'texto_visible' => 'Sin lote',
        ];
    }
}
