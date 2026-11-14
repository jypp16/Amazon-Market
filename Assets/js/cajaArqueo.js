(function () {
    'use strict';

    var service = window.CajaArqueoService;
    var loading = document.getElementById('cash_arqueo_loading');
    var workspace = document.getElementById('cash_arqueo_workspace');
    var noSession = document.getElementById('cash_arqueo_no_session');
    var loadError = document.getElementById('cash_arqueo_load_error');
    var statusChip = document.getElementById('cash_arqueo_status');
    var scheduleBadge = null;
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
    var lastCloseReference = null;
    var authForm = document.getElementById('cash_auth_form');
    var emailInput = document.getElementById('cash_admin_email');
    var passwordInput = document.getElementById('cash_admin_password');
    var authReasonInput = document.getElementById('cash_auth_reason');
    var authCancel = document.getElementById('cash_auth_cancel');
    var authSubmit = document.getElementById('cash_auth_submit');
    var authWarning = document.getElementById('cash_auth_warning');
    var authFeedback = document.getElementById('cash_auth_feedback');
    var appLayout = document.querySelector('.app-layout');
    var arqueoConfig = window.CAJA_ARQUEO_CONFIG || {};
    var scheduleState = 'within_hours';
    var schedule = null;
    var sellerId = arqueoConfig.sellerId;
    var sellerUsername = arqueoConfig.sellerUsername || '';

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

    function formatArqueoDateTime(item) {
        var dateTime = item.date_time || item.datetime || item.created_at || item.fecha_hora;
        if (dateTime) {
            var rawDateTime = String(dateTime);
            var isoDateTime = rawDateTime.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/);
            if (isoDateTime) {
                return isoDateTime[3] + '/' + isoDateTime[2] + '/' + isoDateTime[1]
                    + ' ' + isoDateTime[4] + ':' + isoDateTime[5];
            }
            if (/^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}$/.test(rawDateTime)) return rawDateTime;
            var parsedDateTime = new Date(rawDateTime);
            if (!Number.isNaN(parsedDateTime.getTime())) {
                return new Intl.DateTimeFormat('es-PE', {
                    timeZone: 'America/Lima',
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                }).format(parsedDateTime).replace(',', '');
            }
            return rawDateTime;
        }

        var date = item.date || item.arqueo_date || item.fecha;
        var time = item.time || item.hora;
        if (date && time) {
            var isoDate = String(date).match(/^(\d{4})-(\d{2})-(\d{2})$/);
            return (isoDate ? isoDate[3] + '/' + isoDate[2] + '/' + isoDate[1] : date) + ' ' + time;
        }
        return time || '—';
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

    function latestArqueo() {
        return session && Array.isArray(session.history) && session.history.length
            ? session.history[session.history.length - 1] : null;
    }

    function hasUnsavedCount() {
        var current = activeArqueo();
        if (!current) return false;
        if (parseCount() === Number(current.counted)
            && observationInput.value.trim() === String(current.observation || '').trim()) return false;
        showFeedback(closeFeedback,
            'Hay cambios en el conteo o la observación sin guardar. Guarda un nuevo arqueo antes de cerrar.', true);
        countInput.focus();
        return true;
    }

    function renderSchedule(turn) {
        schedule = turn && turn.shift ? turn.shift : null;
        scheduleState = turn && turn.status ? turn.status : 'within_hours';
        var topbarRight = document.querySelector('.cash-opening-topbar .topbar-right');
        if (!scheduleBadge && topbarRight) {
            scheduleBadge = document.createElement('span');
            scheduleBadge.id = 'cash_arqueo_schedule_status';
            scheduleBadge.className = 'cash-schedule-badge';
            topbarRight.insertBefore(scheduleBadge, statusChip);
        }
        if (scheduleBadge) {
            var outside = scheduleState === 'outside_hours';
            scheduleBadge.textContent = outside ? 'Fuera de horario'
                : (scheduleState === 'no_shift' ? 'Sin turno hoy' : 'Dentro del horario');
            scheduleBadge.classList.toggle('is-outside', outside);
            scheduleBadge.classList.toggle('is-no-turn', scheduleState === 'no_shift');
        }
        var notice = document.getElementById('cash_close_schedule_notice');
        if (scheduleState === 'outside_hours' && schedule) {
            notice.textContent = 'El cierre es anticipado: la salida de referencia es a las '
                + (schedule.scheduled_end || schedule.end || '—')
                + '. Antes de los quince minutos previos se requiere autorización del administrador.';
            notice.hidden = false;
            closeButton.textContent = 'Solicitar autorización';
        } else {
            notice.hidden = true;
            closeButton.textContent = 'Confirmar cierre';
        }
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

            time.textContent = formatArqueoDateTime(item);
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
        } else if (latestArqueo() && latestArqueo().status === 'Desactualizado') {
            arqueoStatus.textContent = '✕ Arqueo desactualizado: realiza uno nuevo antes de cerrar';
            arqueoStatus.className = 'is-warning';
        } else if (!latestArqueo()) {
            arqueoStatus.textContent = '✕ No hay ningún arqueo: realiza uno antes de cerrar';
            arqueoStatus.className = 'is-warning';
        } else {
            arqueoStatus.textContent = '⚠ Debes realizar un arqueo nuevo antes de cerrar.';
            arqueoStatus.className = 'is-warning';
        }
        closeButton.disabled = isLoading || !session || !session.is_open || !owned;
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
        if (!isOpen && session && session.closed_summary) {
            noSession.hidden = true;
            workspace.hidden = false;
            showCloseSummary(session.closed_summary);
            return;
        }
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
            observationInput.value = session.history[session.history.length - 1].observation || '';
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
            if (!nextSession) throw new Error('No se recibió el estado de caja.');
            if (!nextSession.is_open) {
                var savedRef = service.getLastCloseRef ? service.getLastCloseRef() : null;
                if (savedRef && savedRef.session_id && savedRef.reference) {
                    return service.getCierre(savedRef.session_id, savedRef.reference).then(function (rec) {
                        if (rec && !rec.pending && rec.data && rec.data.summary) {
                            nextSession.session_id = savedRef.session_id;
                            nextSession.is_owner = true;
                            nextSession.closed_summary = rec.data.summary;
                        }
                        renderSession(nextSession);
                    }).catch(function () {
                        renderSession(nextSession);
                    });
                }
                renderSession(nextSession);
                return;
            }
            return Promise.all([
                nextSession.is_owner !== false
                    ? service.getArqueos(nextSession.session_id)
                    : Promise.resolve({ history: nextSession.history || [] }),
                service.getTurnoDeHoy()
            ]).then(function (results) {
                var arqueosResult = results[0];
                var history = arqueosResult
                    && (arqueosResult.history || arqueosResult.arqueos || arqueosResult.data);
                if (Array.isArray(history)) nextSession.history = history;
                var serverSchedule = nextSession.schedule || results[1];
                if (nextSession.schedule && typeof nextSession.schedule.requires_authorization === 'boolean') {
                    schedule = nextSession.schedule;
                    scheduleState = nextSession.schedule.requires_authorization ? 'outside_hours' : 'within_hours';
                    renderSchedule({
                        status: scheduleState,
                        shift: nextSession.schedule
                    });
                } else {
                    renderSchedule(serverSchedule);
                }
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
                renderSession(nextSession);
            });
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
        closeButton.disabled = value || !session || !session.is_open || session.is_owner === false;
        closeButton.textContent = value && operation === 'close' ? 'Cerrando…'
            : (scheduleState === 'outside_hours' ? 'Solicitar autorización' : 'Confirmar cierre');
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
                var saved = result.arqueo;
                if (!saved || !Number.isInteger(Number(saved.id)) || Number(saved.id) <= 0) {
                    showFeedback(countFeedback, 'El servidor no devolvió un arqueo persistido. Reintenta con la misma referencia.', true);
                    return;
                }
                appendSavedArqueo(saved);
                clearCountRequest();
                showFeedback(countFeedback, 'Arqueo guardado. El nuevo arqueo está vigente.', false);
            } else if (result.type === 'cash_changed') {
                handleChanged(result);
            } else if (result.type === 'session_closed' || result.type === 'session_not_owned') {
                showFeedback(countFeedback, 'La sesión ya no está disponible para este vendedor.', true);
                return loadState();
            } else if (result.type === 'observation_required') {
                setFieldError(observationInput, 'cash_count_observation_error', 'Escribe una observación para explicar la diferencia.');
                observationInput.focus();
            } else {
                showFeedback(countFeedback, 'No se pudo completar la operación. Inténtalo de nuevo.', true);
                countInput.focus();
            }
        }).catch(function () {
            showFeedback(countFeedback, 'No se pudo completar la operación. Inténtalo de nuevo.', true);
            countInput.focus();
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
            [emailInput, 'cash_admin_email_error'],
            [passwordInput, 'cash_admin_password_error'],
            [authReasonInput, 'cash_auth_reason_error']
        ].forEach(function (field) { clearFieldError(field[0], field[1]); });
    }

    function openAuthorization(sched) {
        var end = sched && (sched.end || sched.shift_end || sched.scheduled_end);
        authWarning.textContent = 'La salida de referencia es a las ' + (end || '—')
            + '. Estás cerrando antes del margen de quince minutos; un administrador debe autorizarlo.';
        authFeedback.hidden = true;
        clearAuthErrors();
        emailInput.value = '';
        passwordInput.value = '';
        authReasonInput.value = '';
        returnFocus = document.activeElement;
        overlay.hidden = false;
        document.body.classList.add('cash-auth-open');
        if (appLayout) appLayout.inert = true;
        emailInput.focus();
    }

    function closeAuthorization(resetRequest) {
        overlay.hidden = true;
        document.body.classList.remove('cash-auth-open');
        if (appLayout) appLayout.inert = false;
        emailInput.value = '';
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
        var sid = session && session.session_id;
        // closeCash conserva la referencia antes de enviar; si fetch falla no
        // llega el resultado envuelto que normalmente actualiza esta variable.
        var pending = service.getLastCloseRef();
        if (pending && Number(pending.session_id) === Number(sid)) {
            lastCloseReference = pending.reference;
        }
        return service.getCierre(sid, lastCloseReference).then(function (rec) {
            if (rec && !rec.pending && rec.data && rec.data.summary) {
                if (!overlay.hidden) closeAuthorization(false);
                showCloseSummary(rec.data.summary);
                return;
            }
            showCloseFailure('El cierre aún no se confirmó en el servidor. Puedes reintentar de forma segura.');
        }).catch(function () {
            showCloseFailure();
        });
    }

    function confirmPersistedClose(directSummary) {
        if (directSummary) {
            if (!overlay.hidden) closeAuthorization(false);
            showCloseSummary(directSummary);
            return Promise.resolve();
        }
        var sid = session && session.session_id;
        return service.getCierre(sid, lastCloseReference).then(function (rec) {
            if (!rec || rec.pending || !rec.data || !rec.data.summary) {
                showCloseFailure('Todavía no se recuperó un cierre confirmado. Reintenta.');
                return;
            }
            if (!overlay.hidden) closeAuthorization(false);
            showCloseSummary(rec.data.summary);
        }).catch(function () {
            showCloseFailure();
        });
    }

    function showCloseFailure(customMessage) {
        var msg = customMessage || 'No se pudo completar la operación. Inténtalo de nuevo.';
        if (!overlay.hidden) {
            authFeedback.textContent = msg;
            authFeedback.hidden = false;
        } else {
            showFeedback(closeFeedback, msg, true);
        }
    }

    function handleCloseResult(wrapped) {
        var result = wrapped && wrapped.result ? wrapped.result : wrapped;
        if (wrapped && wrapped.reference) lastCloseReference = wrapped.reference;
        if (result.type === 'closed') {
            return confirmPersistedClose(result.summary || null);
        } else if (result.type === 'authorization_required') {
            renderSchedule({ status: 'outside_hours', shift: result.schedule || {} });
            openAuthorization(result.schedule || {});
        } else if (result.type === 'authorization_denied' || result.type === 'invalid_admin_email') {
            authFeedback.textContent = result.message || 'No se pudo autorizar. Verifica el correo y contraseña.';
            authFeedback.hidden = false;
            if (result.type === 'invalid_admin_email') emailInput.focus();
            else passwordInput.focus();
        } else if (result.type === 'motive_required') {
            setFieldError(authReasonInput, 'cash_auth_reason_error', result.message || 'Escribe el motivo de la excepción.');
            authReasonInput.focus();
        } else if (result.type === 'arqueo_stale' || result.type === 'cash_changed') {
            if (!overlay.hidden) closeAuthorization(true);
            showFeedback(closeFeedback, result.message || 'Debes realizar un arqueo nuevo antes de cerrar.', true);
            countInput.focus();
            return loadState();
        } else if (result.type === 'session_closed' || result.type === 'session_not_owned') {
            if (!overlay.hidden) closeAuthorization(true);
            showFeedback(closeFeedback, result.message || 'La sesión ya no está disponible para este vendedor.', true);
            return loadState();
        } else {
            showCloseFailure(result.message);
        }
    }

    function submitClose(credentials) {
        if (isLoading || !session || !session.is_open) return Promise.resolve();
        if (hasUnsavedCount()) return Promise.resolve();
        if (!activeArqueo()) {
            var message = latestArqueo() && latestArqueo().status === 'Desactualizado'
                ? 'Arqueo desactualizado: realiza uno nuevo antes de cerrar.'
                : 'No hay ningún arqueo vigente. Realiza uno antes de cerrar.';
            showFeedback(closeFeedback, message, true);
            countInput.focus();
            return Promise.resolve();
        }
        var active = activeArqueo();
        var payload = {
            session_id: session.session_id,
            arqueo_id: active.id,
            admin_email: credentials ? credentials.email : undefined,
            admin_password: credentials ? credentials.password : undefined,
            exception_reason: credentials ? credentials.reason : undefined
        };
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
        var emailVal = emailInput.value.trim();
        if (!emailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
            setFieldError(emailInput, 'cash_admin_email_error', 'Ingresa el correo válido del administrador.');
            firstInvalid = firstInvalid || emailInput;
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
            email: emailVal,
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
    closeButton.addEventListener('click', function () {
        if (hasUnsavedCount()) return;
        if (scheduleState === 'outside_hours' && activeArqueo()) {
            openAuthorization(schedule);
            return;
        }
        submitClose(null);
    });
    authForm.addEventListener('submit', submitAuthorization);
    authCancel.addEventListener('click', function () { closeAuthorization(true); });
    document.addEventListener('keydown', closeDialogOnEscape);
    document.getElementById('cash_arqueo_retry').addEventListener('click', loadState);
    document.body.appendChild(overlay);

    loadState().then(function () {
        if (session && session.is_open && session.is_owner !== false) countInput.focus();
    });
}());
