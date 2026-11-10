<?php require_once "Views/Header.php"; ?>

<?php
$diferenciaAperturaInicial = (float)$data['efectivo_contado'] - (float)$data['referencia_anterior'];
$diferenciaAperturaInicialTexto = ($diferenciaAperturaInicial < 0 ? '−' : ($diferenciaAperturaInicial > 0 ? '+' : ''))
    . 'S/ ' . number_format(abs($diferenciaAperturaInicial), 2, ',', '');
$estadoAperturaInicial = $diferenciaAperturaInicial < 0 ? 'shortage' : ($diferenciaAperturaInicial > 0 ? 'surplus' : 'balanced');
$etiquetaAperturaInicial = $diferenciaAperturaInicial < 0 ? 'Faltante' : ($diferenciaAperturaInicial > 0 ? 'Sobrante' : 'Sin diferencia');
$notaAperturaInicial = $diferenciaAperturaInicial < 0
    ? 'Se registra como faltante de apertura. No genera un egreso.'
    : ($diferenciaAperturaInicial > 0
        ? 'Se registra como sobrante de apertura. No modifica el cierre anterior.'
        : 'El efectivo contado coincide con el cierre anterior.');
?>

<section class="cash-opening">
    <header class="cash-opening-heading">
        <h2>Abrir caja</h2>
        <p>Cuenta el efectivo que recibes antes de empezar a vender.</p>
    </header>

    <div class="cash-opening-grid">
        <form class="cash-opening-card" id="cash_opening_form"
            data-reference="<?= e(number_format((float)$data['referencia_anterior'], 2, '.', '')) ?>">
            <h3>Confirma el efectivo que recibes</h3>

            <section class="cash-previous-reference" aria-label="Referencia del cierre anterior">
                <div>
                    <span class="cash-reference-label">Referencia del cierre anterior</span>
                    <strong><?= e('S/ ' . number_format((float)$data['referencia_anterior'], 2, ',', '')) ?></strong>
                    <small>Contado al cerrar <?= e($data['fecha_cierre_anterior']) ?> · turno <?= e($data['turno_anterior']) ?></small>
                </div>
                <p>Es solo una referencia. Tu saldo inicial es el monto que cuentes y confirmes.</p>
            </section>

            <div class="cash-opening-field">
                <label for="cash_counted">Efectivo contado (S/)</label>
                <input type="number" id="cash_counted" name="efectivo_contado"
                    min="0" step="0.01" inputmode="decimal"
                    value="<?= e(number_format((float)$data['efectivo_contado'], 2, '.', '')) ?>"
                    aria-describedby="cash_counted_help">
                <small id="cash_counted_help">Cuenta el dinero físico. Desde 0,00 y con hasta dos decimales.</small>
            </div>

            <section class="cash-opening-difference is-<?= e($estadoAperturaInicial) ?>" id="cash_difference"
                aria-live="polite" aria-atomic="true">
                <div class="cash-difference-amount">
                    <span>Diferencia de apertura prevista</span>
                    <strong id="cash_difference_amount"><?= e($diferenciaAperturaInicialTexto) ?></strong>
                </div>
                <div class="cash-difference-explanation">
                    <span class="cash-difference-badge" id="cash_difference_label"><?= e($etiquetaAperturaInicial) ?></span>
                    <p id="cash_difference_note"><?= e($notaAperturaInicial) ?></p>
                </div>
            </section>

            <p class="cash-opening-feedback" id="cash_opening_feedback" role="status" tabindex="-1" hidden></p>

            <div class="cash-opening-actions">
                <a class="btn btn-secondary" href="<?= BASE_URL ?>/Home">Cancelar</a>
                <button class="btn btn-cash-primary" type="submit" id="cash_opening_confirm">
                    Confirmar apertura
                </button>
            </div>
        </form>

        <aside class="cash-opening-card cash-opening-summary" aria-labelledby="cash_summary_title">
            <h3 id="cash_summary_title">Resumen de la apertura</h3>
            <dl>
                <div><dt>Vendedor</dt><dd><?= e($_SESSION['nombre'] ?? 'Operador') ?></dd></div>
                <div><dt>Turno</dt><dd><?= e($data['turno_actual']) ?></dd></div>
                <div>
                    <dt>Horario</dt>
                    <dd><span class="cash-schedule-badge" id="cash_schedule_status">Dentro del horario</span></dd>
                </div>
                <div>
                    <dt>Referencia anterior</dt>
                    <dd><?= e('S/ ' . number_format((float)$data['referencia_anterior'], 2, ',', '')) ?></dd>
                </div>
                <div><dt>Efectivo contado</dt><dd id="cash_summary_counted"><?= e('S/ ' . number_format((float)$data['efectivo_contado'], 2, ',', '')) ?></dd></div>
                <div class="cash-summary-difference is-<?= e($estadoAperturaInicial) ?>">
                    <dt>Diferencia de apertura</dt>
                    <dd id="cash_summary_difference"><?= e($diferenciaAperturaInicialTexto) ?></dd>
                </div>
                <div class="cash-summary-balance">
                    <dt>Saldo inicial</dt>
                    <dd id="cash_summary_balance"><?= e('S/ ' . number_format((float)$data['efectivo_contado'], 2, ',', '')) ?></dd>
                </div>
            </dl>
            <p>La fecha y la hora reales de apertura se registran al confirmar. Un reenvío recupera la misma apertura.</p>
        </aside>
    </div>
</section>

<?php
$auth_warning = '';
$auth_reason_placeholder = 'Explica por qué se abre fuera de horario';
$auth_submit_label = 'Autorizar y abrir';
$auth_help_text = 'Obligatorio. La autorización vale una sola vez, solo para esta apertura y este vendedor.';
require 'Views/Caja/_autorizacion.php';
?>

<script>
window.CAJA_SERVICE_CONFIG = <?= json_encode([
    'mockEnabled' => (bool)($data['caja_mock_enabled'] ?? false),
    'mockScenario' => (string)($data['caja_mock_scenario'] ?? 'authorization_required'),
    'referenceAmount' => (float)($data['caja_mock_reference'] ?? 0)
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
