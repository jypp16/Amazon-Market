<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Amazon Market</title>
    <!-- FontAwesome para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?=BASE_URL?>/Assets/css/style.css">
    <link rel="stylesheet" href="<?=BASE_URL?>/Assets/css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h2><span class="gold-text">Amazon</span> <span class="blue-text">Market</span></h2>
            <p>Sistema de Ventas</p>
        </div>

        <?php if(!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger" style="background-color: #fef2f2; color: #ef4444; border: 1px solid #fecaca; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13.5px; font-weight: 600; text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <form action="<?=BASE_URL?>/Auth/login" method="POST">
            <?php csrf_field(); ?>
            <div class="input-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" placeholder="Ingresa tu usuario" required>
            </div>
            <div class="input-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" placeholder="Ingresa tu contraseña" required>
            </div>
            <button type="submit" class="login-btn">Iniciar Sesión</button>
        </form>
    </div>

    <script src="<?= BASE_URL ?>/Assets/js/modal.js"></script>
</body>
</html>
