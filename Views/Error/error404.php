<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Página no encontrada</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #001f3f 0%, #003366 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: #fff;
        }
        .error-container {
            text-align: center;
            max-width: 500px;
            padding: 60px 40px;
        }
        .error-code {
            font-size: 120px;
            font-weight: 700;
            color: #D4AF37;
            line-height: 1;
            text-shadow: 0 0 40px rgba(212, 175, 55, 0.3);
        }
        .error-icon {
            font-size: 60px;
            color: rgba(255,255,255,0.1);
            margin-bottom: 10px;
        }
        .error-title {
            font-size: 24px;
            font-weight: 600;
            margin: 15px 0 10px;
            color: #fff;
        }
        .error-message {
            font-size: 15px;
            color: rgba(255,255,255,0.6);
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #D4AF37, #f0d060);
            color: #001f3f;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
        }
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(212, 175, 55, 0.5);
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            text-decoration: none;
            margin-left: 10px;
            transition: all 0.3s;
        }
        .btn-back:hover {
            background: rgba(255,255,255,0.2);
        }
        .footer-text {
            margin-top: 40px;
            font-size: 12px;
            color: rgba(255,255,255,0.3);
        }
        .footer-text span { color: #D4AF37; }
    </style>
</head>
<body>
    <div class="error-container">
        <i class="fa-solid fa-triangle-exclamation error-icon"></i>
        <div class="error-code">404</div>
        <h1 class="error-title">Página no encontrada</h1>
        <p class="error-message">La página que estás buscando no existe o ha sido movida.<br>Verifica la URL o vuelve al inicio.</p>
        <a href="<?= defined('BASE_URL') ? BASE_URL : '/AM4/AmazonMarket' ?>" class="btn-home"><i class="fa-solid fa-house"></i> Ir al inicio</a>
        <a href="javascript:history.go(-1)" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Volver atrás</a>
        <div class="footer-text">&copy; 2026 <span>Amazon</span> Market</div>
    </div>
</body>
</html>
