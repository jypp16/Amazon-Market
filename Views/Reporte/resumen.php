<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3><i class="fa-solid fa-file-pdf"></i> Resumen Ejecutivo</h3>
    <div class="actions-group">
        <button type="button" class="btn btn-gold" id="btn_exportar_resumen"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</button>
        <button type="button" class="btn btn-outline-success" id="btn_excel_resumen"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
        <button type="button" class="btn btn-outline-info" id="btn_email_resumen"><i class="fa-solid fa-envelope"></i> Enviar por Email</button>
    </div>
</div>

<div class="card margin-bottom-20">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-calendar"></i> Período: <?= e($filtros['desde'] ?? date('Y-m-01')) ?> al <?= e($filtros['hasta'] ?? date('Y-m-d')) ?></h3>
    </div>
    <form method="GET" action="<?= BASE_URL ?>/Reporte/resumen" class="form-grid form-pad-b">
        <div class="form-group col-4">
            <label>Fecha Inicio</label>
            <input type="date" name="desde" value="<?= e($filtros['desde'] ?? date('Y-m-01')) ?>">
        </div>
        <div class="form-group col-4">
            <label>Fecha Fin</label>
            <input type="date" name="hasta" value="<?= e($filtros['hasta'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group col-4 form-actions-end">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-magnifying-glass"></i> Actualizar</button>
        </div>
    </form>
</div>

<div class="dashboard-grid">
    <div class="stat-card card gold-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($ventas['resumen']['total_ingresos'] ?? 0), 2) ?></h3>
                <p>Ingresos Totales</p>
            </div>
        </div>
    </div>
    <div class="stat-card card blue-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-details">
                <h3><?= intval($ventas['resumen']['total_ventas'] ?? 0) ?></h3>
                <p>Total Ventas</p>
            </div>
        </div>
    </div>
    <div class="stat-card card blue-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($ventas['resumen']['ticket_promedio'] ?? 0), 2) ?></h3>
                <p>Ticket Promedio</p>
            </div>
        </div>
    </div>
    <div class="stat-card card gold-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-warehouse"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($inventario['resumen']['valor_total'] ?? 0), 2) ?></h3>
                <p>Valor Inventario</p>
            </div>
        </div>
    </div>
</div>

<div class="report-grid-2">
    <div class="card report-sub-card">
        <h4 class="sub-card-title">
            <i class="fa-solid fa-fire"></i> Top 5 Productos
        </h4>
        <table class="table">
            <thead>
                <tr><th>#</th><th>Producto</th><th>Ingresos</th></tr>
            </thead>
            <tbody>
                <?php foreach (($masVendidos['productos'] ?? []) as $i => $p): ?>
                <tr>
                    <td><span class="badge-accent"><?= $i + 1 ?></span></td>
                    <td class="cell-semibold"><?= e($p['nombre']) ?></td>
                    <td class="price-text">S/. <?= number_format(floatval($p['ingresos']), 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card report-sub-card">
        <h4 class="sub-card-title">
            <i class="fa-solid fa-users"></i> Top 3 Clientes
        </h4>
        <table class="table">
            <thead>
                <tr><th>#</th><th>Cliente</th><th>Total Gastado</th></tr>
            </thead>
            <tbody>
                <?php foreach (($topClientes['clientes'] ?? []) as $i => $c): ?>
                <tr>
                    <td><span class="badge-accent"><?= $i + 1 ?></span></td>
                    <td class="cell-semibold"><?= e($c['nombre']) ?></td>
                    <td class="price-text">S/. <?= number_format(floatval($c['total_gastado']), 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="report-grid-2">
    <div class="card report-sub-card">
        <h4 class="sub-card-title">
            <i class="fa-solid fa-user-tie"></i> Rendimiento Vendedores
        </h4>
        <table class="table">
            <thead>
                <tr><th>Vendedor</th><th># Ventas</th><th>Ingresos</th><th>%</th></tr>
            </thead>
            <tbody>
                <?php foreach (($vendedores['vendedores'] ?? []) as $v): ?>
                <tr>
                    <td class="cell-semibold"><?= e($v['vendedor']) ?></td>
                    <td><?= intval($v['num_ventas']) ?></td>
                    <td class="price-text">S/. <?= number_format(floatval($v['ingreso_total']), 2) ?></td>
                    <td><?= e($v['porcentaje']) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card report-sub-card">
        <h4 class="sub-card-title">
            <i class="fa-solid fa-credit-card"></i> Métodos de Pago
        </h4>
        <table class="table">
            <thead>
                <tr><th>Método</th><th># Transacciones</th><th>Monto</th><th>%</th></tr>
            </thead>
            <tbody>
                <?php foreach (($metodos['metodos'] ?? []) as $m): ?>
                <tr>
                    <td class="cell-semibold"><?= e($m['metodo_pago']) ?></td>
                    <td><?= intval($m['num_transacciones']) ?></td>
                    <td class="price-text">S/. <?= number_format(floatval($m['monto_total']), 2) ?></td>
                    <td><?= e($m['porcentaje']) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (intval($inventario['resumen']['productos_stock_bajo'] ?? 0) > 0): ?>
<div class="card report-sub-card alert-stock margin-bottom-20">
    <h4 class="sub-card-title danger">
        <i class="fa-solid fa-triangle-exclamation"></i> Alertas de Stock Bajo (<?= intval($inventario['resumen']['productos_stock_bajo']) ?> productos)
    </h4>
    <div class="stock-tags">
        <?php foreach (($inventario['productos'] ?? []) as $p): ?>
        <?php if ($p['stock_bajo']): ?>
        <span class="stock-badge stock-low"><?= e($p['nombre']) ?> (<?= number_format(floatval($p['stock_actual']), 2) ?>)</span>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
const exportParamsResumen = <?= json_encode(array_filter($filtros ?? [], fn($v) => $v !== '')) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
