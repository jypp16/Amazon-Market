<?php
require_once 'Views/Header.php';
?>
<section class="cash-query">
    <header class="cash-query-heading">
        <h2>Consultar caja</h2>
        <p>Saldo y movimientos de la sesión. Solo lectura.</p>
    </header>

    <?php if (!empty($data['is_administrator'])): ?>
    <form class="cash-query-filters" id="cash_query_filters">
        <div class="cash-query-filter">
            <label for="cash_query_date">Fecha</label>
            <input type="date" id="cash_query_date" name="date"
                value="<?= e($data['default_date']) ?>" required>
        </div>
        <div class="cash-query-filter">
            <label for="cash_query_seller">Vendedor</label>
            <select id="cash_query_seller" name="seller_id" required>
                <option value="">Selecciona un vendedor</option>
            </select>
            <span class="cash-query-field-error" id="cash_query_seller_error" aria-live="polite"></span>
        </div>
        <div class="cash-query-filter-actions">
            <button class="btn btn-secondary" type="reset" id="cash_query_clear">Limpiar filtros</button>
            <button class="btn btn-cash-primary" type="submit" id="cash_query_submit">Consultar</button>
        </div>
    </form>
    <?php endif; ?>

    <section class="cash-query-message" id="cash_query_loading" role="status" hidden>
        <span class="cash-query-spinner" aria-hidden="true"></span>
        <span>Cargando información de caja…</span>
    </section>
    <section class="cash-query-message is-error" id="cash_query_error" role="alert" hidden>
        <p>No se pudo cargar la información. Inténtalo de nuevo.</p>
        <button class="btn btn-secondary" type="button" id="cash_query_retry">Reintentar</button>
    </section>
    <section class="cash-query-message is-denied" id="cash_query_denied" role="alert" hidden>
        <h3>Acceso denegado</h3>
        <p>No tienes permiso para consultar esta caja.</p>
    </section>
    <section class="cash-query-message is-empty" id="cash_query_no_session" role="status" hidden>
        <h3 id="cash_query_empty_title">No hay información de caja</h3>
        <p id="cash_query_empty_text">No hay aperturas para la fecha y el vendedor seleccionados.</p>
        <a class="cash-movements-open-link" id="cash_query_open_link" href="<?= BASE_URL ?>/Caja/abrir" hidden>Abrir caja</a>
    </section>

    <section class="cash-query-results" id="cash_query_results" aria-live="polite" hidden>
        <div class="cash-query-summary" aria-label="Resumen de la sesión">
            <article class="cash-query-summary-card">
                <span>Saldo inicial</span>
                <strong id="cash_query_opening">—</strong>
            </article>
            <article class="cash-query-summary-card is-positive">
                <span>Ventas en efectivo</span>
                <strong id="cash_query_sales">—</strong>
            </article>
            <article class="cash-query-summary-card is-positive">
                <span>Ingresos</span>
                <strong id="cash_query_income">—</strong>
            </article>
            <article class="cash-query-summary-card is-negative">
                <span>Egresos</span>
                <strong id="cash_query_expenses">—</strong>
            </article>
            <article class="cash-query-summary-card is-current">
                <span>Saldo actual</span>
                <strong id="cash_query_balance">—</strong>
            </article>
        </div>

        <p class="cash-query-other-tenders" id="cash_query_other_tenders"></p>

        <section class="cash-query-table-card" aria-labelledby="cash_query_table_title">
            <h3 class="cash-query-visually-hidden" id="cash_query_table_title">Movimientos de la sesión</h3>
            <div class="cash-query-table-wrap">
                <table class="cash-query-table" aria-labelledby="cash_query_table_title">
                    <thead>
                        <tr>
                            <th scope="col">Fecha y hora</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Motivo o referencia</th>
                            <th scope="col">Importe</th>
                            <th scope="col">Efecto en el saldo</th>
                            <th scope="col">Responsable</th>
                        </tr>
                    </thead>
                    <tbody id="cash_query_rows"></tbody>
                </table>
            </div>
            <div class="cash-query-empty-movements" id="cash_query_empty_movements" hidden>
                Aún no hay movimientos en esta sesión.
            </div>
            <div class="cash-query-pagination">
                <p id="cash_query_page_status" aria-live="polite"></p>
                <nav aria-label="Paginación de movimientos">
                    <button class="btn btn-secondary" id="cash_query_previous" type="button">Anterior</button>
                    <span class="cash-query-page-number" id="cash_query_page_number" aria-current="page">1</span>
                    <button class="btn btn-secondary" id="cash_query_next" type="button">Siguiente</button>
                </nav>
            </div>
        </section>
    </section>
</section>

<script>
window.CAJA_CONSULTA_CONFIG = <?= json_encode([
    'isAdministrator' => (bool)$data['is_administrator'],
    'mockEnabled' => (bool)$data['mock_enabled'],
    'mockScenario' => (string)$data['mock_scenario'],
    'mockLatencyMs' => (int)$data['mock_latency_ms'],
    'pageSize' => (int)$data['page_size'],
    'defaultDate' => (string)$data['default_date']
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<?php require_once 'Views/Footer.php'; ?>
