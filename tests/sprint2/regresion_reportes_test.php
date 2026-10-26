<?php
/** Regresiones reales, transaccionales y exclusivas de la base de pruebas. */
require_once __DIR__ . '/bootstrap_test.php';

use Libraries\Core\InventarioException;
use Services\ReporteService;

$failed = 0;
$passed = 0;
function checkRegression(string $name, bool $ok): void {
    global $failed, $passed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
function rejectsFilter(ReporteService $service, string $method, array $filters): bool {
    try {
        $service->$method($filters, 1);
        return false;
    } catch (InventarioException $e) {
        return $e->getStatusCode() === 422;
    }
}

$service = new ReporteService();
$period = ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31'];
$pdoTest->beginTransaction();
try {
    // Una clasificación posterior no debe cambiar los movimientos ya registrados.
    $pdoTest->exec("INSERT INTO producto (codigo_barra,nombre,id_categoria,id_subcategoria,id_unidad,precio_venta,stock_actual,stock_minimo,estado)
        VALUES ('TST-REG-CATEGORIA','Regresión clasificación',1,1,1,10,0,0,1)");
    $product = (int)$pdoTest->lastInsertId();
    $inventory = new Services\InventarioService();
    $ref = 'reg-cat-entrada-' . bin2hex(random_bytes(6));
    $inventory->registrarAjuste(['id_producto' => $product, 'tipo_ajuste' => 'Ajuste positivo', 'cantidad' => 10, 'saldo_anterior_esperado' => 0, 'justificacion' => 'Prueba de regresión', 'referencia_solicitud' => $ref], $ref, 1);
    $pdoTest->exec("UPDATE producto SET id_categoria=2, id_subcategoria=2 WHERE id_producto={$product}");
    $ref = 'reg-cat-salida-' . bin2hex(random_bytes(6));
    $inventory->registrarAjuste(['id_producto' => $product, 'tipo_ajuste' => 'Ajuste negativo', 'cantidad' => 2, 'saldo_anterior_esperado' => 10, 'justificacion' => 'Prueba de regresión', 'referencia_solicitud' => $ref], $ref, 1);
    // La fecha real se toma para que la prueba no dependa del reloj del sistema.
    $period = ['fecha_inicio' => date('Y-m-01'), 'fecha_fin' => date('Y-m-t')];
    $report = $service->ejecutarReporteKardex($period + ['id_producto' => $product, 'id_categoria' => 2], 1);
    $summary = $report['resumen_producto'];
    checkRegression('Kardex filtrado conserva el saldo real 8, entradas 10 y salidas 2',
        $summary['saldo_inicial'] === 0 && $summary['total_entradas'] === 10 && $summary['total_salidas'] === 2 && $summary['saldo_final'] === 8
        && count($report['items']) === 1 && $report['items'][0]['saldo_resultante'] === 8);
    $query = $period + ['id_producto' => $product, 'q' => 'nombre inexistente'];
    $empty = $service->ejecutarReporteKardex($query, 1);
    checkRegression('Tabla sin coincidencias no borra el saldo del producto', count($empty['items']) === 0 && $empty['resumen_producto']['saldo_final'] === 8);
    $frozen = $service->ejecutarReporteKardex($query + ['referencia_consulta' => $empty['referencia_consulta']], 1);
    checkRegression('Snapshot conserva el resumen completo', $frozen['resumen_producto'] === $empty['resumen_producto']);

    checkRegression('Variaciones rechaza fechas explícitamente vacías', rejectsFilter($service, 'ejecutarReporteVariaciones', ['fecha_inicio' => '', 'fecha_fin' => '']));
    checkRegression('Variaciones rechaza consulta nueva sin fechas', rejectsFilter($service, 'ejecutarReporteVariaciones', []));
    foreach (['ejecutarReporteKardex', 'ejecutarReporteVariaciones'] as $method) {
        checkRegression($method . ': categoría inexistente', rejectsFilter($service, $method, $period + ['id_categoria' => 99999999]));
        checkRegression($method . ': subcategoría de otra categoría', rejectsFilter($service, $method, $period + ['id_categoria' => 1, 'id_subcategoria' => 2]));
        checkRegression($method . ': permite clasificación histórica anterior', !rejectsFilter($service, $method, $period + ['id_producto' => $product, 'id_categoria' => 1, 'id_subcategoria' => 1]));
    }
} finally {
    if ($pdoTest->inTransaction()) $pdoTest->rollBack();
}
echo "Regresiones reportes: {$passed} PASS / {$failed} FAIL" . PHP_EOL;
exit($failed ? 1 : 0);
