document.addEventListener('DOMContentLoaded', function() {
    var rowsElement = document.getElementById('variation-rows');
    if (!rowsElement) return;

    var pageSize = 4;
    var currentPage = 1;
    var filteredRows = [];
    var products = [
        { name: 'Arroz Extra BELL’S Bolsa 750g', code: '200000000005', category: 'Abarrotes', subcategory: 'Arroz', unit: 'unidad' },
        { name: 'Leche evaporada 400 g', code: '077500000001', category: 'Lácteos', subcategory: 'Leches', unit: 'unidad' },
        { name: 'Aceite Vegetal PRIMOR Clásico Botella 1.8L', code: '7750243059381', category: 'Abarrotes', subcategory: 'Aceites', unit: 'unidad' },
        { name: 'Agua San Luis 500 ml', code: '7750182003049', category: 'Bebidas', subcategory: 'Agua', unit: 'unidad' },
        { name: 'Detergente Bolívar 800 g', code: '7750000000456', category: 'Limpieza', subcategory: 'Detergentes', unit: 'unidad' },
        { name: 'Shampoo Sedal 340 ml', code: '7750000000789', category: 'Cuidado Personal', subcategory: 'Shampoo', unit: 'unidad' }
    ];
    var variations = [
        { date: '2026-10-08 10:30', product: 0, type: 'Ajuste negativo', direction: 'Disminución', quantity: 2, reason: 'Diferencia en conteo físico', before: 50, after: 48 },
        { date: '2026-10-12 11:05', product: 1, type: 'Baja por daño', direction: 'Disminución', quantity: 2, reason: 'Latas golpeadas al descargar', before: 18, after: 16 },
        { date: '2026-10-15 16:20', product: 1, type: 'Ajuste positivo', direction: 'Incremento', quantity: 1, reason: 'Diferencia en conteo físico', before: 16, after: 17 },
        { date: '2026-10-18 09:50', product: 0, type: 'Baja por vencimiento', direction: 'Disminución', quantity: 1, reason: 'Producto vencido en góndola', before: 48, after: 47 },
        { date: '2026-10-19 12:15', product: 2, type: 'Ajuste negativo', direction: 'Disminución', quantity: 3, reason: 'Diferencia en conteo físico', before: 34, after: 31 },
        { date: '2026-10-19 14:40', product: 3, type: 'Baja por daño', direction: 'Disminución', quantity: 1, reason: 'Botella con fuga', before: 26, after: 25 },
        { date: '2026-10-19 16:05', product: 4, type: 'Ajuste positivo', direction: 'Incremento', quantity: 2, reason: 'Regularización de inventario', before: 12, after: 14 },
        { date: '2026-10-20 08:20', product: 5, type: 'Baja por vencimiento', direction: 'Disminución', quantity: 1, reason: 'Producto vencido en almacén', before: 20, after: 19 },
        { date: '2026-10-20 09:10', product: 2, type: 'Baja por daño', direction: 'Disminución', quantity: 1, reason: 'Envase deformado', before: 31, after: 30 },
        { date: '2026-10-20 10:25', product: 0, type: 'Ajuste positivo', direction: 'Incremento', quantity: 2, reason: 'Diferencia en conteo físico', before: 47, after: 49 },
        { date: '2026-10-20 11:40', product: 3, type: 'Ajuste negativo', direction: 'Disminución', quantity: 2, reason: 'Unidades faltantes en conteo', before: 25, after: 23 },
        { date: '2026-10-20 13:00', product: 1, type: 'Baja por daño', direction: 'Disminución', quantity: 1, reason: 'Empaque dañado', before: 17, after: 16 },
        { date: '2026-10-20 14:15', product: 4, type: 'Ajuste negativo', direction: 'Disminución', quantity: 1, reason: 'Diferencia en conteo físico', before: 14, after: 13 },
        { date: '2026-10-20 15:30', product: 5, type: 'Ajuste positivo', direction: 'Incremento', quantity: 1, reason: 'Regularización de inventario', before: 19, after: 20 }
    ];

    var productInput = document.getElementById('variation-product');
    var categorySelect = document.getElementById('variation-category');
    var subcategorySelect = document.getElementById('variation-subcategory');
    var startDate = document.getElementById('variation-start-date');
    var endDate = document.getElementById('variation-end-date');
    var typeSelect = document.getElementById('variation-type');
    var directionSelect = document.getElementById('variation-direction');
    var status = document.getElementById('variation-filter-status');
    var pagination = document.getElementById('variation-pagination');
    var summaryToggle = document.getElementById('variation-summary-toggle');
    var productBreakdown = document.getElementById('variation-product-breakdown');
    var allProductsSummary = document.getElementById('variation-all-products-summary');
    var singleProductSummary = document.getElementById('variation-single-product-summary');
    var singleProductTitle = document.getElementById('variation-single-product-title');
    var defaultStartDate = startDate.value;
    var defaultEndDate = endDate.value;

    function initializeFilterOptions() {
        var categories = Array.from(new Set(products.map(function(product) { return product.category; })));
        categories.forEach(function(category) {
            var option = document.createElement('option');
            option.value = category;
            option.textContent = category;
            categorySelect.appendChild(option);
        });
        updateSubcategories();
    }

    function updateSubcategories() {
        var currentValue = subcategorySelect.value;
        subcategorySelect.replaceChildren(new Option('Todas', ''));
        var subcategories = products
            .filter(function(product) { return !categorySelect.value || product.category === categorySelect.value; })
            .map(function(product) { return product.subcategory; });
        Array.from(new Set(subcategories)).forEach(function(subcategory) {
            subcategorySelect.add(new Option(subcategory, subcategory));
        });
        if (subcategories.includes(currentValue)) subcategorySelect.value = currentValue;
    }

    function getFilteredRows() {
        var query = productInput.value.trim().toLocaleLowerCase();
        return variations.filter(function(row) {
            var product = products[row.product];
            var date = row.date.slice(0, 10);
            var matchesProduct = !query || product.name.toLocaleLowerCase().includes(query) || product.code.toLocaleLowerCase().includes(query);
            var matchesCategory = !categorySelect.value || product.category === categorySelect.value;
            var matchesSubcategory = !subcategorySelect.value || product.subcategory === subcategorySelect.value;
            var matchesStart = !startDate.value || date >= startDate.value;
            var matchesEnd = !endDate.value || date <= endDate.value;
            var matchesType = !typeSelect.value || row.type === typeSelect.value;
            var matchesDirection = !directionSelect.value || row.direction === directionSelect.value;
            return matchesProduct && matchesCategory && matchesSubcategory && matchesStart && matchesEnd && matchesType && matchesDirection;
        });
    }

    function renderRows() {
        rowsElement.replaceChildren();
        var pageRows = filteredRows.slice((currentPage - 1) * pageSize, currentPage * pageSize);
        if (!pageRows.length) {
            var emptyRow = document.createElement('tr');
            emptyRow.className = 'variation-empty-row';
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 9;
            emptyCell.textContent = 'No hay variaciones para los filtros seleccionados.';
            emptyRow.appendChild(emptyCell);
            rowsElement.appendChild(emptyRow);
            return;
        }

        pageRows.forEach(function(row) {
            var product = products[row.product];
            var tr = document.createElement('tr');
            [
                row.date,
                product.name + '\n' + product.code,
                product.category + ' / ' + product.subcategory,
                product.unit
            ].forEach(function(value, index) {
                var cell = document.createElement('td');
                if (index === 1) {
                    var productName = document.createElement('strong');
                    productName.textContent = product.name;
                    var productCode = document.createElement('small');
                    productCode.textContent = product.code;
                    cell.append(productName, productCode);
                } else {
                    cell.textContent = value;
                }
                tr.appendChild(cell);
            });

            var typeCell = document.createElement('td');
            var typeBadge = document.createElement('span');
            typeBadge.className = 'variation-type-badge ' + (row.type.indexOf('Baja') === 0 ? 'is-writeoff' : 'is-adjustment');
            typeBadge.textContent = row.type;
            typeCell.appendChild(typeBadge);
            tr.appendChild(typeCell);

            var directionCell = document.createElement('td');
            directionCell.className = row.direction === 'Incremento' ? 'variation-increase' : 'variation-decrease';
            directionCell.textContent = row.direction;
            tr.appendChild(directionCell);

            [row.quantity, row.reason, row.before + ' → ' + row.after].forEach(function(value) {
                var cell = document.createElement('td');
                cell.textContent = value;
                tr.appendChild(cell);
            });
            rowsElement.appendChild(tr);
        });
    }

    function renderSummary() {
        document.getElementById('variation-total-operations').textContent = String(filteredRows.length);
        document.getElementById('variation-product-count').textContent = String(new Set(filteredRows.map(function(row) { return row.product; })).size);
        var productIndexes = Array.from(new Set(filteredRows.map(function(row) { return row.product; })));
        var isSingleProduct = productIndexes.length === 1;
        allProductsSummary.hidden = isSingleProduct;
        singleProductSummary.hidden = !isSingleProduct;
        productBreakdown.replaceChildren();

        if (isSingleProduct) {
            var product = products[productIndexes[0]];
            var increases = 0;
            var decreases = 0;
            filteredRows.forEach(function(row) {
                if (row.direction === 'Incremento') increases += row.quantity;
                else decreases -= row.quantity;
            });
            singleProductTitle.textContent = 'Resumen · ' + product.name;
            document.getElementById('variation-single-product-operations').textContent = String(filteredRows.length);
            document.getElementById('variation-single-product-increases').textContent = '+' + increases;
            document.getElementById('variation-single-product-decreases').textContent = String(decreases).replace('-', '−');
            return;
        }

        var counts = new Map();
        filteredRows.forEach(function(row) {
            var current = counts.get(row.product) || { operations: 0, entries: 0, exits: 0 };
            current.operations++;
            if (row.direction === 'Incremento') current.entries += row.quantity;
            else current.exits -= row.quantity;
            counts.set(row.product, current);
        });

        var table = document.createElement('table');
        table.className = 'variation-breakdown-table';
        var head = document.createElement('thead');
        var headerRow = document.createElement('tr');
        ['Producto', 'Operaciones', 'Incrementos', 'Disminuciones'].forEach(function(label) {
            var cell = document.createElement('th');
            cell.textContent = label;
            headerRow.appendChild(cell);
        });
        head.appendChild(headerRow);
        table.appendChild(head);
        var body = document.createElement('tbody');

        counts.forEach(function(count, productIndex) {
            var product = products[productIndex];
            var row = document.createElement('tr');
            var nameCell = document.createElement('td');
            var name = document.createElement('strong');
            name.textContent = product.name;
            var code = document.createElement('small');
            code.textContent = product.code;
            nameCell.append(name, code);
            row.appendChild(nameCell);
            [count.operations, count.entries, count.exits].forEach(function(value, index) {
                var cell = document.createElement('td');
                cell.textContent = index === 2 && value > 0 ? '-' + value : String(value);
                if (index === 1) cell.className = 'variation-breakdown-increase';
                if (index === 2) cell.className = 'variation-breakdown-decrease';
                row.appendChild(cell);
            });
            body.appendChild(row);
        });
        table.appendChild(body);
        productBreakdown.appendChild(table);
    }

    function renderPagination() {
        pagination.replaceChildren();
        var totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        currentPage = Math.min(currentPage, totalPages);
        function addButton(label, page, disabled, active) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.disabled = disabled;
            if (active) {
                button.classList.add('is-current');
                button.setAttribute('aria-current', 'page');
            }
            button.addEventListener('click', function() {
                currentPage = page;
                render();
            });
            pagination.appendChild(button);
        }
        addButton('Anterior', Math.max(1, currentPage - 1), currentPage === 1, false);
        for (var page = 1; page <= totalPages; page++) {
            addButton(String(page), page, false, page === currentPage);
        }
        addButton('Siguiente', Math.min(totalPages, currentPage + 1), currentPage === totalPages, false);
        var first = filteredRows.length ? (currentPage - 1) * pageSize + 1 : 0;
        var last = filteredRows.length ? Math.min(currentPage * pageSize, filteredRows.length) : 0;
        document.getElementById('variation-page-status').textContent = 'Mostrando ' + first + '–' + last + ' de ' + filteredRows.length + ' operaciones';
    }

    function render() {
        renderRows();
        renderSummary();
        renderPagination();
    }

    document.getElementById('variation-apply').addEventListener('click', function() {
        if (!startDate.value || !endDate.value) {
            status.textContent = 'Selecciona las fechas inicial y final para consultar.';
            status.classList.add('is-error');
            (startDate.value ? endDate : startDate).focus();
            return;
        }
        if (startDate.value && endDate.value && startDate.value > endDate.value) {
            status.textContent = 'La fecha inicial no puede ser posterior a la fecha final.';
            status.classList.add('is-error');
            startDate.focus();
            return;
        }
        status.textContent = '';
        status.classList.remove('is-error');
        currentPage = 1;
        filteredRows = getFilteredRows();
        render();
    });

    document.getElementById('variation-clear').addEventListener('click', function() {
        productInput.value = '';
        categorySelect.value = '';
        updateSubcategories();
        subcategorySelect.value = '';
        startDate.value = defaultStartDate;
        endDate.value = defaultEndDate;
        typeSelect.value = '';
        directionSelect.value = '';
        status.textContent = '';
        status.classList.remove('is-error');
        currentPage = 1;
        filteredRows = variations.slice();
        render();
    });

    categorySelect.addEventListener('change', updateSubcategories);
    summaryToggle.addEventListener('click', function() {
        var expanded = summaryToggle.getAttribute('aria-expanded') === 'true';
        summaryToggle.setAttribute('aria-expanded', String(!expanded));
        productBreakdown.hidden = expanded;
        summaryToggle.querySelector('i').classList.toggle('is-rotated', !expanded);
    });

    initializeFilterOptions();
    filteredRows = variations.slice();
    render();
});
