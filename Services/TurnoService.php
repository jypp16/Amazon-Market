<?php

namespace Services;

use Libraries\Core\Conexion;
use Libraries\Core\InventarioException;
use Models\TurnoModel;
use PDO;
use PDOException;

class TurnoService
{
    private const REGEX_REFERENCIA = '/^[A-Za-z0-9_-]{16,64}$/';
    private const REGEX_HORA_24H   = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    private PDO $pdo;
    private TurnoModel $turnoModel;
    private ControlSolicitudService $controlSolicitudService;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo === null) {
            $this->pdo = Conexion::getInstance()->conect();
        } else {
            $this->pdo = $pdo;
        }
        $this->turnoModel = new TurnoModel($this->pdo);
        $this->controlSolicitudService = new ControlSolicitudService($this->pdo);
    }

    /**
     * EP-01: Lista todos los turnos activos con su conteo real de vendedores asignados efectivos.
     */
    public function listarTurnosActivos(array $queryParams = []): array
    {
        $permitidos = ['url'];
        $errores = [];
        foreach (array_keys($queryParams) as $key) {
            if (!in_array((string)$key, $permitidos, true)) {
                $errores[(string)$key] = ['Parámetro de consulta no permitido en este endpoint.'];
            }
        }
        if (!empty($errores)) {
            throw new InventarioException(
                'Parámetros de consulta inválidos.',
                'VALIDATION_ERROR',
                422,
                $errores
            );
        }

        return $this->turnoModel->listarActivosConAsignadosEfectivos();
    }

    /**
     * EP-02: Crea un nuevo turno de atención con idempotencia (TURNO_CREAR).
     */
    public function crearTurno(array $input, ?string $headerReferencia, int $idUsuarioAutor): array
    {
        $this->validarCamposPermitidos($input, ['referencia_solicitud', 'request_id', 'nombre', 'inicio', 'fin']);
        $referencia = $this->resolverReferenciaCuerpoOCabecera($input, $headerReferencia);
        [$nombre, $inicio, $fin] = $this->validarDatosTurno($input);

        $payloadIdempotencia = [
            'operacion' => 'TURNO_CREAR',
            'nombre'    => $nombre,
            'inicio'    => $inicio,
            'fin'       => $fin,
        ];

        $ejecucion = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idUsuarioAutor,
            'TURNO_CREAR',
            $referencia,
            $payloadIdempotencia,
            function (PDO $pdo, int $idSolicitud) use ($nombre, $inicio, $fin, $idUsuarioAutor): array {
                $model = new TurnoModel($pdo);

                $existente = $model->buscarActivoPorNombreNormalizado($nombre, null, true);
                if ($existente !== null) {
                    throw new InventarioException(
                        'Ya existe un turno activo con ese nombre.',
                        'SHIFT_NAME_ALREADY_EXISTS',
                        409,
                        ['nombre' => ['Ya existe un turno activo con ese nombre.']]
                    );
                }

                try {
                    $idNuevo = $model->insertarTurno(
                        $nombre,
                        $inicio,
                        $fin,
                        $idUsuarioAutor,
                        $idSolicitud > 0 ? $idSolicitud : null
                    );
                } catch (PDOException $e) {
                    if ($this->esViolacionUnicidad($e)) {
                        throw new InventarioException(
                            'Ya existe un turno activo con ese nombre.',
                            'SHIFT_NAME_ALREADY_EXISTS',
                            409,
                            ['nombre' => ['Ya existe un turno activo con ese nombre.']]
                        );
                    }
                    throw $e;
                }

                $data = [
                    'result_code'          => 'SHIFT_CREATED',
                    'id'                   => $idNuevo,
                    'nombre'               => $nombre,
                    'inicio'               => $inicio,
                    'fin'                  => $fin,
                    'estado'               => 1,
                    'vendedores_asignados' => 0,
                ];

                return [
                    'id_registro' => $idNuevo,
                    'http_code'   => 201,
                    'response'    => [
                        'status'            => true,
                        'code'              => 'SHIFT_CREATED',
                        'idempotent_replay' => false,
                        'message'           => 'Turno creado correctamente.',
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
     * EP-03: Edita un turno de atención existente con idempotencia (TURNO_EDITAR).
     */
    public function editarTurno(int $idTurno, array $input, ?string $headerReferencia, int $idUsuarioAutor): array
    {
        if ($idTurno <= 0) {
            throw new InventarioException(
                'El identificador del turno es inválido.',
                'VALIDATION_ERROR',
                422,
                ['id' => ['El identificador del turno debe ser un entero positivo.']]
            );
        }

        $this->validarCamposPermitidos($input, ['referencia_solicitud', 'request_id', 'nombre', 'inicio', 'fin']);
        $referencia = $this->resolverReferenciaCuerpoOCabecera($input, $headerReferencia);
        [$nombre, $inicio, $fin] = $this->validarDatosTurno($input);

        $payloadIdempotencia = [
            'operacion' => 'TURNO_EDITAR',
            'id'        => $idTurno,
            'nombre'    => $nombre,
            'inicio'    => $inicio,
            'fin'       => $fin,
        ];

        $ejecucion = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idUsuarioAutor,
            'TURNO_EDITAR',
            $referencia,
            $payloadIdempotencia,
            function (PDO $pdo, int $idSolicitud) use ($idTurno, $nombre, $inicio, $fin): array {
                $model = new TurnoModel($pdo);

                $turnoActual = $model->obtenerPorId($idTurno, true);
                if ($turnoActual === null || (int)$turnoActual['estado'] !== 1) {
                    throw new InventarioException(
                        'El turno solicitado no existe o se encuentra inactivo.',
                        'SHIFT_NOT_FOUND',
                        404
                    );
                }

                $colision = $model->buscarActivoPorNombreNormalizado($nombre, $idTurno, true);
                if ($colision !== null) {
                    throw new InventarioException(
                        'Ya existe otro turno activo con ese nombre.',
                        'SHIFT_NAME_ALREADY_EXISTS',
                        409,
                        ['nombre' => ['Ya existe otro turno activo con ese nombre.']]
                    );
                }

                try {
                    $model->actualizarTurno(
                        $idTurno,
                        $nombre,
                        $inicio,
                        $fin,
                        $idSolicitud > 0 ? $idSolicitud : null
                    );
                } catch (PDOException $e) {
                    if ($this->esViolacionUnicidad($e)) {
                        throw new InventarioException(
                            'Ya existe otro turno activo con ese nombre.',
                            'SHIFT_NAME_ALREADY_EXISTS',
                            409,
                            ['nombre' => ['Ya existe otro turno activo con ese nombre.']]
                        );
                    }
                    throw $e;
                }

                $vendedoresAsignados = $model->contarVendedoresAsignadosEfectivos($idTurno, false);

                $data = [
                    'result_code'          => 'SHIFT_UPDATED',
                    'id'                   => $idTurno,
                    'nombre'               => $nombre,
                    'inicio'               => $inicio,
                    'fin'                  => $fin,
                    'estado'               => 1,
                    'vendedores_asignados' => $vendedoresAsignados,
                ];

                return [
                    'id_registro' => $idTurno,
                    'http_code'   => 200,
                    'response'    => [
                        'status'            => true,
                        'code'              => 'SHIFT_UPDATED',
                        'idempotent_replay' => false,
                        'message'           => 'Turno actualizado correctamente.',
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
     * EP-04: Da de baja lógica un turno de atención con idempotencia (TURNO_BAJA).
     * Exige referencia en cabecera X-Request-Reference y sin cuerpo JSON.
     */
    public function eliminarTurno(int $idTurno, array $input, ?string $headerReferencia, int $idUsuarioAutor): array
    {
        if ($idTurno <= 0) {
            throw new InventarioException(
                'El identificador del turno es inválido.',
                'VALIDATION_ERROR',
                422,
                ['id' => ['El identificador del turno debe ser un entero positivo.']]
            );
        }

        if (!empty($input)) {
            $errores = [];
            foreach (array_keys($input) as $campo) {
                $errores[(string)$campo] = ['El endpoint DELETE /api/turnos/{id} no admite campos en el cuerpo JSON.'];
            }
            throw new InventarioException(
                'No se permiten campos en el cuerpo de la petición DELETE.',
                'VALIDATION_ERROR',
                422,
                $errores
            );
        }

        $referencia = $this->validarReferenciaCabeceraObligatoria($headerReferencia);

        $payloadIdempotencia = [
            'operacion' => 'TURNO_BAJA',
            'id'        => $idTurno,
        ];

        $ejecucion = $this->controlSolicitudService->ejecutarConIdempotencia(
            $idUsuarioAutor,
            'TURNO_BAJA',
            $referencia,
            $payloadIdempotencia,
            function (PDO $pdo, int $idSolicitud) use ($idTurno): array {
                $model = new TurnoModel($pdo);

                $turnoActual = $model->obtenerPorId($idTurno, true);
                if ($turnoActual === null || (int)$turnoActual['estado'] !== 1) {
                    throw new InventarioException(
                        'El turno solicitado no existe o ya fue eliminado.',
                        'SHIFT_NOT_FOUND',
                        404
                    );
                }

                $asignados = $model->contarVendedoresAsignadosEfectivos($idTurno, true);
                if ($asignados > 0) {
                    throw new InventarioException(
                        'No se puede eliminar: hay vendedores asignados a este turno.',
                        'SHIFT_HAS_ASSIGNED_SELLERS',
                        409
                    );
                }

                if ($model->tieneSesionCajaAbierta($idTurno, true)) {
                    throw new InventarioException(
                        'No se puede eliminar el turno mientras exista una sesión de caja abierta asociada a él.',
                        'SHIFT_IN_USE',
                        409
                    );
                }

                try {
                    $model->darBajaLogica($idTurno, $idSolicitud > 0 ? $idSolicitud : null);
                } catch (PDOException $e) {
                    $sqlState = (string)$e->getCode();
                    $msg = $e->getMessage();
                    if ($sqlState === '45000') {
                        if (stripos($msg, 'ABIERTA') !== false) {
                            throw new InventarioException(
                                'No se puede eliminar el turno mientras exista una sesión de caja abierta asociada a él.',
                                'SHIFT_IN_USE',
                                409
                            );
                        }
                        if (stripos($msg, 'programaci') !== false) {
                            throw new InventarioException(
                                'No se puede eliminar: hay vendedores asignados a este turno.',
                                'SHIFT_HAS_ASSIGNED_SELLERS',
                                409
                            );
                        }
                    }
                    throw $e;
                }

                $data = [
                    'result_code' => 'SHIFT_DELETED',
                    'eliminado'   => true,
                    'id'          => $idTurno,
                ];

                return [
                    'id_registro' => $idTurno,
                    'http_code'   => 200,
                    'response'    => [
                        'status'            => true,
                        'code'              => 'SHIFT_DELETED',
                        'idempotent_replay' => false,
                        'message'           => 'Turno eliminado correctamente.',
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
     * Valida la cabecera obligatoria X-Request-Reference en EP-04 (DELETE).
     */
    private function validarReferenciaCabeceraObligatoria(?string $headerReferencia): string
    {
        $referencia = trim((string)($headerReferencia ?? ''));
        if ($referencia === '' || !preg_match(self::REGEX_REFERENCIA, $referencia)) {
            throw new InventarioException(
                'La cabecera X-Request-Reference es obligatoria y debe tener entre 16 y 64 caracteres válidos.',
                'VALIDATION_ERROR',
                422,
                ['X-Request-Reference' => ['Debe proporcionar una referencia válida de 16 a 64 caracteres (^[A-Za-z0-9_-]{16,64}$).']]
            );
        }

        return $referencia;
    }

    /**
     * Valida nombre, inicio y fin para creación o edición de turnos.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function validarDatosTurno(array $input): array
    {
        $errores = [];

        if (!array_key_exists('nombre', $input) || !is_string($input['nombre'])) {
            $errores['nombre'] = ['El nombre del turno es obligatorio y debe ser una cadena de texto.'];
        } else {
            $nombre = trim($input['nombre']);
            $len = mb_strlen($nombre, 'UTF-8');
            if ($len < 1 || $len > 60) {
                $errores['nombre'] = ['El nombre del turno debe tener entre 1 y 60 caracteres tras eliminar espacios extremos.'];
            }
        }

        if (!array_key_exists('inicio', $input) || !is_string($input['inicio'])) {
            $errores['inicio'] = ['La hora de inicio es obligatoria y debe ser una cadena en formato HH:mm.'];
        }

        if (!array_key_exists('fin', $input) || !is_string($input['fin'])) {
            $errores['fin'] = ['La hora de fin es obligatoria y debe ser una cadena en formato HH:mm.'];
        }

        if (!empty($errores)) {
            throw new InventarioException(
                'Los datos del turno contienen errores de validación.',
                'VALIDATION_ERROR',
                422,
                $errores
            );
        }

        $nombre = trim((string)$input['nombre']);
        $inicio = (string)$input['inicio'];
        $fin    = (string)$input['fin'];

        $inicioValido = (bool)preg_match(self::REGEX_HORA_24H, $inicio);
        $finValido    = (bool)preg_match(self::REGEX_HORA_24H, $fin);

        if (!$inicioValido || !$finValido) {
            $errHoras = [];
            if (!$inicioValido) {
                $errHoras['inicio'] = ['La hora de inicio debe tener formato de 24 horas HH:mm (00:00 a 23:59).'];
            }
            if (!$finValido) {
                $errHoras['fin'] = ['La hora de fin debe tener formato de 24 horas HH:mm (00:00 a 23:59).'];
            }
            throw new InventarioException(
                'El formato de las horas del turno es inválido.',
                'INVALID_SHIFT_HOURS',
                422,
                $errHoras
            );
        }

        if ($fin <= $inicio) {
            throw new InventarioException(
                'La hora de fin debe ser estrictamente posterior a la hora de inicio dentro del mismo día.',
                'INVALID_SHIFT_HOURS',
                422,
                ['fin' => ['La hora de fin debe ser posterior a la hora de inicio.']]
            );
        }

        return [$nombre, $inicio, $fin];
    }

    private function esViolacionUnicidad(PDOException $e): bool
    {
        $sqlState = (string)$e->getCode();
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        return $sqlState === '23000' || $driverCode === 1062;
    }
}
