<?php require_once "Views/Header.php"; ?>

<div class="welcome-box">
    <h2><i class="fa-solid fa-chart-pie"></i> Centro de Reportes</h2>
    <p>Selecciona un reporte para analizar el rendimiento de tu negocio.</p>
</div>

<div class="dashboard-grid" style="margin-top: 20px;">
    <?php if (can('reportes.ver')): ?>
    <a href="<?= BASE_URL ?>/Reporte/ventas" class="card report-card blue-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
            <div class="stat-details">
                <h3>Ventas</h3>
                <p>Por período</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/productosMasVendidos" class="card report-card gold-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-fire"></i></div>
            <div class="stat-details">
                <h3>Más Vendidos</h3>
                <p>Productos top</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/productosMenosVendidos" class="card report-card blue-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-box-archive"></i></div>
            <div class="stat-details">
                <h3>Menos Vendidos</h3>
                <p>Sin movimiento</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/inventario" class="card report-card gold-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-warehouse"></i></div>
            <div class="stat-details">
                <h3>Inventario</h3>
                <p>Valor y stock bajo</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/clientes" class="card report-card blue-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
            <div class="stat-details">
                <h3>Clientes</h3>
                <p>Top y recurrencia</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/vendedores" class="card report-card gold-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-user-tie"></i></div>
            <div class="stat-details">
                <h3>Vendedores</h3>
                <p>Rendimiento</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/categorias" class="card report-card blue-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-tags"></i></div>
            <div class="stat-details">
                <h3>Categorías</h3>
                <p>Ventas por categoría</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/comprobantes" class="card report-card gold-light-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div>
            <div class="stat-details">
                <h3>Comprobantes</h3>
                <p>Boletas y facturas</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/metodosPago" class="card report-card blue-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-credit-card"></i></div>
            <div class="stat-details">
                <h3>Métodos de Pago</h3>
                <p>Cómo pagan</p>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/Reporte/resumen" class="card report-card gold-card">
        <div class="card-body">
            <div class="stat-icon"><i class="fa-solid fa-file-pdf"></i></div>
            <div class="stat-details">
                <h3>Resumen Ejecutivo</h3>
                <p>Consolidado imprimible</p>
            </div>
        </div>
    </a>
    <?php endif; ?>
</div>

<?php require_once "Views/Footer.php"; ?>
