<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3>Directorio de Clientes</h3>
    <div class="toolbar-actions">
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="busqueda_cliente" placeholder="Buscar cliente..." class="search-input">
        </div>
        <?php if (can('clientes.crear')): ?>
        <button type="button" id="btn_crear_cliente" class="btn btn-gold"><i class="fa-solid fa-user-plus"></i> Registrar Cliente</button>
        <?php endif; ?>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Tipo Doc.</th>
                <th>Nro Documento</th>
                <th>Nombre del Cliente</th>
                <th>Teléfono</th>
                <th>Email</th>
                <th>Dirección</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla_clientes">
            <tr>
                <td colspan="7" class="text-center">Cargando clientes...</td>
            </tr>
        </tbody>
    </table>
</div>

<script>
const canEdit = <?= can('clientes.editar') ? 'true' : 'false' ?>;
const canDelete = <?= can('clientes.eliminar') ? 'true' : 'false' ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
