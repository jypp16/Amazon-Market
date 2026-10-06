<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3><i class="fa-solid fa-warehouse"></i> Valor de Inventario y Stock Bajo</h3>
    <div class="actions-group">
        <button type="button" class="btn btn-gold" id="btn_exportar_inventario"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</button>
        <button type="button" class="btn btn-outline-success" id="btn_excel_inventario"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
        <button type="button" class="btn btn-outline-info" id="btn_email_inventario"><i class="fa-solid fa-envelope"></i> Enviar por Email</button>
    </div>
</div>

<div class="dashboard-grid">
    <div class="stat-card card gold-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($resumen['valor_total'] ?? 0), 2) ?></h3>
                <p>Valor Total Inventario</p>
            </div>
        </div>
    </div>
    <div class="stat-card card blue-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div class="stat-details">
                <h3><?= intval($resumen['total_skus'] ?? 0) ?></h3>
                <p>Total SKUs</p>
            </div>
        </div>
    </div>
    <div class="stat-card card danger-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="stat-details">
                <h3><?= intval($resumen['productos_stock_bajo'] ?? 0) ?></h3>
                <p>Stock Bajo (Reabastecer)</p>
            </div>
        </div>
    </div>
    <div class="stat-card card gold-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($resumen['valor_stock_riesgo'] ?? 0), 2) ?></h3>
                <p>Valor en Riesgo</p>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Código</th>
                <th>Producto</th>
                <th>Categoría</th>
                <th>Stock Actual</th>
                <th>Stock Mínimo</th>
                <th>Diferencia</th>
                <th>Precio Venta</th>
                <th>Valor Stock</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($productos)): ?>
            <tr><td colspan="8" class="table-empty">No hay productos registrados.</td></tr>
            <?php else: ?>
            <?php foreach ($productos as $p): ?>
            <tr class="<?= $p['stock_bajo'] ? 'row-danger' : '' ?>">
                <td><span class="barcode-badge"><?= e($p['codigo_barra']) ?></span></td>
                <td class="cell-semibold"><?= e($p['nombre']) ?></td>
                <td><span class="badge-neutral"><?= e($p['categoria']) ?></span></td>
                <td>
                    <?php if ($p['stock_bajo']): ?>
                    <span class="stock-badge stock-low"><?= number_format(floatval($p['stock_actual']), 2) ?></span>
                    <?php else: ?>
                    <span class="stock-badge stock-normal"><?= number_format(floatval($p['stock_actual']), 2) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= number_format(floatval($p['stock_minimo']), 2) ?></td>
                <td>
                    <?php $diff = floatval($p['stock_actual']) - floatval($p['stock_minimo']); ?>
                    <span class="cell-semibold" style="color: <?= $diff < 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
                        <?= $diff >= 0 ? '+' : '' ?><?= number_format($diff, 2) ?>
                    </span>
                </td>
                <td class="price-text">S/. <?= number_format(floatval($p['precio_venta']), 2) ?></td>
                <td class="price-text">S/. <?= number_format(floatval($p['valor_stock']), 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const exportParamsInventario = <?= json_encode([]) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
