<?php require_once "Views/Header.php";

$esAdmin = $data['es_admin'] ?? false;
$ventasRecientes = $data['ventas_recientes'] ?? [];
$productosTop = $data['productos_top'] ?? [];
$ingresosMensuales = $data['ingresos_mensuales'] ?? [];
$horasPico = $data['horas_pico'] ?? [];
$ingresosMes = floatval($data['ingresos_mes'] ?? 0);
$ingresosAnio = floatval($data['ingresos'] ?? 0);
$nombre = e($_SESSION['nombre'] ?? '');
?>

<!-- Bienvenida -->
<div class="dash-welcome">
    <div class="dash-welcome-text">
        <h2>Buenos <?= (date('H') < 12) ? 'días' : ((date('H') < 19) ? 'tardes' : 'noches') ?>, <?= $nombre ?></h2>
        <p><?php if ($esAdmin): ?>
            Panel de control de <strong>Amazon Market</strong>. Resumen operativo completo del negocio.
        <?php else: ?>
            Bienvenido al sistema. Aquí tienes el resumen de tu actividad de ventas.
        <?php endif; ?></p>
    </div>
</div>

<?php if ($esAdmin): ?>
<!-- ===================== ADMIN: KPI COMPLETOS ===================== -->
<div class="dash-kpi-grid">
    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-blue"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number"><?= e($data['productos']) ?></span>
            <span class="dash-kpi-label">Productos Activos</span>
        </div>
        <a href="<?= BASE_URL ?>/Producto" class="dash-kpi-link"><i class="fa-solid fa-arrow-right"></i></a>
    </div>

    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-gold"><i class="fa-solid fa-users"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number"><?= e($data['clientes']) ?></span>
            <span class="dash-kpi-label">Clientes Registrados</span>
        </div>
        <a href="<?= BASE_URL ?>/Cliente" class="dash-kpi-link"><i class="fa-solid fa-arrow-right"></i></a>
    </div>

    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-green"><i class="fa-solid fa-receipt"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number"><?= e($data['ventas_hoy']) ?></span>
            <span class="dash-kpi-label">Ventas Hoy</span>
        </div>
        <span class="dash-kpi-badge badge-success">S/. <?= number_format($data['ingresos_hoy'], 2) ?></span>
    </div>

    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-indigo"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number">S/. <?= number_format($ingresosMes, 2) ?></span>
            <span class="dash-kpi-label">Ingresos del Mes</span>
        </div>
        <span class="dash-kpi-badge badge-info"><?= e($data['ventas_mes']) ?> ventas</span>
    </div>

    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-purple"><i class="fa-solid fa-coins"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number">S/. <?= number_format(floatval($data['ticket_promedio'] ?? 0), 2) ?></span>
            <span class="dash-kpi-label">Ticket Promedio</span>
        </div>
        <span class="dash-kpi-badge badge-muted">mes actual</span>
    </div>

    <div class="dash-kpi-card <?= intval($data['productos_bajo_stock'] ?? 0) > 0 ? 'dash-kpi-alert' : '' ?>">
        <div class="dash-kpi-icon <?= intval($data['productos_bajo_stock'] ?? 0) > 0 ? 'kpi-red' : 'kpi-gray' ?>"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number"><?= e($data['productos_bajo_stock'] ?? 0) ?></span>
            <span class="dash-kpi-label">Stock Bajo</span>
        </div>
        <?php if (intval($data['productos_bajo_stock'] ?? 0) > 0): ?>
            <a href="<?= BASE_URL ?>/Producto" class="dash-kpi-link kpi-link-alert"><i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
    </div>
</div>

<!-- Admin: Ventas Recientes + Más Vendidos -->
<div class="dash-two-col">
    <div class="dash-panel">
        <div class="dash-panel-header">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Ventas Recientes</h3>
            <a href="<?= BASE_URL ?>/Reporte/Ventas" class="dash-panel-link">Ver todas <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="dash-panel-body">
            <?php if (empty($ventasRecientes)): ?>
                <div class="dash-empty"><i class="fa-solid fa-receipt"></i><p>No hay ventas registradas aún</p></div>
            <?php else: ?>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead><tr><th>Comprobante</th><th>Cliente</th><th>Pago</th><th class="text-right">Total</th><th>Hora</th></tr></thead>
                        <tbody>
                            <?php foreach ($ventasRecientes as $v): ?>
                                <tr>
                                    <td><span class="comprobante-badge"><?= e($v['serie'] . '-' . $v['numero']) ?></span></td>
                                    <td class="text-ellipsis"><?= e($v['cliente'] ?? 'Cliente General') ?></td>
                                    <td><span class="pago-badge"><?= e($v['metodo_pago'] ?? '-') ?></span></td>
                                    <td class="text-right fw-bold">S/. <?= number_format(floatval($v['total']), 2) ?></td>
                                    <td class="text-muted"><?= date('d/m H:i', strtotime($v['fecha_venta'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-panel">
        <div class="dash-panel-header">
            <h3><i class="fa-solid fa-trophy"></i> Más Vendidos</h3>
            <a href="<?= BASE_URL ?>/Reporte/productosMasVendidos" class="dash-panel-link">Ver reporte <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="dash-panel-body">
            <?php if (empty($productosTop)): ?>
                <div class="dash-empty"><i class="fa-solid fa-box-open"></i><p>Sin datos de ventas aún</p></div>
            <?php else: ?>
                <div class="dash-top-list">
                    <?php foreach ($productosTop as $i => $p): ?>
                        <div class="dash-top-item">
                            <span class="dash-top-rank"><?= ($i + 1) ?></span>
                            <div class="dash-top-info">
                                <span class="dash-top-name"><?= e($p['nombre']) ?></span>
                                <span class="dash-top-meta"><?= number_format(floatval($p['total_vendido']), 0) ?> uds &middot; S/. <?= number_format(floatval($p['total_ingreso']), 2) ?></span>
                            </div>
                            <div class="dash-top-bar"><div class="dash-top-bar-fill" style="width: <?= min(100, ($productosTop[0]['total_vendido'] > 0 ? (floatval($p['total_vendido']) / floatval($productosTop[0]['total_vendido'])) * 100 : 0)) ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Admin: Ingresos Mensuales + Horas Pico -->
<div class="dash-two-col dash-mt">
    <div class="dash-panel">
        <div class="dash-panel-header">
            <h3><i class="fa-solid fa-chart-column"></i> Ingresos Mensuales</h3>
            <span class="dash-panel-badge">Últimos <?= count($ingresosMensuales) ?> meses</span>
        </div>
        <div class="dash-panel-body">
            <?php if (empty($ingresosMensuales)): ?>
                <div class="dash-empty"><i class="fa-solid fa-chart-simple"></i><p>Suficientes datos para el gráfico</p></div>
            <?php else: ?>
                <?php
                    $maxIngreso = 1;
                    foreach ($ingresosMensuales as $m) { if (floatval($m['ingresos']) > $maxIngreso) $maxIngreso = floatval($m['ingresos']); }
                ?>
                <div class="dash-chart">
                    <?php foreach ($ingresosMensuales as $m):
                        $pct = ($maxIngreso > 0) ? (floatval($m['ingresos']) / $maxIngreso) * 100 : 0;
                        $mesLabel = date('M', strtotime($m['mes'] . '-01'));
                    ?>
                        <div class="dash-chart-col">
                            <div class="dash-chart-value">S/. <?= number_format(floatval($m['ingresos']), 0) ?></div>
                            <div class="dash-chart-bar-track"><div class="dash-chart-bar" style="height: <?= $pct ?>%"></div></div>
                            <div class="dash-chart-label"><?= $mesLabel ?></div>
                            <div class="dash-chart-sub"><?= e($m['num_ventas']) ?> vtas</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-panel">
        <div class="dash-panel-header">
            <h3><i class="fa-solid fa-clock"></i> Horas Pico</h3>
            <span class="dash-panel-badge">Últimos 30 días</span>
        </div>
        <div class="dash-panel-body">
            <?php if (empty($horasPico)): ?>
                <div class="dash-empty"><i class="fa-solid fa-hourglass-half"></i><p>Sin datos suficientes</p></div>
            <?php else: ?>
                <?php
                    $maxHora = 1;
                    foreach ($horasPico as $h) { if (intval($h['total']) > $maxHora) $maxHora = intval($h['total']); }
                ?>
                <div class="dash-hour-list">
                    <?php foreach ($horasPico as $h):
                        $pct = ($maxHora > 0) ? (intval($h['total']) / $maxHora) * 100 : 0;
                        $hora = str_pad($h['hora'], 2, '0', STR_PAD_LEFT);
                    ?>
                        <div class="dash-hour-row">
                            <span class="dash-hour-time"><?= $hora ?>:00</span>
                            <div class="dash-hour-bar-track"><div class="dash-hour-bar" style="width: <?= $pct ?>%"></div></div>
                            <span class="dash-hour-count"><?= e($h['total']) ?> ventas</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Admin: Resumen del Año -->
<div class="dash-year-summary">
    <div class="dash-year-item">
        <i class="fa-solid fa-sack-dollar"></i>
        <div>
            <span class="dash-year-num">S/. <?= number_format($ingresosAnio, 2) ?></span>
            <span class="dash-year-label">Ingresos Totales <?= date('Y') ?></span>
        </div>
    </div>
    <div class="dash-year-divider"></div>
    <div class="dash-year-item">
        <i class="fa-solid fa-cart-shopping"></i>
        <div>
            <span class="dash-year-num"><?= e($data['ventas']) ?></span>
            <span class="dash-year-label">Ventas Totales</span>
        </div>
    </div>
    <div class="dash-year-divider"></div>
    <div class="dash-year-item">
        <i class="fa-solid fa-chart-line"></i>
        <div>
            <span class="dash-year-num">S/. <?= number_format(($data['ventas'] ?? 0) > 0 ? floatval($data['ingresos']) / intval($data['ventas']) : 0, 2) ?></span>
            <span class="dash-year-label">Ticket Promedio General</span>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ===================== VENDEDOR: KPI DE VENTAS ===================== -->
<div class="dash-kpi-grid">
    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-green"><i class="fa-solid fa-receipt"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number"><?= e($data['ventas_hoy']) ?></span>
            <span class="dash-kpi-label">Ventas Hoy</span>
        </div>
        <span class="dash-kpi-badge badge-success">S/. <?= number_format($data['ingresos_hoy'], 2) ?></span>
    </div>

    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-blue"><i class="fa-solid fa-user-check"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number"><?= e($data['mis_ventas_hoy'] ?? 0) ?></span>
            <span class="dash-kpi-label">Mis Ventas Hoy</span>
        </div>
        <span class="dash-kpi-badge badge-info">S/. <?= number_format($data['mis_ingresos_hoy'] ?? 0, 2) ?></span>
    </div>

    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-indigo"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number">S/. <?= number_format($ingresosMes, 2) ?></span>
            <span class="dash-kpi-label">Ingresos del Mes</span>
        </div>
        <span class="dash-kpi-badge badge-info"><?= e($data['ventas_mes']) ?> ventas</span>
    </div>

    <div class="dash-kpi-card">
        <div class="dash-kpi-icon kpi-purple"><i class="fa-solid fa-user"></i></div>
        <div class="dash-kpi-data">
            <span class="dash-kpi-number">S/. <?= number_format($data['mis_ingresos_mes'] ?? 0, 2) ?></span>
            <span class="dash-kpi-label">Mis Ingresos Mes</span>
        </div>
        <span class="dash-kpi-badge badge-muted"><?= e($data['mis_ventas_mes'] ?? 0) ?> mis ventas</span>
    </div>
</div>

<!-- Vendedor: Mis Ventas Recientes + Más Vendidos -->
<div class="dash-two-col">
    <div class="dash-panel">
        <div class="dash-panel-header">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Mis Ventas Recientes</h3>
            <a href="<?= BASE_URL ?>/Venta" class="dash-panel-link">Ir al POS <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="dash-panel-body">
            <?php if (empty($ventasRecientes)): ?>
                <div class="dash-empty"><i class="fa-solid fa-receipt"></i><p>Aún no has registrado ventas</p></div>
            <?php else: ?>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead><tr><th>Comprobante</th><th>Cliente</th><th>Pago</th><th class="text-right">Total</th><th>Hora</th></tr></thead>
                        <tbody>
                            <?php foreach ($ventasRecientes as $v): ?>
                                <tr>
                                    <td><span class="comprobante-badge"><?= e($v['serie'] . '-' . $v['numero']) ?></span></td>
                                    <td class="text-ellipsis"><?= e($v['cliente'] ?? 'Cliente General') ?></td>
                                    <td><span class="pago-badge"><?= e($v['metodo_pago'] ?? '-') ?></span></td>
                                    <td class="text-right fw-bold">S/. <?= number_format(floatval($v['total']), 2) ?></td>
                                    <td class="text-muted"><?= date('d/m H:i', strtotime($v['fecha_venta'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-panel">
        <div class="dash-panel-header">
            <h3><i class="fa-solid fa-trophy"></i> Más Vendidos</h3>
        </div>
        <div class="dash-panel-body">
            <?php if (empty($productosTop)): ?>
                <div class="dash-empty"><i class="fa-solid fa-box-open"></i><p>Sin datos de ventas aún</p></div>
            <?php else: ?>
                <div class="dash-top-list">
                    <?php foreach ($productosTop as $i => $p): ?>
                        <div class="dash-top-item">
                            <span class="dash-top-rank"><?= ($i + 1) ?></span>
                            <div class="dash-top-info">
                                <span class="dash-top-name"><?= e($p['nombre']) ?></span>
                                <span class="dash-top-meta"><?= number_format(floatval($p['total_vendido']), 0) ?> uds</span>
                            </div>
                            <div class="dash-top-bar"><div class="dash-top-bar-fill" style="width: <?= min(100, ($productosTop[0]['total_vendido'] > 0 ? (floatval($p['total_vendido']) / floatval($productosTop[0]['total_vendido'])) * 100 : 0)) ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once "Views/Footer.php"; ?>
