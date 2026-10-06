<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3><i class="fa-solid fa-file-invoice"></i> Reporte de Comprobantes</h3>
    <div class="actions-group">
        <button type="button" class="btn btn-gold" id="btn_exportar_comprobantes"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</button>
        <button type="button" class="btn btn-outline-success" id="btn_excel_comprobantes"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
        <button type="button" class="btn btn-outline-info" id="btn_email_comprobantes"><i class="fa-solid fa-envelope"></i> Enviar por Email</button>
    </div>
</div>

<div class="card margin-bottom-20">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-filter"></i> Filtros</h3>
    </div>
    <form method="GET" action="<?= BASE_URL ?>/Reporte/comprobantes" class="form-grid form-pad-b">
        <div class="form-group col-3">
            <label>Fecha Inicio</label>
            <input type="date" name="desde" value="<?= e($filtros['desde'] ?? date('Y-m-01')) ?>">
        </div>
        <div class="form-group col-3">
            <label>Fecha Fin</label>
            <input type="date" name="hasta" value="<?= e($filtros['hasta'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group col-3">
            <label>Tipo Comprobante</label>
            <select name="id_tipo_comprobante">
                <option value="">Todos</option>
                <?php foreach ($listaComprobantes ?? [] as $tc): ?>
                <option value="<?= $tc['id_tipo_comprobante'] ?>" <?= ($filtros['id_tipo_comprobante'] ?? '') == $tc['id_tipo_comprobante'] ? 'selected' : '' ?>><?= e($tc['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group col-3 form-actions-end">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
        </div>
    </form>
</div>

<div class="dashboard-grid">
    <div class="stat-card card blue-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-file-lines"></i></div>
            <div class="stat-details">
                <h3><?= intval($totalBoletas ?? 0) ?></h3>
                <p>Boletas Emitidas</p>
            </div>
        </div>
    </div>
    <div class="stat-card card gold-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div>
            <div class="stat-details">
                <h3><?= intval($totalFacturas ?? 0) ?></h3>
                <p>Facturas Emitidas</p>
            </div>
        </div>
    </div>
    <div class="stat-card card blue-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-coins"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($igvFacturas ?? 0), 2) ?></h3>
                <p>IGV Aproximado (Facturas)</p>
            </div>
        </div>
    </div>
    <div class="stat-card card gold-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($montoFacturas ?? 0) + floatval($montoBoletas ?? 0), 2) ?></h3>
                <p>Total Comprobantes</p>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Comprobante</th>
                <th>Serie</th>
                <th>Cantidad</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($comprobantes)): ?>
            <tr><td colspan="4" class="table-empty">No hay datos en el período.</td></tr>
            <?php else: ?>
            <?php foreach ($comprobantes as $c): ?>
            <tr>
                <td><span class="badge-neutral"><?= e($c['comprobante']) ?></span></td>
                <td><span class="barcode-badge"><?= e($c['serie']) ?></span></td>
                <td><?= intval($c['cantidad']) ?></td>
                <td class="price-text">S/. <?= number_format(floatval($c['total']), 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const exportParamsComprobantes = <?= json_encode(array_filter($filtros ?? [], fn($v) => $v !== '')) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
