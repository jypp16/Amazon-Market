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
    /** @var callable|null */
    private $clockProvider = null;

    public function setClockProvider(?callable $clockProvider): void
    {
        $this->clockProvider = $clockProvider;
    }

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

    public static function parseDecimalACentimos(string $valorDb): int
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

    public static function centimosADecimal(int $centimos): string
    {
        $sign = $centimos < 0 ? '-' : '';
        $abs = abs($centimos);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function ahoraLima(): DateTimeImmutable
    {
        if ($this->clockProvider !== null) {
            return ($this->clockProvider)();
        }
        return new DateTimeImmutable('now', new DateTimeZone(self::LIMA_TZ));
    }

    private function formatearIsoLima(string $datetimeDb): string
    {
        $dt = new DateTimeImmutable($datetimeDb, new DateTimeZone(self::LIMA_TZ));
        return $dt->format('Y-m-d\TH:i:sP');
    }

    /**
     * EP-17: GET /api/caja/arqueo/estado
     */
    public function consultarEstadoArqueo(int $idVendedorAutenticado, array $queryParams): array
    {
        $this->validarQuerySoloVendedorPropio($queryParams, $idVendedorAutenticado);

        $startedRo = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION READ ONLY');
            $startedRo = true;
        }

        try {
            $sesion = $this->cajaModel->obtenerSesionAbiertaActual(false);

            if ($sesion === null || (int)$sesion['id_usuario_vendedor'] !== $idVendedorAutenticado) {
                if ($startedRo) {
                    $this->pdo->exec('COMMIT');
                }
                return [
                    'status'  => true,
                    'code'    => 'OK',
                    'message' => 'Estado de arqueo obtenido correctamente.',
                    'data'    => [
                        'is_open'                              => ($sesion !== null),
                        'is_owner'                             => false,
                        'session_id'                           => null,
                        'seller_id'                            => null,
                        'seller'                               => null,
                        'turn'                                 => null,
                        'reference_schedule'                   => null,
                        'opening_fund'                         => null,
                        'cash_sales'                           => null,
                        'income'                               => null,
                        'expenses'                             => null,
                        'expected'                             => null,
                        'card'                                 => null,
                        'yape_plin'                            => null,
                        'omitted_withdrawals_zero_impact_total'=> null,
                        'version'                              => null,
                        'active_arqueo_id'                     => null,
                        'has_valid_arqueo'                     => false,
                        'history'                              => [],
                        'schedule'                             => null,
                        'closed_summary'                       => null,
                    ],
                ];
            }

            $idSesion = (int)$sesion['id_sesion_caja'];
            $sellerName = $this->cajaModel->obtenerNombreUsuarioPorId($idVendedorAutenticado);
            $arqueosRaw = $this->cajaModel->listarArqueosSesion($idSesion);

            if ($startedRo) {
                $this->pdo->exec('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($startedRo) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\Throwable $ignore) {
                }
            }
            throw $e;
        }

        $history = array_map(fn (array $r): array => $this->formatearArqueoResource($r), $arqueosRaw);
        $activeArqueoId = $this->resolverArqueoActivoId($sesion, $arqueosRaw);

        $horaInicio = (string)$sesion['hora_inicio_referencia'];
        $horaFin = (string)$sesion['hora_fin_referencia'];
        $nombreTurno = (string)$sesion['nombre_turno_referencia'];

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Estado de arqueo obtenido correctamente.',
            'data'    => [
                'is_open'                              => true,
                'is_owner'                             => true,
                'session_id'                           => $idSesion,
                'seller_id'                            => $idVendedorAutenticado,
                'seller'                               => $sellerName,
                'turn'                                 => $nombreTurno,
                'reference_schedule'                   => [
                    'shift_id'   => (int)$sesion['id_turno'],
                    'shift_name' => $nombreTurno,
                    'start'      => $horaInicio,
                    'end'        => $horaFin,
                    'label'      => "{$nombreTurno} · {$horaInicio}–{$horaFin}",
                ],
                'opening_fund'                         => $this->decimalDbANumero((string)$sesion['saldo_inicial']),
                'cash_sales'                           => $this->decimalDbANumero((string)$sesion['total_ventas_efectivo']),
                'income'                               => $this->decimalDbANumero((string)$sesion['total_ingresos']),
                'expenses'                             => $this->decimalDbANumero((string)$sesion['total_egresos']),
                'expected'                             => $this->decimalDbANumero((string)$sesion['efectivo_esperado_actual']),
                'card'                                 => $this->decimalDbANumero((string)$sesion['total_ventas_tarjeta']),
                'yape_plin'                            => $this->decimalDbANumero((string)$sesion['total_ventas_yape_plin']),
                'omitted_withdrawals_zero_impact_total'=> $this->decimalDbANumero((string)$sesion['total_retiros_omitidos_declarados']),
                'version'                              => (int)$sesion['version_caja'],
                'active_arqueo_id'                     => $activeArqueoId,
                'has_valid_arqueo'                     => ($activeArqueoId !== null),
                'history'                              => $history,
                'schedule'                             => $this->evaluarHorarioCierre($sesion),
                'closed_summary'                       => null,
            ],
        ];
    }

    /**
     * EP-18: GET /api/caja/arqueos
     */
    public function listarArqueos(int $idVendedorAutenticado, array $queryParams): array
    {
        $sessionIdParam = $this->resolverSessionIdQueryOpcional($queryParams);

        if ($sessionIdParam === null) {
            $sesion = $this->exigirSesionPropiaAbierta($this->cajaModel, $idVendedorAutenticado, false);
        } else {
            $sesionRow = $this->cajaModel->obtenerSesionCajaPorId($sessionIdParam);
            if ($sesionRow === null) {
                throw new InventarioException(
                    'La sesión de caja solicitada no existe.',
                    'CASH_SESSION_NOT_FOUND',
                    404
                );
            }
            if ((int)$sesionRow['id_usuario_vendedor'] !== $idVendedorAutenticado) {
                throw new InventarioException(
                    'La sesión de caja solicitada pertenece a otro vendedor.',
                    'SESSION_NOT_OWNED',
                    403
                );
            }
            if ((string)$sesionRow['estado'] !== 'ABIERTA') {
                throw new InventarioException(
                    'La sesión de caja consultada ya se encuentra cerrada.',
                    'CASH_SESSION_CLOSED',
                    409
                );
            }
            $sesion = $sesionRow;
        }

        $idSesion = (int)$sesion['id_sesion_caja'];
        $arqueosRaw = $this->cajaModel->listarArqueosSesion($idSesion);
        $history = array_map(fn (array $r): array => $this->formatearArqueoResource($r), $arqueosRaw);
        $activeArqueoId = $this->resolverArqueoActivoId($sesion, $arqueosRaw);

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Historial de arqueos obtenido correctamente.',
            'data'    => [
                'session_id'       => $idSesion,
                'version'          => (int)$sesion['version_caja'],
                'active_arqueo_id' => $activeArqueoId,
                'history'          => $history,
            ],
        ];
    }

    /**
     * EP-19: POST /api/caja/arqueo
     */
    public function guardarArqueo(
        array $input,
        ?string $headerReferencia,
        array $vendedor,
        ?string $rawCountedLiteral = null,
        bool $countedDuplicado = false
    ): array {
        $idVendedor = (int)$vendedor['id_usuario'];

        $this->validarCamposPermitidos($input, [
            'referencia_solicitud',
            'request_id',
            'counted',
            'observation',
            'version',
        ]);

        $referencia = ControlSolicitudService::resolverReferenciaCuerpoOCabecera($input, $headerReferencia);

        if ($countedDuplicado) {
            throw new InventarioException(
                'El campo counted no puede repetirse en la solicitud.',
                'INVALID_AMOUNT',
                422,
                ['counted' => ['El campo counted no debe enviarse duplicado.']]
            );
        }

        $countedParsed = self::validarImporteMonetario(
            $input['counted'] ?? null,
            array_key_exists('counted', $input),
            'counted',
            true,
            $rawCountedLiteral
        );
        $countedDecimal = $countedParsed['normalizado'];
        $countedCent = $countedParsed['centimos'];

        $versionSolicitada = $this->validarEnteroPositivoRequerido(
            $input['version'] ?? null,
            array_key_exists('version', $input),
            'version',
            'VALIDATION_ERROR',
            'La versión de caja debe ser un entero positivo.'
        );

        $observationNorm = $this->validarObservacionArqueo($input);

        $payloadFuncional = [
            'counted'     => $countedDecimal,
            'observation' => $observationNorm,
            'version'     => $versionSolicitada,
        ];

        $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idVendedor,
            'CAJA_ARQUEO',
            $referencia,
            $payloadFuncional,
            function (PDO $pdoTx, int $idSolicitud) use (
                $idVendedor,
                $countedDecimal,
                $countedCent,
                $versionSolicitada,
                $observationNorm
            ): array {
                $cajaTx = new CajaModel($pdoTx);
                $sesion = $this->exigirSesionPropiaAbierta($cajaTx, $idVendedor, true);
                $idSesion = (int)$sesion['id_sesion_caja'];
                $versionActual = (int)$sesion['version_caja'];
                $expectedDecimal = (string)$sesion['efectivo_esperado_actual'];
                $expectedCent = self::parseDecimalACentimos($expectedDecimal);

                if ($versionActual !== $versionSolicitada) {
                    throw new InventarioException(
                        'La versión de la caja cambió antes de guardar el arqueo.',
                        'CASH_CHANGED',
                        409,
                        [],
                        [
                            'expected' => round($expectedCent / 100, 2),
                            'version'  => $versionActual,
                        ]
                    );
                }

                $diffCent = $countedCent - $expectedCent;
                if ($diffCent !== 0 && $observationNorm === null) {
                    throw new InventarioException(
                        'Debe ingresar una observación cuando existe diferencia entre el efectivo contado y el esperado.',
                        'OBSERVATION_REQUIRED',
                        422,
                        ['observation' => ['La observación es obligatoria cuando existe diferencia.']]
                    );
                }

                $ahoraStr = $this->ahoraLima()->format('Y-m-d H:i:s');
                $cajaTx->reemplazarArqueoVigenteSesion($idSesion, $ahoraStr);

                $idArqueo = $cajaTx->insertarArqueoCaja([
                    'id_sesion_caja'            => $idSesion,
                    'id_usuario_vendedor'       => $idVendedor,
                    'version_caja_evaluada'     => $versionActual,
                    'monto_esperado'            => $expectedDecimal,
                    'monto_contado'             => $countedDecimal,
                    'diferencia'                => self::centimosADecimal($diffCent),
                    'observacion'               => $observationNorm,
                    'fecha_hora_arqueo'         => $ahoraStr,
                    'id_solicitud_idempotencia' => $idSolicitud,
                ]);

                $cajaTx->actualizarUltimoArqueoSesion($idSesion, $idArqueo);

                $arqueosRaw = $cajaTx->listarArqueosSesion($idSesion);
                $history = array_map(fn (array $r): array => $this->formatearArqueoResource($r), $arqueosRaw);
                $arqueoActual = null;
                foreach ($history as $item) {
                    if ($item['id'] === $idArqueo) {
                        $arqueoActual = $item;
                        break;
                    }
                }

                return [
                    'id_registro' => $idArqueo,
                    'response'    => [
                        'status'            => true,
                        'code'              => 'ARQUEO_SAVED',
                        'idempotent_replay' => false,
                        'message'           => 'Arqueo guardado correctamente.',
                        'data'              => [
                            'result_code' => 'ARQUEO_SAVED',
                            'arqueo'      => $arqueoActual,
                            'expected'    => round($expectedCent / 100, 2),
                            'version'     => $versionActual,
                            'history'     => $history,
                        ],
                    ],
                ];
            }
        );

        if (!empty($resIdemp['idempotent_replay'])) {
            $resp = $resIdemp['response'];
            $resp['status'] = true;
            $resp['code'] = 'OPERATION_ALREADY_CONFIRMED';
            $resp['idempotent_replay'] = true;
            return [
                'http_code' => 200,
                'response'  => $resp,
            ];
        }

        return [
            'http_code' => 201,
            'response'  => $resIdemp['response'],
        ];
    }

    private function decimalDbANumero(string $valorDb): float
    {
        return round(self::parseDecimalACentimos($valorDb) / 100, 2);
    }

    private function formatearArqueoResource(array $row): array
    {
        $dt = new DateTimeImmutable((string)$row['fecha_hora_arqueo'], new DateTimeZone(self::LIMA_TZ));
        $mapEstado = [
            'VIGENTE'        => 'Vigente',
            'REEMPLAZADO'    => 'Reemplazado',
            'DESACTUALIZADO' => 'Desactualizado',
        ];
        $estadoFisico = (string)$row['estado'];

        return [
            'id'          => (int)$row['id_arqueo_caja'],
            'session_id'  => (int)$row['id_sesion_caja'],
            'date'        => $dt->format('Y-m-d'),
            'time'        => $dt->format('H:i'),
            'recorded_at' => $dt->format('Y-m-d\TH:i:sP'),
            'expected'    => $this->decimalDbANumero((string)$row['monto_esperado']),
            'counted'     => $this->decimalDbANumero((string)$row['monto_contado']),
            'difference'  => $this->decimalDbANumero((string)$row['diferencia']),
            'observation' => $row['observacion'] !== null ? (string)$row['observacion'] : null,
            'status'      => $mapEstado[$estadoFisico] ?? $estadoFisico,
            'version'     => (int)$row['version_caja_evaluada'],
        ];
    }

    private function resolverArqueoActivoId(array $sesion, array $arqueosRaw): ?int
    {
        $idSesion = (int)$sesion['id_sesion_caja'];
        $versionActual = (int)$sesion['version_caja'];
        $idUltimo = $sesion['id_ultimo_arqueo'] !== null ? (int)$sesion['id_ultimo_arqueo'] : null;
        if ($idUltimo === null || empty($arqueosRaw)) {
            return null;
        }

        $ultimoRow = $arqueosRaw[count($arqueosRaw) - 1];
        if (
            (int)$ultimoRow['id_sesion_caja'] === $idSesion
            && (int)$ultimoRow['id_arqueo_caja'] === $idUltimo
            && (string)$ultimoRow['estado'] === 'VIGENTE'
            && (int)$ultimoRow['version_caja_evaluada'] === $versionActual
        ) {
            return $idUltimo;
        }

        return null;
    }

    private function evaluarHorarioCierre(array $sesion, ?DateTimeImmutable $nowOverride = null): array
    {
        $tz = new DateTimeZone(self::LIMA_TZ);
        $salidaDt = new DateTimeImmutable((string)$sesion['fecha_hora_salida_referencia'], $tz);
        $nowDt = ($nowOverride ?? $this->ahoraLima())->setTimezone($tz);
        $earlyThresholdDt = $salidaDt->modify('-15 minutes');
        $requiresAuth = ($nowDt < $earlyThresholdDt);
        $diffSeconds = $salidaDt->getTimestamp() - $nowDt->getTimestamp();
        $minutesRemaining = $diffSeconds > 0 ? (int)ceil($diffSeconds / 60) : 0;

        return [
            'scheduled_end'            => $salidaDt->format('H:i'),
            'current_time'             => $nowDt->format('H:i'),
            'tolerance_minutes'        => 15,
            'early_threshold'          => $earlyThresholdDt->format('H:i'),
            'minutes_remaining_to_end' => $minutesRemaining,
            'requires_authorization'   => $requiresAuth,
            'reason'                   => $requiresAuth ? 'Cierre anterior al margen de quince minutos.' : null,
        ];
    }

    private function resolverSessionIdQueryOpcional(array $queryParams): ?int
    {
        $hasSessionId = array_key_exists('session_id', $queryParams) && $queryParams['session_id'] !== '' && $queryParams['session_id'] !== null;
        $hasSesionId = array_key_exists('sesion_id', $queryParams) && $queryParams['sesion_id'] !== '' && $queryParams['sesion_id'] !== null;

        if (!$hasSessionId && !$hasSesionId) {
            return null;
        }

        $vals = [];
        if ($hasSessionId) {
            $vals[] = $this->validarEnteroPositivoRequerido(
                $queryParams['session_id'],
                true,
                'session_id',
                'VALIDATION_ERROR',
                'El identificador de sesión debe ser un entero positivo.'
            );
        }
        if ($hasSesionId) {
            $vals[] = $this->validarEnteroPositivoRequerido(
                $queryParams['sesion_id'],
                true,
                'sesion_id',
                'VALIDATION_ERROR',
                'El identificador de sesión debe ser un entero positivo.'
            );
        }

        if (count($vals) === 2 && $vals[0] !== $vals[1]) {
            throw new InventarioException(
                'Los parámetros session_id y sesion_id no coinciden.',
                'AMBIGUOUS_ALIAS_VALUES',
                422,
                ['session_id' => ['Los alias session_id y sesion_id deben tener el mismo valor.']]
            );
        }

        return $vals[0];
    }

    private function validarObservacionArqueo(array $input): ?string
    {
        if (!array_key_exists('observation', $input) || $input['observation'] === null) {
            return null;
        }
        if (!is_string($input['observation'])) {
            throw InventarioException::validacion([
                'observation' => ['La observación debe ser una cadena de texto.'],
            ]);
        }
        $trimmed = trim($input['observation']);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed, 'UTF-8') > 500) {
            throw InventarioException::validacion([
                'observation' => ['La observación no puede superar los 500 caracteres.'],
            ]);
        }
        return $trimmed;
    }

    /**
     * EP-20: POST /api/caja/cerrar
     */
    public function cerrarCaja(
        array $input,
        ?string $headerReferencia,
        array $vendedor
    ): array {
        $idVendedor = (int)$vendedor['id_usuario'];

        $this->validarCamposPermitidos($input, [
            'referencia_solicitud',
            'request_id',
            'arqueo_id',
            'admin_email',
            'admin_password',
            'exception_reason',
        ]);

        $referencia = ControlSolicitudService::resolverReferenciaCuerpoOCabecera($input, $headerReferencia);

        $arqueoId = $this->validarEnteroPositivoRequerido(
            $input['arqueo_id'] ?? null,
            array_key_exists('arqueo_id', $input),
            'arqueo_id',
            'VALIDATION_ERROR',
            'El campo arqueo_id debe ser un entero positivo.'
        );

        $rawAdminEmail = $input['admin_email'] ?? null;
        if ($rawAdminEmail !== null && !is_string($rawAdminEmail)) {
            throw new InventarioException(
                'El correo electrónico del administrador autorizador no tiene un formato válido.',
                'INVALID_ADMIN_EMAIL',
                422,
                ['admin_email' => ['El correo electrónico debe ser una cadena de texto válida.']]
            );
        }

        $rawAdminPassword = $input['admin_password'] ?? null;
        if ($rawAdminPassword !== null && !is_string($rawAdminPassword)) {
            throw new InventarioException(
                'Las credenciales del administrador autorizador son inválidas.',
                'INVALID_ADMIN_CREDENTIALS',
                403
            );
        }

        $rawExceptionReason = $input['exception_reason'] ?? null;
        if ($rawExceptionReason !== null && !is_string($rawExceptionReason)) {
            throw new InventarioException(
                'El motivo de excepción debe ser una cadena de texto.',
                'MOTIVE_REQUIRED',
                422,
                ['exception_reason' => ['El motivo de excepción debe ser una cadena de texto.']]
            );
        }
        $exceptionReasonNorm = $rawExceptionReason !== null ? trim($rawExceptionReason) : null;
        if ($exceptionReasonNorm === '') {
            $exceptionReasonNorm = null;
        } elseif ($exceptionReasonNorm !== null && mb_strlen($exceptionReasonNorm, 'UTF-8') > 250) {
            throw InventarioException::validacion([
                'exception_reason' => ['El motivo de excepción no puede superar los 250 caracteres.'],
            ]);
        }

        $idSesionObjetivo = $this->cajaModel->obtenerIdSesionPorArqueoId($arqueoId);
        $payloadFuncional = [
            'session_id'       => $idSesionObjetivo,
            'arqueo_id'        => $arqueoId,
            'exception_reason' => $exceptionReasonNorm,
        ];

        $resIdemp = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idVendedor,
            'CAJA_CERRAR',
            $referencia,
            $payloadFuncional,
            function (PDO $pdoTx, int $idSolicitud) use (
                $idVendedor,
                $arqueoId,
                $rawAdminEmail,
                $rawAdminPassword,
                $exceptionReasonNorm
            ): array {
                $cajaTx = new CajaModel($pdoTx);
                // Mismo orden de bloqueo que apertura: guardia física primero, luego sesion_caja y luego arqueo_caja.
                $cajaTx->bloquearGuardiaCajaFisica();

                $idSesionArqueo = $cajaTx->obtenerIdSesionPorArqueoId($arqueoId);
                if ($idSesionArqueo === null) {
                    throw new InventarioException(
                        'El arqueo especificado no existe.',
                        'ARQUEO_MISSING',
                        409
                    );
                }

                $sesion = $cajaTx->obtenerSesionPorIdParaCierre($idSesionArqueo, true);
                if ($sesion === null) {
                    throw new InventarioException(
                        'El arqueo especificado no existe.',
                        'ARQUEO_MISSING',
                        409
                    );
                }

                if ((int)$sesion['id_usuario_vendedor'] !== $idVendedor) {
                    throw new InventarioException(
                        'No tiene permisos para cerrar una sesión de caja perteneciente a otro vendedor.',
                        'SESSION_NOT_OWNED',
                        403
                    );
                }

                if ((string)$sesion['estado'] !== 'ABIERTA') {
                    throw new InventarioException(
                        'La sesión de caja ya se encuentra cerrada.',
                        'CASH_SESSION_CLOSED',
                        409
                    );
                }

                $idSesion = (int)$sesion['id_sesion_caja'];

                $arqueo = $cajaTx->obtenerArqueoPorId($arqueoId, true);
                if ($arqueo === null || (int)$arqueo['id_sesion_caja'] !== $idSesion) {
                    throw new InventarioException(
                        'El arqueo especificado no existe para la sesión de caja seleccionada.',
                        'ARQUEO_MISSING',
                        409
                    );
                }

                $idUltimoArqueo = $sesion['id_ultimo_arqueo'] !== null ? (int)$sesion['id_ultimo_arqueo'] : null;
                if (
                    $idUltimoArqueo !== $arqueoId
                    || (string)$arqueo['estado'] !== 'VIGENTE'
                    || (int)$arqueo['version_caja_evaluada'] !== (int)$sesion['version_caja']
                ) {
                    throw new InventarioException(
                        'El arqueo especificado ya no está vigente para la versión actual de la caja.',
                        'ARQUEO_STALE',
                        409
                    );
                }

                // Instante único bajo bloqueo para evaluar la regla de 15 minutos y persistir el cierre.
                $tz = new DateTimeZone(self::LIMA_TZ);
                $ahoraDt = $this->ahoraLima()->setTimezone($tz);
                $ahoraDb = $ahoraDt->format('Y-m-d H:i:s');
                $ahoraIso = $ahoraDt->format('Y-m-d\TH:i:sP');
                $salidaDt = new DateTimeImmutable((string)$sesion['fecha_hora_salida_referencia'], $tz);
                $scheduleEval = $this->evaluarHorarioCierre($sesion, $ahoraDt);

                $authorizationResource = null;
                if ($scheduleEval['requires_authorization']) {
                    $sinEmail = ($rawAdminEmail === null || trim($rawAdminEmail) === '');
                    $sinPassword = ($rawAdminPassword === null || $rawAdminPassword === '');
                    if ($sinEmail && $sinPassword) {
                        throw new InventarioException(
                            'Se requiere autorización de un administrador para cerrar antes del margen de 15 minutos.',
                            'AUTHORIZATION_REQUIRED',
                            409,
                            [],
                            ['schedule' => $scheduleEval]
                        );
                    }

                    if ($exceptionReasonNorm === null) {
                        throw new InventarioException(
                            'Debe ingresar el motivo de excepción para el cierre anticipado.',
                            'MOTIVE_REQUIRED',
                            422,
                            ['exception_reason' => ['El motivo de excepción es obligatorio para el cierre anticipado.']]
                        );
                    }

                    $authService = new AuthService();
                    $adminInfo = $authService->verificarCredencialesAdministradorPorEmail(
                        (string)($rawAdminEmail ?? ''),
                        (string)($rawAdminPassword ?? ''),
                        $idVendedor,
                        $pdoTx
                    );

                    $diffSeconds = $salidaDt->getTimestamp() - $ahoraDt->getTimestamp();
                    $minutosAnticipacion = (int)ceil($diffSeconds / 60);

                    $idAutorizacion = $cajaTx->insertarAutorizacionCaja([
                        'id_sesion_caja'               => $idSesion,
                        'id_usuario_vendedor'          => $idVendedor,
                        'id_usuario_admin'             => $adminInfo['id_usuario'],
                        'motivo'                       => $exceptionReasonNorm,
                        'hora_salida_referencia'       => (string)$sesion['hora_fin_referencia'],
                        'fecha_hora_salida_referencia' => (string)$sesion['fecha_hora_salida_referencia'],
                        'hora_cierre_real'             => $ahoraDb,
                        'minutos_anticipacion'         => $minutosAnticipacion,
                        'fecha_hora_autorizacion'      => $ahoraDb,
                        'id_solicitud_idempotencia'    => $idSolicitud,
                    ]);

                    $authorizationResource = [
                        'required'           => true,
                        'id'                 => $idAutorizacion,
                        'administrator_id'   => $adminInfo['id_usuario'],
                        'administrator_name' => $adminInfo['nombre'],
                        'exception_reason'   => $exceptionReasonNorm,
                        'scheduled_end'      => $salidaDt->format('H:i'),
                        'actual_close_time'  => $ahoraDt->format('H:i'),
                        'minutes_before_end' => $minutosAnticipacion,
                        'authorized_at'      => $ahoraIso,
                    ];
                }

                $cajaTx->cerrarSesionCaja([
                    'id_sesion_caja'           => $idSesion,
                    'id_arqueo_cierre'         => $arqueoId,
                    'fecha_hora_cierre'        => $ahoraDb,
                    'efectivo_esperado_cierre' => (string)$arqueo['monto_esperado'],
                    'efectivo_contado_cierre'  => (string)$arqueo['monto_contado'],
                    'diferencia_cierre'        => (string)$arqueo['diferencia'],
                    'observacion_cierre'       => $arqueo['observacion'],
                    'id_solicitud_cierre'      => $idSolicitud,
                ]);

                $sellerName = $cajaTx->obtenerNombreUsuarioPorId($idVendedor);
                $turnName = (string)$sesion['nombre_turno_referencia'];
                $startHm = substr((string)$sesion['hora_inicio_referencia'], 0, 5);
                $endHm = substr((string)$sesion['hora_fin_referencia'], 0, 5);

                $dataPayload = [
                    'result_code'        => 'CLOSE_CONFIRMED',
                    'session_id'         => $idSesion,
                    'status'             => 'Cerrada',
                    'seller_id'          => $idVendedor,
                    'seller'             => $sellerName,
                    'turn'               => $turnName,
                    'reference_schedule' => [
                        'shift_id'   => (int)$sesion['id_turno'],
                        'shift_name' => $turnName,
                        'start'      => $startHm,
                        'end'        => $endHm,
                        'label'      => "{$turnName} · {$startHm}–{$endHm}",
                    ],
                    'opened_at'          => $this->formatearIsoLima((string)$sesion['fecha_hora_apertura']),
                    'closed_at'          => $ahoraIso,
                    'arqueo_id'          => $arqueoId,
                    'summary'            => [
                        'opening_fund'       => $this->decimalDbANumero((string)$sesion['saldo_inicial']),
                        'opening_difference' => $sesion['diferencia_apertura'] !== null
                            ? $this->decimalDbANumero((string)$sesion['diferencia_apertura'])
                            : null,
                        'cash_sales'         => $this->decimalDbANumero((string)$sesion['total_ventas_efectivo']),
                        'card'               => $this->decimalDbANumero((string)$sesion['total_ventas_tarjeta']),
                        'yape_plin'          => $this->decimalDbANumero((string)$sesion['total_ventas_yape_plin']),
                        'income'             => $this->decimalDbANumero((string)$sesion['total_ingresos']),
                        'expenses'           => $this->decimalDbANumero((string)$sesion['total_egresos']),
                        'expected'           => $this->decimalDbANumero((string)$arqueo['monto_esperado']),
                        'counted'            => $this->decimalDbANumero((string)$arqueo['monto_contado']),
                        'difference'         => $this->decimalDbANumero((string)$arqueo['diferencia']),
                        'observation'        => $arqueo['observacion'] !== null ? (string)$arqueo['observacion'] : null,
                        'closed_at'          => $ahoraIso,
                    ],
                    'authorization'      => $authorizationResource,
                ];

                return [
                    'id_registro' => $idSesion,
                    'response'    => [
                        'status'            => true,
                        'code'              => 'CLOSE_CONFIRMED',
                        'idempotent_replay' => false,
                        'message'           => 'Cierre de sesión de caja confirmado correctamente.',
                        'data'              => $dataPayload,
                    ],
                ];
            }
        );

        if (!empty($resIdemp['idempotent_replay'])) {
            $resp = $resIdemp['response'];
            $resp['status'] = true;
            $resp['code'] = 'OPERATION_ALREADY_CONFIRMED';
            $resp['idempotent_replay'] = true;
            return [
                'http_code' => 200,
                'response'  => $resp,
            ];
        }

        return [
            'http_code' => 200,
            'response'  => $resIdemp['response'],
        ];
    }

    /**
     * EP-21: GET /api/caja/cierre?session_id={id}&referencia_solicitud={ref}
     */
    public function consultarCierre(int $idVendedorAutenticado, array $queryParams): array
    {
        $sessionId = $this->resolverSessionIdQueryOpcional($queryParams);
        if ($sessionId === null) {
            throw InventarioException::validacion([
                'session_id' => ['El parámetro session_id es obligatorio y debe ser un entero positivo.'],
            ]);
        }

        $hasRef = array_key_exists('referencia_solicitud', $queryParams);
        $hasReqId = array_key_exists('request_id', $queryParams);
        if ($hasRef && $hasReqId && (string)$queryParams['referencia_solicitud'] !== (string)$queryParams['request_id']) {
            throw new InventarioException(
                'Los parámetros referencia_solicitud y request_id no coinciden.',
                'AMBIGUOUS_ALIAS_VALUES',
                422,
                ['referencia_solicitud' => ['Los alias referencia_solicitud y request_id deben tener el mismo valor.']]
            );
        }

        $rawRef = $hasRef ? $queryParams['referencia_solicitud'] : ($hasReqId ? $queryParams['request_id'] : null);
        $referencia = ControlSolicitudService::validarReferenciaSolicitud($rawRef);

        $sesion = $this->cajaModel->obtenerSesionCajaPorId($sessionId);
        if ($sesion === null) {
            throw new InventarioException(
                'La sesión de caja solicitada no existe.',
                'CASH_SESSION_NOT_FOUND',
                404
            );
        }
        if ((int)$sesion['id_usuario_vendedor'] !== $idVendedorAutenticado) {
            throw new InventarioException(
                'La sesión de caja solicitada pertenece a otro vendedor.',
                'SESSION_NOT_OWNED',
                403
            );
        }

        $confirmacion = $this->controlSolicitudService->buscarConfirmacionPorReferencia(
            $idVendedorAutenticado,
            'CAJA_CERRAR',
            $referencia
        );

        if (
            $confirmacion !== null
            && (int)($confirmacion['response']['data']['session_id'] ?? 0) === $sessionId
        ) {
            return [
                'status'  => true,
                'code'    => 'OK',
                'message' => 'Resumen de cierre recuperado correctamente.',
                'data'    => $confirmacion['response']['data'],
            ];
        }

        if ((string)$sesion['estado'] === 'ABIERTA') {
            return [
                'status'  => true,
                'code'    => 'OK',
                'message' => 'La sesión aún se encuentra abierta.',
                'data'    => null,
            ];
        }

        throw new InventarioException(
            'No está permitido consultar cierres históricos sin la referencia de confirmación correspondiente.',
            'HISTORICAL_QUERY_NOT_ALLOWED',
            403
        );
    }

    // =========================================================================
    // PASO 12: CONSULTAS DE SESIONES DE CAJA Y REPORTE DE CIERRES (EP-22..EP-24B)
    // =========================================================================

    /**
     * EP-22: Consulta de sesión activa del vendedor autenticado (HU 17).
     */
    public function consultarActivaVendedor(int $idVendedorAutenticado, array $queryParams): array
    {
        $filtrosProhibidos = ['seller_id', 'vendedor_id', 'vendedor', 'date', 'fecha', 'session_id', 'sesion_id'];
        foreach ($filtrosProhibidos as $prohibido) {
            if (array_key_exists($prohibido, $queryParams)) {
                throw new InventarioException(
                    'No tienes permiso para filtrar sesiones por otro vendedor, fecha o sesión específica.',
                    'FORBIDDEN',
                    403
                );
            }
        }

        $this->validarQueryParamsPermitidos($queryParams, ['page', 'page_size', 'limit']);
        [$page, $pageSize, $offset] = $this->resolverPaginacion($queryParams, 10, 100);

        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            $sesionActiva = $this->cajaModel->obtenerSesionAbiertaActual(false);
            if ($sesionActiva === null || (int)$sesionActiva['id_usuario_vendedor'] !== $idVendedorAutenticado) {
                $pdo->commit();
                return [
                    'status'  => true,
                    'code'    => 'NO_OPEN_SESSION',
                    'message' => 'No tienes una sesión de caja abierta actualmente.',
                    'data'    => [
                        'type'               => 'no_session',
                        'has_active_session' => false,
                        'session'            => null,
                        'summary'            => null,
                        'movements'          => [],
                        'pagination'         => $this->construirPaginacion(0, $page, $pageSize, 'total_movements'),
                    ],
                ];
            }

            $idSesion = (int)$sesionActiva['id_sesion_caja'];
            $detalle = $this->cajaModel->obtenerSesionDetallePorId($idSesion);
            $totalMovs = $this->cajaModel->contarMovimientosSesion($idSesion);
            $movsRaw = $totalMovs > 0
                ? $this->cajaModel->listarMovimientosSesionPaginados($idSesion, $pageSize, $offset)
                : [];
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Consulta de sesión activa obtenida correctamente.',
            'data'    => [
                'type'               => 'result',
                'has_active_session' => true,
                'session'            => $this->proyectarCabeceraSesionActiva($detalle),
                'summary'            => $this->proyectarSummaryMonetarioSesion($detalle, false),
                'movements'          => array_map([$this, 'proyectarMovimientoConsulta'], $movsRaw),
                'pagination'         => $this->construirPaginacion($totalMovs, $page, $pageSize, 'total_movements'),
            ],
        ];
    }

    /**
     * EP-23A: Lista vendedores (activos e inactivos) para selector de consulta administrativa (HU 17).
     */
    public function listarVendedoresConsulta(array $queryParams): array
    {
        $this->validarQueryParamsPermitidos($queryParams, []);
        $vendedores = $this->cajaModel->listarVendedoresParaConsulta();

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Vendedores obtenidos correctamente.',
            'data'    => $vendedores,
        ];
    }

    /**
     * EP-23B: Lista sesiones de caja por fecha de apertura y vendedor obligatorios, más turn_id opcional (HU 17).
     */
    public function listarSesionesConsulta(array $queryParams): array
    {
        $this->validarQueryParamsPermitidos($queryParams, ['date', 'seller_id', 'turn_id']);

        if (!array_key_exists('date', $queryParams) || is_array($queryParams['date']) || trim((string)$queryParams['date']) === '') {
            throw new InventarioException(
                'El parámetro date es obligatorio y debe tener formato YYYY-MM-DD.',
                'INVALID_DATE',
                422,
                ['date' => ['El parámetro date es obligatorio y debe tener formato YYYY-MM-DD.']]
            );
        }
        $fecha = $this->validarFechaIso((string)$queryParams['date'], 'date', 'INVALID_DATE');

        if (!array_key_exists('seller_id', $queryParams)) {
            throw new InventarioException(
                'El parámetro seller_id es obligatorio.',
                'VALIDATION_ERROR',
                422,
                ['seller_id' => ['El parámetro seller_id es obligatorio.']]
            );
        }
        $sellerId = $this->validarEnteroPositivoEstricto($queryParams['seller_id'], 'seller_id');

        $turnId = null;
        if (array_key_exists('turn_id', $queryParams)) {
            $turnId = $this->validarEnteroPositivoEstricto($queryParams['turn_id'], 'turn_id');
        }

        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            $rows = $this->cajaModel->listarSesionesPorFechaYVendedor($fecha, $sellerId, $turnId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = [
                'id'         => (int)$row['id_sesion_caja'],
                'session_id' => (int)$row['id_sesion_caja'],
                'seller_id'  => (int)$row['id_usuario_vendedor'],
                'seller'     => (string)$row['seller_name'],
                'turn_id'    => (int)$row['id_turno'],
                'turn'       => (string)$row['nombre_turno_referencia'],
                'status'     => $this->formatearEstadoPublicoSesion((string)$row['estado']),
                'opened_at'  => $this->formatearIsoLima((string)$row['fecha_hora_apertura']),
                'closed_at'  => $row['fecha_hora_cierre'] !== null ? $this->formatearIsoLima((string)$row['fecha_hora_cierre']) : null,
            ];
        }

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => empty($sessions)
                ? 'No se encontraron sesiones para la fecha y el vendedor seleccionados.'
                : 'Sesiones obtenidas correctamente.',
            'data'    => $sessions,
        ];
    }

    /**
     * EP-23C: Detalle completo de una sesión de caja para administración (HU 17 + HU 14).
     */
    public function consultarDetalleSesionAdmin(array $queryParams): array
    {
        $this->validarQueryParamsPermitidos($queryParams, ['session_id', 'sesion_id', 'page', 'page_size', 'limit']);
        $rawSessionId = $this->resolverAliasQuery($queryParams, 'session_id', 'sesion_id');
        if ($rawSessionId === null) {
            throw new InventarioException(
                'El parámetro session_id es obligatorio.',
                'VALIDATION_ERROR',
                422,
                ['session_id' => ['El parámetro session_id es obligatorio.']]
            );
        }

        $sessionId = $this->validarEnteroPositivoEstricto($rawSessionId, 'session_id');
        [$page, $pageSize, $offset] = $this->resolverPaginacion($queryParams, 10, 100);

        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            $detalle = $this->cajaModel->obtenerSesionDetallePorId($sessionId);
            if ($detalle === null) {
                $pdo->commit();
                throw new InventarioException(
                    'La sesión de caja solicitada no existe.',
                    'CASH_SESSION_NOT_FOUND',
                    404
                );
            }

            $autorizacionesRaw = $this->cajaModel->listarAutorizacionesPorSesion($sessionId);
            $totalMovs = $this->cajaModel->contarMovimientosSesion($sessionId);
            $movsRaw = $totalMovs > 0
                ? $this->cajaModel->listarMovimientosSesionPaginados($sessionId, $pageSize, $offset)
                : [];
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $autorizaciones = array_map(
            fn (array $a): array => $this->proyectarAutorizacionCaja($a, true),
            $autorizacionesRaw
        );

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Detalle de sesión obtenido correctamente.',
            'data'    => [
                'type'           => 'result',
                'session'        => $this->proyectarCabeceraSesionAdmin($detalle),
                'summary'        => $this->proyectarSummaryMonetarioSesion($detalle, true),
                'authorizations' => $autorizaciones,
                'movements'      => array_map([$this, 'proyectarMovimientoConsulta'], $movsRaw),
                'pagination'     => $this->construirPaginacion($totalMovs, $page, $pageSize, 'total_movements'),
            ],
        ];
    }

    /**
     * EP-24A: Reporte administrativo de cierres de caja con snapshot inmutable y expiración de 30 minutos (HU 19).
     */
    public function reporteCierresCaja(array $queryParams, int $idAdminAutenticado): array
    {
        $this->validarQueryParamsPermitidos($queryParams, [
            'desde',
            'hasta',
            'turno_id',
            'turno',
            'vendedor_id',
            'vendedor',
            'snapshot_id',
            'page',
            'page_size',
            'limit',
        ]);

        [$page, $pageSize, $offset] = $this->resolverPaginacion($queryParams, 10, 100);
        $ahora = $this->ahoraLima();
        $ahoraDb = $ahora->format('Y-m-d H:i:s');
        $nuevaExpiracion = $ahora->modify('+30 minutes')->format('Y-m-d H:i:s');

        if (array_key_exists('snapshot_id', $queryParams)) {
            $snapshotId = $this->validarEnteroPositivoEstricto($queryParams['snapshot_id'], 'snapshot_id');
            $snapshot = $this->cajaModel->obtenerSnapshotCierresCajaPorIdParaUsuario($snapshotId, $idAdminAutenticado);
            if ($snapshot === null) {
                throw new InventarioException(
                    'El snapshot de consulta solicitado no existe o no está disponible.',
                    'SNAPSHOT_NOT_FOUND',
                    404
                );
            }

            $expiraDt = new DateTimeImmutable((string)$snapshot['expira_en'], new DateTimeZone(self::LIMA_TZ));
            if ($expiraDt <= $ahora) {
                throw new InventarioException(
                    'El snapshot de consulta ha expirado. Ejecute la consulta nuevamente.',
                    'SNAPSHOT_NOT_FOUND',
                    404
                );
            }

            $filtrosSnapshot = json_decode((string)$snapshot['filtros_json'], true);
            if (!is_array($filtrosSnapshot)) {
                $filtrosSnapshot = [];
            }

            $filtrosSolicitados = $this->normalizarFiltrosReporteCierres($queryParams, $ahora, $filtrosSnapshot);
            $hashSolicitado = hash('sha256', json_encode($filtrosSolicitados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!hash_equals((string)$snapshot['hash_filtros'], $hashSolicitado)) {
                throw new InventarioException(
                    'Los filtros proporcionados no coinciden con el snapshot_id indicado.',
                    'SNAPSHOT_FILTER_MISMATCH',
                    409,
                    ['snapshot_id' => ['Los filtros no corresponden al snapshot indicado.']]
                );
            }

            $pdo = $this->pdo;
            $pdo->beginTransaction();
            try {
                $this->cajaModel->renovarExpiracionSnapshot($snapshotId, $nuevaExpiracion);
                $itemsRaw = $this->cajaModel->listarItemsSnapshotCierresCaja($snapshotId, $pageSize, $offset);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $closures = [];
            foreach ($itemsRaw as $itemRaw) {
                $decoded = json_decode((string)$itemRaw['proyeccion_json'], true);
                if (is_array($decoded)) {
                    $closures[] = $decoded;
                }
            }

            $totals = json_decode((string)$snapshot['resumen_json'], true);
            $totalRegistros = (int)$snapshot['total_registros'];

            return [
                'status'  => true,
                'code'    => 'OK',
                'message' => 'Reporte de cierres de caja obtenido correctamente.',
                'data'    => [
                    'snapshot_id'  => $snapshotId,
                    'generated_at' => $this->formatearIsoLima((string)$snapshot['creado_en']),
                    'expires_at'   => $this->formatearIsoLima($nuevaExpiracion),
                    'filters'      => $filtrosSnapshot,
                    'totals'       => is_array($totals) ? $totals : [],
                    'closures'     => $closures,
                    'pagination'   => $this->construirPaginacion($totalRegistros, $page, $pageSize, 'total_closures'),
                ],
            ];
        }

        $filtros = $this->normalizarFiltrosReporteCierres($queryParams, $ahora, null);
        $hashFiltros = hash('sha256', json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            $sesionesRaw = $this->cajaModel->listarSesionesCerradasParaReporte(
                $filtros['desde'],
                $filtros['hasta'],
                $filtros['vendedor_id'],
                $filtros['turno_id']
            );

            $allClosures = [];
            $openingFundCents = 0;
            $openingDiffCents = 0;
            $cashSalesCents = 0;
            $cardSalesCents = 0;
            $yapePlinSalesCents = 0;
            $incomeCents = 0;
            $expensesCents = 0;
            $expectedCashCents = 0;
            $countedCashCents = 0;
            $closingDiffCents = 0;
            $snapshotItems = [];

            foreach ($sesionesRaw as $s) {
                $idSesion = (int)$s['id_sesion_caja'];
                $sOpening = self::parseDecimalACentimos((string)$s['saldo_inicial']);
                $sOpenDiff = $s['diferencia_apertura'] !== null
                    ? self::parseDecimalACentimos((string)$s['diferencia_apertura'])
                    : null;
                $sCashSales = self::parseDecimalACentimos((string)$s['total_ventas_efectivo']);
                $sCardSales = self::parseDecimalACentimos((string)$s['total_ventas_tarjeta']);
                $sYapeSales = self::parseDecimalACentimos((string)$s['total_ventas_yape_plin']);
                $sIncome = self::parseDecimalACentimos((string)$s['total_ingresos']);
                $sExpenses = self::parseDecimalACentimos((string)$s['total_egresos']);
                $sExpected = self::parseDecimalACentimos((string)($s['efectivo_esperado_cierre'] ?? '0.00'));
                $sCounted = self::parseDecimalACentimos((string)($s['efectivo_contado_cierre'] ?? '0.00'));
                $sCloseDiff = self::parseDecimalACentimos((string)($s['diferencia_cierre'] ?? '0.00'));

                $openingFundCents += $sOpening;
                if ($sOpenDiff !== null) {
                    $openingDiffCents += $sOpenDiff;
                }
                $cashSalesCents += $sCashSales;
                $cardSalesCents += $sCardSales;
                $yapePlinSalesCents += $sYapeSales;
                $incomeCents += $sIncome;
                $expensesCents += $sExpenses;
                $expectedCashCents += $sExpected;
                $countedCashCents += $sCounted;
                $closingDiffCents += $sCloseDiff;

                $cierreDt = new DateTimeImmutable((string)$s['fecha_hora_cierre'], new DateTimeZone(self::LIMA_TZ));
                $authorizedByAdmin = $s['authorized_by_admin'] !== null ? (string)$s['authorized_by_admin'] : null;
                $hasOmitted = ((int)($s['omitted_withdrawals_count'] ?? 0) > 0)
                    || self::parseDecimalACentimos((string)$s['total_retiros_omitidos_declarados']) > 0;

                $closureProjection = [
                    'id'                            => $idSesion,
                    'session_id'                    => $idSesion,
                    'cashbox_id'                    => (int)$s['id_caja_fisica'],
                    'code'                          => sprintf('CIE-%04d', $idSesion),
                    'opened_at'                     => $this->formatearIsoLima((string)$s['fecha_hora_apertura']),
                    'closed_at'                     => $this->formatearIsoLima((string)$s['fecha_hora_cierre']),
                    'closing_date'                  => $cierreDt->format('Y-m-d'),
                    'closing_time'                  => $cierreDt->format('H:i'),
                    'shift_id'                      => (int)$s['id_turno'],
                    'turn'                          => (string)$s['nombre_turno_referencia'],
                    'seller_id'                     => (int)$s['id_usuario_vendedor'],
                    'seller'                        => (string)$s['seller_name'],
                    'has_previous_close'            => (int)$s['tiene_cierre_anterior'] === 1,
                    'reference_amount'              => $s['monto_referencia_cierre_anterior'] !== null
                        ? $this->decimalDbANumero((string)$s['monto_referencia_cierre_anterior'])
                        : null,
                    'opening_fund'                  => round($sOpening / 100, 2),
                    'opening_difference'            => $sOpenDiff !== null ? round($sOpenDiff / 100, 2) : null,
                    'cash_sales'                    => round($sCashSales / 100, 2),
                    'card_sales'                    => round($sCardSales / 100, 2),
                    'yape_plin_sales'               => round($sYapeSales / 100, 2),
                    'income'                        => round($sIncome / 100, 2),
                    'expenses'                      => round($sExpenses / 100, 2),
                    'expected'                      => round($sExpected / 100, 2),
                    'counted'                       => round($sCounted / 100, 2),
                    'closing_difference'            => round($sCloseDiff / 100, 2),
                    'observation'                   => $s['observacion_cierre'] !== null ? (string)$s['observacion_cierre'] : null,
                    'has_early_close_authorization' => $authorizedByAdmin !== null,
                    'authorized_by_admin'           => $authorizedByAdmin,
                    'has_omitted_withdrawals'       => $hasOmitted,
                ];
                $allClosures[] = $closureProjection;
                $snapshotItem = $closureProjection;
                $snapshotItem['movements'] = array_map(
                    [$this, 'proyectarMovimientoConsulta'],
                    $this->cajaModel->listarMovimientosSesionCompletos($idSesion)
                );
                $snapshotItems[] = $snapshotItem;
            }

            $totalRegistros = count($allClosures);
            $totals = [
                'total_closures'         => $totalRegistros,
                'opening_fund_total'     => round($openingFundCents / 100, 2),
                'opening_difference_net' => round($openingDiffCents / 100, 2),
                'cash_sales_total'       => round($cashSalesCents / 100, 2),
                'card_sales_total'       => round($cardSalesCents / 100, 2),
                'yape_plin_sales_total'  => round($yapePlinSalesCents / 100, 2),
                'income_total'           => round($incomeCents / 100, 2),
                'expenses_total'         => round($expensesCents / 100, 2),
                'expected_cash_total'    => round($expectedCashCents / 100, 2),
                'counted_cash_total'     => round($countedCashCents / 100, 2),
                'closing_difference_net' => round($closingDiffCents / 100, 2),
            ];

            $referenciaSnapshot = 'SNAP-CIE-' . bin2hex(random_bytes(8));
            $snapshotId = $this->cajaModel->crearSnapshotCierresCaja([
                'referencia_consulta' => $referenciaSnapshot,
                'id_usuario'          => $idAdminAutenticado,
                'hash_filtros'        => $hashFiltros,
                'filtros_json'        => json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'resumen_json'        => json_encode($totals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'total_registros'     => $totalRegistros,
                'exportable'          => $totalRegistros > 0 ? 1 : 0,
                'creado_en'           => $ahoraDb,
                'expira_en'           => $nuevaExpiracion,
            ], $snapshotItems);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $closures = array_slice($allClosures, $offset, $pageSize);

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Reporte de cierres de caja obtenido correctamente.',
            'data'    => [
                'snapshot_id'  => $snapshotId,
                'generated_at' => $this->formatearIsoLima($ahoraDb),
                'expires_at'   => $this->formatearIsoLima($nuevaExpiracion),
                'filters'      => $filtros,
                'totals'       => $totals,
                'closures'     => $closures,
                'pagination'   => $this->construirPaginacion($totalRegistros, $page, $pageSize, 'total_closures'),
            ],
        ];
    }

    /** EP-24C/D: conjunto completo y coherente de datos para los dos generadores. */
    public function reporteCierresCajaParaExportacion(array $queryParams, int $idAdminAutenticado): array
    {
        $this->validarQueryParamsPermitidos($queryParams, [
            'tipo', 'snapshot_id', 'desde', 'hasta', 'turno_id', 'turno',
            'vendedor_id', 'vendedor', 'session_id', 'sesion_id',
        ]);
        if (($queryParams['tipo'] ?? null) !== 'cierres-caja') {
            throw new InventarioException('El tipo de reporte solicitado no es válido.', 'VALIDATION_ERROR', 422);
        }

        $rawSessionId = $this->resolverAliasQuery($queryParams, 'session_id', 'sesion_id');
        if ($rawSessionId !== null) {
            if (array_key_exists('snapshot_id', $queryParams)) {
                throw new InventarioException(
                    'La exportación por sesión no puede combinarse con un snapshot de listado.',
                    'SNAPSHOT_FILTER_MISMATCH',
                    409
                );
            }
            return $this->reporteSesionCerradaParaExportacion(
                $this->validarEnteroPositivoEstricto($rawSessionId, 'session_id'),
                $queryParams
            );
        }

        $consultaParams = $queryParams;
        unset($consultaParams['tipo'], $consultaParams['session_id'], $consultaParams['sesion_id']);
        $consultaParams['page'] = 1;
        $consultaParams['page_size'] = 1;
        $consulta = $this->reporteCierresCaja($consultaParams, $idAdminAutenticado);
        $data = $consulta['data'] ?? null;
        $snapshotId = (int)($data['snapshot_id'] ?? 0);
        if ($snapshotId < 1) {
            throw new \RuntimeException('No se pudo obtener el snapshot para la exportación.');
        }

        $itemsRaw = $this->cajaModel->listarTodosItemsSnapshotCierresCaja($snapshotId);
        $closures = [];
        foreach ($itemsRaw as $itemRaw) {
            $projection = json_decode((string)$itemRaw['proyeccion_json'], true);
            if (!is_array($projection) || !isset($projection['movements']) || !is_array($projection['movements'])) {
                throw new InventarioException(
                    'El snapshot no contiene el detalle de movimientos necesario. Actualice la consulta antes de exportar.',
                    'SNAPSHOT_NOT_FOUND',
                    404
                );
            }
            $closures[] = $projection;
        }
        if (count($closures) !== (int)($data['pagination']['total_closures'] ?? 0)) {
            throw new \RuntimeException('El conjunto congelado del reporte está incompleto.');
        }

        $totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];
        $totals = $this->completarTotalesExportacion($closures, $totals);
        return [
            'filters' => $data['filters'] ?? [],
            'generated_at' => $data['generated_at'] ?? $this->formatearIsoLima($this->ahoraLima()->format('Y-m-d H:i:s')),
            'closures' => $closures,
            'totals' => $totals,
        ];
    }

    private function reporteSesionCerradaParaExportacion(int $sessionId, array $queryParams): array
    {
        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            $row = $this->cajaModel->obtenerSesionDetallePorId($sessionId);
            if ($row === null) {
                throw new InventarioException('El cierre solicitado no existe.', 'CASH_SESSION_NOT_FOUND', 404);
            }
            if ((string)$row['estado'] !== 'CERRADA') {
                throw new InventarioException('La sesión solicitada aún no ha sido cerrada.', 'CASH_SESSION_NOT_CLOSED', 409);
            }
            $movements = array_map(
                [$this, 'proyectarMovimientoConsulta'],
                $this->cajaModel->listarMovimientosSesionCompletos($sessionId)
            );
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $closure = [
            'id' => $sessionId,
            'session_id' => $sessionId,
            'cashbox_id' => (int)$row['id_caja_fisica'],
            'code' => sprintf('CIE-%04d', $sessionId),
            'seller_id' => (int)$row['id_usuario_vendedor'],
            'seller' => (string)$row['seller_name'],
            'shift_id' => (int)$row['id_turno'],
            'turn' => (string)$row['nombre_turno_referencia'],
            'opened_at' => $this->formatearIsoLima((string)$row['fecha_hora_apertura']),
            'closed_at' => $this->formatearIsoLima((string)$row['fecha_hora_cierre']),
            'closing_date' => (new DateTimeImmutable((string)$row['fecha_hora_cierre'], new DateTimeZone(self::LIMA_TZ)))->format('Y-m-d'),
            'closing_time' => (new DateTimeImmutable((string)$row['fecha_hora_cierre'], new DateTimeZone(self::LIMA_TZ)))->format('H:i'),
            'opening_fund' => $this->decimalDbANumero((string)$row['saldo_inicial']),
            'opening_difference' => $row['diferencia_apertura'] !== null ? $this->decimalDbANumero((string)$row['diferencia_apertura']) : null,
            'cash_sales' => $this->decimalDbANumero((string)$row['total_ventas_efectivo']),
            'card_sales' => $this->decimalDbANumero((string)$row['total_ventas_tarjeta']),
            'yape_plin_sales' => $this->decimalDbANumero((string)$row['total_ventas_yape_plin']),
            'income' => $this->decimalDbANumero((string)$row['total_ingresos']),
            'expenses' => $this->decimalDbANumero((string)$row['total_egresos']),
            'expected' => $this->decimalDbANumero((string)$row['efectivo_esperado_cierre']),
            'counted' => $this->decimalDbANumero((string)$row['efectivo_contado_cierre']),
            'closing_difference' => $this->decimalDbANumero((string)$row['diferencia_cierre']),
            'observation' => $row['observacion_cierre'] !== null ? (string)$row['observacion_cierre'] : null,
            'has_omitted_withdrawals' => count(array_filter($movements, static fn(array $m): bool => ($m['type'] ?? '') === 'omitted_withdrawal')) > 0,
            'movements' => $movements,
        ];
        $totals = $this->completarTotalesExportacion([$closure], [
            'total_closures' => 1,
            'opening_fund_total' => $closure['opening_fund'],
            'opening_difference_net' => $closure['opening_difference'] ?? 0,
            'cash_sales_total' => $closure['cash_sales'],
            'card_sales_total' => $closure['card_sales'],
            'yape_plin_sales_total' => $closure['yape_plin_sales'],
            'income_total' => $closure['income'],
            'expenses_total' => $closure['expenses'],
            'expected_cash_total' => $closure['expected'],
            'counted_cash_total' => $closure['counted'],
            'closing_difference_net' => $closure['closing_difference'],
        ]);
        return [
            'filters' => ['session_id' => $sessionId],
            'generated_at' => $this->formatearIsoLima($this->ahoraLima()->format('Y-m-d H:i:s')),
            'closures' => [$closure],
            'totals' => $totals,
        ];
    }

    private function completarTotalesExportacion(array $closures, array $totals): array
    {
        $totals['total_closures'] = count($closures);
        $totals['closures_with_difference'] = 0;
        $totals['shortages_total'] = 0.0;
        $totals['surpluses_total'] = 0.0;
        $totals['opening_difference_applicable_count'] = 0;
        foreach ($closures as $closure) {
            $difference = round((float)($closure['closing_difference'] ?? 0), 2);
            if ($difference !== 0.0) {
                $totals['closures_with_difference']++;
            }
            if ($difference < 0) {
                $totals['shortages_total'] = round($totals['shortages_total'] + abs($difference), 2);
            } elseif ($difference > 0) {
                $totals['surpluses_total'] = round($totals['surpluses_total'] + $difference, 2);
            }
            if (array_key_exists('opening_difference', $closure) && $closure['opening_difference'] !== null) {
                $totals['opening_difference_applicable_count']++;
            }
        }
        return $totals;
    }

    /**
     * EP-24B: Detalle completo de un cierre de caja específico (HU 19).
     */
    public function detalleCierreCaja($rawId, array $queryParams): array
    {
        $this->validarQueryParamsPermitidos($queryParams, ['page', 'page_size', 'limit']);
        $sessionId = $this->validarEnteroPositivoEstricto($rawId, 'id');
        [$page, $pageSize, $offset] = $this->resolverPaginacion($queryParams, 20, 100);

        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            $detalle = $this->cajaModel->obtenerSesionDetallePorId($sessionId);
            if ($detalle === null) {
                $pdo->commit();
                throw new InventarioException(
                    'El cierre de caja solicitado no existe.',
                    'CASH_SESSION_NOT_FOUND',
                    404
                );
            }

            if ((string)$detalle['estado'] !== 'CERRADA') {
                $pdo->commit();
                throw new InventarioException(
                    'La sesión solicitada aún no ha sido cerrada.',
                    'CASH_SESSION_NOT_CLOSED',
                    409
                );
            }

            $autorizacionesRaw = $this->cajaModel->listarAutorizacionesPorSesion($sessionId);
            $arqueosRaw = $this->cajaModel->listarArqueosPorSesionAsc($sessionId);
            $totalMovs = $this->cajaModel->contarMovimientosSesion($sessionId);
            $movsRaw = $totalMovs > 0
                ? $this->cajaModel->listarMovimientosSesionPaginados($sessionId, $pageSize, $offset)
                : [];
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $autorizaciones = array_map(
            fn (array $a): array => $this->proyectarAutorizacionCaja($a, false),
            $autorizacionesRaw
        );
        $closure = [
            'id'                 => (int)$detalle['id_sesion_caja'],
            'session_id'         => (int)$detalle['id_sesion_caja'],
            'code'               => sprintf('CIE-%04d', (int)$detalle['id_sesion_caja']),
            'seller_id'          => (int)$detalle['id_usuario_vendedor'],
            'seller'             => (string)$detalle['seller_name'],
            'shift_id'           => (int)$detalle['id_turno'],
            'turn'               => (string)$detalle['nombre_turno_referencia'],
            'reference_schedule' => $this->proyectarReferenceSchedule($detalle),
            'opened_at'          => $this->formatearIsoLima((string)$detalle['fecha_hora_apertura']),
            'closed_at'          => $this->formatearIsoLima((string)$detalle['fecha_hora_cierre']),
            'has_previous_close' => (int)$detalle['tiene_cierre_anterior'] === 1,
            'reference_amount'   => $detalle['monto_referencia_cierre_anterior'] !== null
                ? $this->decimalDbANumero((string)$detalle['monto_referencia_cierre_anterior'])
                : null,
            'opening_fund'       => $this->decimalDbANumero((string)$detalle['saldo_inicial']),
            'opening_difference' => $detalle['diferencia_apertura'] !== null
                ? $this->decimalDbANumero((string)$detalle['diferencia_apertura'])
                : null,
            'cash_sales'         => $this->decimalDbANumero((string)$detalle['total_ventas_efectivo']),
            'card_sales'         => $this->decimalDbANumero((string)$detalle['total_ventas_tarjeta']),
            'yape_plin_sales'    => $this->decimalDbANumero((string)$detalle['total_ventas_yape_plin']),
            'income'             => $this->decimalDbANumero((string)$detalle['total_ingresos']),
            'expenses'           => $this->decimalDbANumero((string)$detalle['total_egresos']),
            'expected'           => $this->decimalDbANumero((string)($detalle['efectivo_esperado_cierre'] ?? $detalle['efectivo_esperado_actual'])),
            'counted'            => $this->decimalDbANumero((string)($detalle['efectivo_contado_cierre'] ?? '0.00')),
            'closing_difference' => $this->decimalDbANumero((string)($detalle['diferencia_cierre'] ?? '0.00')),
            'observation'        => $detalle['observacion_cierre'] !== null ? (string)$detalle['observacion_cierre'] : null,
        ];

        $arqueos = array_map(function (array $row): array {
            $item = $this->formatearArqueoResource($row);
            return [
                'id'          => $item['id'],
                'recorded_at' => $item['recorded_at'],
                'expected'    => $item['expected'],
                'counted'     => $item['counted'],
                'difference'  => $item['difference'],
                'observation' => $item['observation'],
                'status'      => $item['status'],
                'version'     => $item['version'],
            ];
        }, $arqueosRaw);

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Detalle de cierre de caja obtenido correctamente.',
            'data'    => [
                'closure'       => $closure,
                'authorization' => !empty($autorizaciones) ? $autorizaciones[count($autorizaciones) - 1] : null,
                'arqueos'       => $arqueos,
                'movements'     => array_map([$this, 'proyectarMovimientoConsulta'], $movsRaw),
                'pagination'    => $this->construirPaginacion($totalMovs, $page, $pageSize, 'total_movements'),
            ],
        ];
    }

    // =========================================================================
    // HELPERS PRIVADOS PASO 12
    // =========================================================================

    private function validarQueryParamsPermitidos(array $queryParams, array $permitidos): void
    {
        $errores = [];
        foreach (array_keys($queryParams) as $key) {
            $k = (string)$key;
            if ($k === 'url') {
                continue;
            }
            if (!in_array($k, $permitidos, true)) {
                $errores[$k] = ["El parámetro de consulta '{$key}' no está permitido."];
            }
        }
        if (!empty($errores)) {
            throw new InventarioException(
                'Parámetros de consulta no permitidos.',
                'VALIDATION_ERROR',
                422,
                $errores
            );
        }
    }

    private function resolverAliasQuery(array $queryParams, string $canonico, string $alias)
    {
        $hasCanonico = array_key_exists($canonico, $queryParams);
        $hasAlias = array_key_exists($alias, $queryParams);
        if ($hasCanonico && $hasAlias) {
            if (is_array($queryParams[$canonico]) || is_array($queryParams[$alias])) {
                throw new InventarioException(
                    "Los parámetros {$canonico} y {$alias} no son válidos.",
                    'VALIDATION_ERROR',
                    422,
                    [$canonico => ["El parámetro {$canonico} debe ser escalar."]]
                );
            }
            if (trim((string)$queryParams[$canonico]) !== trim((string)$queryParams[$alias])) {
                throw new InventarioException(
                    "Los parámetros {$canonico} y {$alias} no coinciden.",
                    'AMBIGUOUS_ALIAS_VALUES',
                    422,
                    [$canonico => ["Los alias {$canonico} y {$alias} deben tener el mismo valor."]]
                );
            }
        }
        return $hasCanonico ? $queryParams[$canonico] : ($hasAlias ? $queryParams[$alias] : null);
    }

    private function validarEnteroPositivoEstricto($rawVal, string $fieldName): int
    {
        if ($rawVal === null || is_bool($rawVal) || is_array($rawVal) || is_object($rawVal) || is_float($rawVal)) {
            throw new InventarioException(
                "El parámetro {$fieldName} debe ser un entero positivo.",
                'VALIDATION_ERROR',
                422,
                [$fieldName => ["El parámetro {$fieldName} debe ser un entero positivo."]]
            );
        }

        if (is_int($rawVal)) {
            if ($rawVal < 1) {
                throw new InventarioException(
                    "El parámetro {$fieldName} debe ser un entero positivo.",
                    'VALIDATION_ERROR',
                    422,
                    [$fieldName => ["El parámetro {$fieldName} debe ser mayor o igual a 1."]]
                );
            }
            return $rawVal;
        }

        $trimmed = trim((string)$rawVal);
        if ($trimmed === '' || !preg_match('/^\d+$/', $trimmed)) {
            throw new InventarioException(
                "El parámetro {$fieldName} debe ser un entero positivo.",
                'VALIDATION_ERROR',
                422,
                [$fieldName => ["El parámetro {$fieldName} debe ser un entero positivo."]]
            );
        }

        $sinCeros = ltrim($trimmed, '0');
        if ($sinCeros === '') {
            throw new InventarioException(
                "El parámetro {$fieldName} debe ser mayor o igual a 1.",
                'VALIDATION_ERROR',
                422,
                [$fieldName => ["El parámetro {$fieldName} debe ser mayor o igual a 1."]]
            );
        }

        $maxStr = (string)PHP_INT_MAX;
        if (strlen($sinCeros) > strlen($maxStr) || (strlen($sinCeros) === strlen($maxStr) && strcmp($sinCeros, $maxStr) > 0)) {
            throw new InventarioException(
                "El parámetro {$fieldName} excede el rango entero permitido.",
                'VALIDATION_ERROR',
                422,
                [$fieldName => ["El parámetro {$fieldName} excede el límite máximo permitido."]]
            );
        }

        return (int)$sinCeros;
    }

    private function resolverPaginacion(array $queryParams, int $defaultSize, int $maxSize): array
    {
        $page = 1;
        if (array_key_exists('page', $queryParams)) {
            $page = $this->validarEnteroPositivoEstricto($queryParams['page'], 'page');
        }

        $pageSize = $defaultSize;
        $rawPageSize = $this->resolverAliasQuery($queryParams, 'page_size', 'limit');
        if ($rawPageSize !== null || array_key_exists('page_size', $queryParams) || array_key_exists('limit', $queryParams)) {
            $pageSize = $this->validarEnteroPositivoEstricto($rawPageSize, 'page_size');
            if ($pageSize > $maxSize) {
                throw new InventarioException(
                    "El parámetro page_size no puede ser mayor a {$maxSize}.",
                    'VALIDATION_ERROR',
                    422,
                    ['page_size' => ["El parámetro page_size debe estar entre 1 y {$maxSize}."]]
                );
            }
        }

        if (($page - 1) > intdiv(PHP_INT_MAX, $pageSize)) {
            throw new InventarioException(
                'El número de página solicitado excede el límite permitido.',
                'VALIDATION_ERROR',
                422,
                ['page' => ['El parámetro page excede el límite permitido para el tamaño de página.']]
            );
        }

        return [$page, $pageSize, ($page - 1) * $pageSize];
    }

    private function construirPaginacion(int $totalItems, int $page, int $pageSize, string $contextField): array
    {
        $totalPages = $totalItems > 0 ? (int)ceil($totalItems / $pageSize) : 0;
        return [
            'page'        => $page,
            'page_size'   => $pageSize,
            'total_pages' => $totalPages,
            'total_items' => $totalItems,
            $contextField => $totalItems,
        ];
    }

    private function validarFechaIso($rawDate, string $campo, string $errorCode = 'VALIDATION_ERROR'): string
    {
        if (is_array($rawDate) || is_object($rawDate) || is_bool($rawDate)) {
            throw new InventarioException(
                "El campo {$campo} debe tener formato YYYY-MM-DD.",
                $errorCode,
                422,
                [$campo => ["El campo {$campo} debe tener formato YYYY-MM-DD."]]
            );
        }
        $trimmed = trim((string)$rawDate);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            throw new InventarioException(
                "El campo {$campo} debe tener formato YYYY-MM-DD.",
                $errorCode,
                422,
                [$campo => ["El campo {$campo} debe tener formato YYYY-MM-DD."]]
            );
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed, new DateTimeZone(self::LIMA_TZ));
        if ($dt === false || $dt->format('Y-m-d') !== $trimmed) {
            throw new InventarioException(
                "El campo {$campo} no es una fecha válida.",
                $errorCode,
                422,
                [$campo => ["El campo {$campo} no es una fecha válida."]]
            );
        }

        return $trimmed;
    }

    private function normalizarFiltrosReporteCierres(array $queryParams, DateTimeImmutable $ahora, ?array $snapshotFilters): array
    {
        $defaultDesde = $snapshotFilters !== null
            ? (string)($snapshotFilters['desde'] ?? $ahora->format('Y-m-01'))
            : $ahora->format('Y-m-01');
        $defaultHasta = $snapshotFilters !== null
            ? (string)($snapshotFilters['hasta'] ?? $ahora->format('Y-m-d'))
            : $ahora->format('Y-m-d');

        if (array_key_exists('desde', $queryParams)) {
            $desde = $this->validarFechaIso($queryParams['desde'], 'desde', 'INVALID_DATE_RANGE');
        } else {
            $desde = $defaultDesde;
        }

        if (array_key_exists('hasta', $queryParams)) {
            $hasta = $this->validarFechaIso($queryParams['hasta'], 'hasta', 'INVALID_DATE_RANGE');
        } else {
            $hasta = $defaultHasta;
        }

        if ($desde > $hasta) {
            throw new InventarioException(
                'La fecha inicial (desde) no puede ser mayor que la fecha final (hasta).',
                'INVALID_DATE_RANGE',
                422,
                ['desde' => ['La fecha inicial (desde) no puede ser mayor que la fecha final (hasta).']]
            );
        }

        $rawTurnoId = $this->resolverAliasQuery($queryParams, 'turno_id', 'turno');
        $hasTurnoKey = array_key_exists('turno_id', $queryParams) || array_key_exists('turno', $queryParams);
        if ($hasTurnoKey) {
            $turnoId = $this->validarEnteroPositivoEstricto($rawTurnoId, 'turno_id');
        } else {
            $turnoId = $snapshotFilters !== null && array_key_exists('turno_id', $snapshotFilters)
                ? $snapshotFilters['turno_id']
                : null;
        }

        $rawVendedorId = $this->resolverAliasQuery($queryParams, 'vendedor_id', 'vendedor');
        $hasVendedorKey = array_key_exists('vendedor_id', $queryParams) || array_key_exists('vendedor', $queryParams);
        if ($hasVendedorKey) {
            $vendedorId = $this->validarEnteroPositivoEstricto($rawVendedorId, 'vendedor_id');
        } else {
            $vendedorId = $snapshotFilters !== null && array_key_exists('vendedor_id', $snapshotFilters)
                ? $snapshotFilters['vendedor_id']
                : null;
        }

        return [
            'desde'       => $desde,
            'hasta'       => $hasta,
            'turno_id'    => $turnoId !== null ? (int)$turnoId : null,
            'vendedor_id' => $vendedorId !== null ? (int)$vendedorId : null,
        ];
    }

    private function formatearEstadoPublicoSesion(string $estadoDb): string
    {
        return $estadoDb === 'CERRADA' ? 'Cerrada' : 'Abierta';
    }

    private function proyectarReferenceSchedule(array $detalle): array
    {
        $turnName = (string)$detalle['nombre_turno_referencia'];
        $start = (string)$detalle['hora_inicio_referencia'];
        $end = (string)$detalle['hora_fin_referencia'];

        return [
            'shift_id'   => (int)$detalle['id_turno'],
            'shift_name' => $turnName,
            'start'      => $start,
            'end'        => $end,
            'label'      => sprintf('%s · %s–%s', $turnName, $start, $end),
        ];
    }

    private function proyectarCabeceraSesionActiva(array $detalle): array
    {
        return [
            'id'                 => (int)$detalle['id_sesion_caja'],
            'session_id'         => (int)$detalle['id_sesion_caja'],
            'seller_id'          => (int)$detalle['id_usuario_vendedor'],
            'seller'             => (string)$detalle['seller_name'],
            'turn'               => (string)$detalle['nombre_turno_referencia'],
            'reference_schedule' => $this->proyectarReferenceSchedule($detalle),
            'status'             => $this->formatearEstadoPublicoSesion((string)$detalle['estado']),
            'opened_at'          => $this->formatearIsoLima((string)$detalle['fecha_hora_apertura']),
            'closed_at'          => $detalle['fecha_hora_cierre'] !== null ? $this->formatearIsoLima((string)$detalle['fecha_hora_cierre']) : null,
            'opening_difference' => $detalle['diferencia_apertura'] !== null
                ? $this->decimalDbANumero((string)$detalle['diferencia_apertura'])
                : null,
            'version'            => (int)$detalle['version_caja'],
        ];
    }

    private function proyectarCabeceraSesionAdmin(array $detalle): array
    {
        return [
            'id'                 => (int)$detalle['id_sesion_caja'],
            'session_id'         => (int)$detalle['id_sesion_caja'],
            'seller_id'          => (int)$detalle['id_usuario_vendedor'],
            'seller'             => (string)$detalle['seller_name'],
            'turn_id'            => (int)$detalle['id_turno'],
            'turn'               => (string)$detalle['nombre_turno_referencia'],
            'reference_schedule' => $this->proyectarReferenceSchedule($detalle),
            'status'             => $this->formatearEstadoPublicoSesion((string)$detalle['estado']),
            'opened_at'          => $this->formatearIsoLima((string)$detalle['fecha_hora_apertura']),
            'closed_at'          => $detalle['fecha_hora_cierre'] !== null ? $this->formatearIsoLima((string)$detalle['fecha_hora_cierre']) : null,
            'has_previous_close' => (int)$detalle['tiene_cierre_anterior'] === 1,
            'reference_amount'   => $detalle['monto_referencia_cierre_anterior'] !== null
                ? $this->decimalDbANumero((string)$detalle['monto_referencia_cierre_anterior'])
                : null,
            'opening_balance'    => $this->decimalDbANumero((string)$detalle['saldo_inicial']),
            'opening_difference' => $detalle['diferencia_apertura'] !== null
                ? $this->decimalDbANumero((string)$detalle['diferencia_apertura'])
                : null,
            'counted_at_close'   => $detalle['efectivo_contado_cierre'] !== null
                ? $this->decimalDbANumero((string)$detalle['efectivo_contado_cierre'])
                : null,
            'closing_difference' => $detalle['diferencia_cierre'] !== null
                ? $this->decimalDbANumero((string)$detalle['diferencia_cierre'])
                : null,
            'version'            => (int)$detalle['version_caja'],
        ];
    }

    private function proyectarSummaryMonetarioSesion(array $detalle, bool $incluirCierre): array
    {
        $summary = [
            'opening_balance' => $this->decimalDbANumero((string)$detalle['saldo_inicial']),
            'cash_sales'      => $this->decimalDbANumero((string)$detalle['total_ventas_efectivo']),
            'income'          => $this->decimalDbANumero((string)$detalle['total_ingresos']),
            'expenses'        => $this->decimalDbANumero((string)$detalle['total_egresos']),
            'current_balance' => $this->decimalDbANumero((string)$detalle['efectivo_esperado_actual']),
            'card'            => $this->decimalDbANumero((string)$detalle['total_ventas_tarjeta']),
            'yape_plin'       => $this->decimalDbANumero((string)$detalle['total_ventas_yape_plin']),
        ];

        if ($incluirCierre) {
            $summary = [
                'opening_balance'    => $summary['opening_balance'],
                'cash_sales'         => $summary['cash_sales'],
                'income'             => $summary['income'],
                'expenses'           => $summary['expenses'],
                'current_balance'    => $summary['current_balance'],
                'expected_cash'      => $detalle['efectivo_esperado_cierre'] !== null
                    ? $this->decimalDbANumero((string)$detalle['efectivo_esperado_cierre'])
                    : $this->decimalDbANumero((string)$detalle['efectivo_esperado_actual']),
                'counted_cash'       => $detalle['efectivo_contado_cierre'] !== null
                    ? $this->decimalDbANumero((string)$detalle['efectivo_contado_cierre'])
                    : null,
                'closing_difference' => $detalle['diferencia_cierre'] !== null
                    ? $this->decimalDbANumero((string)$detalle['diferencia_cierre'])
                    : null,
                'card'               => $summary['card'],
                'yape_plin'          => $summary['yape_plin'],
            ];
        }

        return $summary;
    }

    private function proyectarAutorizacionCaja(array $a, bool $incluirSesionYVendedor): array
    {
        $out = [
            'id' => (int)$a['id_autorizacion_caja'],
        ];
        if ($incluirSesionYVendedor) {
            $out['session_id'] = (int)$a['id_sesion_caja'];
        }
        $out['operation'] = (string)$a['tipo_operacion'];
        if ($incluirSesionYVendedor) {
            $out['seller_id'] = (int)$a['id_usuario_vendedor'];
            $out['seller_name'] = (string)$a['seller_name'];
        }
        $out['administrator_id'] = (int)$a['id_usuario_admin'];
        $out['administrator_name'] = (string)$a['admin_name'];
        $out['administrator_email'] = (string)$a['administrator_email'];
        $out['reason'] = (string)$a['motivo'];
        $out['scheduled_end'] = (string)$a['hora_salida_referencia'];
        $out['actual_close_time'] = (string)$a['hora_cierre_real'];
        $out['minutes_before_end'] = (int)$a['minutos_anticipacion'];
        $out['authorized_at'] = $this->formatearIsoLima((string)$a['fecha_hora_autorizacion']);

        return $out;
    }

    private function proyectarMovimientoConsulta(array $m): array
    {
        $mapTipos = [
            'VENTA_EFECTIVO'   => 'cash_sale',
            'INGRESO_MANUAL'   => 'manual_income',
            'INYECCION_ADMIN'  => 'admin_injection',
            'SALIDA_CAJA'      => 'cash_out',
            'RETIRO_ADMIN'     => 'admin_withdrawal',
            'RETIRO_OMITIDO'   => 'omitted_withdrawal',
            'CORRECCION'       => 'correction',
        ];
        $mapLabels = [
            'VENTA_EFECTIVO'   => 'Venta en efectivo',
            'INGRESO_MANUAL'   => 'Ingreso',
            'INYECCION_ADMIN'  => 'Inyección',
            'SALIDA_CAJA'      => 'Salida',
            'RETIRO_ADMIN'     => 'Retiro',
            'RETIRO_OMITIDO'   => 'Retiro omitido',
            'CORRECCION'       => 'Corrección',
        ];
        $tipoDb = (string)$m['tipo_movimiento'];
        $tipoApi = $mapTipos[$tipoDb] ?? strtolower($tipoDb);
        $label = $mapLabels[$tipoDb] ?? $tipoApi;
        $referencia = $m['referencia_visible'] !== null && trim((string)$m['referencia_visible']) !== ''
            ? (string)$m['referencia_visible']
            : (string)$m['motivo'];

        $dt = new DateTimeImmutable((string)$m['fecha_hora_registro'], new DateTimeZone(self::LIMA_TZ));
        $impact = $this->decimalDbANumero((string)$m['efecto_saldo']);
        if ($tipoApi === 'omitted_withdrawal') {
            $effectText = 'Sin efecto: ya reflejado en la apertura';
        } elseif ($impact > 0) {
            $effectText = 'Suma';
        } elseif ($impact < 0) {
            $effectText = 'Resta';
        } else {
            $effectText = 'Sin efecto';
        }

        $tieneCorrecciones = !empty($m['tiene_correcciones']) || $m['monto_corregido_actual'] !== null;

        $item = [
            'id'          => (int)$m['id_movimiento_caja'],
            'session_id'  => (int)$m['id_sesion_caja'],
            'date'        => $dt->format('Y-m-d'),
            'time'        => $dt->format('H:i'),
            'recorded_at' => $dt->format('Y-m-d\TH:i:sP'),
            'type'        => $tipoApi,
            'label'       => $label,
            'reason'      => (string)$m['motivo'],
            'reference'   => $referencia,
            'amount'      => $this->decimalDbANumero((string)$m['monto']),
        ];

        if ($m['monto_corregido_actual'] !== null) {
            $item['current_amount'] = $this->decimalDbANumero((string)$m['monto_corregido_actual']);
        }

        $item['impact'] = $impact;
        $item['effect'] = $effectText;
        $item['status'] = $tieneCorrecciones ? 'Corregido' : 'Confirmado';
        $item['responsible'] = (string)$m['registered_by_name'];
        $item['administrator_id'] = $m['id_usuario_admin'] !== null ? (int)$m['id_usuario_admin'] : null;
        $item['administrator_name'] = $m['authorized_by_name'] !== null ? (string)$m['authorized_by_name'] : null;
        $item['origin_session_id'] = $m['id_sesion_origen_omitido'] !== null ? (int)$m['id_sesion_origen_omitido'] : null;
        $item['declared_occurred_in_open_origin_session'] = $m['declarado_ocurrio_en_sesion_origen_abierta'] !== null
            ? ((int)$m['declarado_ocurrio_en_sesion_origen_abierta'] === 1)
            : null;
        $item['confirmed_reflected_in_opening'] = $m['confirmado_reflejado_en_apertura'] !== null
            ? ((int)$m['confirmado_reflejado_en_apertura'] === 1)
            : null;

        if ($tipoApi === 'correction') {
            $item['original_movement_id'] = $m['corr_original_id'] !== null
                ? (int)$m['corr_original_id']
                : ($m['id_movimiento_original'] !== null ? (int)$m['id_movimiento_original'] : null);
            $item['correction_id'] = $m['corr_id'] !== null ? (int)$m['corr_id'] : null;
        }

        return $item;
    }
}
