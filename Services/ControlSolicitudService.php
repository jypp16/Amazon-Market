<?php

namespace Services;

use Libraries\Core\Conexion;
use Libraries\Core\InventarioException;
use PDO;

/**
 * Controla solicitudes por `(id_usuario, tipo_operacion, referencia_solicitud)`:
 *  1. Misma referencia y mismos datos (ya confirmada) -> devuelve el resultado original guardado (200 OK).
 *  2. Misma referencia con datos diferentes -> rechaza con 409 IDEMPOTENCY_PAYLOAD_MISMATCH.
 *  3. Solicitudes simultáneas iguales -> bloquea no-bloqueante con GET_LOCK(..., 0) / estado EN_PROCESO
 *     y responde 409 REQUEST_IN_PROGRESS sin aplicar dos veces.
 *  4. Si la operación falla y hace rollback -> jamás queda marcada como CONFIRMADA.
 */
class ControlSolicitudService {

    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Conexion::getInstance()->conect();
    }

    public static function validarReferenciaSolicitud(mixed $referencia): string {
        if (!is_string($referencia)) {
            throw InventarioException::validacion([
                'referencia_solicitud' => 'La referencia de solicitud es obligatoria.',
            ]);
        }

        $limpia = trim($referencia);
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $limpia)) {
            throw InventarioException::validacion([
                'referencia_solicitud' => 'La referencia de solicitud debe tener entre 16 y 64 caracteres alfanuméricos, guiones o guiones bajos.',
            ]);
        }

        return $limpia;
    }

    /**
     * Calcula el hash SHA-256 canónico de los datos funcionales de la solicitud,
     * excluyendo claves de transporte como `referencia_solicitud` o `csrf_token`.
     */
    public static function calcularHashPayload(array $payload): string {
        $clavesExcluidas = ['referencia_solicitud', 'csrf_token', '_method'];
        $limpio = array_diff_key($payload, array_flip($clavesExcluidas));
        $normalizado = self::normalizarValorRecursivo($limpio);
        return hash('sha256', json_encode($normalizado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normalizarValorRecursivo(mixed $valor): mixed {
        if (is_array($valor)) {
            $esLista = array_is_list($valor);
            if (!$esLista) {
                ksort($valor);
            }
            $resultado = [];
            foreach ($valor as $k => $v) {
                $resultado[$k] = self::normalizarValorRecursivo($v);
            }
            return $resultado;
        }
        if (is_string($valor)) {
            $recortado = trim($valor);
            if (preg_match('/^-?\d+$/', $recortado)) {
                return (int)$recortado;
            }
            return $recortado;
        }
        if (is_bool($valor) || is_int($valor) || $valor === null) {
            return $valor;
        }
        if (is_float($valor)) {
            return floor($valor) === $valor ? (int)$valor : (string)$valor;
        }
        return (string)$valor;
    }

    public static function construirNombreLock(int $idUsuario, string $tipoOperacion, string $referenciaSolicitud): string {
        return 'am_idemp_' . substr(hash('sha256', "{$idUsuario}:{$tipoOperacion}:{$referenciaSolicitud}"), 0, 48);
    }

    /**
     * Ejecuta una operación de negocio protegiéndola contra duplicación y concurrencia.
     *
     * @param int      $idUsuario           ID del usuario autenticado (obtenido del servidor)
     * @param string   $tipoOperacion       Constante canónica (BAJA, AJUSTE, COMPRA, VENTA, etc.)
     * @param mixed    $referenciaSolicitud Referencia única de la intención de envío (16..64 chars)
     * @param array    $payloadFuncional    Datos de entrada de la solicitud
     * @param callable $operacionNegocio    Callback `fn(PDO $pdo): array` que retorna `['id_registro' => int, 'response' => array]`
     * @return array{http_code: int, idempotent_replay: bool, response: array}
     */
    public function ejecutarConIdempotencia(
        int $idUsuario,
        string $tipoOperacion,
        mixed $referenciaSolicitud,
        array $payloadFuncional,
        callable $operacionNegocio
    ): array {
        if ($idUsuario <= 0) {
            throw InventarioException::noAutenticado();
        }

        $referenciaValida = self::validarReferenciaSolicitud($referenciaSolicitud);
        $hashPayload = self::calcularHashPayload($payloadFuncional);
        $lockName = self::construirNombreLock($idUsuario, $tipoOperacion, $referenciaValida);

        // 1. Adquirir lock de usuario no bloqueante (timeout = 0) para detectar solicitudes simultáneas al instante
        $stmtLock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $stmtLock->execute([':lock_name' => $lockName]);
        $lockAdquirido = (int)$stmtLock->fetchColumn();

        if ($lockAdquirido !== 1) {
            throw InventarioException::solicitudEnProceso($referenciaValida);
        }

        $esTransaccionPropia = !$this->pdo->inTransaction();

        try {
            if ($esTransaccionPropia) {
                $this->pdo->beginTransaction();
            }

            // 2. Consultar si existe registro previo para (id_usuario, tipo_operacion, referencia_solicitud)
            $sqlSelect = 'SELECT id_solicitud, hash_payload, estado_solicitud, codigo_http, respuesta_json
                            FROM control_solicitud_idempotencia
                           WHERE id_usuario = :id_usuario
                             AND tipo_operacion = :tipo_operacion
                             AND referencia_solicitud = :referencia_solicitud
                           FOR UPDATE';
            $stmtSelect = $this->pdo->prepare($sqlSelect);
            $stmtSelect->execute([
                ':id_usuario' => $idUsuario,
                ':tipo_operacion' => $tipoOperacion,
                ':referencia_solicitud' => $referenciaValida,
            ]);
            $existente = $stmtSelect->fetch(PDO::FETCH_ASSOC);

            if ($existente) {
                // Caso A: Misma referencia pero datos diferentes -> rechazar conflicto 409
                if (!hash_equals((string)$existente['hash_payload'], $hashPayload)) {
                    if ($esTransaccionPropia && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw InventarioException::conflictoIdempotencia($referenciaValida);
                }

                // Caso B: Solicitud aún marcada en procesamiento -> informar estado 409 sin duplicar
                if ($existente['estado_solicitud'] === 'EN_PROCESO') {
                    if ($esTransaccionPropia && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw InventarioException::solicitudEnProceso($referenciaValida);
                }

                // Caso C: Solicitud ya confirmada con los mismos datos -> recuperar resultado original (200 OK)
                if ($existente['estado_solicitud'] === 'CONFIRMADA') {
                    if ($esTransaccionPropia && $this->pdo->inTransaction()) {
                        $this->pdo->commit();
                    }
                    $guardado = json_decode((string)$existente['respuesta_json'], true) ?: [];
                    $respuestaReplay = [
                        'status' => true,
                        'code' => 'OPERATION_ALREADY_CONFIRMED',
                        'idempotent_replay' => true,
                        'message' => 'La operación ya fue confirmada previamente. Se devuelve el resultado original.',
                        'data' => $guardado['data'] ?? $guardado,
                    ];
                    return [
                        'http_code' => 200,
                        'idempotent_replay' => true,
                        'response' => $respuestaReplay,
                    ];
                }

                // Si un intento anterior había quedado en FALLIDA, reactivarlo a EN_PROCESO dentro de esta transacción
                $stmtReactivar = $this->pdo->prepare(
                    'UPDATE control_solicitud_idempotencia
                        SET estado_solicitud = "EN_PROCESO",
                            codigo_http = NULL,
                            respuesta_json = NULL
                      WHERE id_solicitud = :id_solicitud'
                );
                $stmtReactivar->execute([':id_solicitud' => (int)$existente['id_solicitud']]);
                $idSolicitud = (int)$existente['id_solicitud'];
            } else {
                // Registrar intención de solicitud en estado EN_PROCESO
                $stmtInsert = $this->pdo->prepare(
                    'INSERT INTO control_solicitud_idempotencia (
                        id_usuario,
                        tipo_operacion,
                        referencia_solicitud,
                        hash_payload,
                        estado_solicitud
                    ) VALUES (
                        :id_usuario,
                        :tipo_operacion,
                        :referencia_solicitud,
                        :hash_payload,
                        "EN_PROCESO"
                    )'
                );
                $stmtInsert->execute([
                    ':id_usuario' => $idUsuario,
                    ':tipo_operacion' => $tipoOperacion,
                    ':referencia_solicitud' => $referenciaValida,
                    ':hash_payload' => $hashPayload,
                ]);
                $idSolicitud = (int)$this->pdo->lastInsertId();
            }

            // 3. Ejecutar la operación de negocio en la misma transacción
            $resultadoOperacion = $operacionNegocio($this->pdo);

            $idRegistroRecurso = isset($resultadoOperacion['id_registro'])
                ? (int)$resultadoOperacion['id_registro']
                : null;
            $codigoHttp = isset($resultadoOperacion['http_code'])
                ? (int)$resultadoOperacion['http_code']
                : 201;
            $cuerpoRespuesta = $resultadoOperacion['response'] ?? $resultadoOperacion;

            // 4. Marcar solicitud como CONFIRMADA y guardar el resultado dentro de la misma transacción
            $stmtConfirmar = $this->pdo->prepare(
                'UPDATE control_solicitud_idempotencia
                    SET estado_solicitud = "CONFIRMADA",
                        id_registro_recurso = :id_registro_recurso,
                        codigo_http = :codigo_http,
                        respuesta_json = :respuesta_json
                  WHERE id_solicitud = :id_solicitud'
            );
            $stmtConfirmar->execute([
                ':id_registro_recurso' => $idRegistroRecurso,
                ':codigo_http' => $codigoHttp,
                ':respuesta_json' => json_encode($cuerpoRespuesta, JSON_UNESCAPED_UNICODE),
                ':id_solicitud' => $idSolicitud,
            ]);

            if ($esTransaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return [
                'http_code' => $codigoHttp,
                'idempotent_replay' => false,
                'response' => $cuerpoRespuesta,
            ];
        } catch (\Throwable $error) {
            if ($esTransaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            try {
                $stmtUnlock = $this->pdo->prepare('DO RELEASE_LOCK(:lock_name)');
                $stmtUnlock->execute([':lock_name' => $lockName]);
            } catch (\Throwable) {
                // Ignorar error de liberación de lock al cerrar
            }
        }
    }
}
