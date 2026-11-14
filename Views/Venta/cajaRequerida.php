<?php require_once "Views/Header.php"; ?>
<section class="card" aria-labelledby="pos_cash_required_title">
    <div class="card-body">
        <h2 id="pos_cash_required_title">Debes abrir caja para realizar una venta</h2>
        <p>Abre tu caja antes de ingresar al punto de venta. Si otro vendedor está usando la caja, espera a que cierre su sesión.</p>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/Caja/abrir">Abrir caja</a>
    </div>
</section>
<?php require_once "Views/Footer.php"; ?>
