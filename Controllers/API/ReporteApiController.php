<?php

namespace Controllers\API;

use Libraries\Core\ApiController;
use Services\ReporteService;
use Services\MailService;

require_once __DIR__ . '/../../Libraries/PDF/ReportPDF.php';

class ReporteApiController extends ApiController {

    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new ReporteService();
    }

    public function get(?string $params = ""): void {
        $this->requirePermission('reportes.ver');

        $tipo = $params ?? '';
        $input = $_GET;

        switch ($tipo) {
            case 'ventas':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteVentas($input)]);
                break;
            case 'productos-mas-vendidos':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteProductosMasVendidos($input)]);
                break;
            case 'productos-menos-vendidos':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteProductosMenosVendidos($input)]);
                break;
            case 'inventario':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteInventario()]);
                break;
            case 'clientes':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteClientes($input)]);
                break;
            case 'vendedores':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteVendedores($input)]);
                break;
            case 'categorias':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteCategorias($input)]);
                break;
            case 'comprobantes':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteComprobantes($input)]);
                break;
            case 'metodos-pago':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteMetodosPago($input)]);
                break;
            case 'resumen':
                $this->sendJsonResponse(['status' => true, 'data' => $this->service->reporteResumen($input)]);
                break;
            case 'exportar-pdf':
                $this->requirePermission('reportes.exportar');
                $this->exportarPdf($input);
                break;
            case 'exportar-excel':
                $this->requirePermission('reportes.exportar');
                $this->exportarExcel($input);
                break;
            default:
                $this->sendJsonResponse(['status' => false, 'message' => 'Tipo de reporte no válido.'], 400);
        }
    }

    public function post(?string $params = ""): void {
        $this->requirePermission('reportes.exportar');

        $input = $this->getInput();
        $tipo = $input['tipo'] ?? '';
        $email = $input['email'] ?? '';

        if (empty($tipo) || empty($email)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Faltan parámetros: tipo y email son requeridos.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendJsonResponse(['status' => false, 'message' => 'El correo electrónico no es válido.'], 400);
        }

        $inputFiltros = array_diff_key($input, array_flip(['tipo', 'email']));
        $pdfContent = $this->generarPdfContent($tipo, $inputFiltros);

        if ($pdfContent === null) {
            $this->sendJsonResponse(['status' => false, 'message' => 'Tipo de reporte no válido.'], 400);
        }

        $nombreMap = [
            'ventas' => 'Ventas por Periodo',
            'productos-mas-vendidos' => 'Productos Mas Vendidos',
            'productos-menos-vendidos' => 'Productos Menos Vendidos',
            'inventario' => 'Inventario',
            'clientes' => 'Clientes',
            'vendedores' => 'Vendedores',
            'categorias' => 'Categorias',
            'comprobantes' => 'Comprobantes',
            'metodos-pago' => 'Metodos de Pago',
            'resumen' => 'Resumen Ejecutivo',
        ];
        $nombreReporte = $nombreMap[$tipo] ?? ucfirst($tipo);
        $asunto = 'Reporte ' . $nombreReporte . ' - AmazonMarket';
        $filename = 'Reporte_' . $tipo . '_' . date('Y-m-d') . '.pdf';

        $mailService = new MailService();
        if ($mailService->enviarReportePDF($email, $asunto, $pdfContent, $filename, $nombreReporte)) {
            $this->sendJsonResponse(['status' => true, 'message' => 'Reporte enviado correctamente a ' . $email . '.']);
        } else {
            $this->sendJsonResponse(['status' => false, 'message' => 'Error al enviar el correo: ' . $mailService->getLastError()], 500);
        }
    }

    private function exportarPdf(array $input): void {
        $tipo = $input['tipo'] ?? '';
        $filename = 'Reporte_' . $tipo . '_' . date('Y-m-d_His') . '.pdf';

        $pdf = new \ReportPDF();
        $pdf->AliasNbPages();
        $pdf->setEmpresa('AMAZON MARKET');

        switch ($tipo) {
            case 'ventas':
                $this->generarPdfVentas($pdf, $input);
                break;
            case 'productos-mas-vendidos':
                $this->generarPdfProductosMasVendidos($pdf, $input);
                break;
            case 'productos-menos-vendidos':
                $this->generarPdfProductosMenosVendidos($pdf, $input);
                break;
            case 'inventario':
                $this->generarPdfInventario($pdf, $input);
                break;
            case 'clientes':
                $this->generarPdfClientes($pdf, $input);
                break;
            case 'vendedores':
                $this->generarPdfVendedores($pdf, $input);
                break;
            case 'categorias':
                $this->generarPdfCategorias($pdf, $input);
                break;
            case 'comprobantes':
                $this->generarPdfComprobantes($pdf, $input);
                break;
            case 'metodos-pago':
                $this->generarPdfMetodosPago($pdf, $input);
                break;
            case 'resumen':
                $this->generarPdfResumen($pdf, $input);
                break;
            default:
                $pdf->AddPage();
                $pdf->writeTitle('ERROR');
                $pdf->writeSubtitle('Tipo de reporte no valido.');
                $pdf->Output('D', $filename);
                exit;
        }

        $pdf->Output('D', $filename);
        exit;
    }

    private function getPeriodo(array $input): string {
        $desde = $input['desde'] ?? date('Y-m-01');
        $hasta = $input['hasta'] ?? date('Y-m-d');
        return date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));
    }

    private function generarPdfContent(string $tipo, array $input): ?string {
        $pdf = new \ReportPDF();
        $pdf->AliasNbPages();
        $pdf->setEmpresa('AMAZON MARKET');

        switch ($tipo) {
            case 'ventas':
                $this->generarPdfVentas($pdf, $input);
                break;
            case 'productos-mas-vendidos':
                $this->generarPdfProductosMasVendidos($pdf, $input);
                break;
            case 'productos-menos-vendidos':
                $this->generarPdfProductosMenosVendidos($pdf, $input);
                break;
            case 'inventario':
                $this->generarPdfInventario($pdf, $input);
                break;
            case 'clientes':
                $this->generarPdfClientes($pdf, $input);
                break;
            case 'vendedores':
                $this->generarPdfVendedores($pdf, $input);
                break;
            case 'categorias':
                $this->generarPdfCategorias($pdf, $input);
                break;
            case 'comprobantes':
                $this->generarPdfComprobantes($pdf, $input);
                break;
            case 'metodos-pago':
                $this->generarPdfMetodosPago($pdf, $input);
                break;
            case 'resumen':
                $this->generarPdfResumen($pdf, $input);
                break;
            default:
                return null;
        }

        return $pdf->Output('S');
    }

    private function exportarExcel(array $input): void {
        $tipo = $input['tipo'] ?? '';
        $excelService = new \Services\ExcelService();

        switch ($tipo) {
            case 'ventas':
                $data = $this->service->reporteVentas($input);
                $file = $excelService->generarVentas($data, $input);
                break;
            case 'productos-mas-vendidos':
                $data = $this->service->reporteProductosMasVendidos($input);
                $file = $excelService->generarProductosMasVendidos($data, $input);
                break;
            case 'productos-menos-vendidos':
                $data = $this->service->reporteProductosMenosVendidos($input);
                $file = $excelService->generarProductosMenosVendidos($data, $input);
                break;
            case 'inventario':
                $data = $this->service->reporteInventario();
                $file = $excelService->generarInventario($data);
                break;
            case 'clientes':
                $data = $this->service->reporteClientes($input);
                $file = $excelService->generarClientes($data, $input);
                break;
            case 'vendedores':
                $data = $this->service->reporteVendedores($input);
                $file = $excelService->generarVendedores($data, $input);
                break;
            case 'categorias':
                $data = $this->service->reporteCategorias($input);
                $file = $excelService->generarCategorias($data, $input);
                break;
            case 'comprobantes':
                $data = $this->service->reporteComprobantes($input);
                $file = $excelService->generarComprobantes($data, $input);
                break;
            case 'metodos-pago':
                $data = $this->service->reporteMetodosPago($input);
                $file = $excelService->generarMetodosPago($data, $input);
                break;
            case 'resumen':
                $data = $this->service->reporteResumen($input);
                $file = $excelService->generarResumen($data, $input);
                break;
            default:
                http_response_code(400);
                echo json_encode(['status' => false, 'message' => 'Tipo de reporte no válido.']);
                return;
        }

        $filename = 'Reporte_' . $tipo . '_' . date('Y-m-d_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($file);
        unlink($file);
        exit;
    }

    private function generarPdfVentas($pdf, array $input): void {
        $data = $this->service->reporteVentas($input);
        $resumen = $data['resumen'] ?? [];

        $pdf->AddPage();
        $pdf->writeTitle('VENTAS POR PERIODO');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeKPIRow([
            ['label' => 'Total Ventas', 'value' => $pdf->formatNumber($resumen['total_ventas'] ?? 0)],
            ['label' => 'Ingresos Totales', 'value' => $pdf->formatMoney($resumen['total_ingresos'] ?? 0), 'color' => 'gold'],
            ['label' => 'Ticket Promedio', 'value' => $pdf->formatMoney($resumen['ticket_promedio'] ?? 0)],
            ['label' => 'Vs Periodo Ant.', 'value' => ($data['varIngresos'] ?? 0) . '%', 'color' => ($data['varIngresos'] ?? 0) >= 0 ? 'success' : 'danger'],
        ]);

        $pdf->writeSubtitle('DESGLOSE POR COMPROBANTE');
        $pdf->writeKPIRow([
            ['label' => 'Boletas', 'value' => $pdf->formatNumber($data['boletas'] ?? 0)],
            ['label' => 'Facturas', 'value' => $pdf->formatNumber($data['facturas'] ?? 0)],
        ]);

        $pdf->writeSubtitle('DETALLE DE VENTAS');
        $headers = ['Fecha', 'Serie', 'Numero', 'Cliente', 'Comprobante', 'Total', 'Vendedor'];
        $widths = [32, 10, 16, 38, 24, 28, 32];
        $aligns = ['L', 'C', 'C', 'L', 'L', 'R', 'L'];

        $rows = [];
        $totalGeneral = 0;
        foreach ($data['ventas'] as $v) {
            $rows[] = [
                $pdf->formatDateFull($v['fecha_venta']),
                $v['serie'],
                $v['numero'],
                mb_substr($v['cliente'], 0, 25),
                mb_substr($v['comprobante'], 0, 16),
                $pdf->formatMoney($v['total']),
                mb_substr($v['vendedor'], 0, 21),
            ];
            $totalGeneral += floatval($v['total']);
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeTotals([
            'Total Registros:' => $pdf->formatNumber(count($data['ventas'])),
            'Ingresos Totales:' => $pdf->formatMoney($totalGeneral),
        ]);
    }

    private function generarPdfProductosMasVendidos($pdf, array $input): void {
        $data = $this->service->reporteProductosMasVendidos($input);

        $pdf->AddPage();
        $pdf->writeTitle('PRODUCTOS MAS VENDIDOS');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeKPIRow([
            ['label' => 'Total Productos', 'value' => $pdf->formatNumber(count($data['productos']))],
            ['label' => 'Ingresos Totales', 'value' => $pdf->formatMoney($data['totalIngresos'] ?? 0), 'color' => 'gold'],
            ['label' => 'Unidades Totales', 'value' => $pdf->formatNumber($data['totalUnidades'] ?? 0)],
        ]);

        $pdf->writeSubtitle('RANKING DE PRODUCTOS');
        $headers = ['Producto', 'Categoria', 'Unidades', 'Ingresos', '% del Total'];
        $widths = [64, 48, 22, 28, 18];
        $aligns = ['L', 'L', 'C', 'R', 'C'];

        $rows = [];
        foreach ($data['productos'] as $p) {
            $rows[] = [
                mb_substr($p['nombre'], 0, 42),
                mb_substr($p['categoria'], 0, 32),
                $pdf->formatNumber($p['unidades_vendidas']),
                $pdf->formatMoney($p['ingresos']),
                $pdf->formatPercent($p['porcentaje_ingresos']),
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeTotals([
            'Total Productos:' => $pdf->formatNumber(count($data['productos'])),
            'Ingresos Totales:' => $pdf->formatMoney($data['totalIngresos'] ?? 0),
            'Unidades Totales:' => $pdf->formatNumber($data['totalUnidades'] ?? 0),
        ]);
    }

    private function generarPdfProductosMenosVendidos($pdf, array $input): void {
        $data = $this->service->reporteProductosMenosVendidos($input);

        $pdf->AddPage();
        $pdf->writeTitle('PRODUCTOS MENOS VENDIDOS / SIN MOVIMIENTO');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $headers = ['Producto', 'Categoria', 'Stock Actual', 'Stock Min.', 'Precio', 'Unid. Vendidas', 'Ingresos'];
        $widths = [44, 32, 20, 16, 24, 20, 24];
        $aligns = ['L', 'L', 'C', 'C', 'R', 'C', 'R'];

        $rows = [];
        $totalIngresos = 0;
        foreach ($data['productos'] as $p) {
            $estado = $p['stock_actual'] <= $p['stock_minimo'] ? ' *' : '';
            $rows[] = [
                mb_substr($p['nombre'], 0, 29) . $estado,
                mb_substr($p['categoria'], 0, 21),
                $pdf->formatNumber($p['stock_actual']),
                $pdf->formatNumber($p['stock_minimo']),
                $pdf->formatMoney($p['precio_venta']),
                $pdf->formatNumber($p['unidades_vendidas']),
                $pdf->formatMoney($p['ingresos']),
            ];
            $totalIngresos += floatval($p['ingresos']);
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeTotals([
            'Total Productos:' => $pdf->formatNumber(count($data['productos'])),
            'Ingresos Totales:' => $pdf->formatMoney($totalIngresos),
        ]);
    }

    private function generarPdfInventario($pdf, array $input): void {
        $data = $this->service->reporteInventario();
        $resumen = $data['resumen'] ?? [];

        $pdf->AddPage();
        $pdf->writeTitle('VALOR DE INVENTARIO Y STOCK BAJO');
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeKPIRow([
            ['label' => 'Total SKUs', 'value' => $pdf->formatNumber($resumen['total_skus'] ?? 0)],
            ['label' => 'Valor Total', 'value' => $pdf->formatMoney($resumen['valor_total'] ?? 0), 'color' => 'gold'],
            ['label' => 'Stock Bajo', 'value' => $pdf->formatNumber($resumen['productos_stock_bajo'] ?? 0), 'color' => 'danger'],
            ['label' => 'Valor en Riesgo', 'value' => $pdf->formatMoney($resumen['valor_stock_riesgo'] ?? 0), 'color' => 'danger'],
        ]);

        $pdf->writeSubtitle('DETALLE DE INVENTARIO');
        $headers = ['Codigo', 'Producto', 'Categoria', 'Stock', 'Minimo', 'Precio', 'Valor Stock', 'Estado'];
        $widths = [25, 36, 29, 13, 13, 21, 22, 21];
        $aligns = ['C', 'L', 'L', 'C', 'C', 'R', 'R', 'C'];

        $rows = [];
        $valorTotal = 0;
        foreach ($data['productos'] as $p) {
            $estado = $p['stock_bajo'] ? 'STOCK BAJO' : 'OK';
            $rows[] = [
                $p['codigo_barra'],
                mb_substr($p['nombre'], 0, 24),
                mb_substr($p['categoria'], 0, 19),
                $pdf->formatNumber($p['stock_actual']),
                $pdf->formatNumber($p['stock_minimo']),
                $pdf->formatMoney($p['precio_venta']),
                $pdf->formatMoney($p['valor_stock']),
                $estado,
            ];
            $valorTotal += floatval($p['valor_stock']);
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeTotals([
            'Total Productos:' => $pdf->formatNumber(count($data['productos'])),
            'Valor Total:' => $pdf->formatMoney($valorTotal),
        ]);
    }

    private function generarPdfClientes($pdf, array $input): void {
        $data = $this->service->reporteClientes($input);

        $pdf->AddPage();
        $pdf->writeTitle('REPORTE DE CLIENTES');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeSubtitle('DISTRIBUCION DE CLIENTES');
        foreach ($data['distribucion'] ?? [] as $d) {
            $pdf->writeMeta($d['tipo'] . ':', $pdf->formatNumber($d['cantidad']));
        }
        $pdf->Ln(3);

        $pdf->writeSubtitle('TOP CLIENTES');
        $headers = ['Cliente', 'Documento', '# Compras', 'Total Gastado', 'Ticket Prom.', 'Ultima Compra'];
        $widths = [58, 29, 20, 26, 26, 21];
        $aligns = ['L', 'L', 'C', 'R', 'R', 'C'];

        $rows = [];
        $totalGastado = 0;
        foreach ($data['clientes'] as $c) {
            $rows[] = [
                mb_substr($c['nombre'], 0, 38),
                $c['tipo_doc'] . ' ' . $c['nro_documento'],
                $pdf->formatNumber($c['num_compras']),
                $pdf->formatMoney($c['total_gastado']),
                $pdf->formatMoney($c['ticket_promedio']),
                $pdf->formatDate($c['ultima_compra']),
            ];
            $totalGastado += floatval($c['total_gastado']);
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeTotals([
            'Total Clientes:' => $pdf->formatNumber(count($data['clientes'])),
            'Ingresos por Clientes:' => $pdf->formatMoney($totalGastado),
        ]);
    }

    private function generarPdfVendedores($pdf, array $input): void {
        $data = $this->service->reporteVendedores($input);

        $pdf->AddPage();
        $pdf->writeTitle('VENTAS POR VENDEDOR');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeKPIRow([
            ['label' => 'Total Vendedores', 'value' => $pdf->formatNumber(count($data['vendedores']))],
            ['label' => 'Ingresos Totales', 'value' => $pdf->formatMoney($data['totalIngresos'] ?? 0), 'color' => 'gold'],
        ]);

        $pdf->writeSubtitle('RENDIMIENTO DE VENDEDORES');
        $headers = ['Vendedor', '# Ventas', 'Ingreso Total', 'Ticket Promedio', '% del Total'];
        $widths = [74, 24, 32, 32, 18];
        $aligns = ['L', 'C', 'R', 'R', 'C'];

        $rows = [];
        foreach ($data['vendedores'] as $v) {
            $rows[] = [
                mb_substr($v['vendedor'], 0, 49),
                $pdf->formatNumber($v['num_ventas']),
                $pdf->formatMoney($v['ingreso_total']),
                $pdf->formatMoney($v['ticket_promedio']),
                $pdf->formatPercent($v['porcentaje']),
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeTotals([
            'Total Vendedores:' => $pdf->formatNumber(count($data['vendedores'])),
            'Ingresos Totales:' => $pdf->formatMoney($data['totalIngresos'] ?? 0),
        ]);
    }

    private function generarPdfCategorias($pdf, array $input): void {
        $data = $this->service->reporteCategorias($input);

        $pdf->AddPage();
        $pdf->writeTitle('VENTAS POR CATEGORIA');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeKPIRow([
            ['label' => 'Total Categorias', 'value' => $pdf->formatNumber(count($data['categorias']))],
            ['label' => 'Ingresos Totales', 'value' => $pdf->formatMoney($data['totalIngresos'] ?? 0), 'color' => 'gold'],
            ['label' => 'Unidades Totales', 'value' => $pdf->formatNumber($data['totalUnidades'] ?? 0)],
        ]);

        $pdf->writeSubtitle('RENDIMIENTO POR CATEGORIA');
        $headers = ['Categoria', 'Unidades', 'Ingresos', '% del Total'];
        $widths = [62, 37, 41, 40];
        $aligns = ['L', 'C', 'R', 'C'];

        $rows = [];
        foreach ($data['categorias'] as $c) {
            $rows[] = [
                $c['categoria'],
                $pdf->formatNumber($c['unidades']),
                $pdf->formatMoney($c['ingresos']),
                $pdf->formatPercent($c['porcentaje']),
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeTotals([
            'Total Categorias:' => $pdf->formatNumber(count($data['categorias'])),
            'Ingresos Totales:' => $pdf->formatMoney($data['totalIngresos'] ?? 0),
            'Unidades Totales:' => $pdf->formatNumber($data['totalUnidades'] ?? 0),
        ]);
    }

    private function generarPdfComprobantes($pdf, array $input): void {
        $data = $this->service->reporteComprobantes($input);

        $pdf->AddPage();
        $pdf->writeTitle('REPORTE DE COMPROBANTES');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeKPIRow([
            ['label' => 'Boletas Emitidas', 'value' => $pdf->formatNumber($data['totalBoletas'] ?? 0)],
            ['label' => 'Monto Boletas', 'value' => $pdf->formatMoney($data['montoBoletas'] ?? 0)],
            ['label' => 'Facturas Emitidas', 'value' => $pdf->formatNumber($data['totalFacturas'] ?? 0)],
            ['label' => 'IGV Facturas', 'value' => $pdf->formatMoney($data['igvFacturas'] ?? 0), 'color' => 'gold'],
        ]);

        $pdf->writeSubtitle('DETALLE DE COMPROBANTES');
        $headers = ['Comprobante', 'Serie', 'Cantidad', 'Total'];
        $widths = [50, 40, 40, 50];
        $aligns = ['L', 'C', 'C', 'R'];

        $rows = [];
        foreach ($data['comprobantes'] as $c) {
            $rows[] = [
                $c['comprobante'],
                $c['serie'],
                $pdf->formatNumber($c['cantidad']),
                $pdf->formatMoney($c['total']),
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $montoTotal = floatval($data['montoBoletas'] ?? 0) + floatval($data['montoFacturas'] ?? 0);
        $pdf->writeTotals([
            'Total Comprobantes:' => $pdf->formatNumber(count($data['comprobantes'])),
            'Monto Total:' => $pdf->formatMoney($montoTotal),
        ]);
    }

    private function generarPdfMetodosPago($pdf, array $input): void {
        $data = $this->service->reporteMetodosPago($input);

        $pdf->AddPage();
        $pdf->writeTitle('METODOS DE PAGO');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeKPIRow([
            ['label' => 'Total Metodos', 'value' => $pdf->formatNumber(count($data['metodos']))],
            ['label' => 'Total Transacciones', 'value' => $pdf->formatNumber($data['totalTransacciones'] ?? 0)],
            ['label' => 'Total Recaudado', 'value' => $pdf->formatMoney($data['totalMontos'] ?? 0), 'color' => 'gold'],
        ]);

        $pdf->writeSubtitle('ANALISIS DE METODOS DE PAGO');
        $headers = ['Metodo de Pago', '# Transacciones', 'Monto Total', '% del Total'];
        $widths = [56, 37, 47, 40];
        $aligns = ['L', 'C', 'R', 'C'];

        $rows = [];
        foreach ($data['metodos'] as $m) {
            $rows[] = [
                $m['metodo_pago'],
                $pdf->formatNumber($m['num_transacciones']),
                $pdf->formatMoney($m['monto_total']),
                $pdf->formatPercent($m['porcentaje']),
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeTotals([
            'Total Metodos:' => $pdf->formatNumber(count($data['metodos'])),
            'Total Transacciones:' => $pdf->formatNumber($data['totalTransacciones'] ?? 0),
            'Total Recaudado:' => $pdf->formatMoney($data['totalMontos'] ?? 0),
        ]);
    }

    private function generarPdfResumen($pdf, array $input): void {
        $data = $this->service->reporteResumen($input);
        $ventas = $data['ventas'] ?? [];
        $resumen = $ventas['resumen'] ?? [];
        $inventario = $data['inventario'] ?? [];
        $invResumen = $inventario['resumen'] ?? [];

        $pdf->AddPage();
        $pdf->writeTitle('RESUMEN EJECUTIVO');
        $pdf->writeMeta('Periodo:', $this->getPeriodo($input));
        $pdf->writeMeta('Generado por:', $_SESSION['nombre'] ?? 'Sistema');
        $pdf->writeSeparator();

        $pdf->writeKPIRow([
            ['label' => 'Ingresos Totales', 'value' => $pdf->formatMoney($resumen['total_ingresos'] ?? 0), 'color' => 'gold'],
            ['label' => 'Total Ventas', 'value' => $pdf->formatNumber($resumen['total_ventas'] ?? 0)],
            ['label' => 'Ticket Promedio', 'value' => $pdf->formatMoney($resumen['ticket_promedio'] ?? 0)],
            ['label' => 'Valor Inventario', 'value' => $pdf->formatMoney($invResumen['valor_total'] ?? 0)],
        ]);

        $pdf->writeSubtitle('TOP 5 PRODUCTOS MAS VENDIDOS');
        $headers = ['Producto', 'Ingresos'];
        $widths = [120, 60];
        $aligns = ['L', 'R'];
        $rows = [];
        foreach (($data['masVendidos']['productos'] ?? []) as $p) {
            $rows[] = [
                $p['nombre'],
                $pdf->formatMoney($p['ingresos']),
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeSubtitle('TOP 3 CLIENTES');
        $headers = ['Cliente', 'Total Gastado'];
        $widths = [120, 60];
        $aligns = ['L', 'R'];
        $rows = [];
        foreach (($data['topClientes']['clientes'] ?? []) as $c) {
            $rows[] = [
                $c['nombre'],
                $pdf->formatMoney($c['total_gastado']),
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeSubtitle('RENDIMIENTO VENDEDORES');
        $headers = ['Vendedor', '# Ventas', 'Ingresos', '%'];
        $widths = [70, 30, 50, 30];
        $aligns = ['L', 'C', 'R', 'C'];
        $rows = [];
        foreach (($data['vendedores']['vendedores'] ?? []) as $v) {
            $rows[] = [
                $v['vendedor'],
                $pdf->formatNumber($v['num_ventas']),
                $pdf->formatMoney($v['ingreso_total']),
                $v['porcentaje'] . '%',
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $pdf->writeSubtitle('METODOS DE PAGO');
        $headers = ['Metodo', '# Transacciones', 'Monto', '%'];
        $widths = [60, 40, 50, 30];
        $aligns = ['L', 'C', 'R', 'C'];
        $rows = [];
        foreach (($data['metodos']['metodos'] ?? []) as $m) {
            $rows[] = [
                $m['metodo_pago'],
                $pdf->formatNumber($m['num_transacciones']),
                $pdf->formatMoney($m['monto_total']),
                $m['porcentaje'] . '%',
            ];
        }
        $pdf->writeTable($headers, $rows, $widths, $aligns);

        $stockBajo = intval($invResumen['productos_stock_bajo'] ?? 0);
        if ($stockBajo > 0) {
            $pdf->writeSubtitle('ALERTAS DE STOCK BAJO (' . $stockBajo . ' productos)');
            foreach (($inventario['productos'] ?? []) as $p) {
                if ($p['stock_bajo']) {
                    $pdf->writeMeta($p['nombre'] . ':', $pdf->formatNumber($p['stock_actual']) . ' unidades');
                }
            }
        }
    }
}
