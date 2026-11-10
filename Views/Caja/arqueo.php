<?php
require_once 'Views/Header.php';
$auth_warning = 'Estás cerrando caja fuera del horario de tu turno. Un administrador debe autorizar este cierre.';
$auth_reason_placeholder = 'Explica por qué solicitas cerrar caja fuera de horario';
$auth_submit_label = 'Autorizar y cerrar';
$auth_help_text = 'Obligatorio. La autorización vale una sola vez, solo para este cierre y este vendedor.';
?>
<section class="cash-count">
    <header class="cash-count-heading">
        <h2>Arqueo y cierre</h2>
        <p>Compara el efectivo contado con el esperado y cierra tu sesión.</p>
    </header>

    <div class="cash-count-loading" id="cash_arqueo_loading" role="status">Cargando el estado del arqueo…</div>
    <section class="cash-movements-notice" id="cash_arqueo_no_session" role="status" hidden>
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <div>
            <h2>Sin sesión de caja abierta</h2>
            <p>Debes abrir una sesión de caja para realizar un arqueo o cierre.</p>
            <a class="cash-movements-open-link" href="<?= BASE_URL ?>/Caja/abrir">Abrir caja</a>
        </div>
    </section>
    <section class="cash-movements-load-error" id="cash_arqueo_load_error" role="alert" hidden>
        <p>No se pudo cargar el estado de caja. Inténtalo de nuevo.</p>
        <button class="btn btn-secondary" type="button" id="cash_arqueo_retry">Reintentar</button>
    </section>
    <div class="cash-count-workspace" id="cash_arqueo_workspace" hidden>
        <div class="cash-count-grid">
            <section class="cash-opening-card cash-count-card" aria-labelledby="cash_count_title">
                <h3 id="cash_count_title">Arqueo de caja</h3>
                <dl class="cash-count-breakdown">
                    <div><dt>Fondo inicial</dt><dd id="cash_count_opening">—</dd></div>
                    <div><dt>+ Ventas en efectivo</dt><dd id="cash_count_sales">—</dd></div>
                    <div><dt>+ Ingresos e inyecciones</dt><dd id="cash_count_income">—</dd></div>
                    <div><dt>− Egresos</dt><dd id="cash_count_expenses">—</dd></div>
                    <div class="cash-count-expected"><dt>Efectivo esperado</dt><dd id="cash_count_expected">—</dd></div>
                </dl>
                <p class="cash-count-payment-note" id="cash_count_payment_note">Tarjeta (—) y Yape/Plin (—) no cuentan en el efectivo esperado.</p>

                <form id="cash_count_form" novalidate>
                    <div class="cash-opening-field">
                        <label for="cash_count_amount">Efectivo contado (S/)</label>
                        <input type="text" id="cash_count_amount" name="counted_amount"
                            inputmode="decimal" autocomplete="off" placeholder="0,00"
                            aria-describedby="cash_count_amount_help cash_count_amount_error">
                        <small id="cash_count_amount_help">Desde 0,00 y con hasta dos decimales.</small>
                        <span class="cash-count-error" id="cash_count_amount_error" aria-live="polite"></span>
                    </div>

                    <section class="cash-opening-difference is-empty" id="cash_count_difference"
                        aria-live="polite" aria-atomic="true">
                        <div class="cash-difference-amount">
                            <span>Diferencia del arqueo</span>
                            <strong id="cash_count_difference_amount">—</strong>
                        </div>
                        <div class="cash-difference-explanation">
                            <span class="cash-difference-badge" id="cash_count_difference_label">Pendiente</span>
                            <p id="cash_count_difference_text">Ingresa el efectivo contado para ver la diferencia.</p>
                        </div>
                    </section>

                    <div class="cash-opening-field">
                        <label for="cash_count_observation">Observación</label>
                        <textarea id="cash_count_observation" name="observation" rows="3"
                            aria-describedby="cash_count_observation_help cash_count_observation_error"></textarea>
                        <small id="cash_count_observation_help">Obligatoria cuando la diferencia es distinta de cero.</small>
                        <span class="cash-count-error" id="cash_count_observation_error" aria-live="polite"></span>
                    </div>
                    <p class="cash-count-feedback" id="cash_count_feedback" role="status" aria-live="polite" hidden></p>
                    <div class="cash-opening-actions">
                        <a class="btn btn-secondary" href="<?= BASE_URL ?>/Home">Cancelar</a>
                        <button class="btn btn-cash-primary" type="submit" id="cash_count_save">Guardar arqueo</button>
                    </div>
                </form>
            </section>

            <div class="cash-count-side">
                <section class="cash-opening-card cash-count-card" aria-labelledby="cash_history_title">
                    <h3 id="cash_history_title">Historial de arqueos</h3>
                    <div class="cash-count-table-wrap">
                        <table class="cash-count-table">
                            <thead><tr><th scope="col">Hora</th><th scope="col">Contado</th><th scope="col">Diferencia</th><th scope="col">Estado</th></tr></thead>
                            <tbody id="cash_count_history"></tbody>
                        </table>
                    </div>
                    <p class="cash-count-history-note">Cada arqueo se conserva. Una venta o movimiento nuevo deja desactualizado el arqueo vigente.</p>
                </section>

                <section class="cash-opening-card cash-count-card cash-close-card" aria-labelledby="cash_close_title">
                    <h3 id="cash_close_title">Cerrar caja</h3>
                    <ul class="cash-close-checklist">
                        <li id="cash_close_ownership">Sesión abierta y propia</li>
                        <li id="cash_close_arqueo_status">Debes realizar un arqueo nuevo antes de cerrar.</li>
                    </ul>
                    <div class="cash-close-notice">Al cerrar se guarda el efectivo esperado, el contado y la diferencia, y se bloquean nuevas ventas y movimientos en esta sesión.</div>
                    <div class="cash-close-summary" id="cash_close_summary" hidden></div>
                    <p class="cash-count-feedback" id="cash_close_feedback" role="status" aria-live="polite" hidden></p>
                    <div class="cash-opening-actions">
                        <a class="btn btn-secondary" href="<?= BASE_URL ?>/Home">Cancelar</a>
                        <button class="btn btn-cash-primary" type="button" id="cash_close_confirm" disabled>Confirmar cierre</button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</section>

<?php require 'Views/Caja/_autorizacion.php'; ?>
<script>
window.CAJA_ARQUEO_CONFIG = <?= json_encode([
    'mockEnabled' => (bool)$data['mock_enabled'],
    'mockScenario' => (string)$data['mock_scenario'],
    'mockLatencyMs' => (int)$data['mock_latency_ms']
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<?php require_once 'Views/Footer.php'; ?>
