<?php

require_once __DIR__ . '/ReportPDF.php';

class ReporteCierresCajaPDF extends ReportPDF
{
    private string $emitidoEn = '';
    private string $administrador = '';
    private array $oro = [212, 175, 55];
    private array $oscuro = [30, 41, 59];
    private array $gris = [100, 116, 139];
    private array $claro = [241, 245, 249];

    public function generar(array $reporte, string $administrador): string
    {
        $this->administrador = $administrador;
        $this->emitidoEn = (string)($reporte['generated_at'] ?? '');
        $this->AliasNbPages();
        $this->setEmpresa('AMAZON MARKET');
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 23);
        $this->AddPage('P', 'A4');

        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(...$this->oscuro);
        $this->Cell(0, 9, 'CIERRES DE CAJA', 0, 1, 'L');
        $this->SetDrawColor(...$this->oro);
        $this->SetLineWidth(0.7);
        $this->Line(15, $this->GetY(), 78, $this->GetY());
        $this->Ln(5);

        $filtros = $reporte['filters'] ?? [];
        $cierres = $reporte['closures'] ?? [];
        $totales = $reporte['totals'] ?? [];
        $this->meta('Período', $this->textoPeriodo($filtros));
        $this->meta('Turno', $this->filtroNombre($filtros, $cierres, 'turno_id', 'turn'));
        $this->meta('Vendedor', $this->filtroNombre($filtros, $cierres, 'vendedor_id', 'seller'));
        $this->meta('Generado por', $administrador);
        $this->meta('Emitido', $this->fechaHora($this->emitidoEn));
        if (isset($filtros['session_id'])) {
            $this->meta('Sesión', (string)$filtros['session_id']);
        }
        $this->Ln(3);

        $this->seccion('Resumen general');
        $this->writeReportTable(
            ['Cierres', 'Con diferencia', 'Esperado', 'Contado', 'Dif. cierre neta', 'Dif. apertura neta'],
            [[
                (string)($totales['total_closures'] ?? 0),
                (string)($totales['closures_with_difference'] ?? 0),
                $this->dinero($totales['expected_cash_total'] ?? 0),
                $this->dinero($totales['counted_cash_total'] ?? 0),
                $this->dineroFirmado($totales['closing_difference_net'] ?? 0),
                $this->dineroFirmado($totales['opening_difference_net'] ?? 0),
            ]],
            [24, 27, 31, 31, 35, 32],
            ['C', 'C', 'R', 'R', 'R', 'R']
        );
        $this->Ln(2);
        $this->writeReportTable(
            ['Faltantes', 'Sobrantes', 'Efectivo', 'Tarjeta', 'Yape / Plin', 'Ventas totales'],
            [[
                $this->dinero($totales['shortages_total'] ?? 0),
                $this->dinero($totales['surpluses_total'] ?? 0),
                $this->dinero($totales['cash_sales_total'] ?? 0),
                $this->dinero($totales['card_sales_total'] ?? 0),
                $this->dinero($totales['yape_plin_sales_total'] ?? 0),
                $this->dinero(
                    (float)($totales['cash_sales_total'] ?? 0)
                    + (float)($totales['card_sales_total'] ?? 0)
                    + (float)($totales['yape_plin_sales_total'] ?? 0)
                ),
            ]],
            [30, 30, 30, 30, 30, 30],
            ['R', 'R', 'R', 'R', 'R', 'R']
        );
        $this->Ln(2);
        $this->writeReportTable(
            ['Fondo inicial', 'Ingresos netos', 'Egresos netos', 'Aperturas con diferencia', '', ''],
            [[
                $this->dinero($totales['opening_fund_total'] ?? 0),
                $this->dinero($totales['income_total'] ?? 0),
                $this->dinero($totales['expenses_total'] ?? 0),
                (string)($totales['opening_difference_applicable_count'] ?? 0),
                '',
                '',
            ]],
            [36, 36, 36, 36, 18, 18],
            ['R', 'R', 'R', 'C', 'C', 'C']
        );
        $this->writeWrappedBlock(
            'Interpretación: la diferencia de cierre es contado menos esperado. La diferencia de apertura se presenta aparte; cuando no existe cierre anterior, no aplica. Las correcciones se muestran con su vínculo y los retiros omitidos con impacto cero cuando ya quedaron reflejados en la apertura.',
            9
        );

        if ($cierres === []) {
            $this->seccion('Detalle de cierres');
            $this->writeWrappedBlock('No hay cierres para el período y los filtros seleccionados.', 10);
        }

        foreach ($cierres as $cierre) {
            $this->AddPage('P', 'A4');
            $this->seccion('Cierre ' . (string)($cierre['code'] ?? ('#' . ($cierre['session_id'] ?? ''))));
            $this->writeReportTable(
                ['Fecha / hora', 'Caja', 'Turno', 'Vendedor', 'Esperado', 'Contado', 'Diferencia', 'Estado'],
                [[
                    $this->fechaHora($cierre['closed_at'] ?? ''),
                    'Caja física #' . (string)($cierre['cashbox_id'] ?? 1),
                    (string)($cierre['turn'] ?? ''),
                    (string)($cierre['seller'] ?? ''),
                    $this->dinero($cierre['expected'] ?? 0),
                    $this->dinero($cierre['counted'] ?? 0),
                    $this->dineroFirmado($cierre['closing_difference'] ?? 0),
                    $this->estadoCierre($cierre['closing_difference'] ?? 0),
                ]],
                [21, 12, 18, 31, 20, 20, 22, 22],
                ['L', 'C', 'L', 'L', 'R', 'R', 'R', 'C']
            );

            $this->seccion('Desglose de efectivo');
            $openDiff = array_key_exists('opening_difference', $cierre) && $cierre['opening_difference'] !== null
                ? $this->dineroFirmado($cierre['opening_difference'])
                : 'No aplica';
            $ventasTotal = (float)($cierre['cash_sales'] ?? 0) + (float)($cierre['card_sales'] ?? 0) + (float)($cierre['yape_plin_sales'] ?? 0);
            $this->writeReportTable(
                ['Fondo inicial', 'Dif. apertura', 'Venta efectivo', 'Tarjeta', 'Yape / Plin', 'Ventas total'],
                [[
                    $this->dinero($cierre['opening_fund'] ?? 0), $openDiff,
                    $this->dinero($cierre['cash_sales'] ?? 0), $this->dinero($cierre['card_sales'] ?? 0),
                    $this->dinero($cierre['yape_plin_sales'] ?? 0), $this->dinero($ventasTotal),
                ]],
                [30, 30, 30, 30, 30, 30],
                ['R', 'R', 'R', 'R', 'R', 'R']
            );
            $this->writeReportTable(
                ['Ingresos netos', 'Egresos netos', 'Esperado', 'Contado', 'Dif. cierre', 'Observación'],
                [[
                    $this->dinero($cierre['income'] ?? 0), $this->dinero($cierre['expenses'] ?? 0),
                    $this->dinero($cierre['expected'] ?? 0), $this->dinero($cierre['counted'] ?? 0),
                    $this->dineroFirmado($cierre['closing_difference'] ?? 0),
                    $this->acortar($cierre['observation'] ?? '', 44),
                ]],
                [28, 28, 28, 28, 28, 40],
                ['R', 'R', 'R', 'R', 'R', 'L']
            );

            $movimientos = $cierre['movements'] ?? [];
            $this->seccion('Movimientos manuales y correcciones (' . count($movimientos) . ')');
            if ($movimientos === []) {
                $this->writeWrappedBlock('No se registraron movimientos en esta sesión.', 9);
                continue;
            }
            $this->encabezadoMovimientos();
            foreach ($movimientos as $movimiento) {
                $this->filaMovimiento($movimiento);
            }
        }

        return $this->Output('S');
    }

    public function Header(): void
    {
        $this->SetFillColor(...$this->oro);
        $this->Rect(0, 0, $this->GetPageWidth(), 30, 'F');
        $this->SetTextColor(...$this->oscuro);
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetXY(15, 8);
        $this->Cell(0, 8, 'AMAZON MARKET', 0, 1, 'L');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', '', 9);
        $this->SetXY(15, 18);
        $this->Cell(0, 6, 'Reporte administrativo de cierres de caja', 0, 1, 'L');
        $this->SetY(36);
    }

    public function Footer(): void
    {
        $this->SetY(-17);
        $this->SetDrawColor(...$this->oro);
        $this->Line(15, $this->GetY(), $this->GetPageWidth() - 15, $this->GetY());
        $this->SetY(-13);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(...$this->gris);
        $this->Cell(130, 5, 'Reporte generado: ' . $this->fechaHora($this->emitidoEn), 0, 0, 'L');
        $this->Cell(50, 5, 'Página ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    private function meta(string $etiqueta, string $valor): void
    {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(...$this->gris);
        $this->Cell(35, 5, $etiqueta, 0, 0, 'L');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...$this->oscuro);
        $this->Cell(0, 5, $valor, 0, 1, 'L');
    }

    private function seccion(string $titulo): void
    {
        if ($this->GetY() > $this->GetPageHeight() - 32) {
            $this->AddPage('P', 'A4');
        }
        $this->Ln(2);
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(...$this->oscuro);
        $this->Cell(0, 7, $titulo, 0, 1, 'L');
    }

    private function writeReportTable(array $headers, array $rows, array $widths, array $aligns): void
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(...$this->oscuro);
        $this->SetTextColor(255, 255, 255);
        foreach ($headers as $index => $header) {
            $this->Cell($widths[$index], 7, $header, 1, 0, 'C', true);
        }
        $this->Ln();
        foreach ($rows as $row) {
            if ($this->GetY() > $this->GetPageHeight() - 30) {
                $this->AddPage('P', 'A4');
                $this->SetFont('Helvetica', 'B', 7);
                $this->SetFillColor(...$this->oscuro);
                $this->SetTextColor(255, 255, 255);
                foreach ($headers as $index => $header) {
                    $this->Cell($widths[$index], 7, $header, 1, 0, 'C', true);
                }
                $this->Ln();
            }
            $this->SetFont('Helvetica', '', 7);
            $this->SetTextColor(...$this->oscuro);
            $this->SetFillColor(255, 255, 255);
            foreach ($row as $index => $value) {
                $txt = $this->acortar((string)$value, max(12, (int)($widths[$index] * 1.6)));
                $this->Cell($widths[$index], 7, $txt, 1, 0, $aligns[$index] ?? 'L', true);
            }
            $this->Ln();
        }
    }

    private function encabezadoMovimientos(): void
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(...$this->oscuro);
        $this->SetTextColor(255, 255, 255);
        foreach ([['Hora', 20], ['Tipo', 32], ['Importe', 25], ['Impacto', 25], ['Responsable', 39], ['Administrador', 39]] as [$label, $width]) {
            $this->Cell($width, 7, $label, 1, 0, 'C', true);
        }
        $this->Ln();
    }

    private function filaMovimiento(array $m): void
    {
        if ($this->GetY() > $this->GetPageHeight() - 32) {
            $this->AddPage('P', 'A4');
            $this->encabezadoMovimientos();
        }
        $hora = $this->fechaHora($m['recorded_at'] ?? (($m['date'] ?? '') . ' ' . ($m['time'] ?? '')));
        $tipo = (string)($m['label'] ?? $m['type'] ?? 'Movimiento');
        if (!empty($m['original_movement_id'])) {
            $tipo .= ' #' . (string)$m['original_movement_id'];
        }
        $impacto = (float)($m['impact'] ?? 0);
        $values = [
            $hora,
            $tipo,
            $this->dinero($m['amount'] ?? 0),
            $this->dineroFirmado($impacto),
            (string)($m['responsible'] ?? ''),
            (string)($m['administrator_name'] ?? '—'),
        ];
        $widths = [20, 32, 25, 25, 39, 39];
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(...$this->oscuro);
        foreach ($values as $i => $value) {
            $this->Cell($widths[$i], 7, $this->acortar($value, 30), 1, 0, $i < 2 || $i > 3 ? 'L' : 'R');
        }
        $this->Ln();

        $detail = (string)($m['reason'] ?? '');
        if (!empty($m['reference']) && $m['reference'] !== $detail) {
            $detail .= ' | Referencia: ' . (string)$m['reference'];
        }
        if (!empty($m['origin_session_id'])) {
            $detail .= ' | Cierre de origen: #' . (string)$m['origin_session_id'];
        }
        if (($m['type'] ?? '') === 'omitted_withdrawal') {
            $detail .= ' | Declarado ocurrido en sesión de origen abierta: '
                . (!empty($m['declared_occurred_in_open_origin_session']) ? 'Sí' : 'No')
                . ' | Ya reflejado en apertura: '
                . (!empty($m['confirmed_reflected_in_opening']) ? 'Sí' : 'No')
                . ' | Impacto registrado: S/ 0.00';
        }
        if (!empty($m['correction_id'])) {
            $detail .= ' | Corrección #' . (string)$m['correction_id'];
        }
        $this->writeWrappedBlock('Detalle: ' . $detail, 8, true);
    }

    private function writeWrappedBlock(string $text, int $fontSize = 9, bool $compact = false): void
    {
        $this->SetFont('Helvetica', '', $fontSize);
        $this->SetTextColor(...$this->oscuro);
        $lineHeight = $compact ? 4 : 5;
        $lines = $this->wrap($text, $this->GetPageWidth() - 30);
        $needed = count($lines) * $lineHeight + 2;
        if ($this->GetY() + $needed > $this->GetPageHeight() - 20) {
            $this->AddPage('P', 'A4');
            if ($compact) {
                $this->encabezadoMovimientos();
            }
        }
        foreach ($lines as $line) {
            $this->Cell(0, $lineHeight, $line, 0, 1, 'L');
        }
        if (!$compact) {
            $this->Ln(1);
        }
    }

    private function wrap(string $text, float $width): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->getUtf8StringWidth($candidate) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines ?: [''];
    }

    private function textoPeriodo(array $filtros): string
    {
        if (isset($filtros['session_id'])) {
            return 'Sesión individual';
        }
        return (string)($filtros['desde'] ?? '—') . ' al ' . (string)($filtros['hasta'] ?? '—');
    }

    private function filtroNombre(array $filtros, array $cierres, string $key, string $field): string
    {
        if (empty($filtros[$key])) {
            return 'Todos';
        }
        foreach ($cierres as $cierre) {
            if ((int)($cierre[$key === 'turno_id' ? 'shift_id' : 'seller_id'] ?? 0) === (int)$filtros[$key]) {
                return (string)($cierre[$field] ?? ('#' . $filtros[$key]));
            }
        }
        return '#' . (string)$filtros[$key];
    }

    private function fechaHora($value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '—';
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('America/Lima'))->format('d/m/Y H:i');
        } catch (Throwable $error) {
            return $value;
        }
    }

    private function dinero($value): string
    {
        return 'S/ ' . number_format((float)$value, 2, '.', ',');
    }

    private function dineroFirmado($value): string
    {
        $number = (float)$value;
        return ($number > 0 ? '+' : ($number < 0 ? '−' : '')) . $this->dinero(abs($number));
    }

    private function estadoCierre($value): string
    {
        $number = (float)$value;
        return $number === 0.0 ? 'Cuadrado' : ($number < 0 ? 'Faltante' : 'Sobrante');
    }

    private function acortar($value, int $max): string
    {
        $text = trim((string)$value);
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
            return mb_substr($text, 0, max(0, $max - 1), 'UTF-8') . '…';
        }
        if (!function_exists('mb_strlen') && strlen($text) > $max) {
            return substr($text, 0, max(0, $max - 3)) . '...';
        }
        return $text;
    }
}
