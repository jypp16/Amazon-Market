<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden;">
        <div style="background: #d4af37; color: #1e293b; padding: 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 20px;">AmazonMarket</h1>
        </div>
        <div style="padding: 30px;">
            <h2 style="color: #1e293b; margin-top: 0;">Confirmación de Venta</h2>
            <p>Hola <strong><?= htmlspecialchars($nombre) ?></strong>,</p>
            <p>Tu venta ha sido registrada exitosamente:</p>
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr style="background: #f8f9fa;">
                    <td style="padding: 10px; border: 1px solid #dee2e6;"><strong>Serie:</strong></td>
                    <td style="padding: 10px; border: 1px solid #dee2e6;"><?= htmlspecialchars($serie) ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #dee2e6;"><strong>Número:</strong></td>
                    <td style="padding: 10px; border: 1px solid #dee2e6;"><?= htmlspecialchars($numero) ?></td>
                </tr>
                <tr style="background: #f8f9fa;">
                    <td style="padding: 10px; border: 1px solid #dee2e6;"><strong>Total:</strong></td>
                    <td style="padding: 10px; border: 1px solid #dee2e6; color: #16a34a; font-weight: bold;">S/. <?= htmlspecialchars($total) ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #dee2e6;"><strong>Fecha:</strong></td>
                    <td style="padding: 10px; border: 1px solid #dee2e6;"><?= htmlspecialchars($fecha) ?></td>
                </tr>
            </table>
            <p style="color: #6b7280; font-size: 12px;">Gracias por tu compra.</p>
            <p>Saludos,<br><strong>Equipo AmazonMarket</strong></p>
        </div>
    </div>
</body>
</html>
