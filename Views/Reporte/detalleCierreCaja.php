<?php require 'Views/Header.php'; ?>

<?php
$cierreId = (int)($data['cierre_id'] ?? 0);
?>

<main class="close-page">
    <section class="close-header">
        <div>
            <span class="close-badge">Reporte administrativo · Detalle</span>
            <h1 id="closure_detail_title">Detalle de cierre de caja</h1>
            <p id="closure_detail_subtitle" class="close-subtitle">Consultando resumen, arqueos, autorizaciones y movimientos del cierre.</p>
        </div>
        <div class="close-header-actions">
            <a class="close-button close-button-secondary" href="<?= BASE_URL ?>/Reporte/cierresCaja">Volver a cierres</a>
            <?php if ((int)($_SESSION['rol'] ?? 0) === 1 && \Libraries\Middleware\RBACMiddleware::tienePermisoAsignado(1, 'reportes.cierres_caja') && \Libraries\Middleware\RBACMiddleware::tienePermisoAsignado(1, 'reportes.exportar')): ?>
                <button id="closure_export_pdf" type="button" class="close-button close-button-secondary" disabled>Descargar PDF</button>
                <button id="closure_export_excel" type="button" class="close-button close-button-secondary" disabled>Descargar Excel</button>
            <?php endif; ?>
        </div>
    </section>

    <div id="closure_detail_feedback" class="close-alert" style="display: none;"></div>

    <div id="closure_detail_content" style="display: none;">
        <section class="close-panel">
            <div class="close-summary-grid">
                <article class="close-metric">
                    <span class="close-metric-label">Efectivo esperado</span>
                    <strong id="detail_expected" class="close-metric-value">S/ 0.00</strong>
                </article>
                <article class="close-metric">
                    <span class="close-metric-label">Efectivo contado</span>
                    <strong id="detail_counted" class="close-metric-value">S/ 0.00</strong>
                </article>
                <article class="close-metric">
                    <span class="close-metric-label">Diferencia de cierre</span>
                    <strong id="detail_closing_diff" class="close-metric-value">S/ 0.00</strong>
                </article>
                <article class="close-metric">
                    <span class="close-metric-label">Diferencia de apertura</span>
                    <strong id="detail_opening_diff" class="close-metric-value">No aplica</strong>
                </article>
            </div>
        </section>

        <section class="close-panel">
            <h2>Resumen del turno y cierre</h2>
            <div class="close-detail-grid">
                <div class="close-detail-item">
                    <span>Código</span>
                    <strong id="detail_code">—</strong>
                </div>
                <div class="close-detail-item">
                    <span>Vendedor</span>
                    <strong id="detail_seller">—</strong>
                </div>
                <div class="close-detail-item">
                    <span>Turno y horario</span>
                    <strong id="detail_schedule">—</strong>
                </div>
                <div class="close-detail-item">
                    <span>Apertura</span>
                    <strong id="detail_opened_at">—</strong>
                </div>
                <div class="close-detail-item">
                    <span>Cierre</span>
                    <strong id="detail_closed_at">—</strong>
                </div>
                <div class="close-detail-item">
                    <span>Fondo inicial</span>
                    <strong id="detail_opening_fund">S/ 0.00</strong>
                </div>
                <div class="close-detail-item">
                    <span>Cierre anterior de referencia</span>
                    <strong id="detail_reference_amount">Sin cierre anterior</strong>
                </div>
                <div class="close-detail-item">
                    <span>Autorización</span>
                    <strong id="detail_authorization">Sin excepción</strong>
                </div>
                <div class="close-detail-item">
                    <span>Ventas en efectivo</span>
                    <strong id="detail_cash_sales">S/ 0.00</strong>
                </div>
                <div class="close-detail-item">
                    <span>Ingresos / Egresos</span>
                    <strong id="detail_income_expenses">S/ 0.00 / S/ 0.00</strong>
                </div>
                <div class="close-detail-item">
                    <span>Ventas tarjeta / Yape·Plin</span>
                    <strong id="detail_digital_sales">S/ 0.00 / S/ 0.00</strong>
                </div>
                <div class="close-detail-item">
                    <span>Observación de cierre</span>
                    <strong id="detail_observation">Sin observación</strong>
                </div>
            </div>
        </section>

        <section class="close-panel">
            <div class="close-section-head">
                <div>
                    <h2>Arqueos registrados</h2>
                    <p>Historial de arqueos realizados durante la sesión.</p>
                </div>
            </div>
            <div class="close-table-wrap">
                <table class="close-table">
                    <thead>
                        <tr>
                            <th>Fecha y hora</th>
                            <th>Esperado</th>
                            <th>Contado</th>
                            <th>Diferencia</th>
                            <th>Estado</th>
                            <th>Observación</th>
                        </tr>
                    </thead>
                    <tbody id="detail_arqueos_tbody">
                        <tr><td colspan="6" class="close-empty">Sin arqueos registrados.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="close-panel">
            <div class="close-section-head">
                <div>
                    <h2>Movimientos de la sesión</h2>
                    <p>Ventas en efectivo, inyecciones, retiros, correcciones y retiros omitidos vinculados.</p>
                </div>
            </div>
            <div class="close-table-wrap">
                <table class="close-table">
                    <thead>
                        <tr>
                            <th>Fecha y hora</th>
                            <th>Tipo</th>
                            <th>Motivo / Referencia</th>
                            <th>Monto</th>
                            <th>Vendedor</th>
                            <th>Administrador</th>
                        </tr>
                    </thead>
                    <tbody id="detail_movements_tbody">
                        <tr><td colspan="6" class="close-empty">Sin movimientos registrados.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="close-pagination">
                <span id="detail_movs_pagination_info">Mostrando 0 de 0</span>
                <div class="close-pagination-actions">
                    <button id="detail_movs_prev_btn" type="button" class="close-button close-button-secondary" disabled>Anterior</button>
                    <button id="detail_movs_next_btn" type="button" class="close-button close-button-secondary" disabled>Siguiente</button>
                </div>
            </div>
        </section>
    </div>
</main>

<script>
    window.CAJA_REPORTES_CONFIG = {
        mode: 'detail',
        baseUrl: <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>,
        cierreId: <?= json_encode($cierreId) ?>
    };
</script>
<script src="<?= BASE_URL ?>/Assets/js/reporteCierres.js"></script>

<?php require 'Views/Footer.php'; ?>
