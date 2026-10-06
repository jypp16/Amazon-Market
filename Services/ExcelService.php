<?php

namespace Services;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;
use OpenSpout\Writer\XLSX\Options as XLSXOptions;

class ExcelService {

    private ?XLSXWriter $writer = null;
    private Style $headerStyle;
    private Style $moneyStyle;
    private Style $percentStyle;
    private Style $numberStyle;

    private function createTempFile(): string {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $filename = $tmp . '.xlsx';
        unlink($tmp);
        return $filename;
    }

    public function __construct() {
        $this->headerStyle = (new Style())
            ->setFontBold()
            ->setBackgroundColor('1E293B')
            ->setFontColor('D4AF37')
            ->setFontSize(11);

        $this->moneyStyle = (new Style())
            ->setFormat('#,##0.00');

        $this->percentStyle = (new Style())
            ->setFormat('0.0%');

        $this->numberStyle = (new Style())
            ->setFormat('#,##0');
    }

    private function initWriter(): XLSXWriter {
        $options = new XLSXOptions();
        $options->setTempFolder(sys_get_temp_dir());
        $this->writer = new XLSXWriter($options);
        return $this->writer;
    }

    private function addHeaderRow(array $headers): void {
        $cells = array_map(fn($h) => Cell::fromValue($h, $this->headerStyle), $headers);
        $this->writer->addRow(new Row($cells));
    }

    private function addDataRow(array $values, array $types = []): void {
        $cells = [];
        foreach ($values as $i => $val) {
            $type = $types[$i] ?? 'text';
            $cell = match($type) {
                'money' => Cell::fromValue(floatval($val), $this->moneyStyle),
                'percent' => Cell::fromValue(floatval($val) / 100, $this->percentStyle),
                'number' => Cell::fromValue(intval($val), $this->numberStyle),
                default => Cell::fromValue($val),
            };
            $cells[] = $cell;
        }
        $this->writer->addRow(new Row($cells));
    }

    private function addEmptyRow(): void {
        $this->writer->addRow(Row::fromValues(['']));
    }

    private function addTitleRow(string $title): void {
        $style = (new Style())->setFontBold()->setFontSize(14)->setFontColor('1E293B');
        $this->writer->addRow(new Row([Cell::fromValue($title, $style)]));
    }

    private function addMetaRow(string $label, string $value): void {
        $labelStyle = (new Style())->setFontBold()->setFontSize(10);
        $this->writer->addRow(new Row([
            Cell::fromValue($label, $labelStyle),
            Cell::fromValue($value),
        ]));
    }

    private function addTotalRow(array $totals): void {
        $this->addEmptyRow();
        $style = (new Style())->setFontBold()->setFontSize(10)->setBackgroundColor('F3F4F6');
        foreach ($totals as $label => $value) {
            $this->writer->addRow(new Row([
                Cell::fromValue($label, $style),
                Cell::fromValue($value, $style),
            ]));
        }
    }

    public function open(string $filePath): void {
        $this->initWriter();
        $this->writer->openToFile($filePath);
    }

    public function close(): void {
        if ($this->writer) {
            $this->writer->close();
            $this->writer = null;
        }
    }

    public function generarVentas(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('REPORTE DE VENTAS POR PERIODO');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $resumen = $data['resumen'] ?? [];
        $this->addMetaRow('Total Ventas:', number_format($resumen['total_ventas'] ?? 0));
        $this->addMetaRow('Ingresos Totales:', 'S/. ' . number_format($resumen['total_ingresos'] ?? 0, 2));
        $this->addMetaRow('Ticket Promedio:', 'S/. ' . number_format($resumen['ticket_promedio'] ?? 0, 2));
        $this->addEmptyRow();

        $this->addHeaderRow(['Fecha', 'Serie', 'Numero', 'Cliente', 'Comprobante', 'Total', 'Vendedor']);
        $totalGeneral = 0;
        foreach ($data['ventas'] ?? [] as $v) {
            $this->addDataRow([
                date('d/m/Y H:i', strtotime($v['fecha_venta'])),
                $v['serie'],
                $v['numero'],
                $v['cliente'],
                $v['comprobante'],
                floatval($v['total']),
                $v['vendedor'],
            ], ['text', 'text', 'text', 'text', 'text', 'money', 'text']);
            $totalGeneral += floatval($v['total']);
        }
        $this->addTotalRow([
            'Total Registros:' => count($data['ventas'] ?? []),
            'Ingresos Totales:' => 'S/. ' . number_format($totalGeneral, 2),
        ]);

        $this->close();
        return $filename;
    }

    public function generarProductosMasVendidos(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('PRODUCTOS MAS VENDIDOS');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $this->addHeaderRow(['#', 'Producto', 'Categoria', 'Unidades', 'Ingresos', '% del Total']);
        foreach ($data['productos'] ?? [] as $i => $p) {
            $this->addDataRow([
                $i + 1,
                $p['nombre'],
                $p['categoria'],
                intval($p['unidades_vendidas']),
                floatval($p['ingresos']),
                floatval($p['porcentaje_ingresos']),
            ], ['number', 'text', 'text', 'number', 'money', 'percent']);
        }
        $this->addTotalRow([
            'Total Productos:' => count($data['productos'] ?? []),
            'Ingresos Totales:' => 'S/. ' . number_format($data['totalIngresos'] ?? 0, 2),
            'Unidades Totales:' => number_format($data['totalUnidades'] ?? 0),
        ]);

        $this->close();
        return $filename;
    }

    public function generarProductosMenosVendidos(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('PRODUCTOS MENOS VENDIDOS / SIN MOVIMIENTO');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $this->addHeaderRow(['Producto', 'Categoria', 'Stock Actual', 'Stock Min.', 'Precio', 'Unid. Vendidas', 'Ingresos']);
        $totalIngresos = 0;
        foreach ($data['productos'] ?? [] as $p) {
            $this->addDataRow([
                $p['nombre'] . ($p['stock_actual'] <= $p['stock_minimo'] ? ' *' : ''),
                $p['categoria'],
                intval($p['stock_actual']),
                intval($p['stock_minimo']),
                floatval($p['precio_venta']),
                intval($p['unidades_vendidas']),
                floatval($p['ingresos']),
            ], ['text', 'text', 'number', 'number', 'money', 'number', 'money']);
            $totalIngresos += floatval($p['ingresos']);
        }
        $this->addTotalRow([
            'Total Productos:' => count($data['productos'] ?? []),
            'Ingresos Totales:' => 'S/. ' . number_format($totalIngresos, 2),
        ]);

        $this->close();
        return $filename;
    }

    public function generarInventario(array $data): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('VALOR DE INVENTARIO Y STOCK BAJO');
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $resumen = $data['resumen'] ?? [];
        $this->addMetaRow('Total SKUs:', number_format($resumen['total_skus'] ?? 0));
        $this->addMetaRow('Valor Total:', 'S/. ' . number_format($resumen['valor_total'] ?? 0, 2));
        $this->addMetaRow('Stock Bajo:', number_format($resumen['productos_stock_bajo'] ?? 0));
        $this->addEmptyRow();

        $this->addHeaderRow(['Codigo', 'Producto', 'Categoria', 'Stock', 'Minimo', 'Precio', 'Valor Stock', 'Estado']);
        $valorTotal = 0;
        foreach ($data['productos'] ?? [] as $p) {
            $this->addDataRow([
                $p['codigo_barra'],
                $p['nombre'],
                $p['categoria'],
                intval($p['stock_actual']),
                intval($p['stock_minimo']),
                floatval($p['precio_venta']),
                floatval($p['valor_stock']),
                $p['stock_bajo'] ? 'STOCK BAJO' : 'OK',
            ], ['text', 'text', 'text', 'number', 'number', 'money', 'money', 'text']);
            $valorTotal += floatval($p['valor_stock']);
        }
        $this->addTotalRow([
            'Total Productos:' => count($data['productos'] ?? []),
            'Valor Total:' => 'S/. ' . number_format($valorTotal, 2),
        ]);

        $this->close();
        return $filename;
    }

    public function generarClientes(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('REPORTE DE CLIENTES');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $this->addHeaderRow(['Cliente', 'Documento', '# Compras', 'Total Gastado', 'Ticket Prom.', 'Ultima Compra']);
        $totalGastado = 0;
        foreach ($data['clientes'] ?? [] as $c) {
            $this->addDataRow([
                $c['nombre'],
                $c['tipo_doc'] . ' ' . $c['nro_documento'],
                intval($c['num_compras']),
                floatval($c['total_gastado']),
                floatval($c['ticket_promedio']),
                date('d/m/Y', strtotime($c['ultima_compra'])),
            ], ['text', 'text', 'number', 'money', 'money', 'text']);
            $totalGastado += floatval($c['total_gastado']);
        }
        $this->addTotalRow([
            'Total Clientes:' => count($data['clientes'] ?? []),
            'Ingresos por Clientes:' => 'S/. ' . number_format($totalGastado, 2),
        ]);

        $this->close();
        return $filename;
    }

    public function generarVendedores(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('VENTAS POR VENDEDOR');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $this->addHeaderRow(['Vendedor', '# Ventas', 'Ingreso Total', 'Ticket Promedio', '% del Total']);
        foreach ($data['vendedores'] ?? [] as $v) {
            $this->addDataRow([
                $v['vendedor'],
                intval($v['num_ventas']),
                floatval($v['ingreso_total']),
                floatval($v['ticket_promedio']),
                floatval($v['porcentaje']),
            ], ['text', 'number', 'money', 'money', 'percent']);
        }
        $this->addTotalRow([
            'Total Vendedores:' => count($data['vendedores'] ?? []),
            'Ingresos Totales:' => 'S/. ' . number_format($data['totalIngresos'] ?? 0, 2),
        ]);

        $this->close();
        return $filename;
    }

    public function generarCategorias(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('VENTAS POR CATEGORIA');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $this->addHeaderRow(['Categoria', 'Unidades', 'Ingresos', '% del Total']);
        foreach ($data['categorias'] ?? [] as $c) {
            $this->addDataRow([
                $c['categoria'],
                intval($c['unidades']),
                floatval($c['ingresos']),
                floatval($c['porcentaje']),
            ], ['text', 'number', 'money', 'percent']);
        }
        $this->addTotalRow([
            'Total Categorias:' => count($data['categorias'] ?? []),
            'Ingresos Totales:' => 'S/. ' . number_format($data['totalIngresos'] ?? 0, 2),
            'Unidades Totales:' => number_format($data['totalUnidades'] ?? 0),
        ]);

        $this->close();
        return $filename;
    }

    public function generarComprobantes(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('REPORTE DE COMPROBANTES');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $this->addHeaderRow(['Comprobante', 'Serie', 'Cantidad', 'Total']);
        foreach ($data['comprobantes'] ?? [] as $c) {
            $this->addDataRow([
                $c['comprobante'],
                $c['serie'],
                intval($c['cantidad']),
                floatval($c['total']),
            ], ['text', 'text', 'number', 'money']);
        }
        $montoTotal = floatval($data['montoBoletas'] ?? 0) + floatval($data['montoFacturas'] ?? 0);
        $this->addTotalRow([
            'Total Comprobantes:' => count($data['comprobantes'] ?? []),
            'Monto Total:' => 'S/. ' . number_format($montoTotal, 2),
        ]);

        $this->close();
        return $filename;
    }

    public function generarMetodosPago(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $this->addTitleRow('METODOS DE PAGO');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $this->addHeaderRow(['Metodo de Pago', '# Transacciones', 'Monto Total', '% del Total']);
        foreach ($data['metodos'] ?? [] as $m) {
            $this->addDataRow([
                $m['metodo_pago'],
                intval($m['num_transacciones']),
                floatval($m['monto_total']),
                floatval($m['porcentaje']),
            ], ['text', 'number', 'money', 'percent']);
        }
        $this->addTotalRow([
            'Total Metodos:' => count($data['metodos'] ?? []),
            'Total Transacciones:' => number_format($data['totalTransacciones'] ?? 0),
            'Total Recaudado:' => 'S/. ' . number_format($data['totalMontos'] ?? 0, 2),
        ]);

        $this->close();
        return $filename;
    }

    public function generarResumen(array $data, array $input): string {
        $filename = $this->createTempFile();
        $this->open($filename);

        $ventas = $data['ventas'] ?? [];
        $resumen = $ventas['resumen'] ?? [];
        $inventario = $data['inventario'] ?? [];
        $invResumen = $inventario['resumen'] ?? [];

        $this->addTitleRow('RESUMEN EJECUTIVO');
        $this->addMetaRow('Periodo:', ($input['desde'] ?? date('Y-m-01')) . ' al ' . ($input['hasta'] ?? date('Y-m-d')));
        $this->addMetaRow('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $this->addEmptyRow();

        $this->addMetaRow('Ingresos Totales:', 'S/. ' . number_format($resumen['total_ingresos'] ?? 0, 2));
        $this->addMetaRow('Total Ventas:', number_format($resumen['total_ventas'] ?? 0));
        $this->addMetaRow('Ticket Promedio:', 'S/. ' . number_format($resumen['ticket_promedio'] ?? 0, 2));
        $this->addMetaRow('Valor Inventario:', 'S/. ' . number_format($invResumen['valor_total'] ?? 0, 2));
        $this->addEmptyRow();

        $this->addHeaderRow(['TOP 5 PRODUCTOS MAS VENDIDOS', 'Ingresos']);
        foreach (($data['masVendidos']['productos'] ?? []) as $p) {
            $this->addDataRow([$p['nombre'], floatval($p['ingresos'])], ['text', 'money']);
        }
        $this->addEmptyRow();

        $this->addHeaderRow(['TOP 3 CLIENTES', 'Total Gastado']);
        foreach (($data['topClientes']['clientes'] ?? []) as $c) {
            $this->addDataRow([$c['nombre'], floatval($c['total_gastado'])], ['text', 'money']);
        }
        $this->addEmptyRow();

        $this->addHeaderRow(['VENDEDOR', '# Ventas', 'Ingresos', '%']);
        foreach (($data['vendedores']['vendedores'] ?? []) as $v) {
            $this->addDataRow([
                $v['vendedor'],
                intval($v['num_ventas']),
                floatval($v['ingreso_total']),
                floatval($v['porcentaje']),
            ], ['text', 'number', 'money', 'percent']);
        }
        $this->addEmptyRow();

        $this->addHeaderRow(['METODO DE PAGO', '# Transacciones', 'Monto', '%']);
        foreach (($data['metodos']['metodos'] ?? []) as $m) {
            $this->addDataRow([
                $m['metodo_pago'],
                intval($m['num_transacciones']),
                floatval($m['monto_total']),
                floatval($m['porcentaje']),
            ], ['text', 'number', 'money', 'percent']);
        }

        $this->close();
        return $filename;
    }
}