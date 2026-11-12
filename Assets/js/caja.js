(function () {
    'use strict';

    var openingForm = document.getElementById('cash_opening_form');
    var cashInput = document.getElementById('cash_counted');
    var openingButton = document.getElementById('cash_opening_confirm');
    if (!openingForm || !cashInput || !openingButton || !window.CajaService) return;

    var reference = Number(openingForm.dataset.reference);
    var differenceCard = document.getElementById('cash_difference');
    var differenceAmount = document.getElementById('cash_difference_amount');
    var differenceLabel = document.getElementById('cash_difference_label');
    var differenceNote = document.getElementById('cash_difference_note');
    var summaryCounted = document.getElementById('cash_summary_counted');
    var summaryDifference = document.getElementById('cash_summary_difference');
    var summaryDifferenceRow = summaryDifference.parentElement;
    var summaryBalance = document.getElementById('cash_summary_balance');
    var openingFeedback = document.getElementById('cash_opening_feedback');
    var statusChip = document.getElementById('cash_opening_status');
    var scheduleStatus = document.getElementById('cash_schedule_status');
    var scheduleNotice = document.getElementById('cash_opening_schedule_notice');
    var alreadyOpenNotice = document.getElementById('cash_opening_already_open');
    var overlay = document.getElementById('cash_auth_overlay');
    var dialog = overlay.querySelector('[role="dialog"]');
    var authForm = document.getElementById('cash_auth_form');
    var usernameInput = document.getElementById('cash_admin_username');
    var passwordInput = document.getElementById('cash_admin_password');
    var reasonInput = document.getElementById('cash_auth_reason');
    var authCancel = document.getElementById('cash_auth_cancel');
    var authSubmit = document.getElementById('cash_auth_submit');
    var authFeedback = document.getElementById('cash_auth_feedback');
    var appLayout = document.querySelector('.app-layout');
    var openingRequestId = null;
    var openingRequestAmount = null;
    var isLoading = false;
    var returnFocus = null;
    var schedule = null;
    var scheduleState = 'within_hours';
    var openingUnavailable = false;
    var openingConfig = window.CAJA_SERVICE_CONFIG || {};
    var sellerId = openingConfig.sellerId;
    var sellerUsername = openingConfig.sellerUsername || '';

    function formatAmount(amount) {
        return 'S/ ' + new Intl.NumberFormat('es-ES', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.abs(amount));
    }

    function formatDifference(amount) {
        if (amount < 0) return '−' + formatAmount(amount);
        if (amount > 0) return '+' + formatAmount(amount);
        return formatAmount(amount);
    }

    function setDifference(amount, state, label, note) {
        differenceCard.className = 'cash-opening-difference is-' + state;
        differenceAmount.textContent = formatDifference(amount);
        differenceLabel.textContent = label;
        differenceNote.textContent = note;
        summaryDifference.textContent = formatDifference(amount);
        summaryDifferenceRow.className = 'cash-summary-difference is-' + state;
    }

    function parseAmount() {
        var value = cashInput.value.trim();
        if (!/^\d+(?:[,.]\d{1,2})?$/.test(value)) return null;
        var amount = Number(value.replace(',', '.'));
        return Number.isFinite(amount) && amount >= 0 ? Math.round(amount * 100) / 100 : null;
    }

    function setOpeningFieldError(message) {
        if (message) {
            cashInput.setAttribute('aria-invalid', 'true');
            document.getElementById('cash_counted_error').textContent = message;
        } else {
            cashInput.removeAttribute('aria-invalid');
            document.getElementById('cash_counted_error').textContent = '';
        }
    }

    function updatePreview() {
        var counted = parseAmount();
        var valid = counted !== null;
        openingButton.disabled = !valid || isLoading || openingUnavailable;
        openingFeedback.hidden = true;

        if (!valid) {
            differenceCard.className = 'cash-opening-difference is-empty';
            differenceAmount.textContent = '—';
            differenceLabel.textContent = 'Pendiente';
            differenceNote.textContent = 'Ingresa un monto válido para calcular la diferencia.';
            summaryCounted.textContent = '—';
            summaryDifference.textContent = '—';
            summaryBalance.textContent = '—';
            summaryDifferenceRow.className = 'cash-summary-difference';
            return;
        }

        setOpeningFieldError('');
        var difference = counted - reference;
        var state = difference < 0 ? 'shortage' : (difference > 0 ? 'surplus' : 'balanced');
        var label = difference < 0 ? 'Faltante' : (difference > 0 ? 'Sobrante' : 'Sin diferencia');
        var note = difference < 0
            ? 'Se registra como faltante de apertura. No genera un egreso.'
            : (difference > 0
                ? 'Se registra como sobrante de apertura. No modifica el cierre anterior.'
                : 'El efectivo contado coincide con el cierre anterior.');

        setDifference(difference, state, label, note);
        summaryCounted.textContent = formatAmount(counted);
        summaryBalance.textContent = formatAmount(counted);
    }

    function clearFieldError(input, errorId) {
        input.removeAttribute('aria-invalid');
        document.getElementById(errorId).textContent = '';
    }

    function setFieldError(input, errorId, message) {
        input.setAttribute('aria-invalid', 'true');
        document.getElementById(errorId).textContent = message;
    }

    function clearFieldErrors() {
        clearFieldError(usernameInput, 'cash_admin_username_error');
        clearFieldError(passwordInput, 'cash_admin_password_error');
        clearFieldError(reasonInput, 'cash_auth_reason_error');
    }

    function setOutsideSchedule(times) {
        schedule = times || {};
        if (scheduleState !== 'no_shift') {
            scheduleState = 'outside_hours';
            statusChip.textContent = 'Fuera de horario';
            statusChip.classList.add('is-outside');
            scheduleStatus.textContent = 'Fuera del horario';
            scheduleStatus.classList.add('is-outside');
            openingButton.textContent = 'Solicitar autorización';
        }
        if (scheduleState === 'no_shift') {
            document.getElementById('cash_auth_warning').textContent =
                'No tienes un turno programado para hoy. Un administrador debe autorizar esta apertura.';
            return;
        }
        var start = schedule.start || '—';
        var end = schedule.end || '—';
        document.getElementById('cash_auth_warning').textContent =
            'Estás abriendo caja fuera del horario de tu turno (' + start + ' – ' + end +
            '). Un administrador debe autorizar esta apertura.';
    }

    function openAuthorizationDialog(times) {
        if (times) schedule = times;
        setOutsideSchedule(schedule);
        returnFocus = document.activeElement;
        authFeedback.hidden = true;
        clearFieldErrors();
        overlay.hidden = false;
        document.body.classList.add('cash-auth-open');
        if (appLayout) appLayout.inert = true;
        usernameInput.focus();
    }

    function closeAuthorizationDialog(options) {
        options = options || {};
        if (isLoading && !options.force) return;

        overlay.hidden = true;
        document.body.classList.remove('cash-auth-open');
        if (appLayout) appLayout.inert = false;
        passwordInput.value = '';
        clearFieldErrors();
        authFeedback.hidden = true;
        if (options.clearFields) {
            usernameInput.value = '';
            reasonInput.value = '';
        }
        if (options.resetRequest) resetOpeningRequest();

        var target = options.focusTarget || returnFocus;
        returnFocus = null;
        if (target && typeof target.focus === 'function') target.focus();
    }

    function focusableDialogItems() {
        return Array.prototype.slice.call(dialog.querySelectorAll(
            'input:not([disabled]), textarea:not([disabled]), button:not([disabled])'
        )).filter(function (item) {
            return item.offsetParent !== null;
        });
    }

    function showOpeningFeedback(message, isError) {
        openingFeedback.textContent = message;
        openingFeedback.classList.toggle('is-error', Boolean(isError));
        openingFeedback.hidden = false;
    }

    function showAuthorizationFeedback(message) {
        authFeedback.textContent = message;
        authFeedback.hidden = false;
    }

    function showAlreadyOpen() {
        resetOpeningRequest();
        if (!overlay.hidden) closeAuthorizationDialog({ clearFields: true, force: true });
        openingButton.disabled = true;
        openingButton.textContent = 'Caja abierta';
        cashInput.disabled = true;
        alreadyOpenNotice.hidden = false;
        openingUnavailable = true;
        statusChip.textContent = 'Caja abierta';
        statusChip.classList.remove('is-outside');
        statusChip.classList.remove('is-no-turn');
        scheduleStatus.textContent = 'Caja abierta';
        scheduleStatus.classList.remove('is-outside');
        scheduleStatus.classList.remove('is-no-turn');
        showOpeningFeedback('Ya tienes una caja abierta.', true);
    }

    function displayOpened(data) {
        var openingBalance = Number(data.opening_balance);
        var openingDifference = Number(data.opening_difference);
        var balanceIsValid = Number.isFinite(openingBalance);
        var differenceIsValid = Number.isFinite(openingDifference);
        var message = 'Apertura confirmada.';

        if (balanceIsValid) {
            summaryCounted.textContent = formatAmount(openingBalance);
            summaryBalance.textContent = formatAmount(openingBalance);
            cashInput.value = openingBalance.toFixed(2);
        }
        if (differenceIsValid) {
            var state = openingDifference < 0 ? 'shortage' : (openingDifference > 0 ? 'surplus' : 'balanced');
            var label = openingDifference < 0 ? 'Faltante' : (openingDifference > 0 ? 'Sobrante' : 'Sin diferencia');
            var note = openingDifference < 0
                ? 'Se registra como faltante de apertura. No genera un egreso.'
                : (openingDifference > 0
                    ? 'Se registra como sobrante de apertura. No modifica el cierre anterior.'
                    : 'El efectivo contado coincide con el cierre anterior.');
            setDifference(openingDifference, state, label, note);
            message += ' Diferencia de apertura: ' + formatDifference(openingDifference) + '.';
        }
        if (balanceIsValid) message += ' Saldo inicial: ' + formatAmount(openingBalance) + '.';
        if (data.opened_at) {
            var openedAt = new Date(data.opened_at);
            var dateText = Number.isNaN(openedAt.getTime())
                ? data.opened_at
                : new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short' }).format(openedAt);
            message += ' Apertura registrada: ' + dateText + '.';
        }

        resetOpeningRequest();
        openingButton.disabled = true;
        openingButton.textContent = 'Caja abierta';
        statusChip.textContent = 'Caja abierta';
        statusChip.classList.remove('is-outside');
        statusChip.classList.remove('is-no-turn');
        showOpeningFeedback(message, false);

        if (!overlay.hidden) {
            closeAuthorizationDialog({
                clearFields: true,
                focusTarget: openingFeedback,
                force: true
            });
        }
    }

    function handleOpeningResponse(response) {
        if (response.type === 'authorization_required') {
            openAuthorizationDialog(response.schedule);
            return;
        }
        if (response.type === 'already_open') {
            showAlreadyOpen();
            return;
        }
        if (response.type === 'opened') {
            displayOpened(response.data || {});
            return;
        }
        showOpeningFeedback('No se pudo completar la apertura. Inténtalo de nuevo.', true);
    }

    function getRequestId() {
        var amount = parseAmount();
        if (!openingRequestId || openingRequestAmount !== amount) {
            openingRequestId = CajaService.createRequestId();
            openingRequestAmount = amount;
        }
        return openingRequestId;
    }

    function resetOpeningRequest() {
        openingRequestId = null;
        openingRequestAmount = null;
    }

    function validateAuthorization() {
        clearFieldErrors();
        var firstInvalid = null;
        if (!usernameInput.value.trim()) {
            setFieldError(usernameInput, 'cash_admin_username_error', 'Completa este campo.');
            firstInvalid = firstInvalid || usernameInput;
        }
        if (sellerUsername && usernameInput.value.trim().toLowerCase() === sellerUsername.toLowerCase()) {
            setFieldError(usernameInput, 'cash_admin_username_error', 'Un vendedor no puede autorizarse a sí mismo.');
            firstInvalid = firstInvalid || usernameInput;
        }
        if (!passwordInput.value) {
            setFieldError(passwordInput, 'cash_admin_password_error', 'Completa este campo.');
            firstInvalid = firstInvalid || passwordInput;
        }
        if (!reasonInput.value.trim()) {
            setFieldError(reasonInput, 'cash_auth_reason_error', 'Escribe el motivo de la excepción.');
            firstInvalid = firstInvalid || reasonInput;
        }
        if (firstInvalid) firstInvalid.focus();
        return !firstInvalid;
    }

    function setLoading(loading) {
        isLoading = loading;
        dialog.setAttribute('aria-busy', loading ? 'true' : 'false');
        authCancel.disabled = loading;
        authSubmit.disabled = loading;
        authSubmit.textContent = loading ? 'Autorizando y abriendo…' : 'Autorizar y abrir';
        if (!overlay.hidden) {
            usernameInput.disabled = loading;
            passwordInput.disabled = loading;
            reasonInput.disabled = loading;
        }
        openingButton.disabled = loading || openingUnavailable || parseAmount() === null;
    }

    cashInput.addEventListener('input', function () {
        if (openingRequestAmount !== null && parseAmount() !== openingRequestAmount) {
            resetOpeningRequest();
        }
        setOpeningFieldError('');
        updatePreview();
    });

    openingForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (isLoading || openingUnavailable) return;
        if (parseAmount() === null) {
            setOpeningFieldError('Ingresa un monto desde cero con hasta dos decimales.');
            cashInput.focus();
            updatePreview();
            return;
        }
        if (scheduleState !== 'within_hours') {
            openAuthorizationDialog(schedule);
            return;
        }

        openingFeedback.hidden = true;
        openingButton.disabled = true;
        isLoading = true;
        try {
            var response = await CajaService.requestOpening({
                request_id: getRequestId(),
                cash_counted: parseAmount()
            });
            handleOpeningResponse(response);
        } catch (error) {
            showOpeningFeedback('No se pudo completar la apertura. Inténtalo de nuevo.', true);
            cashInput.focus();
        } finally {
            isLoading = false;
            if (openingButton.textContent !== 'Caja abierta') updatePreview();
        }
    });

    authForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (isLoading || !validateAuthorization()) return;

        authFeedback.hidden = true;
        var payload = {
            request_id: getRequestId(),
            cash_counted: parseAmount(),
            admin_username: usernameInput.value.trim(),
            admin_password: passwordInput.value,
            exception_reason: reasonInput.value.trim(),
            operation: 'open'
        };
        setLoading(true);

        var focusAfterResponse = null;
        try {
            var response = await CajaService.authorizeAndOpen(payload);
            if (response.type === 'authorization_denied') {
                showAuthorizationFeedback('No se pudo autorizar. Verifica los datos.');
                focusAfterResponse = passwordInput;
            } else if (response.type === 'motive_required') {
                setFieldError(reasonInput, 'cash_auth_reason_error', 'Escribe el motivo de la excepción.');
                focusAfterResponse = reasonInput;
            } else if (response.type === 'already_open') {
                showAlreadyOpen();
            } else if (response.type === 'opened') {
                displayOpened(response.data || {});
            } else {
                showAuthorizationFeedback('No se pudo completar la apertura. Inténtalo de nuevo.');
            }
        } catch (error) {
            showAuthorizationFeedback('No se pudo completar la apertura. Inténtalo de nuevo.');
        } finally {
            payload.admin_password = '';
            passwordInput.value = '';
            setLoading(false);
            if (focusAfterResponse && !overlay.hidden) focusAfterResponse.focus();
        }
    });

    authCancel.addEventListener('click', function () {
        closeAuthorizationDialog({ clearFields: true });
    });

    document.addEventListener('keydown', function (event) {
        if (overlay.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            if (!isLoading) {
                closeAuthorizationDialog({ clearFields: true });
            }
            return;
        }
        if (event.key !== 'Tab') return;

        var items = focusableDialogItems();
        if (!items.length) return;
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    [
        [usernameInput, 'cash_admin_username_error'],
        [passwordInput, 'cash_admin_password_error'],
        [reasonInput, 'cash_auth_reason_error']
    ].forEach(function (field) {
        field[0].addEventListener('input', function () {
            clearFieldError(field[0], field[1]);
            authFeedback.hidden = true;
        });
    });

    document.body.appendChild(overlay);

    function renderContext(state, today) {
        if (!state || !today) throw new Error('No se recibió el estado de apertura completo.');
        reference = Number(state.reference_amount);
        if (!Number.isFinite(reference)) throw new Error('No se recibió la referencia del cierre anterior.');
        openingForm.dataset.reference = String(reference);
        document.getElementById('cash_reference_amount').textContent = formatAmount(reference);
        document.getElementById('cash_reference_date').textContent = state.reference_date || '—';
        document.getElementById('cash_reference_turn').textContent = state.reference_turn || '—';
        document.getElementById('cash_summary_reference').textContent = formatAmount(reference);

        var shift = today.shift || {};
        schedule = shift;
        scheduleState = today.status || (today.has_shift_today ? 'within_hours' : 'no_shift');
        var isNoShift = scheduleState === 'no_shift';
        var isOutside = scheduleState === 'outside_hours';
        document.getElementById('cash_summary_turn').textContent = isNoShift
            ? 'Sin programar' : (shift.label || shift.name || 'Turno');
        scheduleStatus.textContent = isNoShift ? 'Sin turno hoy'
            : (isOutside ? 'Fuera de horario' : 'Dentro del horario');
        scheduleStatus.classList.toggle('is-outside', isOutside);
        scheduleStatus.classList.toggle('is-no-turn', isNoShift);
        statusChip.textContent = isNoShift ? 'Sin turno hoy'
            : (isOutside ? 'Fuera de horario' : 'Sin caja abierta');
        statusChip.classList.toggle('is-outside', isOutside);
        statusChip.classList.toggle('is-no-turn', isNoShift);
        openingButton.textContent = isOutside || isNoShift ? 'Solicitar autorización' : 'Confirmar apertura';
        if (isNoShift) {
            scheduleNotice.textContent =
                'No tienes un turno programado para hoy. Un administrador debe autorizar esta apertura.';
            scheduleNotice.hidden = false;
        }

        if (state.is_open) {
            showAlreadyOpen();
            return;
        }
        cashInput.value = String(state.counted_amount === undefined ? '' : state.counted_amount).replace('.', ',');
        updatePreview();
    }

    Promise.all([
        typeof CajaService.getEstadoCaja === 'function'
            ? CajaService.getEstadoCaja(sellerId)
            : Promise.resolve({ is_open: false, reference_amount: reference, counted_amount: cashInput.value }),
        typeof CajaService.getTurnoDeHoy === 'function'
            ? CajaService.getTurnoDeHoy(sellerId)
            : Promise.resolve({ status: 'within_hours', shift: { label: 'Turno' } })
    ]).then(function (results) {
        renderContext(results[0], results[1]);
        cashInput.focus();
    }).catch(function () {
        showOpeningFeedback('No se pudo cargar la información de caja. Inténtalo de nuevo.', true);
        cashInput.focus();
    });
}());
