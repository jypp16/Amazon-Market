<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3>Directorio de Usuarios</h3>
    <div class="toolbar-actions">
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="busqueda_usuario" placeholder="Buscar usuario..." class="search-input">
        </div>
        <?php if (can('usuarios.crear')): ?>
        <button type="button" id="btn_crear_usuario" class="btn btn-gold"><i class="fa-solid fa-user-plus"></i> Registrar Usuario</button>
        <?php endif; ?>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Rol</th>
                <th>Username</th>
                <th>Nombre</th>
                <th>DNI</th>
                <th>Teléfono</th>
                <th>Email</th>
                <th>Dirección</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla_usuarios">
            <tr>
                <td colspan="8" class="text-center">Cargando usuarios...</td>
            </tr>
        </tbody>
    </table>
</div>

<script>
const canEdit = <?= can('usuarios.editar') ? 'true' : 'false' ?>;
const canDelete = <?= can('usuarios.eliminar') ? 'true' : 'false' ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
