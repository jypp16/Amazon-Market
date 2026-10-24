document.addEventListener('DOMContentLoaded', function() {
    var productInput = document.getElementById('kardex-product');
    if (!productInput) return;

    var productResults = document.getElementById('kardex-product-results');
    var categorySelect = document.getElementById('kardex-category');
    var subcategorySelect = document.getElementById('kardex-subcategory');
    var status = document.getElementById('kardex-filter-status');
    var startDate = document.getElementById('kardex-start-date');
    var endDate = document.getElementById('kardex-end-date');
    var clearButton = document.querySelector('.kardex-clear-button');
    var consultButton = document.querySelector('.kardex-search-button');
    var movementsBody = document.getElementById('kardex-movements');
    var resultsCount = document.getElementById('kardex-results-count');
    var previousPageButton = document.getElementById('kardex-previous-page');
    var nextPageButton = document.getElementById('kardex-next-page');
    var currentPageLabel = document.getElementById('kardex-current-page');
    var productSummary = document.getElementById('kardex-product-summary');
    var productSummaryTitle = document.getElementById('kardex-product-summary-title');
    var guidance = document.getElementById('kardex-guidance');
    var selectedProduct = null;
    var searchSequence = 0;
    var searchTimer;
    var currentPage = 1;
    var totalPages = 1;
    var movementRequestSequence = 0;
    var categoryLoadError = '';
    var defaultStartDate = startDate.value;
    var defaultEndDate = endDate.value;

    function setStatus(message, isError) {
        status.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
    }

    function updateProductSummary(product) {
        productSummary.hidden = !product;
        productSummaryTitle.textContent = product ? 'Resumen del período completo · ' + (product.nombre || product.codigo_barra || '') : '';
    }

    function showResultsMessage(message, isError) {
        productResults.replaceChildren();
        var result = document.createElement('div');
        result.className = 'kardex-product-result-message' + (isError ? ' is-error' : '');
        result.setAttribute('role', isError ? 'alert' : 'status');
        result.textContent = message;
        productResults.appendChild(result);
        productResults.hidden = false;
    }

    function loadCategories() {
        Api.get('categorias').then(function(response) {
            if (!response || !response.ok || !response.data || response.data.status !== true) {
                var message = (response && response.data && response.data.message) || 'No se pudieron cargar las categorías.';
                categoryLoadError = message;
                setStatus(message, true);
                return;
            }
            categoryLoadError = '';
            var categories = Array.isArray(response.data.data) ? response.data.data : [];
            categories.forEach(function(category) {
                var option = document.createElement('option');
                option.value = category.id_categoria;
                option.textContent = category.nombre;
                categorySelect.appendChild(option);
            });
        }).catch(function(error) {
            categoryLoadError = 'Error al cargar las categorías: ' + error.message;
            setStatus(categoryLoadError, true);
        });
    }

    function renderProducts(products) {
        productResults.replaceChildren();
        if (!products.length) {
            showResultsMessage('No se encontraron productos.');
            return;
        }
        products.forEach(function(product) {
            var option = document.createElement('button');
            option.type = 'button';
            option.className = 'kardex-product-result';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = (product.codigo_barra || '') + ' · ' + (product.nombre || '');
            option.addEventListener('click', function() {
                selectedProduct = product;
                productInput.value = product.nombre || '';
                updateProductSummary(null);
                if (product.id_categoria) categorySelect.value = String(product.id_categoria);
                productResults.replaceChildren();
                productResults.hidden = true;
                setStatus('Producto seleccionado: ' + (product.nombre || product.codigo_barra || ''), false);
            });
            productResults.appendChild(option);
        });
        productResults.hidden = false;
    }

    function renderMovements(movements) {
        movementsBody.replaceChildren();
        if (!movements.length) {
            var emptyRow = document.createElement('tr');
            emptyRow.className = 'kardex-empty-row';
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 10;
            emptyCell.textContent = 'No hay movimientos para los filtros seleccionados.';
            emptyRow.appendChild(emptyCell);
            movementsBody.appendChild(emptyRow);
            return;
        }

        var numberFormat = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 });
        movements.forEach(function(movement) {
            var row = document.createElement('tr');
            var date = document.createElement('td');
            date.textContent = movement.fecha_movimiento || '—';
            row.appendChild(date);

            var product = document.createElement('td');
            var productName = document.createElement('strong');
            productName.textContent = movement.producto || 'Producto';
            var productCode = document.createElement('small');
            productCode.textContent = movement.codigo_barra || '';
            product.appendChild(productName);
            product.appendChild(productCode);
            row.appendChild(product);

            var category = document.createElement('td');
            category.textContent = movement.categoria || 'Sin categoría';
            row.appendChild(category);

            var origin = document.createElement('td');
            origin.textContent = movement.origen || '—';
            row.appendChild(origin);

            var direction = document.createElement('td');
            var directionBadge = document.createElement('span');
            var isEntry = movement.sentido === 'Entrada';
            directionBadge.className = 'kardex-direction ' + (isEntry ? 'kardex-direction-entry' : 'kardex-direction-exit');
            var directionLabel = document.createElement('span');
            directionLabel.textContent = isEntry ? 'Entrada' : 'Salida';
            directionBadge.appendChild(directionLabel);
            direction.appendChild(directionBadge);
            row.appendChild(direction);

            var amount = document.createElement('td');
            amount.className = isEntry ? 'kardex-amount-entry' : 'kardex-amount-exit';
            amount.textContent = (isEntry ? '+' : '−') + numberFormat.format(Number(movement.cantidad) || 0);
            row.appendChild(amount);

            [movement.saldo_anterior, movement.saldo_resultante].forEach(function(balance) {
                var cell = document.createElement('td');
                cell.textContent = numberFormat.format(Number(balance) || 0);
                row.appendChild(cell);
            });

            var responsible = document.createElement('td');
            responsible.textContent = movement.responsable || 'No disponible';
            row.appendChild(responsible);

            var lot = document.createElement('td');
            lot.textContent = movement.lote || '—';
            row.appendChild(lot);
            movementsBody.appendChild(row);
        });
    }

    async function loadMovements() {
        var requestSequence = ++movementRequestSequence;
        var params = new URLSearchParams({ pagina: String(currentPage) });
        if (startDate.value) params.set('desde', startDate.value);
        if (endDate.value) params.set('hasta', endDate.value);
        if (selectedProduct) params.set('id_producto', String(selectedProduct.id_producto));
        if (categorySelect.value) params.set('id_categoria', categorySelect.value);

        movementsBody.replaceChildren();
        var loadingRow = document.createElement('tr');
        loadingRow.className = 'kardex-empty-row';
        var loadingCell = document.createElement('td');
        loadingCell.colSpan = 10;
        loadingCell.textContent = 'Cargando movimientos…';
        loadingRow.appendChild(loadingCell);
        movementsBody.appendChild(loadingRow);
        resultsCount.textContent = 'Cargando movimientos…';

        try {
            var response = await Api.get('reportes/kardex?' + params.toString());
            if (requestSequence !== movementRequestSequence) return;
            if (!response) return;
            if (!response.ok || !response.data || response.data.status !== true) {
                throw new Error((response.data && response.data.message) || 'No se pudieron cargar los movimientos.');
            }

            var data = response.data.data || {};
            var movements = Array.isArray(data.movimientos) ? data.movimientos : [];
            var pagination = data.paginacion || {};
            currentPage = Number(pagination.pagina) || 1;
            totalPages = Number(pagination.total_paginas) || 1;
            renderMovements(movements);
            var pageSize = Number(pagination.por_pagina) || 10;
            var firstResult = movements.length ? (currentPage - 1) * pageSize + 1 : 0;
            var lastResult = movements.length ? firstResult + movements.length - 1 : 0;
            resultsCount.textContent = 'Mostrando ' + (movements.length ? firstResult + '–' + lastResult : '0') + ' de ' + (Number(pagination.total) || 0) + ' movimientos';
            currentPageLabel.textContent = String(currentPage);
            previousPageButton.disabled = currentPage <= 1;
            nextPageButton.disabled = currentPage >= totalPages;
            setStatus(categoryLoadError, Boolean(categoryLoadError));
        } catch (error) {
            if (requestSequence !== movementRequestSequence) return;
            movementsBody.replaceChildren();
            var errorRow = document.createElement('tr');
            errorRow.className = 'kardex-empty-row';
            var errorCell = document.createElement('td');
            errorCell.colSpan = 10;
            errorCell.textContent = 'No se pudieron cargar los movimientos.';
            errorRow.appendChild(errorCell);
            movementsBody.appendChild(errorRow);
            resultsCount.textContent = 'No se pudieron cargar los movimientos.';
            currentPageLabel.textContent = '1';
            previousPageButton.disabled = true;
            nextPageButton.disabled = true;
            setStatus(error.message, true);
        }
    }

    async function searchProducts(query, sequence) {
        showResultsMessage('Buscando productos…');
        var params = new URLSearchParams({ q: query, limit: '6' });
        if (categorySelect.value) params.set('categoria', categorySelect.value);
        try {
            var response = await Api.get('productos?' + params.toString());
            if (sequence !== searchSequence) return;
            if (!response || !response.ok || !response.data || response.data.status !== true) {
                var message = (response && response.data && response.data.message) || 'No se pudieron buscar los productos.';
                showResultsMessage(message, true);
                return;
            }
            renderProducts(Array.isArray(response.data.data) ? response.data.data.slice(0, 6) : []);
        } catch (error) {
            if (sequence === searchSequence) {
                showResultsMessage('Error al buscar productos: ' + error.message, true);
            }
        }
    }

    productInput.addEventListener('input', function() {
        window.clearTimeout(searchTimer);
        selectedProduct = null;
        updateProductSummary(null);
        var query = productInput.value.trim();
        var sequence = ++searchSequence;
        if (!query) {
            productResults.replaceChildren();
            productResults.hidden = true;
            setStatus('', false);
            return;
        }
        searchTimer = window.setTimeout(function() {
            searchProducts(query, sequence);
        }, 250);
    });

    productInput.addEventListener('focus', function() {
        if (productInput.value.trim() && productResults.childElementCount) productResults.hidden = false;
    });

    categorySelect.addEventListener('change', function() {
        selectedProduct = null;
        updateProductSummary(null);
        setStatus(categorySelect.value ? 'Filtro de categoría seleccionado.' : '', false);
        if (productInput.value.trim()) {
            window.clearTimeout(searchTimer);
            var query = productInput.value.trim();
            var sequence = ++searchSequence;
            searchTimer = window.setTimeout(function() {
                searchProducts(query, sequence);
            }, 0);
        }
    });

    clearButton.addEventListener('click', function() {
        window.clearTimeout(searchTimer);
        searchSequence++;
        productInput.value = '';
        categorySelect.value = '';
        subcategorySelect.value = '';
        startDate.value = defaultStartDate;
        endDate.value = defaultEndDate;
        selectedProduct = null;
        updateProductSummary(null);
        guidance.hidden = false;
        productResults.replaceChildren();
        productResults.hidden = true;
        setStatus('Filtros limpiados.', false);
        currentPage = 1;
        loadMovements();
    });

    consultButton.addEventListener('click', function() {
        if (productInput.value.trim() && !selectedProduct) {
            setStatus('Selecciona un producto de las sugerencias para aplicar el filtro.', true);
            productInput.focus();
            return;
        }
        if (startDate.value && endDate.value && startDate.value > endDate.value) {
            setStatus('La fecha inicial no puede ser posterior a la fecha final.', true);
            startDate.focus();
            return;
        }
        guidance.hidden = true;
        updateProductSummary(selectedProduct);
        productResults.hidden = true;
        currentPage = 1;
        loadMovements();
    });

    previousPageButton.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadMovements();
        }
    });

    nextPageButton.addEventListener('click', function() {
        if (currentPage < totalPages) {
            currentPage++;
            loadMovements();
        }
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.kardex-product-search')) productResults.hidden = true;
    });

    loadCategories();
    loadMovements();
});
