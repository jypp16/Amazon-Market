(function () {
    'use strict';

    var openingForm = document.getElementById('cash_opening_form');
    var shiftSelect = document.getElementById('cash_shift_select');
    var cashInput = document.getElementById('cash_counted');
    var openingButton = document.getElementById('cash_opening_confirm');
    if (!openingForm || !shiftSelect || !cashInput || !openingButton || !window.CajaService) return;

    var reference = null;
    var hasPreviousClose = false;
    var differenceCard = document.getElementById('cash_difference');
    var differenceAmount = document.getElementById('cash_difference_amount');
    var differenceLabel = document.getElementById('cash_difference_label');
    var differenceNote = document.getElementById('cash_difference_note');
    var summaryTurn = document.getElementById('cash_summary_turn');
    var summaryReference = document.getElementById('cash_summary_reference');
    var summaryCounted = document.getElementById('cash_summary_counted');
    var summaryDifference = document.getElementById('cash_summary_difference');
    var summaryDifferenceRow = summaryDifference.parentElement;
    var summaryBalance = document.getElementById('cash_summary_balance');
    var openingFeedback = document.getElementById('cash_opening_feedback');
    var statusChip = document.getElementById('cash_opening_status');
    var scheduleStatus = document.getElementById('cash_schedule_status');
    var scheduleNotice = document.getElementById('cash_opening_schedule_notice');
    var alreadyOpenNotice = document.getElementById('cash_opening_already_open');
    var occupiedTitle = document.getElementById('cash_opening_occupied_title');
    var occupiedMessage = document.getElementById('cash_opening_occupied_message');
    var occupiedLink = document.getElementById('cash_opening_occupied_link');

    var isLoading = false;
    var isSubmitting = false;
    var openingUnavailable = false;
    var todayInfo = null;

    function formatAmount(amount) {
        return 'S/ ' + new Intl.NumberFormat('es-PE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.abs(amount));
    }

    function formatDifference(amount) {
        if (amount < 0) return '−' + formatAmount(amount);
        if (amount > 0) return '+' + formatAmount(amount);
        return formatAmount(amount);
    }

    function formatIsoDateTime(iso) {
        if (!iso) return '—';
        var d = new Date(iso);
        if (Number.isNaN(d.getTime())) return String(iso);
        return d.toLocaleString('es-PE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: false
        });
    }

    function setDifference(amount, state, label, note) {
        differenceCard.className = 'cash-opening-difference is-' + state;
        differenceAmount.textContent = formatDifference(amount);
        differenceLabel.textContent = label;
        differenceNote.textContent = note;
        summaryDifference.textContent = formatDifference(amount);
        summaryDifferenceRow.className = 'cash-summary-difference is-' + state;
    }

    function setNoPreviousDifference() {
        differenceCard.className = 'cash-opening-difference is-empty';
        differenceAmount.textContent = 'No aplica';
        differenceLabel.textContent = 'Primera apertura';
        differenceNote.textContent = 'No existe un cierre anterior confirmado para calcular diferencia.';
        summaryDifference.textContent = 'No aplica';
        summaryDifferenceRow.className = 'cash-summary-difference';
    }

    function parseAmount() {
        var value = cashInput.value.trim();
        if (value === '') return null;
        if (!/^\d+(?:[.,]\d{1,2})?$/.test(value)) return null;
        var amount = Number(value.replace(',', '.'));
        if (!Number.isFinite(amount) || amount < 0) return null;
        return Math.round(amount * 100) / 100;
    }

    function getSelectedShiftId() {
        var val = Number(shiftSelect.value);
        return Number.isInteger(val) && val > 0 ? val : 0;
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

    function showOpeningFeedback(message, isError) {
        openingFeedback.textContent = message;
        openingFeedback.classList.toggle('is-error', Boolean(isError));
        openingFeedback.hidden = false;
    }

    function updateShiftContext() {
        var shiftId = getSelectedShiftId();
        var available = todayInfo && Array.isArray(todayInfo.available_shifts) ? todayInfo.available_shifts : [];
        var scheduled = todayInfo && Array.isArray(todayInfo.scheduled_shifts) ? todayInfo.scheduled_shifts : [];
        var selected = available.find(function (s) { return Number(s.id) === shiftId; }) || null;
        var isScheduled = scheduled.some(function (s) { return Number(s.id) === shiftId; });

        if (!selected) {
            summaryTurn.textContent = 'Sin seleccionar';
            scheduleStatus.textContent = 'Selecciona un turno';
            scheduleStatus.classList.remove('is-outside', 'is-no-turn');
            scheduleNotice.hidden = true;
            return;
        }

        summaryTurn.textContent = selected.label || (selected.name + ' · ' + selected.start + '–' + selected.end);
        var state = todayInfo ? todayInfo.status : 'no_shift';

        if (!todayInfo || !todayInfo.has_shift_today) {
            scheduleStatus.textContent = 'Sin programación hoy';
            scheduleStatus.classList.remove('is-outside');
            scheduleStatus.classList.add('is-no-turn');
            scheduleNotice.textContent = 'No tienes turno programado para hoy. Puedes abrir caja en el turno seleccionado sin autorización administrativa.';
            scheduleNotice.hidden = false;
        } else if (!isScheduled) {
            scheduleStatus.textContent = 'Suplencia';
            scheduleStatus.classList.add('is-outside');
            scheduleStatus.classList.remove('is-no-turn');
            scheduleNotice.textContent = 'Estás seleccionando un turno distinto al programado (suplencia). No requiere credenciales administrativas.';
            scheduleNotice.hidden = false;
        } else if (state === 'outside_hours') {
            scheduleStatus.textContent = 'Fuera de horario';
            scheduleStatus.classList.add('is-outside');
            scheduleStatus.classList.remove('is-no-turn');
            scheduleNotice.textContent = 'Estás abriendo fuera del horario habitual de tu turno. No requiere autorización administrativa.';
            scheduleNotice.hidden = false;
        } else {
            scheduleStatus.textContent = 'Dentro del horario';
            scheduleStatus.classList.remove('is-outside', 'is-no-turn');
            scheduleNotice.hidden = true;
        }
    }

    function updatePreview() {
        var counted = parseAmount();
        var shiftId = getSelectedShiftId();
        var valid = counted !== null && shiftId > 0;
        openingButton.disabled = !valid || isLoading || isSubmitting || openingUnavailable;
        openingFeedback.hidden = true;

        if (counted === null) {
            if (!hasPreviousClose) {
                setNoPreviousDifference();
            } else {
                differenceCard.className = 'cash-opening-difference is-empty';
                differenceAmount.textContent = '—';
                differenceLabel.textContent = 'Pendiente';
                differenceNote.textContent = 'Ingresa un monto válido (desde 0,00 hasta dos decimales).';
                summaryDifference.textContent = '—';
                summaryDifferenceRow.className = 'cash-summary-difference';
            }
            summaryCounted.textContent = '—';
            summaryBalance.textContent = '—';
            return;
        }

        summaryCounted.textContent = formatAmount(counted);
        summaryBalance.textContent = formatAmount(counted);

        if (!hasPreviousClose || reference === null) {
            setNoPreviousDifference();
            return;
        }

        var diff = Math.round((counted - reference) * 100) / 100;
        if (diff < 0) {
            setDifference(diff, 'shortage', 'Faltante', 'Se registrará como faltante de apertura. No genera egreso.');
        } else if (diff > 0) {
            setDifference(diff, 'surplus', 'Sobrante', 'Se registrará como sobrante de apertura. No altera el cierre anterior.');
        } else {
            setDifference(0, 'balanced', 'Sin diferencia', 'El efectivo contado coincide exactamente con el cierre anterior.');
        }
    }

    function showAlreadyOpen(state) {
        openingUnavailable = true;
        openingButton.disabled = true;
        openingButton.textContent = 'Caja no disponible';
        cashInput.disabled = true;
        shiftSelect.disabled = true;
        alreadyOpenNotice.hidden = false;

        if (state && !state.is_owner) {
            occupiedTitle.textContent = 'Caja física ocupada';
            occupiedMessage.textContent = 'La caja física ya tiene una sesión abierta por ' + (state.occupied_by || 'otro vendedor') + '.';
            occupiedLink.hidden = true;
            scheduleStatus.textContent = 'Caja ocupada';
            showOpeningFeedback('La caja física ya se encuentra abierta por otro vendedor.', true);
        } else {
            occupiedTitle.textContent = 'Ya tienes una caja abierta';
            occupiedMessage.textContent = 'No puedes abrir otra sesión mientras esta caja siga abierta.';
            occupiedLink.hidden = false;
            openingButton.textContent = 'Caja abierta';
            scheduleStatus.textContent = 'Caja abierta';
            showOpeningFeedback('Ya tienes una caja abierta.', true);
        }
        if (statusChip) {
            statusChip.textContent = scheduleStatus.textContent;
            statusChip.classList.remove('is-outside', 'is-no-turn');
        }
    }

    function displayOpened(data, message) {
        var openingBalance = Number(data.opening_balance);
        var hasOpeningDiff = data.opening_difference !== null && data.opening_difference !== undefined;
        var openingDifference = hasOpeningDiff ? Number(data.opening_difference) : null;

        if (Number.isFinite(openingBalance)) {
            summaryCounted.textContent = formatAmount(openingBalance);
            summaryBalance.textContent = formatAmount(openingBalance);
            cashInput.value = openingBalance.toFixed(2).replace('.', ',');
        }
        if (!hasOpeningDiff || !Number.isFinite(openingDifference)) {
            setNoPreviousDifference();
        } else {
            var state = openingDifference < 0 ? 'shortage' : (openingDifference > 0 ? 'surplus' : 'balanced');
            var label = openingDifference < 0 ? 'Faltante' : (openingDifference > 0 ? 'Sobrante' : 'Sin diferencia');
            var note = openingDifference < 0
                ? 'Registrado como faltante de apertura.'
                : (openingDifference > 0 ? 'Registrado como sobrante de apertura.' : 'Coincide con el cierre anterior.');
            setDifference(openingDifference, state, label, note);
        }

        openingUnavailable = true;
        openingButton.disabled = true;
        openingButton.textContent = 'Caja abierta';
        cashInput.disabled = true;
        shiftSelect.disabled = true;
        alreadyOpenNotice.hidden = false;
        occupiedTitle.textContent = 'Caja abierta';
        occupiedMessage.textContent = message || 'La sesión de caja quedó abierta y lista para operar.';
        occupiedLink.hidden = false;
        showOpeningFeedback(message || 'Apertura confirmada.', false);
    }

    function renderContext(state, today) {
        todayInfo = today;
        hasPreviousClose = Boolean(state.has_previous_close && state.reference_amount !== null);
        reference = hasPreviousClose ? Number(state.reference_amount) : null;

        var refAmountEl = document.getElementById('cash_reference_amount');
        var refMetaEl = document.getElementById('cash_reference_meta');
        if (!hasPreviousClose) {
            refAmountEl.textContent = 'Sin cierre anterior';
            refMetaEl.textContent = 'Primera apertura histórica · diferencia de apertura: No aplica';
            summaryReference.textContent = 'Sin cierre anterior';
            openingForm.dataset.reference = '';
        } else {
            refAmountEl.textContent = formatAmount(reference);
            refMetaEl.innerHTML = 'Contado al cerrar <span id="cash_reference_date"></span> · turno <span id="cash_reference_turn"></span>';
            document.getElementById('cash_reference_date').textContent = formatIsoDateTime(state.reference_date);
            document.getElementById('cash_reference_turn').textContent = state.reference_turn || '—';
            summaryReference.textContent = formatAmount(reference);
            openingForm.dataset.reference = String(reference);
        }

        shiftSelect.textContent = '';
        var available = Array.isArray(today.available_shifts) ? today.available_shifts : [];
        var scheduledIds = new Set((today.scheduled_shifts || []).map(function (s) { return Number(s.id); }));
        if (!available.length) {
            var emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = 'No hay turnos activos disponibles';
            shiftSelect.appendChild(emptyOpt);
            openingUnavailable = true;
        } else {
            available.forEach(function (s) {
                var opt = document.createElement('option');
                opt.value = String(s.id);
                var suffix = scheduledIds.has(Number(s.id)) ? ' (Programado hoy)' : '';
                opt.textContent = (s.label || (s.name + ' · ' + s.start + '–' + s.end)) + suffix;
                shiftSelect.appendChild(opt);
            });
            if (today.recommended_shift_id) {
                shiftSelect.value = String(today.recommended_shift_id);
            }
        }

        updateShiftContext();

        if (state.is_open) {
            showAlreadyOpen(state);
            return;
        }

        if (state.counted_amount !== null && state.counted_amount !== undefined) {
            cashInput.value = Number(state.counted_amount).toFixed(2).replace('.', ',');
        }
        updatePreview();
    }

    cashInput.addEventListener('input', function () {
        var raw = cashInput.value.trim();
        if (raw !== '' && !/^\d+(?:[.,]\d{1,2})?$/.test(raw)) {
            setOpeningFieldError('Ingresa un monto válido desde 0,00 con máximo dos decimales (sin redondear).');
        } else {
            setOpeningFieldError('');
        }
        updatePreview();
    });

    shiftSelect.addEventListener('change', function () {
        updateShiftContext();
        updatePreview();
    });

    openingForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (isLoading || isSubmitting || openingUnavailable) return;

        var counted = parseAmount();
        var shiftId = getSelectedShiftId();
        if (shiftId <= 0) {
            showOpeningFeedback('Selecciona un turno válido para abrir la caja.', true);
            shiftSelect.focus();
            return;
        }
        if (counted === null) {
            setOpeningFieldError('Ingresa un monto válido desde 0,00 con máximo dos decimales.');
            cashInput.focus();
            updatePreview();
            return;
        }

        isSubmitting = true;
        isLoading = true;
        openingButton.disabled = true;
        openingFeedback.hidden = true;

        try {
            var response = await CajaService.requestOpening({
                shift_id: shiftId,
                cash_counted: counted
            });
            if (response.type === 'opened') {
                displayOpened(response.data || {}, response.message);
            } else if (response.type === 'already_open') {
                var freshState = await CajaService.getEstadoCaja().catch(function () { return null; });
                showAlreadyOpen(freshState || { is_open: true, is_owner: false });
            } else if (response.type === 'in_progress' || response.type === 'uncertain') {
                showOpeningFeedback(response.message, true);
            } else {
                showOpeningFeedback(response.message || 'No se pudo completar la apertura.', true);
            }
        } catch (error) {
            showOpeningFeedback(error.message || 'Error de conexión al intentar abrir la caja.', true);
        } finally {
            isSubmitting = false;
            isLoading = false;
            if (!openingUnavailable) updatePreview();
        }
    });

    isLoading = true;
    Promise.all([
        CajaService.getEstadoCaja(),
        CajaService.getTurnoDeHoy()
    ]).then(function (results) {
        isLoading = false;
        renderContext(results[0], results[1]);
        if (!openingUnavailable) cashInput.focus();
    }).catch(function (error) {
        isLoading = false;
        openingUnavailable = true;
        openingButton.disabled = true;
        showOpeningFeedback((error && error.message) || 'No se pudo cargar la información de caja.', true);
    });
}());
