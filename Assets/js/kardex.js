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
    var exportPdfBtn = document.querySelector('.kardex-export-pdf');
    var exportExcelBtn = document.querySelector('.kardex-export-excel');

    var selectedProduct = null;
    var searchSequence = 0;
    var searchTimer;
    var currentPage = 1;
    var totalPages = 1;
    var movementRequestSequence = 0;
    var categoryLoadError = '';
    var categoriesData = {};
    var currentConsultaRef = null;
    var appliedFilters = null;
    var isExportableCurrent = false;

    function exportarKardexPdf() {
        if (!isExportableCurrent || !currentConsultaRef || !appliedFilters) {
            setStatus('Primero realiza una consulta exportable de Kardex.', true);
            return;
        }

        var params = new URLSearchParams({
            tipo: 'kardex',
            referencia_consulta: currentConsultaRef,
            fecha_inicio: appliedFilters.fecha_inicio,
            fecha_fin: appliedFilters.fecha_fin
        });
        ['id_producto', 'id_categoria', 'id_subcategoria'].forEach(function(key) {
            if (appliedFilters[key]) params.set(key, appliedFilters[key]);
        });

        exportPdfBtn.disabled = true;
        setStatus('Generando el PDF del Kardex…', false);
        Api.download('reportes/exportar-pdf?' + params.toString(), 'Reporte_Kardex.pdf')
            .then(function(result) {
                if (!result || !result.ok) {
                    var errorData = (result && result.data) || {};
                    if (['QUERY_REFERENCE_EXPIRED', 'QUERY_FILTERS_MISMATCH'].includes(errorData.code)) {
                        currentConsultaRef = null;
                        isExportableCurrent = false;
                        if (exportExcelBtn) exportExcelBtn.disabled = true;
                    }
                    setStatus(errorData.message || 'No se pudo descargar el PDF del Kardex.', true);
                    return;
                }
                setStatus('El PDF del Kardex se descargó correctamente.', false);
            })
            .catch(function() {
                setStatus('No se pudo conectar con el servidor para descargar el PDF del Kardex.', true);
            })
            .finally(function() {
                exportPdfBtn.disabled = !isExportableCurrent;
                if (exportExcelBtn && !isExportableCurrent) exportExcelBtn.disabled = true;
            });
    }

    var defaultStartDate = startDate.value || (new Date().toISOString().slice(0, 7) + '-01');
    var defaultEndDate = endDate.value || new Date().toISOString().slice(0, 10);
    startDate.value = defaultStartDate;
    endDate.value = defaultEndDate;

    if (exportPdfBtn) exportPdfBtn.addEventListener('click', exportarKardexPdf);

    var numberFormat = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 });

    function setStatus(message, isError) {
        status.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
    }

    function updateSummaryCards(resumen) {
        if (!resumen || !resumen.aplicable) {
            productSummary.hidden = true;
            guidance.hidden = false;
            return;
        }

        productSummary.hidden = false;
        guidance.hidden = true;
        productSummaryTitle.textContent = 'Resumen del período completo · ' + (resumen.producto || (selectedProduct ? selectedProduct.nombre : ''));

        var summaryCards = productSummary.querySelectorAll('.kardex-summary-card p');
        if (summaryCards.length >= 5) {
            summaryCards[0].textContent = resumen.unidad_inventario || 'UND';
            summaryCards[1].textContent = numberFormat.format(Number(resumen.saldo_inicial) || 0);
            summaryCards[2].textContent = numberFormat.format(Number(resumen.total_entradas) || 0);
            summaryCards[3].textContent = numberFormat.format(Number(resumen.total_salidas) || 0);
            summaryCards[4].textContent = numberFormat.format(Number(resumen.saldo_final) || 0);
        }
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
        Api.get('categorias?modo=reporte').then(function(response) {
            if (!response || !response.ok || !response.data || response.data.status !== true) {
                var message = (response && response.data && response.data.message) || 'No se pudieron cargar las categorías.';
                categoryLoadError = message;
                setStatus(message, true);
                return;
            }
            categoryLoadError = '';
            var categories = Array.isArray(response.data.data) ? response.data.data : [];
            categories.forEach(function(category) {
                categoriesData[category.id_categoria] = category;
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

    function updateSubcategories(idCategoria) {
        subcategorySelect.replaceChildren();
        var defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'Todas';
        subcategorySelect.appendChild(defaultOption);

        if (!idCategoria || !categoriesData[idCategoria]) {
            subcategorySelect.disabled = true;
            return;
        }

        var subcats = categoriesData[idCategoria].subcategorias || [];
        if (subcats.length > 0) {
            subcats.forEach(function(sc) {
                var option = document.createElement('option');
                option.value = sc.id_subcategoria;
                option.textContent = sc.nombre;
                subcategorySelect.appendChild(option);
            });
            subcategorySelect.disabled = false;
        } else {
            subcategorySelect.disabled = true;
        }
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

            var estadoLabel = (product.estado === 0 || product.etiqueta_estado === 'Inactivo') ? ' [Inactivo]' : '';
            option.textContent = (product.codigo_barra || '') + ' · ' + (product.nombre || '') + estadoLabel;

            option.addEventListener('click', function() {
                selectedProduct = product;
                productInput.value = product.nombre || product.codigo_barra || '';
                if (product.id_categoria) {
                    categorySelect.value = String(product.id_categoria);
                    updateSubcategories(product.id_categoria);
                    if (product.id_subcategoria) {
                        subcategorySelect.value = String(product.id_subcategoria);
                    }
                }
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

        movements.forEach(function(movement) {
            var row = document.createElement('tr');

            // 1. Fecha y hora
            var date = document.createElement('td');
            date.textContent = movement.fecha_hora || movement.fecha_movimiento || '—';
            row.appendChild(date);

            // 2. Producto
            var productCell = document.createElement('td');
            var productName = document.createElement('strong');
            productName.textContent = movement.producto_nombre || movement.producto || 'Producto';
            var productCode = document.createElement('small');
            productCode.textContent = movement.codigo_barra || '';
            productCell.appendChild(productName);
            productCell.appendChild(document.createElement('br'));
            productCell.appendChild(productCode);
            row.appendChild(productCell);

            // 3. Categoría / subcategoría
            var catCell = document.createElement('td');
            var catName = movement.categoria_nombre || movement.categoria || 'Sin categoría';
            var subcatName = movement.subcategoria_nombre || 'General';
            catCell.textContent = catName + ' / ' + subcatName;
            row.appendChild(catCell);

            // 4. Origen / tipo movimiento
            var origin = document.createElement('td');
            origin.textContent = movement.origen || movement.tipo_movimiento || '—';
            row.appendChild(origin);

            // 5 & 6. Entradas y Salidas
            var entradaVal = movement.entrada !== null && movement.entrada !== undefined ? movement.entrada : (movement.entradas || 0);
            var salidaVal = movement.salida !== null && movement.salida !== undefined ? movement.salida : (movement.salidas || 0);

            var entryCell = document.createElement('td');
            entryCell.className = 'kardex-amount-entry';
            entryCell.textContent = Number(entradaVal) > 0 ? numberFormat.format(Number(entradaVal)) : '—';
            row.appendChild(entryCell);

            var exitCell = document.createElement('td');
            exitCell.className = 'kardex-amount-exit';
            exitCell.textContent = Number(salidaVal) > 0 ? numberFormat.format(Number(salidaVal)) : '—';
            row.appendChild(exitCell);

            // 7 & 8. Saldo anterior y Saldo resultante
            var prevBalanceCell = document.createElement('td');
            prevBalanceCell.textContent = numberFormat.format(Number(movement.saldo_anterior) || 0);
            row.appendChild(prevBalanceCell);

            var resultBalanceCell = document.createElement('td');
            resultBalanceCell.textContent = numberFormat.format(Number(movement.saldo_resultante) || 0);
            row.appendChild(resultBalanceCell);

            // 9. Responsable
            var responsible = document.createElement('td');
            responsible.textContent = movement.responsable || 'No disponible';
            row.appendChild(responsible);

            // 10. Lote
            var lot = document.createElement('td');
            lot.textContent = movement.lote || 'Sin lote';
            row.appendChild(lot);

            movementsBody.appendChild(row);
        });
    }

    async function loadMovements() {
        var requestSequence = ++movementRequestSequence;
        var params = new URLSearchParams({
            page: String(currentPage),
            limit: '10'
        });

        if (!appliedFilters) {
            appliedFilters = {
                fecha_inicio: startDate.value,
                fecha_fin: endDate.value,
                id_producto: selectedProduct ? String(selectedProduct.id_producto) : '',
                id_categoria: categorySelect.value,
                id_subcategoria: subcategorySelect.value
            };
        }
        Object.keys(appliedFilters).forEach(function(key) {
            if (appliedFilters[key]) params.set(key, appliedFilters[key]);
        });
        if (currentConsultaRef) params.set('referencia_consulta', currentConsultaRef);

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
                var errData = response.data || {};
                if (errData.code === 'QUERY_REFERENCE_EXPIRED' || errData.code === 'QUERY_FILTERS_MISMATCH') {
                    currentConsultaRef = null;
                }
                throw new Error(errData.message || 'No se pudieron cargar los movimientos.');
            }

            var data = response.data.data || {};
            currentConsultaRef = data.referencia_consulta || null;
            isExportableCurrent = Boolean(data.exportable);

            var movements = Array.isArray(data.items) ? data.items : (Array.isArray(data.movimientos) ? data.movimientos : []);
            var pagination = data.pagination || data.paginacion || {};
            currentPage = Number(pagination.page || pagination.pagina) || 1;
            totalPages = Number(pagination.pages || pagination.total_paginas) || 1;

            renderMovements(movements);
            updateSummaryCards(data.resumen_producto);

            var pageSize = Number(pagination.limit || pagination.por_pagina) || 10;
            var totalCount = Number(pagination.total) || 0;
            var firstResult = movements.length ? (currentPage - 1) * pageSize + 1 : 0;
            var lastResult = movements.length ? firstResult + movements.length - 1 : 0;

            resultsCount.textContent = 'Mostrando ' + (movements.length ? firstResult + '–' + lastResult : '0') + ' de ' + totalCount + ' movimientos';
            currentPageLabel.textContent = String(currentPage);
            previousPageButton.disabled = currentPage <= 1;
            nextPageButton.disabled = currentPage >= totalPages;

            if (exportPdfBtn) exportPdfBtn.disabled = !isExportableCurrent;
            if (exportExcelBtn) exportExcelBtn.disabled = !isExportableCurrent;

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
            if (exportPdfBtn) exportPdfBtn.disabled = true;
            if (exportExcelBtn) exportExcelBtn.disabled = true;
            setStatus(error.message, true);
        }
    }

    async function searchProducts(query, sequence) {
        showResultsMessage('Buscando productos…');
        var params = new URLSearchParams({
            modo: 'reporte',
            q: query,
            limit: '8'
        });
        if (categorySelect.value) params.set('id_categoria', categorySelect.value);

        try {
            var response = await Api.get('productos?' + params.toString());
            if (sequence !== searchSequence) return;
            if (!response || !response.ok || !response.data || response.data.status !== true) {
                var message = (response && response.data && response.data.message) || 'No se pudieron buscar los productos.';
                showResultsMessage(message, true);
                return;
            }
            renderProducts(Array.isArray(response.data.data) ? response.data.data.slice(0, 8) : []);
        } catch (error) {
            if (sequence === searchSequence) {
                showResultsMessage('Error al buscar productos: ' + error.message, true);
            }
        }
    }

    productInput.addEventListener('input', function() {
        window.clearTimeout(searchTimer);
        selectedProduct = null;
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
        if (productInput.value.trim() && productResults.childElementCount) {
            productResults.hidden = false;
        }
    });

    categorySelect.addEventListener('change', function() {
        updateSubcategories(categorySelect.value);
        setStatus(categorySelect.value ? 'Categoría seleccionada.' : '', false);
        if (productInput.value.trim()) {
            window.clearTimeout(searchTimer);
            var query = productInput.value.trim();
            var sequence = ++searchSequence;
            searchTimer = window.setTimeout(function() {
                searchProducts(query, sequence);
            }, 0);
        }
    });

    subcategorySelect.addEventListener('change', function() {
        setStatus(subcategorySelect.value ? 'Subcategoría seleccionada.' : '', false);
    });

    startDate.addEventListener('change', function() {
        setStatus('Filtros pendientes. Pulsa Consultar para aplicarlos.', false);
    });

    endDate.addEventListener('change', function() {
        setStatus('Filtros pendientes. Pulsa Consultar para aplicarlos.', false);
    });

    clearButton.addEventListener('click', function() {
        window.clearTimeout(searchTimer);
        searchSequence++;
        productInput.value = '';
        categorySelect.value = '';
        updateSubcategories('');
        startDate.value = defaultStartDate;
        endDate.value = defaultEndDate;
        selectedProduct = null;
        currentConsultaRef = null;
        appliedFilters = null;
        updateSummaryCards(null);
        guidance.hidden = false;
        productResults.replaceChildren();
        productResults.hidden = true;
        setStatus('Filtros limpiados.', false);
        currentPage = 1;
        loadMovements();
    });

    consultButton.addEventListener('click', function() {
        if (productInput.value.trim() && !selectedProduct) {
            setStatus('Selecciona un producto de las sugerencias para aplicar el filtro específico.', true);
            productInput.focus();
            return;
        }
        if (!startDate.value || !endDate.value) {
            setStatus('Selecciona la fecha inicial y la fecha final.', true);
            (!startDate.value ? startDate : endDate).focus();
            return;
        }
        if (startDate.value > endDate.value) {
            setStatus('La fecha inicial no puede ser posterior a la fecha final.', true);
            startDate.focus();
            return;
        }
        currentConsultaRef = null;
        appliedFilters = null;
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
        if (!event.target.closest('.kardex-product-search')) {
            productResults.hidden = true;
        }
    });

    loadCategories();
    loadMovements();
});
