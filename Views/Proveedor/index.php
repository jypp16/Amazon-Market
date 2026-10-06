<?php require_once "Views/Header.php"; ?>

<div class="table-container-header">
    <h3>Proveedores</h3>
    <div class="toolbar-actions">
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="search" id="busqueda_proveedor" placeholder="Buscar proveedor..." class="search-input" aria-label="Buscar proveedor">
        </div>
        <button type="button" id="btn_crear_proveedor" class="btn btn-gold">
            <i class="fa-solid fa-circle-plus"></i> Registrar
        </button>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th scope="col">Tipo de documento</th>
                <th scope="col">N° Documento</th>
                <th scope="col">Proveedor</th>
                <th scope="col">Teléfono</th>
                <th scope="col">Estado</th>
                <th scope="col">Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla_proveedores">
            <tr>
                <td colspan="6" class="table-empty">Aún no hay proveedores para mostrar.</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="pagination-container" aria-label="Paginación de proveedores">
    <span class="pag-info">Mostrando 0 proveedores</span>
    <div class="pag-btns">
        <button type="button" class="pag-btn" aria-label="Página anterior" disabled>
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        <button type="button" class="pag-btn pag-active" aria-current="page" disabled>1</button>
        <button type="button" class="pag-btn" aria-label="Página siguiente" disabled>
            <i class="fa-solid fa-chevron-right"></i>
        </button>
    </div>
</div>

<?php require_once "Views/Footer.php"; ?>
