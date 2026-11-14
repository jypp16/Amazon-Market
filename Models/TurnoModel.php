<?php

namespace Models;

use Libraries\Core\Model;
use PDO;

class TurnoModel extends Model
{
    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->conect = $pdo;
        } else {
            parent::__construct();
        }
    }

    /**
     * Subconsulta SQL reutilizable que determina si una fila vigente de programacion_semanal (alias ps)
     * tiene vigencia efectiva en la semana actual o en alguna semana futura.
     */
    private static function sqlCondicionReglaEfectivaActualOFutura(string $aliasPs = 'ps'): string
    {
        $semanaActual = "DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";
        $desdeEfectivo = "GREATEST({$aliasPs}.semana_inicio, {$semanaActual})";

        return "(
            (
                {$aliasPs}.alcance = 'semana'
                AND {$aliasPs}.semana_inicio >= {$semanaActual}
            )
            OR (
                {$aliasPs}.alcance = 'desde'
                AND (
                    NOT EXISTS (
                        SELECT 1
                        FROM programacion_semanal ps_next
                        WHERE ps_next.id_usuario_vendedor = {$aliasPs}.id_usuario_vendedor
                          AND ps_next.es_vigente = 1
                          AND ps_next.alcance = 'desde'
                          AND ps_next.semana_inicio > {$aliasPs}.semana_inicio
                    )
                    OR (
                        (
                            SELECT MIN(ps_next.semana_inicio)
                            FROM programacion_semanal ps_next
                            WHERE ps_next.id_usuario_vendedor = {$aliasPs}.id_usuario_vendedor
                              AND ps_next.es_vigente = 1
                              AND ps_next.alcance = 'desde'
                              AND ps_next.semana_inicio > {$aliasPs}.semana_inicio
                        ) > {$desdeEfectivo}
                        AND (
                            SELECT COUNT(DISTINCT ps_exc.semana_inicio)
                            FROM programacion_semanal ps_exc
                            WHERE ps_exc.id_usuario_vendedor = {$aliasPs}.id_usuario_vendedor
                              AND ps_exc.es_vigente = 1
                              AND ps_exc.alcance = 'semana'
                              AND ps_exc.semana_inicio >= {$desdeEfectivo}
                              AND ps_exc.semana_inicio < (
                                  SELECT MIN(ps_next2.semana_inicio)
                                  FROM programacion_semanal ps_next2
                                  WHERE ps_next2.id_usuario_vendedor = {$aliasPs}.id_usuario_vendedor
                                    AND ps_next2.es_vigente = 1
                                    AND ps_next2.alcance = 'desde'
                                    AND ps_next2.semana_inicio > {$aliasPs}.semana_inicio
                              )
                        ) < DATEDIFF(
                            (
                                SELECT MIN(ps_next3.semana_inicio)
                                FROM programacion_semanal ps_next3
                                WHERE ps_next3.id_usuario_vendedor = {$aliasPs}.id_usuario_vendedor
                                  AND ps_next3.es_vigente = 1
                                  AND ps_next3.alcance = 'desde'
                                  AND ps_next3.semana_inicio > {$aliasPs}.semana_inicio
                            ),
                            {$desdeEfectivo}
                        ) DIV 7
                    )
                )
            )
        )";
    }

    /**
     * EP-01: Lista todos los turnos activos (estado = 1) ordenados por hora_inicio ASC, id_turno ASC,
     * calculando vendedores_asignados efectivos en la semana actual o futuras.
     */
    public function listarActivosConAsignadosEfectivos(): array
    {
        $condicionEfectiva = self::sqlCondicionReglaEfectivaActualOFutura('ps');

        $sql = "SELECT
                    t.id_turno,
                    t.nombre,
                    TIME_FORMAT(t.hora_inicio, '%H:%i') AS hora_inicio,
                    TIME_FORMAT(t.hora_fin, '%H:%i') AS hora_fin,
                    t.estado,
                    (
                        SELECT COUNT(DISTINCT ps.id_usuario_vendedor)
                        FROM programacion_semanal ps
                        INNER JOIN programacion_semanal_detalle psd
                            ON psd.id_programacion = ps.id_programacion
                        WHERE ps.es_vigente = 1
                          AND psd.id_turno = t.id_turno
                          AND {$condicionEfectiva}
                    ) AS vendedores_asignados
                FROM turno t
                WHERE t.estado = 1
                ORDER BY t.hora_inicio ASC, t.id_turno ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = self::formatearFilaTurno($row);
        }
        return $result;
    }

    /**
     * Cuenta cuántos vendedores distintos tienen asignado efectivamente el turno en la semana actual o futuras.
     */
    public function contarVendedoresAsignadosEfectivos(int $idTurno, bool $lockShare = false): int
    {
        $condicionEfectiva = self::sqlCondicionReglaEfectivaActualOFutura('ps');
        $lockClause = $lockShare ? ' LOCK IN SHARE MODE' : '';

        $sql = "SELECT COUNT(DISTINCT ps.id_usuario_vendedor) AS total
                FROM programacion_semanal ps
                INNER JOIN programacion_semanal_detalle psd
                    ON psd.id_programacion = ps.id_programacion
                WHERE ps.es_vigente = 1
                  AND psd.id_turno = :id_turno
                  AND {$condicionEfectiva}{$lockClause}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_turno' => $idTurno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    /**
     * Obtiene un turno por ID (opcionalmente bloqueando la fila con FOR UPDATE).
     */
    public function obtenerPorId(int $idTurno, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    t.id_turno,
                    t.nombre,
                    TIME_FORMAT(t.hora_inicio, '%H:%i') AS hora_inicio,
                    TIME_FORMAT(t.hora_fin, '%H:%i') AS hora_fin,
                    t.estado
                FROM turno t
                WHERE t.id_turno = :id_turno
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_turno' => $idTurno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Busca si existe otro turno activo con el mismo nombre normalizado (LOWER(TRIM(nombre))).
     */
    public function buscarActivoPorNombreNormalizado(string $nombre, ?int $excluirIdTurno = null, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT id_turno, nombre, estado
                FROM turno
                WHERE estado = 1
                  AND nombre_activo_normalizado = LOWER(TRIM(:nombre))";
        $params = [':nombre' => $nombre];

        if ($excluirIdTurno !== null) {
            $sql .= " AND id_turno <> :excluir_id";
            $params[':excluir_id'] = $excluirIdTurno;
        }

        $sql .= " LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Inserta un nuevo turno activo.
     */
    public function insertarTurno(string $nombre, string $horaInicio, string $horaFin, int $idUsuarioAutor, ?int $idSolicitud = null): int
    {
        $sql = "INSERT INTO turno (
                    nombre,
                    hora_inicio,
                    hora_fin,
                    estado,
                    id_usuario_creacion,
                    id_solicitud_creacion
                ) VALUES (
                    :nombre,
                    :hora_inicio,
                    :hora_fin,
                    1,
                    :id_creacion,
                    :id_solicitud_creacion
                )";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':nombre'                => $nombre,
            ':hora_inicio'           => $horaInicio . ':00',
            ':hora_fin'              => $horaFin . ':00',
            ':id_creacion'           => $idUsuarioAutor,
            ':id_solicitud_creacion' => $idSolicitud,
        ]);

        return (int)$this->conect->lastInsertId();
    }

    /**
     * Actualiza un turno existente conservando su PK e historial.
     */
    public function actualizarTurno(int $idTurno, string $nombre, string $horaInicio, string $horaFin, ?int $idSolicitud = null): void
    {
        $sql = "UPDATE turno
                SET nombre = :nombre,
                    hora_inicio = :hora_inicio,
                    hora_fin = :hora_fin,
                    id_solicitud_ultima_edicion = :id_solicitud
                WHERE id_turno = :id_turno";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':nombre'       => $nombre,
            ':hora_inicio'  => $horaInicio . ':00',
            ':hora_fin'     => $horaFin . ':00',
            ':id_solicitud' => $idSolicitud,
            ':id_turno'     => $idTurno,
        ]);
    }

    /**
     * Realiza la baja lógica de un turno (estado = 0).
     */
    public function darBajaLogica(int $idTurno, ?int $idSolicitud = null): void
    {
        $sql = "UPDATE turno
                SET estado = 0,
                    baja_en = NOW(),
                    id_solicitud_ultima_edicion = :id_solicitud
                WHERE id_turno = :id_turno";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_solicitud' => $idSolicitud,
            ':id_turno'     => $idTurno,
        ]);
    }

    /**
     * Verifica si existe al menos una sesión de caja en estado ABIERTA asociada al turno.
     */
    public function tieneSesionCajaAbierta(int $idTurno, bool $lockShare = false): bool
    {
        $lock = $lockShare ? ' LOCK IN SHARE MODE' : '';
        $sql = "SELECT 1
                FROM sesion_caja
                WHERE id_turno = :id_turno
                  AND estado = 'ABIERTA'
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_turno' => $idTurno]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Formatea una fila de turno según el contrato público de EP-01/02/03.
     */
    public static function formatearFilaTurno(array $row, ?int $vendedoresAsignadosOverride = null): array
    {
        $idTurno = (int)$row['id_turno'];
        $vendedoresAsignados = $vendedoresAsignadosOverride !== null
            ? $vendedoresAsignadosOverride
            : (int)($row['vendedores_asignados'] ?? 0);

        return [
            'id'                   => $idTurno,
            'nombre'               => (string)$row['nombre'],
            'inicio'               => (string)$row['hora_inicio'],
            'fin'                  => (string)$row['hora_fin'],
            'estado'               => (int)$row['estado'],
            'vendedores_asignados' => $vendedoresAsignados,
        ];
    }
}
