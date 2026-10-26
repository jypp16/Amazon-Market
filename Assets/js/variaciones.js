document.addEventListener('DOMContentLoaded', function() {
    var rowsElement = document.getElementById('variation-rows');
    if (!rowsElement) return;

    var productInput = document.getElementById('variation-product');
    var categorySelect = document.getElementById('variation-category');
    var subcategorySelect = document.getElementById('variation-subcategory');
    var startDate = document.getElementById('variation-start-date');
    var endDate = document.getElementById('variation-end-date');
    var typeSelect = document.getElementById('variation-type');
    var directionSelect = document.getElementById('variation-direction');
    var status = document.getElementById('variation-filter-status');
    var pagination = document.getElementById('variation-pagination');
    var pageStatus = document.getElementById('variation-page-status');
    var summaryToggle = document.getElementById('variation-summary-toggle');
    var productBreakdown = document.getElementById('variation-product-breakdown');
    var allProductsSummary = document.getElementById('variation-all-products-summary');
    var singleProductSummary = document.getElementById('variation-single-product-summary');
    var singleProductTitle = document.getElementById('variation-single-product-title');
    var singleProductOperations = document.getElementById('variation-single-product-operations');
    var singleProductIncreases = document.getElementById('variation-single-product-increases');
    var singleProductDecreases = document.getElementById('variation-single-product-decreases');
    var totalOperationsElement = document.getElementById('variation-total-operations');
    var productCountElement = document.getElementById('variation-product-count');
    var exportPdfBtn = document.querySelector('.kardex-export-pdf');
    var exportExcelBtn = document.querySelector('.kardex-export-excel');

    var defaultStartDate = startDate.value;
    var defaultEndDate = endDate.value;
    var pageSize = 10;
    var currentPage = 1;
    var totalPages = 1;
    var currentConsultaRef = null;
    var appliedFilters = null;
    var isExportableCurrent = false;
    var requestSequence = 0;
    var categoriesData = {};
    var categoryLoadError = '';

    function exportarVariacionesPdf() {
        if (!isExportableCurrent || !currentConsultaRef || !appliedFilters) {
            setStatus('Primero realiza una consulta exportable de Variaciones.', true);
            return;
        }

        var params = new URLSearchParams({
            tipo: 'variaciones',
            referencia_consulta: currentConsultaRef,
            fecha_inicio: appliedFilters.fecha_inicio,
            fecha_fin: appliedFilters.fecha_fin
        });
        ['q', 'id_categoria', 'id_subcategoria', 'tipo_variacion'].forEach(function(key) {
            if (appliedFilters[key]) params.set(key, appliedFilters[key]);
        });
        if (appliedFilters.sentido) {
            var sentido = 'TODOS';
            if (['Incremento', 'INCREMENTO'].includes(appliedFilters.sentido)) sentido = 'INCREMENTO';
            else if (['Disminución', 'DISMINUCION'].includes(appliedFilters.sentido)) sentido = 'DISMINUCION';
            params.set('sentido', sentido);
        }

        exportPdfBtn.disabled = true;
        setStatus('Generando el PDF de Variaciones…', false);
        Api.download('reportes/exportar-pdf?' + params.toString(), 'Reporte_Variaciones.pdf')
            .then(function(result) {
                if (!result || !result.ok) {
                    var errorData = (result && result.data) || {};
                    if (['QUERY_REFERENCE_EXPIRED', 'QUERY_FILTERS_MISMATCH'].includes(errorData.code)) {
                        currentConsultaRef = null;
                        isExportableCurrent = false;
                        if (exportExcelBtn) exportExcelBtn.disabled = true;
                    }
                    setStatus(errorData.message || 'No se pudo descargar el PDF de Variaciones.', true);
                    return;
                }
                setStatus('El PDF de Variaciones se descargó correctamente.', false);
            })
            .catch(function() {
                setStatus('No se pudo conectar con el servidor para descargar el PDF de Variaciones.', true);
            })
            .finally(function() {
                exportPdfBtn.disabled = !isExportableCurrent;
                if (exportExcelBtn && !isExportableCurrent) exportExcelBtn.disabled = true;
            });
    }

    function exportarVariacionesExcel() {
        if (!isExportableCurrent || !currentConsultaRef || !appliedFilters) {
            setStatus('Primero realiza una consulta exportable de Variaciones.', true);
            return;
        }

        var params = new URLSearchParams({
            tipo: 'variaciones',
            referencia_consulta: currentConsultaRef,
            fecha_inicio: appliedFilters.fecha_inicio,
            fecha_fin: appliedFilters.fecha_fin
        });
        ['q', 'id_categoria', 'id_subcategoria', 'tipo_variacion'].forEach(function(key) {
            if (appliedFilters[key]) params.set(key, appliedFilters[key]);
        });
        if (appliedFilters.sentido) {
            var sentido = 'TODOS';
            if (['Incremento', 'INCREMENTO'].includes(appliedFilters.sentido)) sentido = 'INCREMENTO';
            else if (['Disminución', 'DISMINUCION'].includes(appliedFilters.sentido)) sentido = 'DISMINUCION';
            params.set('sentido', sentido);
        }

        exportExcelBtn.disabled = true;
        setStatus('Generando el Excel de Variaciones…', false);
        Api.downloadExcel('reportes/exportar-excel?' + params.toString(), 'Reporte_Variaciones.xlsx')
            .then(function(result) {
                if (!result || !result.ok) {
                    var errorData = (result && result.data) || {};
                    if (['QUERY_REFERENCE_EXPIRED', 'QUERY_FILTERS_MISMATCH'].includes(errorData.code)) {
                        currentConsultaRef = null;
                        isExportableCurrent = false;
                        if (exportPdfBtn) exportPdfBtn.disabled = true;
                    }
                    setStatus(errorData.message || 'No se pudo descargar el Excel de Variaciones.', true);
                    return;
                }
                setStatus('El Excel de Variaciones se descargó correctamente.', false);
            })
            .catch(function() {
                setStatus('No se pudo conectar con el servidor para descargar el Excel de Variaciones.', true);
            })
            .finally(function() {
                exportExcelBtn.disabled = !isExportableCurrent;
                if (exportPdfBtn && !isExportableCurrent) exportPdfBtn.disabled = true;
            });
    }

    if (exportPdfBtn) exportPdfBtn.addEventListener('click', exportarVariacionesPdf);
    if (exportExcelBtn) exportExcelBtn.addEventListener('click', exportarVariacionesExcel);

    function setStatus(message, isError) {
        status.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
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

    categorySelect.addEventListener('change', function() {
        updateSubcategories(categorySelect.value);
    });

    function renderRows(items) {
        rowsElement.replaceChildren();
        if (!items || !items.length) {
            var emptyRow = document.createElement('tr');
            emptyRow.className = 'variation-empty-row';
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 9;
            emptyCell.textContent = 'No hay variaciones para los filtros seleccionados.';
            emptyRow.appendChild(emptyCell);
            rowsElement.appendChild(emptyRow);
            return;
        }

        items.forEach(function(item) {
            var tr = document.createElement('tr');

            // 1. Fecha y hora
            var dateCell = document.createElement('td');
            dateCell.textContent = item.fecha_hora || item.fecha_movimiento || '—';
            tr.appendChild(dateCell);

            // 2. Producto (nombre y código)
            var productCell = document.createElement('td');
            var productName = document.createElement('strong');
            productName.textContent = item.producto_nombre || 'Producto';
            var productCode = document.createElement('small');
            productCode.textContent = item.codigo_barra || '';
            productCell.appendChild(productName);
            productCell.appendChild(productCode);
            tr.appendChild(productCell);

            // 3. Categoría / subcategoría
            var catCell = document.createElement('td');
            var catName = item.categoria_nombre || 'Sin categoría';
            var subcatName = item.subcategoria_nombre || 'General';
            catCell.textContent = catName + ' / ' + subcatName;
            tr.appendChild(catCell);

            // 4. Unidad
            var unitCell = document.createElement('td');
            unitCell.textContent = item.unidad_abreviatura || 'UND';
            tr.appendChild(unitCell);

            // 5. Tipo
            var typeCell = document.createElement('td');
            var typeBadge = document.createElement('span');
            var tipo = item.tipo_variacion || item.tipo_movimiento || '';
            var isWriteoff = tipo.indexOf('Baja') === 0;
            typeBadge.className = 'variation-type-badge ' + (isWriteoff ? 'is-writeoff' : 'is-adjustment');
            typeBadge.textContent = tipo;
            typeCell.appendChild(typeBadge);
            tr.appendChild(typeCell);

            // 6. Entradas
            var entryCell = document.createElement('td');
            entryCell.className = 'variation-increase';
            entryCell.textContent = (item.entrada !== null && item.entrada !== undefined) ? String(item.entrada) : '—';
            tr.appendChild(entryCell);

            // 7. Salidas
            var exitCell = document.createElement('td');
            exitCell.className = 'variation-decrease';
            exitCell.textContent = (item.salida !== null && item.salida !== undefined) ? String(item.salida) : '—';
            tr.appendChild(exitCell);

            // 8. Motivo o justificación
            var reasonCell = document.createElement('td');
            reasonCell.textContent = item.justificacion || '—';
            tr.appendChild(reasonCell);

            // 9. Saldo anterior → resultante
            var balanceCell = document.createElement('td');
            balanceCell.textContent = (item.saldo_anterior !== undefined ? item.saldo_anterior : '') + ' → ' + (item.saldo_resultante !== undefined ? item.saldo_resultante : '');
            tr.appendChild(balanceCell);

            rowsElement.appendChild(tr);
        });
    }

    function renderSummary(resumen) {
        if (!resumen) {
            totalOperationsElement.textContent = '0';
            productCountElement.textContent = '0';
            allProductsSummary.hidden = false;
            singleProductSummary.hidden = true;
            productBreakdown.replaceChildren();
            return;
        }

        var totalOps = Number(resumen.total_operaciones) || 0;
        var prodCount = Number(resumen.productos_con_variaciones) || 0;
        var isSingleProduct = Boolean(resumen.es_producto_unico);

        totalOperationsElement.textContent = String(totalOps);
        productCountElement.textContent = String(prodCount);

        allProductsSummary.hidden = isSingleProduct;
        singleProductSummary.hidden = !isSingleProduct;
        productBreakdown.replaceChildren();

        if (isSingleProduct && resumen.resumen_producto_unico) {
            var single = resumen.resumen_producto_unico;
            singleProductTitle.textContent = 'Resumen · ' + (single.producto_nombre || 'Producto');
            singleProductOperations.textContent = String(single.operaciones || 0);
            singleProductIncreases.textContent = '+' + (single.incrementos || 0);
            var decVal = Number(single.disminuciones) || 0;
            singleProductDecreases.textContent = decVal > 0 ? '−' + decVal : '−0';
            return;
        }

        var listPorProducto = Array.isArray(resumen.resumen_por_producto) ? resumen.resumen_por_producto : [];
        if (!listPorProducto.length) {
            return;
        }

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
        listPorProducto.forEach(function(prod) {
            var row = document.createElement('tr');
            var nameCell = document.createElement('td');
            var name = document.createElement('strong');
            name.textContent = prod.producto_nombre;
            var code = document.createElement('small');
            code.textContent = prod.codigo_barra || '';
            nameCell.append(name, code);
            row.appendChild(nameCell);

            var opsCell = document.createElement('td');
            opsCell.textContent = String(prod.operaciones || 0);
            row.appendChild(opsCell);

            var incCell = document.createElement('td');
            incCell.className = 'variation-breakdown-increase';
            incCell.textContent = '+' + (prod.incrementos || 0);
            row.appendChild(incCell);

            var decCell = document.createElement('td');
            decCell.className = 'variation-breakdown-decrease';
            var d = Number(prod.disminuciones) || 0;
            decCell.textContent = d > 0 ? '−' + d : '0';
            row.appendChild(decCell);

            body.appendChild(row);
        });
        table.appendChild(body);
        productBreakdown.appendChild(table);
    }

    function renderPagination(paginationData) {
        pagination.replaceChildren();
        var total = Number(paginationData.total) || 0;
        currentPage = Number(paginationData.page) || 1;
        totalPages = Math.max(1, Number(paginationData.pages) || 1);

        function addButton(label, targetPage, disabled, active) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.disabled = disabled;
            if (active) {
                button.classList.add('is-current');
                button.setAttribute('aria-current', 'page');
            }
            button.addEventListener('click', function() {
                if (targetPage !== currentPage) {
                    loadVariaciones(targetPage);
                }
            });
            pagination.appendChild(button);
        }

        addButton('Anterior', Math.max(1, currentPage - 1), currentPage <= 1, false);

        for (var page = 1; page <= totalPages; page++) {
            addButton(String(page), page, false, page === currentPage);
        }

        addButton('Siguiente', Math.min(totalPages, currentPage + 1), currentPage >= totalPages, false);

        var first = total > 0 ? (currentPage - 1) * pageSize + 1 : 0;
        var last = total > 0 ? Math.min(currentPage * pageSize, total) : 0;
        pageStatus.textContent = 'Mostrando ' + (total > 0 ? first + '–' + last : '0') + ' de ' + total + ' operaciones';
    }

    async function loadVariaciones(page) {
        var requestSeq = ++requestSequence;
        page = page || 1;

        if (!appliedFilters) {
            appliedFilters = {
                q: productInput.value.trim(),
                id_categoria: categorySelect.value,
                id_subcategoria: subcategorySelect.value,
                fecha_inicio: startDate.value,
                fecha_fin: endDate.value,
                tipo_variacion: typeSelect.value,
                sentido: directionSelect.value
            };
        }

        var params = new URLSearchParams({
            page: String(page),
            limit: String(pageSize),
            fecha_inicio: appliedFilters.fecha_inicio,
            fecha_fin: appliedFilters.fecha_fin
        });

        if (appliedFilters.q) params.set('q', appliedFilters.q);
        if (appliedFilters.id_categoria) params.set('id_categoria', appliedFilters.id_categoria);
        if (appliedFilters.id_subcategoria) params.set('id_subcategoria', appliedFilters.id_subcategoria);
        if (appliedFilters.tipo_variacion) params.set('tipo_variacion', appliedFilters.tipo_variacion);

        if (appliedFilters.sentido) {
            var sentidoParam = 'TODOS';
            if (appliedFilters.sentido === 'Incremento' || appliedFilters.sentido === 'INCREMENTO') {
                sentidoParam = 'INCREMENTO';
            } else if (appliedFilters.sentido === 'Disminución' || appliedFilters.sentido === 'DISMINUCION') {
                sentidoParam = 'DISMINUCION';
            }
            params.set('sentido', sentidoParam);
        }

        if (currentConsultaRef) {
            params.set('referencia_consulta', currentConsultaRef);
        }

        rowsElement.replaceChildren();
        var loadingRow = document.createElement('tr');
        loadingRow.className = 'variation-empty-row';
        var loadingCell = document.createElement('td');
        loadingCell.colSpan = 9;
        loadingCell.textContent = 'Cargando variaciones…';
        loadingRow.appendChild(loadingCell);
        rowsElement.appendChild(loadingRow);

        try {
            var response = await Api.get('reportes/variaciones?' + params.toString());
            if (requestSeq !== requestSequence) return;
            if (!response) return;

            if (!response.ok || !response.data || response.data.status !== true) {
                var errData = response.data || {};
                if (errData.code === 'QUERY_REFERENCE_EXPIRED' || errData.code === 'QUERY_FILTERS_MISMATCH') {
                    currentConsultaRef = null;
                }
                throw new Error(errData.message || 'No se pudieron cargar las variaciones.');
            }

            var data = response.data.data || {};
            currentConsultaRef = data.referencia_consulta || null;
            isExportableCurrent = Boolean(data.exportable);

            var items = Array.isArray(data.items) ? data.items : [];
            var resumen = data.resumen || {};
            var paginationData = data.pagination || { total: items.length, page: page, limit: pageSize, pages: 1 };

            renderRows(items);
            renderSummary(resumen);
            renderPagination(paginationData);

            if (exportPdfBtn) exportPdfBtn.disabled = !isExportableCurrent;
            if (exportExcelBtn) exportExcelBtn.disabled = !isExportableCurrent;

            setStatus(categoryLoadError, Boolean(categoryLoadError));
        } catch (error) {
            if (requestSeq !== requestSequence) return;
            rowsElement.replaceChildren();
            var errorRow = document.createElement('tr');
            errorRow.className = 'variation-empty-row';
            var errorCell = document.createElement('td');
            errorCell.colSpan = 9;
            errorCell.textContent = 'No se pudieron cargar las variaciones.';
            errorRow.appendChild(errorCell);
            rowsElement.appendChild(errorRow);

            pageStatus.textContent = 'Error al cargar variaciones.';
            pagination.replaceChildren();
            if (exportPdfBtn) exportPdfBtn.disabled = true;
            if (exportExcelBtn) exportExcelBtn.disabled = true;
            setStatus(error.message, true);
        }
    }

    document.getElementById('variation-apply').addEventListener('click', function() {
        if (!startDate.value || !endDate.value) {
            setStatus('Selecciona las fechas inicial y final para consultar.', true);
            (startDate.value ? endDate : startDate).focus();
            return;
        }
        if (startDate.value && endDate.value && startDate.value > endDate.value) {
            setStatus('La fecha inicial no puede ser posterior a la fecha final.', true);
            startDate.focus();
            return;
        }

        setStatus('', false);
        currentConsultaRef = null;
        appliedFilters = {
            q: productInput.value.trim(),
            id_categoria: categorySelect.value,
            id_subcategoria: subcategorySelect.value,
            fecha_inicio: startDate.value,
            fecha_fin: endDate.value,
            tipo_variacion: typeSelect.value,
            sentido: directionSelect.value
        };

        loadVariaciones(1);
    });

    document.getElementById('variation-clear').addEventListener('click', function() {
        productInput.value = '';
        categorySelect.value = '';
        updateSubcategories('');
        subcategorySelect.value = '';
        startDate.value = defaultStartDate;
        endDate.value = defaultEndDate;
        typeSelect.value = '';
        directionSelect.value = '';
        setStatus('', false);

        currentConsultaRef = null;
        appliedFilters = {
            q: '',
            id_categoria: '',
            id_subcategoria: '',
            fecha_inicio: defaultStartDate,
            fecha_fin: defaultEndDate,
            tipo_variacion: '',
            sentido: ''
        };

        loadVariaciones(1);
    });

    summaryToggle.addEventListener('click', function() {
        var expanded = summaryToggle.getAttribute('aria-expanded') === 'true';
        summaryToggle.setAttribute('aria-expanded', String(!expanded));
        productBreakdown.hidden = expanded;
        var icon = summaryToggle.querySelector('i');
        if (icon) {
            icon.classList.toggle('is-rotated', !expanded);
        }
    });

    loadCategories();
    // Carga inicial automática con filtros por defecto
    appliedFilters = {
        q: productInput.value.trim(),
        id_categoria: categorySelect.value,
        id_subcategoria: subcategorySelect.value,
        fecha_inicio: startDate.value,
        fecha_fin: endDate.value,
        tipo_variacion: typeSelect.value,
        sentido: directionSelect.value
    };
    loadVariaciones(1);
});
