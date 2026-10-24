<?php require_once "Views/Header.php"; ?>

<section class="kardex-page" aria-labelledby="kardex-title">
    <header class="kardex-page-heading">
        <div>
            <h1 id="kardex-title">Kardex de inventario</h1>
            <p>Entradas, salidas y saldos por producto. Consulta de solo lectura.</p>
        </div>
        <div class="kardex-export-actions">
            <button type="button" class="kardex-export-pdf"><i class="fa-regular fa-file-pdf" aria-hidden="true"></i> Exportar PDF</button>
            <button type="button" class="kardex-export-excel"><i class="fa-regular fa-file-excel" aria-hidden="true"></i> Exportar Excel</button>
        </div>
    </header>

    <div class="kardex-filter-card" aria-label="Filtros del Kardex">
        <div class="kardex-filter-grid">
            <div class="kardex-filter-field kardex-filter-product">
                <label for="kardex-product">Producto</label>
                <div class="kardex-product-search">
                    <input id="kardex-product" type="search" placeholder="Todos los productos" autocomplete="off" aria-describedby="kardex-filter-status">
                    <div id="kardex-product-results" class="kardex-product-results" role="listbox" aria-label="Productos encontrados" hidden></div>
                </div>
            </div>
            <div class="kardex-filter-field">
                <label for="kardex-category">Categoría</label>
                <select id="kardex-category"><option value="">Todas</option></select>
            </div>
            <div class="kardex-filter-field">
                <label for="kardex-subcategory">Subcategoría</label>
                <select id="kardex-subcategory" disabled><option value="">Por implementar</option></select>
            </div>
            <div class="kardex-filter-field">
                <label for="kardex-start-date">Desde</label>
                <input id="kardex-start-date" type="date">
            </div>
            <div class="kardex-filter-field">
                <label for="kardex-end-date">Hasta (obligatorio)</label>
                <input id="kardex-end-date" type="date" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="kardex-filter-actions">
            <button type="button" class="kardex-clear-button">Limpiar filtros</button>
            <button type="button" class="kardex-search-button">Consultar</button>
        </div>
        <p id="kardex-filter-status" class="kardex-filter-status" aria-live="polite"></p>
    </div>

    <section id="kardex-product-summary" class="kardex-product-summary" aria-label="Resumen del período" hidden>
        <h2 id="kardex-product-summary-title"></h2>
        <div class="kardex-summary-grid">
            <article class="kardex-summary-card">
                <h3>Unidad de inventario</h3>
                <p>—</p>
            </article>
            <article class="kardex-summary-card">
                <h3>Saldo inicial</h3>
                <p>—</p>
            </article>
            <article class="kardex-summary-card">
                <h3>Entradas</h3>
                <p>—</p>
            </article>
            <article class="kardex-summary-card">
                <h3>Salidas</h3>
                <p>—</p>
            </article>
            <article class="kardex-summary-card kardex-summary-final">
                <h3>Saldo final</h3>
                <p>—</p>
            </article>
        </div>
    </section>

    <aside id="kardex-guidance" class="kardex-guidance">
        <i class="fa-regular fa-circle-info" aria-hidden="true"></i>
        <div>
            <strong>Se muestran compras y ventas registradas, además de ajustes cuando existan.</strong>
            <p>Sin un producto seleccionado, se listan los movimientos de todos los productos. La subcategoría aún no está disponible.</p>
        </div>
    </aside>

    <div class="kardex-table-card">
        <div class="kardex-table-scroll">
            <table class="kardex-table">
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Producto</th>
                        <th>Categoría / subcategoría</th>
                        <th>Origen</th>
                        <th>Entradas</th>
                        <th>Salidas</th>
                        <th>Saldo anterior</th>
                        <th>Saldo resultante</th>
                        <th>Responsable</th>
                        <th>Lote</th>
                    </tr>
                </thead>
                <tbody id="kardex-movements">
                    <tr class="kardex-empty-row">
                        <td colspan="10">No hay movimientos para mostrar.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <footer class="kardex-table-footer">
            <span id="kardex-results-count">Mostrando 0 movimientos</span>
            <nav class="kardex-pagination" aria-label="Paginación">
                <button id="kardex-previous-page" type="button" disabled>Anterior</button>
                <span id="kardex-current-page" aria-current="page">1</span>
                <button id="kardex-next-page" type="button" disabled>Siguiente</button>
            </nav>
        </footer>
    </div>
</section>

<?php require_once "Views/Footer.php"; ?>
