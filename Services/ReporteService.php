<?php

namespace Services;

use Models\ReporteModel;
use Models\CategoriaModel;
use Models\UsuarioModel;
use Models\ClienteModel;
use Models\KardexModel;
use Models\SubcategoriaModel;
use Libraries\Core\InventarioException;
use Exception;

class ReporteService {

    private $model;

    public function __construct() {
        $this->model = new ReporteModel();
    }

    public function validarFechas(array $input): array {
        $desde = $input['desde'] ?? date('Y-m-01');
        $hasta = $input['hasta'] ?? date('Y-m-d');

        $desde = date('Y-m-d', strtotime($desde));
        $hasta = date('Y-m-d', strtotime($hasta));

        if ($desde > date('Y-m-d')) $desde = date('Y-m-d');
        if ($hasta > date('Y-m-d')) $hasta = date('Y-m-d');
        if ($desde > $hasta) $desde = $hasta;

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    public function reporteVentas(array $input): array {
        $fechas = $this->validarFechas($input);
        $filtros = array_merge($fechas, [
            'id_tipo_comprobante' => intval($input['id_tipo_comprobante'] ?? 0),
            'id_metodo_pago' => intval($input['id_metodo_pago'] ?? 0),
            'id_usuario' => intval($input['id_usuario'] ?? 0),
            'id_cliente' => intval($input['id_cliente'] ?? 0),
        ]);

        $ventas = $this->model->ventasPorPeriodo($filtros);
        $resumen = $this->model->resumenVentasPeriodo($fechas['desde'], $fechas['hasta']);
        $ventasPorDia = $this->model->ventasPorDia($fechas['desde'], $fechas['hasta']);
        $comprobantes = $this->model->comprobantesPorTipo($fechas['desde'], $fechas['hasta']);

        $diasAntes = intval(abs((strtotime($fechas['desde']) - strtotime($fechas['hasta'])) / 86400));
        $desdeAnterior = date('Y-m-d', strtotime($fechas['desde']) - ($diasAntes + 1) * 86400);
        $hastaAnterior = date('Y-m-d', strtotime($fechas['desde']) - 86400);
        $resumenAnterior = $this->model->resumenVentasPeriodo($desdeAnterior, $hastaAnterior);

        $varIngresos = 0;
        if (floatval($resumenAnterior['total_ingresos'] ?? 0) > 0) {
            $varIngresos = round((($resumen['total_ingresos'] - $resumenAnterior['total_ingresos']) / $resumenAnterior['total_ingresos']) * 100, 1);
        }

        $boletas = 0;
        $facturas = 0;
        foreach ($comprobantes as $c) {
            if (stripos($c['comprobante'], 'Boleta') !== false) $boletas = intval($c['cantidad']);
            if (stripos($c['comprobante'], 'Factura') !== false) $facturas = intval($c['cantidad']);
        }

        return [
            'ventas' => $ventas,
            'resumen' => $resumen,
            'ventasPorDia' => $ventasPorDia,
            'comprobantes' => $comprobantes,
            'varIngresos' => $varIngresos,
            'boletas' => $boletas,
            'facturas' => $facturas,
            'filtros' => $filtros,
        ];
    }

    public function reporteProductosMasVendidos(array $input): array {
        $fechas = $this->validarFechas($input);
        $filtros = array_merge($fechas, [
            'id_categoria' => intval($input['id_categoria'] ?? 0),
            'topN' => min(500, max(10, intval($input['topN'] ?? 10))),
        ]);

        $productos = $this->model->productosMasVendidos($filtros);
        $totalIngresos = 0;
        $totalUnidades = 0;
        foreach ($productos as $p) {
            $totalIngresos += floatval($p['ingresos']);
            $totalUnidades += intval($p['unidades_vendidas']);
        }

        $resultado = [];
        foreach ($productos as $p) {
            $p['porcentaje_ingresos'] = $totalIngresos > 0 ? round((floatval($p['ingresos']) / $totalIngresos) * 100, 1) : 0;
            $p['porcentaje_unidades'] = $totalUnidades > 0 ? round((intval($p['unidades_vendidas']) / $totalUnidades) * 100, 1) : 0;
            $resultado[] = $p;
        }

        return [
            'productos' => $resultado,
            'totalIngresos' => $totalIngresos,
            'totalUnidades' => $totalUnidades,
            'filtros' => $filtros,
        ];
    }

    public function reporteProductosMenosVendidos(array $input): array {
        $fechas = $this->validarFechas($input);
        $filtros = array_merge($fechas, [
            'id_categoria' => intval($input['id_categoria'] ?? 0),
            'umbral' => max(0, intval($input['umbral'] ?? 5)),
        ]);

        $productos = $this->model->productosMenosVendidos($filtros);

        return [
            'productos' => $productos,
            'filtros' => $filtros,
        ];
    }

    public function reporteInventario(): array {
        $productos = $this->model->inventario();
        $resumen = $this->model->resumenInventario();

        return [
            'productos' => $productos,
            'resumen' => $resumen,
        ];
    }

    public function reporteKardex(array $filtros, ?int $idUsuario = null): array {
        return $this->ejecutarReporteKardex($filtros, $idUsuario);
    }

    public function reporteVariaciones(array $filtros, ?int $idUsuario = null): array {
        return $this->ejecutarReporteVariaciones($filtros, $idUsuario);
    }

    private function formatearItemsKardex(array $filas): array {
        $items = [];
        foreach ($filas as $fila) {
            $sentido = $fila['sentido_movimiento'];
            $cantidad = (int)$fila['cantidad'];
            $esIncremento = $sentido === 'INCREMENTO';

            $lote = 'Sin lote';
            if ($fila['condicion_lote'] === 'CON_LOTE' && !empty($fila['numero_lote'])) {
                $lote = $fila['numero_lote'];
            }

            $items[] = [
                'id_kardex' => (int)$fila['id_kardex'],
                'fecha_hora' => $fila['fecha_movimiento'],
                'fecha_movimiento' => $fila['fecha_movimiento'],
                'id_producto' => (int)$fila['id_producto'],
                'codigo_barra' => $fila['producto_codigo_snapshot'],
                'producto_nombre' => $fila['producto_nombre_snapshot'],
                'producto' => $fila['producto_nombre_snapshot'],
                'categoria_nombre' => $fila['categoria_nombre_snapshot'],
                'categoria' => $fila['categoria_nombre_snapshot'],
                'subcategoria_nombre' => $fila['subcategoria_nombre_snapshot'],
                'unidad_abreviatura' => $fila['unidad_abreviatura_snapshot'],
                'origen' => $fila['tipo_movimiento'],
                'tipo_movimiento' => $fila['tipo_movimiento'],
                'referencia_operacion' => !empty($fila['referencia_operacion']) ? $fila['referencia_operacion'] : '—',
                'entrada' => $esIncremento ? $cantidad : null,
                'salida' => !$esIncremento ? $cantidad : null,
                'entradas' => $esIncremento ? $cantidad : 0,
                'salidas' => !$esIncremento ? $cantidad : 0,
                'saldo_anterior' => (int)$fila['stock_anterior'],
                'saldo_resultante' => (int)$fila['stock_resultante'],
                'responsable' => !empty($fila['responsable_nombre_snapshot']) ? $fila['responsable_nombre_snapshot'] : 'No disponible',
                'lote' => $lote,
                'justificacion' => $fila['justificacion'] ?? '',
            ];
        }
        return $items;
    }

    private function validarClasificacionReporte(?int $idCategoria, ?int $idSubcategoria): void {
        if ($idCategoria !== null && !(new CategoriaModel())->find($idCategoria)) {
            throw InventarioException::validacion(['id_categoria' => 'La categoría seleccionada no existe.']);
        }
        if ($idSubcategoria !== null) {
            $subcategoria = (new SubcategoriaModel())->find($idSubcategoria);
            if (!$subcategoria || ($idCategoria !== null && (int)$subcategoria['id_categoria'] !== $idCategoria)) {
                throw InventarioException::validacion(['id_subcategoria' => 'La subcategoría no existe o no corresponde a la categoría seleccionada.']);
            }
        }
    }

    private function consultaConsistente(callable $consulta): array {
        $db = \Libraries\Core\Conexion::getInstance()->conect();
        $propia = !$db->inTransaction();
        if ($propia) {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->beginTransaction();
        }
        try {
            $resultado = $consulta();
            if ($propia) $db->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($propia && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private function validarTiposFiltrosReporte(array $input): void {
        foreach (['fecha_inicio', 'fecha_fin', 'desde', 'hasta', 'q', 'referencia_consulta', 'tipo_variacion', 'tipo', 'sentido', 'sentido_movimiento', 'direction'] as $campo) {
            if (isset($input[$campo]) && !is_string($input[$campo])) {
                throw InventarioException::validacion([$campo => 'El filtro debe ser un valor de texto.']);
            }
        }
    }

    public function ejecutarReporteKardex(array $input, ?int $idUsuario = null): array {
        $this->validarTiposFiltrosReporte($input);
        return $this->consultaConsistente(fn() => $this->consultarReporteKardex($input, $idUsuario));
    }

    private function consultarReporteKardex(array $input, ?int $idUsuario): array {
        $kardexModel = new KardexModel();

        if ($idUsuario === null || $idUsuario <= 0) {
            $idUsuario = (int)($_SESSION['idUser'] ?? ($_SESSION['userData']['id_usuario'] ?? 1));
        }

        $fechaInicioRaw = trim((string)($input['fecha_inicio'] ?? $input['desde'] ?? ''));
        $fechaFinRaw = trim((string)($input['fecha_fin'] ?? $input['hasta'] ?? ''));

        $fechaInicio = $fechaInicioRaw !== '' ? $fechaInicioRaw : date('Y-m-01');
        $fechaFin = $fechaFinRaw !== '' ? $fechaFinRaw : date('Y-m-d');

        $dtInicio = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio);
        $dtFin = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaFin);

        if (!$dtInicio || $dtInicio->format('Y-m-d') !== $fechaInicio) {
            throw InventarioException::validacion(
                ['fecha_inicio' => 'La fecha inicial no tiene un formato válido (YYYY-MM-DD).'],
                'Verifique los filtros ingresados.'
            );
        }
        if (!$dtFin || $dtFin->format('Y-m-d') !== $fechaFin) {
            throw InventarioException::validacion(
                ['fecha_fin' => 'La fecha final no tiene un formato válido (YYYY-MM-DD).'],
                'Verifique los filtros ingresados.'
            );
        }

        if ($fechaInicio > $fechaFin) {
            throw InventarioException::validacion(
                ['fecha_inicio' => 'La fecha inicial no puede ser posterior a la fecha final.'],
                'La fecha inicial no puede ser posterior a la fecha final.'
            );
        }

        $fechaInicioInicio = $fechaInicio . ' 00:00:00';
        $fechaFinFin = $fechaFin . ' 23:59:59';

        $idProducto = !empty($input['id_producto']) ? filter_var($input['id_producto'], FILTER_VALIDATE_INT) : null;
        if (isset($input['id_producto']) && $input['id_producto'] !== '' && ($idProducto === false || $idProducto < 1)) {
            throw InventarioException::validacion(
                ['id_producto' => 'El identificador de producto debe ser un entero positivo.'],
                'Verifique los filtros ingresados.'
            );
        }

        $idCategoria = !empty($input['id_categoria']) ? filter_var($input['id_categoria'], FILTER_VALIDATE_INT) : null;
        if (isset($input['id_categoria']) && $input['id_categoria'] !== '' && ($idCategoria === false || $idCategoria < 1)) {
            throw InventarioException::validacion(
                ['id_categoria' => 'El identificador de categoría debe ser un entero positivo.'],
                'Verifique los filtros ingresados.'
            );
        }

        $idSubcategoria = !empty($input['id_subcategoria']) ? filter_var($input['id_subcategoria'], FILTER_VALIDATE_INT) : null;
        if (isset($input['id_subcategoria']) && $input['id_subcategoria'] !== '' && ($idSubcategoria === false || $idSubcategoria < 1)) {
            throw InventarioException::validacion(
                ['id_subcategoria' => 'El identificador de subcategoría debe ser un entero positivo.'],
                'Verifique los filtros ingresados.'
            );
        }

        $this->validarClasificacionReporte($idCategoria ?: null, $idSubcategoria ?: null);
        $q = isset($input['q']) ? trim((string)$input['q']) : '';

        $pageRaw = $input['page'] ?? $input['pagina'] ?? 1;
        $page = filter_var($pageRaw, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1) {
            $page = 1;
        }

        $limitRaw = $input['limit'] ?? $input['por_pagina'] ?? 10;
        $limit = filter_var($limitRaw, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            $limit = 10;
        } elseif ($limit > 100) {
            $limit = 100;
        }

        $filtrosCanonicos = [
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'id_producto' => $idProducto ? (int)$idProducto : null,
            'id_categoria' => $idCategoria ? (int)$idCategoria : null,
            'id_subcategoria' => $idSubcategoria ? (int)$idSubcategoria : null,
            'q' => $q !== '' ? $q : null,
        ];
        $hashFiltros = hash('sha256', json_encode($filtrosCanonicos));

        $referenciaConsulta = trim((string)($input['referencia_consulta'] ?? ''));

        if ($referenciaConsulta !== '') {
            $snapshot = $kardexModel->obtenerSnapshotPorReferencia($referenciaConsulta, 'kardex');
            if (!$snapshot) {
                throw InventarioException::referenciaConsultaExpirada($referenciaConsulta);
            }

            if (!empty($snapshot['esta_expirado'])) {
                throw InventarioException::referenciaConsultaExpirada($referenciaConsulta);
            }

            if ((int)$snapshot['id_usuario'] !== $idUsuario) {
                throw InventarioException::referenciaConsultaNoAutorizada();
            }

            $filtrosEnviados = isset($input['fecha_inicio']) || isset($input['desde'])
                || isset($input['fecha_fin']) || isset($input['hasta'])
                || isset($input['id_producto']) || isset($input['id_categoria'])
                || isset($input['id_subcategoria']) || isset($input['q']);

            if ($filtrosEnviados && $snapshot['hash_filtros'] !== $hashFiltros) {
                throw InventarioException::filtrosConsultaConflicto($referenciaConsulta);
            }

            $kardexModel->renovarExpiracionSnapshot((int)$snapshot['id_snapshot'], 30);

            $totalRegistros = (int)$snapshot['total_registros'];
            $exportable = (bool)$snapshot['exportable'];
            $resumenProducto = json_decode($snapshot['resumen_json'], true) ?: ['aplicable' => false];
            $filtrosAplicados = json_decode($snapshot['filtros_json'], true) ?: $filtrosCanonicos;

            $totalPaginas = max(1, (int)ceil($totalRegistros / $limit));
            if ($page > $totalPaginas) {
                $page = $totalPaginas;
            }
            $offset = ($page - 1) * $limit;

            $filasPaginadas = $kardexModel->obtenerItemsSnapshotPaginados((int)$snapshot['id_snapshot'], $offset, $limit);
            $items = $this->formatearItemsKardex($filasPaginadas);

            return [
                'referencia_consulta' => $referenciaConsulta,
                'expira_en' => $snapshot['expira_en'],
                'filtros_aplicados' => $filtrosAplicados,
                'resumen_producto' => $resumenProducto,
                'exportable' => $exportable,
                'items' => $items,
                'pagination' => [
                    'total' => $totalRegistros,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $totalPaginas,
                ],
                'movimientos' => $items,
                'paginacion' => [
                    'pagina' => $page,
                    'por_pagina' => $limit,
                    'total' => $totalRegistros,
                    'total_paginas' => $totalPaginas,
                ],
            ];
        }

        $productoInfo = null;
        if ($idProducto !== null) {
            $productoInfo = $kardexModel->obtenerProductoParaReporte($idProducto);
            if (!$productoInfo) {
                throw InventarioException::validacion(
                    ['id_producto' => 'El producto seleccionado no existe.'],
                    'Verifique los filtros ingresados.'
                );
            }
        }

        $filtrosConsulta = [
            'fecha_inicio_inicio' => $fechaInicioInicio,
            'fecha_fin_fin' => $fechaFinFin,
            'id_producto' => $idProducto,
            'id_categoria' => $idCategoria,
            'id_subcategoria' => $idSubcategoria,
            'q' => $q,
        ];
        $filas = $kardexModel->consultarKardexCanonico($filtrosConsulta);
        $totalRegistros = count($filas);
        $kardexIds = array_column($filas, 'id_kardex');

        if ($idProducto !== null) {
            $saldoInicial = $kardexModel->obtenerSaldoInicial($idProducto, $fechaInicioInicio);

            // El resumen es del período completo: la clasificación histórica y la
            // búsqueda solo filtran filas, no alteran las existencias del producto.
            $filasPeriodo = $kardexModel->consultarKardexCanonico([
                'fecha_inicio_inicio' => $fechaInicioInicio,
                'fecha_fin_fin' => $fechaFinFin,
                'id_producto' => $idProducto,
            ]);

            $totalEntradas = 0;
            $totalSalidas = 0;
            foreach ($filasPeriodo as $fila) {
                if ($fila['sentido_movimiento'] === 'INCREMENTO') {
                    $totalEntradas += (int)$fila['cantidad'];
                } elseif ($fila['sentido_movimiento'] === 'DISMINUCION') {
                    $totalSalidas += (int)$fila['cantidad'];
                }
            }
            $saldoFinal = $saldoInicial + $totalEntradas - $totalSalidas;

            $resumenProducto = [
                'aplicable' => true,
                'id_producto' => (int)$productoInfo['id_producto'],
                'producto' => $productoInfo['nombre'],
                'codigo_barra' => $productoInfo['codigo_barra'] ?: '',
                'estado_producto' => (int)$productoInfo['estado'] === 1 ? 'Activo' : 'Inactivo',
                'unidad_inventario' => $productoInfo['unidad'] ?: 'UND',
                'saldo_inicial' => $saldoInicial,
                'total_entradas' => $totalEntradas,
                'total_salidas' => $totalSalidas,
                'saldo_final' => $saldoFinal,
                'total_movimientos' => count($filasPeriodo),
                'movimientos_filtrados' => $totalRegistros,
                'alcance' => 'PERIODO_COMPLETO_PRODUCTO',
            ];
            $exportable = true;
        } else {
            $resumenProducto = [
                'aplicable' => false,
            ];
            $exportable = $totalRegistros > 0;
        }

        $nuevaReferencia = 'rc-' . date('YmdHis') . '-' . bin2hex(random_bytes(8));
        $guardado = $kardexModel->guardarSnapshotConsulta(
            $nuevaReferencia,
            $idUsuario,
            'kardex',
            $hashFiltros,
            $filtrosCanonicos,
            $resumenProducto,
            $totalRegistros,
            $exportable,
            $kardexIds
        );

        $totalPaginas = max(1, (int)ceil($totalRegistros / $limit));
        if ($page > $totalPaginas) {
            $page = $totalPaginas;
        }
        $filasPaginadas = array_slice($filas, ($page - 1) * $limit, $limit);
        $items = $this->formatearItemsKardex($filasPaginadas);

        return [
            'referencia_consulta' => $nuevaReferencia,
            'expira_en' => $guardado['expira_en'] ?? '',
            'filtros_aplicados' => $filtrosCanonicos,
            'resumen_producto' => $resumenProducto,
            'exportable' => $exportable,
            'items' => $items,
            'pagination' => [
                'total' => $totalRegistros,
                'page' => $page,
                'limit' => $limit,
                'pages' => $totalPaginas,
            ],
            'movimientos' => $items,
            'paginacion' => [
                'pagina' => $page,
                'por_pagina' => $limit,
                'total' => $totalRegistros,
                'total_paginas' => $totalPaginas,
            ],
        ];
    }

    private function formatearItemsVariaciones(array $filas): array {
        $items = [];
        foreach ($filas as $fila) {
            $sentido = $fila['sentido_movimiento'];
            $cantidad = (int)$fila['cantidad'];
            $esIncremento = $sentido === 'INCREMENTO';

            $lote = 'Sin lote';
            if (($fila['condicion_lote'] ?? '') === 'CON_LOTE' && !empty($fila['numero_lote'])) {
                $lote = $fila['numero_lote'];
            }

            $items[] = [
                'id_kardex' => (int)$fila['id_kardex'],
                'fecha_hora' => $fila['fecha_movimiento'],
                'fecha_movimiento' => $fila['fecha_movimiento'],
                'id_producto' => (int)$fila['id_producto'],
                'codigo_barra' => $fila['producto_codigo_snapshot'] ?: '',
                'producto_nombre' => $fila['producto_nombre_snapshot'],
                'categoria_nombre' => $fila['categoria_nombre_snapshot'] ?: 'Sin categoría',
                'subcategoria_nombre' => $fila['subcategoria_nombre_snapshot'] ?: 'General',
                'unidad_abreviatura' => $fila['unidad_abreviatura_snapshot'] ?: 'UND',
                'tipo_variacion' => $fila['tipo_movimiento'],
                'tipo_movimiento' => $fila['tipo_movimiento'],
                'sentido' => $sentido,
                'sentido_movimiento' => $sentido,
                'entrada' => $esIncremento ? $cantidad : null,
                'salida' => !$esIncremento ? $cantidad : null,
                'cantidad' => $cantidad,
                'saldo_anterior' => (int)$fila['stock_anterior'],
                'saldo_resultante' => (int)$fila['stock_resultante'],
                'responsable' => !empty($fila['responsable_nombre_snapshot']) ? $fila['responsable_nombre_snapshot'] : 'No disponible',
                'lote' => $lote,
                'justificacion' => $fila['justificacion'] ?? '',
            ];
        }
        return $items;
    }

    private function reporteCongeladoParaExportacion(array $input, int $idUsuario, string $tipo): array {
        $referencia = $input['referencia_consulta'] ?? null;
        if (!is_string($referencia) || trim($referencia) === '') {
            throw InventarioException::validacion(['referencia_consulta' => 'Debe consultar el reporte antes de exportarlo.']);
        }

        $consulta = $tipo === 'kardex'
            ? $this->ejecutarReporteKardex($input, $idUsuario)
            : $this->ejecutarReporteVariaciones($input, $idUsuario);

        if (empty($consulta['exportable'])) {
            throw InventarioException::exportacionVacia($tipo);
        }

        $kardexModel = new KardexModel();
        $snapshot = $kardexModel->obtenerSnapshotPorReferencia(trim($referencia), $tipo);
        if (!$snapshot) {
            throw InventarioException::referenciaConsultaExpirada(trim($referencia));
        }
        $filas = $kardexModel->obtenerTodosItemsSnapshot((int)$snapshot['id_snapshot']);
        $consulta['items'] = $tipo === 'kardex'
            ? $this->formatearItemsKardex($filas)
            : $this->formatearItemsVariaciones($filas);
        $consulta['movimientos'] = $consulta['items'];
        $consulta['exportable'] = true;
        $consulta['generado_en'] = $kardexModel->obtenerFechaHoraServidor();
        return $consulta;
    }

    /** Datos completos de la referencia autorizada para construir el PDF del Kardex. */
    public function reporteKardexParaPdf(array $input, int $idUsuario): array {
        return $this->reporteCongeladoParaExportacion($input, $idUsuario, 'kardex');
    }

    /** Datos completos de la referencia autorizada para construir el PDF de Variaciones. */
    public function reporteVariacionesParaPdf(array $input, int $idUsuario): array {
        return $this->reporteCongeladoParaExportacion($input, $idUsuario, 'variaciones');
    }

    /** Datos completos de la referencia autorizada para construir el Excel del Kardex. */
    public function reporteKardexParaExcel(array $input, int $idUsuario): array {
        return $this->reporteCongeladoParaExportacion($input, $idUsuario, 'kardex');
    }

    /** Datos completos de la referencia autorizada para construir el Excel de Variaciones. */
    public function reporteVariacionesParaExcel(array $input, int $idUsuario): array {
        return $this->reporteCongeladoParaExportacion($input, $idUsuario, 'variaciones');
    }

    public function ejecutarReporteVariaciones(array $input, ?int $idUsuario = null): array {
        $this->validarTiposFiltrosReporte($input);
        return $this->consultaConsistente(fn() => $this->consultarReporteVariaciones($input, $idUsuario));
    }

    private function consultarReporteVariaciones(array $input, ?int $idUsuario): array {
        $kardexModel = new KardexModel();

        if ($idUsuario === null || $idUsuario <= 0) {
            $idUsuario = (int)($_SESSION['idUser'] ?? ($_SESSION['userData']['id_usuario'] ?? 1));
        }

        $fechaInicioRaw = trim((string)($input['fecha_inicio'] ?? $input['desde'] ?? ''));
        $fechaFinRaw = trim((string)($input['fecha_fin'] ?? $input['hasta'] ?? ''));

        // Paginar solo con referencia reutiliza las fechas congeladas; una
        // consulta nueva sí debe declarar ambas fechas, incluso sin resultados.
        $soloReferencia = !empty($input['referencia_consulta'])
            && !array_key_exists('fecha_inicio', $input) && !array_key_exists('desde', $input)
            && !array_key_exists('fecha_fin', $input) && !array_key_exists('hasta', $input);
        if (!$soloReferencia && ($fechaInicioRaw === '' || $fechaFinRaw === '')) {
            throw InventarioException::validacion(['fechas' => 'La fecha inicial y la fecha final son obligatorias.']);
        }

        $fechaInicio = $fechaInicioRaw !== '' ? $fechaInicioRaw : date('Y-m-01');
        $fechaFin = $fechaFinRaw !== '' ? $fechaFinRaw : date('Y-m-d');

        $dtInicio = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio);
        $dtFin = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaFin);

        if (!$dtInicio || $dtInicio->format('Y-m-d') !== $fechaInicio) {
            throw InventarioException::validacion(
                ['fecha_inicio' => 'La fecha inicial no tiene un formato válido (YYYY-MM-DD).'],
                'Verifique los filtros ingresados.'
            );
        }
        if (!$dtFin || $dtFin->format('Y-m-d') !== $fechaFin) {
            throw InventarioException::validacion(
                ['fecha_fin' => 'La fecha final no tiene un formato válido (YYYY-MM-DD).'],
                'Verifique los filtros ingresados.'
            );
        }

        if ($fechaInicio > $fechaFin) {
            throw InventarioException::validacion(
                ['fecha_inicio' => 'La fecha inicial no puede ser posterior a la fecha final.'],
                'La fecha inicial no puede ser posterior a la fecha final.'
            );
        }

        $fechaInicioInicio = $fechaInicio . ' 00:00:00';
        $fechaFinFin = $fechaFin . ' 23:59:59';

        $idProducto = !empty($input['id_producto']) ? filter_var($input['id_producto'], FILTER_VALIDATE_INT) : null;
        if (isset($input['id_producto']) && $input['id_producto'] !== '' && ($idProducto === false || $idProducto < 1)) {
            throw InventarioException::validacion(
                ['id_producto' => 'El identificador de producto debe ser un entero positivo.'],
                'Verifique los filtros ingresados.'
            );
        }

        $idCategoria = !empty($input['id_categoria']) ? filter_var($input['id_categoria'], FILTER_VALIDATE_INT) : null;
        if (isset($input['id_categoria']) && $input['id_categoria'] !== '' && ($idCategoria === false || $idCategoria < 1)) {
            throw InventarioException::validacion(
                ['id_categoria' => 'El identificador de categoría debe ser un entero positivo.'],
                'Verifique los filtros ingresados.'
            );
        }

        $idSubcategoria = !empty($input['id_subcategoria']) ? filter_var($input['id_subcategoria'], FILTER_VALIDATE_INT) : null;
        if (isset($input['id_subcategoria']) && $input['id_subcategoria'] !== '' && ($idSubcategoria === false || $idSubcategoria < 1)) {
            throw InventarioException::validacion(
                ['id_subcategoria' => 'El identificador de subcategoría debe ser un entero positivo.'],
                'Verifique los filtros ingresados.'
            );
        }

        $q = isset($input['q']) ? trim((string)$input['q']) : '';

        $this->validarClasificacionReporte($idCategoria ?: null, $idSubcategoria ?: null);

        // Validación de tipo de variación
        $tipoVariacionRaw = trim((string)($input['tipo_variacion'] ?? $input['tipo'] ?? ''));
        $tiposPermitidos = ['', 'Ajuste negativo', 'Ajuste positivo', 'Baja por daño', 'Baja por vencimiento'];
        if (!in_array($tipoVariacionRaw, $tiposPermitidos, true)) {
            throw InventarioException::validacion(
                ['tipo_variacion' => 'El tipo de variación no es válido.'],
                'Verifique los filtros ingresados.'
            );
        }

        // Normalización y validación del sentido de movimiento
        $sentidoRaw = trim((string)($input['sentido'] ?? $input['sentido_movimiento'] ?? $input['direction'] ?? ''));
        $sentidoNormalizado = 'TODOS';
        if ($sentidoRaw === '' || in_array(mb_strtoupper($sentidoRaw, 'UTF-8'), ['TODOS', 'TODAS'], true)) {
            $sentidoNormalizado = 'TODOS';
        } elseif (in_array(mb_strtoupper($sentidoRaw, 'UTF-8'), ['INCREMENTO', 'ENTRADAS'], true)) {
            $sentidoNormalizado = 'INCREMENTO';
        } elseif (in_array(mb_strtoupper($sentidoRaw, 'UTF-8'), ['DISMINUCION', 'DISMINUCIÓN', 'SALIDAS'], true)) {
            $sentidoNormalizado = 'DISMINUCION';
        } else {
            throw InventarioException::validacion(
                ['sentido' => 'El sentido de movimiento no es válido (valores permitidos: TODOS, INCREMENTO, DISMINUCION).'],
                'Verifique los filtros ingresados.'
            );
        }

        $pageRaw = $input['page'] ?? $input['pagina'] ?? 1;
        $page = filter_var($pageRaw, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1) {
            $page = 1;
        }

        $limitRaw = $input['limit'] ?? $input['por_pagina'] ?? 10;
        $limit = filter_var($limitRaw, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            $limit = 10;
        } elseif ($limit > 100) {
            $limit = 100;
        }

        $filtrosCanonicos = [
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'id_producto' => $idProducto ? (int)$idProducto : null,
            'q' => $q,
            'id_categoria' => $idCategoria ? (int)$idCategoria : null,
            'id_subcategoria' => $idSubcategoria ? (int)$idSubcategoria : null,
            'tipo_variacion' => $tipoVariacionRaw,
            'sentido' => $sentidoNormalizado,
        ];
        $hashFiltros = hash('sha256', json_encode($filtrosCanonicos));

        $referenciaConsulta = trim((string)($input['referencia_consulta'] ?? ''));

        if ($referenciaConsulta !== '') {
            $snapshot = $kardexModel->obtenerSnapshotPorReferencia($referenciaConsulta, 'variaciones');
            if (!$snapshot) {
                throw InventarioException::referenciaConsultaExpirada($referenciaConsulta);
            }

            if (!empty($snapshot['esta_expirado'])) {
                throw InventarioException::referenciaConsultaExpirada($referenciaConsulta);
            }

            if ((int)$snapshot['id_usuario'] !== $idUsuario) {
                throw InventarioException::referenciaConsultaNoAutorizada();
            }

            $filtrosEnviados = isset($input['fecha_inicio']) || isset($input['desde'])
                || isset($input['fecha_fin']) || isset($input['hasta'])
                || isset($input['id_producto']) || isset($input['id_categoria'])
                || isset($input['id_subcategoria']) || isset($input['q'])
                || isset($input['tipo_variacion']) || isset($input['sentido']);

            if ($filtrosEnviados && $snapshot['hash_filtros'] !== $hashFiltros) {
                throw InventarioException::filtrosConsultaConflicto($referenciaConsulta);
            }

            $kardexModel->renovarExpiracionSnapshot((int)$snapshot['id_snapshot'], 30);

            $totalRegistros = (int)$snapshot['total_registros'];
            $exportable = (bool)$snapshot['exportable'];
            $resumen = json_decode($snapshot['resumen_json'], true) ?: [];
            $filtrosAplicados = json_decode($snapshot['filtros_json'], true) ?: $filtrosCanonicos;

            $totalPaginas = max(1, (int)ceil($totalRegistros / $limit));
            if ($page > $totalPaginas) {
                $page = $totalPaginas;
            }
            $offset = ($page - 1) * $limit;

            $filasPaginadas = $kardexModel->obtenerItemsSnapshotPaginados((int)$snapshot['id_snapshot'], $offset, $limit);
            $items = $this->formatearItemsVariaciones($filasPaginadas);

            return [
                'referencia_consulta' => $referenciaConsulta,
                'expira_en' => $snapshot['expira_en'],
                'filtros_aplicados' => $filtrosAplicados,
                'resumen' => $resumen,
                'exportable' => $exportable,
                'items' => $items,
                'pagination' => [
                    'total' => $totalRegistros,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $totalPaginas,
                ],
                'variaciones' => $items,
                'paginacion' => [
                    'pagina' => $page,
                    'por_pagina' => $limit,
                    'total' => $totalRegistros,
                    'total_paginas' => $totalPaginas,
                ],
            ];
        }

        if ($idProducto !== null) {
            $productoInfo = $kardexModel->obtenerProductoParaReporte($idProducto);
            if (!$productoInfo) {
                throw InventarioException::validacion(
                    ['id_producto' => 'El producto seleccionado no existe.'],
                    'Verifique los filtros ingresados.'
                );
            }
        }

        $filtrosConsulta = [
            'fecha_inicio_inicio' => $fechaInicioInicio,
            'fecha_fin_fin' => $fechaFinFin,
            'id_producto' => $idProducto,
            'id_categoria' => $idCategoria,
            'id_subcategoria' => $idSubcategoria,
            'tipo_variacion' => $tipoVariacionRaw,
            'sentido' => $sentidoNormalizado,
            'q' => $q,
        ];
        $filas = $kardexModel->consultarVariacionesCanonicas($filtrosConsulta);
        $totalRegistros = count($filas);
        $kardexIds = array_column($filas, 'id_kardex');

        // Cálculo del resumen
        $porProducto = [];
        $totalesPorTipo = [
            'baja_por_dano' => ['operaciones' => 0, 'unidades' => 0],
            'baja_por_vencimiento' => ['operaciones' => 0, 'unidades' => 0],
            'ajuste_positivo' => ['operaciones' => 0, 'unidades' => 0],
            'ajuste_negativo' => ['operaciones' => 0, 'unidades' => 0],
        ];

        foreach ($filas as $fila) {
            $idProd = (int)$fila['id_producto'];
            $cantidad = (int)$fila['cantidad'];
            $sentidoFila = $fila['sentido_movimiento'];
            $origen = $fila['origen_operacion'];

            if (!isset($porProducto[$idProd])) {
                $porProducto[$idProd] = [
                    'id_producto' => $idProd,
                    'codigo_barra' => $fila['producto_codigo_snapshot'] ?: '',
                    'producto_nombre' => $fila['producto_nombre_snapshot'],
                    'operaciones' => 0,
                    'incrementos' => 0,
                    'disminuciones' => 0,
                ];
            }

            $porProducto[$idProd]['operaciones']++;
            if ($sentidoFila === 'INCREMENTO') {
                $porProducto[$idProd]['incrementos'] += $cantidad;
            } else {
                $porProducto[$idProd]['disminuciones'] += $cantidad;
            }

            switch ($origen) {
                case 'BAJA_DANO':
                    $totalesPorTipo['baja_por_dano']['operaciones']++;
                    $totalesPorTipo['baja_por_dano']['unidades'] += $cantidad;
                    break;
                case 'BAJA_VENCIMIENTO':
                    $totalesPorTipo['baja_por_vencimiento']['operaciones']++;
                    $totalesPorTipo['baja_por_vencimiento']['unidades'] += $cantidad;
                    break;
                case 'AJUSTE_POSITIVO':
                    $totalesPorTipo['ajuste_positivo']['operaciones']++;
                    $totalesPorTipo['ajuste_positivo']['unidades'] += $cantidad;
                    break;
                case 'AJUSTE_NEGATIVO':
                    $totalesPorTipo['ajuste_negativo']['operaciones']++;
                    $totalesPorTipo['ajuste_negativo']['unidades'] += $cantidad;
                    break;
            }
        }

        $resumenPorProducto = array_values($porProducto);
        $productosConVariaciones = count($resumenPorProducto);
        $esProductoUnico = ($productosConVariaciones === 1);
        $resumenProductoUnico = $esProductoUnico ? $resumenPorProducto[0] : null;

        $resumen = [
            'total_operaciones' => $totalRegistros,
            'productos_con_variaciones' => $productosConVariaciones,
            'es_producto_unico' => $esProductoUnico,
            'resumen_producto_unico' => $resumenProductoUnico,
            'resumen_por_producto' => $resumenPorProducto,
            'totales_por_tipo' => $totalesPorTipo,
        ];

        $exportable = ($totalRegistros > 0);

        $nuevaReferencia = 'rc-' . date('YmdHis') . '-' . bin2hex(random_bytes(8));
        $guardado = $kardexModel->guardarSnapshotConsulta(
            $nuevaReferencia,
            $idUsuario,
            'variaciones',
            $hashFiltros,
            $filtrosCanonicos,
            $resumen,
            $totalRegistros,
            $exportable,
            $kardexIds
        );

        $totalPaginas = max(1, (int)ceil($totalRegistros / $limit));
        if ($page > $totalPaginas) {
            $page = $totalPaginas;
        }
        $filasPaginadas = array_slice($filas, ($page - 1) * $limit, $limit);
        $items = $this->formatearItemsVariaciones($filasPaginadas);

        return [
            'referencia_consulta' => $nuevaReferencia,
            'expira_en' => $guardado['expira_en'] ?? '',
            'filtros_aplicados' => $filtrosCanonicos,
            'resumen' => $resumen,
            'exportable' => $exportable,
            'items' => $items,
            'pagination' => [
                'total' => $totalRegistros,
                'page' => $page,
                'limit' => $limit,
                'pages' => $totalPaginas,
            ],
            'variaciones' => $items,
            'paginacion' => [
                'pagina' => $page,
                'por_pagina' => $limit,
                'total' => $totalRegistros,
                'total_paginas' => $totalPaginas,
            ],
        ];
    }

    public function reporteClientes(array $input): array {
        $fechas = $this->validarFechas($input);
        $topN = min(500, max(10, intval($input['topN'] ?? 20)));

        $clientes = $this->model->topClientes(['desde' => $fechas['desde'], 'hasta' => $fechas['hasta'], 'topN' => $topN]);
        $distribucion = $this->model->clientesNuevosVsRecurrentes($fechas['desde'], $fechas['hasta']);

        return [
            'clientes' => $clientes,
            'distribucion' => $distribucion,
            'filtros' => array_merge($fechas, ['topN' => $topN]),
        ];
    }

    public function reporteVendedores(array $input): array {
        $fechas = $this->validarFechas($input);
        $vendedores = $this->model->ventasPorVendedor($fechas);

        $totalIngresos = 0;
        foreach ($vendedores as $v) {
            $totalIngresos += floatval($v['ingreso_total']);
        }

        $resultado = [];
        foreach ($vendedores as $v) {
            $v['porcentaje'] = $totalIngresos > 0 ? round((floatval($v['ingreso_total']) / $totalIngresos) * 100, 1) : 0;
            $resultado[] = $v;
        }

        return [
            'vendedores' => $resultado,
            'totalIngresos' => $totalIngresos,
            'filtros' => $fechas,
        ];
    }

    public function reporteCategorias(array $input): array {
        $fechas = $this->validarFechas($input);
        $categorias = $this->model->ventasPorCategoria($fechas);

        $totalIngresos = 0;
        $totalUnidades = 0;
        foreach ($categorias as $c) {
            $totalIngresos += floatval($c['ingresos']);
            $totalUnidades += intval($c['unidades']);
        }

        $resultado = [];
        foreach ($categorias as $c) {
            $c['porcentaje'] = $totalIngresos > 0 ? round((floatval($c['ingresos']) / $totalIngresos) * 100, 1) : 0;
            $resultado[] = $c;
        }

        return [
            'categorias' => $resultado,
            'totalIngresos' => $totalIngresos,
            'totalUnidades' => $totalUnidades,
            'filtros' => $fechas,
        ];
    }

    public function reporteComprobantes(array $input): array {
        $fechas = $this->validarFechas($input);
        $filtros = array_merge($fechas, [
            'id_tipo_comprobante' => intval($input['id_tipo_comprobante'] ?? 0),
        ]);

        $comprobantes = $this->model->comprobantesDetallado($filtros);

        $totalBoletas = 0;
        $totalFacturas = 0;
        $montoBoletas = 0;
        $montoFacturas = 0;

        foreach ($comprobantes as $c) {
            if (stripos($c['comprobante'], 'Boleta') !== false) {
                $totalBoletas += intval($c['cantidad']);
                $montoBoletas += floatval($c['total']);
            } else {
                $totalFacturas += intval($c['cantidad']);
                $montoFacturas += floatval($c['total']);
            }
        }

        $igvFacturas = round($montoFacturas * 0.18, 2);

        return [
            'comprobantes' => $comprobantes,
            'totalBoletas' => $totalBoletas,
            'totalFacturas' => $totalFacturas,
            'montoBoletas' => $montoBoletas,
            'montoFacturas' => $montoFacturas,
            'igvFacturas' => $igvFacturas,
            'filtros' => $filtros,
        ];
    }

    public function reporteMetodosPago(array $input): array {
        $fechas = $this->validarFechas($input);
        $metodos = $this->model->metodosDePago($fechas);

        $totalTransacciones = 0;
        $totalMontos = 0;
        foreach ($metodos as $m) {
            $totalTransacciones += intval($m['num_transacciones']);
            $totalMontos += floatval($m['monto_total']);
        }

        $resultado = [];
        foreach ($metodos as $m) {
            $m['porcentaje'] = $totalMontos > 0 ? round((floatval($m['monto_total']) / $totalMontos) * 100, 1) : 0;
            $resultado[] = $m;
        }

        return [
            'metodos' => $resultado,
            'totalTransacciones' => $totalTransacciones,
            'totalMontos' => $totalMontos,
            'filtros' => $fechas,
        ];
    }

    public function reporteResumen(array $input): array {
        $fechas = $this->validarFechas($input);
        $ventas = $this->reporteVentas($input);
        $masVendidos = $this->reporteProductosMasVendidos(array_merge($input, ['topN' => 5]));
        $topClientes = $this->reporteClientes(array_merge($input, ['topN' => 3]));
        $inventario = $this->reporteInventario();
        $metodos = $this->reporteMetodosPago($input);
        $vendedores = $this->reporteVendedores($input);

        return [
            'ventas' => $ventas,
            'masVendidos' => $masVendidos,
            'topClientes' => $topClientes,
            'inventario' => $inventario,
            'metodos' => $metodos,
            'vendedores' => $vendedores,
            'filtros' => $fechas,
        ];
    }

    public function obtenerCategorias(): array {
        return $this->model->obtenerActivos('categoria', 'id_categoria', 'nombre');
    }

    public function obtenerUsuarios(): array {
        return $this->model->obtenerActivos('usuario', 'id_usuario', 'nombre');
    }

    public function obtenerClientes(): array {
        return $this->model->obtenerActivos('cliente', 'id_cliente', 'nombre');
    }

    public function obtenerComprobantes(): array {
        return $this->model->obtenerActivos('tipo_comprobante', 'id_tipo_comprobante', 'nombre');
    }

    public function obtenerMetodosPago(): array {
        return $this->model->obtenerActivos('metodo_pago', 'id_metodo_pago', 'nombre');
    }
}
