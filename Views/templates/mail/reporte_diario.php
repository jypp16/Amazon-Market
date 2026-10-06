<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden;">
        <div style="background: #1e293b; color: #d4af37; padding: 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 20px;">Reporte Diario de Ventas</h1>
        </div>
        <div style="padding: 30px;">
            <h2 style="color: #1e293b; margin-top: 0;">Resumen del Día</h2>
            <p>Hola <strong><?= htmlspecialchars($nombre) ?></strong>,</p>
            <p>Este es el resumen de ventas del día <strong><?= htmlspecialchars($fecha) ?></strong>:</p>
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr style="background: #f8f9fa;">
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><strong>Total de Ventas:</strong></td>
                    <td style="padding: 12px; border: 1px solid #dee2e6; font-weight: bold;"><?= htmlspecialchars($totalVentas) ?></td>
                </tr>
                <tr>
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><strong>Ingresos Totales:</strong></td>
                    <td style="padding: 12px; border: 1px solid #dee2e6; color: #16a34a; font-weight: bold;">S/. <?= htmlspecialchars($totalIngresos) ?></td>
                </tr>
                <tr style="background: #f8f9fa;">
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><strong>Producto Más Vendido:</strong></td>
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><?= htmlspecialchars($productoTop) ?></td>
                </tr>
            </table>
            <p>Saludos,<br><strong>Equipo AmazonMarket</strong></p>
        </div>
    </div>
</body>
</html>
