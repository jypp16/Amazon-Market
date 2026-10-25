<?php

namespace Services;

use Models\ReporteModel;
use Models\CategoriaModel;
use Models\UsuarioModel;
use Models\ClienteModel;
use Models\KardexModel;
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

    public function ejecutarReporteKardex(array $input, ?int $idUsuario = null): array {
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

            $totalEntradas = 0;
            $totalSalidas = 0;
            foreach ($filas as $fila) {
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
                'total_movimientos' => $totalRegistros,
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
