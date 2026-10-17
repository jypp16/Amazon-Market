<?php require_once "Views/Header.php"; ?>

<section class="purchase-history">
    <header class="purchase-history-header">
        <nav class="purchase-breadcrumb" aria-label="Ruta de navegación">
            <span>Compras</span>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page">Historial de compras</span>
        </nav>
        <div class="purchase-history-actions">
            <?php if (can('compras.crear')): ?>
            <a href="<?= BASE_URL ?>/Compra/registrar" class="btn btn-gold">
                <i class="fa-solid fa-cart-plus"></i> Registrar compra
            </a>
            <?php endif; ?>
            <button type="button" id="compra_exportar_pdf" class="providers-export-btn providers-export-pdf" aria-label="Exportar historial de compras a PDF" aria-pressed="false" title="Exportar a PDF">
                <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                <span>Exportar PDF</span>
            </button>
            <button type="button" id="compra_exportar_excel" class="providers-export-btn providers-export-excel" aria-label="Exportar historial de compras a Excel" aria-pressed="false" title="Exportar a Excel">
                <i class="fa-solid fa-file-excel" aria-hidden="true"></i>
                <span>Exportar Excel</span>
            </button>
        </div>
    </header>

    <form class="purchase-filters" method="get" action="<?= BASE_URL ?>/Compra">
        <input type="hidden" name="url" value="Compra">
        <div class="purchase-filter-field purchase-filter-provider">
            <label for="busqueda_proveedor_compra">Proveedor</label>
            <div class="purchase-provider-combobox">
                <input type="search" id="busqueda_proveedor_compra" placeholder="Buscar proveedor..."
                    role="combobox" aria-autocomplete="list" aria-expanded="false"
                    aria-controls="opciones_proveedor_compra" autocomplete="off">
                <div id="opciones_proveedor_compra" class="purchase-provider-options" role="listbox" hidden></div>
                <select id="filtro_proveedor_compra" name="proveedor" class="visually-hidden" tabindex="-1" aria-hidden="true">
                <option value="">Todos los proveedores</option>
                <?php foreach ($data['proveedores'] as $proveedor): ?>
                <option value="<?= (int)$proveedor['id_proveedor'] ?>">
                    <?= e($proveedor['razon_social']) ?>
                </option>
                <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="purchase-filter-field">
            <label for="fecha_desde_compra">Desde (recepción)</label>
            <input type="date" id="fecha_desde_compra" name="fecha_desde" value="<?= e($data['fecha_desde']) ?>">
        </div>
        <div class="purchase-filter-field">
            <label for="fecha_hasta_compra">Hasta (recepción)</label>
            <input type="date" id="fecha_hasta_compra" name="fecha_hasta" value="<?= e($data['fecha_hasta']) ?>">
        </div>
        <fieldset class="purchase-filter-states" aria-describedby="compras_error_estados">
            <legend>Estados</legend>
            <div class="purchase-state-options">
                <label for="estado_compra_confirmada"><input type="checkbox" id="estado_compra_confirmada" name="estados[]" value="Confirmada" checked> Confirmada</label>
                <label for="estado_compra_corregida"><input type="checkbox" id="estado_compra_corregida" name="estados[]" value="Corregida" checked> Corregida</label>
                <label for="estado_compra_anulada"><input type="checkbox" id="estado_compra_anulada" name="estados[]" value="Anulada"> Anulada</label>
            </div>
            <p id="compras_error_estados" class="purchase-filter-error" role="alert"></p>
        </fieldset>
        <div class="purchase-filter-actions">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-filter"></i> Filtrar</button>
            <a href="<?= BASE_URL ?>/Compra" class="btn btn-secondary">Limpiar</a>
        </div>
    </form>

    <section class="purchase-summary" aria-label="Resumen de compras">
        <article class="purchase-summary-card">
            <h3>Total de compras válidas (todo el filtro)</h3>
            <p id="compras_total_valido">S/ 0.00</p>
        </article>
        <article class="purchase-summary-card">
            <h3>Compras válidas (todo el filtro)</h3>
            <p id="compras_cantidad_valida">0</p>
        </article>
        <article class="purchase-summary-card">
            <h3>Anuladas (no suman)</h3>
            <p id="compras_cantidad_anulada">0</p>
            <small id="compras_importe_anulado">Importe: S/ 0.00</small>
        </article>
    </section>

    <p id="compras_aviso_integridad" class="purchase-filter-error" role="status" hidden></p>

    <div class="table-responsive purchase-history-table">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Documento</th>
                    <th scope="col">Proveedor</th>
                    <th scope="col">Emisión</th>
                    <th scope="col">Recepción</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Importe vigente</th>
                    <th scope="col">Registró</th>
                    <th scope="col"><span class="visually-hidden">Acciones</span></th>
                </tr>
            </thead>
            <tbody id="tabla_compras">
                <tr>
                    <td colspan="8" class="table-empty">No hay compras para mostrar.</td>
                </tr>
            </tbody>
        </table>
        <div class="purchase-table-footer">
            <div class="purchase-table-info">
                <span id="compras_mostrando">Mostrando 0 compras</span>
                <strong id="compras_total_pagina">Total de esta página (válidas): S/ 0.00</strong>
            </div>
            <nav id="paginacion_compras" class="pag-btns" aria-label="Paginación del historial de compras">
                <button type="button" class="pag-btn" aria-label="Página anterior" disabled>Anterior</button>
                <button type="button" class="pag-btn pag-active" aria-current="page" disabled>1</button>
                <button type="button" class="pag-btn" aria-label="Página siguiente" disabled>Siguiente</button>
            </nav>
        </div>
    </div>
</section>

<dialog id="compras_generando_pdf" class="purchase-action-dialog purchase-report-loading" aria-labelledby="compras_generando_titulo" aria-describedby="compras_generando_descripcion">
    <form method="dialog">
        <h3 id="compras_generando_titulo"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Generando reporte…</h3>
        <p id="compras_generando_descripcion" role="status">Espera un momento. El PDF se abrirá en la pestaña reservada cuando esté listo.</p>
        <div class="purchase-action-dialog-buttons">
            <button type="button" id="compras_cancelar_pdf" class="btn btn-secondary" autofocus>Cancelar</button>
        </div>
    </form>
</dialog>

<?php require_once "Views/Footer.php"; ?>
