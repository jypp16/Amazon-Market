<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3>Inventario de Productos</h3>
    <div class="toolbar-actions">
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="busqueda_producto" placeholder="Buscar producto..." class="search-input">
        </div>
        <div class="filter-wrapper">
            <i class="fa-solid fa-filter filter-icon"></i>
            <select id="filtro_categoria" class="filter-select">
                <option value="">Todas las categorías</option>
            </select>
        </div>
        <?php if (can('productos.crear')): ?>
        <button type="button" id="btn_crear_producto" class="btn btn-gold"><i class="fa-solid fa-circle-plus"></i> Registrar Producto</button>
        <?php endif; ?>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Código de Barras</th>
                <th>Nombre</th>
                <th>Categoría</th>
                <th>Stock</th>
                <th>Precio Venta</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla_productos">
            <tr>
                <td colspan="6" class="text-center">Cargando productos...</td>
            </tr>
        </tbody>
    </table>
</div>

<div id="paginacion_productos" class="pagination-container"></div>

<script>
const canEdit = <?= can('productos.editar') ? 'true' : 'false' ?>;
const canDelete = <?= can('productos.eliminar') ? 'true' : 'false' ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
