(function () {
    'use strict';

    var config = window.CAJA_CONSULTA_CONFIG || {};
    var service = window.CajaConsultaService;
    var isAdministrator = config.isAdministrator === true;
    var pageSize = Math.max(1, Number(config.pageSize) || 10);
    var filters = document.getElementById('cash_query_filters');
    var dateInput = document.getElementById('cash_query_date');
    var sellerInput = document.getElementById('cash_query_seller');
    var submitButton = document.getElementById('cash_query_submit');
    var results = document.getElementById('cash_query_results');
    var loading = document.getElementById('cash_query_loading');
    var error = document.getElementById('cash_query_error');
    var denied = document.getElementById('cash_query_denied');
    var empty = document.getElementById('cash_query_no_session');
    var statusChip = document.getElementById('cash_consulta_status');
    var currentPage = 1;
    var totalPages = 0;
    var totalMovements = 0;
    var currentRequest = null;
    var isLoading = false;
    var requestSequence = 0;

    if (!service || !statusChip) return;

    var typeStyles = {
        cash_sale: 'is-neutral',
        admin_injection: 'is-blue',
        manual_income: 'is-blue',
        admin_withdrawal: 'is-amber',
        cash_out: 'is-amber',
        omitted_withdrawal: 'is-neutral'
    };

    function formatMoney(value) {
        var amount = Number(value);
        if (value === null || value === undefined || !Number.isFinite(amount)) return '—';
        return 'S/ ' + new Intl.NumberFormat('es-ES', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.abs(amount));
    }

    function formatSigned(value, sign) {
        var amount = Number(value);
        if (value === null || value === undefined || !Number.isFinite(amount)) return '—';
        var prefix = sign === 'plus' ? '+' : (sign === 'minus' ? '−' : '');
        return prefix + formatMoney(amount);
    }

    function clearResult() {
        requestSequence += 1;
        isLoading = false;
        currentRequest = null;
        totalPages = 0;
        totalMovements = 0;
        currentPage = 1;
        results.hidden = true;
        error.hidden = true;
        denied.hidden = true;
        empty.hidden = true;
        loading.hidden = true;
        if (submitButton) submitButton.disabled = false;
        document.getElementById('cash_query_previous').disabled = true;
        document.getElementById('cash_query_next').disabled = true;
    }

    function setSummaryLoading() {
        results.hidden = false;
        loading.hidden = false;
        error.hidden = true;
        denied.hidden = true;
        empty.hidden = true;
        [
            'cash_query_opening', 'cash_query_sales', 'cash_query_income',
            'cash_query_expenses', 'cash_query_balance', 'cash_query_other_tenders'
        ].forEach(function (id) {
            document.getElementById(id).textContent = 'Cargando…';
        });
        var body = document.getElementById('cash_query_rows');
        body.replaceChildren();
        var row = document.createElement('tr');
        var cell = document.createElement('td');
        cell.colSpan = 6;
        cell.textContent = 'Cargando movimientos…';
        row.appendChild(cell);
        body.appendChild(row);
        document.getElementById('cash_query_page_status').textContent = 'Cargando movimientos…';
        document.getElementById('cash_query_empty_movements').hidden = true;
        if (submitButton) submitButton.disabled = true;
        document.getElementById('cash_query_previous').disabled = true;
        document.getElementById('cash_query_next').disabled = true;
        isLoading = true;
    }

    function renderSummary(summary) {
        document.getElementById('cash_query_opening').textContent = formatMoney(summary.opening_balance);
        document.getElementById('cash_query_sales').textContent = formatSigned(summary.cash_sales, 'plus');
        document.getElementById('cash_query_income').textContent = formatSigned(summary.income, 'plus');
        document.getElementById('cash_query_expenses').textContent = formatSigned(summary.expenses, 'minus');
        document.getElementById('cash_query_balance').textContent = formatMoney(summary.current_balance);
        document.getElementById('cash_query_other_tenders').textContent =
            'Aparte, no suman al saldo de efectivo: Tarjeta ' + formatMoney(summary.card)
            + ' · Yape/Plin ' + formatMoney(summary.yape_plin) + '.';
    }

    function amountSign(movement) {
        if (movement.amount_sign === 'plus' || movement.amount_sign === '+') return 'plus';
        if (movement.amount_sign === 'minus' || movement.amount_sign === '−' || movement.amount_sign === '-') return 'minus';
        if (movement.effect === 'Suma') return 'plus';
        if (movement.effect === 'Resta') return 'minus';
        return '';
    }

    function renderMovements(movements) {
        var body = document.getElementById('cash_query_rows');
        body.replaceChildren();
        movements.forEach(function (movement) {
            var row = document.createElement('tr');
            var time = document.createElement('td');
            var type = document.createElement('td');
            var typeBadge = document.createElement('span');
            var reference = document.createElement('td');
            var amount = document.createElement('td');
            var effect = document.createElement('td');
            var responsible = document.createElement('td');
            var typeKey = movement.type || '';
            var sign = amountSign(movement);

            time.textContent = movement.time || '—';
            typeBadge.className = 'cash-query-type ' + (typeStyles[typeKey] || 'is-unknown');
            typeBadge.textContent = service.typeLabels[typeKey] || movement.type_label || typeKey || 'Otro';
            type.appendChild(typeBadge);
            reference.textContent = movement.reference || movement.reason || '—';
            amount.textContent = formatSigned(movement.amount, sign);
            amount.className = sign === 'plus' ? 'is-positive' : (sign === 'minus' ? 'is-negative' : 'is-neutral');
            effect.textContent = movement.effect || '—';
            responsible.textContent = movement.responsible || '—';

            [time, type, reference, amount, effect, responsible].forEach(function (cell) {
                row.appendChild(cell);
            });
            body.appendChild(row);
        });

        document.getElementById('cash_query_empty_movements').hidden = movements.length !== 0;
    }

    function renderPagination(pagination) {
        currentPage = Number(pagination.page) || 1;
        totalPages = Number(pagination.total_pages) || 0;
        totalMovements = Number(pagination.total_movements) || 0;
        var shown = document.getElementById('cash_query_rows').children.length;
        if (document.getElementById('cash_query_empty_movements').hidden === false) shown = 0;
        var first = totalMovements === 0 ? 0 : (currentPage - 1) * pageSize + 1;
        var last = Math.min((currentPage - 1) * pageSize + shown, totalMovements);
        document.getElementById('cash_query_page_status').textContent =
            'Mostrando ' + shown + ' de ' + totalMovements + ' movimientos · Página '
            + currentPage + (totalPages ? ' de ' + totalPages : '');
        document.getElementById('cash_query_page_number').textContent = String(currentPage);
        document.getElementById('cash_query_previous').disabled = isLoading || currentPage <= 1;
        document.getElementById('cash_query_next').disabled = isLoading || currentPage >= totalPages;
        document.getElementById('cash_query_page_number').setAttribute('aria-label',
            'Página ' + currentPage + (totalPages ? ' de ' + totalPages : ''));
        document.getElementById('cash_query_page_status').setAttribute('data-range',
            first + '-' + last + ' de ' + totalMovements);
    }

    function renderResult(result) {
        loading.hidden = true;
        isLoading = false;
        if (submitButton) submitButton.disabled = false;
        if (!result || result.type === 'empty' || result.type === 'no_session') {
            results.hidden = true;
            denied.hidden = true;
            error.hidden = true;
            empty.hidden = false;
            if (!isAdministrator) {
                document.getElementById('cash_query_empty_title').textContent = 'Sin caja activa';
                document.getElementById('cash_query_empty_text').textContent =
                    'Debes abrir una sesión de caja para consultar tu saldo y movimientos.';
                document.getElementById('cash_query_open_link').hidden = false;
                statusChip.textContent = 'Sin caja abierta';
            } else {
                document.getElementById('cash_query_empty_title').textContent = 'Sin aperturas';
                document.getElementById('cash_query_empty_text').textContent =
                    'No hay aperturas para la fecha y el vendedor seleccionados.';
                document.getElementById('cash_query_open_link').hidden = true;
            }
            return;
        }
        if (result.type === 'access_denied') {
            results.hidden = true;
            empty.hidden = true;
            error.hidden = true;
            denied.hidden = false;
            statusChip.textContent = 'Acceso denegado';
            return;
        }
        if (result.type === 'error') {
            showError();
            return;
        }
        if (!result.summary || !Array.isArray(result.movements)) {
            showError();
            return;
        }

        empty.hidden = true;
        denied.hidden = true;
        error.hidden = true;
        results.hidden = false;
        renderSummary(result.summary);
        renderMovements(result.movements);
        renderPagination(result.pagination || {});
        if (!isAdministrator && result.session) {
            statusChip.textContent = 'Caja abierta · ' + (result.session.seller || 'Vendedor')
                + ' · Turno ' + (result.session.turn || '—');
        }
    }

    function showError() {
        isLoading = false;
        loading.hidden = true;
        results.hidden = true;
        empty.hidden = true;
        denied.hidden = true;
        error.hidden = false;
        if (submitButton) submitButton.disabled = false;
    }

    function requestPage(page, selectedFilters) {
        if (isLoading) return;
        var request;
        if (isAdministrator) {
            var base = selectedFilters || currentRequest || {
                date: dateInput.value,
                seller_id: sellerInput.value
            };
            request = { date: base.date, seller_id: base.seller_id, page: page };
        } else {
            request = Object.assign({}, currentRequest || {}, { page: page });
        }
        currentRequest = request;
        requestSequence += 1;
        var sequence = requestSequence;
        setSummaryLoading();
        Promise.resolve().then(function () {
            return isAdministrator ? service.query(request) : service.getActiveSession(request);
        }).then(function (result) {
            if (sequence === requestSequence) renderResult(result);
        }).catch(function () {
            if (sequence === requestSequence) showError();
        });
    }

    function queryAdmin(event) {
        if (event) event.preventDefault();
        var sellerError = document.getElementById('cash_query_seller_error');
        sellerError.textContent = '';
        sellerInput.removeAttribute('aria-invalid');
        if (!dateInput.value) {
            showError();
            return;
        }
        if (!sellerInput.value) {
            sellerInput.setAttribute('aria-invalid', 'true');
            sellerError.textContent = 'Selecciona un vendedor.';
            sellerInput.focus();
            return;
        }
        requestPage(1, { date: dateInput.value, seller_id: sellerInput.value });
    }

    function loadSellerView() {
        statusChip.textContent = 'Cargando sesión…';
        requestPage(1);
    }

    function initializeAdmin() {
        statusChip.textContent = 'Vista del administrador';
        service.listSellers().then(function (sellers) {
            sellers.forEach(function (seller) {
                var option = document.createElement('option');
                option.value = seller.id;
                option.textContent = seller.name;
                sellerInput.appendChild(option);
            });
        }).catch(showError);
    }

    if (isAdministrator) {
        initializeAdmin();
        filters.addEventListener('submit', queryAdmin);
        filters.addEventListener('reset', function () {
            window.setTimeout(function () {
                sellerInput.value = '';
                dateInput.value = config.defaultDate || '';
                document.getElementById('cash_query_seller_error').textContent = '';
                sellerInput.removeAttribute('aria-invalid');
                clearResult();
            }, 0);
        });
    } else {
        loadSellerView();
    }

    document.getElementById('cash_query_retry').addEventListener('click', function () {
        if (!currentRequest) {
            if (isAdministrator) initializeAdmin();
            else loadSellerView();
            return;
        }
        if (isAdministrator) requestPage(currentRequest.page || 1);
        else requestPage(currentRequest.page || 1);
    });

    document.getElementById('cash_query_previous').addEventListener('click', function () {
        if (currentPage > 1) requestPage(currentPage - 1);
    });
    document.getElementById('cash_query_next').addEventListener('click', function () {
        if (currentPage < totalPages) requestPage(currentPage + 1);
    });
}());
