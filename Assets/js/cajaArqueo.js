(function () {
    'use strict';

    var service = window.CajaArqueoService;
    var loading = document.getElementById('cash_arqueo_loading');
    var workspace = document.getElementById('cash_arqueo_workspace');
    var noSession = document.getElementById('cash_arqueo_no_session');
    var loadError = document.getElementById('cash_arqueo_load_error');
    var statusChip = document.getElementById('cash_arqueo_status');
    var countInput = document.getElementById('cash_count_amount');
    var observationInput = document.getElementById('cash_count_observation');
    var saveButton = document.getElementById('cash_count_save');
    var closeButton = document.getElementById('cash_close_confirm');
    var countFeedback = document.getElementById('cash_count_feedback');
    var closeFeedback = document.getElementById('cash_close_feedback');
    var closeSummary = document.getElementById('cash_close_summary');
    var countForm = document.getElementById('cash_count_form');
    var overlay = document.getElementById('cash_auth_overlay');

    if (!service || !workspace || !countForm || !overlay) return;

    var session = null;
    var isLoading = false;
    var countRequestId = null;
    var countFingerprint = null;
    var closeRequestId = null;
    var closeFingerprint = null;
    var returnFocus = null;
    var authForm = document.getElementById('cash_auth_form');
    var usernameInput = document.getElementById('cash_admin_username');
    var passwordInput = document.getElementById('cash_admin_password');
    var authReasonInput = document.getElementById('cash_auth_reason');
    var authCancel = document.getElementById('cash_auth_cancel');
    var authSubmit = document.getElementById('cash_auth_submit');
    var authWarning = document.getElementById('cash_auth_warning');
    var authFeedback = document.getElementById('cash_auth_feedback');
    var appLayout = document.querySelector('.app-layout');

    function formatMoney(value) {
        var amount = Number(value);
        if (value === null || value === undefined || !Number.isFinite(amount)) return '—';
        return 'S/ ' + new Intl.NumberFormat('es-ES', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.abs(amount));
    }

    function formatSigned(value) {
        var amount = Number(value);
        if (value === null || value === undefined || !Number.isFinite(amount)) return '—';
        if (amount < 0) return '−' + formatMoney(amount);
        if (amount > 0) return '+' + formatMoney(amount);
        return formatMoney(0);
    }

    function parseCount() {
        var text = countInput.value.trim();
        if (!/^\d+(?:[,.]\d{1,2})?$/.test(text)) return null;
        var value = Number(text.replace(',', '.'));
        return Number.isFinite(value) && value >= 0 ? Math.round(value * 100) / 100 : null;
    }

    function setFieldError(input, errorId, message) {
        input.setAttribute('aria-invalid', 'true');
        document.getElementById(errorId).textContent = message;
    }

    function clearFieldError(input, errorId) {
        input.removeAttribute('aria-invalid');
        document.getElementById(errorId).textContent = '';
    }

    function activeArqueo() {
        if (!session) return null;
        return (session.history || []).find(function (item) { return item.status === 'Vigente'; }) || null;
    }

    function updateDifference() {
        var value = parseCount();
        var card = document.getElementById('cash_count_difference');
        var amount = document.getElementById('cash_count_difference_amount');
        var label = document.getElementById('cash_count_difference_label');
        var text = document.getElementById('cash_count_difference_text');
        var observationRequired = false;

        if (value === null || !session) {
            card.className = 'cash-opening-difference is-empty';
            amount.textContent = '—';
            label.textContent = 'Pendiente';
            text.textContent = 'Ingresa un conteo válido para calcular la diferencia.';
        } else {
            var difference = Math.round((value - Number(session.expected)) * 100) / 100;
            if (!Number.isFinite(difference)) {
                card.className = 'cash-opening-difference is-empty';
                amount.textContent = '—';
                label.textContent = 'No disponible';
                text.textContent = 'No se recibió el efectivo esperado del servidor.';
                saveButton.disabled = true;
                return;
            }
            var stateName = difference < 0 ? 'shortage' : (difference > 0 ? 'surplus' : 'balanced');
            var stateLabel = difference < 0 ? 'Faltante' : (difference > 0 ? 'Sobrante' : 'Sin diferencia');
            card.className = 'cash-opening-difference is-' + stateName;
            amount.textContent = formatSigned(difference);
            label.textContent = stateLabel;
            text.textContent = difference === 0
                ? 'El efectivo contado coincide con el efectivo esperado.'
                : 'La diferencia es contado menos efectivo esperado.';
            observationRequired = difference !== 0;
        }

        if (observationRequired && !observationInput.value.trim()) {
            setFieldError(observationInput, 'cash_count_observation_error', 'Escribe una observación para explicar la diferencia.');
        } else if (observationInput.getAttribute('aria-invalid') === 'true') {
            clearFieldError(observationInput, 'cash_count_observation_error');
        }

        saveButton.disabled = isLoading || !session || !session.is_open || value === null
            || (observationRequired && !observationInput.value.trim());
    }

    function renderHistory(history) {
        var tbody = document.getElementById('cash_count_history');
        tbody.replaceChildren();
        (history || []).forEach(function (item) {
            var row = document.createElement('tr');
            var time = document.createElement('td');
            var counted = document.createElement('td');
            var difference = document.createElement('td');
            var statusCell = document.createElement('td');
            var badge = document.createElement('span');

            time.textContent = item.time || '—';
            counted.textContent = formatMoney(item.counted);
            difference.textContent = formatSigned(item.difference);
            difference.className = Number(item.difference) < 0
                ? 'cash-count-negative'
                : (Number(item.difference) > 0 ? 'cash-count-positive' : 'cash-count-neutral');
            badge.className = 'cash-count-status is-' + ({
                'Vigente': 'current',
                'Reemplazado': 'replaced',
                'Desactualizado': 'stale'
            }[item.status] || 'stale');
            badge.textContent = item.status || 'Desactualizado';
            statusCell.appendChild(badge);
            row.appendChild(time);
            row.appendChild(counted);
            row.appendChild(difference);
            row.appendChild(statusCell);
            tbody.appendChild(row);
        });
    }

    function renderChecklist() {
        var ownership = document.getElementById('cash_close_ownership');
        var arqueoStatus = document.getElementById('cash_close_arqueo_status');
        var current = activeArqueo();
        var owned = Boolean(session && session.is_owner !== false);
        ownership.textContent = (owned ? '✓ ' : '⚠ ') + 'Sesión abierta y propia';
        ownership.className = owned ? 'is-valid' : 'is-warning';
        if (current) {
            arqueoStatus.textContent = '✓ Arqueo vigente de las ' + (current.time || '—');
            arqueoStatus.className = 'is-valid';
        } else {
            arqueoStatus.textContent = '⚠ Debes realizar un arqueo nuevo antes de cerrar.';
            arqueoStatus.className = 'is-warning';
        }
        closeButton.disabled = isLoading || !session || !session.is_open || !owned || !current;
    }

    function renderSession(nextSession) {
        session = nextSession;
        var isOpen = Boolean(session && session.is_open);
        var owned = Boolean(session && session.is_owner !== false);
        var status = document.getElementById('cash_arqueo_status');

        if (session) {
            document.getElementById('cash_count_opening').textContent = formatMoney(session.opening_fund);
            document.getElementById('cash_count_sales').textContent = formatMoney(session.cash_sales);
            document.getElementById('cash_count_income').textContent = formatMoney(session.income);
            document.getElementById('cash_count_expenses').textContent = formatMoney(session.expenses);
            document.getElementById('cash_count_expected').textContent = formatMoney(session.expected);
            document.getElementById('cash_count_payment_note').textContent =
                'Tarjeta (' + formatMoney(session.card) + ') y Yape/Plin (' + formatMoney(session.yape_plin) +
                ') no cuentan en el efectivo esperado.';
            renderHistory(session.history);
            status.textContent = (isOpen ? 'Caja abierta' : 'Caja cerrada') + ' · '
                + (session.seller || 'Vendedor') + ' · ' + (session.turn || 'Turno');
        }

        loading.hidden = true;
        loadError.hidden = true;
        if (!isOpen || !owned) {
            workspace.hidden = true;
            noSession.hidden = false;
            noSession.querySelector('p').textContent = !isOpen
                ? 'No tienes una sesión de caja abierta. El formulario de arqueo está bloqueado.'
                : 'Esta sesión no pertenece al vendedor autenticado.';
            return;
        }

        noSession.hidden = true;
        workspace.hidden = false;
        if (!countInput.value && session.history && session.history.length) {
            countInput.value = String(session.history[session.history.length - 1].counted).replace('.', ',');
        }
        renderChecklist();
        updateDifference();
    }

    function showLoadError() {
        loading.hidden = true;
        workspace.hidden = true;
        noSession.hidden = true;
        loadError.hidden = false;
    }

    function loadState() {
        loading.hidden = false;
        loadError.hidden = true;
        return service.getState().then(function (nextSession) {
            if (nextSession && nextSession.is_open) {
                var requiredNumbers = [
                    'opening_fund', 'cash_sales', 'income', 'expenses',
                    'expected', 'card', 'yape_plin', 'version'
                ];
                var complete = requiredNumbers.every(function (key) {
                    return Number.isFinite(Number(nextSession[key]));
                });
                if (!complete || !Array.isArray(nextSession.history)) {
                    throw new Error('El estado de arqueo está incompleto.');
                }
            }
            renderSession(nextSession);
        }).catch(showLoadError);
    }

    function showFeedback(element, message, isError) {
        element.textContent = message;
        element.classList.toggle('is-error', Boolean(isError));
        element.hidden = false;
    }

    function setLoading(value, operation) {
        isLoading = value;
        countInput.disabled = value || !session || !session.is_open;
        observationInput.disabled = value || !session || !session.is_open;
        var amount = parseCount();
        var needsObservation = amount !== null && session
            && Math.round((amount - Number(session.expected)) * 100) !== 0
            && !observationInput.value.trim();
        saveButton.disabled = value || !session || !session.is_open || amount === null || needsObservation;
        saveButton.textContent = value && operation === 'count' ? 'Guardando…' : 'Guardar arqueo';
        closeButton.disabled = value || !activeArqueo();
        closeButton.textContent = value && operation === 'close' ? 'Cerrando…' : 'Confirmar cierre';
        renderChecklist();
    }

    function payloadFingerprint(payload) {
        return JSON.stringify(payload);
    }

    function getCountRequestId(payload) {
        var fingerprint = payloadFingerprint(payload);
        if (!countRequestId || countFingerprint !== fingerprint) {
            countRequestId = service.createRequestId();
            countFingerprint = fingerprint;
        }
        return countRequestId;
    }

    function getCloseRequestId(payload) {
        var fingerprint = payload.arqueo_id;
        if (!closeRequestId || closeFingerprint !== fingerprint) {
            closeRequestId = service.createRequestId();
            closeFingerprint = fingerprint;
        }
        return closeRequestId;
    }

    function clearCountRequest() {
        countRequestId = null;
        countFingerprint = null;
    }

    function handleChanged(result) {
        if (!Number.isFinite(Number(result.expected))) {
            showFeedback(countFeedback, 'No se pudo completar la operación. Inténtalo de nuevo.', true);
            return;
        }
        session.expected = Number(result.expected);
        session.version = Number(result.version);
        (session.history || []).forEach(function (item) {
            if (item.status === 'Vigente') item.status = 'Desactualizado';
        });
        document.getElementById('cash_count_expected').textContent = formatMoney(session.expected);
        renderHistory(session.history);
        renderChecklist();
        updateDifference();
        showFeedback(countFeedback,
            'Hubo cambios en la caja. Revisa el conteo y guarda un arqueo nuevo.', true);
        countInput.focus();
        clearCountRequest();
        closeRequestId = null;
        closeFingerprint = null;
    }

    function validateCount() {
        clearFieldError(countInput, 'cash_count_amount_error');
        clearFieldError(observationInput, 'cash_count_observation_error');
        var value = parseCount();
        if (value === null) {
            setFieldError(countInput, 'cash_count_amount_error', 'Ingresa un monto desde cero con hasta dos decimales.');
            countInput.focus();
            updateDifference();
            return null;
        }
        var difference = Math.round((value - Number(session.expected)) * 100) / 100;
        var observation = observationInput.value.trim();
        if (difference !== 0 && !observation) {
            setFieldError(observationInput, 'cash_count_observation_error', 'Escribe una observación para explicar la diferencia.');
            observationInput.focus();
            return null;
        }
        return { counted: value, observation: observation, difference: difference };
    }

    function appendSavedArqueo(arqueo) {
        (session.history || []).forEach(function (item) {
            if (item.status === 'Vigente') item.status = 'Reemplazado';
        });
        session.history.push(arqueo);
        renderHistory(session.history);
        renderChecklist();
    }

    function saveArqueo(event) {
        event.preventDefault();
        if (isLoading || !session || !session.is_open) return;
        var values = validateCount();
        if (!values) return;
        var payload = {
            counted: values.counted,
            observation: values.observation,
            version: session.version,
            request_id: ''
        };
        payload.request_id = getCountRequestId(payload);
        countFeedback.hidden = true;
        setLoading(true, 'count');
        service.saveArqueo(payload).then(function (result) {
            if (result.type === 'saved') {
                var saved = result.arqueo || {
                    id: 'arqueo-local-' + Date.now(),
                    time: new Intl.DateTimeFormat('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date()),
                    counted: values.counted,
                    difference: values.difference,
                    observation: values.observation,
                    status: 'Vigente',
                    version: session.version
                };
                appendSavedArqueo(saved);
                clearCountRequest();
                showFeedback(countFeedback, 'Arqueo guardado. El nuevo arqueo está vigente.', false);
            } else if (result.type === 'caja_changed') {
                handleChanged(result);
            } else if (result.type === 'session_closed' || result.type === 'session_not_owned') {
                showFeedback(countFeedback, 'La sesión ya no está disponible para este vendedor.', true);
                return loadState();
            } else if (result.type === 'observation_required') {
                setFieldError(observationInput, 'cash_count_observation_error', 'Escribe una observación para explicar la diferencia.');
                observationInput.focus();
            } else {
                showFeedback(countFeedback, 'No se pudo completar la operación. Inténtalo de nuevo.', true);
            }
        }).catch(function () {
            showFeedback(countFeedback, 'No se pudo completar la operación. Inténtalo de nuevo.', true);
        }).finally(function () {
            setLoading(false);
            updateDifference();
        });
    }

    function focusableDialogItems() {
        return Array.prototype.slice.call(overlay.querySelectorAll(
            'input:not([disabled]), textarea:not([disabled]), button:not([disabled])'
        )).filter(function (item) { return item.offsetParent !== null; });
    }

    function clearAuthErrors() {
        [
            [usernameInput, 'cash_admin_username_error'],
            [passwordInput, 'cash_admin_password_error'],
            [authReasonInput, 'cash_auth_reason_error']
        ].forEach(function (field) { clearFieldError(field[0], field[1]); });
    }

    function openAuthorization(schedule) {
        var start = schedule && (schedule.start || schedule.shift_start);
        var end = schedule && (schedule.end || schedule.shift_end);
        authWarning.textContent = 'Estás cerrando caja fuera del horario de tu turno ('
            + (start || '—') + ' – ' + (end || '—') + '). Un administrador debe autorizar este cierre.';
        authFeedback.hidden = true;
        clearAuthErrors();
        usernameInput.value = '';
        passwordInput.value = '';
        authReasonInput.value = '';
        returnFocus = document.activeElement;
        overlay.hidden = false;
        document.body.classList.add('cash-auth-open');
        if (appLayout) appLayout.inert = true;
        usernameInput.focus();
    }

    function closeAuthorization(resetRequest) {
        overlay.hidden = true;
        document.body.classList.remove('cash-auth-open');
        if (appLayout) appLayout.inert = false;
        usernameInput.value = '';
        passwordInput.value = '';
        authReasonInput.value = '';
        clearAuthErrors();
        authFeedback.hidden = true;
        if (resetRequest) {
            closeRequestId = null;
            closeFingerprint = null;
        }
        var target = returnFocus;
        returnFocus = null;
        if (target && typeof target.focus === 'function') target.focus();
    }

    function showCloseSummary(summary) {
        // TODO: Limitar el resumen a los datos autorizados por el rol cuando el backend confirme permisos.
        // TODO: La impresión del cierre queda fuera hasta definir el requisito de producto.
        var closedAt = summary.closed_at || summary.closedAt || '';
        var time = closedAt ? new Date(closedAt) : null;
        var closeTime = time && !Number.isNaN(time.getTime())
            ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short' }).format(time)
            : (closedAt || '—');
        closeSummary.replaceChildren();
        var title = document.createElement('h4');
        title.textContent = 'Cierre confirmado por el servidor';
        closeSummary.appendChild(title);
        [
            ['Efectivo esperado', formatMoney(summary.expected)],
            ['Efectivo contado', formatMoney(summary.counted)],
            ['Diferencia', formatSigned(summary.difference)],
            ['Observación', summary.observation || 'Sin observación'],
            ['Hora de cierre', closeTime]
        ].forEach(function (entry) {
            var line = document.createElement('p');
            var label = document.createElement('strong');
            label.textContent = entry[0] + ': ';
            line.appendChild(label);
            line.appendChild(document.createTextNode(entry[1]));
            closeSummary.appendChild(line);
        });
        closeSummary.hidden = false;
        if (session) {
            session.is_open = false;
            session.closed_summary = summary;
        }
        countInput.disabled = true;
        observationInput.disabled = true;
        saveButton.disabled = true;
        saveButton.textContent = 'Sesión cerrada';
        closeButton.disabled = true;
        closeButton.textContent = 'Cierre confirmado';
        document.getElementById('cash_arqueo_status').textContent =
            'Caja cerrada · ' + (session.seller || 'Vendedor') + ' · ' + (session.turn || 'Turno');
        showFeedback(closeFeedback, 'La caja quedó cerrada. Ya no se aceptan nuevas operaciones en esta sesión.', false);
    }

    function reconcileCloseFailure() {
        return service.getState().then(function (current) {
            if (current && !current.is_open && current.closed_summary) {
                session = current;
                showCloseSummary(current.closed_summary);
                return;
            }
            showCloseFailure();
        }).catch(function () {
            showCloseFailure();
        });
    }

    function showCloseFailure() {
        if (!overlay.hidden) {
            authFeedback.textContent = 'No se pudo completar la operación. Inténtalo de nuevo.';
            authFeedback.hidden = false;
        } else {
            showFeedback(closeFeedback, 'No se pudo completar la operación. Inténtalo de nuevo.', true);
        }
    }

    function handleCloseResult(result) {
        if (result.type === 'closed') {
            closeAuthorization(false);
            showCloseSummary(result.summary || result);
        } else if (result.type === 'authorization_required') {
            openAuthorization(result.schedule || {});
        } else if (result.type === 'authorization_denied') {
            authFeedback.textContent = 'No se pudo autorizar. Verifica los datos.';
            authFeedback.hidden = false;
            passwordInput.focus();
        } else if (result.type === 'arqueo_stale' || result.type === 'cash_changed') {
            if (!overlay.hidden) closeAuthorization(true);
            showFeedback(closeFeedback, 'Debes realizar un arqueo nuevo antes de cerrar.', true);
            countInput.focus();
            return loadState();
        } else if (result.type === 'session_closed' || result.type === 'session_not_owned') {
            if (!overlay.hidden) closeAuthorization(true);
            showFeedback(closeFeedback, 'La sesión ya no está disponible para este vendedor.', true);
            return loadState();
        } else {
            showCloseFailure();
        }
    }

    function submitClose(credentials) {
        if (isLoading || !session || !activeArqueo()) return Promise.resolve();
        var active = activeArqueo();
        var payload = {
            arqueo_id: active.id,
            request_id: '',
            admin_username: credentials ? credentials.username : undefined,
            admin_password: credentials ? credentials.password : undefined,
            exception_reason: credentials ? credentials.reason : undefined
        };
        payload.request_id = getCloseRequestId(payload);
        closeFeedback.hidden = true;
        setLoading(true, 'close');
        return service.closeCash(payload).then(handleCloseResult).catch(reconcileCloseFailure).finally(function () {
            if (credentials) {
                credentials.password = '';
                payload.admin_password = '';
                passwordInput.value = '';
            }
            setLoading(false);
        });
    }

    function submitAuthorization(event) {
        event.preventDefault();
        if (isLoading) return;
        clearAuthErrors();
        var firstInvalid = null;
        if (!usernameInput.value.trim()) {
            setFieldError(usernameInput, 'cash_admin_username_error', 'Completa este campo.');
            firstInvalid = firstInvalid || usernameInput;
        }
        if (!passwordInput.value) {
            setFieldError(passwordInput, 'cash_admin_password_error', 'Completa este campo.');
            firstInvalid = firstInvalid || passwordInput;
        }
        if (!authReasonInput.value.trim()) {
            setFieldError(authReasonInput, 'cash_auth_reason_error', 'Escribe el motivo de la excepción.');
            firstInvalid = firstInvalid || authReasonInput;
        }
        if (firstInvalid) {
            firstInvalid.focus();
            return;
        }

        var credentials = {
            username: usernameInput.value.trim(),
            password: passwordInput.value,
            reason: authReasonInput.value.trim()
        };
        authSubmit.disabled = true;
        authCancel.disabled = true;
        authSubmit.textContent = 'Autorizando y cerrando…';
        authForm.setAttribute('aria-busy', 'true');
        submitClose(credentials).finally(function () {
            authSubmit.disabled = false;
            authCancel.disabled = false;
            authSubmit.textContent = 'Autorizar y cerrar';
            authForm.setAttribute('aria-busy', 'false');
        });
    }

    function closeDialogOnEscape(event) {
        if (overlay.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            if (!isLoading) closeAuthorization(true);
            return;
        }
        if (event.key !== 'Tab') return;
        var items = focusableDialogItems();
        if (!items.length) return;
        if (event.shiftKey && document.activeElement === items[0]) {
            event.preventDefault();
            items[items.length - 1].focus();
        } else if (!event.shiftKey && document.activeElement === items[items.length - 1]) {
            event.preventDefault();
            items[0].focus();
        }
    }

    countForm.addEventListener('submit', saveArqueo);
    countInput.addEventListener('input', function () {
        clearFieldError(countInput, 'cash_count_amount_error');
        countFeedback.hidden = true;
        updateDifference();
    });
    observationInput.addEventListener('input', function () {
        if (observationInput.value.trim()) clearFieldError(observationInput, 'cash_count_observation_error');
        updateDifference();
    });
    closeButton.addEventListener('click', function () { submitClose(null); });
    authForm.addEventListener('submit', submitAuthorization);
    authCancel.addEventListener('click', function () { closeAuthorization(true); });
    document.addEventListener('keydown', closeDialogOnEscape);
    document.getElementById('cash_arqueo_retry').addEventListener('click', loadState);
    document.body.appendChild(overlay);

    loadState().then(function () {
        if (session && session.is_open && session.is_owner !== false) countInput.focus();
    });
}());
