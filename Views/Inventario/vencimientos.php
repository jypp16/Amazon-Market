<?php require_once "Views/Header.php"; ?>

<section class="expiration-alerts" aria-labelledby="expiration-alerts-title">
    <div class="expiration-alerts-card">
        <header class="expiration-alerts-heading">
            <h1 id="expiration-alerts-title" class="expiration-alerts-icon-title">
                <i class="fa-regular fa-clock" aria-hidden="true"></i>
                <span>Pendientes de revisión</span>
            </h1>
            <span id="expiration-alerts-count" class="expiration-alerts-count" aria-live="polite">Cargando avisos...</span>
        </header>

        <aside class="expiration-alerts-info" role="note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <p>Revisa físicamente cada fecha. Confirmar existencias no modifica el inventario; si hay unidades vencidas, regístralas como baja en Ajustes de inventario.</p>
        </aside>

        <div id="expiration-alerts-status" class="expiration-alerts-status" aria-live="polite"></div>

        <div id="expiration-alerts-table-state" class="table-responsive expiration-alerts-table">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Categoría / Subcategoría</th>
                        <th scope="col">Producto</th>
                        <th scope="col">Vencimiento (dd/mm/aaaa)</th>
                        <th scope="col">Condición</th>
                        <th scope="col">Lote</th>
                        <th scope="col">Acción</th>
                    </tr>
                </thead>
                <tbody id="expiration-alerts-tbody">
                    <tr>
                        <td colspan="6">
                            <div class="expiration-alerts-skeleton" aria-label="Cargando avisos">
                                <span></span><span></span><span></span><span></span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="expiration-alerts-footer">Los avisos no muestran stock ni cantidades compradas. Cada fecha se revisa por separado.</p>
    </div>

    <dialog id="expiration-review-dialog" class="expiration-review-dialog" aria-labelledby="expiration-review-title">
        <div class="expiration-review-header">
            <button type="button" id="expiration-review-close" class="expiration-review-close" aria-label="Cerrar">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
        <div class="expiration-review-icon" aria-hidden="true">
            <i class="fa-regular fa-clock"></i>
        </div>
        <h2 id="expiration-review-title">Confirmar existencias</h2>
        <p id="expiration-review-question" class="expiration-review-question"></p>
        <p class="expiration-review-note">«Sí, quedan» mantiene el aviso activo. «No quedan» lo cierra. Con la «X» se cierra sin guardar ninguna revisión.</p>
        <div id="expiration-review-error" class="expiration-review-error" role="alert" hidden></div>
        <div class="expiration-review-actions">
            <button type="button" id="expiration-review-yes" class="expiration-review-button expiration-review-yes">Sí, quedan</button>
            <button type="button" id="expiration-review-no" class="expiration-review-button expiration-review-no">No quedan</button>
        </div>
        <button type="button" id="expiration-review-retry" class="expiration-review-retry" hidden>
            <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reintentar
        </button>
    </dialog>
</section>

<?php require_once "Views/Footer.php"; ?>