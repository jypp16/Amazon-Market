<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3><i class="fa-solid fa-credit-card"></i> Métodos de Pago</h3>
    <div class="actions-group">
        <button type="button" class="btn btn-gold" id="btn_exportar_metodos"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</button>
        <button type="button" class="btn btn-outline-success" id="btn_excel_metodos"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
        <button type="button" class="btn btn-outline-info" id="btn_email_metodos"><i class="fa-solid fa-envelope"></i> Enviar por Email</button>
    </div>
</div>

<div class="card margin-bottom-20">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-filter"></i> Filtros</h3>
    </div>
    <form method="GET" action="<?= BASE_URL ?>/Reporte/metodosPago" class="form-grid form-pad-b">
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
                <h3>S/. <?= number_format(floatval($totalMontos ?? 0), 2) ?></h3>
                <p>Total Recaudado</p>
            </div>
        </div>
    </div>
    <div class="stat-card card blue-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-details">
                <h3><?= intval($totalTransacciones ?? 0) ?></h3>
                <p>Total Transacciones</p>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Método de Pago</th>
                <th># Transacciones</th>
                <th>Monto Total</th>
                <th>% del Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($metodos)): ?>
            <tr><td colspan="5" class="table-empty">No hay datos en el período.</td></tr>
            <?php else: ?>
            <?php foreach ($metodos as $i => $m): ?>
            <tr>
                <td><span class="badge-accent"><?= $i + 1 ?></span></td>
                <td class="cell-semibold"><?= e($m['metodo_pago']) ?></td>
                <td><?= intval($m['num_transacciones']) ?></td>
                <td class="price-text">S/. <?= number_format(floatval($m['monto_total']), 2) ?></td>
                <td>
                    <div class="progress-row">
                        <div class="progress-track">
                            <div class="progress-fill" style="width: <?= e($m['porcentaje']) ?>%;"></div>
                        </div>
                        <span class="progress-label"><?= e($m['porcentaje']) ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const exportParamsMetodos = <?= json_encode(array_filter($filtros ?? [], fn($v) => $v !== '')) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
