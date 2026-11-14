<?php require 'Views/Header.php'; ?>

<?php
$defaultFrom = htmlspecialchars((string)($data['default_from'] ?? date('Y-m-d', strtotime('-30 days'))), ENT_QUOTES, 'UTF-8');
$defaultTo = htmlspecialchars((string)($data['default_to'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8');
?>

<main class="close-page">
    <section class="close-header">
        <div>
            <span class="close-badge">Reportes administrativos</span>
            <h1>Cierres de caja</h1>
            <p class="close-subtitle">Consulta los cierres registrados, filtra por fecha, turno o vendedor y abre el detalle completo de cada cierre.</p>
        </div>
        <div class="close-header-actions">
            <a class="close-button close-button-secondary" href="<?= BASE_URL ?>/Reporte">Volver a reportes</a>
            <?php if ((int)($_SESSION['rol'] ?? 0) === 1 && \Libraries\Middleware\RBACMiddleware::tienePermisoAsignado(1, 'reportes.cierres_caja') && \Libraries\Middleware\RBACMiddleware::tienePermisoAsignado(1, 'reportes.exportar')): ?>
                <button id="closures_export_pdf" type="button" class="close-button close-button-pdf" disabled>Descargar PDF</button>
                <button id="closures_export_excel" type="button" class="close-button close-button-excel" disabled>Descargar Excel</button>
            <?php endif; ?>
        </div>
    </section>

    <div id="closures_feedback" class="close-alert" style="display: none;"></div>

    <section class="close-panel">
        <div class="close-section-head">
            <div>
                <h2>Filtros del reporte</h2>
                <p>Los totales reflejan todo el conjunto filtrado, independientemente de la página visible.</p>
            </div>
        </div>
        <form id="closures_filter_form" class="close-filter-grid" autocomplete="off">
            <div class="close-field">
                <label for="closures_from">Desde</label>
                <input id="closures_from" type="date" name="desde" value="<?= $defaultFrom ?>">
            </div>
            <div class="close-field">
                <label for="closures_to">Hasta</label>
                <input id="closures_to" type="date" name="hasta" value="<?= $defaultTo ?>">
            </div>
            <div class="close-field">
                <label for="closures_turn">Turno</label>
                <select id="closures_turn" name="turno_id">
                    <option value="">Todos los turnos</option>
                </select>
            </div>
            <div class="close-field">
                <label for="closures_seller">Vendedor</label>
                <select id="closures_seller" name="vendedor_id">
                    <option value="">Todos los vendedores</option>
                </select>
            </div>
            <div class="close-filter-actions">
                <button id="closures_apply_btn" type="submit" class="close-button close-button-primary">Consultar</button>
                <button id="closures_reset_btn" type="button" class="close-button close-button-secondary">Limpiar</button>
            </div>
        </form>
    </section>

    <section class="close-panel">
        <div class="close-summary-grid">
            <article class="close-metric">
                <span class="close-metric-label">Cierres encontrados</span>
                <strong id="closures_total_closures" class="close-metric-value">0</strong>
            </article>
            <article class="close-metric">
                <span class="close-metric-label">Total esperado</span>
                <strong id="closures_total_expected" class="close-metric-value">S/ 0.00</strong>
            </article>
            <article class="close-metric">
                <span class="close-metric-label">Total contado</span>
                <strong id="closures_total_counted" class="close-metric-value">S/ 0.00</strong>
            </article>
            <article class="close-metric">
                <span class="close-metric-label">Diferencia neta de cierre</span>
                <strong id="closures_total_diff" class="close-metric-value">S/ 0.00</strong>
            </article>
        </div>

        <div class="close-table-wrap">
            <table class="close-table">
                <thead>
                    <tr>
                        <th>Fecha y hora de cierre</th>
                        <th>Vendedor</th>
                        <th>Turno</th>
                        <th>Fondo inicial</th>
                        <th>Dif. apertura</th>
                        <th>Esperado</th>
                        <th>Contado</th>
                        <th>Dif. cierre</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody id="closures_tbody">
                    <tr>
                        <td colspan="9" class="close-empty">Cargando cierres de caja...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="close-pagination">
            <span id="closures_pagination_info">Mostrando 0 de 0</span>
            <div class="close-pagination-actions">
                <button id="closures_prev_btn" type="button" class="close-button close-button-secondary" disabled>Anterior</button>
                <button id="closures_next_btn" type="button" class="close-button close-button-secondary" disabled>Siguiente</button>
            </div>
        </div>
    </section>
</main>

<script>
    window.CAJA_REPORTES_CONFIG = {
        mode: 'list',
        baseUrl: <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>,
        defaultFrom: <?= json_encode($defaultFrom, JSON_UNESCAPED_SLASHES) ?>,
        defaultTo: <?= json_encode($defaultTo, JSON_UNESCAPED_SLASHES) ?>
    };
</script>
<script src="<?= BASE_URL ?>/Assets/js/reporteCierres.js"></script>

<?php require 'Views/Footer.php'; ?>
