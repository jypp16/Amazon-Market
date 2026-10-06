<?php

namespace Services;

use Libraries\Mailer\Mailer;

class MailService {

    private Mailer $mailer;

    public function __construct() {
        $this->mailer = new Mailer();
    }

    public function enviarConfirmacionVenta(string $email, string $nombreCliente, array $venta): bool {
        $this->mailer->reset();
        $this->mailer->to($email, $nombreCliente);
        $this->mailer->subject('Confirmación de Venta - AmazonMarket');

        $html = $this->getTemplate('confirmacion_venta', [
            'nombre' => $nombreCliente,
            'serie' => $venta['serie'] ?? '',
            'numero' => $venta['numero'] ?? '',
            'total' => $venta['total'] ?? '0.00',
            'fecha' => date('d/m/Y H:i'),
        ]);

        $this->mailer->html($html);
        return $this->mailer->send();
    }

    public function enviarAlertaStockBajo(string $email, string $nombreVendedor, array $productos): bool {
        $this->mailer->reset();
        $this->mailer->to($email, $nombreVendedor);
        $this->mailer->subject('Alerta de Stock Bajo - AmazonMarket');

        $listaHtml = '';
        foreach ($productos as $p) {
            $listaHtml .= "<li>" . htmlspecialchars($p['nombre']) . " - Stock actual: " . intval($p['stock_actual']) . "</li>";
        }

        $html = $this->getTemplate('alerta_stock', [
            'nombre' => $nombreVendedor,
            'productos' => $listaHtml,
            'fecha' => date('d/m/Y H:i'),
        ]);

        $this->mailer->html($html);
        return $this->mailer->send();
    }

    public function enviarReporteDiario(string $email, string $nombreVendedor, array $resumen): bool {
        $this->mailer->reset();
        $this->mailer->to($email, $nombreVendedor);
        $this->mailer->subject('Reporte Diario de Ventas - AmazonMarket');

        $html = $this->getTemplate('reporte_diario', [
            'nombre' => $nombreVendedor,
            'totalVentas' => $resumen['total_ventas'] ?? 0,
            'totalIngresos' => $resumen['total_ingresos'] ?? '0.00',
            'productoTop' => $resumen['producto_top'] ?? 'N/A',
            'fecha' => date('d/m/Y'),
        ]);

        $this->mailer->html($html);
        return $this->mailer->send();
    }

    public function enviarReporteVentas(string $email, string $nombreVendedor, array $input = []): bool {
        $reporteService = new ReporteService();
        $data = $reporteService->reporteResumen($input);
        $ventas = $data['ventas'] ?? [];
        $resumen = $ventas['resumen'] ?? [];
        $masVendidos = $data['masVendidos']['productos'] ?? [];
        $productoTop = !empty($masVendidos) ? $masVendidos[0]['nombre'] : 'Sin datos';

        return $this->enviarReporteDiario($email, $nombreVendedor, [
            'total_ventas' => $resumen['total_ventas'] ?? 0,
            'total_ingresos' => number_format($resumen['total_ingresos'] ?? 0, 2),
            'producto_top' => $productoTop,
        ]);
    }

    public function enviarReportePDF(string $email, string $asunto, string $pdfContent, string $pdfFilename, string $nombreReporte = ''): bool {
        $this->mailer->reset();
        $this->mailer->to($email);
        $this->mailer->subject($asunto);

        $html = $this->getTemplate('reporte_email', [
            'nombreReporte' => $nombreReporte,
            'fecha' => date('d/m/Y H:i'),
            'generadoPor' => $_SESSION['nombre'] ?? 'Sistema',
        ]);

        $this->mailer->html($html);
        $this->mailer->stringAttach($pdfContent, $pdfFilename);
        return $this->mailer->send();
    }

    public function enviar(string $email, string $asunto, string $mensajeHtml): bool {
        $this->mailer->reset();
        $this->mailer->to($email);
        $this->mailer->subject($asunto);
        $this->mailer->html($mensajeHtml);
        return $this->mailer->send();
    }

    public function getLastError(): string {
        return $this->mailer->getErrorInfo();
    }

    private function getTemplate(string $template, array $data): string {
        $templateDir = __DIR__ . '/../Views/templates/mail/';
        $templateFile = $templateDir . $template . '.php';

        if (!file_exists($templateFile)) {
            return $this->getDefaultTemplate($data);
        }

        extract($data);
        ob_start();
        include $templateFile;
        return ob_get_clean();
    }

    private function getDefaultTemplate(array $data): string {
        $nombre = $data['nombre'] ?? 'Usuario';
        $fecha = $data['fecha'] ?? date('d/m/Y H:i');

        return "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden;'>
                <div style='background: #d4af37; color: #1e293b; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0; font-size: 20px;'>AmazonMarket</h1>
                </div>
                <div style='padding: 30px;'>
                    <p>Hola <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
                    <p>Fecha: " . htmlspecialchars($fecha) . "</p>
                    <p>Saludos,<br>Equipo AmazonMarket</p>
                </div>
            </div>
        </body>
        </html>";
    }
}