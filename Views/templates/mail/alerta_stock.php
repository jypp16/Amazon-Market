<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden;">
        <div style="background: #dc2626; color: white; padding: 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 20px;">Alerta de Stock Bajo</h1>
        </div>
        <div style="padding: 30px;">
            <h2 style="color: #1e293b; margin-top: 0;">Productos con Stock Bajo</h2>
            <p>Hola <strong><?= htmlspecialchars($nombre) ?></strong>,</p>
            <p>Los siguientes productos tienen stock por debajo del mínimo:</p>
            <ul style="background: #fef2f2; padding: 20px 20px 20px 40px; border-radius: 8px; border-left: 4px solid #dc2626;">
                <?= $productos ?>
            </ul>
            <p style="color: #6b7280; font-size: 12px;">Fecha: <?= htmlspecialchars($fecha) ?></p>
            <p>Saludos,<br><strong>Equipo AmazonMarket</strong></p>
        </div>
    </div>
</body>
</html>
