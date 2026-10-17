<?php require_once "Views/Header.php"; ?>

<div class="table-container-header providers-header">
    <div class="providers-heading-group">
        <h3>Proveedores</h3>
        <div class="providers-filters">
            <div class="search-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon" aria-hidden="true"></i>
                <input type="search" id="busqueda_proveedor" placeholder="Buscar proveedor..." class="search-input" aria-label="Buscar proveedor">
            </div>
            <div class="providers-state-filter">
                <label for="estado_proveedor">Estado</label>
                <select id="estado_proveedor" class="filter-select">
                    <option value="todos">Todos</option>
                    <option value="activos">Activos</option>
                    <option value="inactivos">Inactivos</option>
                </select>
            </div>
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
        <button type="button" id="proveedores_exportar_excel" class="providers-export-btn providers-export-excel" aria-label="Exportar proveedores a Excel" title="Exportar a Excel">
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

<div id="paginacion_proveedores" class="pagination-container" aria-label="Paginación de proveedores" aria-live="polite"></div>

<dialog id="proveedores_generando_excel" class="purchase-action-dialog purchase-report-loading" aria-labelledby="proveedores_generando_titulo" aria-describedby="proveedores_generando_descripcion">
    <form method="dialog">
        <h3 id="proveedores_generando_titulo"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Generando reporte…</h3>
        <p id="proveedores_generando_descripcion" role="status">Espera un momento. El archivo Excel se descargará cuando esté listo.</p>
        <div class="purchase-action-dialog-buttons">
            <button type="button" id="proveedores_cancelar_excel" class="btn btn-secondary" autofocus>Cancelar</button>
        </div>
    </form>
</dialog>

<?php require_once "Views/Footer.php"; ?>
