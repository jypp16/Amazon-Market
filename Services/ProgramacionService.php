<?php

namespace Services;

use DateTimeImmutable;
use Libraries\Core\Conexion;
use Libraries\Core\InventarioException;
use Models\ProgramacionSemanalModel;
use PDO;
use PDOException;

class ProgramacionService
{
    private const REGEX_REFERENCIA = '/^[A-Za-z0-9_-]{16,64}$/';
    private const REGEX_FECHA_ISO  = '/^\d{4}-\d{2}-\d{2}$/';

    private PDO $pdo;
    private ProgramacionSemanalModel $programacionModel;
    private ControlSolicitudService $controlSolicitudService;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo === null) {
            $this->pdo = Conexion::getInstance()->conect();
        } else {
            $this->pdo = $pdo;
        }
        $this->programacionModel = new ProgramacionSemanalModel($this->pdo);
        $this->controlSolicitudService = new ControlSolicitudService($this->pdo);
    }

    /**
     * EP-05: Consulta la programación semanal efectiva de un vendedor para un lunes determinado.
     */
    public function consultarProgramacionSemanal(array $queryParams): array
    {
        $permitidos = ['url', 'usuario_id', 'usuarioId', 'semana_inicio', 'semanaInicio'];
        $erroresDesconocidos = [];
        foreach (array_keys($queryParams) as $key) {
            if (!in_array((string)$key, $permitidos, true)) {
                $erroresDesconocidos[(string)$key] = ['Parámetro de consulta no permitido en este endpoint.'];
            }
        }
        if (!empty($erroresDesconocidos)) {
            throw new InventarioException(
                'Parámetros de consulta inválidos.',
                'VALIDATION_ERROR',
                422,
                $erroresDesconocidos
            );
        }

        $rawUsuarioId    = $this->resolverAliasQuery($queryParams, 'usuario_id', 'usuarioId');
        $rawSemanaInicio = $this->resolverAliasQuery($queryParams, 'semana_inicio', 'semanaInicio');

        $erroresRequeridos = [];
        if ($rawUsuarioId === null || trim($rawUsuarioId) === '') {
            $erroresRequeridos['usuario_id'] = ['El parámetro usuario_id es obligatorio.'];
        }
        if ($rawSemanaInicio === null || trim($rawSemanaInicio) === '') {
            $erroresRequeridos['semana_inicio'] = ['El parámetro semana_inicio es obligatorio.'];
        }
        if (!empty($erroresRequeridos)) {
            throw new InventarioException(
                'Faltan parámetros obligatorios en la consulta.',
                'VALIDATION_ERROR',
                422,
                $erroresRequeridos
            );
        }

        $idVendedor = $this->validarEnteroPositivo($rawUsuarioId, 'usuario_id');
        $semanaInicio = $this->validarFechaLunes(trim($rawSemanaInicio));

        $usuario = $this->programacionModel->obtenerUsuario($idVendedor, false);
        if ($usuario === null) {
            throw new InventarioException(
                'El usuario indicado no existe.',
                'USER_NOT_FOUND',
                404
            );
        }

        if ((int)$usuario['id_rol'] !== 2) {
            throw new InventarioException(
                'El usuario indicado no tiene rol Vendedor.',
                'TARGET_NOT_SELLER',
                422,
                ['usuario_id' => ['El usuario consultado debe tener rol Vendedor (id_rol = 2).']]
            );
        }

        $baseAplicable = $this->programacionModel->obtenerBaseAplicableEnSemana($idVendedor, $semanaInicio, false);
        $tieneProgramacionBase = ($baseAplicable !== null);

        $excepcionSemanal = $this->programacionModel->obtenerExcepcionVigenteEnSemana($idVendedor, $semanaInicio, false);
        $reglaEfectiva = $excepcionSemanal ?? $baseAplicable;

        if ($reglaEfectiva === null) {
            return [
                'status'  => true,
                'code'    => 'OK',
                'message' => 'El vendedor aún no tiene programación registrada para esta semana.',
                'data'    => null,
            ];
        }

        $semanaAnterior = (new DateTimeImmutable($semanaInicio))->modify('-7 days')->format('Y-m-d');
        $tieneSemanaAnterior = $this->programacionModel->tieneProgramacionEfectivaEnSemana($idVendedor, $semanaAnterior);
        $turnos = $this->programacionModel->obtenerDetallesProgramacion((int)$reglaEfectiva['id_programacion']);

        return [
            'status'  => true,
            'code'    => 'OK',
            'message' => 'Programación obtenida correctamente.',
            'data'    => [
                'usuario_id'              => $idVendedor,
                'semana_inicio'           => $semanaInicio,
                'alcance'                 => (string)$reglaEfectiva['alcance'],
                'origen_semana_inicio'    => (string)$reglaEfectiva['semana_inicio'],
                'tiene_programacion_base' => $tieneProgramacionBase,
                'tiene_semana_anterior'   => $tieneSemanaAnterior,
                'turnos'                  => $turnos,
            ],
        ];
    }

    /**
     * Resuelve los turnos programados efectivos de un vendedor para una fecha local (America/Lima),
     * reutilizando exactamente la misma precedencia (excepción semanal > base aplicable) de EP-05.
     *
     * @return array<int, array{id:int,name:string,label:string,start:string,end:string}>
     */
    public function obtenerTurnosProgramadosEnFecha(int $idVendedor, DateTimeImmutable $fechaLocal): array
    {
        $diaSemana = (int)$fechaLocal->format('N') - 1;
        $semanaInicio = $fechaLocal->modify('-' . $diaSemana . ' days')->format('Y-m-d');

        $baseAplicable = $this->programacionModel->obtenerBaseAplicableEnSemana($idVendedor, $semanaInicio, false);
        $excepcionSemanal = $this->programacionModel->obtenerExcepcionVigenteEnSemana($idVendedor, $semanaInicio, false);
        $reglaEfectiva = $excepcionSemanal ?? $baseAplicable;

        if ($reglaEfectiva === null) {
            return [];
        }

        $detalles = $this->programacionModel->obtenerDetallesProgramacion((int)$reglaEfectiva['id_programacion']);
        $resultado = [];
        foreach ($detalles as $detalle) {
            if (!in_array($diaSemana, $detalle['dias'], true)) {
                continue;
            }
            $inicio = (string)$detalle['inicio'];
            $fin = (string)$detalle['fin'];
            $nombre = (string)$detalle['nombre'];
            $resultado[] = [
                'id'    => (int)$detalle['turno_id'],
                'name'  => $nombre,
                'label' => $nombre . ' · ' . $inicio . '–' . $fin,
                'start' => $inicio,
                'end'   => $fin,
            ];
        }

        return $resultado;
    }

    /**
     * EP-06: Guarda o duplica la programación semanal de un vendedor con idempotencia (PROGRAMACION_HORARIO).
     */
    public function guardarProgramacionSemanal(array $input, ?string $headerReferencia, int $idUsuarioAutor): array
    {
        $permitidosRaiz = [
            'referencia_solicitud',
            'request_id',
            'usuario_id',
            'usuarioId',
            'semana_inicio',
            'semanaInicio',
            'alcance',
            'turnos',
        ];
        $this->validarCamposPermitidos($input, $permitidosRaiz);

        $referencia      = $this->resolverReferenciaCuerpoOCabecera($input, $headerReferencia);
        $rawUsuarioId    = $this->resolverAliasCuerpo($input, 'usuario_id', 'usuarioId');
        $rawSemanaInicio = $this->resolverAliasCuerpo($input, 'semana_inicio', 'semanaInicio');

        $erroresRequeridos = [];
        if ($rawUsuarioId === null) {
            $erroresRequeridos['usuario_id'] = ['El campo usuario_id es obligatorio.'];
        }
        if ($rawSemanaInicio === null) {
            $erroresRequeridos['semana_inicio'] = ['El campo semana_inicio es obligatorio.'];
        }
        if (!array_key_exists('alcance', $input) || $input['alcance'] === null) {
            $erroresRequeridos['alcance'] = ['El campo alcance es obligatorio.'];
        }
        if (!array_key_exists('turnos', $input) || $input['turnos'] === null) {
            $erroresRequeridos['turnos'] = ['El campo turnos es obligatorio.'];
        }
        if (!empty($erroresRequeridos)) {
            throw new InventarioException(
                'Faltan campos obligatorios en la solicitud.',
                'VALIDATION_ERROR',
                422,
                $erroresRequeridos
            );
        }

        $idVendedor = $this->validarEnteroPositivo($rawUsuarioId, 'usuario_id');

        if (!is_string($rawSemanaInicio)) {
            throw new InventarioException(
                'El campo semana_inicio debe ser una cadena en formato YYYY-MM-DD.',
                'VALIDATION_ERROR',
                422,
                ['semana_inicio' => ['El campo semana_inicio debe ser una cadena de texto YYYY-MM-DD.']]
            );
        }
        $semanaInicio = $this->validarFechaLunes(trim($rawSemanaInicio));

        if (!is_string($input['alcance']) || !in_array($input['alcance'], ['semana', 'desde'], true)) {
            throw new InventarioException(
                "El campo alcance debe ser exactamente 'semana' o 'desde'.",
                'VALIDATION_ERROR',
                422,
                ['alcance' => ["El valor de alcance debe ser 'semana' o 'desde'."]]
            );
        }
        $alcance = $input['alcance'];

        $turnosNormalizados = $this->validarYNormalizarTurnosEntrada($input['turnos']);

        $payloadIdempotencia = [
            'operacion'     => 'PROGRAMACION_HORARIO',
            'usuario_id'    => $idVendedor,
            'semana_inicio' => $semanaInicio,
            'alcance'       => $alcance,
            'turnos'        => $turnosNormalizados,
        ];

        $ejecucion = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idUsuarioAutor,
            'PROGRAMACION_HORARIO',
            $referencia,
            $payloadIdempotencia,
            function (PDO $pdo, int $idSolicitud) use (
                $idVendedor,
                $semanaInicio,
                $alcance,
                $turnosNormalizados,
                $idUsuarioAutor
            ): array {
                $model = new ProgramacionSemanalModel($pdo);

                // 1. Bloquear primero la fila estable del usuario vendedor en `usuario`
                $usuario = $model->obtenerUsuario($idVendedor, true);
                if ($usuario === null) {
                    throw new InventarioException(
                        'El usuario indicado no existe.',
                        'USER_NOT_FOUND',
                        404
                    );
                }

                if ((int)$usuario['id_rol'] !== 2 || (int)$usuario['estado'] !== 1) {
                    throw new InventarioException(
                        'El usuario objetivo debe ser un vendedor activo.',
                        'TARGET_NOT_SELLER',
                        422,
                        ['usuario_id' => ['El usuario indicado debe tener rol Vendedor (id_rol = 2) y estar activo.']]
                    );
                }

                // 2. Bloquear en orden ascendente todos los turnos solicitados y verificar que estén activos
                $idsTurnos = array_column($turnosNormalizados, 'turno_id');
                $turnosEnBd = $model->bloquearYObtenerTurnosPorIds($idsTurnos);

                foreach ($idsTurnos as $idTurnoReq) {
                    if (!isset($turnosEnBd[$idTurnoReq]) || (int)$turnosEnBd[$idTurnoReq]['estado'] !== 1) {
                        throw new InventarioException(
                            "El turno con identificador {$idTurnoReq} no existe o se encuentra inactivo.",
                            'INVALID_SHIFT',
                            422,
                            ['turnos' => ["El turno {$idTurnoReq} no existe o está inactivo."]]
                        );
                    }
                }

                // 3. Verificar existencia de base aplicable si se solicita alcance = 'semana'
                $baseAplicable = $model->obtenerBaseAplicableEnSemana($idVendedor, $semanaInicio, true);
                if ($alcance === 'semana' && $baseAplicable === null) {
                    throw new InventarioException(
                        'No se puede guardar una excepción semanal ("Solo esta semana") cuando el vendedor aún no tiene una programación base aplicable ("Desde esta semana en adelante").',
                        'VALIDATION_ERROR',
                        422,
                        ['alcance' => ['Debe registrar primero una programación base con alcance "desde" aplicable a esta semana.']]
                    );
                }

                // 4. Versionado: desactivar únicamente la versión vigente previa de la misma regla (vendedor, semana, alcance)
                $vigentePrevia = $model->obtenerVersionVigentePorRegla($idVendedor, $semanaInicio, $alcance, true);
                $maxVersion    = $model->obtenerMaxVersionPorRegla($idVendedor, $semanaInicio, $alcance, true);
                $nuevaVersion  = $maxVersion + 1;

                if ($vigentePrevia !== null) {
                    $model->desactivarVersionVigente((int)$vigentePrevia['id_programacion']);
                }

                $idProgramacionPrevia = $vigentePrevia !== null ? (int)$vigentePrevia['id_programacion'] : null;

                try {
                    $idNuevaProgramacion = $model->insertarCabeceraProgramacion(
                        $idVendedor,
                        $semanaInicio,
                        $alcance,
                        $nuevaVersion,
                        $idUsuarioAutor,
                        $idSolicitud > 0 ? $idSolicitud : null,
                        $idProgramacionPrevia
                    );

                    foreach ($turnosNormalizados as $itemTurno) {
                        $idTurno = (int)$itemTurno['turno_id'];
                        foreach ($itemTurno['dias'] as $dia) {
                            $model->insertarDetalleProgramacion($idNuevaProgramacion, $idTurno, (int)$dia);
                        }
                    }
                } catch (PDOException $e) {
                    $sqlState = (string)$e->getCode();
                    $msg = $e->getMessage();
                    if ($sqlState === '45000') {
                        if (stripos($msg, 'lunes') !== false) {
                            throw new InventarioException(
                                'La fecha de inicio de semana debe corresponder estrictamente a un lunes.',
                                'INVALID_WEEK_START_DATE',
                                422,
                                ['semana_inicio' => [$msg]]
                            );
                        }
                        if (stripos($msg, 'Vendedor') !== false) {
                            throw new InventarioException(
                                'El usuario objetivo debe ser un vendedor activo.',
                                'TARGET_NOT_SELLER',
                                422,
                                ['usuario_id' => [$msg]]
                            );
                        }
                        if (stripos($msg, 'inactivo') !== false) {
                            throw new InventarioException(
                                'Uno de los turnos seleccionados se encuentra inactivo.',
                                'INVALID_SHIFT',
                                422,
                                ['turnos' => [$msg]]
                            );
                        }
                        if (stripos($msg, 'dia_semana') !== false) {
                            throw new InventarioException(
                                'Los días de programación deben estar entre 0 (lunes) y 6 (domingo).',
                                'INVALID_SCHEDULE_DAYS',
                                422,
                                ['turnos' => [$msg]]
                            );
                        }
                    }
                    throw $e;
                }

                $turnosRespuesta = $model->obtenerDetallesProgramacion($idNuevaProgramacion);

                $data = [
                    'result_code'             => 'SCHEDULE_SAVED',
                    'usuario_id'              => $idVendedor,
                    'semana_inicio'           => $semanaInicio,
                    'alcance'                 => $alcance,
                    'tiene_programacion_base' => true,
                    'turnos'                  => $turnosRespuesta,
                ];

                return [
                    'id_registro' => $idNuevaProgramacion,
                    'http_code'   => 200,
                    'response'    => [
                        'status'            => true,
                        'code'              => 'SCHEDULE_SAVED',
                        'idempotent_replay' => false,
                        'message'           => 'Programación semanal guardada correctamente.',
                        'data'              => $data,
                    ],
                ];
            }
        );

        return [
            'http_code' => (int)$ejecucion['http_code'],
            'response'  => $ejecucion['response'],
        ];
    }

    /**
     * Valida y normaliza el arreglo turnos de EP-06.
     *
     * @param mixed $turnosRaw
     * @return array<int, array{turno_id: int, dias: int[]}>
     */
    private function validarYNormalizarTurnosEntrada($turnosRaw): array
    {
        if (!is_array($turnosRaw) || empty($turnosRaw) || !array_is_list($turnosRaw)) {
            throw new InventarioException(
                'El campo turnos debe ser un arreglo no vacío de turnos.',
                'VALIDATION_ERROR',
                422,
                ['turnos' => ['Debe enviar una lista no vacía de turnos.']]
            );
        }

        $permitidosItem = ['turno_id', 'turnoId', 'dias'];
        $erroresCampos = [];

        foreach ($turnosRaw as $idx => $item) {
            if (!is_array($item) || array_is_list($item)) {
                $erroresCampos["turnos.{$idx}"] = ['Cada elemento de turnos debe ser un objeto JSON válido.'];
                continue;
            }
            foreach (array_keys($item) as $k) {
                if (!in_array((string)$k, $permitidosItem, true)) {
                    $erroresCampos["turnos.{$idx}.{$k}"] = ['Campo no permitido dentro de un elemento de turnos.'];
                }
            }
        }

        if (!empty($erroresCampos)) {
            throw new InventarioException(
                'La estructura de turnos contiene errores o campos no permitidos.',
                'VALIDATION_ERROR',
                422,
                $erroresCampos
            );
        }

        $normalizados = [];
        $turnosVistos = [];

        foreach ($turnosRaw as $idx => $item) {
            $rawTurnoId = $this->resolverAliasCuerpo($item, 'turno_id', 'turnoId', "turnos.{$idx}.turno_id");
            if ($rawTurnoId === null) {
                throw new InventarioException(
                    "El campo turno_id es obligatorio en turnos[{$idx}].",
                    'VALIDATION_ERROR',
                    422,
                    ["turnos.{$idx}.turno_id" => ['El campo turno_id es obligatorio.']]
                );
            }

            $idTurno = $this->validarEnteroPositivo($rawTurnoId, "turnos.{$idx}.turno_id");

            if (isset($turnosVistos[$idTurno])) {
                throw new InventarioException(
                    'No se puede repetir el mismo turno dentro de la programación semanal.',
                    'DUPLICATE_SHIFT_IN_SCHEDULE',
                    422,
                    ['turnos' => ["El turno con ID {$idTurno} está duplicado en la programación."]]
                );
            }
            $turnosVistos[$idTurno] = true;

            if (!array_key_exists('dias', $item) || !is_array($item['dias']) || !array_is_list($item['dias'])) {
                throw new InventarioException(
                    "El campo dias en turnos[{$idx}] debe ser un arreglo de enteros entre 0 y 6.",
                    'INVALID_SCHEDULE_DAYS',
                    422,
                    ["turnos.{$idx}.dias" => ['Debe proporcionar un arreglo no vacío de días (0 = lunes .. 6 = domingo).']]
                );
            }

            $diasRaw = $item['dias'];
            if (empty($diasRaw) || count($diasRaw) > 7) {
                throw new InventarioException(
                    'Cada turno programado debe incluir entre 1 y 7 días válidos (0 a 6).',
                    'INVALID_SCHEDULE_DAYS',
                    422,
                    ["turnos.{$idx}.dias" => ['Debe seleccionar al menos un día y como máximo 7 días entre 0 y 6.']]
                );
            }

            $diasLimpiados = [];
            $diasVistos = [];
            foreach ($diasRaw as $diaVal) {
                if (!is_int($diaVal) || $diaVal < 0 || $diaVal > 6) {
                    throw new InventarioException(
                        'Los días de programación deben ser números enteros entre 0 (lunes) y 6 (domingo).',
                        'INVALID_SCHEDULE_DAYS',
                        422,
                        ["turnos.{$idx}.dias" => ['Cada día debe ser un entero entre 0 (lunes) y 6 (domingo).']]
                    );
                }
                if (isset($diasVistos[$diaVal])) {
                    throw new InventarioException(
                        'No se permiten días repetidos dentro del mismo turno.',
                        'INVALID_SCHEDULE_DAYS',
                        422,
                        ["turnos.{$idx}.dias" => ["El día {$diaVal} está repetido para el turno {$idTurno}."]]
                    );
                }
                $diasVistos[$diaVal] = true;
                $diasLimpiados[] = $diaVal;
            }

            sort($diasLimpiados, SORT_NUMERIC);
            $normalizados[] = [
                'turno_id' => $idTurno,
                'dias'     => $diasLimpiados,
            ];
        }

        usort(
            $normalizados,
            static fn(array $a, array $b): int => $a['turno_id'] <=> $b['turno_id']
        );

        return $normalizados;
    }

    /**
     * Verifica que el payload JSON no contenga propiedades desconocidas.
     */
    private function validarCamposPermitidos(array $input, array $permitidos): void
    {
        $errores = [];
        foreach (array_keys($input) as $key) {
            if (!in_array((string)$key, $permitidos, true)) {
                $errores[(string)$key] = ['Campo no permitido en este endpoint.'];
            }
        }
        if (!empty($errores)) {
            throw new InventarioException(
                'La solicitud contiene campos no permitidos.',
                'VALIDATION_ERROR',
                422,
                $errores
            );
        }
    }

    /**
     * Resuelve un par canónico/alias en query string (GET) verificando no contradicción.
     */
    private function resolverAliasQuery(array $queryParams, string $canonico, string $alias): ?string
    {
        $tieneCanonico = array_key_exists($canonico, $queryParams) && $queryParams[$canonico] !== null;
        $tieneAlias    = array_key_exists($alias, $queryParams) && $queryParams[$alias] !== null;

        if ($tieneCanonico && !is_scalar($queryParams[$canonico])) {
            throw new InventarioException(
                "El parámetro {$canonico} tiene un formato inválido.",
                'VALIDATION_ERROR',
                422,
                [$canonico => ["El parámetro {$canonico} debe ser un valor escalar."]]
            );
        }
        if ($tieneAlias && !is_scalar($queryParams[$alias])) {
            throw new InventarioException(
                "El parámetro {$alias} tiene un formato inválido.",
                'VALIDATION_ERROR',
                422,
                [$alias => ["El parámetro {$alias} debe ser un valor escalar."]]
            );
        }

        if ($tieneCanonico && $tieneAlias) {
            $valC = trim((string)$queryParams[$canonico]);
            $valA = trim((string)$queryParams[$alias]);
            if ($valC !== $valA) {
                throw new InventarioException(
                    "Los parámetros {$canonico} y {$alias} contienen valores contradictorios.",
                    'AMBIGUOUS_ALIAS_VALUES',
                    422,
                    [$canonico => ["El valor de {$canonico} no coincide con su alias {$alias}."]]
                );
            }
            return $valC;
        }

        if ($tieneCanonico) {
            return (string)$queryParams[$canonico];
        }
        if ($tieneAlias) {
            return (string)$queryParams[$alias];
        }

        return null;
    }

    /**
     * Resuelve un par canónico/alias en el cuerpo JSON verificando no contradicción.
     *
     * @return mixed
     */
    private function resolverAliasCuerpo(array $contenedor, string $canonico, string $alias, ?string $rutaError = null)
    {
        $claveError = $rutaError ?? $canonico;
        $tieneCanonico = array_key_exists($canonico, $contenedor) && $contenedor[$canonico] !== null;
        $tieneAlias    = array_key_exists($alias, $contenedor) && $contenedor[$alias] !== null;

        if ($tieneCanonico && !is_scalar($contenedor[$canonico])) {
            throw new InventarioException(
                "El campo {$claveError} debe ser un valor escalar.",
                'VALIDATION_ERROR',
                422,
                [$claveError => ["El campo {$claveError} debe ser un valor escalar."]]
            );
        }
        if ($tieneAlias && !is_scalar($contenedor[$alias])) {
            throw new InventarioException(
                "El campo {$alias} debe ser un valor escalar.",
                'VALIDATION_ERROR',
                422,
                [$claveError => ["El campo {$alias} debe ser un valor escalar."]]
            );
        }

        if ($tieneCanonico && $tieneAlias) {
            $valC = $contenedor[$canonico];
            $valA = $contenedor[$alias];

            if (is_bool($valC) !== is_bool($valA)) {
                throw new InventarioException(
                    "Los campos {$canonico} y {$alias} contienen valores contradictorios.",
                    'AMBIGUOUS_ALIAS_VALUES',
                    422,
                    [$claveError => ["El valor de {$canonico} no coincide con su alias {$alias}."]]
                );
            }

            $strC = is_string($valC) ? trim($valC) : (string)$valC;
            $strA = is_string($valA) ? trim($valA) : (string)$valA;

            if ($strC !== $strA) {
                throw new InventarioException(
                    "Los campos {$canonico} y {$alias} contienen valores contradictorios.",
                    'AMBIGUOUS_ALIAS_VALUES',
                    422,
                    [$claveError => ["El valor de {$canonico} no coincide con su alias {$alias}."]]
                );
            }

            return $valC;
        }

        if ($tieneCanonico) {
            return $contenedor[$canonico];
        }
        if ($tieneAlias) {
            return $contenedor[$alias];
        }

        return null;
    }

    /**
     * Resuelve y valida la referencia de operación desde referencia_solicitud, request_id y/o X-Request-Reference.
     */
    private function resolverReferenciaCuerpoOCabecera(array $input, ?string $headerReferencia): string
    {
        $presentes = [];

        if (array_key_exists('referencia_solicitud', $input) && $input['referencia_solicitud'] !== null) {
            if (!is_string($input['referencia_solicitud'])) {
                throw new InventarioException(
                    'El campo referencia_solicitud debe ser una cadena de texto.',
                    'VALIDATION_ERROR',
                    422,
                    ['referencia_solicitud' => ['Debe ser una cadena de texto entre 16 y 64 caracteres.']]
                );
            }
            $presentes['referencia_solicitud'] = trim($input['referencia_solicitud']);
        }

        if (array_key_exists('request_id', $input) && $input['request_id'] !== null) {
            if (!is_string($input['request_id'])) {
                throw new InventarioException(
                    'El campo request_id debe ser una cadena de texto.',
                    'VALIDATION_ERROR',
                    422,
                    ['request_id' => ['Debe ser una cadena de texto entre 16 y 64 caracteres.']]
                );
            }
            $presentes['request_id'] = trim($input['request_id']);
        }

        if ($headerReferencia !== null && trim($headerReferencia) !== '') {
            $presentes['X-Request-Reference'] = trim($headerReferencia);
        }

        if (empty($presentes)) {
            throw new InventarioException(
                'La referencia de la solicitud es obligatoria.',
                'VALIDATION_ERROR',
                422,
                ['referencia_solicitud' => ['La referencia_solicitud es obligatoria (16 a 64 caracteres).']]
            );
        }

        $valoresUnicos = array_values(array_unique(array_values($presentes)));
        if (count($valoresUnicos) > 1) {
            throw new InventarioException(
                'Se enviaron valores contradictorios para la referencia de la solicitud.',
                'AMBIGUOUS_ALIAS_VALUES',
                422,
                ['referencia_solicitud' => ['Los valores de referencia_solicitud, request_id y X-Request-Reference deben coincidir.']]
            );
        }

        $referencia = $valoresUnicos[0];
        if (!preg_match(self::REGEX_REFERENCIA, $referencia)) {
            throw new InventarioException(
                'El formato de la referencia de la solicitud es inválido.',
                'VALIDATION_ERROR',
                422,
                ['referencia_solicitud' => ['Debe tener entre 16 y 64 caracteres alfanuméricos, guiones (-) o guiones bajos (_).']]
            );
        }

        return $referencia;
    }

    /**
     * Valida que un valor sea un entero positivo (>= 1), rechazando booleanos, decimales, cero o negativos.
     *
     * @param mixed $valor
     */
    private function validarEnteroPositivo($valor, string $nombreCampo): int
    {
        if (is_bool($valor)) {
            throw new InventarioException(
                "El campo {$nombreCampo} debe ser un entero positivo.",
                'VALIDATION_ERROR',
                422,
                [$nombreCampo => ['Debe ser un número entero positivo mayor o igual a 1.']]
            );
        }

        if (is_int($valor)) {
            if ($valor >= 1) {
                return $valor;
            }
            throw new InventarioException(
                "El campo {$nombreCampo} debe ser un entero positivo.",
                'VALIDATION_ERROR',
                422,
                [$nombreCampo => ['Debe ser un número entero positivo mayor o igual a 1.']]
            );
        }

        if (is_string($valor)) {
            $limpio = trim($valor);
            if (preg_match('/^[1-9]\d*$/', $limpio)) {
                return (int)$limpio;
            }
        }

        throw new InventarioException(
            "El campo {$nombreCampo} debe ser un entero positivo.",
            'VALIDATION_ERROR',
            422,
            [$nombreCampo => ['Debe ser un número entero positivo mayor o igual a 1.']]
        );
    }

    /**
     * Valida que una cadena sea una fecha calendario real en formato YYYY-MM-DD y corresponda estrictamente a un lunes (N = 1).
     */
    private function validarFechaLunes(string $fecha): string
    {
        if (!preg_match(self::REGEX_FECHA_ISO, $fecha)) {
            throw new InventarioException(
                'La fecha de inicio de semana debe tener formato YYYY-MM-DD y corresponder a un lunes.',
                'INVALID_WEEK_START_DATE',
                422,
                ['semana_inicio' => ['Formato inválido. Use YYYY-MM-DD correspondiente a un lunes.']]
            );
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));
        if (!checkdate($mes, $dia, $anio)) {
            throw new InventarioException(
                'La fecha indicada en semana_inicio no existe en el calendario.',
                'INVALID_WEEK_START_DATE',
                422,
                ['semana_inicio' => ['La fecha indicada no es una fecha calendario válida.']]
            );
        }

        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($dt === false || $dt->format('Y-m-d') !== $fecha || (int)$dt->format('N') !== 1) {
            throw new InventarioException(
                'La fecha de inicio de semana debe corresponder estrictamente a un lunes.',
                'INVALID_WEEK_START_DATE',
                422,
                ['semana_inicio' => ['La fecha semana_inicio debe ser estrictamente un lunes (ISO-8601 N = 1).']]
            );
        }

        return $fecha;
    }
}
