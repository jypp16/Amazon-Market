<?php require_once "Views/Header.php"; ?>

<section id="mov-detail" class="mov-page" data-id="<?= (int)($data['id_movimiento'] ?? 0) ?>">
    <a id="mov-back" class="mov-back" href="<?= BASE_URL ?>/Inventario/movimientos">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>
    <div id="mov-status" class="mov-status" aria-live="polite" hidden></div>
    <div id="mov-content" hidden></div>
</section>

<?php require_once "Views/Footer.php"; ?>