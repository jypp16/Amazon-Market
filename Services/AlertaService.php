<?php

namespace Services;

use DateTimeImmutable;
use DateTimeZone;
use Libraries\Core\Conexion;
use Libraries\Core\DefinicionMovimiento;
use Libraries\Core\InventarioException;
use Models\ProductoModel;
use Models\DetalleCompraModel;
use PDO;

class AlertaService {
    private const ZONA_HORARIA = 'America/Lima';

    private ProductoModel $productoModel;
    private DetalleCompraModel $detalleCompraModel;
    private PDO $pdo;
    private ControlSolicitudService $controlSolicitudService;

    public function __construct(?PDO $pdo = null) {
        $this->productoModel = new ProductoModel();
        $this->detalleCompraModel = new DetalleCompraModel();
        $this->pdo = $pdo ?? Conexion::getInstance()->conect();
        $this->controlSolicitudService = new ControlSolicitudService($this->pdo);
    }

    public function obtenerAlertasStock() {
        // Traer productos cuyo stock_actual <= stock_minimo
        return $this->productoModel->select(['id_producto', 'codigo_barra', 'nombre', 'stock_actual', 'stock_minimo'])
                                   ->whereRaw('stock_actual <= stock_minimo')
                                   ->where(['estado' => 1])
                                   ->orderBy('stock_actual', 'ASC')
                                   ->get();
    }

    public function obtenerRangoFechasVencimiento(int $dias = 15): array {
        $tz = new DateTimeZone(self::ZONA_HORARIA);
        $hoy = new DateTimeImmutable('today', $tz);
        $limite = $hoy->modify('+' . max(0, $dias) . ' days');
        return [
            'hoy' => $hoy->format('Y-m-d'),
            'limite' => $limite->format('Y-m-d'),
        ];
    }

    /**
     * Regla única y compartida para avisos de vencimiento activos (HU-25):
     * - Líneas vigentes (es_vigente = 1) con fecha_vencimiento no nula
     * - Compras no anuladas (estado <> 'Anulada')
     * - Aviso en estado ACTIVO
     * - Fecha pasada, hoy o dentro de los próximos $dias días inclusive (America/Lima)
     * - Excluye estrictamente cantidades compradas y stock del producto.
     */
    public function obtenerAlertasVencimiento(int $dias = 15): array {
        $rango = $this->obtenerRangoFechasVencimiento($dias);
        $fechaHoy = $rango['hoy'];
        $fechaLimite = $rango['limite'];

        $sql = "SELECT
                    av.id_aviso,
                    av.id_linea_compra,
                    av.id_compra,
                    av.id_detalle_compra_vigente,
                    dc.fecha_vencimiento,
                    dc.numero_lote,
                    p.id_producto,
                    p.nombre AS producto_nombre,
                    c.nombre AS categoria_nombre,
                    COALESCE(s.nombre, 'General') AS subcategoria_nombre
                FROM aviso_vencimiento av
                INNER JOIN detalle_compra dc ON dc.id_detalle_compra = av.id_detalle_compra_vigente
                INNER JOIN compra co ON co.id_compra = av.id_compra
                INNER JOIN producto p ON p.id_producto = dc.id_producto
                INNER JOIN categoria c ON c.id_categoria = p.id_categoria
                LEFT JOIN subcategoria s ON s.id_subcategoria = p.id_subcategoria AND s.id_categoria = p.id_categoria
                WHERE av.estado = 'ACTIVO'
                  AND dc.es_vigente = 1
                  AND dc.fecha_vencimiento IS NOT NULL
                  AND co.estado <> 'Anulada'
                  AND dc.fecha_vencimiento <= :fecha_limite
                ORDER BY dc.fecha_vencimiento ASC, av.id_aviso ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':fecha_limite', $fechaLimite, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(static function (array $row) use ($fechaHoy): array {
            $fecha = (string)$row['fecha_vencimiento'];
            if ($fecha < $fechaHoy) {
                $condicion = 'fecha_pasada';
            } elseif ($fecha === $fechaHoy) {
                $condicion = 'vence_hoy';
            } else {
                $condicion = 'proximo_a_vencer';
            }

            $lote = ($row['numero_lote'] !== null && trim((string)$row['numero_lote']) !== '')
                ? (string)$row['numero_lote']
                : null;

            return [
                'id'                => (int)$row['id_aviso'],
                'id_aviso'          => (int)$row['id_aviso'],
                'id_linea_compra'   => (int)$row['id_linea_compra'],
                'id_compra'         => (int)$row['id_compra'],
                'id_detalle_compra' => (int)$row['id_detalle_compra_vigente'],
                'categoria'         => (string)$row['categoria_nombre'],
                'subcategoria'      => (string)$row['subcategoria_nombre'],
                'producto'          => (string)$row['producto_nombre'],
                'fecha_vencimiento' => $fecha,
                'condicion'         => $condicion,
                'lote'              => $lote,
            ];
        }, $rows);

        return [
            'fecha_referencia' => $fechaHoy,
            'fecha_limite'     => $fechaLimite,
            'avisos_activos'   => count($items),
            'items'            => $items,
        ];
    }

    public function obtenerResumenAvisosVencimiento(int $dias = 15): array {
        $rango = $this->obtenerRangoFechasVencimiento($dias);
        $count = $this->contarAvisosActivos($rango['limite']);
        return [
            'fecha_referencia' => $rango['hoy'],
            'fecha_limite'     => $rango['limite'],
            'avisos_activos'   => $count,
        ];
    }

    private function contarAvisosActivos(string $fechaLimite): int {
        $sql = "SELECT COUNT(*)
                FROM aviso_vencimiento av
                INNER JOIN detalle_compra dc ON dc.id_detalle_compra = av.id_detalle_compra_vigente
                INNER JOIN compra co ON co.id_compra = av.id_compra
                WHERE av.estado = 'ACTIVO'
                  AND dc.es_vigente = 1
                  AND dc.fecha_vencimiento IS NOT NULL
                  AND co.estado <> 'Anulada'
                  AND dc.fecha_vencimiento <= :fecha_limite";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':fecha_limite', $fechaLimite, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Registra una revisión física ("Sí, quedan" o "No quedan") sobre un aviso de vencimiento.
     * - Jamás modifica stock ni Kardex.
     * - Valida dentro de la transacción que la línea y fecha vistas sigan vigentes.
     * - Protegido por idempotencia (ControlSolicitudService).
     */
    public function registrarRevisionAviso(
        int $idAviso,
        array $input,
        ?string $referenciaSolicitud,
        int $idUsuario
    ): array {
        if ($idAviso <= 0) {
            throw new InventarioException('El identificador del aviso debe ser un entero positivo.', 'INVALID_ID', 400);
        }
        if ($idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }

        $refValidada = ControlSolicitudService::resolverReferenciaCuerpoOCabecera($input, $referenciaSolicitud);

        $respRaw = is_string($input['respuesta'] ?? null) ? strtolower(trim($input['respuesta'])) : '';
        if (in_array($respRaw, ['si', 'si_quedan', 'sí'], true)) {
            $respuestaCanon = 'SI_QUEDAN';
            $estadoResultante = 'ACTIVO';
        } elseif (in_array($respRaw, ['no', 'no_quedan'], true)) {
            $respuestaCanon = 'NO_QUEDAN';
            $estadoResultante = 'CERRADO';
        } else {
            throw InventarioException::validacion([
                'respuesta' => 'La respuesta debe ser "si" (Sí, quedan) o "no" (No quedan).'
            ]);
        }

        $fechaVista = is_string($input['fecha_vencimiento_vista'] ?? null)
            ? trim($input['fecha_vencimiento_vista'])
            : (is_string($input['fecha_vencimiento'] ?? null) ? trim($input['fecha_vencimiento']) : '');
        if (!$this->esFechaIsoValida($fechaVista)) {
            throw InventarioException::validacion([
                'fecha_vencimiento_vista' => 'Debe indicar la fecha de vencimiento revisada en formato YYYY-MM-DD.'
            ]);
        }

        $tieneCanon = array_key_exists('id_detalle_compra_visto', $input);
        $tieneAlias = array_key_exists('id_detalle_compra', $input);
        if (!$tieneCanon && !$tieneAlias) {
            throw InventarioException::validacion([
                'id_detalle_compra_visto' => 'Debe indicar el identificador entero positivo del detalle de compra revisado (id_detalle_compra_visto).'
            ]);
        }

        $parsePosInt = static function (mixed $raw, string $campo): int {
            if (is_bool($raw) || is_array($raw) || is_object($raw) || $raw === null || $raw === '') {
                throw InventarioException::validacion([
                    $campo => "El campo {$campo} debe ser un número entero positivo."
                ]);
            }
            if (is_string($raw)) {
                $raw = trim($raw);
                if (!preg_match('/^[1-9]\d*$/', $raw)) {
                    throw InventarioException::validacion([
                        $campo => "El campo {$campo} debe ser un número entero positivo."
                    ]);
                }
                return (int)$raw;
            }
            if (is_int($raw) && $raw > 0) {
                return $raw;
            }
            throw InventarioException::validacion([
                $campo => "El campo {$campo} debe ser un número entero positivo."
            ]);
        };

        $valCanon = $tieneCanon ? $parsePosInt($input['id_detalle_compra_visto'], 'id_detalle_compra_visto') : null;
        $valAlias = $tieneAlias ? $parsePosInt($input['id_detalle_compra'], 'id_detalle_compra') : null;

        if ($valCanon !== null && $valAlias !== null && $valCanon !== $valAlias) {
            throw new InventarioException(
                'Los campos id_detalle_compra_visto e id_detalle_compra contienen valores contradictorios.',
                'AMBIGUOUS_ALIAS_VALUES',
                422,
                ['id_detalle_compra_visto' => 'id_detalle_compra_visto e id_detalle_compra deben coincidir cuando se envían ambos.']
            );
        }
        $idDetalleVisto = $valCanon ?? $valAlias;

        $idLineaVista = null;
        if (isset($input['id_linea_compra']) && $input['id_linea_compra'] !== '' && $input['id_linea_compra'] !== null) {
            $linVal = filter_var($input['id_linea_compra'], FILTER_VALIDATE_INT);
            if ($linVal === false || $linVal <= 0) {
                throw InventarioException::validacion(['id_linea_compra' => 'Identificador lógico de línea inválido.']);
            }
            $idLineaVista = (int)$linVal;
        }

        $payloadFuncional = [
            'id_aviso' => $idAviso,
            'respuesta' => $respuestaCanon,
            'fecha_vencimiento_vista' => $fechaVista,
            'id_detalle_compra_visto' => $idDetalleVisto,
            'id_linea_compra' => $idLineaVista,
        ];

        $operacion = function (PDO $pdo) use (
            $idAviso, $idUsuario, $respuestaCanon, $estadoResultante, $fechaVista, $idDetalleVisto, $idLineaVista, $refValidada
        ) {
            $sqlLock = "SELECT
                            av.id_aviso,
                            av.id_linea_compra,
                            av.id_compra,
                            av.id_detalle_compra_vigente,
                            av.fecha_vencimiento_vigente,
                            av.estado AS aviso_estado,
                            dc.es_vigente,
                            dc.fecha_vencimiento AS detalle_fecha_vencimiento,
                            co.estado AS compra_estado
                        FROM aviso_vencimiento av
                        INNER JOIN detalle_compra dc ON dc.id_detalle_compra = av.id_detalle_compra_vigente
                        INNER JOIN compra co ON co.id_compra = av.id_compra
                        WHERE av.id_aviso = :id_aviso
                        FOR UPDATE";
            $stmtLock = $pdo->prepare($sqlLock);
            $stmtLock->execute([':id_aviso' => $idAviso]);
            $row = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new InventarioException('El aviso de vencimiento solicitado no existe.', 'AVISO_NOT_FOUND', 404);
            }
            if ((string)$row['compra_estado'] === 'Anulada' || (int)$row['es_vigente'] !== 1 || $row['detalle_fecha_vencimiento'] === null) {
                throw new InventarioException(
                    'La línea de compra asociada a este aviso ya no está vigente o fue anulada. Actualiza el listado.',
                    'AVISO_NOT_ELIGIBLE',
                    409,
                    [],
                    ['requiere_recarga' => true]
                );
            }

            $rango = $this->obtenerRangoFechasVencimiento(15);
            $fechaLimite = $rango['limite'];
            $fechaVigente = (string)$row['detalle_fecha_vencimiento'];
            $idDetalleVigente = (int)$row['id_detalle_compra_vigente'];
            $idLineaVigente = (int)$row['id_linea_compra'];

            // Aplicar exactamente la misma regla de elegibilidad (+15 días inclusive en America/Lima) que el listado/resumen
            if ($fechaVigente > $fechaLimite) {
                throw new InventarioException(
                    "El aviso con fecha {$fechaVigente} se encuentra fuera del rango elegible (hasta {$fechaLimite}) y no admite confirmación.",
                    'AVISO_NOT_ELIGIBLE',
                    409,
                    ['fecha_vencimiento_vista' => "Fecha fuera del rango elegible (hasta {$fechaLimite})."],
                    [
                        'id_aviso' => $idAviso,
                        'fecha_vencimiento_vigente' => $fechaVigente,
                        'fecha_limite' => $fechaLimite,
                        'requiere_recarga' => true,
                    ]
                );
            }

            if ((string)$row['aviso_estado'] === 'CERRADO') {
                throw new InventarioException(
                    'Este aviso ya fue cerrado previamente. Actualiza el listado.',
                    'AVISO_ALREADY_CLOSED',
                    409,
                    [],
                    ['requiere_recarga' => true]
                );
            }

            if (
                $fechaVigente !== $fechaVista
                || $idDetalleVigente !== $idDetalleVisto
                || ($idLineaVista !== null && $idLineaVigente !== $idLineaVista)
            ) {
                throw new InventarioException(
                    "La línea o fecha de vencimiento de este aviso cambió (vigente: {$fechaVigente}) mientras lo revisabas. Actualiza el listado para confirmar la fecha vigente.",
                    'EXPIRATION_DATE_CHANGED',
                    409,
                    ['fecha_vencimiento_vista' => "La fecha vigente es {$fechaVigente}."],
                    [
                        'id_aviso' => $idAviso,
                        'fecha_vencimiento_vista' => $fechaVista,
                        'fecha_vencimiento_vigente' => $fechaVigente,
                        'id_detalle_compra_vigente' => $idDetalleVigente,
                        'id_linea_compra' => $idLineaVigente,
                        'requiere_recarga' => true,
                    ]
                );
            }

            $stmtRev = $pdo->prepare(
                'INSERT INTO revision_aviso_vencimiento (
                    id_aviso,
                    id_detalle_compra,
                    id_usuario,
                    respuesta,
                    fecha_vencimiento_revisada,
                    estado_resultante,
                    referencia_solicitud,
                    fecha_revision
                ) VALUES (
                    :id_aviso,
                    :id_detalle_compra,
                    :id_usuario,
                    :respuesta,
                    :fecha_revisada,
                    :estado_resultante,
                    :referencia,
                    NOW()
                )'
            );
            $stmtRev->execute([
                ':id_aviso' => $idAviso,
                ':id_detalle_compra' => $idDetalleVigente,
                ':id_usuario' => $idUsuario,
                ':respuesta' => $respuestaCanon,
                ':fecha_revisada' => $fechaVigente,
                ':estado_resultante' => $estadoResultante,
                ':referencia' => $refValidada,
            ]);
            $idRevision = (int)$pdo->lastInsertId();

            if ($estadoResultante === 'CERRADO') {
                $stmtUpd = $pdo->prepare(
                    'UPDATE aviso_vencimiento
                        SET estado = "CERRADO",
                            cerrado_en = NOW(),
                            cerrado_por = :usr_cierre,
                            ultima_revision_en = NOW(),
                            ultima_revision_por = :usr_rev
                      WHERE id_aviso = :id_aviso'
                );
                $stmtUpd->execute([
                    ':usr_cierre' => $idUsuario,
                    ':usr_rev' => $idUsuario,
                    ':id_aviso' => $idAviso,
                ]);
            } else {
                $stmtUpd = $pdo->prepare(
                    'UPDATE aviso_vencimiento
                        SET ultima_revision_en = NOW(),
                            ultima_revision_por = :usr_rev
                      WHERE id_aviso = :id_aviso'
                );
                $stmtUpd->execute([
                    ':usr_rev' => $idUsuario,
                    ':id_aviso' => $idAviso,
                ]);
            }

            $rango = $this->obtenerRangoFechasVencimiento(15);
            $activosRestantes = $this->contarAvisosActivos($rango['limite']);

            return [
                'id_registro' => $idRevision,
                'http_code' => 201,
                'response' => [
                    'status' => true,
                    'code' => 'REVIEW_REGISTERED',
                    'idempotent_replay' => false,
                    'message' => $estadoResultante === 'CERRADO'
                        ? 'Revisión registrada. El aviso fue cerrado.'
                        : 'Revisión registrada. El aviso permanece activo.',
                    'data' => [
                        'id_revision' => $idRevision,
                        'id_aviso' => $idAviso,
                        'respuesta' => $respuestaCanon === 'NO_QUEDAN' ? 'no' : 'si',
                        'estado' => strtolower($estadoResultante),
                        'fecha_vencimiento_revisada' => $fechaVigente,
                        'avisos_activos' => $activosRestantes,
                        'referencia_solicitud' => $refValidada,
                    ],
                ],
            ];
        };

        $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idUsuario,
            DefinicionMovimiento::SOLICITUD_REVISION_AVISO,
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

    private function esFechaIsoValida(string $fecha): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }
        [$a, $m, $d] = array_map('intval', explode('-', $fecha));
        return checkdate($m, $d, $a);
    }
}
