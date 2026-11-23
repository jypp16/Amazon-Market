<?php

namespace Models;

use Libraries\Core\Model;
use PDO;

class OperacionInventarioModel extends Model {
    public const ORIGEN_A_TIPO = [
        'BAJA_DANO'        => 'baja_danio',
        'BAJA_VENCIMIENTO' => 'baja_vencimiento',
        'AJUSTE_POSITIVO'  => 'ajuste_positivo',
        'AJUSTE_NEGATIVO'  => 'ajuste_negativo',
    ];

    public const TIPO_A_ORIGEN = [
        'baja_danio'       => 'BAJA_DANO',
        'baja_vencimiento' => 'BAJA_VENCIMIENTO',
        'ajuste_positivo'  => 'AJUSTE_POSITIVO',
        'ajuste_negativo'  => 'AJUSTE_NEGATIVO',
    ];

    public function __construct() {
        parent::__construct();
        $this->table = 'operacion_inventario';
        $this->primaryKey = 'id_operacion';
    }

    /**
     * Consulta paginada de movimientos de inventario (una fila por operación con valores vigentes).
     * El filtro por tipo aplica sobre origen_vigente tras cualquier reclasificación.
     * Orden estable: fecha_registro DESC, id_operacion DESC.
     */
    public function listarMovimientos(array $filtros): array {
        $where = [
            "oi.origen_vigente IN ('BAJA_DANO', 'BAJA_VENCIMIENTO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO')"
        ];
        $params = [];

        $q = trim((string)($filtros['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.nombre LIKE :q_nombre OR p.codigo_barra LIKE :q_codigo)';
            $like = '%' . $q . '%';
            $params[':q_nombre'] = $like;
            $params[':q_codigo'] = $like;
        }

        $tipo = trim((string)($filtros['tipo'] ?? ''));
        if ($tipo !== '' && isset(self::TIPO_A_ORIGEN[$tipo])) {
            $where[] = 'oi.origen_vigente = :origen_vigente';
            $params[':origen_vigente'] = self::TIPO_A_ORIGEN[$tipo];
        }

        $desde = trim((string)($filtros['desde'] ?? ''));
        if ($desde !== '') {
            $where[] = 'oi.fecha_registro >= :desde_inicio';
            $params[':desde_inicio'] = $desde . ' 00:00:00';
        }

        $hasta = trim((string)($filtros['hasta'] ?? ''));
        if ($hasta !== '') {
            $where[] = 'oi.fecha_registro <= :hasta_fin';
            $params[':hasta_fin'] = $hasta . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $pagina = max(1, (int)($filtros['pagina'] ?? 1));
        $porPagina = max(1, min(50, (int)($filtros['por_pagina'] ?? 10)));

        $sqlCount = "SELECT COUNT(*) AS total
                     FROM operacion_inventario oi
                     INNER JOIN producto p ON p.id_producto = oi.id_producto
                     WHERE {$whereSql}";
        $stmtCount = $this->conect->prepare($sqlCount);
        foreach ($params as $k => $v) {
            $stmtCount->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmtCount->execute();
        $total = (int)$stmtCount->fetchColumn();

        $totalPaginas = max(1, (int)ceil($total / $porPagina));
        $paginaEfectiva = $total > 0 ? min($pagina, $totalPaginas) : 1;
        $offset = ($paginaEfectiva - 1) * $porPagina;

        $sql = "SELECT
                    oi.id_operacion,
                    oi.id_producto,
                    oi.origen_vigente,
                    oi.sentido_vigente,
                    oi.cantidad_vigente,
                    oi.version,
                    oi.total_correcciones,
                    oi.fecha_registro,
                    p.nombre AS producto_nombre,
                    p.codigo_barra AS producto_codigo_barra
                FROM operacion_inventario oi
                INNER JOIN producto p ON p.id_producto = oi.id_producto
                WHERE {$whereSql}
                ORDER BY oi.fecha_registro DESC, oi.id_operacion DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conect->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(static function (array $row): array {
            $origen = (string)$row['origen_vigente'];
            $tipoSlug = self::ORIGEN_A_TIPO[$origen] ?? strtolower($origen);
            $signo = ($tipoSlug === 'ajuste_positivo') ? 1 : -1;
            $cantidad = (int)$row['cantidad_vigente'];
            $totalCorr = (int)$row['total_correcciones'];
            $codigoBarra = ($row['producto_codigo_barra'] !== null && $row['producto_codigo_barra'] !== '')
                ? (string)$row['producto_codigo_barra']
                : null;

            return [
                'id'                 => (int)$row['id_operacion'],
                'id_operacion'       => (int)$row['id_operacion'],
                'id_producto'        => (int)$row['id_producto'],
                'fecha_hora'         => str_replace(' ', 'T', (string)$row['fecha_registro']),
                'producto'           => (string)$row['producto_nombre'],
                'codigo_barra'       => $codigoBarra,
                'tipo'               => $tipoSlug,
                'cantidad'           => $cantidad,
                'signo'              => $signo,
                'cantidad_firmada'   => $signo * $cantidad,
                'corregido'          => $totalCorr > 0,
                'total_correcciones' => $totalCorr,
                'version'            => (int)$row['version'],
            ];
        }, $rows);

        return [
            'items'         => $items,
            'total'         => $total,
            'pagina'        => $paginaEfectiva,
            'por_pagina'    => $porPagina,
            'total_paginas' => $totalPaginas,
        ];
    }

    /**
     * Obtiene el detalle de un movimiento de inventario separando datos vigentes,
     * registro original inmutable, stock actual del producto e historial cronológico de correcciones.
     */
    public function obtenerMovimientoPorId(int $idOperacion): ?array {
        $sql = "SELECT
                    oi.id_operacion,
                    oi.id_producto,
                    oi.id_usuario,
                    oi.categoria_operacion,
                    oi.origen_operacion,
                    oi.tipo_movimiento,
                    oi.sentido_movimiento,
                    oi.cantidad,
                    oi.saldo_anterior,
                    oi.saldo_resultante,
                    oi.justificacion,
                    oi.numero_lote,
                    oi.condicion_lote,
                    oi.fecha_registro,
                    oi.categoria_vigente,
                    oi.origen_vigente,
                    oi.tipo_movimiento_vigente,
                    oi.sentido_vigente,
                    oi.cantidad_vigente,
                    oi.justificacion_vigente,
                    oi.numero_lote_vigente,
                    oi.condicion_lote_vigente,
                    oi.version,
                    oi.total_correcciones,
                    p.nombre AS producto_nombre,
                    p.codigo_barra AS producto_codigo_barra,
                    p.stock_actual AS producto_stock_actual,
                    COALESCE(u.nombre, u.username, 'Sistema') AS responsable_nombre
                FROM operacion_inventario oi
                INNER JOIN producto p ON p.id_producto = oi.id_producto
                LEFT JOIN usuario u ON u.id_usuario = oi.id_usuario
                WHERE oi.id_operacion = :id
                  AND oi.origen_operacion IN ('BAJA_DANO', 'BAJA_VENCIMIENTO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO')
                LIMIT 1";

        $stmt = $this->conect->prepare($sql);
        $stmt->bindValue(':id', $idOperacion, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $origenOrig = (string)$row['origen_operacion'];
        $tipoOrig = self::ORIGEN_A_TIPO[$origenOrig] ?? strtolower($origenOrig);
        $signoOrig = ($tipoOrig === 'ajuste_positivo') ? 1 : -1;
        $cantidadOrig = (int)$row['cantidad'];

        $origenVig = (string)$row['origen_vigente'];
        $tipoVig = self::ORIGEN_A_TIPO[$origenVig] ?? strtolower($origenVig);
        $signoVig = ($tipoVig === 'ajuste_positivo') ? 1 : -1;
        $cantidadVig = (int)$row['cantidad_vigente'];

        $codigoBarra = ($row['producto_codigo_barra'] !== null && $row['producto_codigo_barra'] !== '')
            ? (string)$row['producto_codigo_barra']
            : null;
        $loteOrig = ($row['numero_lote'] !== null && trim((string)$row['numero_lote']) !== '')
            ? (string)$row['numero_lote']
            : null;
        $loteVig = ($row['numero_lote_vigente'] !== null && trim((string)$row['numero_lote_vigente']) !== '')
            ? (string)$row['numero_lote_vigente']
            : null;
        $fechaIso = str_replace(' ', 'T', (string)$row['fecha_registro']);

        // Historial cronológico de correcciones confirmadas
        $sqlCorr = "SELECT
                        c.id_correccion,
                        c.numero_correccion,
                        c.version_anterior,
                        c.version_nueva,
                        c.motivo,
                        c.efecto_anterior,
                        c.efecto_nuevo,
                        c.delta_stock,
                        c.stock_anterior,
                        c.stock_resultante,
                        c.id_kardex,
                        c.cambios_json,
                        c.fecha_correccion,
                        COALESCE(u.nombre, u.username, 'Sistema') AS responsable_nombre
                    FROM correccion_operacion_inventario c
                    LEFT JOIN usuario u ON u.id_usuario = c.id_usuario
                    WHERE c.id_operacion = :id
                    ORDER BY c.numero_correccion ASC, c.id_correccion ASC";
        $stmtCorr = $this->conect->prepare($sqlCorr);
        $stmtCorr->bindValue(':id', $idOperacion, PDO::PARAM_INT);
        $stmtCorr->execute();
        $corrRows = $stmtCorr->fetchAll(PDO::FETCH_ASSOC);

        $correcciones = array_map(static function (array $c): array {
            $cambios = json_decode((string)$c['cambios_json'], true);
            if (!is_array($cambios)) {
                $cambios = [];
            }
            return [
                'id'               => (int)$c['numero_correccion'],
                'id_correccion'    => (int)$c['id_correccion'],
                'numero'           => (int)$c['numero_correccion'],
                'version_anterior' => (int)$c['version_anterior'],
                'version_nueva'    => (int)$c['version_nueva'],
                'fecha_hora'       => str_replace(' ', 'T', (string)$c['fecha_correccion']),
                'responsable'      => (string)$c['responsable_nombre'],
                'motivo'           => (string)$c['motivo'],
                'cambios'          => $cambios,
                'delta_stock'      => (int)$c['delta_stock'],
                'stock_anterior'   => (int)$c['stock_anterior'],
                'stock_resultante' => (int)$c['stock_resultante'],
                'id_kardex'        => $c['id_kardex'] !== null ? (int)$c['id_kardex'] : null,
            ];
        }, $corrRows);

        $totalCorr = count($correcciones);

        return [
            'id'                 => (int)$row['id_operacion'],
            'id_operacion'       => (int)$row['id_operacion'],
            'id_producto'        => (int)$row['id_producto'],
            'producto'           => (string)$row['producto_nombre'],
            'codigo_barra'       => $codigoBarra,
            'stock_actual'       => (int)$row['producto_stock_actual'],
            'corregido'          => $totalCorr > 0,
            'total_correcciones' => $totalCorr,
            'version'            => (int)$row['version'],
            'vigente'            => [
                'tipo'             => $tipoVig,
                'cantidad'         => $cantidadVig,
                'signo'            => $signoVig,
                'cantidad_firmada' => $signoVig * $cantidadVig,
                'justificacion'    => (string)$row['justificacion_vigente'],
                'lote'             => $loteVig,
            ],
            'original'           => [
                'fecha_hora'       => $fechaIso,
                'responsable'      => (string)$row['responsable_nombre'],
                'tipo'             => $tipoOrig,
                'cantidad'         => $cantidadOrig,
                'signo'            => $signoOrig,
                'cantidad_firmada' => $signoOrig * $cantidadOrig,
                'justificacion'    => (string)$row['justificacion'],
                'lote'             => $loteOrig,
                'saldo_anterior'   => (int)$row['saldo_anterior'],
                'saldo_resultante' => (int)$row['saldo_resultante'],
            ],
            'correcciones'       => $correcciones,
        ];
    }
}
