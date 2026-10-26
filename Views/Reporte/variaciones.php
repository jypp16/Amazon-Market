<?php require_once "Views/Header.php"; ?>

<section class="variation-page" aria-labelledby="variation-title">
    <header class="kardex-page-heading variation-heading">
        <div>
            <h1 id="variation-title">Reporte de variaciones</h1>
            <p>Bajas por daño o vencimiento y ajustes de conteo. Consulta de solo lectura.</p>
        </div>
        <div class="kardex-export-actions">
            <button type="button" class="kardex-export-pdf" disabled><i class="fa-regular fa-file-pdf" aria-hidden="true"></i> Exportar PDF</button>
            <button type="button" class="kardex-export-excel" disabled><i class="fa-regular fa-file-excel" aria-hidden="true"></i> Exportar Excel</button>
        </div>
    </header>
    <p id="kardex-export-status" class="kardex-export-status" role="status" aria-live="polite">Las exportaciones se habilitan cuando hay variaciones para la consulta.</p>

    <section class="kardex-filter-card variation-filter-card" aria-label="Filtros de variaciones">
        <div class="variation-filter-grid">
            <div class="kardex-filter-field variation-filter-product">
                <label for="variation-product">Producto</label>
                <input id="variation-product" type="search" placeholder="Todos los productos" autocomplete="off">
            </div>
            <div class="kardex-filter-field">
                <label for="variation-category">Categoría</label>
                <select id="variation-category"><option value="">Todas</option></select>
            </div>
            <div class="kardex-filter-field">
                <label for="variation-subcategory">Subcategoría</label>
                <select id="variation-subcategory"><option value="">Todas</option></select>
            </div>
            <div class="kardex-filter-field">
                <label for="variation-start-date">Desde (obligatorio)</label>
                <input id="variation-start-date" type="date" value="<?= date('Y-m-01') ?>">
            </div>
            <div class="kardex-filter-field">
                <label for="variation-end-date">Hasta (obligatorio)</label>
                <input id="variation-end-date" type="date" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="kardex-filter-field">
                <label for="variation-type">Tipo de variación</label>
                <select id="variation-type">
                    <option value="">Todas</option>
                    <option value="Ajuste negativo">Ajuste negativo</option>
                    <option value="Ajuste positivo">Ajuste positivo</option>
                    <option value="Baja por daño">Baja por daño</option>
                    <option value="Baja por vencimiento">Baja por vencimiento</option>
                </select>
            </div>
            <div class="kardex-filter-field">
                <label for="variation-direction">Sentido</label>
                <select id="variation-direction">
                    <option value="">Todos</option>
                    <option value="Incremento">Entradas</option>
                    <option value="Disminución">Salidas</option>
                </select>
            </div>
        </div>
        <div class="kardex-filter-actions">
            <button id="variation-clear" type="button">Limpiar filtros</button>
            <button id="variation-apply" class="kardex-search-button" type="button">Consultar</button>
        </div>
        <p id="variation-filter-status" class="kardex-filter-status" aria-live="polite"></p>
    </section>

    <section class="variation-summary-card" aria-label="Resumen de variaciones">
        <div id="variation-all-products-summary" class="variation-summary-content">
            <div class="variation-summary-stats">
                <div><span>Total de operaciones</span><strong id="variation-total-operations">0</strong></div>
                <div><span>Productos con variaciones</span><strong id="variation-product-count">0</strong></div>
            </div>
            <button id="variation-summary-toggle" class="variation-summary-toggle" type="button" aria-expanded="false" aria-controls="variation-product-breakdown">
                Ver resumen por producto <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div id="variation-product-breakdown" class="variation-product-breakdown" hidden></div>
        </div>
        <div id="variation-single-product-summary" class="variation-single-product-summary" hidden>
            <h2 id="variation-single-product-title"></h2>
            <div class="variation-single-product-cards">
                <article>
                    <span>Total de operaciones</span>
                    <strong id="variation-single-product-operations">0</strong>
                </article>
                <article class="variation-single-product-increases">
                    <span>Incrementos</span>
                    <strong id="variation-single-product-increases">+0</strong>
                </article>
                <article class="variation-single-product-decreases">
                    <span>Disminuciones</span>
                    <strong id="variation-single-product-decreases">−0</strong>
                </article>
            </div>
        </div>
    </section>

    <section class="variation-table-card" aria-label="Operaciones de variación">
        <div class="variation-table-scroll">
            <table class="variation-table">
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Producto</th>
                        <th>Categoría / subcategoría</th>
                        <th>Unidad</th>
                        <th>Tipo</th>
                        <th>Entradas</th>
                        <th>Salidas</th>
                        <th>Motivo o justificación</th>
                        <th>Saldo anterior → resultante</th>
                    </tr>
                </thead>
                <tbody id="variation-rows"></tbody>
            </table>
        </div>
        <footer class="variation-table-footer">
            <span id="variation-page-status"></span>
            <nav id="variation-pagination" class="variation-pagination" aria-label="Paginación"></nav>
        </footer>
    </section>
</section>

<?php require_once "Views/Footer.php"; ?>
