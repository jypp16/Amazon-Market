<?php require_once "Views/Header.php"; ?>

<section id="mov-list" class="mov-page" aria-label="Movimientos de inventario">
    <form id="mov-filtros" class="mov-card mov-filters" novalidate>
        <div class="mov-field mov-field-search">
            <label for="mov-q">Buscar</label>
            <div class="mov-input-icon">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="mov-q" placeholder="Nombre o código de barras" autocomplete="off">
            </div>
        </div>
        <div class="mov-field">
            <label for="mov-tipo">Tipo</label>
            <select id="mov-tipo">
                <option value="">Todos los tipos</option>
                <option value="baja_danio">Baja por daño</option>
                <option value="baja_vencimiento">Baja por vencimiento</option>
                <option value="ajuste_positivo">Ajuste positivo</option>
                <option value="ajuste_negativo">Ajuste negativo</option>
            </select>
        </div>
        <div class="mov-field">
            <label for="mov-desde">Desde</label>
            <input type="date" id="mov-desde">
        </div>
        <div class="mov-field">
            <label for="mov-hasta">Hasta</label>
            <input type="date" id="mov-hasta">
        </div>
        <button type="button" id="mov-limpiar" class="mov-btn">Limpiar</button>
        <button type="submit" class="mov-btn mov-btn-gold"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar</button>
        <p id="mov-fecha-error" class="mov-field-error mov-filters-error" role="alert" hidden></p>
    </form>

    <div class="mov-card mov-table-card">
        <div id="mov-status" class="mov-status" aria-live="polite" hidden></div>
        <div id="mov-table-wrap" class="table-responsive">
            <table class="mov-table">
                <thead>
                    <tr>
                        <th scope="col">N.°</th>
                        <th scope="col">Fecha y hora</th>
                        <th scope="col">Producto</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Cantidad vigente</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Acción</th>
                    </tr>
                </thead>
                <tbody id="mov-tbody"></tbody>
            </table>
        </div>
        <footer class="mov-table-footer">
            <span id="mov-resumen"></span>
            <nav id="mov-pager" class="mov-pager" aria-label="Paginación"></nav>
        </footer>
    </div>

    <aside class="mov-note" role="note">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <p>Al volver desde el detalle se conservan la búsqueda, los filtros y la página.</p>
    </aside>
</section>

<?php require_once "Views/Footer.php"; ?>