<?php

namespace Models;

use Libraries\Core\Model;
use PDO;

class KardexModel extends Model {

    public function __construct() {
        parent::__construct();
        $this->table = 'kardex';
        $this->primaryKey = 'id_kardex';
    }

    /**
     * Registra un movimiento en el kardex
     */
    public function registrarMovimiento(array $datos) {
        return $this->insert($datos);
    }

    /**
     * Obtiene el saldo histórico inmediatamente anterior al inicio del período
     * para un producto específico.
     * Lee estrictamente el stock_resultante del último movimiento registrado antes de fecha_inicio_inicio.
     */
    public function obtenerSaldoInicial(int $idProducto, string $fechaInicioInicio): int {
        $sql = "SELECT stock_resultante 
                FROM kardex 
                WHERE id_producto = :id_producto 
                  AND fecha_movimiento < :fecha_inicio_inicio
                ORDER BY fecha_movimiento DESC, id_kardex DESC 
                LIMIT 1";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([
            ':id_producto' => $idProducto,
            ':fecha_inicio_inicio' => $fechaInicioInicio,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['stock_resultante'] : 0;
    }

    /**
     * Obtiene información básica del producto para el encabezado del reporte
     * (incluso si está inactivo).
     */
    public function obtenerProductoParaReporte(int $idProducto): ?array {
        $sql = "SELECT p.id_producto, p.codigo_barra, p.nombre, p.estado,
                       p.stock_actual, p.stock_minimo,
                       c.nombre AS categoria, sc.nombre AS subcategoria,
                       u.abreviatura AS unidad
                FROM producto p
                LEFT JOIN categoria c ON c.id_categoria = p.id_categoria
                LEFT JOIN subcategoria sc ON sc.id_subcategoria = p.id_subcategoria
                LEFT JOIN unidad_medida u ON u.id_unidad = p.id_unidad
                WHERE p.id_producto = :id_producto
                LIMIT 1";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([':id_producto' => $idProducto]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Consulta canónica de movimientos en kardex aplicando filtros en el rango
     * y orden cronológico determinístico: fecha_movimiento ASC, id_kardex ASC.
     */
    public function consultarKardexCanonico(array $filtros): array {
        $sql = "SELECT k.*
                FROM kardex k
                WHERE k.fecha_movimiento BETWEEN :fecha_inicio AND :fecha_fin";
        $params = [
            ':fecha_inicio' => $filtros['fecha_inicio_inicio'],
            ':fecha_fin' => $filtros['fecha_fin_fin'],
        ];

        if (!empty($filtros['id_producto'])) {
            $sql .= " AND k.id_producto = :id_producto";
            $params[':id_producto'] = (int)$filtros['id_producto'];
        }

        if (!empty($filtros['id_categoria'])) {
            $sql .= " AND k.id_categoria_snapshot = :id_categoria";
            $params[':id_categoria'] = (int)$filtros['id_categoria'];
        }

        if (!empty($filtros['id_subcategoria'])) {
            $sql .= " AND k.id_subcategoria_snapshot = :id_subcategoria";
            $params[':id_subcategoria'] = (int)$filtros['id_subcategoria'];
        }

        if (!empty($filtros['q'])) {
            $sql .= " AND (k.producto_nombre_snapshot LIKE :q OR k.producto_codigo_snapshot LIKE :q)";
            $params[':q'] = '%' . trim($filtros['q']) . '%';
        }

        $sql .= " ORDER BY k.fecha_movimiento ASC, k.id_kardex ASC";

        $stmt = $this->conect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Consulta canónica de variaciones de inventario (bajas y ajustes exclusivamente).
     * Excluye compras, ventas, anulaciones y correcciones.
     * Orden cronológico determinístico: fecha_movimiento ASC, id_kardex ASC.
     */
    public function consultarVariacionesCanonicas(array $filtros): array {
        $sql = "SELECT k.*
                FROM kardex k
                WHERE k.origen_operacion IN ('BAJA_DANO', 'BAJA_VENCIMIENTO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO')
                  AND k.fecha_movimiento BETWEEN :fecha_inicio AND :fecha_fin";
        $params = [
            ':fecha_inicio' => $filtros['fecha_inicio_inicio'],
            ':fecha_fin' => $filtros['fecha_fin_fin'],
        ];

        if (!empty($filtros['id_producto'])) {
            $sql .= " AND k.id_producto = :id_producto";
            $params[':id_producto'] = (int)$filtros['id_producto'];
        }

        if (!empty($filtros['id_categoria'])) {
            $sql .= " AND k.id_categoria_snapshot = :id_categoria";
            $params[':id_categoria'] = (int)$filtros['id_categoria'];
        }

        if (!empty($filtros['id_subcategoria'])) {
            $sql .= " AND k.id_subcategoria_snapshot = :id_subcategoria";
            $params[':id_subcategoria'] = (int)$filtros['id_subcategoria'];
        }

        if (!empty($filtros['tipo_variacion'])) {
            $sql .= " AND k.tipo_movimiento = :tipo_variacion";
            $params[':tipo_variacion'] = $filtros['tipo_variacion'];
        }

        if (!empty($filtros['sentido']) && $filtros['sentido'] !== 'TODOS') {
            $sql .= " AND k.sentido_movimiento = :sentido";
            $params[':sentido'] = $filtros['sentido'];
        }

        if (!empty($filtros['q'])) {
            $sql .= " AND (k.producto_nombre_snapshot LIKE :q OR k.producto_codigo_snapshot LIKE :q)";
            $params[':q'] = '%' . trim($filtros['q']) . '%';
        }

        $sql .= " ORDER BY k.fecha_movimiento ASC, k.id_kardex ASC";

        $stmt = $this->conect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Guarda la instantánea de consulta congelada en reporte_consulta_snapshot
     * y reporte_consulta_snapshot_item para consistencia de paginación y exportación.
     */
    public function guardarSnapshotConsulta(
        string $referencia,
        int $idUsuario,
        string $tipoReporte,
        string $hashFiltros,
        array $filtros,
        array $resumen,
        int $totalRegistros,
        bool $exportable,
        array $kardexIds
    ): array {
        $db = $this->conect();
        $inTransaction = $db->inTransaction();
        if (!$inTransaction) {
            $db->beginTransaction();
        }

        try {
            // Limpieza preventiva de snapshots vencidos hace más de 1 día
            $db->exec("DELETE FROM reporte_consulta_snapshot WHERE expira_en < DATE_SUB(NOW(), INTERVAL 1 DAY)");

            $sqlSnapshot = "INSERT INTO reporte_consulta_snapshot 
                            (referencia_consulta, id_usuario, tipo_reporte, hash_filtros, filtros_json, resumen_json, total_registros, exportable, creado_en, expira_en)
                            VALUES (:ref, :usuario, :tipo, :hash, :filtros, :resumen, :total, :exportable, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))";
            $stmt = $db->prepare($sqlSnapshot);
            $stmt->execute([
                ':ref' => $referencia,
                ':usuario' => $idUsuario,
                ':tipo' => $tipoReporte,
                ':hash' => $hashFiltros,
                ':filtros' => json_encode($filtros, JSON_UNESCAPED_UNICODE),
                ':resumen' => json_encode($resumen, JSON_UNESCAPED_UNICODE),
                ':total' => $totalRegistros,
                ':exportable' => $exportable ? 1 : 0,
            ]);
            $idSnapshot = (int)$db->lastInsertId();

            if (!empty($kardexIds)) {
                $chunkSize = 250;
                $chunks = array_chunk($kardexIds, $chunkSize);
                $orden = 1;
                foreach ($chunks as $chunk) {
                    $values = [];
                    $placeholders = [];
                    foreach ($chunk as $idKardex) {
                        $placeholders[] = "(?, ?, ?)";
                        $values[] = $idSnapshot;
                        $values[] = $orden++;
                        $values[] = (int)$idKardex;
                    }
                    $sqlItems = "INSERT INTO reporte_consulta_snapshot_item (id_snapshot, orden_posicion, id_kardex) VALUES " . implode(", ", $placeholders);
                    $stmtItems = $db->prepare($sqlItems);
                    $stmtItems->execute($values);
                }
            }

            if (!$inTransaction) {
                $db->commit();
            }

            $expiraEn = $db->query("SELECT expira_en FROM reporte_consulta_snapshot WHERE id_snapshot = {$idSnapshot}")->fetchColumn();

            return [
                'id_snapshot' => $idSnapshot,
                'expira_en' => $expiraEn ?: '',
            ];
        } catch (\Throwable $e) {
            if (!$inTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Obtiene la cabecera de la instantánea de consulta por su referencia y tipo.
     */
    public function obtenerSnapshotPorReferencia(string $referencia, string $tipoReporte = 'kardex'): ?array {
        $sql = "SELECT *, (expira_en < NOW()) AS esta_expirado FROM reporte_consulta_snapshot 
                WHERE referencia_consulta = :ref AND tipo_reporte = :tipo 
                LIMIT 1";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([
            ':ref' => $referencia,
            ':tipo' => $tipoReporte,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Renueva la expiración de la consulta activa.
     */
    public function renovarExpiracionSnapshot(int $idSnapshot, int $minutos = 30): void {
        $sql = "UPDATE reporte_consulta_snapshot 
                SET expira_en = DATE_ADD(NOW(), INTERVAL :minutos MINUTE) 
                WHERE id_snapshot = :id";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([
            ':id' => $idSnapshot,
            ':minutos' => $minutos,
        ]);
    }

    /**
     * Obtiene los movimientos congelados en la instantánea para una página específica.
     */
    public function obtenerItemsSnapshotPaginados(int $idSnapshot, int $offset, int $limit): array {
        $sql = "SELECT k.*, item.orden_posicion
                FROM reporte_consulta_snapshot_item item
                INNER JOIN kardex k ON k.id_kardex = item.id_kardex
                WHERE item.id_snapshot = :id_snapshot
                ORDER BY item.orden_posicion ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->conect()->prepare($sql);
        $stmt->bindValue(':id_snapshot', $idSnapshot, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Recupera todo el orden congelado para la exportación de una consulta autorizada. */
    public function obtenerTodosItemsSnapshot(int $idSnapshot): array {
        $sql = "SELECT k.*, item.orden_posicion
                  FROM reporte_consulta_snapshot_item item
                  INNER JOIN kardex k ON k.id_kardex = item.id_kardex
                 WHERE item.id_snapshot = :id_snapshot
                 ORDER BY item.orden_posicion ASC";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([':id_snapshot' => $idSnapshot]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Devuelve la marca de tiempo del mismo reloj de base usado por kardex. */
    public function obtenerFechaHoraServidor(): string {
        return (string)$this->conect->query('SELECT CURRENT_TIMESTAMP()')->fetchColumn();
    }

    /**
     * Compatibilidad con llamadas legacy a obtenerMovimientos
     */
    public function obtenerMovimientos(array $filtros): array {
        $service = new \Services\ReporteService();
        $resultado = $service->reporteKardex($filtros);
        return [
            'movimientos' => $resultado['items'] ?? $resultado['movimientos'] ?? [],
            'paginacion' => $resultado['paginacion'] ?? [
                'pagina' => $resultado['pagination']['page'] ?? 1,
                'por_pagina' => $resultado['pagination']['limit'] ?? 10,
                'total' => $resultado['pagination']['total'] ?? 0,
                'total_paginas' => $resultado['pagination']['pages'] ?? 1,
            ],
            'resumen_producto' => $resultado['resumen_producto'] ?? null,
            'exportable' => $resultado['exportable'] ?? false,
            'referencia_consulta' => $resultado['referencia_consulta'] ?? null,
        ];
    }
}
