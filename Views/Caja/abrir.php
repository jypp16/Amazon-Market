<?php require_once "Views/Header.php"; ?>

<section class="cash-opening">
    <header class="cash-opening-heading">
        <p>Cuenta el efectivo que recibes antes de empezar a vender.</p>
    </header>

    <div class="cash-opening-grid">
        <form class="cash-opening-card" id="cash_opening_form"
            data-reference="0">
            <h3>Confirma el efectivo que recibes</h3>

            <section class="cash-previous-reference" aria-label="Referencia del cierre anterior">
                <div>
                    <span class="cash-reference-label">Referencia del cierre anterior</span>
                    <strong id="cash_reference_amount">Cargando…</strong>
                    <small id="cash_reference_meta">Contado al cerrar <span id="cash_reference_date">—</span> · turno <span id="cash_reference_turn">—</span></small>
                </div>
                <p>Es solo una referencia. Tu saldo inicial es el monto que cuentes y confirmes.</p>
            </section>

            <div class="cash-opening-field">
                <label for="cash_shift_select">Turno atendido</label>
                <select id="cash_shift_select" name="shift_id" aria-describedby="cash_shift_help">
                    <option value="">Cargando turnos…</option>
                </select>
                <small id="cash_shift_help">Puedes abrir tu turno programado o elegir otro turno activo en caso de suplencia o apertura sin programación.</small>
            </div>

            <div class="cash-opening-field">
                <label for="cash_counted">Efectivo contado (S/)</label>
                <input type="text" id="cash_counted" name="efectivo_contado"
                    inputmode="decimal" autocomplete="off" placeholder="0,00"
                    aria-describedby="cash_counted_help cash_counted_error">
                <small id="cash_counted_help">Cuenta el dinero físico. Desde 0,00 y con hasta dos decimales.</small>
                <span class="cash-count-error" id="cash_counted_error" aria-live="polite"></span>
            </div>

            <section class="cash-opening-difference is-empty" id="cash_difference"
                aria-live="polite" aria-atomic="true">
                <div class="cash-difference-amount">
                    <span>Diferencia de apertura prevista</span>
                    <strong id="cash_difference_amount">—</strong>
                </div>
                <div class="cash-difference-explanation">
                    <span class="cash-difference-badge" id="cash_difference_label">Pendiente</span>
                    <p id="cash_difference_note">Ingresa el efectivo contado para ver la diferencia.</p>
                </div>
            </section>

            <p class="cash-opening-feedback" id="cash_opening_feedback" role="status" tabindex="-1" hidden></p>
            <section class="cash-opening-no-shift" id="cash_opening_schedule_notice" role="status" hidden></section>
        </form>

        <div class="cash-opening-side">
            <aside class="cash-opening-card cash-opening-summary" aria-labelledby="cash_summary_title">
                <h3 id="cash_summary_title">Resumen de la apertura</h3>
                <dl>
                    <div><dt>Vendedor</dt><dd><?= e($_SESSION['nombre'] ?? 'Operador') ?></dd></div>
                    <div><dt>Turno</dt><dd id="cash_summary_turn">Cargando…</dd></div>
                    <div>
                        <dt>Horario</dt>
                        <dd><span class="cash-schedule-badge" id="cash_schedule_status">Cargando…</span></dd>
                    </div>
                    <div><dt>Referencia anterior</dt><dd id="cash_summary_reference">—</dd></div>
                    <div><dt>Efectivo contado</dt><dd id="cash_summary_counted">—</dd></div>
                    <div class="cash-summary-difference">
                        <dt>Diferencia de apertura</dt>
                        <dd id="cash_summary_difference">—</dd>
                    </div>
                    <div class="cash-summary-balance">
                        <dt>Saldo inicial</dt>
                        <dd id="cash_summary_balance">—</dd>
                    </div>
                </dl>
                <p>La fecha y la hora reales de apertura se registran al confirmar. Un reenvío recupera la misma apertura.</p>
            </aside>

            <section class="cash-opening-card cash-opening-already-open" id="cash_opening_already_open" role="status" hidden>
                <h3 id="cash_opening_occupied_title">Ya tienes una caja abierta</h3>
                <p id="cash_opening_occupied_message">No puedes abrir otra sesión mientras esta caja siga abierta.</p>
                <a class="btn btn-cash-primary" id="cash_opening_occupied_link" href="<?= BASE_URL ?>/Caja/movimientos">Ir a Movimientos</a>
            </section>
        </div>
    </div>
    <div class="cash-opening-actions">
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/Home">Cancelar</a>
        <button class="btn btn-cash-primary" type="submit" id="cash_opening_confirm" form="cash_opening_form">
            Confirmar apertura
        </button>
    </div>
</section>

<script>
window.CAJA_SERVICE_CONFIG = <?= json_encode([
    'sellerId' => (int)($_SESSION['idUser'] ?? 0),
    'sellerName' => $_SESSION['nombre'] ?? 'Vendedor',
    'sellerUsername' => $_SESSION['username'] ?? ''
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
