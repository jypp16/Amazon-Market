<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3><i class="fa-solid fa-box-archive"></i> Productos Menos Vendidos / Sin Movimiento</h3>
    <div class="actions-group">
        <button type="button" class="btn btn-gold" id="btn_exportar_menosVendidos"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</button>
        <button type="button" class="btn btn-outline-success" id="btn_excel_menosVendidos"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
        <button type="button" class="btn btn-outline-info" id="btn_email_menosVendidos"><i class="fa-solid fa-envelope"></i> Enviar por Email</button>
    </div>
</div>

<div class="card margin-bottom-20">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-filter"></i> Filtros</h3>
    </div>
    <form method="GET" action="<?= BASE_URL ?>/Reporte/productosMenosVendidos" class="form-grid form-pad-b">
        <div class="form-group col-3">
            <label>Fecha Inicio</label>
            <input type="date" name="desde" value="<?= e($filtros['desde'] ?? date('Y-m-01')) ?>">
        </div>
        <div class="form-group col-3">
            <label>Fecha Fin</label>
            <input type="date" name="hasta" value="<?= e($filtros['hasta'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group col-3">
            <label>Categoría</label>
            <select name="id_categoria">
                <option value="">Todas</option>
                <?php foreach ($categorias ?? [] as $cat): ?>
                <option value="<?= $cat['id_categoria'] ?>" <?= ($filtros['id_categoria'] ?? '') == $cat['id_categoria'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group col-2">
            <label>Umbral (unidades ≤)</label>
            <input type="number" name="umbral" value="<?= e($filtros['umbral'] ?? 5) ?>" min="0" max="100">
        </div>
        <div class="form-group col-1 form-actions-end">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-magnifying-glass"></i></button>
        </div>
    </form>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Producto</th>
                <th>Categoría</th>
                <th>Stock Actual</th>
                <th>Stock Mínimo</th>
                <th>Precio Venta</th>
                <th>Unidades Vendidas</th>
                <th>Ingresos</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($productos)): ?>
            <tr><td colspan="7" class="table-empty">Todos los productos tienen movimiento en el período.</td></tr>
            <?php else: ?>
            <?php foreach ($productos as $p): ?>
            <tr>
                <td class="cell-semibold"><?= e($p['nombre']) ?></td>
                <td><span class="badge-neutral"><?= e($p['categoria']) ?></span></td>
                <td>
                    <?php if ($p['stock_actual'] <= $p['stock_minimo']): ?>
                    <span class="stock-badge stock-low"><?= number_format(floatval($p['stock_actual']), 2) ?> (Bajo)</span>
                    <?php else: ?>
                    <span class="stock-badge stock-normal"><?= number_format(floatval($p['stock_actual']), 2) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= number_format(floatval($p['stock_minimo']), 2) ?></td>
                <td class="price-text">S/. <?= number_format(floatval($p['precio_venta']), 2) ?></td>
                <td><?= intval($p['unidades_vendidas']) ?></td>
                <td>S/. <?= number_format(floatval($p['ingresos']), 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const exportParamsMenosVendidos = <?= json_encode(array_filter($filtros ?? [], fn($v) => $v !== '')) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
