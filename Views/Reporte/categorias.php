<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3><i class="fa-solid fa-tags"></i> Ventas por Categoría</h3>
    <div class="actions-group">
        <button type="button" class="btn btn-gold" id="btn_exportar_categorias"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</button>
        <button type="button" class="btn btn-outline-success" id="btn_excel_categorias"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
        <button type="button" class="btn btn-outline-info" id="btn_email_categorias"><i class="fa-solid fa-envelope"></i> Enviar por Email</button>
    </div>
</div>

<div class="card margin-bottom-20">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-filter"></i> Filtros</h3>
    </div>
    <form method="GET" action="<?= BASE_URL ?>/Reporte/categorias" class="form-grid form-pad-b">
        <div class="form-group col-4">
            <label>Fecha Inicio</label>
            <input type="date" name="desde" value="<?= e($filtros['desde'] ?? date('Y-m-01')) ?>">
        </div>
        <div class="form-group col-4">
            <label>Fecha Fin</label>
            <input type="date" name="hasta" value="<?= e($filtros['hasta'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group col-4 form-actions-end">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
        </div>
    </form>
</div>

<div class="dashboard-grid">
    <div class="stat-card card gold-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($totalIngresos ?? 0), 2) ?></h3>
                <p>Ingresos Totales</p>
            </div>
        </div>
    </div>
    <div class="stat-card card blue-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div class="stat-details">
                <h3><?= intval($totalUnidades ?? 0) ?></h3>
                <p>Unidades Totales</p>
            </div>
        </div>
    </div>
    <div class="stat-card card blue-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-tags"></i></div>
            <div class="stat-details">
                <h3><?= count($categorias ?? []) ?></h3>
                <p>Categorías con Ventas</p>
            </div>
        </div>
    </div>
</div>

<div id="chart_categorias_barras" class="chart-container margin-bottom-20"></div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Categoría</th>
                <th>Unidades</th>
                <th>Ingresos</th>
                <th>% del Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($categorias)): ?>
            <tr><td colspan="5" class="table-empty">No hay datos en el período.</td></tr>
            <?php else: ?>
            <?php foreach ($categorias as $i => $c): ?>
            <tr>
                <td><span class="badge-accent"><?= $i + 1 ?></span></td>
                <td class="cell-semibold"><?= e($c['categoria']) ?></td>
                <td><?= intval($c['unidades']) ?></td>
                <td class="price-text">S/. <?= number_format(floatval($c['ingresos']), 2) ?></td>
                <td>
                    <div class="progress-row">
                        <div class="progress-track">
                            <div class="progress-fill" style="width: <?= e($c['porcentaje']) ?>%;"></div>
                        </div>
                        <span class="progress-label"><?= e($c['porcentaje']) ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const chartDataCategorias = <?= json_encode($categorias ?? []) ?>;
const exportParamsCategorias = <?= json_encode(array_filter($filtros ?? [], fn($v) => $v !== '')) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
