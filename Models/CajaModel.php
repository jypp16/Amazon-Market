<?php

namespace Models;

use Libraries\Core\Model;
use PDO;

class CajaModel extends Model
{
    private const LOCK_CAJA_FISICA = 'am_caja_fisica_unica';

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->conect = $pdo;
        } else {
            parent::__construct();
        }
    }

    /**
     * Lista el catálogo de turnos activos (estado = 1) ordenados por hora de inicio.
     *
     * @return array<int, array{id:int,nombre:string,inicio:string,fin:string}>
     */
    public function listarTurnosActivosCatalogo(): array
    {
        $sql = "SELECT
                    id_turno AS id,
                    nombre,
                    TIME_FORMAT(hora_inicio, '%H:%i') AS inicio,
                    TIME_FORMAT(hora_fin, '%H:%i') AS fin
                FROM turno
                WHERE estado = 1
                ORDER BY hora_inicio ASC, id_turno ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['nombre'] = (string)$row['nombre'];
            $row['inicio'] = (string)$row['inicio'];
            $row['fin'] = (string)$row['fin'];
        }

        return $rows;
    }

    /**
     * Obtiene un turno por ID (opcionalmente con FOR UPDATE).
     */
    public function obtenerTurnoPorId(int $idTurno, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    id_turno,
                    nombre,
                    TIME_FORMAT(hora_inicio, '%H:%i') AS inicio,
                    TIME_FORMAT(hora_fin, '%H:%i') AS fin,
                    estado
                FROM turno
                WHERE id_turno = :id_turno
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_turno' => $idTurno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Adquiere el bloqueo nombrado exclusivo de la única caja física.
     */
    public function adquirirLockCajaFisica(int $timeoutSeconds = 5): bool
    {
        $stmt = $this->conect->prepare('SELECT GET_LOCK(:lock_name, :timeout_sec) AS acquired');
        $stmt->bindValue(':lock_name', self::LOCK_CAJA_FISICA, PDO::PARAM_STR);
        $stmt->bindValue(':timeout_sec', $timeoutSeconds, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && (int)($row['acquired'] ?? 0) === 1;
    }

    /**
     * Libera el bloqueo nombrado de la única caja física (si la conexión lo posee).
     */
    public function liberarLockCajaFisica(): void
    {
        try {
            $stmt = $this->conect->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute([':lock_name' => self::LOCK_CAJA_FISICA]);
        } catch (\Throwable $e) {
            error_log('[CajaModel::liberarLockCajaFisica] ' . $e->getMessage());
        }
    }

    /**
     * Bloquea la fila ancla única de caja_fisica_guardia para serializar aperturas incluso con 0 sesiones previas.
     */
    public function bloquearGuardiaCajaFisica(): ?array
    {
        $sql = "SELECT
                    id_caja_fisica,
                    codigo_caja,
                    id_sesion_abierta_actual,
                    id_ultima_sesion_cerrada
                FROM caja_fisica_guardia
                WHERE id_caja_fisica = 1
                LIMIT 1
                FOR UPDATE";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Obtiene la sesión ABIERTA global en la caja física única (si existe).
     */
    public function obtenerSesionAbiertaActual(bool $forUpdate = false): ?array
    {
        if ($forUpdate) {
            // Orden único de bloqueo: caja_fisica_guardia (id=1) -> sesion_caja (PRIMARY KEY).
            // Evita deadlocks contra abrirCaja/cerrarCaja por cruce PRIMARY <-> idx_sesion_estado_cierre_id.
            $guardia = $this->bloquearGuardiaCajaFisica();
            $idAbierta = ($guardia !== null && $guardia['id_sesion_abierta_actual'] !== null)
                ? (int)$guardia['id_sesion_abierta_actual']
                : 0;

            if ($idAbierta <= 0) {
                $stmtId = $this->conect->prepare(
                    "SELECT id_sesion_caja
                     FROM sesion_caja
                     WHERE estado = 'ABIERTA'
                     ORDER BY id_sesion_caja DESC
                     LIMIT 1"
                );
                $stmtId->execute();
                $foundId = $stmtId->fetchColumn();
                $idAbierta = $foundId !== false ? (int)$foundId : 0;
            }

            if ($idAbierta <= 0) {
                return null;
            }

            $row = $this->obtenerSesionPorIdParaCierre($idAbierta, true);
            if ($row === null || ($row['estado'] ?? '') !== 'ABIERTA') {
                return null;
            }

            return $row;
        }

        $sql = "SELECT
                    s.id_sesion_caja,
                    s.id_caja_fisica,
                    s.id_usuario_vendedor,
                    s.id_turno,
                    s.nombre_turno_referencia,
                    TIME_FORMAT(s.hora_inicio_referencia, '%H:%i') AS hora_inicio_referencia,
                    TIME_FORMAT(s.hora_fin_referencia, '%H:%i') AS hora_fin_referencia,
                    s.fecha_hora_salida_referencia,
                    s.es_suplencia,
                    s.abierta_fuera_horario,
                    s.fecha_hora_apertura,
                    s.tiene_cierre_anterior,
                    s.id_sesion_cierre_anterior,
                    s.monto_referencia_cierre_anterior,
                    s.fecha_hora_cierre_anterior_ref,
                    s.nombre_turno_cierre_anterior_ref,
                    s.saldo_inicial,
                    s.diferencia_apertura,
                    s.total_ventas_efectivo,
                    s.total_ventas_tarjeta,
                    s.total_ventas_yape_plin,
                    s.total_ingresos,
                    s.total_egresos,
                    s.total_retiros_omitidos_declarados,
                    s.efectivo_esperado_actual,
                    s.id_ultimo_arqueo,
                    s.version_caja,
                    s.estado,
                    s.id_solicitud_apertura
                FROM sesion_caja s
                WHERE s.estado = 'ABIERTA'
                ORDER BY s.id_sesion_caja DESC
                LIMIT 1";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // Consultar seller_name fuera del FOR UPDATE para evitar deadlocks por inversión de orden de bloqueo (usuario <-> sesion_caja)
        $stmtSeller = $this->conect->prepare('SELECT nombre FROM usuario WHERE id_usuario = :id_usuario LIMIT 1');
        $stmtSeller->execute([':id_usuario' => (int)$row['id_usuario_vendedor']]);
        $sellerName = $stmtSeller->fetchColumn();
        $row['seller_name'] = $sellerName !== false ? (string)$sellerName : '';

        return $row;
    }

    /**
     * Obtiene una sesión de caja específica por ID con todas las columnas requeridas para cierre.
     */
    public function obtenerSesionPorIdParaCierre(int $idSesion, bool $forUpdate = true): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    s.id_sesion_caja,
                    s.id_caja_fisica,
                    s.id_usuario_vendedor,
                    s.id_turno,
                    s.nombre_turno_referencia,
                    TIME_FORMAT(s.hora_inicio_referencia, '%H:%i') AS hora_inicio_referencia,
                    TIME_FORMAT(s.hora_fin_referencia, '%H:%i') AS hora_fin_referencia,
                    s.fecha_hora_salida_referencia,
                    s.es_suplencia,
                    s.abierta_fuera_horario,
                    s.fecha_hora_apertura,
                    s.tiene_cierre_anterior,
                    s.id_sesion_cierre_anterior,
                    s.monto_referencia_cierre_anterior,
                    s.fecha_hora_cierre_anterior_ref,
                    s.nombre_turno_cierre_anterior_ref,
                    s.saldo_inicial,
                    s.diferencia_apertura,
                    s.total_ventas_efectivo,
                    s.total_ventas_tarjeta,
                    s.total_ventas_yape_plin,
                    s.total_ingresos,
                    s.total_egresos,
                    s.total_retiros_omitidos_declarados,
                    s.efectivo_esperado_actual,
                    s.id_ultimo_arqueo,
                    s.version_caja,
                    s.estado,
                    s.id_solicitud_apertura
                FROM sesion_caja s
                WHERE s.id_sesion_caja = :id_sesion
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_sesion' => $idSesion]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $stmtSeller = $this->conect->prepare('SELECT nombre FROM usuario WHERE id_usuario = :id_usuario LIMIT 1');
        $stmtSeller->execute([':id_usuario' => (int)$row['id_usuario_vendedor']]);
        $sellerName = $stmtSeller->fetchColumn();
        $row['seller_name'] = $sellerName !== false ? (string)$sellerName : '';

        return $row;
    }

    /**
     * Obtiene el último cierre confirmado de la caja física única.
     */
    public function obtenerUltimoCierreConfirmado(bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    id_sesion_caja,
                    id_usuario_vendedor,
                    id_turno,
                    nombre_turno_referencia,
                    fecha_hora_cierre,
                    efectivo_esperado_cierre,
                    efectivo_contado_cierre,
                    diferencia_cierre
                FROM sesion_caja
                WHERE estado = 'CERRADA'
                ORDER BY id_sesion_caja DESC
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Inserta una nueva sesión de caja en estado ABIERTA.
     */
    public function insertarSesionApertura(array $datos): int
    {
        $sql = "INSERT INTO sesion_caja (
                    id_caja_fisica,
                    id_usuario_vendedor,
                    id_turno,
                    nombre_turno_referencia,
                    hora_inicio_referencia,
                    hora_fin_referencia,
                    fecha_hora_salida_referencia,
                    es_suplencia,
                    abierta_fuera_horario,
                    fecha_hora_apertura,
                    tiene_cierre_anterior,
                    id_sesion_cierre_anterior,
                    monto_referencia_cierre_anterior,
                    fecha_hora_cierre_anterior_ref,
                    nombre_turno_cierre_anterior_ref,
                    saldo_inicial,
                    diferencia_apertura,
                    total_ventas_efectivo,
                    total_ventas_tarjeta,
                    total_ventas_yape_plin,
                    total_ingresos,
                    total_egresos,
                    total_retiros_omitidos_declarados,
                    efectivo_esperado_actual,
                    version_caja,
                    estado,
                    id_solicitud_apertura
                ) VALUES (
                    1,
                    :id_usuario_vendedor,
                    :id_turno,
                    :nombre_turno_referencia,
                    :hora_inicio_referencia,
                    :hora_fin_referencia,
                    :fecha_hora_salida_referencia,
                    :es_suplencia,
                    :abierta_fuera_horario,
                    :fecha_hora_apertura,
                    :tiene_cierre_anterior,
                    :id_sesion_cierre_anterior,
                    :monto_referencia_cierre_anterior,
                    :fecha_hora_cierre_anterior_ref,
                    :nombre_turno_cierre_anterior_ref,
                    :saldo_inicial,
                    :diferencia_apertura,
                    0.00,
                    0.00,
                    0.00,
                    0.00,
                    0.00,
                    0.00,
                    :efectivo_esperado_actual,
                    1,
                    'ABIERTA',
                    :id_solicitud_apertura
                )";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_usuario_vendedor'              => (int)$datos['id_usuario_vendedor'],
            ':id_turno'                         => (int)$datos['id_turno'],
            ':nombre_turno_referencia'          => (string)$datos['nombre_turno_referencia'],
            ':hora_inicio_referencia'           => (string)$datos['hora_inicio_referencia'],
            ':hora_fin_referencia'              => (string)$datos['hora_fin_referencia'],
            ':fecha_hora_salida_referencia'     => (string)$datos['fecha_hora_salida_referencia'],
            ':es_suplencia'                     => (int)$datos['es_suplencia'],
            ':abierta_fuera_horario'            => (int)$datos['abierta_fuera_horario'],
            ':fecha_hora_apertura'              => (string)$datos['fecha_hora_apertura'],
            ':tiene_cierre_anterior'            => (int)$datos['tiene_cierre_anterior'],
            ':id_sesion_cierre_anterior'        => $datos['id_sesion_cierre_anterior'],
            ':monto_referencia_cierre_anterior' => $datos['monto_referencia_cierre_anterior'],
            ':fecha_hora_cierre_anterior_ref'   => $datos['fecha_hora_cierre_anterior_ref'],
            ':nombre_turno_cierre_anterior_ref' => $datos['nombre_turno_cierre_anterior_ref'],
            ':saldo_inicial'                    => (string)$datos['saldo_inicial'],
            ':diferencia_apertura'              => $datos['diferencia_apertura'],
            ':efectivo_esperado_actual'         => (string)$datos['efectivo_esperado_actual'],
            ':id_solicitud_apertura'            => $datos['id_solicitud_apertura'],
        ]);

        return (int)$this->conect->lastInsertId();
    }

    /**
     * Lista la proyección mínima (id, name) de administradores activos (id_rol = 1, estado = 1).
     *
     * @return array<int, array{id:int,name:string}>
     */
    public function listarAdministradoresActivos(): array
    {
        $sql = "SELECT
                    id_usuario AS id,
                    nombre AS name
                FROM usuario
                WHERE id_rol = 1
                  AND estado = 1
                ORDER BY nombre ASC, id_usuario ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['name'] = (string)$row['name'];
        }

        return $rows;
    }

    /**
     * Obtiene un administrador activo (id_rol = 1, estado = 1) por su ID.
     */
    public function obtenerAdministradorActivoPorId(int $idUsuario, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    id_usuario,
                    nombre,
                    id_rol,
                    estado
                FROM usuario
                WHERE id_usuario = :id_usuario
                  AND id_rol = 1
                  AND estado = 1
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_usuario' => $idUsuario]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Lista las sesiones cerradas anteriores a la apertura actual (máximo 20).
     */
    public function listarSesionesCerradasAnterioresElegibles(
        string $fechaAperturaActual,
        int $idSesionActual,
        int $limit = 10
    ): array {
        $limit = max(1, min(20, $limit));
        $sql = "SELECT
                    id_sesion_caja,
                    nombre_turno_referencia,
                    fecha_hora_cierre,
                    efectivo_esperado_cierre,
                    efectivo_contado_cierre
                FROM sesion_caja
                WHERE estado = 'CERRADA'
                  AND fecha_hora_cierre < :fecha_apertura_actual
                  AND id_sesion_caja <> :id_sesion_actual
                ORDER BY fecha_hora_cierre DESC, id_sesion_caja DESC
                LIMIT :limit_rows";

        $stmt = $this->conect->prepare($sql);
        $stmt->bindValue(':fecha_apertura_actual', $fechaAperturaActual, PDO::PARAM_STR);
        $stmt->bindValue(':id_sesion_actual', $idSesionActual, PDO::PARAM_INT);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verifica si una sesión pertenece al conjunto elegible de las últimas 20 sesiones cerradas anteriores.
     */
    public function obtenerSesionOrigenElegibleParaOmitido(
        int $idOrigen,
        string $fechaAperturaActual,
        int $idSesionActual
    ): ?array {
        $sql = "SELECT
                    elegibles.id_sesion_caja,
                    elegibles.nombre_turno_referencia,
                    elegibles.fecha_hora_cierre
                FROM (
                    SELECT
                        id_sesion_caja,
                        nombre_turno_referencia,
                        fecha_hora_cierre
                    FROM sesion_caja
                    WHERE estado = 'CERRADA'
                      AND fecha_hora_cierre < :fecha_apertura_actual
                      AND id_sesion_caja <> :id_sesion_actual
                    ORDER BY fecha_hora_cierre DESC, id_sesion_caja DESC
                    LIMIT 20
                ) AS elegibles
                WHERE elegibles.id_sesion_caja = :id_origen
                LIMIT 1";

        $stmt = $this->conect->prepare($sql);
        $stmt->bindValue(':fecha_apertura_actual', $fechaAperturaActual, PDO::PARAM_STR);
        $stmt->bindValue(':id_sesion_actual', $idSesionActual, PDO::PARAM_INT);
        $stmt->bindValue(':id_origen', $idOrigen, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Inserta un registro en movimiento_caja.
     */
    public function insertarMovimientoCaja(array $datos): int
    {
        $sql = "INSERT INTO movimiento_caja (
                    id_sesion_caja,
                    tipo_movimiento,
                    monto,
                    efecto_saldo,
                    motivo,
                    referencia_visible,
                    id_usuario_registro,
                    id_usuario_admin,
                    id_venta,
                    id_sesion_origen_omitido,
                    declarado_ocurrio_en_sesion_origen_abierta,
                    confirmado_reflejado_en_apertura,
                    id_movimiento_original,
                    id_solicitud_idempotencia,
                    fecha_hora_registro
                ) VALUES (
                    :id_sesion_caja,
                    :tipo_movimiento,
                    :monto,
                    :efecto_saldo,
                    :motivo,
                    :referencia_visible,
                    :id_usuario_registro,
                    :id_usuario_admin,
                    :id_venta,
                    :id_sesion_origen_omitido,
                    :declarado_ocurrio_en_sesion_origen_abierta,
                    :confirmado_reflejado_en_apertura,
                    :id_movimiento_original,
                    :id_solicitud_idempotencia,
                    :fecha_hora_registro
                )";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_sesion_caja'                              => (int)$datos['id_sesion_caja'],
            ':tipo_movimiento'                             => (string)$datos['tipo_movimiento'],
            ':monto'                                       => (string)$datos['monto'],
            ':efecto_saldo'                                => (string)$datos['efecto_saldo'],
            ':motivo'                                      => (string)$datos['motivo'],
            ':referencia_visible'                          => $datos['referencia_visible'],
            ':id_usuario_registro'                         => (int)$datos['id_usuario_registro'],
            ':id_usuario_admin'                            => $datos['id_usuario_admin'],
            ':id_venta'                                    => $datos['id_venta'] ?? null,
            ':id_sesion_origen_omitido'                    => $datos['id_sesion_origen_omitido'],
            ':declarado_ocurrio_en_sesion_origen_abierta'  => $datos['declarado_ocurrio_en_sesion_origen_abierta'],
            ':confirmado_reflejado_en_apertura'            => $datos['confirmado_reflejado_en_apertura'],
            ':id_movimiento_original'                      => $datos['id_movimiento_original'] ?? null,
            ':id_solicitud_idempotencia'                   => $datos['id_solicitud_idempotencia'],
            ':fecha_hora_registro'                         => (string)$datos['fecha_hora_registro'],
        ]);

        return (int)$this->conect->lastInsertId();
    }

    /**
     * Actualiza acumulados de sesión abierta e incrementa version_caja en +1.
     */
    public function actualizarAcumuladosYVersionSesion(
        int $idSesion,
        string $totalIngresos,
        string $totalEgresos,
        string $totalRetirosOmitidos,
        string $efectivoEsperadoActual
    ): void {
        $sql = "UPDATE sesion_caja
                SET total_ingresos = :total_ingresos,
                    total_egresos = :total_egresos,
                    total_retiros_omitidos_declarados = :total_retiros_omitidos,
                    efectivo_esperado_actual = :efectivo_esperado_actual,
                    version_caja = version_caja + 1
                WHERE id_sesion_caja = :id_sesion
                  AND estado = 'ABIERTA'";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':total_ingresos'           => $totalIngresos,
            ':total_egresos'            => $totalEgresos,
            ':total_retiros_omitidos'   => $totalRetirosOmitidos,
            ':efectivo_esperado_actual' => $efectivoEsperadoActual,
            ':id_sesion'                => $idSesion,
        ]);
    }

    /**
     * Actualiza acumulados de ventas por medio de pago, efectivo esperado e incrementa version_caja en +1.
     */
    public function actualizarAcumuladosVentaYVersionSesion(
        int $idSesion,
        string $totalVentasEfectivo,
        string $totalVentasTarjeta,
        string $totalVentasYapePlin,
        string $efectivoEsperadoActual
    ): void {
        $sql = "UPDATE sesion_caja
                SET total_ventas_efectivo = :total_ventas_efectivo,
                    total_ventas_tarjeta = :total_ventas_tarjeta,
                    total_ventas_yape_plin = :total_ventas_yape_plin,
                    efectivo_esperado_actual = :efectivo_esperado_actual,
                    version_caja = version_caja + 1
                WHERE id_sesion_caja = :id_sesion
                  AND estado = 'ABIERTA'";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':total_ventas_efectivo'    => $totalVentasEfectivo,
            ':total_ventas_tarjeta'     => $totalVentasTarjeta,
            ':total_ventas_yape_plin'   => $totalVentasYapePlin,
            ':efectivo_esperado_actual' => $efectivoEsperadoActual,
            ':id_sesion'                => $idSesion,
        ]);
    }

    /**
     * Marca como DESACTUALIZADO cualquier arqueo VIGENTE de la sesión tras una operación de caja.
     */
    public function desactualizarArqueoVigenteSesion(int $idSesion, string $fechaHoraCambio): void
    {
        $sql = "UPDATE arqueo_caja
                SET estado = 'DESACTUALIZADO',
                    vigencia_activa = NULL,
                    causa_cambio_estado = 'OPERACION_CAJA_POSTERIOR',
                    fecha_hora_cambio_estado = :fecha_hora_cambio
                WHERE id_sesion_caja = :id_sesion
                  AND estado = 'VIGENTE'";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':fecha_hora_cambio' => $fechaHoraCambio,
            ':id_sesion'         => $idSesion,
        ]);
    }

    /**
     * Lista los movimientos originales corregibles de una sesión y vendedor en orden cronológico.
     */
    public function listarMovimientosCorregiblesSesion(int $idSesion, int $idVendedor): array
    {
        $sql = "SELECT
                    m.id_movimiento_caja,
                    m.tipo_movimiento,
                    m.monto,
                    m.efecto_saldo,
                    m.motivo,
                    m.referencia_visible,
                    m.fecha_hora_registro
                FROM movimiento_caja m
                WHERE m.id_sesion_caja = :id_sesion
                  AND m.id_usuario_registro = :id_vendedor
                  AND m.tipo_movimiento IN ('INGRESO_MANUAL', 'INYECCION_ADMIN', 'SALIDA_CAJA', 'RETIRO_ADMIN')
                ORDER BY m.fecha_hora_registro ASC, m.id_movimiento_caja ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_sesion'   => $idSesion,
            ':id_vendedor' => $idVendedor,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista todas las correcciones vinculadas registradas en una sesión.
     */
    public function listarCorreccionesSesion(int $idSesion): array
    {
        $sql = "SELECT
                    c.id_correccion_caja,
                    c.id_movimiento_original,
                    c.id_correccion_previa,
                    c.numero_eslabon,
                    c.monto_original,
                    c.monto_vigente_previo,
                    c.monto_corregido,
                    c.efecto_saldo,
                    c.motivo,
                    c.fecha_hora_registro,
                    m.referencia_visible AS original_reference,
                    u.nombre AS responsible
                FROM correccion_movimiento_caja c
                INNER JOIN movimiento_caja m
                    ON m.id_movimiento_caja = c.id_movimiento_original
                INNER JOIN usuario u
                    ON u.id_usuario = c.id_usuario_registro
                WHERE c.id_sesion_caja = :id_sesion
                ORDER BY c.fecha_hora_registro ASC, c.id_correccion_caja ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_sesion' => $idSesion]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene un movimiento de caja por ID (opcionalmente con FOR UPDATE).
     */
    public function obtenerMovimientoPorId(int $idMovimiento, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    id_movimiento_caja,
                    id_sesion_caja,
                    tipo_movimiento,
                    monto,
                    efecto_saldo,
                    motivo,
                    referencia_visible,
                    id_usuario_registro,
                    id_usuario_admin,
                    fecha_hora_registro
                FROM movimiento_caja
                WHERE id_movimiento_caja = :id_movimiento
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_movimiento' => $idMovimiento]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Obtiene el estado de una sesión por ID.
     */
    public function obtenerEstadoSesionPorId(int $idSesion): ?array
    {
        $sql = "SELECT id_sesion_caja, id_usuario_vendedor, estado
                FROM sesion_caja
                WHERE id_sesion_caja = :id_sesion
                LIMIT 1";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_sesion' => $idSesion]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Obtiene el último eslabón confirmado de corrección para un movimiento original.
     */
    public function obtenerUltimaCorreccionDeMovimiento(int $idMovimientoOriginal, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    id_correccion_caja,
                    numero_eslabon,
                    monto_corregido
                FROM correccion_movimiento_caja
                WHERE id_movimiento_original = :id_original
                ORDER BY numero_eslabon DESC
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_original' => $idMovimientoOriginal]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Inserta un eslabón en correccion_movimiento_caja.
     */
    public function insertarCorreccionMovimiento(array $datos): int
    {
        $sql = "INSERT INTO correccion_movimiento_caja (
                    id_sesion_caja,
                    id_movimiento_original,
                    id_movimiento_correccion,
                    id_correccion_previa,
                    numero_eslabon,
                    monto_original,
                    monto_vigente_previo,
                    monto_corregido,
                    efecto_saldo,
                    motivo,
                    id_usuario_registro,
                    id_solicitud_idempotencia,
                    fecha_hora_registro
                ) VALUES (
                    :id_sesion_caja,
                    :id_movimiento_original,
                    :id_movimiento_correccion,
                    :id_correccion_previa,
                    :numero_eslabon,
                    :monto_original,
                    :monto_vigente_previo,
                    :monto_corregido,
                    :efecto_saldo,
                    :motivo,
                    :id_usuario_registro,
                    :id_solicitud_idempotencia,
                    :fecha_hora_registro
                )";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_sesion_caja'            => (int)$datos['id_sesion_caja'],
            ':id_movimiento_original'    => (int)$datos['id_movimiento_original'],
            ':id_movimiento_correccion'  => (int)$datos['id_movimiento_correccion'],
            ':id_correccion_previa'      => $datos['id_correccion_previa'],
            ':numero_eslabon'            => (int)$datos['numero_eslabon'],
            ':monto_original'            => (string)$datos['monto_original'],
            ':monto_vigente_previo'      => (string)$datos['monto_vigente_previo'],
            ':monto_corregido'           => (string)$datos['monto_corregido'],
            ':efecto_saldo'              => (string)$datos['efecto_saldo'],
            ':motivo'                    => (string)$datos['motivo'],
            ':id_usuario_registro'       => (int)$datos['id_usuario_registro'],
            ':id_solicitud_idempotencia' => $datos['id_solicitud_idempotencia'],
            ':fecha_hora_registro'       => (string)$datos['fecha_hora_registro'],
        ]);

        return (int)$this->conect->lastInsertId();
    }

    public function obtenerSesionCajaPorId(int $idSesion): ?array
    {
        $sql = "SELECT
                    id_sesion_caja,
                    id_usuario_vendedor,
                    version_caja,
                    id_ultimo_arqueo,
                    estado
                FROM sesion_caja
                WHERE id_sesion_caja = :id_sesion
                LIMIT 1";
        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_sesion' => $idSesion]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function obtenerNombreUsuarioPorId(int $idUsuario): string
    {
        $stmt = $this->conect->prepare("SELECT nombre FROM usuario WHERE id_usuario = :id LIMIT 1");
        $stmt->execute([':id' => $idUsuario]);
        $nombre = $stmt->fetchColumn();
        return is_string($nombre) ? $nombre : '';
    }

    /**
     * Lista el historial cronológico completo de arqueos de una sesión.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarArqueosSesion(int $idSesion): array
    {
        $sql = "SELECT
                    id_arqueo_caja,
                    id_sesion_caja,
                    id_usuario_vendedor,
                    version_caja_evaluada,
                    monto_esperado,
                    monto_contado,
                    diferencia,
                    observacion,
                    estado,
                    vigencia_activa,
                    causa_cambio_estado,
                    fecha_hora_arqueo,
                    fecha_hora_cambio_estado
                FROM arqueo_caja
                WHERE id_sesion_caja = :id_sesion
                ORDER BY fecha_hora_arqueo ASC, id_arqueo_caja ASC";
        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_sesion' => $idSesion]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function reemplazarArqueoVigenteSesion(int $idSesion, string $fechaHoraCambio): void
    {
        $sql = "UPDATE arqueo_caja
                   SET estado = 'REEMPLAZADO',
                       vigencia_activa = NULL,
                       causa_cambio_estado = 'NUEVO_ARQUEO',
                       fecha_hora_cambio_estado = :fecha_cambio
                 WHERE id_sesion_caja = :id_sesion
                   AND estado = 'VIGENTE'";
        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':fecha_cambio' => $fechaHoraCambio,
            ':id_sesion'    => $idSesion,
        ]);
    }

    public function insertarArqueoCaja(array $datos): int
    {
        $sql = "INSERT INTO arqueo_caja (
                    id_sesion_caja,
                    id_usuario_vendedor,
                    version_caja_evaluada,
                    monto_esperado,
                    monto_contado,
                    diferencia,
                    observacion,
                    estado,
                    vigencia_activa,
                    fecha_hora_arqueo,
                    id_solicitud_idempotencia
                ) VALUES (
                    :id_sesion_caja,
                    :id_usuario_vendedor,
                    :version_caja_evaluada,
                    :monto_esperado,
                    :monto_contado,
                    :diferencia,
                    :observacion,
                    'VIGENTE',
                    1,
                    :fecha_hora_arqueo,
                    :id_solicitud_idempotencia
                )";
        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_sesion_caja'            => (int)$datos['id_sesion_caja'],
            ':id_usuario_vendedor'       => (int)$datos['id_usuario_vendedor'],
            ':version_caja_evaluada'     => (int)$datos['version_caja_evaluada'],
            ':monto_esperado'            => (string)$datos['monto_esperado'],
            ':monto_contado'             => (string)$datos['monto_contado'],
            ':diferencia'                => (string)$datos['diferencia'],
            ':observacion'               => $datos['observacion'],
            ':fecha_hora_arqueo'         => (string)$datos['fecha_hora_arqueo'],
            ':id_solicitud_idempotencia' => $datos['id_solicitud_idempotencia'],
        ]);
        return (int)$this->conect->lastInsertId();
    }

    public function actualizarUltimoArqueoSesion(int $idSesion, int $idArqueo): void
    {
        $sql = "UPDATE sesion_caja
                   SET id_ultimo_arqueo = :id_arqueo
                 WHERE id_sesion_caja = :id_sesion";
        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_arqueo' => $idArqueo,
            ':id_sesion' => $idSesion,
        ]);
    }

    public function obtenerIdSesionPorArqueoId(int $idArqueo): ?int
    {
        $stmt = $this->conect->prepare(
            "SELECT id_sesion_caja FROM arqueo_caja WHERE id_arqueo_caja = :id_arqueo LIMIT 1"
        );
        $stmt->execute([':id_arqueo' => $idArqueo]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    public function obtenerArqueoPorId(int $idArqueo, bool $forUpdate = false): ?array
    {
        $sql = "SELECT
                    id_arqueo_caja,
                    id_sesion_caja,
                    id_usuario_vendedor,
                    version_caja_evaluada,
                    monto_esperado,
                    monto_contado,
                    diferencia,
                    observacion,
                    estado,
                    fecha_hora_arqueo
                FROM arqueo_caja
                WHERE id_arqueo_caja = :id_arqueo
                LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_arqueo' => $idArqueo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function insertarAutorizacionCaja(array $datos): int
    {
        $sql = "INSERT INTO autorizacion_caja (
                    id_sesion_caja,
                    tipo_operacion,
                    id_usuario_vendedor,
                    id_usuario_admin,
                    motivo,
                    hora_salida_referencia,
                    fecha_hora_salida_referencia,
                    hora_cierre_real,
                    minutos_anticipacion,
                    fecha_hora_autorizacion,
                    id_solicitud_idempotencia
                ) VALUES (
                    :id_sesion_caja,
                    'CIERRE_ANTICIPADO',
                    :id_usuario_vendedor,
                    :id_usuario_admin,
                    :motivo,
                    :hora_salida_referencia,
                    :fecha_hora_salida_referencia,
                    :hora_cierre_real,
                    :minutos_anticipacion,
                    :fecha_hora_autorizacion,
                    :id_solicitud_idempotencia
                )";
        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_sesion_caja'               => (int)$datos['id_sesion_caja'],
            ':id_usuario_vendedor'          => (int)$datos['id_usuario_vendedor'],
            ':id_usuario_admin'             => (int)$datos['id_usuario_admin'],
            ':motivo'                       => (string)$datos['motivo'],
            ':hora_salida_referencia'       => (string)$datos['hora_salida_referencia'],
            ':fecha_hora_salida_referencia' => (string)$datos['fecha_hora_salida_referencia'],
            ':hora_cierre_real'             => (string)$datos['hora_cierre_real'],
            ':minutos_anticipacion'         => (int)$datos['minutos_anticipacion'],
            ':fecha_hora_autorizacion'      => (string)$datos['fecha_hora_autorizacion'],
            ':id_solicitud_idempotencia'    => $datos['id_solicitud_idempotencia'],
        ]);
        return (int)$this->conect->lastInsertId();
    }

    public function cerrarSesionCaja(array $datos): void
    {
        $sql = "UPDATE sesion_caja
                   SET estado = 'CERRADA',
                       id_arqueo_cierre = :id_arqueo_cierre,
                       fecha_hora_cierre = :fecha_hora_cierre,
                       efectivo_esperado_cierre = :efectivo_esperado_cierre,
                       efectivo_contado_cierre = :efectivo_contado_cierre,
                       diferencia_cierre = :diferencia_cierre,
                       observacion_cierre = :observacion_cierre,
                       id_solicitud_cierre = :id_solicitud_cierre,
                       version_caja = version_caja + 1
                 WHERE id_sesion_caja = :id_sesion_caja
                   AND estado = 'ABIERTA'";
        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_arqueo_cierre'         => (int)$datos['id_arqueo_cierre'],
            ':fecha_hora_cierre'        => (string)$datos['fecha_hora_cierre'],
            ':efectivo_esperado_cierre' => (string)$datos['efectivo_esperado_cierre'],
            ':efectivo_contado_cierre'  => (string)$datos['efectivo_contado_cierre'],
            ':diferencia_cierre'        => (string)$datos['diferencia_cierre'],
            ':observacion_cierre'       => $datos['observacion_cierre'],
            ':id_solicitud_cierre'      => (int)$datos['id_solicitud_cierre'],
            ':id_sesion_caja'           => (int)$datos['id_sesion_caja'],
        ]);
    }

    /**
     * Obtiene el detalle completo de una sesión de caja (abierta o cerrada) por ID.
     */
    public function obtenerSesionDetallePorId(int $idSesion): ?array
    {
        $sql = "SELECT
                    s.id_sesion_caja,
                    s.id_caja_fisica,
                    s.id_usuario_vendedor,
                    u.nombre AS seller_name,
                    u.email AS seller_email,
                    s.id_turno,
                    s.nombre_turno_referencia,
                    TIME_FORMAT(s.hora_inicio_referencia, '%H:%i') AS hora_inicio_referencia,
                    TIME_FORMAT(s.hora_fin_referencia, '%H:%i') AS hora_fin_referencia,
                    s.fecha_hora_salida_referencia,
                    s.es_suplencia,
                    s.abierta_fuera_horario,
                    s.fecha_hora_apertura,
                    s.tiene_cierre_anterior,
                    s.id_sesion_cierre_anterior,
                    s.monto_referencia_cierre_anterior,
                    s.fecha_hora_cierre_anterior_ref,
                    s.nombre_turno_cierre_anterior_ref,
                    s.saldo_inicial,
                    s.diferencia_apertura,
                    s.total_ventas_efectivo,
                    s.total_ventas_tarjeta,
                    s.total_ventas_yape_plin,
                    s.total_ingresos,
                    s.total_egresos,
                    s.total_retiros_omitidos_declarados,
                    s.efectivo_esperado_actual,
                    s.id_ultimo_arqueo,
                    s.id_arqueo_cierre,
                    s.fecha_hora_cierre,
                    s.efectivo_esperado_cierre,
                    s.efectivo_contado_cierre,
                    s.diferencia_cierre,
                    s.observacion_cierre,
                    s.version_caja,
                    s.estado
                FROM sesion_caja s
                INNER JOIN usuario u ON u.id_usuario = s.id_usuario_vendedor
                WHERE s.id_sesion_caja = :id_sesion
                LIMIT 1";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_sesion' => $idSesion]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Lista todos los vendedores (activos e inactivos) para selector de consulta histórica (EP-23A).
     */
    public function listarVendedoresParaConsulta(): array
    {
        $sql = "SELECT
                    id_usuario AS id,
                    nombre AS name,
                    username,
                    estado
                FROM usuario
                WHERE id_rol = 2
                ORDER BY nombre ASC, id_usuario ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['name'] = (string)$row['name'];
            $row['username'] = (string)$row['username'];
            $row['estado'] = (int)$row['estado'];
        }

        return $rows;
    }

    /**
     * Lista sesiones de caja abiertas en una fecha local de apertura para un vendedor y turno opcional (EP-23B).
     */
    public function listarSesionesPorFechaYVendedor(string $fecha, int $idVendedor, ?int $idTurno = null): array
    {
        $inicio = $fecha . ' 00:00:00';
        $finExclusivo = (new \DateTimeImmutable($fecha . ' 00:00:00', new \DateTimeZone('America/Lima')))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');

        $sql = "SELECT
                    s.id_sesion_caja,
                    s.id_usuario_vendedor,
                    u.nombre AS seller_name,
                    s.id_turno,
                    s.nombre_turno_referencia,
                    TIME_FORMAT(s.hora_inicio_referencia, '%H:%i') AS hora_inicio_referencia,
                    TIME_FORMAT(s.hora_fin_referencia, '%H:%i') AS hora_fin_referencia,
                    s.estado,
                    s.fecha_hora_apertura,
                    s.fecha_hora_cierre
                FROM sesion_caja s
                INNER JOIN usuario u ON u.id_usuario = s.id_usuario_vendedor
                WHERE s.fecha_hora_apertura >= :inicio
                  AND s.fecha_hora_apertura < :fin_exclusivo
                  AND s.id_usuario_vendedor = :id_vendedor";

        $params = [
            ':inicio'        => $inicio,
            ':fin_exclusivo' => $finExclusivo,
            ':id_vendedor'   => $idVendedor,
        ];
        if ($idTurno !== null) {
            $sql .= " AND s.id_turno = :id_turno";
            $params[':id_turno'] = $idTurno;
        }
        $sql .= " ORDER BY s.fecha_hora_apertura ASC, s.id_sesion_caja ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cuenta el total de movimientos de una sesión de caja.
     */
    public function contarMovimientosSesion(int $idSesion): int
    {
        $stmt = $this->conect->prepare(
            'SELECT COUNT(*) FROM movimiento_caja WHERE id_sesion_caja = :id_sesion'
        );
        $stmt->execute([':id_sesion' => $idSesion]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Lista movimientos paginados de una sesión en orden cronológico ascendente.
     */
    public function listarMovimientosSesionPaginados(int $idSesion, int $limit, int $offset): array
    {
        $sql = "SELECT
                    m.id_movimiento_caja,
                    m.id_sesion_caja,
                    m.tipo_movimiento,
                    m.monto,
                    m.efecto_saldo,
                    m.motivo,
                    m.referencia_visible,
                    m.id_usuario_registro,
                    ur.nombre AS registered_by_name,
                    m.id_usuario_admin,
                    ua.nombre AS authorized_by_name,
                    m.id_venta,
                    m.id_sesion_origen_omitido,
                    m.declarado_ocurrio_en_sesion_origen_abierta,
                    m.confirmado_reflejado_en_apertura,
                    m.id_movimiento_original,
                    m.fecha_hora_registro,
                    corr.id_correccion_caja AS corr_id,
                    corr.id_movimiento_original AS corr_original_id,
                    EXISTS(
                        SELECT 1
                          FROM correccion_movimiento_caja c3
                         WHERE c3.id_movimiento_original = m.id_movimiento_caja
                    ) AS tiene_correcciones,
                    (SELECT c2.monto_corregido
                       FROM correccion_movimiento_caja c2
                      WHERE c2.id_movimiento_original = m.id_movimiento_caja
                      ORDER BY c2.numero_eslabon DESC, c2.id_correccion_caja DESC
                      LIMIT 1) AS monto_corregido_actual
                FROM movimiento_caja m
                INNER JOIN usuario ur ON ur.id_usuario = m.id_usuario_registro
                LEFT JOIN usuario ua ON ua.id_usuario = m.id_usuario_admin
                LEFT JOIN correccion_movimiento_caja corr ON corr.id_movimiento_correccion = m.id_movimiento_caja
                WHERE m.id_sesion_caja = :id_sesion
                ORDER BY m.fecha_hora_registro ASC, m.id_movimiento_caja ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conect->prepare($sql);
        $stmt->bindValue(':id_sesion', $idSesion, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista las autorizaciones de cierre anticipado de una sesión.
     */
    public function listarAutorizacionesPorSesion(int $idSesion): array
    {
        $sql = "SELECT
                    a.id_autorizacion_caja,
                    a.id_sesion_caja,
                    a.tipo_operacion,
                    a.id_usuario_vendedor,
                    uv.nombre AS seller_name,
                    a.id_usuario_admin,
                    ua.nombre AS admin_name,
                    ua.email AS administrator_email,
                    a.motivo,
                    TIME_FORMAT(a.hora_salida_referencia, '%H:%i') AS hora_salida_referencia,
                    a.fecha_hora_salida_referencia,
                    TIME_FORMAT(a.hora_cierre_real, '%H:%i') AS hora_cierre_real,
                    a.minutos_anticipacion,
                    a.fecha_hora_autorizacion
                FROM autorizacion_caja a
                INNER JOIN usuario uv ON uv.id_usuario = a.id_usuario_vendedor
                INNER JOIN usuario ua ON ua.id_usuario = a.id_usuario_admin
                WHERE a.id_sesion_caja = :id_sesion
                ORDER BY a.fecha_hora_autorizacion ASC, a.id_autorizacion_caja ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_sesion' => $idSesion]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista arqueos de una sesión en orden ascendente para consultas y detalle de cierre.
     */
    public function listarArqueosPorSesionAsc(int $idSesion): array
    {
        $sql = "SELECT
                    a.id_arqueo_caja,
                    a.id_sesion_caja,
                    a.id_usuario_vendedor,
                    u.nombre AS seller_name,
                    a.version_caja_evaluada,
                    a.monto_esperado,
                    a.monto_contado,
                    a.diferencia,
                    a.observacion,
                    a.estado,
                    a.fecha_hora_arqueo
                FROM arqueo_caja a
                INNER JOIN usuario u ON u.id_usuario = a.id_usuario_vendedor
                WHERE a.id_sesion_caja = :id_sesion
                ORDER BY a.id_arqueo_caja ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_sesion' => $idSesion]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista sesiones cerradas dentro de un rango de fechas de cierre para EP-24A.
     */
    public function listarSesionesCerradasParaReporte(
        string $fechaDesde,
        string $fechaHasta,
        ?int $idVendedor = null,
        ?int $idTurno = null
    ): array {
        $desdeInicio = $fechaDesde . ' 00:00:00';
        $hastaFinExclusivo = (new \DateTimeImmutable($fechaHasta . ' 00:00:00', new \DateTimeZone('America/Lima')))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');

        $sql = "SELECT
                    s.id_sesion_caja,
                    s.id_usuario_vendedor,
                    u.nombre AS seller_name,
                    s.id_turno,
                    s.nombre_turno_referencia,
                    TIME_FORMAT(s.hora_inicio_referencia, '%H:%i') AS hora_inicio_referencia,
                    TIME_FORMAT(s.hora_fin_referencia, '%H:%i') AS hora_fin_referencia,
                    s.fecha_hora_apertura,
                    s.fecha_hora_cierre,
                    s.tiene_cierre_anterior,
                    s.monto_referencia_cierre_anterior,
                    s.saldo_inicial,
                    s.diferencia_apertura,
                    s.total_ventas_efectivo,
                    s.total_ventas_tarjeta,
                    s.total_ventas_yape_plin,
                    s.total_ingresos,
                    s.total_egresos,
                    s.total_retiros_omitidos_declarados,
                    s.efectivo_esperado_cierre,
                    s.efectivo_contado_cierre,
                    s.diferencia_cierre,
                    s.observacion_cierre,
                    (SELECT ua.nombre
                       FROM autorizacion_caja a
                       INNER JOIN usuario ua ON ua.id_usuario = a.id_usuario_admin
                      WHERE a.id_sesion_caja = s.id_sesion_caja
                        AND a.tipo_operacion = 'CIERRE_ANTICIPADO'
                      ORDER BY a.id_autorizacion_caja DESC
                      LIMIT 1) AS authorized_by_admin,
                    (SELECT COUNT(*)
                       FROM movimiento_caja m
                      WHERE m.id_sesion_caja = s.id_sesion_caja
                        AND m.tipo_movimiento = 'RETIRO_OMITIDO') AS omitted_withdrawals_count
                FROM sesion_caja s
                INNER JOIN usuario u ON u.id_usuario = s.id_usuario_vendedor
                WHERE s.estado = 'CERRADA'
                  AND s.fecha_hora_cierre >= :desde_inicio
                  AND s.fecha_hora_cierre < :hasta_fin_exclusivo";

        $params = [
            ':desde_inicio'        => $desdeInicio,
            ':hasta_fin_exclusivo' => $hastaFinExclusivo,
        ];
        if ($idVendedor !== null) {
            $sql .= " AND s.id_usuario_vendedor = :id_vendedor";
            $params[':id_vendedor'] = $idVendedor;
        }
        if ($idTurno !== null) {
            $sql .= " AND s.id_turno = :id_turno";
            $params[':id_turno'] = $idTurno;
        }
        $sql .= " ORDER BY s.fecha_hora_cierre DESC, s.id_sesion_caja DESC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crea un snapshot inmutable de consulta de cierres de caja con sus ítems materializados.
     */
    public function crearSnapshotCierresCaja(array $snapshot, array $items): int
    {
        $sql = "INSERT INTO reporte_consulta_snapshot (
                    referencia_consulta,
                    id_usuario,
                    tipo_reporte,
                    hash_filtros,
                    filtros_json,
                    resumen_json,
                    total_registros,
                    exportable,
                    creado_en,
                    expira_en
                ) VALUES (
                    :referencia_consulta,
                    :id_usuario,
                    'cierres_caja',
                    :hash_filtros,
                    :filtros_json,
                    :resumen_json,
                    :total_registros,
                    :exportable,
                    :creado_en,
                    :expira_en
                )";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':referencia_consulta' => (string)$snapshot['referencia_consulta'],
            ':id_usuario'          => (int)$snapshot['id_usuario'],
            ':hash_filtros'        => (string)$snapshot['hash_filtros'],
            ':filtros_json'        => (string)$snapshot['filtros_json'],
            ':resumen_json'        => (string)$snapshot['resumen_json'],
            ':total_registros'     => (int)$snapshot['total_registros'],
            ':exportable'          => (int)$snapshot['exportable'],
            ':creado_en'           => (string)$snapshot['creado_en'],
            ':expira_en'           => (string)$snapshot['expira_en'],
        ]);

        $idSnapshot = (int)$this->conect->lastInsertId();

        if (!empty($items)) {
            $sqlItem = "INSERT INTO reporte_caja_snapshot_item (
                            id_snapshot,
                            orden_posicion,
                            id_sesion_caja,
                            proyeccion_json
                        ) VALUES (
                            :id_snapshot,
                            :orden_posicion,
                            :id_sesion_caja,
                            :proyeccion_json
                        )";
            $stmtItem = $this->conect->prepare($sqlItem);
            foreach ($items as $idx => $item) {
                $stmtItem->execute([
                    ':id_snapshot'     => $idSnapshot,
                    ':orden_posicion'  => $idx + 1,
                    ':id_sesion_caja'  => (int)$item['session_id'],
                    ':proyeccion_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        }

        return $idSnapshot;
    }

    /**
     * Obtiene un snapshot de cierres de caja por id_snapshot entero perteneciente al usuario.
     */
    public function obtenerSnapshotCierresCajaPorIdParaUsuario(int $idSnapshot, int $idUsuario): ?array
    {
        $sql = "SELECT
                    id_snapshot,
                    referencia_consulta,
                    id_usuario,
                    tipo_reporte,
                    hash_filtros,
                    filtros_json,
                    resumen_json,
                    total_registros,
                    exportable,
                    creado_en,
                    expira_en
                FROM reporte_consulta_snapshot
                WHERE id_snapshot = :id_snapshot
                  AND id_usuario = :id_usuario
                  AND tipo_reporte = 'cierres_caja'
                LIMIT 1";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_snapshot' => $idSnapshot,
            ':id_usuario'  => $idUsuario,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Renueva la fecha de expiración de un snapshot vigente (sliding window 30 min).
     */
    public function renovarExpiracionSnapshot(int $idSnapshot, string $nuevaExpiracion): void
    {
        $stmt = $this->conect->prepare(
            'UPDATE reporte_consulta_snapshot SET expira_en = :expira_en WHERE id_snapshot = :id_snapshot'
        );
        $stmt->execute([
            ':expira_en'   => $nuevaExpiracion,
            ':id_snapshot' => $idSnapshot,
        ]);
    }

    /**
     * Lista ítems paginados de un snapshot de cierres de caja en orden_posicion ASC.
     */
    public function listarItemsSnapshotCierresCaja(int $idSnapshot, int $limit, int $offset): array
    {
        $sql = "SELECT
                    orden_posicion,
                    id_sesion_caja,
                    proyeccion_json
                FROM reporte_caja_snapshot_item
                WHERE id_snapshot = :id_snapshot
                ORDER BY orden_posicion ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conect->prepare($sql);
        $stmt->bindValue(':id_snapshot', $idSnapshot, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
