<?php

require_once __DIR__ . '/ReportPDF.php';

/** Base visual para los informes de inventario del Sprint 2. */
abstract class ReporteInventarioPDF extends ReportPDF {
    public function __construct() {
        parent::__construct('L', 'mm', 'A4');
        $this->AliasNbPages();
        $this->setEmpresa('AMAZON MARKET');
    }

    protected function iniciar(string $titulo, array $datos, string $responsable): void {
        $this->setFechaGeneracion(isset($datos['generado_en']) ? (string)$datos['generado_en'] : null);
        $this->AddPage();
        $this->writeTitle($titulo);
        $filtros = $datos['filtros_aplicados'] ?? [];
        $this->metaEnvuelta('Período:', $this->formatDate($filtros['fecha_inicio']) . ' al ' . $this->formatDate($filtros['fecha_fin']));
        $this->metaEnvuelta('Generado por:', $responsable !== '' ? $responsable : 'Administrador');
        $this->metaEnvuelta('Referencia de consulta:', (string)($datos['referencia_consulta'] ?? ''));

        $labels = [
            'id_producto' => 'Producto ID', 'id_categoria' => 'Categoría ID',
            'id_subcategoria' => 'Subcategoría ID', 'q' => 'Búsqueda',
            'tipo_variacion' => 'Tipo de variación', 'sentido' => 'Sentido',
        ];
        foreach ($labels as $field => $label) {
            $value = $filtros[$field] ?? null;
            if ($value === null || $value === '') continue;
            if ($field === 'sentido') $value = ['TODOS'=>'Todas','INCREMENTO'=>'Entradas','DISMINUCION'=>'Salidas'][$value] ?? $value;
            $this->metaEnvuelta($label . ':', (string)$value);
        }
        $this->writeSeparator();
    }

    protected function seccion(string $titulo): void {
        if ($this->GetY() > $this->GetPageHeight() - 48) $this->AddPage();
        $this->writeSubtitle($titulo);
    }

    protected function metaEnvuelta(string $label, string $value): void {
        $y = $this->GetY();
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(100, 116, 139);
        $this->SetXY(15, $y);
        $this->Cell(48, 5, $label, 0, 0, 'L');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(30, 41, 59);
        $this->SetXY(63, $y);
        $this->MultiCell($this->GetPageWidth() - 78, 5, $value, 0, 'L');
        $this->SetY(max($y + 5, $this->GetY()));
    }

    /** Envuelve las celdas para conservar la justificación íntegra en auditoría. */
    protected function tablaCompleta(array $headers, array $rows, array $widths, array $aligns = []): void {
        if (!$rows) {
            $this->SetFont('Helvetica', 'I', 9);
            $this->SetTextColor(100, 116, 139);
            $this->MultiCell($this->GetPageWidth() - 30, 6, 'Sin registros para mostrar en el detalle.', 0, 'L');
            return;
        }
        $aligns = $aligns ?: array_fill(0, count($headers), 'L');
        $this->cabeceraTabla($headers, $widths);
        $lineHeight = 4;
        foreach ($rows as $index => $row) {
            $heights = [];
            foreach ($row as $column => $text) $heights[] = $this->lineas((string)$text, (float)$widths[$column] - 4) * $lineHeight + 2;
            $rowHeight = max($heights);
            if ($this->GetY() + $rowHeight > $this->GetPageHeight() - 25) {
                $this->AddPage();
                $this->cabeceraTabla($headers, $widths);
            }
            $y = $this->GetY();
            $x = 15;
            $fill = ($index % 2 === 0) ? [241,245,249] : [255,255,255];
            foreach ($row as $column => $text) {
                $this->SetFillColor(...$fill);
                $this->SetDrawColor(220,220,220);
                $this->SetTextColor(30,41,59);
                $this->SetFont('Helvetica', '', 7);
                $this->Rect($x, $y, $widths[$column], $rowHeight, 'DF');
                $this->SetXY($x, $y);
                $this->SetXY($x + 1, $y + 1);
                $this->MultiCell($widths[$column] - 2, $lineHeight, (string)$text, 0, $aligns[$column], false);
                $x += $widths[$column];
            }
            $this->SetXY(15, $y + $rowHeight);
        }
    }

    private function cabeceraTabla(array $headers, array $widths): void {
        if ($this->GetY() > $this->GetPageHeight() - 37) $this->AddPage();
        $this->SetFont('Helvetica','B',7);
        $this->SetFillColor(30,41,59);
        $this->SetTextColor(255,255,255);
        $this->SetDrawColor(30,41,59);
        $this->SetXY(15,$this->GetY());
        foreach ($headers as $index => $header) $this->Cell($widths[$index],8,$this->fitText($header,$widths[$index]-2),1,0,'C',true);
        $this->Ln();
    }

    private function lineas(string $text, float $width): int {
        $total = 0;
        foreach (explode("\n", str_replace(["\r\n","\r"],"\n",$text)) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u',trim($paragraph)) ?: [] as $word) {
                if ($word === '') continue;
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->getUtf8StringWidth($candidate) > $width) { $total++; $line=$word; }
                else $line=$candidate;
                while ($line !== '' && $this->getUtf8StringWidth($line) > $width) {
                    $chars = preg_split('//u',$line,-1,PREG_SPLIT_NO_EMPTY) ?: [];
                    $piece = '';
                    while ($chars && $this->getUtf8StringWidth($piece . $chars[0]) <= $width) $piece .= array_shift($chars);
                    $total++; $line=implode('',$chars);
                }
            }
            $total++;
        }
        return max(1,$total);
    }

    protected function obtenerFilasPorProducto(array $items): array {
        $groups=[];
        foreach ($items as $item) {
            $id=(int)$item['id_producto'];
            if (!isset($groups[$id])) $groups[$id]=['nombre'=>trim(($item['codigo_barra']??'')."\n".($item['producto_nombre']??'')),'unidad'=>$item['unidad_abreviatura']??'UND','operaciones'=>0,'entradas'=>0,'salidas'=>0];
            $groups[$id]['operaciones']++;
            if (($item['sentido']??$item['sentido_movimiento']??'')==='INCREMENTO') $groups[$id]['entradas']+=(int)($item['entrada']??$item['cantidad']??0);
            else $groups[$id]['salidas']+=(int)($item['salida']??$item['cantidad']??0);
        }
        return array_values($groups);
    }
}
