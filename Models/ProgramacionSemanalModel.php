<?php

namespace Models;

use Libraries\Core\Model;
use PDO;

class ProgramacionSemanalModel extends Model
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
     * Obtiene un usuario por su ID (opcionalmente bloqueando con FOR UPDATE).
     */
    public function obtenerUsuario(int $idUsuario, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT id_usuario, id_rol, estado, nombre, username
                FROM usuario
                WHERE id_usuario = :id_usuario
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_usuario' => $idUsuario]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Bloquea en orden ascendente de id_turno y devuelve los turnos solicitados indexados por id_turno.
     *
     * @param int[] $idsTurnos
     * @return array<int, array>
     */
    public function bloquearYObtenerTurnosPorIds(array $idsTurnos): array
    {
        if (empty($idsTurnos)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $idsTurnos)));
        sort($ids, SORT_NUMERIC);

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    id_turno,
                    nombre,
                    TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
                    TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin,
                    estado
                FROM turno
                WHERE id_turno IN ({$placeholders})
                ORDER BY id_turno ASC
                FOR UPDATE";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id_turno']] = $row;
        }
        return $map;
    }

    /**
     * Obtiene la excepción vigente (alcance = 'semana') para un vendedor y lunes exacto.
     */
    public function obtenerExcepcionVigenteEnSemana(int $idVendedor, string $semanaInicio, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    id_programacion,
                    id_usuario_vendedor,
                    semana_inicio,
                    alcance,
                    version_programacion,
                    es_vigente,
                    vigencia_activa,
                    id_programacion_previa,
                    id_programacion_origen_duplicacion,
                    id_usuario_autor,
                    id_solicitud_idempotencia,
                    creado_en,
                    reemplazada_en
                FROM programacion_semanal
                WHERE id_usuario_vendedor = :id_vendedor
                  AND semana_inicio = :semana_inicio
                  AND alcance = 'semana'
                  AND es_vigente = 1
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_vendedor'   => $idVendedor,
            ':semana_inicio' => $semanaInicio,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Obtiene la base vigente (alcance = 'desde') más reciente aplicable a una semana dada (semana_inicio <= W).
     */
    public function obtenerBaseAplicableEnSemana(int $idVendedor, string $semanaInicio, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    id_programacion,
                    id_usuario_vendedor,
                    semana_inicio,
                    alcance,
                    version_programacion,
                    es_vigente,
                    vigencia_activa,
                    id_programacion_previa,
                    id_programacion_origen_duplicacion,
                    id_usuario_autor,
                    id_solicitud_idempotencia,
                    creado_en,
                    reemplazada_en
                FROM programacion_semanal
                WHERE id_usuario_vendedor = :id_vendedor
                  AND semana_inicio <= :semana_inicio
                  AND alcance = 'desde'
                  AND es_vigente = 1
                ORDER BY semana_inicio DESC, version_programacion DESC
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_vendedor'   => $idVendedor,
            ':semana_inicio' => $semanaInicio,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Determina si existe programación efectiva (excepción o base aplicable) para un vendedor en una semana dada.
     */
    public function tieneProgramacionEfectivaEnSemana(int $idVendedor, string $semanaInicio): bool
    {
        $sql = "SELECT 1
                FROM programacion_semanal
                WHERE id_usuario_vendedor = :id_vendedor
                  AND es_vigente = 1
                  AND (
                      (alcance = 'semana' AND semana_inicio = :semana_exacta)
                      OR (alcance = 'desde' AND semana_inicio <= :semana_base)
                  )
                LIMIT 1";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_vendedor'   => $idVendedor,
            ':semana_exacta' => $semanaInicio,
            ':semana_base'   => $semanaInicio,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Obtiene y agrupa los detalles de una versión de programación semanal.
     * Nota: NO filtra por t.estado = 1 para preservar la consulta histórica de programaciones pasadas.
     */
    public function obtenerDetallesProgramacion(int $idProgramacion): array
    {
        $sql = "SELECT
                    psd.id_turno,
                    t.nombre,
                    TIME_FORMAT(t.hora_inicio, '%H:%i') AS hora_inicio,
                    TIME_FORMAT(t.hora_fin, '%H:%i') AS hora_fin,
                    psd.dia_semana
                FROM programacion_semanal_detalle psd
                INNER JOIN turno t
                    ON t.id_turno = psd.id_turno
                WHERE psd.id_programacion = :id_programacion
                ORDER BY t.hora_inicio ASC, psd.id_turno ASC, psd.dia_semana ASC";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_programacion' => $idProgramacion]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $agrupados = [];
        foreach ($rows as $row) {
            $idTurno = (int)$row['id_turno'];
            if (!isset($agrupados[$idTurno])) {
                $agrupados[$idTurno] = [
                    'turno_id' => $idTurno,
                    'nombre'   => (string)$row['nombre'],
                    'inicio'   => (string)$row['hora_inicio'],
                    'fin'      => (string)$row['hora_fin'],
                    'dias'     => [],
                ];
            }
            $agrupados[$idTurno]['dias'][] = (int)$row['dia_semana'];
        }

        return array_values($agrupados);
    }

    /**
     * Obtiene la versión vigente exacta de la regla (id_usuario_vendedor, semana_inicio, alcance).
     */
    public function obtenerVersionVigentePorRegla(
        int $idVendedor,
        string $semanaInicio,
        string $alcance,
        bool $forUpdate = false
    ): ?array {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT
                    id_programacion,
                    id_usuario_vendedor,
                    semana_inicio,
                    alcance,
                    version_programacion,
                    es_vigente,
                    vigencia_activa,
                    id_programacion_previa
                FROM programacion_semanal
                WHERE id_usuario_vendedor = :id_vendedor
                  AND semana_inicio = :semana_inicio
                  AND alcance = :alcance
                  AND es_vigente = 1
                LIMIT 1{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_vendedor'   => $idVendedor,
            ':semana_inicio' => $semanaInicio,
            ':alcance'       => $alcance,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Obtiene el máximo número de versión registrado para la regla (id_usuario_vendedor, semana_inicio, alcance).
     */
    public function obtenerMaxVersionPorRegla(
        int $idVendedor,
        string $semanaInicio,
        string $alcance,
        bool $forUpdate = false
    ): int {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT COALESCE(MAX(version_programacion), 0) AS max_version
                FROM programacion_semanal
                WHERE id_usuario_vendedor = :id_vendedor
                  AND semana_inicio = :semana_inicio
                  AND alcance = :alcance{$lock}";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_vendedor'   => $idVendedor,
            ':semana_inicio' => $semanaInicio,
            ':alcance'       => $alcance,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['max_version'] ?? 0);
    }

    /**
     * Desactiva únicamente la versión vigente previa de la misma regla (id_usuario_vendedor, semana_inicio, alcance),
     * conservando intacta su cabecera y sus filas en programacion_semanal_detalle.
     */
    public function desactivarVersionVigente(int $idProgramacionPrevia): void
    {
        $sql = "UPDATE programacion_semanal
                SET es_vigente = 0,
                    vigencia_activa = NULL,
                    reemplazada_en = NOW()
                WHERE id_programacion = :id_programacion";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([':id_programacion' => $idProgramacionPrevia]);
    }

    /**
     * Inserta una nueva cabecera vigente en programacion_semanal.
     */
    public function insertarCabeceraProgramacion(
        int $idVendedor,
        string $semanaInicio,
        string $alcance,
        int $version,
        int $idUsuarioAutor,
        ?int $idSolicitud,
        ?int $idProgramacionPrevia = null,
        ?int $idProgramacionOrigenDuplicacion = null
    ): int {
        $sql = "INSERT INTO programacion_semanal (
                    id_usuario_vendedor,
                    semana_inicio,
                    alcance,
                    version_programacion,
                    es_vigente,
                    vigencia_activa,
                    id_programacion_previa,
                    id_programacion_origen_duplicacion,
                    id_usuario_autor,
                    id_solicitud_idempotencia,
                    creado_en
                ) VALUES (
                    :id_vendedor,
                    :semana_inicio,
                    :alcance,
                    :version_programacion,
                    1,
                    1,
                    :id_programacion_previa,
                    :id_programacion_origen_duplicacion,
                    :id_usuario_autor,
                    :id_solicitud_idempotencia,
                    NOW()
                )";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_vendedor'                        => $idVendedor,
            ':semana_inicio'                      => $semanaInicio,
            ':alcance'                            => $alcance,
            ':version_programacion'               => $version,
            ':id_programacion_previa'             => $idProgramacionPrevia,
            ':id_programacion_origen_duplicacion' => $idProgramacionOrigenDuplicacion,
            ':id_usuario_autor'                   => $idUsuarioAutor,
            ':id_solicitud_idempotencia'          => $idSolicitud,
        ]);

        return (int)$this->conect->lastInsertId();
    }

    /**
     * Inserta un día asignado a un turno dentro del detalle de una programación semanal.
     */
    public function insertarDetalleProgramacion(int $idProgramacion, int $idTurno, int $diaSemana): void
    {
        $sql = "INSERT INTO programacion_semanal_detalle (
                    id_programacion,
                    id_turno,
                    dia_semana
                ) VALUES (
                    :id_programacion,
                    :id_turno,
                    :dia_semana
                )";

        $stmt = $this->conect->prepare($sql);
        $stmt->execute([
            ':id_programacion' => $idProgramacion,
            ':id_turno'        => $idTurno,
            ':dia_semana'      => $diaSemana,
        ]);
    }
}
