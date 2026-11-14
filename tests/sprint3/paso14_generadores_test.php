<?php

require_once __DIR__ . '/../../Libraries/Core/Autoload.php';
require_once __DIR__ . '/../../Libraries/PDF/ReporteCierresCajaPDF.php';

function verificarPaso14(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException('FAIL: ' . $mensaje);
    }
    echo "PASS: {$mensaje}\n";
}

$closures = [];
$expectedCash = 0.0;
$countedCash = 0.0;
$closingDiff = 0.0;
$openingDiff = 0.0;
$cashSales = 0.0;
$cardSales = 0.0;
$yapeSales = 0.0;
$income = 0.0;
$expenses = 0.0;
$movementTotal = 0;

for ($i = 1; $i <= 12; $i++) {
    $diff = $i % 3 === 0 ? 2.5 : ($i % 3 === 1 ? -1.25 : 0.0);
    $expected = 100 + $i;
    $counted = $expected + $diff;
    $movements = [];
    for ($j = 1; $j <= 90; $j++) {
        $type = $j === 1 ? 'omitted_withdrawal' : ($j === 2 ? 'correction' : 'manual_income');
        $isOmitted = $type === 'omitted_withdrawal';
        $movements[] = [
            'id' => ($i * 1000) + $j,
            'session_id' => $i,
            'date' => '2026-11-13',
            'time' => sprintf('%02d:%02d', 8 + intdiv($j, 60), $j % 60),
            'recorded_at' => sprintf('2026-11-13T08:%02d:00-05:00', $j % 60),
            'type' => $type,
            'label' => $isOmitted ? 'Retiro omitido' : ($type === 'correction' ? 'Corrección' : 'Ingreso'),
            'reason' => $j === 4 ? '=1+1 detalle con tildes y ' . str_repeat('motivo largo ', 15) : 'Movimiento de prueba número ' . $j . ' áéíóú ñ',
            'reference' => $j === 1 ? 'Cierre de origen #99' : '',
            'amount' => $isOmitted ? 35.0 : 10.0,
            'impact' => $isOmitted ? 0.0 : ($type === 'correction' ? -2.0 : 10.0),
            'responsible' => '=Vendedor +@equipo',
            'administrator_name' => $j === 1 || $j % 2 === 0 ? 'Admin de prueba' : null,
            'origin_session_id' => $isOmitted ? 99 : null,
            'declared_occurred_in_open_origin_session' => $isOmitted,
            'confirmed_reflected_in_opening' => $isOmitted,
            'original_movement_id' => $type === 'correction' ? ($i * 1000) + 3 : null,
            'correction_id' => $type === 'correction' ? ($i * 1000) + 2 : null,
        ];
    }
    $movementTotal += count($movements);
    $closures[] = [
        'id' => $i,
        'session_id' => $i,
        'code' => sprintf('CIE-%04d', $i),
        'closed_at' => sprintf('2026-11-%02dT18:45:00-05:00', $i),
        'closing_date' => sprintf('2026-11-%02d', $i),
        'closing_time' => '18:45',
        'shift_id' => 2,
        'turn' => 'Tarde',
        'seller_id' => 5,
        'seller' => 'Vendedora de prueba',
        'opening_fund' => 75.0,
        'opening_difference' => $i === 1 ? null : 0.0,
        'cash_sales' => 40.0,
        'card_sales' => 12.5,
        'yape_plin_sales' => 8.5,
        'income' => 15.0,
        'expenses' => 5.0,
        'expected' => $expected,
        'counted' => $counted,
        'closing_difference' => $diff,
        'observation' => $i === 1 ? null : 'Observación de cierre',
        'movements' => $movements,
    ];
    $expectedCash += $expected;
    $countedCash += $counted;
    $closingDiff += $diff;
    $openingDiff += $i === 1 ? 0 : 0;
    $cashSales += 40;
    $cardSales += 12.5;
    $yapeSales += 8.5;
    $income += 15;
    $expenses += 5;
}

$report = [
    'administrator' => 'Administrador de prueba',
    'generated_at' => '2026-11-13T20:30:00-05:00',
    'filters' => ['desde' => '2026-11-01', 'hasta' => '2026-11-13', 'turno_id' => 2, 'vendedor_id' => 5],
    'closures' => $closures,
    'totals' => [
        'total_closures' => count($closures),
        'closures_with_difference' => 8,
        'opening_fund_total' => 900.0,
        'opening_difference_net' => $openingDiff,
        'cash_sales_total' => $cashSales,
        'card_sales_total' => $cardSales,
        'yape_plin_sales_total' => $yapeSales,
        'income_total' => $income,
        'expenses_total' => $expenses,
        'expected_cash_total' => $expectedCash,
        'counted_cash_total' => $countedCash,
        'closing_difference_net' => $closingDiff,
        'shortages_total' => 5.0,
        'surpluses_total' => 10.0,
        'opening_difference_applicable_count' => 11,
    ],
];

$temp = sys_get_temp_dir() . '/paso14_generadores_' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
    $excel = (new Services\ExcelService())->generarReporteCierresCaja($report);
    file_put_contents($temp . '/reporte.xlsx', $excel);
    $zip = new ZipArchive();
    verificarPaso14($zip->open($temp . '/reporte.xlsx') === true, 'XLSX abre como contenedor OpenXML');
    $workbook = $zip->getFromName('xl/workbook.xml');
    verificarPaso14(is_string($workbook) && substr_count($workbook, '<sheet ') === 3, 'XLSX contiene tres hojas');
    verificarPaso14(str_contains($workbook, 'Resumen') && str_contains($workbook, 'Cierres') && str_contains($workbook, 'Movimientos'), 'XLSX conserva nombres y orden de hojas');
    $summary = $zip->getFromName('xl/worksheets/sheet1.xml');
    $closes = $zip->getFromName('xl/worksheets/sheet2.xml');
    $movementSheet = $zip->getFromName('xl/worksheets/sheet3.xml');
    $zip->close();
    verificarPaso14(is_string($summary) && str_contains($summary, 'pane') === false, 'Resumen se genera sin controles de inmovilización');
    verificarPaso14(is_string($closes) && str_contains($closes, 'topLeftCell="B2"') && str_contains($closes, '<autoFilter'), 'Cierres congela B2 y habilita filtros');
    verificarPaso14(is_string($movementSheet) && str_contains($movementSheet, 'topLeftCell="A2"') && str_contains($movementSheet, '<autoFilter'), 'Movimientos congela A2 y habilita filtros');
    verificarPaso14(is_string($movementSheet) && substr_count($movementSheet, '<row ') === $movementTotal + 1, 'XLSX incluye todos los movimientos, superando 1000 filas');
    verificarPaso14(is_string($movementSheet) && !str_contains($movementSheet, '<f>'), 'XLSX no convierte texto de usuarios en fórmulas');
    verificarPaso14(is_string($movementSheet) && str_contains($movementSheet, '0.00'), 'XLSX mantiene el impacto cero del retiro omitido');
    verificarPaso14(str_contains($summary, 'Total ventas'), 'Resumen incluye total de ventas');
    verificarPaso14(str_contains($movementSheet, 'Recibió'), 'Retiro omitido identifica quién recibió');


    $pdf = (new ReporteCierresCajaPDF())->generar($report, 'Administrador de prueba');
    file_put_contents($temp . '/reporte.pdf', $pdf);
    verificarPaso14(str_starts_with($pdf, '%PDF-'), 'PDF empieza con la firma esperada');
    preg_match_all('/\/Type \/Page\b/', $pdf, $pages);
    verificarPaso14(count($pages[0]) > 1, 'PDF crea páginas dinámicas para el conjunto completo');
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
    $pdfText = '';
    foreach ($streams[1] as $stream) {
        $decoded = @gzuncompress($stream);
        if (is_string($decoded)) $pdfText .= $decoded;
    }
    verificarPaso14(str_contains($pdfText, 'CIE-0001') && str_contains($pdfText, 'Retiro omitido'), 'PDF incluye cierres y movimientos vinculados');
    verificarPaso14(str_contains($pdfText, 'No aplica'), 'PDF conserva null en diferencia de apertura');
} finally {
    if (is_dir($temp)) {
        foreach (glob($temp . '/*') ?: [] as $file) unlink($file);
        rmdir($temp);
    }
}
