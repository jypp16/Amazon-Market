<?php

namespace Services;

use DateTimeImmutable;
use DateTimeZone;
use Libraries\Core\Conexion;
use Libraries\Core\InventarioException;
use Models\CajaModel;
use PDO;

class CajaService
{
    private const LIMA_TZ = 'America/Lima';
    private const MAX_AMOUNT = '99999999.99';
    private const MAX_CENTIMOS = 9999999999;

    private const MAP_API_A_BD_TIPO = [
        'manual_income'      => 'INGRESO_MANUAL',
        'admin_injection'    => 'INYECCION_ADMIN',
        'cash_out'           => 'SALIDA_CAJA',
        'admin_withdrawal'   => 'RETIRO_ADMIN',
        'omitted_withdrawal' => 'RETIRO_OMITIDO',
    ];

    private const MAP_BD_A_API_TIPO = [
        'VENTA_EFECTIVO'  => 'cash_sale',
        'INGRESO_MANUAL'  => 'manual_income',
        'INYECCION_ADMIN' => 'admin_injection',
        'SALIDA_CAJA'     => 'cash_out',
        'RETIRO_ADMIN'    => 'admin_withdrawal',
        'RETIRO_OMITIDO'  => 'omitted_withdrawal',
        'CORRECCION'      => 'correction',
    ];

    private const MAP_ETIQUETAS_TIPO = [
        'cash_sale'          => 'Venta efectivo',
        'manual_income'      => 'Ingreso manual',
        'admin_injection'    => 'Inyección',
        'cash_out'           => 'Salida',
        'admin_withdrawal'   => 'Retiro',
        'omitted_withdrawal' => 'Retiro omitido',
        'correction'         => 'Corrección',
    ];

    private PDO $pdo;
    private CajaModel $cajaModel;
    private ProgramacionService $programacionService;
    private ControlSolicitudService $controlSolicitudService;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Conexion::getInstance()->conect();
        $this->cajaModel = new CajaModel($this->pdo);
        $this->programacionService = new ProgramacionService($this->pdo);
        $this->controlSolicitudService = new ControlSolicitudService($this->pdo);
    }

    /**
     * EP-07: GET /api/caja/turno-hoy
     */
    public function consultarTurnoHoy(int $idVendedorAutenticado, array $queryParams): array
    {
        $this->validarQuerySoloVendedorPropio($queryParams, $idVendedorAutenticado);

        $ahora = $this->ahoraLima();
        $serverDate = $ahora->format('Y-m-d');
        $serverTime = $ahora->format('H:i');

        $scheduledShifts = $this->programacionService->obtenerTurnosProgramadosEnFecha($idVendedorAutenticado, $ahora);
        $catalogoActivo = $this->cajaModel->listarTurnosActivosCatalogo();

        $activeIds = array_map(static fn (array $t): int => (int)$t['id'], $catalogoActivo);
        $scheduledShifts = array_values(array_filter(
            $scheduledShifts,
            static fn (array $s): bool => in_array((int)$s['id'], $activeIds, true)
        ));

        $scheduledIds = array_map(static fn (array $s): int => (int)$s['id'], $scheduledShifts);

        $availableShifts = [];
        foreach ($catalogoActivo as $turno) {
            $id = (int)$turno['id'];
            $availableShifts[] = [
                'id'                      => $id,
                'name'                    => (string)$turno['nombre'],
                'label'                   => $turno['nombre'] . ' · ' . $turno['inicio'] . '–' . $turno['fin'],
                'start'                   => (string)$turno['inicio'],
                'end'                     => (string)$turno['fin'],
                'is_scheduled_for_seller' => in_array($id, $scheduledIds, true),
            ];
        }

        $hasShiftToday = !empty($scheduledShifts);
        $status = 'no_shift';
        $recommendedShift = null;
        $recommendedShiftId = null;

        if ($hasShiftToday) {
            foreach ($scheduledShifts as $shift) {
                if ($serverTime >= $shift['start'] && $serverTime <= $shift['end']) {
                    $status = 'within_hours';
                    $recommendedShift = $shift;
                    $recommendedShiftId = (int)$shift['id'];
                    break;
                }
            }

            if ($recommendedShift === null) {
                $status = 'outside_hours';
                foreach ($scheduledShifts as $shift) {
                    if ($shift['start'] > $serverTime) {
                        $recommendedShift = $shift;
                        $recommendedShiftId = (int)$shift['id'];
                        break;
                    }
                }
                if ($recommendedShift === null) {
                    $recommendedShift = $scheduledShifts[0];
                    $recommendedShiftId = (int)$recommendedShift['id'];
                }
            }
        }

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Información de turnos para apertura obtenida.',
            'data'    => [
                'vendedor_id'                    => $idVendedorAutenticado,
                'server_date'                    => $serverDate,
                'server_time'                    => $serverTime,
                'status'                         => $status,
                'has_shift_today'                => $hasShiftToday,
                'shift'                          => $recommendedShift,
                'scheduled_shifts'               => $scheduledShifts,
                'available_shifts'               => $availableShifts,
                'recommended_shift_id'           => $recommendedShiftId,
                'requires_authorization_to_open' => false,
            ],
        ];
    }

    /**
     * EP-08: GET /api/caja/estado
     */
    public function consultarEstadoCaja(int $idVendedorAutenticado, array $queryParams): array
    {
        $this->validarQuerySoloVendedorPropio($queryParams, $idVendedorAutenticado);

        $sesionAbierta = $this->cajaModel->obtenerSesionAbiertaActual(false);
        $ultimoCierre = $this->cajaModel->obtenerUltimoCierreConfirmado(false);
        $hasPreviousClose = ($ultimoCierre !== null);

        if ($sesionAbierta === null) {
            if (!$hasPreviousClose) {
                return [
                    'status'  => true,
                    'code'    => 'OK',
                    'message' => 'Estado de caja obtenido (primera apertura sin referencia previa).',
                    'data'    => [
                        'is_open'            => false,
                        'is_owner'           => false,
                        'session_id'         => null,
                        'has_previous_close' => false,
                        'reference_amount'   => null,
                        'reference_date'     => null,
                        'reference_turn'     => null,
                        'counted_amount'     => null,
                        'occupied_by'        => null,
                    ],
                ];
            }

            return [
                'status'  => true,
                'code'    => 'OK',
                'message' => 'Estado de caja obtenido.',
                'data'    => [
                    'is_open'            => false,
                    'is_owner'           => false,
                    'session_id'         => null,
                    'has_previous_close' => true,
                    'reference_amount'   => round((float)$ultimoCierre['efectivo_contado_cierre'], 2),
                    'reference_date'     => $this->formatearIsoLima((string)$ultimoCierre['fecha_hora_cierre']),
                    'reference_turn'     => (string)$ultimoCierre['nombre_turno_referencia'],
                    'counted_amount'     => null,
                    'occupied_by'        => null,
                ],
            ];
        }

        $isOwner = ((int)$sesionAbierta['id_usuario_vendedor'] === $idVendedorAutenticado);
        if (!$isOwner) {
            return [
                'status'  => true,
                'code'    => 'OK',
                'message' => 'La caja física tiene una sesión abierta por otro vendedor.',
                'data'    => [
                    'is_open'            => true,
                    'is_owner'           => false,
                    'session_id'         => (int)$sesionAbierta['id_sesion_caja'],
                    'has_previous_close' => $hasPreviousClose,
                    'reference_amount'   => null,
                    'reference_date'     => null,
                    'reference_turn'     => null,
                    'counted_amount'     => null,
                    'occupied_by'        => [
                        'seller_name' => (string)$sesionAbierta['seller_name'],
                        'shift_name'  => (string)$sesionAbierta['nombre_turno_referencia'],
                        'opened_at'   => $this->formatearIsoLima((string)$sesionAbierta['fecha_hora_apertura']),
                    ],
                ],
            ];
        }

        $tieneCierreEnSesion = ((int)$sesionAbierta['tiene_cierre_anterior'] === 1);
        $shiftName = (string)$sesionAbierta['nombre_turno_referencia'];
        $shiftStart = (string)$sesionAbierta['hora_inicio_referencia'];
        $shiftEnd = (string)$sesionAbierta['hora_fin_referencia'];

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Estado de caja obtenido.',
            'data'    => [
                'is_open'            => true,
                'is_owner'           => true,
                'session_id'         => (int)$sesionAbierta['id_sesion_caja'],
                'has_previous_close' => $tieneCierreEnSesion,
                'reference_amount'   => $tieneCierreEnSesion ? round((float)$sesionAbierta['monto_referencia_cierre_anterior'], 2) : null,
                'reference_date'     => $tieneCierreEnSesion ? $this->formatearIsoLima((string)$sesionAbierta['fecha_hora_cierre_anterior_ref']) : null,
                'reference_turn'     => $tieneCierreEnSesion ? (string)$sesionAbierta['nombre_turno_cierre_anterior_ref'] : null,
                'counted_amount'     => round((float)$sesionAbierta['saldo_inicial'], 2),
                'occupied_by'        => null,
                'shift'              => [
                    'id'    => (int)$sesionAbierta['id_turno'],
                    'name'  => $shiftName,
                    'start' => $shiftStart,
                    'end'   => $shiftEnd,
                    'label' => $shiftName . ' · ' . $shiftStart . '–' . $shiftEnd,
                ],
                'opened_at'          => $this->formatearIsoLima((string)$sesionAbierta['fecha_hora_apertura']),
                'expected_end_at'    => $this->formatearIsoLima((string)$sesionAbierta['fecha_hora_salida_referencia']),
            ],
        ];
    }

    /**
     * EP-09: POST /api/caja/abrir
     */
    public function abrirCaja(
        array $input,
        ?string $headerReferencia,
        array $vendedorAutenticado,
        ?string $rawCashCountedLiteral = null,
        bool $cashCountedDuplicated = false
    ): array {
        $this->validarCamposPermitidos($input, [
            'referencia_solicitud',
            'request_id',
            'shift_id',
            'turno_id',
            'cash_counted',
        ]);

        if ($cashCountedDuplicated) {
            throw new InventarioException(
                'El campo cash_counted no puede repetirse en la solicitud.',
                'VALIDATION_ERROR',
                422,
                ['cash_counted' => ['El campo cash_counted no puede aparecer más de una vez en el objeto raíz.']]
            );
        }

        $referencia = $this->resolverReferenciaCuerpoOCabecera($input, $headerReferencia);
        $rawShiftId = $this->resolverAliasCuerpo($input, 'shift_id', 'turno_id');
        $shiftId = $this->validarShiftIdEntrada($rawShiftId);
        $importeValidado = self::validarImporteApertura(
            $input['cash_counted'] ?? null,
            array_key_exists('cash_counted', $input),
            $rawCashCountedLiteral
        );
        $cashCountedStr = $importeValidado['normalizado'];
        $cashCentimos = $importeValidado['centimos'];
        $cashCountedFloat = $importeValidado['float'];

        $idVendedor = (int)$vendedorAutenticado['id_usuario'];
        $sellerName = (string)($vendedorAutenticado['nombre'] ?? '');

        $payloadCanonico = [
            'vendedor_id'  => $idVendedor,
            'shift_id'     => $shiftId,
            'cash_counted' => $cashCountedStr,
        ];

        $ejecucion = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idVendedor,
            'CAJA_ABRIR',
            $referencia,
            $payloadCanonico,
            function (PDO $pdo, int $idSolicitud) use ($idVendedor, $sellerName, $shiftId, $cashCountedStr, $cashCentimos, $cashCountedFloat): array {
                $cajaModel = new CajaModel($pdo);
                $programacionService = new ProgramacionService($pdo);

                if (!$cajaModel->adquirirLockCajaFisica(5)) {
                    throw new InventarioException(
                        'La caja física está procesando otra operación concurrente. Intente nuevamente.',
                        'CONCURRENT_OPERATION_CONFLICT',
                        409
                    );
                }

                try {
                    $guardia = $cajaModel->bloquearGuardiaCajaFisica();
                    if ($guardia === null) {
                        throw new InventarioException(
                            'No se encontró la fila de control de caja física única.',
                            'INTERNAL_ERROR',
                            500
                        );
                    }

                    $turno = $cajaModel->obtenerTurnoPorId($shiftId, true);
                    if ($turno === null || (int)$turno['estado'] !== 1) {
                        throw new InventarioException(
                            'El turno seleccionado no existe o está inactivo.',
                            'INVALID_SHIFT',
                            422,
                            ['shift_id' => ['El turno debe existir y encontrarse activo.']]
                        );
                    }

                    $sesionAbierta = $cajaModel->obtenerSesionAbiertaActual(true);
                    if ($sesionAbierta !== null) {
                        throw new InventarioException(
                            'Ya existe una sesión de caja abierta en la única caja física.',
                            'CASH_ALREADY_OPEN',
                            409,
                            [],
                            [
                                'occupied_by' => [
                                    'seller_name' => (string)$sesionAbierta['seller_name'],
                                    'shift_name'  => (string)$sesionAbierta['nombre_turno_referencia'],
                                    'opened_at'   => $this->formatearIsoLima((string)$sesionAbierta['fecha_hora_apertura']),
                                ],
                            ]
                        );
                    }

                    $ultimoCierre = $cajaModel->obtenerUltimoCierreConfirmado(true);
                    $ahora = $this->ahoraLima();
                    $fechaLocal = $ahora->format('Y-m-d');
                    $horaActual = $ahora->format('H:i');
                    $fechaHoraApertura = $ahora->format('Y-m-d H:i:s');

                    $turnoNombre = (string)$turno['nombre'];
                    $turnoInicio = (string)$turno['inicio'];
                    $turnoFin = (string)$turno['fin'];
                    $fechaHoraSalidaRef = $fechaLocal . ' ' . $turnoFin . ':00';

                    $scheduledShifts = $programacionService->obtenerTurnosProgramadosEnFecha($idVendedor, $ahora);
                    $scheduledIds = array_map(static fn (array $s): int => (int)$s['id'], $scheduledShifts);
                    $esSuplencia = !in_array($shiftId, $scheduledIds, true);
                    $fueraDeHorario = $esSuplencia || ($horaActual < $turnoInicio || $horaActual > $turnoFin);

                    $hasPreviousClose = ($ultimoCierre !== null);

                    if (!$hasPreviousClose) {
                        $tieneCierreAnterior = 0;
                        $idCierreAnterior = null;
                        $refAmountDb = null;
                        $refDateDb = null;
                        $refTurnDb = null;
                        $diffDb = null;
                        $refAmountResp = null;
                        $refDateResp = null;
                        $refTurnResp = null;
                        $diffResp = null;
                    } else {
                        $refCentimos = self::parseDecimalACentimos((string)$ultimoCierre['efectivo_contado_cierre']);
                        $diffCentimos = $cashCentimos - $refCentimos;

                        $tieneCierreAnterior = 1;
                        $idCierreAnterior = (int)$ultimoCierre['id_sesion_caja'];
                        $refAmountDb = self::centimosADecimal($refCentimos);
                        $refDateDb = (string)$ultimoCierre['fecha_hora_cierre'];
                        $refTurnDb = (string)$ultimoCierre['nombre_turno_referencia'];
                        $diffDb = self::centimosADecimal($diffCentimos);

                        $refAmountResp = (float)$refAmountDb;
                        $refDateResp = $this->formatearIsoLima($refDateDb);
                        $refTurnResp = $refTurnDb;
                        $diffResp = (float)$diffDb;
                    }

                    $idSesion = $cajaModel->insertarSesionApertura([
                        'id_usuario_vendedor'              => $idVendedor,
                        'id_turno'                         => $shiftId,
                        'nombre_turno_referencia'          => $turnoNombre,
                        'hora_inicio_referencia'           => $turnoInicio . ':00',
                        'hora_fin_referencia'              => $turnoFin . ':00',
                        'fecha_hora_salida_referencia'     => $fechaHoraSalidaRef,
                        'es_suplencia'                     => $esSuplencia ? 1 : 0,
                        'abierta_fuera_horario'            => $fueraDeHorario ? 1 : 0,
                        'fecha_hora_apertura'              => $fechaHoraApertura,
                        'tiene_cierre_anterior'            => $tieneCierreAnterior,
                        'id_sesion_cierre_anterior'        => $idCierreAnterior,
                        'monto_referencia_cierre_anterior' => $refAmountDb,
                        'fecha_hora_cierre_anterior_ref'   => $refDateDb,
                        'nombre_turno_cierre_anterior_ref' => $refTurnDb,
                        'saldo_inicial'                    => $cashCountedStr,
                        'diferencia_apertura'              => $diffDb,
                        'efectivo_esperado_actual'         => $cashCountedStr,
                        'id_solicitud_apertura'            => $idSolicitud > 0 ? $idSolicitud : null,
                    ]);

                    $response = [
                        'status'            => true,
                        'code'              => 'OPENED',
                        'idempotent_replay' => false,
                        'message'           => 'Sesión de caja abierta correctamente.',
                        'data'              => [
                            'result_code'             => 'OPENED',
                            'session_id'              => $idSesion,
                            'seller_id'               => $idVendedor,
                            'seller_name'             => $sellerName,
                            'shift'                   => [
                                'id'    => $shiftId,
                                'name'  => $turnoNombre,
                                'start' => $turnoInicio,
                                'end'   => $turnoFin,
                                'label' => $turnoNombre . ' · ' . $turnoInicio . '–' . $turnoFin,
                            ],
                            'is_substitution'         => $esSuplencia,
                            'opened_outside_schedule' => $fueraDeHorario,
                            'has_previous_close'      => $hasPreviousClose,
                            'reference_amount'        => $refAmountResp,
                            'reference_date'          => $refDateResp,
                            'reference_turn'          => $refTurnResp,
                            'opening_balance'         => $cashCountedFloat,
                            'opening_difference'      => $diffResp,
                            'opened_at'               => $this->formatearIsoLima($fechaHoraApertura),
                            'version'                 => 1,
                            'status'                  => 'Abierta',
                        ],
                    ];

                    return [
                        'id_registro' => $idSesion,
                        'http_code'   => 201,
                        'response'    => $response,
                    ];
                } finally {
                    $cajaModel->liberarLockCajaFisica();
                }
            }
        );

        return [
            'http_code' => (int)$ejecucion['http_code'],
            'response'  => $ejecucion['response'],
        ];
    }

    /**
     * EP-10: GET /api/caja/sesion-actual
     */
    public function consultarSesionActual(int $idVendedorAutenticado, array $queryParams): array
    {
        $this->validarQuerySoloVendedorPropio($queryParams, $idVendedorAutenticado);

        $sesionAbierta = $this->cajaModel->obtenerSesionAbiertaActual(false);
        if ($sesionAbierta === null || (int)$sesionAbierta['id_usuario_vendedor'] !== $idVendedorAutenticado) {
            return [
                'status'  => true,
                'code'    => 'OK',
                'message' => 'No existe una sesión de caja abierta para el vendedor actual.',
                'data'    => [
                    'is_open'         => false,
                    'is_owner'        => false,
                    'session_id'      => null,
                    'seller_id'       => null,
                    'seller'          => null,
                    'turn'            => null,
                    'shift_label'     => null,
                    'opening_balance' => null,
                    'cash_sales'      => null,
                    'income'          => null,
                    'expenses'        => null,
                    'current_balance' => null,
                    'balance'         => null,
                    'version'         => null,
                ],
            ];
        }

        $turn = (string)$sesionAbierta['nombre_turno_referencia'];
        $start = (string)$sesionAbierta['hora_inicio_referencia'];
        $end = (string)$sesionAbierta['hora_fin_referencia'];
        $currentBalance = round((float)$sesionAbierta['efectivo_esperado_actual'], 2);

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Estado de la sesión activa obtenido correctamente.',
            'data'    => [
                'is_open'         => true,
                'is_owner'        => true,
                'session_id'      => (int)$sesionAbierta['id_sesion_caja'],
                'seller_id'       => (int)$sesionAbierta['id_usuario_vendedor'],
                'seller'          => (string)$sesionAbierta['seller_name'],
                'turn'            => $turn,
                'shift_label'     => $turn . ' · ' . $start . '–' . $end,
                'opening_balance' => round((float)$sesionAbierta['saldo_inicial'], 2),
                'cash_sales'      => round((float)$sesionAbierta['total_ventas_efectivo'], 2),
                'income'          => round((float)$sesionAbierta['total_ingresos'], 2),
                'expenses'        => round((float)$sesionAbierta['total_egresos'], 2),
                'current_balance' => $currentBalance,
                'balance'         => $currentBalance,
                'version'         => (int)$sesionAbierta['version_caja'],
            ],
        ];
    }

    /**
     * EP-11: GET /api/caja/administradores
     */
    public function listarAdministradoresActivos(int $idVendedorAutenticado, array $queryParams): array
    {
        $this->validarCamposPermitidos($queryParams, ['url']);
        $this->exigirSesionPropiaAbierta($this->cajaModel, $idVendedorAutenticado, false);

        $admins = $this->cajaModel->listarAdministradoresActivos();

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Administradores activos obtenidos correctamente.',
            'data'    => $admins,
        ];
    }

    /**
     * EP-12: GET /api/caja/turnos-anteriores
     */
    public function listarTurnosAnteriores(int $idVendedorAutenticado, array $queryParams): array
    {
        $this->validarCamposPermitidos($queryParams, ['url', 'estado', 'page_size', 'limit']);

        if (array_key_exists('estado', $queryParams) && $queryParams['estado'] !== '') {
            $estadoParam = is_string($queryParams['estado']) ? trim($queryParams['estado']) : '';
            if ($estadoParam !== 'cerrado') {
                throw new InventarioException(
                    'El parámetro estado solo admite el valor "cerrado".',
                    'VALIDATION_ERROR',
                    422,
                    ['estado' => ['Solo se admite el valor "cerrado".']]
                );
            }
        }

        $rawLimit = $this->resolverAliasCuerpo($queryParams, 'page_size', 'limit');
        $limit = 10;
        if ($rawLimit !== null) {
            if (is_array($rawLimit) || !preg_match('/^\d+$/', trim((string)$rawLimit))) {
                throw new InventarioException(
                    'El parámetro page_size es inválido.',
                    'VALIDATION_ERROR',
                    422,
                    ['page_size' => ['Debe ser un entero entre 1 y 20.']]
                );
            }
            $limit = (int)trim((string)$rawLimit);
            if ($limit < 1 || $limit > 20) {
                throw new InventarioException(
                    'El parámetro page_size debe estar entre 1 y 20.',
                    'VALIDATION_ERROR',
                    422,
                    ['page_size' => ['El rango permitido es de 1 a 20 registros.']]
                );
            }
        }

        $sesionPropia = $this->exigirSesionPropiaAbierta($this->cajaModel, $idVendedorAutenticado, false);
        $rows = $this->cajaModel->listarSesionesCerradasAnterioresElegibles(
            (string)$sesionPropia['fecha_hora_apertura'],
            (int)$sesionPropia['id_sesion_caja'],
            $limit
        );

        $data = [];
        foreach ($rows as $row) {
            $id = (int)$row['id_sesion_caja'];
            $turn = (string)$row['nombre_turno_referencia'];
            $closedAtIso = $this->formatearIsoLima((string)$row['fecha_hora_cierre']);
            $date = (new DateTimeImmutable((string)$row['fecha_hora_cierre'], new DateTimeZone(self::LIMA_TZ)))->format('Y-m-d');
            $expectedCent = self::parseDecimalACentimos((string)$row['efectivo_esperado_cierre']);
            $countedCent = self::parseDecimalACentimos((string)$row['efectivo_contado_cierre']);
            $shortageCent = max($expectedCent - $countedCent, 0);

            $data[] = [
                'id'        => $id,
                'date'      => $date,
                'turn'      => $turn,
                'closed_at' => $closedAtIso,
                'label'     => "Sesión #{$id} · {$date} · Turno {$turn} (cerrado)",
                'expected'  => (float)self::centimosADecimal($expectedCent),
                'counted'   => (float)self::centimosADecimal($countedCent),
                'shortage'  => (float)self::centimosADecimal($shortageCent),
            ];
        }

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => empty($data)
                ? 'No existen turnos cerrados anteriores registrados.'
                : 'Turnos cerrados anteriores obtenidos correctamente.',
            'data'    => $data,
        ];
    }

    /**
     * EP-13: POST /api/caja/ingresos
     */
    public function registrarIngreso(
        array $input,
        ?string $headerReferencia,
        array $vendedorAutenticado,
        ?string $rawAmountLiteral = null,
        bool $amountDuplicated = false
    ): array {
        $this->validarCamposPermitidos($input, [
            'referencia_solicitud',
            'request_id',
            'type',
            'amount',
            'reason',
            'administrator_id',
        ]);

        if ($amountDuplicated) {
            throw new InventarioException(
                'El campo amount no puede repetirse en la solicitud.',
                'VALIDATION_ERROR',
                422,
                ['amount' => ['El campo amount no puede aparecer más de una vez en el objeto raíz.']]
            );
        }

        $referencia = $this->resolverReferenciaCuerpoOCabecera($input, $headerReferencia);
        $rawType = $input['type'] ?? null;
        if (!is_string($rawType)) {
            throw new InventarioException(
                'El tipo de ingreso es inválido.',
                'INVALID_MOVEMENT_TYPE',
                422,
                ['type' => ['Debe ser manual_income (o manual) o admin_injection.']]
            );
        }
        $typeTrim = trim($rawType);
        if ($typeTrim === 'manual') {
            $typeTrim = 'manual_income';
        }
        if (!in_array($typeTrim, ['manual_income', 'admin_injection'], true)) {
            throw new InventarioException(
                'El tipo de ingreso no es válido.',
                'INVALID_MOVEMENT_TYPE',
                422,
                ['type' => ['Solo se admite manual_income (o manual) o admin_injection.']]
            );
        }

        $importeValidado = self::validarImporteMonetario(
            $input['amount'] ?? null,
            array_key_exists('amount', $input),
            'amount',
            false,
            $rawAmountLiteral
        );
        $reason = $this->validarMotivoObligatorio($input['reason'] ?? null, array_key_exists('reason', $input));

        $administratorId = null;
        if ($typeTrim === 'manual_income') {
            if (array_key_exists('administrator_id', $input) && $input['administrator_id'] !== null) {
                throw new InventarioException(
                    'El campo administrator_id no aplica para ingresos manuales.',
                    'VALIDATION_ERROR',
                    422,
                    ['administrator_id' => ['Debe ser null o omitirse cuando type es manual_income.']]
                );
            }
        } else {
            $administratorId = $this->validarEnteroPositivoRequerido(
                $input['administrator_id'] ?? null,
                array_key_exists('administrator_id', $input),
                'administrator_id',
                'ADMINISTRATOR_REQUIRED',
                'Debe indicar un administrador activo válido.'
            );
        }

        $idVendedor = (int)$vendedorAutenticado['id_usuario'];
        $sellerName = (string)($vendedorAutenticado['nombre'] ?? '');

        $payloadCanonico = [
            'vendedor_id'      => $idVendedor,
            'type'             => $typeTrim,
            'amount'           => $importeValidado['normalizado'],
            'reason'           => $reason,
            'administrator_id' => $administratorId,
        ];

        $ejecucion = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idVendedor,
            'CAJA_INGRESO',
            $referencia,
            $payloadCanonico,
            function (PDO $pdo, int $idSolicitud) use (
                $idVendedor,
                $sellerName,
                $typeTrim,
                $importeValidado,
                $reason,
                $administratorId
            ): array {
                $cajaModel = new CajaModel($pdo);
                $sesion = $this->exigirSesionPropiaAbierta($cajaModel, $idVendedor, true);
                $idSesion = (int)$sesion['id_sesion_caja'];

                $adminName = null;
                if ($typeTrim === 'admin_injection') {
                    $admin = $cajaModel->obtenerAdministradorActivoPorId((int)$administratorId, false);
                    if ($admin === null) {
                        throw new InventarioException(
                            'El administrador especificado no existe o no se encuentra activo.',
                            'ADMINISTRATOR_REQUIRED',
                            422,
                            ['administrator_id' => ['Debe corresponder a un administrador activo.']]
                        );
                    }
                    $adminName = (string)$admin['nombre'];
                }

                $amountCent = $importeValidado['centimos'];
                $totalIngresosCent = self::parseDecimalACentimos((string)$sesion['total_ingresos']) + $amountCent;
                $totalEgresosStr = (string)$sesion['total_egresos'];
                $totalOmitidosStr = (string)$sesion['total_retiros_omitidos_declarados'];
                $nuevoEsperadoCent = self::parseDecimalACentimos((string)$sesion['efectivo_esperado_actual']) + $amountCent;

                if ($totalIngresosCent > self::MAX_CENTIMOS || $nuevoEsperadoCent > self::MAX_CENTIMOS) {
                    throw new InventarioException(
                        'El ingreso supera la capacidad técnica permitida para los acumulados de la sesión.',
                        'BALANCE_OVERFLOW',
                        409
                    );
                }

                $ahora = $this->ahoraLima();
                $fechaHoraDb = $ahora->format('Y-m-d H:i:s');
                $referenciaVisible = $typeTrim === 'admin_injection'
                    ? $reason . ' · Entregó: ' . $adminName
                    : $reason;

                $idMovimiento = $cajaModel->insertarMovimientoCaja([
                    'id_sesion_caja'                             => $idSesion,
                    'tipo_movimiento'                            => self::MAP_API_A_BD_TIPO[$typeTrim],
                    'monto'                                      => $importeValidado['normalizado'],
                    'efecto_saldo'                               => $importeValidado['normalizado'],
                    'motivo'                                     => $reason,
                    'referencia_visible'                         => $referenciaVisible,
                    'id_usuario_registro'                        => $idVendedor,
                    'id_usuario_admin'                           => $administratorId,
                    'id_venta'                                   => null,
                    'id_sesion_origen_omitido'                   => null,
                    'declarado_ocurrio_en_sesion_origen_abierta' => null,
                    'confirmado_reflejado_en_apertura'           => null,
                    'id_movimiento_original'                     => null,
                    'id_solicitud_idempotencia'                  => $idSolicitud > 0 ? $idSolicitud : null,
                    'fecha_hora_registro'                        => $fechaHoraDb,
                ]);

                $cajaModel->actualizarAcumuladosYVersionSesion(
                    $idSesion,
                    self::centimosADecimal($totalIngresosCent),
                    $totalEgresosStr,
                    $totalOmitidosStr,
                    self::centimosADecimal($nuevoEsperadoCent)
                );
                $cajaModel->desactualizarArqueoVigenteSesion($idSesion, $fechaHoraDb);

                $nuevaVersion = (int)$sesion['version_caja'] + 1;
                $nuevoSaldoFloat = (float)self::centimosADecimal($nuevoEsperadoCent);

                $response = [
                    'status'            => true,
                    'code'              => 'MOVEMENT_REGISTERED',
                    'idempotent_replay' => false,
                    'message'           => 'Ingreso registrado correctamente.',
                    'data'              => [
                        'result_code' => 'MOVEMENT_REGISTERED',
                        'movement'    => [
                            'id'                 => $idMovimiento,
                            'session_id'         => $idSesion,
                            'type'               => $typeTrim,
                            'label'              => self::MAP_ETIQUETAS_TIPO[$typeTrim],
                            'amount'             => $importeValidado['float'],
                            'current_amount'     => $importeValidado['float'],
                            'impact'             => $importeValidado['float'],
                            'effect'             => 'Suma',
                            'reason'             => $reason,
                            'reference'          => $referenciaVisible,
                            'administrator_id'   => $administratorId,
                            'administrator_name' => $adminName,
                            'responsible'        => $sellerName,
                            'recorded_at'        => $this->formatearIsoLima($fechaHoraDb),
                            'status'             => 'Confirmado',
                        ],
                        'balance'         => $nuevoSaldoFloat,
                        'current_balance' => $nuevoSaldoFloat,
                        'version'         => $nuevaVersion,
                    ],
                ];

                return [
                    'id_registro' => $idMovimiento,
                    'http_code'   => 201,
                    'response'    => $response,
                ];
            }
        );

        return [
            'http_code' => (int)$ejecucion['http_code'],
            'response'  => $ejecucion['response'],
        ];
    }

    /**
     * EP-14: POST /api/caja/egresos
     */
    public function registrarEgreso(
        array $input,
        ?string $headerReferencia,
        array $vendedorAutenticado,
        ?string $rawAmountLiteral = null,
        bool $amountDuplicated = false
    ): array {
        $this->validarCamposPermitidos($input, [
            'referencia_solicitud',
            'request_id',
            'type',
            'amount',
            'reason',
            'administrator_id',
            'origin_session_id',
            'origin_turn_id',
            'declared_occurred_in_open_origin_session',
            'confirmed_reflected_in_opening',
            'confirm_previous_open',
        ]);

        if ($amountDuplicated) {
            throw new InventarioException(
                'El campo amount no puede repetirse en la solicitud.',
                'VALIDATION_ERROR',
                422,
                ['amount' => ['El campo amount no puede aparecer más de una vez en el objeto raíz.']]
            );
        }

        $referencia = $this->resolverReferenciaCuerpoOCabecera($input, $headerReferencia);
        $rawType = $input['type'] ?? null;
        if (!is_string($rawType) || !in_array(trim($rawType), ['cash_out', 'admin_withdrawal', 'omitted_withdrawal'], true)) {
            throw new InventarioException(
                'El tipo de egreso no es válido.',
                'INVALID_MOVEMENT_TYPE',
                422,
                ['type' => ['Solo se admite cash_out, admin_withdrawal u omitted_withdrawal.']]
            );
        }
        $typeTrim = trim($rawType);

        $importeValidado = self::validarImporteMonetario(
            $input['amount'] ?? null,
            array_key_exists('amount', $input),
            'amount',
            false,
            $rawAmountLiteral
        );
        $reason = $this->validarMotivoObligatorio($input['reason'] ?? null, array_key_exists('reason', $input));

        $rawOriginId = $this->resolverAliasCuerpo($input, 'origin_session_id', 'origin_turn_id');
        $rawConfirmedReflected = $this->resolverAliasBooleano($input, 'confirmed_reflected_in_opening', 'confirm_previous_open');
        $rawDeclaredOpen = $input['declared_occurred_in_open_origin_session'] ?? null;

        $administratorId = null;
        $originSessionId = null;
        $declaredOpen = null;
        $confirmedReflected = null;

        if ($typeTrim === 'cash_out') {
            if (array_key_exists('administrator_id', $input) && $input['administrator_id'] !== null) {
                throw new InventarioException(
                    'El campo administrator_id no aplica para salidas de caja.',
                    'VALIDATION_ERROR',
                    422,
                    ['administrator_id' => ['Debe ser null o omitirse cuando type es cash_out.']]
                );
            }
            if ($rawOriginId !== null || $rawDeclaredOpen !== null || $rawConfirmedReflected !== null) {
                throw new InventarioException(
                    'Los campos de retiro omitido no aplican para salidas de caja.',
                    'VALIDATION_ERROR',
                    422,
                    ['type' => ['origin_session_id y declaraciones solo aplican a omitted_withdrawal.']]
                );
            }
        } elseif ($typeTrim === 'admin_withdrawal') {
            $administratorId = $this->validarEnteroPositivoRequerido(
                $input['administrator_id'] ?? null,
                array_key_exists('administrator_id', $input),
                'administrator_id',
                'ADMINISTRATOR_REQUIRED',
                'Debe indicar un administrador activo válido.'
            );
            if ($rawOriginId !== null || $rawDeclaredOpen !== null || $rawConfirmedReflected !== null) {
                throw new InventarioException(
                    'Los campos de retiro omitido no aplican para retiros de administrador.',
                    'VALIDATION_ERROR',
                    422,
                    ['type' => ['origin_session_id y declaraciones solo aplican a omitted_withdrawal.']]
                );
            }
        } else {
            // omitted_withdrawal
            $administratorId = $this->validarEnteroPositivoRequerido(
                $input['administrator_id'] ?? null,
                array_key_exists('administrator_id', $input),
                'administrator_id',
                'ADMINISTRATOR_REQUIRED',
                'Debe indicar el administrador activo que recibió el efectivo.'
            );
            $originSessionId = $this->validarEnteroPositivoRequerido(
                $rawOriginId,
                $rawOriginId !== null,
                'origin_session_id',
                'ORIGIN_TURN_REQUIRED',
                'Debe seleccionar la sesión cerrada de origen del retiro omitido.'
            );

            if ($rawDeclaredOpen !== true || $rawConfirmedReflected !== true) {
                throw new InventarioException(
                    'Se requieren ambas declaraciones booleanas verdaderas para registrar un retiro omitido.',
                    'CONFIRMATION_REQUIRED',
                    422,
                    [
                        'declared_occurred_in_open_origin_session' => ['Debe enviarse como booleano true.'],
                        'confirmed_reflected_in_opening'           => ['Debe enviarse como booleano true.'],
                    ]
                );
            }
            $declaredOpen = true;
            $confirmedReflected = true;
        }

        $idVendedor = (int)$vendedorAutenticado['id_usuario'];
        $sellerName = (string)($vendedorAutenticado['nombre'] ?? '');

        $payloadCanonico = [
            'vendedor_id'                              => $idVendedor,
            'type'                                     => $typeTrim,
            'amount'                                   => $importeValidado['normalizado'],
            'reason'                                   => $reason,
            'administrator_id'                         => $administratorId,
            'origin_session_id'                        => $originSessionId,
            'declared_occurred_in_open_origin_session' => $declaredOpen,
            'confirmed_reflected_in_opening'           => $confirmedReflected,
        ];

        $ejecucion = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idVendedor,
            'CAJA_EGRESO',
            $referencia,
            $payloadCanonico,
            function (PDO $pdo, int $idSolicitud) use (
                $idVendedor,
                $sellerName,
                $typeTrim,
                $importeValidado,
                $reason,
                $administratorId,
                $originSessionId,
                $declaredOpen,
                $confirmedReflected
            ): array {
                $cajaModel = new CajaModel($pdo);
                $sesion = $this->exigirSesionPropiaAbierta($cajaModel, $idVendedor, true);
                $idSesion = (int)$sesion['id_sesion_caja'];

                $adminName = null;
                if ($typeTrim !== 'cash_out') {
                    $admin = $cajaModel->obtenerAdministradorActivoPorId((int)$administratorId, false);
                    if ($admin === null) {
                        throw new InventarioException(
                            'El administrador especificado no existe o no se encuentra activo.',
                            'ADMINISTRATOR_REQUIRED',
                            422,
                            ['administrator_id' => ['Debe corresponder a un administrador activo.']]
                        );
                    }
                    $adminName = (string)$admin['nombre'];
                }

                $amountCent = $importeValidado['centimos'];
                $totalIngresosStr = (string)$sesion['total_ingresos'];
                $totalEgresosCent = self::parseDecimalACentimos((string)$sesion['total_egresos']);
                $totalOmitidosCent = self::parseDecimalACentimos((string)$sesion['total_retiros_omitidos_declarados']);
                $esperadoActualCent = self::parseDecimalACentimos((string)$sesion['efectivo_esperado_actual']);

                $ahora = $this->ahoraLima();
                $fechaHoraDb = $ahora->format('Y-m-d H:i:s');

                if ($typeTrim === 'omitted_withdrawal') {
                    $origen = $cajaModel->obtenerSesionOrigenElegibleParaOmitido(
                        (int)$originSessionId,
                        (string)$sesion['fecha_hora_apertura'],
                        $idSesion
                    );
                    if ($origen === null) {
                        throw new InventarioException(
                            'La sesión de origen no existe, no está cerrada antes de la apertura actual o está fuera del conjunto elegible.',
                            'ORIGIN_TURN_REQUIRED',
                            422,
                            ['origin_session_id' => ['Debe pertenecer a las últimas 20 sesiones cerradas anteriores a la apertura actual.']]
                        );
                    }

                    $nuevoTotalOmitidosCent = $totalOmitidosCent + $amountCent;
                    if ($nuevoTotalOmitidosCent > self::MAX_CENTIMOS) {
                        throw new InventarioException(
                            'El retiro omitido supera la capacidad técnica permitida para los acumulados de la sesión.',
                            'BALANCE_OVERFLOW',
                            409
                        );
                    }

                    $origenDate = (new DateTimeImmutable((string)$origen['fecha_hora_cierre'], new DateTimeZone(self::LIMA_TZ)))->format('Y-m-d');
                    $origenTurn = (string)$origen['nombre_turno_referencia'];
                    $referenciaVisible = "Sesión #{$originSessionId} ({$origenDate} · {$origenTurn}) · Recibió: {$adminName} · registrado hoy";
                    $efectoSaldoDb = '0.00';
                    $impactFloat = 0.0;
                    $effectLabel = 'Sin efecto: ya reflejado en la apertura';
                    $nuevoEsperadoCent = $esperadoActualCent;
                    $nuevoTotalEgresosCent = $totalEgresosCent;
                    $message = 'Retiro omitido registrado correctamente sin alterar el saldo actual.';
                } else {
                    if ($amountCent > $esperadoActualCent) {
                        throw new InventarioException(
                            'El egreso supera el saldo efectivo disponible en la sesión actual.',
                            'INSUFFICIENT_BALANCE',
                            409,
                            ['amount' => ['Saldo insuficiente para cubrir el egreso solicitado.']],
                            ['current_balance' => (float)self::centimosADecimal($esperadoActualCent)]
                        );
                    }

                    $nuevoTotalEgresosCent = $totalEgresosCent + $amountCent;
                    if ($nuevoTotalEgresosCent > self::MAX_CENTIMOS) {
                        throw new InventarioException(
                            'El egreso supera la capacidad técnica permitida para los acumulados de la sesión.',
                            'BALANCE_OVERFLOW',
                            409
                        );
                    }

                    $nuevoTotalOmitidosCent = $totalOmitidosCent;
                    $nuevoEsperadoCent = $esperadoActualCent - $amountCent;
                    $efectoSaldoDb = '-' . $importeValidado['normalizado'];
                    $impactFloat = -$importeValidado['float'];
                    $effectLabel = 'Resta';
                    $referenciaVisible = $typeTrim === 'admin_withdrawal'
                        ? $reason . ' · Recibió: ' . $adminName
                        : $reason;
                    $message = 'Egreso registrado correctamente.';
                }

                $idMovimiento = $cajaModel->insertarMovimientoCaja([
                    'id_sesion_caja'                             => $idSesion,
                    'tipo_movimiento'                            => self::MAP_API_A_BD_TIPO[$typeTrim],
                    'monto'                                      => $importeValidado['normalizado'],
                    'efecto_saldo'                               => $efectoSaldoDb,
                    'motivo'                                     => $reason,
                    'referencia_visible'                         => $referenciaVisible,
                    'id_usuario_registro'                        => $idVendedor,
                    'id_usuario_admin'                           => $administratorId,
                    'id_venta'                                   => null,
                    'id_sesion_origen_omitido'                   => $originSessionId,
                    'declarado_ocurrio_en_sesion_origen_abierta' => $declaredOpen === true ? 1 : null,
                    'confirmado_reflejado_en_apertura'           => $confirmedReflected === true ? 1 : null,
                    'id_movimiento_original'                     => null,
                    'id_solicitud_idempotencia'                  => $idSolicitud > 0 ? $idSolicitud : null,
                    'fecha_hora_registro'                        => $fechaHoraDb,
                ]);

                $cajaModel->actualizarAcumuladosYVersionSesion(
                    $idSesion,
                    $totalIngresosStr,
                    self::centimosADecimal($nuevoTotalEgresosCent),
                    self::centimosADecimal($nuevoTotalOmitidosCent),
                    self::centimosADecimal($nuevoEsperadoCent)
                );
                $cajaModel->desactualizarArqueoVigenteSesion($idSesion, $fechaHoraDb);

                $nuevaVersion = (int)$sesion['version_caja'] + 1;
                $nuevoSaldoFloat = (float)self::centimosADecimal($nuevoEsperadoCent);

                $response = [
                    'status'            => true,
                    'code'              => 'MOVEMENT_REGISTERED',
                    'idempotent_replay' => false,
                    'message'           => $message,
                    'data'              => [
                        'result_code' => 'MOVEMENT_REGISTERED',
                        'movement'    => [
                            'id'                                       => $idMovimiento,
                            'session_id'                               => $idSesion,
                            'type'                                     => $typeTrim,
                            'label'                                    => self::MAP_ETIQUETAS_TIPO[$typeTrim],
                            'amount'                                   => $importeValidado['float'],
                            'current_amount'                           => $importeValidado['float'],
                            'impact'                                   => $impactFloat,
                            'effect'                                   => $effectLabel,
                            'reason'                                   => $reason,
                            'reference'                                => $referenciaVisible,
                            'administrator_id'                         => $administratorId,
                            'administrator_name'                       => $adminName,
                            'origin_session_id'                        => $originSessionId,
                            'declared_occurred_in_open_origin_session' => $declaredOpen,
                            'confirmed_reflected_in_opening'           => $confirmedReflected,
                            'responsible'                              => $sellerName,
                            'recorded_at'                              => $this->formatearIsoLima($fechaHoraDb),
                            'status'                                   => 'Confirmado',
                        ],
                        'balance'         => $nuevoSaldoFloat,
                        'current_balance' => $nuevoSaldoFloat,
                        'version'         => $nuevaVersion,
                    ],
                ];

                return [
                    'id_registro' => $idMovimiento,
                    'http_code'   => 201,
                    'response'    => $response,
                ];
            }
        );

        return [
            'http_code' => (int)$ejecucion['http_code'],
            'response'  => $ejecucion['response'],
        ];
    }

    /**
     * EP-15: GET /api/caja/movimientos/corregibles
     */
    public function listarMovimientosCorregibles(int $idVendedorAutenticado, array $queryParams): array
    {
        $this->validarCamposPermitidos($queryParams, ['url', 'session_id', 'sesion_id']);
        $rawSessionId = $this->resolverAliasCuerpo($queryParams, 'session_id', 'sesion_id');
        $sessionIdFiltro = null;
        if ($rawSessionId !== null) {
            $sessionIdFiltro = $this->validarEnteroPositivoRequerido(
                $rawSessionId,
                true,
                'session_id',
                'VALIDATION_ERROR',
                'El parámetro session_id debe ser un entero positivo.'
            );
        }

        $sesion = $this->exigirSesionPropiaAbierta($this->cajaModel, $idVendedorAutenticado, false);
        $idSesionActiva = (int)$sesion['id_sesion_caja'];

        if ($sessionIdFiltro !== null && $sessionIdFiltro !== $idSesionActiva) {
            throw new InventarioException(
                'No tiene permisos para consultar movimientos de una sesión distinta a su sesión abierta actual.',
                'SESSION_NOT_OWNED',
                403
            );
        }

        $movRows = $this->cajaModel->listarMovimientosCorregiblesSesion($idSesionActiva, $idVendedorAutenticado);
        $corrRows = $this->cajaModel->listarCorreccionesSesion($idSesionActiva);

        $ultimoVigentePorOriginal = [];
        $corrections = [];
        foreach ($corrRows as $c) {
            $origId = (int)$c['id_movimiento_original'];
            $correctedFloat = round((float)$c['monto_corregido'], 2);
            $ultimoVigentePorOriginal[$origId] = $correctedFloat;

            $efectoCent = self::parseDecimalACentimos((string)$c['efecto_saldo']);
            $corrections[] = [
                'id'                        => (int)$c['id_correccion_caja'],
                'original_movement_id'      => $origId,
                'previous_correction_id'    => $c['id_correccion_previa'] !== null ? (int)$c['id_correccion_previa'] : null,
                'original_reference'        => (string)($c['original_reference'] ?? ''),
                'original_amount'           => round((float)$c['monto_original'], 2),
                'previous_corrected_amount' => round((float)$c['monto_vigente_previo'], 2),
                'corrected_amount'          => $correctedFloat,
                'balance_effect'            => (float)self::centimosADecimal($efectoCent),
                'effect'                    => self::formatearEtiquetaEfectoCorreccion($efectoCent),
                'reason'                    => (string)$c['motivo'],
                'status'                    => 'Confirmada',
                'recorded_at'               => $this->formatearIsoLima((string)$c['fecha_hora_registro']),
                'responsible'               => (string)$c['responsible'],
            ];
        }

        $movements = [];
        foreach ($movRows as $m) {
            $movId = (int)$m['id_movimiento_caja'];
            $apiType = self::MAP_BD_A_API_TIPO[(string)$m['tipo_movimiento']];
            $amountFloat = round((float)$m['monto'], 2);
            $tieneCorreccion = array_key_exists($movId, $ultimoVigentePorOriginal);

            $movements[] = [
                'id'             => $movId,
                'type'           => $apiType,
                'label'          => self::MAP_ETIQUETAS_TIPO[$apiType],
                'amount'         => $amountFloat,
                'current_amount' => $tieneCorreccion ? $ultimoVigentePorOriginal[$movId] : $amountFloat,
                'reference'      => (string)($m['referencia_visible'] ?? $m['motivo']),
                'reason'         => (string)$m['motivo'],
                'recorded_at'    => $this->formatearIsoLima((string)$m['fecha_hora_registro']),
                'status'         => $tieneCorreccion ? 'Corregido' : 'Confirmado',
            ];
        }

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Movimientos corregibles y correcciones obtenidos correctamente.',
            'data'    => [
                'session_id'  => $idSesionActiva,
                'movements'   => $movements,
                'corrections' => $corrections,
            ],
        ];
    }

    /**
     * EP-16: POST /api/caja/movimientos/correcciones
     */
    public function registrarCorreccion(
        array $input,
        ?string $headerReferencia,
        array $vendedorAutenticado,
        ?string $rawCorrectedAmountLiteral = null,
        bool $correctedAmountDuplicated = false
    ): array {
        $this->validarCamposPermitidos($input, [
            'referencia_solicitud',
            'request_id',
            'session_id',
            'sesion_id',
            'original_movement_id',
            'corrected_amount',
            'reason',
        ]);

        if ($correctedAmountDuplicated) {
            throw new InventarioException(
                'El campo corrected_amount no puede repetirse en la solicitud.',
                'VALIDATION_ERROR',
                422,
                ['corrected_amount' => ['El campo corrected_amount no puede aparecer más de una vez en el objeto raíz.']]
            );
        }

        $referencia = $this->resolverReferenciaCuerpoOCabecera($input, $headerReferencia);
        $rawSessionId = $this->resolverAliasCuerpo($input, 'session_id', 'sesion_id');
        $sessionIdParam = null;
        if ($rawSessionId !== null) {
            $sessionIdParam = $this->validarEnteroPositivoRequerido(
                $rawSessionId,
                true,
                'session_id',
                'VALIDATION_ERROR',
                'El campo session_id debe ser un entero positivo.'
            );
        }

        $originalMovementId = $this->validarEnteroPositivoRequerido(
            $input['original_movement_id'] ?? null,
            array_key_exists('original_movement_id', $input),
            'original_movement_id',
            'VALIDATION_ERROR',
            'Debe indicar un original_movement_id válido.'
        );

        $importeValidado = self::validarImporteMonetario(
            $input['corrected_amount'] ?? null,
            array_key_exists('corrected_amount', $input),
            'corrected_amount',
            true,
            $rawCorrectedAmountLiteral
        );

        $reason = $this->validarMotivoObligatorio($input['reason'] ?? null, array_key_exists('reason', $input));
        $idVendedor = (int)$vendedorAutenticado['id_usuario'];
        $sellerName = (string)($vendedorAutenticado['nombre'] ?? '');

        $payloadCanonico = [
            'vendedor_id'          => $idVendedor,
            'session_id'           => $sessionIdParam,
            'original_movement_id' => $originalMovementId,
            'corrected_amount'     => $importeValidado['normalizado'],
            'reason'               => $reason,
        ];

        $ejecucion = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idVendedor,
            'CAJA_CORRECCION',
            $referencia,
            $payloadCanonico,
            function (PDO $pdo, int $idSolicitud) use (
                $idVendedor,
                $sellerName,
                $sessionIdParam,
                $originalMovementId,
                $importeValidado,
                $reason
            ): array {
                $cajaModel = new CajaModel($pdo);
                $sesion = $this->exigirSesionPropiaAbierta($cajaModel, $idVendedor, true);
                $idSesion = (int)$sesion['id_sesion_caja'];

                if ($sessionIdParam !== null && $sessionIdParam !== $idSesion) {
                    throw new InventarioException(
                        'La sesión indicada no corresponde a su sesión abierta actual.',
                        'SESSION_NOT_OWNED',
                        403
                    );
                }

                $orig = $cajaModel->obtenerMovimientoPorId($originalMovementId, true);
                if ($orig === null) {
                    throw new InventarioException(
                        'El movimiento original indicado no existe.',
                        'MOVEMENT_NOT_FOUND',
                        404
                    );
                }

                if ((int)$orig['id_usuario_registro'] !== $idVendedor) {
                    throw new InventarioException(
                        'No tiene permisos para corregir un movimiento de otro vendedor.',
                        'SESSION_NOT_OWNED',
                        403
                    );
                }

                if ((int)$orig['id_sesion_caja'] !== $idSesion) {
                    $sesionOrig = $cajaModel->obtenerEstadoSesionPorId((int)$orig['id_sesion_caja']);
                    if ($sesionOrig !== null && (int)$sesionOrig['id_usuario_vendedor'] === $idVendedor && $sesionOrig['estado'] === 'CERRADA') {
                        throw new InventarioException(
                            'No se pueden corregir movimientos pertenecientes a una sesión cerrada.',
                            'CASH_SESSION_CLOSED',
                            409
                        );
                    }
                    throw new InventarioException(
                        'El movimiento original no pertenece a su sesión abierta actual.',
                        'SESSION_NOT_OWNED',
                        403
                    );
                }

                $origTipoBd = (string)$orig['tipo_movimiento'];
                if (!in_array($origTipoBd, ['INGRESO_MANUAL', 'INYECCION_ADMIN', 'SALIDA_CAJA', 'RETIRO_ADMIN'], true)) {
                    throw new InventarioException(
                        'Este tipo de movimiento no admite correcciones vinculadas.',
                        'CORRECTION_NOT_ALLOWED',
                        409
                    );
                }

                $ultimaCorr = $cajaModel->obtenerUltimaCorreccionDeMovimiento($originalMovementId, true);
                $origAmountCent = self::parseDecimalACentimos((string)$orig['monto']);
                $prevCorrId = $ultimaCorr !== null ? (int)$ultimaCorr['id_correccion_caja'] : null;
                $numeroEslabon = $ultimaCorr !== null ? ((int)$ultimaCorr['numero_eslabon'] + 1) : 1;
                $vigentePrevioCent = $ultimaCorr !== null
                    ? self::parseDecimalACentimos((string)$ultimaCorr['monto_corregido'])
                    : $origAmountCent;

                $correctedCent = $importeValidado['centimos'];
                if ($correctedCent === $vigentePrevioCent) {
                    throw new InventarioException(
                        'El importe corregido debe ser distinto del importe vigente actual.',
                        'NO_CORRECTION_CHANGE',
                        422,
                        ['corrected_amount' => ['El nuevo importe es igual al importe vigente actual.']]
                    );
                }

                $esIngreso = in_array($origTipoBd, ['INGRESO_MANUAL', 'INYECCION_ADMIN'], true);
                $balanceEffectCent = $esIngreso
                    ? ($correctedCent - $vigentePrevioCent)
                    : ($vigentePrevioCent - $correctedCent);

                $esperadoActualCent = self::parseDecimalACentimos((string)$sesion['efectivo_esperado_actual']);
                $nuevoEsperadoCent = $esperadoActualCent + $balanceEffectCent;

                if ($nuevoEsperadoCent < 0) {
                    throw new InventarioException(
                        'El ajuste de corrección dejaría el efectivo esperado en negativo.',
                        'INSUFFICIENT_BALANCE',
                        409,
                        ['corrected_amount' => ['Saldo insuficiente para aplicar el ajuste reductor.']],
                        ['current_balance' => (float)self::centimosADecimal($esperadoActualCent)]
                    );
                }

                $totalIngresosCent = self::parseDecimalACentimos((string)$sesion['total_ingresos']);
                $totalEgresosCent = self::parseDecimalACentimos((string)$sesion['total_egresos']);
                $totalOmitidosStr = (string)$sesion['total_retiros_omitidos_declarados'];

                if ($esIngreso) {
                    $totalIngresosCent += $balanceEffectCent;
                } else {
                    $totalEgresosCent -= $balanceEffectCent;
                }

                if (
                    $nuevoEsperadoCent > self::MAX_CENTIMOS
                    || $totalIngresosCent > self::MAX_CENTIMOS
                    || $totalEgresosCent > self::MAX_CENTIMOS
                    || $totalIngresosCent < 0
                    || $totalEgresosCent < 0
                ) {
                    throw new InventarioException(
                        'La corrección supera la capacidad técnica permitida para los acumulados de la sesión.',
                        'BALANCE_OVERFLOW',
                        409
                    );
                }

                $ahora = $this->ahoraLima();
                $fechaHoraDb = $ahora->format('Y-m-d H:i:s');
                $origRef = (string)($orig['referencia_visible'] ?? $orig['motivo']);
                $absEffectStr = self::centimosADecimal(abs($balanceEffectCent));
                $effectStr = self::centimosADecimal($balanceEffectCent);

                $idMovCorreccion = $cajaModel->insertarMovimientoCaja([
                    'id_sesion_caja'                             => $idSesion,
                    'tipo_movimiento'                            => 'CORRECCION',
                    'monto'                                      => $absEffectStr,
                    'efecto_saldo'                               => $effectStr,
                    'motivo'                                     => $reason,
                    'referencia_visible'                         => 'Corrección #' . $numeroEslabon . ' sobre mov. #' . $originalMovementId . ': ' . $reason,
                    'id_usuario_registro'                        => $idVendedor,
                    'id_usuario_admin'                           => null,
                    'id_venta'                                   => null,
                    'id_sesion_origen_omitido'                   => null,
                    'declarado_ocurrio_en_sesion_origen_abierta' => null,
                    'confirmado_reflejado_en_apertura'           => null,
                    'id_movimiento_original'                     => $originalMovementId,
                    'id_solicitud_idempotencia'                  => null,
                    'fecha_hora_registro'                        => $fechaHoraDb,
                ]);

                $idCorreccion = $cajaModel->insertarCorreccionMovimiento([
                    'id_sesion_caja'            => $idSesion,
                    'id_movimiento_original'    => $originalMovementId,
                    'id_movimiento_correccion'  => $idMovCorreccion,
                    'id_correccion_previa'      => $prevCorrId,
                    'numero_eslabon'            => $numeroEslabon,
                    'monto_original'            => self::centimosADecimal($origAmountCent),
                    'monto_vigente_previo'      => self::centimosADecimal($vigentePrevioCent),
                    'monto_corregido'           => $importeValidado['normalizado'],
                    'efecto_saldo'              => $effectStr,
                    'motivo'                    => $reason,
                    'id_usuario_registro'       => $idVendedor,
                    'id_solicitud_idempotencia' => $idSolicitud > 0 ? $idSolicitud : null,
                    'fecha_hora_registro'       => $fechaHoraDb,
                ]);

                $cajaModel->actualizarAcumuladosYVersionSesion(
                    $idSesion,
                    self::centimosADecimal($totalIngresosCent),
                    self::centimosADecimal($totalEgresosCent),
                    $totalOmitidosStr,
                    self::centimosADecimal($nuevoEsperadoCent)
                );
                $cajaModel->desactualizarArqueoVigenteSesion($idSesion, $fechaHoraDb);

                $nuevaVersion = (int)$sesion['version_caja'] + 1;
                $nuevoSaldoFloat = (float)self::centimosADecimal($nuevoEsperadoCent);
                $origApiType = self::MAP_BD_A_API_TIPO[$origTipoBd];

                $response = [
                    'status'            => true,
                    'code'              => 'CORRECTION_SAVED',
                    'idempotent_replay' => false,
                    'message'           => 'Corrección vinculada registrada correctamente.',
                    'data'              => [
                        'result_code' => 'CORRECTION_SAVED',
                        'correction'  => [
                            'id'                        => $idCorreccion,
                            'original_movement_id'      => $originalMovementId,
                            'previous_correction_id'    => $prevCorrId,
                            'original_reference'        => $origRef,
                            'original_amount'           => (float)self::centimosADecimal($origAmountCent),
                            'previous_corrected_amount' => (float)self::centimosADecimal($vigentePrevioCent),
                            'corrected_amount'          => $importeValidado['float'],
                            'balance_effect'            => (float)$effectStr,
                            'effect'                    => self::formatearEtiquetaEfectoCorreccion($balanceEffectCent),
                            'reason'                    => $reason,
                            'status'                    => 'Confirmada',
                            'recorded_at'               => $this->formatearIsoLima($fechaHoraDb),
                            'responsible'               => $sellerName,
                        ],
                        'original_movement' => [
                            'id'             => $originalMovementId,
                            'type'           => $origApiType,
                            'label'          => self::MAP_ETIQUETAS_TIPO[$origApiType],
                            'amount'         => (float)self::centimosADecimal($origAmountCent),
                            'current_amount' => $importeValidado['float'],
                            'status'         => 'Corregido',
                        ],
                        'balance'         => $nuevoSaldoFloat,
                        'current_balance' => $nuevoSaldoFloat,
                        'version'         => $nuevaVersion,
                    ],
                ];

                return [
                    'id_registro' => $idCorreccion,
                    'http_code'   => 201,
                    'response'    => $response,
                ];
            }
        );

        return [
            'http_code' => (int)$ejecucion['http_code'],
            'response'  => $ejecucion['response'],
        ];
    }

    private function exigirSesionPropiaAbierta(CajaModel $cajaModel, int $idVendedorAutenticado, bool $forUpdate): array
    {
        $sesion = $cajaModel->obtenerSesionAbiertaActual($forUpdate);
        if ($sesion === null) {
            throw new InventarioException(
                'No existe una sesión de caja abierta para el vendedor actual.',
                'CASH_SESSION_CLOSED',
                409
            );
        }
        if ((int)$sesion['id_usuario_vendedor'] !== $idVendedorAutenticado) {
            throw new InventarioException(
                'La sesión de caja abierta pertenece a otro vendedor.',
                'SESSION_NOT_OWNED',
                403
            );
        }
        return $sesion;
    }

    private function validarMotivoObligatorio($rawReason, bool $presente): string
    {
        if (!$presente || $rawReason === null) {
            throw new InventarioException(
                'El motivo es obligatorio.',
                'MOTIVE_REQUIRED',
                422,
                ['reason' => ['El motivo no puede estar vacío.']]
            );
        }
        if (!is_string($rawReason)) {
            throw new InventarioException(
                'El motivo es inválido.',
                'VALIDATION_ERROR',
                422,
                ['reason' => ['El motivo debe ser una cadena de texto.']]
            );
        }
        $trimmed = trim($rawReason);
        if ($trimmed === '') {
            throw new InventarioException(
                'El motivo es obligatorio.',
                'MOTIVE_REQUIRED',
                422,
                ['reason' => ['El motivo debe contener al menos 1 carácter visible.']]
            );
        }
        if (mb_strlen($trimmed, 'UTF-8') > 250) {
            throw new InventarioException(
                'El motivo excede el límite de 250 caracteres.',
                'VALIDATION_ERROR',
                422,
                ['reason' => ['El motivo admite como máximo 250 caracteres.']]
            );
        }
        return $trimmed;
    }

    private function validarEnteroPositivoRequerido(
        $rawVal,
        bool $presente,
        string $fieldName,
        string $errorCode,
        string $message
    ): int {
        if (!$presente || $rawVal === null || is_bool($rawVal) || is_array($rawVal) || is_object($rawVal) || is_float($rawVal)) {
            throw new InventarioException(
                $message,
                $errorCode,
                422,
                [$fieldName => [$message]]
            );
        }

        if (is_int($rawVal)) {
            $id = $rawVal;
        } elseif (is_string($rawVal) && preg_match('/^\d+$/', trim($rawVal))) {
            $id = (int)trim($rawVal);
        } else {
            throw new InventarioException(
                $message,
                $errorCode,
                422,
                [$fieldName => [$message]]
            );
        }

        if ($id <= 0) {
            throw new InventarioException(
                $message,
                $errorCode,
                422,
                [$fieldName => [$message]]
            );
        }

        return $id;
    }

    private function resolverAliasBooleano(array $contenedor, string $canonico, string $alias)
    {
        $tieneCanonico = array_key_exists($canonico, $contenedor) && $contenedor[$canonico] !== null;
        $tieneAlias = array_key_exists($alias, $contenedor) && $contenedor[$alias] !== null;

        if ($tieneCanonico && $tieneAlias && $contenedor[$canonico] !== $contenedor[$alias]) {
            throw new InventarioException(
                "Los campos {$canonico} y {$alias} contienen valores contradictorios.",
                'AMBIGUOUS_ALIAS_VALUES',
                422,
                [$canonico => ["Conflicto entre {$canonico} y {$alias}."]]
            );
        }

        return $tieneCanonico ? $contenedor[$canonico] : ($tieneAlias ? $contenedor[$alias] : null);
    }

    private static function formatearEtiquetaEfectoCorreccion(int $efectoCentimos): string
    {
        $prefix = $efectoCentimos >= 0 ? 'Suma S/ ' : 'Resta S/ ';
        return $prefix . number_format(abs($efectoCentimos) / 100, 2, ',', '');
    }

    private function validarQuerySoloVendedorPropio(array $queryParams, int $idVendedorAutenticado): void
    {
        $permitidos = ['url', 'vendedor_id'];
        $desconocidos = [];
        foreach (array_keys($queryParams) as $key) {
            if (!in_array((string)$key, $permitidos, true)) {
                $desconocidos[(string)$key] = ['Parámetro de consulta no permitido en este endpoint.'];
            }
        }
        if (!empty($desconocidos)) {
            throw new InventarioException(
                'Parámetros de consulta inválidos.',
                'VALIDATION_ERROR',
                422,
                $desconocidos
            );
        }

        if (array_key_exists('vendedor_id', $queryParams)) {
            $raw = $queryParams['vendedor_id'];
            if (is_array($raw) || trim((string)$raw) === '') {
                throw new InventarioException(
                    'El parámetro vendedor_id es inválido.',
                    'VALIDATION_ERROR',
                    422,
                    ['vendedor_id' => ['Debe ser un entero positivo válido.']]
                );
            }
            $str = trim((string)$raw);
            if (!ctype_digit($str) || (int)$str <= 0) {
                throw new InventarioException(
                    'El parámetro vendedor_id es inválido.',
                    'VALIDATION_ERROR',
                    422,
                    ['vendedor_id' => ['Debe ser un entero positivo válido.']]
                );
            }
            if ((int)$str !== $idVendedorAutenticado) {
                throw new InventarioException(
                    'No tienes permisos para consultar información de otro vendedor.',
                    'FORBIDDEN',
                    403
                );
            }
        }
    }

    private function validarCamposPermitidos(array $input, array $permitidos): void
    {
        $errores = [];
        foreach (array_keys($input) as $campo) {
            if (!in_array((string)$campo, $permitidos, true)) {
                $errores[(string)$campo] = ['Campo no permitido en la solicitud.'];
            }
        }
        if (!empty($errores)) {
            throw new InventarioException(
                'La solicitud contiene campos no reconocidos.',
                'VALIDATION_ERROR',
                422,
                $errores
            );
        }
    }

    private function resolverAliasCuerpo(array $contenedor, string $canonico, string $alias)
    {
        $tieneCanonico = array_key_exists($canonico, $contenedor) && $contenedor[$canonico] !== null && $contenedor[$canonico] !== '';
        $tieneAlias = array_key_exists($alias, $contenedor) && $contenedor[$alias] !== null && $contenedor[$alias] !== '';

        if ($tieneCanonico && $tieneAlias) {
            $valC = is_scalar($contenedor[$canonico]) ? (string)$contenedor[$canonico] : json_encode($contenedor[$canonico]);
            $valA = is_scalar($contenedor[$alias]) ? (string)$contenedor[$alias] : json_encode($contenedor[$alias]);
            if ($valC !== $valA) {
                throw new InventarioException(
                    "Los campos {$canonico} y {$alias} contienen valores contradictorios.",
                    'AMBIGUOUS_ALIAS_VALUES',
                    422,
                    [$canonico => ["Conflicto entre {$canonico} y {$alias}."]]
                );
            }
        }

        return $tieneCanonico ? $contenedor[$canonico] : ($tieneAlias ? $contenedor[$alias] : null);
    }

    private function resolverReferenciaCuerpoOCabecera(array $input, ?string $headerReferencia): string
    {
        $refBody = $this->resolverAliasCuerpo($input, 'referencia_solicitud', 'request_id');
        $refBodyStr = ($refBody !== null && !is_array($refBody) && !is_object($refBody)) ? trim((string)$refBody) : null;
        $refHeaderStr = ($headerReferencia !== null && trim($headerReferencia) !== '') ? trim($headerReferencia) : null;

        if ($refBody !== null && (is_array($refBody) || is_object($refBody) || is_bool($refBody))) {
            throw new InventarioException(
                'La referencia de solicitud es inválida.',
                'VALIDATION_ERROR',
                422,
                ['referencia_solicitud' => ['Debe ser una cadena válida de 16 a 64 caracteres.']]
            );
        }

        if ($refBodyStr !== null && $refBodyStr !== '' && $refHeaderStr !== null && $refBodyStr !== $refHeaderStr) {
            throw new InventarioException(
                'La referencia enviada en X-Request-Reference no coincide con el cuerpo de la solicitud.',
                'AMBIGUOUS_ALIAS_VALUES',
                422,
                ['referencia_solicitud' => ['Conflicto entre X-Request-Reference y el cuerpo.']]
            );
        }

        $referencia = $refBodyStr ?: $refHeaderStr;
        if ($referencia === null || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $referencia)) {
            throw new InventarioException(
                'La referencia de solicitud es obligatoria y debe tener entre 16 y 64 caracteres alfanuméricos, guion o guion bajo.',
                'VALIDATION_ERROR',
                422,
                ['referencia_solicitud' => ['Formato inválido.']]
            );
        }

        return $referencia;
    }

    private function validarShiftIdEntrada($rawShiftId): int
    {
        if ($rawShiftId === null || is_bool($rawShiftId) || is_array($rawShiftId) || is_object($rawShiftId)) {
            throw new InventarioException(
                'El turno seleccionado es inválido.',
                'INVALID_SHIFT',
                422,
                ['shift_id' => ['Debe proporcionar un identificador entero positivo de turno activo.']]
            );
        }

        if (is_int($rawShiftId)) {
            $id = $rawShiftId;
        } elseif (is_string($rawShiftId) && preg_match('/^\d+$/', trim($rawShiftId))) {
            $id = (int)trim($rawShiftId);
        } else {
            throw new InventarioException(
                'El turno seleccionado es inválido.',
                'INVALID_SHIFT',
                422,
                ['shift_id' => ['Debe ser un entero positivo.']]
            );
        }

        if ($id <= 0) {
            throw new InventarioException(
                'El turno seleccionado es inválido.',
                'INVALID_SHIFT',
                422,
                ['shift_id' => ['Debe ser un entero mayor a 0.']]
            );
        }

        return $id;
    }

    /**
     * Valida y normaliza el importe de apertura en céntimos exactos sin redondear ni truncar entradas inválidas.
     *
     * @return array{centimos:int,normalizado:string,float:float}
     */
    public static function validarImporteApertura($valor, bool $presente, ?string $rawJsonLiteral = null): array
    {
        return self::validarImporteMonetario($valor, $presente, 'cash_counted', true, $rawJsonLiteral);
    }

    /**
     * Valida y normaliza un importe monetario en céntimos exactos.
     *
     * @return array{centimos:int,normalizado:string,float:float}
     */
    public static function validarImporteMonetario(
        $valor,
        bool $presente,
        string $fieldName,
        bool $allowZero,
        ?string $rawJsonLiteral = null
    ): array {
        $minStr = $allowZero ? '0.00' : '0.01';
        if (!$presente || $valor === null || is_bool($valor) || is_array($valor) || is_object($valor)) {
            throw new InventarioException(
                "El importe de {$fieldName} es inválido.",
                'INVALID_AMOUNT',
                422,
                [$fieldName => ["Debe ser un importe decimal válido mayor o igual a {$minStr}."]]
            );
        }

        if ($rawJsonLiteral !== null) {
            $str = trim($rawJsonLiteral);
        } elseif (is_int($valor)) {
            $str = (string)$valor;
        } elseif (is_float($valor)) {
            if (!is_finite($valor)) {
                throw new InventarioException(
                    "El importe de {$fieldName} es inválido.",
                    'INVALID_AMOUNT',
                    422,
                    [$fieldName => ['El importe debe ser un número finito.']]
                );
            }
            $encoded = json_encode($valor);
            $str = is_string($encoded) ? $encoded : '';
            if ($str === '-0' || $str === '-0.0') {
                $str = '0';
            }
        } elseif (is_string($valor)) {
            $str = trim($valor);
            if ($str === '') {
                throw new InventarioException(
                    "El importe de {$fieldName} es inválido.",
                    'INVALID_AMOUNT',
                    422,
                    [$fieldName => ['El importe no puede estar vacío.']]
                );
            }
        } else {
            throw new InventarioException(
                "El importe de {$fieldName} es inválido.",
                'INVALID_AMOUNT',
                422,
                [$fieldName => ['Formato de importe no válido.']]
            );
        }

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $str)) {
            throw new InventarioException(
                "El importe de {$fieldName} es inválido.",
                'INVALID_AMOUNT',
                422,
                [$fieldName => ['Debe ser un número decimal sin notación científica ni caracteres inválidos.']]
            );
        }

        if (str_starts_with($str, '-')) {
            throw new InventarioException(
                "El importe de {$fieldName} no puede ser negativo.",
                'INVALID_AMOUNT',
                422,
                [$fieldName => ["El importe debe ser mayor o igual a {$minStr}."]]
            );
        }

        $partes = explode('.', $str, 2);
        $entero = ltrim($partes[0], '0');
        $entero = ($entero === '') ? '0' : $entero;
        $decimales = $partes[1] ?? '';

        if (strlen($decimales) > 2) {
            throw new InventarioException(
                "El importe de {$fieldName} admite como máximo 2 decimales.",
                'INVALID_AMOUNT',
                422,
                [$fieldName => ['Máximo 2 decimales permitidos.']]
            );
        }

        $decimalesNorm = str_pad($decimales, 2, '0', STR_PAD_RIGHT);
        $normalizado = $entero . '.' . $decimalesNorm;

        // Comparación exacta de cadena decimal contra 99999999.99 (8 dígitos enteros + 2 decimales)
        if (strlen($entero) > 8 || (strlen($entero) === 8 && strcmp($normalizado, self::MAX_AMOUNT) > 0)) {
            throw new InventarioException(
                "El importe de {$fieldName} excede el máximo permitido (99999999.99).",
                'AMOUNT_OUT_OF_RANGE',
                422,
                [$fieldName => ['El importe máximo permitido es 99999999.99.']]
            );
        }

        $centimos = ((int)$entero) * 100 + (int)$decimalesNorm;
        if (!$allowZero && $centimos <= 0) {
            throw new InventarioException(
                "El importe de {$fieldName} debe ser mayor a 0.00.",
                'INVALID_AMOUNT',
                422,
                [$fieldName => ['El importe debe ser al menos 0.01.']]
            );
        }

        return [
            'centimos'    => $centimos,
            'normalizado' => self::centimosADecimal($centimos),
            'float'       => (float)$normalizado,
        ];
    }

    private static function parseDecimalACentimos(string $valorDb): int
    {
        $str = trim($valorDb);
        $negativo = str_starts_with($str, '-');
        if ($negativo) {
            $str = substr($str, 1);
        }
        $partes = explode('.', $str, 2);
        $entero = (int)($partes[0] !== '' ? $partes[0] : '0');
        $dec = (int)str_pad(substr($partes[1] ?? '', 0, 2), 2, '0', STR_PAD_RIGHT);
        $centimos = $entero * 100 + $dec;
        return $negativo ? -$centimos : $centimos;
    }

    private static function centimosADecimal(int $centimos): string
    {
        $sign = $centimos < 0 ? '-' : '';
        $abs = abs($centimos);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function ahoraLima(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::LIMA_TZ));
    }

    private function formatearIsoLima(string $datetimeDb): string
    {
        $dt = new DateTimeImmutable($datetimeDb, new DateTimeZone(self::LIMA_TZ));
        return $dt->format('Y-m-d\TH:i:sP');
    }
}
