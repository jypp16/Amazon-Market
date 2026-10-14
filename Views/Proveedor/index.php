<?php require_once "Views/Header.php"; ?>

<div class="table-container-header providers-header">
    <div class="providers-heading-group">
        <h3>Proveedores</h3>
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="search" id="busqueda_proveedor" placeholder="Buscar proveedor..." class="search-input" aria-label="Buscar proveedor">
        </div>
    </div>
    <div class="providers-actions">
        <button type="button" id="btn_crear_proveedor" class="btn btn-gold">
            <i class="fa-solid fa-circle-plus"></i> Registrar
        </button>
        <button type="button" class="providers-export-btn providers-export-pdf" aria-label="Imprimir o exportar proveedores a PDF" title="Imprimir o guardar como PDF">
            <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
            <span>Imprimir o Exportar</span>
        </button>
        <button type="button" class="providers-export-btn providers-export-excel" aria-label="Exportar proveedores a Excel" title="Exportar a Excel">
            <i class="fa-solid fa-file-excel" aria-hidden="true"></i>
            <span>Exportar Excel</span>
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

<div id="paginacion_proveedores" class="pagination-container" aria-label="Paginación de proveedores"></div>

<?php require_once "Views/Footer.php"; ?>
