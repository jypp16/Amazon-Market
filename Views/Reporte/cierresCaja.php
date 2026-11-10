<?php require_once 'Views/Header.php'; ?>

<section class="close-report">
    <header class="close-report-heading">
        <div>
            <h1>Cierres de caja</h1>
            <p>Revisa los cierres, identifica diferencias y exporta el período. Solo lectura.</p>
        </div>
        <div class="close-report-export">
            <button type="button" class="close-button close-button-pdf" disabled>Exportar PDF</button>
            <button type="button" class="close-button close-button-excel" disabled>Exportar Excel</button>
        </div>
    </header>

    <form class="close-filter-card" aria-label="Filtros de cierres de caja">
        <div class="close-filter-field">
            <label for="close_from">Desde (obligatorio)</label>
            <input id="close_from" type="date" value="2026-10-01">
        </div>
        <div class="close-filter-field">
            <label for="close_to">Hasta (obligatorio)</label>
            <input id="close_to" type="date" value="2026-10-20">
        </div>
        <div class="close-filter-field">
            <label for="close_shift">Turno</label>
            <select id="close_shift">
                <option>Todos</option>
                <option>Mañana</option>
                <option>Tarde</option>
            </select>
        </div>
        <div class="close-filter-field">
            <label for="close_seller">Vendedor</label>
            <select id="close_seller">
                <option>Todos</option>
                <option>Luis</option>
                <option>Marta</option>
            </select>
        </div>
        <div class="close-filter-actions">
            <button type="reset" class="close-button">Limpiar filtros</button>
            <button type="button" class="close-button close-button-primary" disabled>Consultar</button>
        </div>
    </form>

    <section class="close-table-card" aria-label="Cierres de caja del período">
        <div class="close-table-scroll">
            <table class="close-table">
                <thead>
                    <tr>
                        <th scope="col">Cierre</th>
                        <th scope="col">Vendedor</th>
                        <th scope="col">Turno</th>
                        <th scope="col">Efectivo esperado</th>
                        <th scope="col">Efectivo contado</th>
                        <th scope="col">Diferencia de cierre</th>
                        <th scope="col">Diferencia de apertura</th>
                        <th scope="col">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['cierres'] as $cierre): ?>
                    <?php
                    $diferenciaCierre = (float)$cierre['closing_difference'];
                    $diferenciaApertura = (float)$cierre['opening_difference'];
                    ?>
                    <tr>
                        <td><?= e($cierre['closed_at']) ?></td>
                        <td><strong><?= e($cierre['seller']) ?></strong></td>
                        <td><?= e($cierre['shift']) ?></td>
                        <td><?= e('S/ ' . number_format((float)$cierre['expected'], 2, ',', '')) ?></td>
                        <td><?= e('S/ ' . number_format((float)$cierre['counted'], 2, ',', '')) ?></td>
                        <td>
                            <strong class="<?= $diferenciaCierre < 0 ? 'is-negative' : ($diferenciaCierre > 0 ? 'is-positive' : '') ?>">
                                <?= e(($diferenciaCierre < 0 ? '−' : ($diferenciaCierre > 0 ? '+' : '')) . 'S/ ' . number_format(abs($diferenciaCierre), 2, ',', '')) ?>
                            </strong>
                            <small><?= $diferenciaCierre < 0 ? 'Faltante' : ($diferenciaCierre > 0 ? 'Sobrante' : 'Sin diferencia') ?></small>
                            <?php if (!empty($cierre['has_omitted_withdrawal'])): ?>
                            <span class="close-inline-badge">Retiro omitido vinculado</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= $diferenciaApertura < 0 ? 'is-negative' : ($diferenciaApertura > 0 ? 'is-positive' : '') ?>">
                                <?= e(($diferenciaApertura < 0 ? '−' : ($diferenciaApertura > 0 ? '+' : '')) . 'S/ ' . number_format(abs($diferenciaApertura), 2, ',', '')) ?>
                            </span>
                        </td>
                        <td>
                            <a class="close-button close-detail-link"
                                href="<?= BASE_URL ?>/Reporte/detalleCierreCaja/<?= (int)$cierre['id'] ?>">Ver detalle</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="close-table-footer">
            <p>Mostrando <?= count($data['cierres']) ?> de <?= count($data['cierres']) ?> cierres · La exportación incluye todos los cierres del filtro</p>
            <nav class="close-pagination" aria-label="Paginación de cierres">
                <button type="button" class="close-button" disabled>Anterior</button>
                <span aria-current="page">1</span>
                <button type="button" class="close-button" disabled>Siguiente</button>
            </nav>
        </footer>
    </section>
</section>

<?php require_once 'Views/Footer.php'; ?>
