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

        $this->seccion('RESUMEN');
        $this->tarjetas([
            ['Cierres de caja', (string)($totales['total_closures'] ?? 0)],
            ['Cierres con diferencia', (string)($totales['closures_with_difference'] ?? 0)],
            ['Diferencia neta de cierre', $this->dineroFirmado($totales['closing_difference_net'] ?? 0)],
            ['Diferencia de apertura', $this->dineroFirmado($totales['opening_difference_net'] ?? 0)],
        ]);
        $this->writeWrappedBlock('Faltantes: ' . $this->dinero($totales['shortages_total'] ?? 0)
            . ' | Sobrantes: ' . $this->dinero($totales['surpluses_total'] ?? 0), 8);
        $this->seccion('VENTAS POR MEDIO DE PAGO');
        $this->tarjetas([
            ['Efectivo', $this->dinero($totales['cash_sales_total'] ?? 0)],
            ['Tarjeta', $this->dinero($totales['card_sales_total'] ?? 0)],
            ['Yape / Plin', $this->dinero($totales['yape_plin_sales_total'] ?? 0)],
            ['Total ventas', $this->dinero((float)($totales['cash_sales_total'] ?? 0) + (float)($totales['card_sales_total'] ?? 0) + (float)($totales['yape_plin_sales_total'] ?? 0))],
        ]);
        $this->seccion('LISTADO DE CIERRES CON DETALLE');
        if ($cierres === []) {
            $this->seccion('Detalle de cierres');
            $this->writeWrappedBlock('No hay cierres para el período y los filtros seleccionados.', 10);
        }

        foreach ($cierres as $cierre) {
            if ($this->GetY() > 190) $this->AddPage('P', 'A4');
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
                    (string)($cierre['observation'] ?? ''),
                ]],
                [28, 28, 28, 28, 28, 40],
                ['R', 'R', 'R', 'R', 'R', 'L']
            );

            foreach ($cierre['linked_omitted_withdrawals'] ?? [] as $linked) {
                $this->writeWrappedBlock('Registro vinculado: retiro omitido de ' . $this->dinero($linked['amount'])
                    . ' registrado en el turno ' . $linked['registered_turn'] . ' (' . $this->fechaHora($linked['recorded_at'])
                    . '), sesión #' . $linked['registered_session_id'] . '. El cierre original no se modifica.', 8);
            }
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

        // Mantener totales y su interpretación juntos; evitar una última página con una sola línea.
        if ($this->GetY() + 65 > $this->GetPageHeight() - 23) $this->AddPage('P', 'A4');
        $this->seccion('TOTALES GENERALES');
        foreach (['opening_fund_total'=>'Fondo inicial', 'expected_cash_total'=>'Efectivo esperado',
            'counted_cash_total'=>'Efectivo contado', 'closing_difference_net'=>'Diferencia de cierre',
            'opening_difference_net'=>'Diferencia de apertura'] as $key=>$label) {
            $this->meta($label, $this->dineroFirmado($totales[$key] ?? 0));
        }
        $this->writeWrappedBlock('Diferencia de cierre = contado menos esperado. Efectivo esperado = fondo inicial + ventas en efectivo + ingresos - egresos. La diferencia de apertura se muestra aparte. Los retiros omitidos vinculados no modifican el cierre original.', 8);
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

    private function tarjetas(array $cards): void
    {
        $y=$this->GetY();
        foreach ($cards as $i=>$card) {
            $x=15+$i*46;
            $this->SetFillColor(237,244,247); $this->Rect($x,$y,43,20,'F');
            $this->SetXY($x+2,$y+2);$this->SetFont('Helvetica','',7);$this->SetTextColor(...$this->gris);
            $this->Cell(39,5,$card[0]);
            $this->SetXY($x+2,$y+9);$this->SetFont('Helvetica','B',11);$this->SetTextColor(...$this->oscuro);
            $this->Cell(39,7,$card[1]);
        }
        $this->SetXY(15,$y+23);
    }

    private function writeReportTable(array $headers, array $rows, array $widths, array $aligns): void
    {
        $header=function() use ($headers,$widths) {
            $this->SetFont('Helvetica','B',7);$this->SetFillColor(...$this->claro);$this->SetTextColor(...$this->oscuro);
            foreach($headers as $i=>$h) $this->Cell($widths[$i],7,$h,0,0,'C',true);
            if ($headers !== []) $this->Ln();
        };
        $header();
        foreach($rows as $row) {
            $this->SetFont('Helvetica','',7);
            $wrapped=[];$height=0;
            foreach($row as $i=>$v) {
                $wrapped[$i]=$this->wrap((string)$v,$widths[$i]-3);
                $height=max($height,count($wrapped[$i])*4+2);
            }
            if($this->GetY()+$height>$this->GetPageHeight()-23) {$this->AddPage();$header();}
            $y=$this->GetY();$x=15;
            $this->SetFont('Helvetica','',7);$this->SetTextColor(...$this->oscuro);
            foreach($wrapped as $i=>$lines) {
                $this->SetXY($x,$y+1);
                foreach($lines as $line){$this->SetX($x);$this->Cell($widths[$i],4,$line,0,1,$aligns[$i]??'L');}
                $x+=$widths[$i];
            }
            $this->SetXY(15,$y+$height);
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
        $values = [$hora, $tipo, $this->dinero($m['amount'] ?? 0), $this->dineroFirmado($impacto), (string)($m['responsible'] ?? ''), (string)($m['administrator_name'] ?? '—')];
        $this->writeReportTable([], [$values], [20,32,25,25,39,39], ['L','L','R','R','L','L']);

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
        foreach ($lines as $line) {
            if ($this->GetY()+$lineHeight>$this->GetPageHeight()-23) {
                $this->AddPage();if($compact)$this->encabezadoMovimientos();
                $this->SetFont('Helvetica','',$fontSize);$this->SetTextColor(...$this->oscuro);
            }
            $this->Cell(0,$lineHeight,$line,0,1,'L');
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
        $tokens=[];
        foreach($words as $word) {
            $part='';
            foreach(preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                if($part!=='' && $this->getUtf8StringWidth($part.$char)>$width) {$tokens[]=$part;$part='';}
                $part.=$char;
            }
            if($part!=='')$tokens[]=$part;
        }
        foreach ($tokens as $word) {
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
        return ($number > 0 ? '+' : ($number < 0 ? '-' : '')) . $this->dinero(abs($number));
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
