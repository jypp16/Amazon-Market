<?php

require_once __DIR__ . '/fpdf.php';

class ReportPDF extends FPDF {

    private $empresa = 'AMAZON MARKET';
    private $colorPrimary = [212, 175, 55];
    private $colorDark = [30, 41, 59];
    private $colorGray = [100, 116, 139];
    private $colorLight = [241, 245, 249];
    private $colorWhite = [255, 255, 255];
    private $colorDanger = [220, 38, 38];
    private $colorSuccess = [22, 163, 74];

    public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4') {
        parent::__construct($orientation, $unit, $format);
        $this->SetAutoPageBreak(true, 25);
        $this->SetMargins(15, 15, 15);
    }

    public function setEmpresa(string $empresa): void {
        $this->empresa = $empresa;
    }

    public function Header(): void {
        $this->SetFillColor(255, 255, 255);
        $this->Rect(0, 0, 210, 297, 'F');

        $this->SetFillColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
        $this->Rect(0, 0, 210, 35, 'F');

        $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
        $this->SetFont('Helvetica', 'B', 18);
        $this->SetXY(15, 10);
        $this->Cell(0, 10, $this->empresa, 0, 1, 'L');

        $this->SetTextColor($this->colorWhite[0], $this->colorWhite[1], $this->colorWhite[2]);
        $this->SetFont('Helvetica', '', 10);
        $this->SetXY(15, 22);
        $this->Cell(0, 8, date('d/m/Y H:i'), 0, 1, 'L');

        $this->SetY(42);
    }

    public function Footer(): void {
        $this->SetY(-20);
        $this->SetDrawColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(3);

        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor($this->colorGray[0], $this->colorGray[1], $this->colorGray[2]);
        $this->Cell(0, 5, $this->empresa . ' | Reporte generado el ' . date('d/m/Y H:i:s'), 0, 0, 'L');
        $this->Cell(0, 5, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    public function writeTitle(string $titulo): void {
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
        $this->Cell(0, 10, $titulo, 0, 1, 'L');
        $this->SetDrawColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
        $this->SetLineWidth(0.8);
        $this->Line(15, $this->GetY(), 80, $this->GetY());
        $this->Ln(6);
    }

    public function writeSubtitle(string $texto): void {
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
        $this->Cell(0, 8, $texto, 0, 1, 'L');
        $this->Ln(2);
    }

    public function writeMeta(string $etiqueta, string $valor): void {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor($this->colorGray[0], $this->colorGray[1], $this->colorGray[2]);
        $this->Cell(45, 6, $etiqueta, 0, 0, 'L');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
        $this->Cell(0, 6, $valor, 0, 1, 'L');
    }

    public function writeSeparator(): void {
        $this->Ln(3);
        $this->SetDrawColor($this->colorLight[0], $this->colorLight[1], $this->colorLight[2]);
        $this->SetLineWidth(0.3);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(5);
    }

    public function writeKPI(string $etiqueta, string $valor, string $color = 'dark', float $boxWidth = 55): void {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor($this->colorLight[0], $this->colorLight[1], $this->colorLight[2]);
        $this->Rect($x, $y, $boxWidth, 22, 'F');

        $this->SetXY($x + 3, $y + 2);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor($this->colorGray[0], $this->colorGray[1], $this->colorGray[2]);
        $this->Cell($boxWidth - 6, 5, $etiqueta, 0, 1, 'L');

        $this->SetXY($x + 3, $y + 9);
        $this->SetFont('Helvetica', 'B', 13);

        if ($color === 'gold') {
            $this->SetTextColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
        } elseif ($color === 'danger') {
            $this->SetTextColor($this->colorDanger[0], $this->colorDanger[1], $this->colorDanger[2]);
        } elseif ($color === 'success') {
            $this->SetTextColor($this->colorSuccess[0], $this->colorSuccess[1], $this->colorSuccess[2]);
        } else {
            $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
        }

        $this->Cell($boxWidth - 6, 8, $valor, 0, 1, 'L');
        $this->SetXY($x + $boxWidth + 3, $y);
    }

    public function writeKPIRow(array $kpis): void {
        $startX = 15;
        $availableWidth = 180;
        $numKpis = count($kpis);
        $gap = 3;
        $boxWidth = ($availableWidth - ($numKpis - 1) * $gap) / $numKpis;
        $boxWidth = min($boxWidth, 55);

        $this->SetX($startX);
        foreach ($kpis as $kpi) {
            $this->writeKPI($kpi['label'], $kpi['value'], $kpi['color'] ?? 'dark', $boxWidth);
        }
        $this->Ln(28);
    }

    private function writeTableHeader(array $headers, array $colWidths): void {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
        $this->SetTextColor($this->colorWhite[0], $this->colorWhite[1], $this->colorWhite[2]);
        $this->SetDrawColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);

        $x = 15;
        $this->SetX($x);
        for ($i = 0; $i < count($headers); $i++) {
            $this->Cell($colWidths[$i], 8, $headers[$i], 1, 0, 'C', true);
        }
        $this->Ln();
    }

    public function writeTotals(array $totales): void {
        $this->Ln(5);
        $this->SetDrawColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
        $this->SetLineWidth(0.5);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(3);

        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);

        foreach ($totales as $etiqueta => $valor) {
            $this->SetX(120);
            $this->Cell(40, 6, $etiqueta, 0, 0, 'R');
            $this->SetFont('Helvetica', 'B', 10);
            $this->SetTextColor($this->colorPrimary[0], $this->colorPrimary[1], $this->colorPrimary[2]);
            $this->Cell(30, 6, $valor, 0, 1, 'R');
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
        }
    }

    public function formatMoney($value): string {
        return 'S/. ' . number_format(floatval($value), 2);
    }

    public function formatNumber($value): string {
        return number_format(intval($value), 0, ',', '.');
    }

    public function formatPercent($value): string {
        return number_format(floatval($value), 1) . '%';
    }

    public function formatDate($date): string {
        return date('d/m/Y', strtotime($date));
    }

    public function formatDateFull($date): string {
        return date('d/m/Y H:i', strtotime($date));
    }

    private function toLatin1(string $txt): string {
        if (mb_check_encoding($txt, 'UTF-8')) {
            return mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8');
        }
        return $txt;
    }

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {
        return parent::Cell($w, $h, $this->toLatin1($txt), $border, $ln, $align, $fill, $link);
    }

    public function MultiCell($w, $h = 0, $txt = '', $border = 0, $align = '', $fill = false) {
        return parent::MultiCell($w, $h, $this->toLatin1($txt), $border, $align, $fill);
    }

    public function fitText(string $txt, float $maxWidth): string {
        $txt = (string) $txt;
        if ($this->GetStringWidth($txt) <= $maxWidth) {
            return $txt;
        }
        while (mb_strlen($txt) > 0 && $this->GetStringWidth($txt . '...') > $maxWidth) {
            $txt = mb_substr($txt, 0, -1);
        }
        return $txt . '...';
    }

    public function writeTable(array $headers, array $rows, array $colWidths = [], array $aligns = []): void {
        if (empty($rows)) {
            $this->SetFont('Helvetica', 'I', 10);
            $this->SetTextColor($this->colorGray[0], $this->colorGray[1], $this->colorGray[2]);
            $this->Cell(0, 10, 'No hay datos para mostrar.', 0, 1, 'C');
            return;
        }

        $numCols = count($headers);
        $totalWidth = 180;
        if (empty($colWidths)) {
            $colWidth = $totalWidth / $numCols;
            $colWidths = array_fill(0, $numCols, $colWidth);
        }
        if (empty($aligns)) {
            $aligns = array_fill(0, $numCols, 'L');
        }

        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
        $this->SetTextColor($this->colorWhite[0], $this->colorWhite[1], $this->colorWhite[2]);
        $this->SetDrawColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);

        $x = 15;
        $this->SetX($x);
        for ($i = 0; $i < $numCols; $i++) {
            $this->Cell($colWidths[$i], 8, $headers[$i], 1, 0, 'C', true);
        }
        $this->Ln();

        $this->SetFont('Helvetica', '', 8);
        $rowNum = 0;
        foreach ($rows as $row) {
            if ($this->GetY() > 260) {
                $this->AddPage();
                $this->writeTableHeader($headers, $colWidths);
            }

            if ($rowNum % 2 === 0) {
                $this->SetFillColor($this->colorLight[0], $this->colorLight[1], $this->colorLight[2]);
            } else {
                $this->SetFillColor($this->colorWhite[0], $this->colorWhite[1], $this->colorWhite[2]);
            }

            $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]);
            $this->SetDrawColor(220, 220, 220);

            $x = 15;
            $this->SetX($x);
            $maxH = 7;
            for ($i = 0; $i < $numCols; $i++) {
                $cellWidth = $colWidths[$i] - 2;
                $txt = $this->fitText($row[$i] ?? '', $cellWidth);
                $this->Cell($colWidths[$i], $maxH, $txt, 1, 0, $aligns[$i], true);
            }
            $this->Ln();
            $rowNum++;
        }
    }
}
