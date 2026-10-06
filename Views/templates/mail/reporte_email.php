<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden;">
        <div style="background: #1e293b; color: #d4af37; padding: 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 20px;">AmazonMarket</h1>
            <p style="margin: 5px 0 0; font-size: 13px; color: #94a3b8;">Sistema de Gestión de Ventas</p>
        </div>
        <div style="padding: 30px;">
            <h2 style="color: #1e293b; margin-top: 0;">Reporte: <?= htmlspecialchars($nombreReporte) ?></h2>
            <p style="color: #475569;">Se adjunta el reporte <strong><?= htmlspecialchars($nombreReporte) ?></strong> en formato PDF.</p>
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr style="background: #f8f9fa;">
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><strong>Tipo de Reporte:</strong></td>
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><?= htmlspecialchars($nombreReporte) ?></td>
                </tr>
                <tr>
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><strong>Fecha de Generación:</strong></td>
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><?= htmlspecialchars($fecha) ?></td>
                </tr>
                <tr style="background: #f8f9fa;">
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><strong>Generado por:</strong></td>
                    <td style="padding: 12px; border: 1px solid #dee2e6;"><?= htmlspecialchars($generadoPor) ?></td>
                </tr>
            </table>
            <p style="color: #64748b; font-size: 13px;">El documento PDF se encuentra adjunto a este correo.</p>
            <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">
            <p style="color: #94a3b8; font-size: 12px; text-align: center;">Este es un correo automático generado por AmazonMarket.</p>
        </div>
    </div>
</body>
</html>
