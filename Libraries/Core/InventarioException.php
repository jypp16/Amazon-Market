<?php

namespace Libraries\Core;

class InventarioException extends \RuntimeException {

    private string $errorCode;
    private int $httpStatus;
    private array $errors;
    private array $contextData;

    public function __construct(
        string $message,
        string $errorCode = 'INVENTORY_ERROR',
        int $httpStatus = 422,
        array $errors = [],
        array $contextData = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->errors = $errors;
        $this->contextData = $contextData;
    }

    public function getErrorCode(): string {
        return $this->errorCode;
    }

    public function getHttpStatus(): int {
        return $this->httpStatus;
    }

    public function getHttpCode(): int {
        return $this->httpStatus;
    }

    public function getStatusCode(): int {
        return $this->httpStatus;
    }

    public function asResponseArray(): array {
        return $this->toApiResponse();
    }

    public function getErrors(): array {
        return $this->errors;
    }

    public function getContextData(): array {
        return $this->contextData;
    }

    public function toApiResponse(): array {
        $response = [
            'status' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if (!empty($this->errors)) {
            $response['errors'] = $this->errors;
        }

        if (!empty($this->contextData)) {
            $response['data'] = $this->contextData;
        } else {
            $response['data'] = null;
        }

        return $response;
    }

    public static function validacion(array $errors, string $message = 'Verifique los datos ingresados en el formulario.'): self {
        return new self($message, 'VALIDATION_ERROR', 422, $errors);
    }

    public static function stockInsuficiente(
        int $idProducto,
        int $stockActual,
        int $cantidadSolicitada,
        string $nombreProducto = ''
    ): self {
        $prefijo = $nombreProducto !== ''
            ? "Stock insuficiente para \"{$nombreProducto}\"."
            : 'Stock insuficiente.';
        $mensaje = "{$prefijo} El producto dispone actualmente de {$stockActual} unidades y se intentó descontar {$cantidadSolicitada}.";

        return new self(
            $mensaje,
            'INSUFFICIENT_STOCK',
            409,
            ['cantidad' => "La cantidad supera el stock disponible ({$stockActual} unidades)."],
            [
                'id_producto' => $idProducto,
                'stock_actual' => $stockActual,
                'cantidad_solicitada' => $cantidadSolicitada,
            ]
        );
    }

    public static function saldoCambiado(int $idProducto, int $saldoEsperado, int $stockActual): self {
        return new self(
            "El stock del producto cambió de {$saldoEsperado} a {$stockActual} unidades mientras preparaba el ajuste. Revise el nuevo saldo antes de confirmar.",
            'STOCK_CHANGED_CONFLICT',
            409,
            ['saldo_anterior_esperado' => "El saldo vigente cambió a {$stockActual} unidades."],
            [
                'id_producto' => $idProducto,
                'saldo_anterior_esperado' => $saldoEsperado,
                'stock_actual' => $stockActual,
                'requiere_revision' => true,
            ]
        );
    }

    public static function conflictoIdempotencia(string $referenciaSolicitud): self {
        return new self(
            'La referencia de solicitud ya fue utilizada con datos diferentes. Genere una nueva solicitud.',
            'IDEMPOTENCY_PAYLOAD_MISMATCH',
            409,
            ['referencia_solicitud' => 'La referencia de solicitud no coincide con los datos originales.'],
            ['referencia_solicitud' => $referenciaSolicitud]
        );
    }

    public static function solicitudEnProceso(string $referenciaSolicitud): self {
        return new self(
            'La solicitud se encuentra actualmente en procesamiento. Espere su finalización.',
            'REQUEST_IN_PROGRESS',
            409,
            [],
            [
                'referencia_solicitud' => $referenciaSolicitud,
                'estado_solicitud' => 'EN_PROCESO',
            ]
        );
    }

    public static function noAutenticado(
        string $message = 'Su sesión ha expirado o el usuario ya no se encuentra activo.'
    ): self {
        return new self($message, 'AUTH_REQUIRED', 401);
    }

    public static function accesoDenegadoAdmin(
        string $message = 'Acceso denegado. Esta operación está reservada exclusivamente a usuarios con rol Administrador.'
    ): self {
        return new self($message, 'FORBIDDEN_ADMIN_ONLY', 403);
    }

    public static function referenciaConsultaExpirada(string $referenciaConsulta = ''): self {
        return new self(
            'La referencia de la consulta ha expirado. Pulse Consultar nuevamente para actualizar el reporte.',
            'QUERY_REFERENCE_EXPIRED',
            410,
            [],
            [
                'referencia_consulta' => $referenciaConsulta,
                'requiere_nueva_consulta' => true,
            ]
        );
    }

    public static function referenciaConsultaNoAutorizada(): self {
        return new self(
            'La referencia de consulta no pertenece al usuario actual.',
            'QUERY_REFERENCE_FORBIDDEN',
            403
        );
    }

    public static function filtrosConsultaConflicto(string $referenciaConsulta): self {
        return new self(
            'Los filtros enviados no coinciden con la referencia de consulta activa. Ejecute una nueva consulta para aplicar los nuevos filtros.',
            'QUERY_FILTERS_MISMATCH',
            409,
            [],
            [
                'referencia_consulta' => $referenciaConsulta,
                'requiere_nueva_consulta' => true,
            ]
        );
    }

    public static function exportacionVacia(string $tipoReporte): self {
        return new self(
            'No hay movimientos exportables para esta consulta. Ajuste los filtros y vuelva a consultar.',
            'EMPTY_EXPORT_NOT_ALLOWED',
            422,
            [],
            ['tipo_reporte' => $tipoReporte]
        );
    }
}
