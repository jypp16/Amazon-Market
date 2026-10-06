<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3><i class="fa-solid fa-chart-line"></i> Ventas por Período</h3>
    <div class="actions-group">
        <button type="button" class="btn btn-gold" id="btn_exportar_ventas"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</button>
        <button type="button" class="btn btn-outline-success" id="btn_excel_ventas"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
        <button type="button" class="btn btn-outline-info" id="btn_email_ventas"><i class="fa-solid fa-envelope"></i> Enviar por Email</button>
    </div>
</div>

<div class="card margin-bottom-20">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-filter"></i> Filtros</h3>
    </div>
    <form method="GET" action="<?= BASE_URL ?>/Reporte/ventas" class="form-grid form-pad-b">
        <div class="form-group col-3">
            <label>Fecha Inicio</label>
            <input type="date" name="desde" value="<?= e($filtros['desde'] ?? date('Y-m-01')) ?>">
        </div>
        <div class="form-group col-3">
            <label>Fecha Fin</label>
            <input type="date" name="hasta" value="<?= e($filtros['hasta'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group col-3">
            <label>Comprobante</label>
            <select name="id_tipo_comprobante">
                <option value="">Todos</option>
                <?php foreach ($listaComprobantes ?? [] as $tc): ?>
                <option value="<?= $tc['id_tipo_comprobante'] ?>" <?= ($filtros['id_tipo_comprobante'] ?? '') == $tc['id_tipo_comprobante'] ? 'selected' : '' ?>><?= e($tc['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group col-3">
            <label>Método de Pago</label>
            <select name="id_metodo_pago">
                <option value="">Todos</option>
                <?php foreach ($listaMetodosPago ?? [] as $mp): ?>
                <option value="<?= $mp['id_metodo_pago'] ?>" <?= ($filtros['id_metodo_pago'] ?? '') == $mp['id_metodo_pago'] ? 'selected' : '' ?>><?= e($mp['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group col-3">
            <label>Vendedor</label>
            <select name="id_usuario">
                <option value="">Todos</option>
                <?php foreach ($listaUsuarios ?? [] as $u): ?>
                <option value="<?= $u['id_usuario'] ?>" <?= ($filtros['id_usuario'] ?? '') == $u['id_usuario'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group col-3">
            <label>Cliente</label>
            <select name="id_cliente">
                <option value="">Todos</option>
                <?php foreach ($listaClientes ?? [] as $c): ?>
                <option value="<?= $c['id_cliente'] ?>" <?= ($filtros['id_cliente'] ?? '') == $c['id_cliente'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
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
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-details">
                <h3><?= intval($resumen['total_ventas'] ?? 0) ?></h3>
                <p>Total Ventas</p>
            </div>
        </div>
    </div>
    <div class="stat-card card gold-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($resumen['total_ingresos'] ?? 0), 2) ?></h3>
                <p>Ingresos Totales</p>
            </div>
        </div>
    </div>
    <div class="stat-card card blue-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-details">
                <h3>S/. <?= number_format(floatval($resumen['ticket_promedio'] ?? 0), 2) ?></h3>
                <p>Ticket Promedio</p>
            </div>
        </div>
    </div>
    <div class="stat-card card gold-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-chart-simple"></i></div>
            <div class="stat-details">
                <h3><?= $varIngresos >= 0 ? '+' : '' ?><?= e($varIngresos) ?>%</h3>
                <p>vs Período Anterior</p>
            </div>
        </div>
    </div>
</div>

<div class="card margin-bottom-20">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-chart-column"></i> Comprobantes: <?= e($boletas) ?> Boletas | <?= e($facturas) ?> Facturas</h3>
    </div>
    <div class="report-sub-card">
        <div id="chart_ventas_dia" class="chart-container" data-chart="line"></div>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Serie</th>
                <th>Número</th>
                <th>Cliente</th>
                <th>Comprobante</th>
                <th>Total</th>
                <th>Vendedor</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($ventas)): ?>
            <tr><td colspan="7" class="table-empty">No hay ventas en el período seleccionado.</td></tr>
            <?php else: ?>
            <?php foreach ($ventas as $v): ?>
            <tr>
                <td><?= e(date('d/m/Y H:i', strtotime($v['fecha_venta']))) ?></td>
                <td><span class="barcode-badge"><?= e($v['serie']) ?></span></td>
                <td><?= e($v['numero']) ?></td>
                <td><?= e($v['cliente']) ?></td>
                <td><span class="badge-neutral"><?= e($v['comprobante']) ?></span></td>
                <td class="price-text">S/. <?= number_format(floatval($v['total']), 2) ?></td>
                <td><?= e($v['vendedor']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const chartDataVentas = <?= json_encode($ventasPorDia ?? []) ?>;
const exportParamsVentas = <?= json_encode(array_filter($filtros ?? [], fn($v) => $v !== '')) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
