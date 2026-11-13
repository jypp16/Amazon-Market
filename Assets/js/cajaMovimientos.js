(function () {
    'use strict';

    var config = window.CAJA_MOVEMENTS_CONFIG || {};
    var service = window.CajaMovimientosService;
    var loading = document.getElementById('cash_movements_loading');
    var noSession = document.getElementById('cash_movements_no_session');
    var loadError = document.getElementById('cash_movements_load_error');
    var retryButton = document.getElementById('cash_movements_retry');
    var workspace = document.getElementById('cash_movements_workspace');
    var status = document.getElementById('cash_movements_status');
    var confirmation = document.getElementById('cash_movements_confirmation');
    var incomeForm = document.getElementById('cash_income_form');
    var expenseForm = document.getElementById('cash_expense_form');
    var correctionForm = document.getElementById('cash_correction_form');
    if (!service || !workspace) return;

    var session = null;
    var administrators = [];
    var closedTurns = [];
    var currentTab = 'income';
    var submitting = false;
    var requestFingerprint = null;
    var requestId = null;
    var correctionData = null;
    var correctionLoading = false;
    var sellerUsername = config.sellerUsername || '';
    var expenseTypes = config.expenseTypes || [
        { value: 'cash_out', label: 'Salida de caja' },
        { value: 'admin_withdrawal', label: 'Retiro del administrador' },
        { value: 'omitted_withdrawal', label: 'Retiro omitido' }
    ];

    var income = {
        amount: document.getElementById('cash_income_amount'),
        reason: document.getElementById('cash_income_reason'),
        typeGroup: document.querySelectorAll('input[name="income_type"]'),
        adminGroup: document.getElementById('cash_income_admin_group'),
        admin: document.getElementById('cash_income_admin'),
        submit: document.getElementById('cash_income_submit'),
        feedback: document.getElementById('cash_income_feedback')
    };
    var expense = {
        amount: document.getElementById('cash_expense_amount'),
        reason: document.getElementById('cash_expense_reason'),
        typeGroup: document.querySelectorAll('input[name="expense_type"]'),
        adminGroup: document.getElementById('cash_expense_admin_group'),
        admin: document.getElementById('cash_expense_admin'),
        originGroup: document.getElementById('cash_origin_turn_group'),
        originTurn: document.getElementById('cash_origin_turn'),
        confirmGroup: document.getElementById('cash_confirm_omitted_group'),
        confirmOmitted: document.getElementById('cash_confirm_omitted'),
        submit: document.getElementById('cash_expense_submit'),
        feedback: document.getElementById('cash_expense_feedback')
    };
    var correction = {
        original: document.getElementById('cash_correction_original'),
        amount: document.getElementById('cash_correction_amount'),
        reason: document.getElementById('cash_correction_reason'),
        submit: document.getElementById('cash_correction_submit'),
        cancel: document.getElementById('cash_correction_cancel'),
        feedback: document.getElementById('cash_correction_feedback')
    };

    function formatMoney(value) {
        return 'S/ ' + new Intl.NumberFormat('es-ES', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.abs(value));
    }

    function formatSignedMoney(value) {
        if (value < 0) return '−' + formatMoney(value);
        if (value > 0) return '+' + formatMoney(value);
        return formatMoney(0);
    }

    function parseAmount(input) {
        var value = input.value.trim();
        if (!/^(?:\d+(?:[.,]\d{1,2})?|[.,]\d{1,2})$/.test(value)) return null;
        var amount = Number(value.replace(',', '.'));
        return Number.isFinite(amount) && amount > 0 ? amount : null;
    }

    function selectedValue(group) {
        for (var index = 0; index < group.length; index++) {
            if (group[index].checked) return group[index].value;
        }
        return '';
    }

    function currentExpenseType() {
        return selectedValue(expense.typeGroup);
    }

    function isOmittedWithdrawal() {
        return currentExpenseType() === 'omitted_withdrawal';
    }

    function isAdministratorWithdrawal() {
        return currentExpenseType() !== 'cash_out';
    }

    function fieldError(id) {
        return document.getElementById(id);
    }

    function setFieldError(input, errorId, message) {
        input.setAttribute('aria-invalid', 'true');
        fieldError(errorId).textContent = message;
    }

    function clearFieldError(input, errorId) {
        input.removeAttribute('aria-invalid');
        fieldError(errorId).textContent = '';
    }

    function clearErrors(form) {
        form.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
            input.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('.cash-movement-error').forEach(function (error) {
            error.textContent = '';
        });
    }

    function setStatus(open) {
        status.textContent = open
            ? 'Caja abierta · ' + session.seller + ' · Turno ' + session.turn
            : 'Caja cerrada';
        status.classList.toggle('is-outside', !open);
    }

    function correctionError(input, errorId, message) {
        if (message) setFieldError(input, errorId, message);
        else clearFieldError(input, errorId);
    }

    function selectedCorrectionMovement() {
        if (!correctionData) return null;
        return (correctionData.movements || []).find(function (movement) {
            return String(movement.id) === String(correction.original.value);
        }) || null;
    }

    function renderCorrectionDetail() {
        var movement = selectedCorrectionMovement();
        var detail = document.getElementById('cash_correction_original_detail');
        if (!movement) {
            detail.textContent = '';
            detail.hidden = true;
            return;
        }
        var originalAmount = Number(movement.amount);
        var currentAmount = Number(movement.current_amount !== undefined
            ? movement.current_amount : movement.amount);
        detail.textContent = (movement.label || movement.type || 'Movimiento')
            + ' · ' + (movement.reference || movement.reason || 'Sin referencia')
            + ' · Importe original: ' + formatMoney(originalAmount)
            + (currentAmount !== originalAmount ? ' · Importe vigente: ' + formatMoney(currentAmount) : '')
            + ' · Estado: ' + (movement.status || 'Confirmado');
        detail.hidden = false;
    }

    function renderCorrectionLedger() {
        var tbody = document.getElementById('cash_correction_history_rows');
        tbody.replaceChildren();
        var entries = [];
        (correctionData && correctionData.movements || []).forEach(function (movement) {
            entries.push({
                label: 'Original · ' + (movement.status || 'Confirmado'),
                reference: movement.reference || movement.id,
                amount: formatMoney(movement.amount),
                note: movement.reason || 'Registro original conservado',
                order: movement.recorded_at || ''
            });
        });
        (correctionData && correctionData.corrections || []).forEach(function (item) {
            entries.push({
                label: 'Corrección vinculada · ' + (item.status || 'Registrada'),
                reference: item.original_reference || item.original_movement_id,
                amount: formatMoney(item.corrected_amount),
                note: (item.reason || '—') + ' · Efecto: ' + (item.effect || 'Pendiente del servidor'),
                order: item.recorded_at || ''
            });
        });
        entries.sort(function (left, right) {
            return String(left.order).localeCompare(String(right.order));
        });
        if (!entries.length) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 4;
            emptyCell.textContent = 'No hay movimientos confirmados disponibles para corregir.';
            emptyRow.appendChild(emptyCell);
            tbody.appendChild(emptyRow);
            return;
        }
        entries.forEach(function (entry) {
            var row = document.createElement('tr');
            [entry.label, entry.reference, entry.amount, entry.note].forEach(function (value) {
                var cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });
            tbody.appendChild(row);
        });
    }

    function renderCorrectionOptions() {
        var selectedValue = correction.original.value;
        correction.original.replaceChildren(new Option('Selecciona el movimiento que se registró mal', ''));
        (correctionData && correctionData.movements || []).forEach(function (movement) {
            var option = new Option(
                (movement.label || movement.type || 'Movimiento') + ' · '
                    + (movement.reference || movement.id) + ' · ' + formatMoney(movement.amount),
                movement.id
            );
            correction.original.add(option);
        });
        correction.original.value = selectedValue;
        correction.submit.disabled = submitting || correctionLoading
            || !(correctionData && correctionData.movements && correctionData.movements.length);
        renderCorrectionDetail();
        renderCorrectionLedger();
    }

    function loadCorrectionData() {
        if (correctionData || correctionLoading || typeof service.listCorrectableMovements !== 'function') return;
        correctionLoading = true;
        correction.feedback.hidden = true;
        correction.submit.disabled = true;
        correction.submit.textContent = 'Cargando registros…';
        service.listCorrectableMovements(session.session_id || config.sessionId).then(function (result) {
            if (!result || !Array.isArray(result.movements) || !Array.isArray(result.corrections)) {
                throw new Error('No se recibió el historial de correcciones.');
            }
            correctionData = result;
            renderCorrectionOptions();
        }).catch(function () {
            correction.feedback.textContent = 'No se pudieron cargar los registros para corregir. Inténtalo de nuevo.';
            correction.feedback.classList.add('is-error');
            correction.feedback.hidden = false;
        }).finally(function () {
            correctionLoading = false;
            correction.submit.textContent = 'Solicitar corrección';
            renderCorrectionOptions();
        });
    }

    function showNoSession() {
        session.is_open = false;
        setStatus(false);
        loading.hidden = true;
        workspace.hidden = true;
        noSession.hidden = false;
    }

    function populateSelect(select, options, placeholder, format) {
        select.replaceChildren(new Option(placeholder, ''));
        options.forEach(function (option) {
            select.add(new Option(format(option), option.id));
        });
    }

    function loadWorkspace() {
        loading.hidden = false;
        noSession.hidden = true;
        loadError.hidden = true;
        workspace.hidden = true;
        status.textContent = 'Cargando estado de caja…';
        service.getSessionState().then(function (state) {
            session = state;
            if (!state.is_open) {
                showNoSession();
                return null;
            }
            if (!Number.isFinite(Number(state.balance))) {
                throw new Error('El servicio de caja no devolvió un saldo válido.');
            }
            return Promise.all([
                service.listActiveAdministrators(),
                service.listClosedTurns()
            ]);
        }).then(function (lists) {
            if (!lists) return;
            administrators = lists[0];
            closedTurns = lists[1];
            populateSelect(income.admin, administrators, 'Selecciona un administrador', function (admin) {
                return admin.name || admin.username;
            });
            populateSelect(expense.admin, administrators, 'Selecciona un administrador', function (admin) {
                return admin.name || admin.username;
            });
            populateSelect(expense.originTurn, closedTurns, 'Selecciona el turno cerrado', function (turn) {
                return turn.label;
            });
            loading.hidden = true;
            workspace.hidden = false;
            setStatus(true);
            renderPreview();
        }).catch(function () {
            loading.hidden = true;
            workspace.hidden = true;
            noSession.hidden = true;
            loadError.hidden = false;
            status.textContent = 'Estado de caja no disponible';
        });
    }

    function activeForm() {
        return currentTab === 'income' ? incomeForm : expenseForm;
    }

    function activeFeedback() {
        if (currentTab === 'income') return income.feedback;
        if (currentTab === 'expense') return expense.feedback;
        return correction.feedback;
    }

    function renderOriginClose(turnId) {
        var turn = closedTurns.find(function (item) { return item.id === turnId; });
        var closePanel = document.getElementById('cash_origin_close');
        closePanel.hidden = !turn;
        if (!turn) return;
        document.getElementById('cash_origin_expected').textContent = formatMoney(Number(turn.expected));
        document.getElementById('cash_origin_counted').textContent = formatMoney(Number(turn.counted));
        document.getElementById('cash_origin_shortage').textContent = formatMoney(Number(turn.shortage));
    }

    function renderPreview() {
        if (!session) return;
        var current = Number(session.balance);
        document.getElementById('cash_effect_current').textContent = formatMoney(current);
        var label = document.getElementById('cash_effect_label');
        var impact = document.getElementById('cash_effect_amount');
        var totalLabel = document.getElementById('cash_effect_total_label');
        var total = document.getElementById('cash_effect_total');
        var omittedNote = document.getElementById('cash_omitted_note');
        var balanceNote = document.getElementById('cash_balance_note');
        var closePanel = document.getElementById('cash_origin_close');
        var originId = expense.originTurn.value;

        if (currentTab === 'correction') {
            label.textContent = 'Corrección vinculada';
            label.className = 'cash-effect-neutral';
            impact.className = 'cash-effect-neutral';
            impact.textContent = 'Se calcula al autorizar';
            totalLabel.textContent = 'Saldo después';
            total.textContent = 'Pendiente de autorización';
            omittedNote.hidden = true;
            closePanel.hidden = true;
            balanceNote.textContent =
                'El servidor confirma el efecto de la corrección. No se crea un ingreso ni un egreso físico nuevo.';
            correction.submit.disabled = submitting || correctionLoading
                || !(correctionData && correctionData.movements && correctionData.movements.length);
            return;
        }

        if (currentTab === 'income') {
            var amount = parseAmount(income.amount);
            var injection = selectedValue(income.typeGroup) === 'admin_injection';
            var administratorMissing = injection && !income.admin.value;
            income.adminGroup.hidden = !injection;
            label.textContent = injection ? 'Ingreso (inyección)' : 'Ingreso';
            label.className = 'cash-effect-income';
            impact.className = 'cash-effect-income';
            totalLabel.textContent = 'Saldo previsto';
            total.textContent = amount && !administratorMissing ? formatMoney(current + amount) : formatMoney(current);
            impact.textContent = amount && !administratorMissing ? '+' + formatMoney(amount) : '+S/ 0,00';
            omittedNote.hidden = true;
            closePanel.hidden = true;
            balanceNote.textContent = administratorMissing
                ? 'Selecciona el administrador que entregó el efectivo; el saldo no cambia hasta confirmar.'
                : 'El saldo se actualiza una sola vez, aunque se repita el envío.';
            income.submit.disabled = submitting;
            return;
        }

        var expenseAmount = parseAmount(expense.amount);
        var expenseKind = currentExpenseType();
        var omitted = expenseKind === 'omitted_withdrawal';
        var needsAdmin = expenseKind !== 'cash_out';
        var missingAdmin = needsAdmin && !expense.admin.value;
        expense.adminGroup.hidden = !needsAdmin;
        expense.originGroup.hidden = !omitted;
        expense.confirmGroup.hidden = !omitted;
        omittedNote.hidden = !omitted;
        closePanel.hidden = !omitted || !originId;
        renderOriginClose(omitted ? originId : '');

        label.textContent = omitted ? 'Impacto de este registro' : 'Egreso';
        label.className = omitted ? 'cash-effect-neutral' : 'cash-effect-expense';
        impact.className = omitted ? 'cash-effect-neutral' : 'cash-effect-expense';
        totalLabel.textContent = omitted ? 'Saldo sin cambios' : 'Saldo después';
        impact.textContent = omitted ? 'S/ 0,00' : (expenseAmount ? '−' + formatMoney(expenseAmount) : '−S/ 0,00');
        total.textContent = omitted
            ? formatMoney(current)
            : (expenseAmount ? formatSignedMoney(current - expenseAmount) : formatMoney(current));

        var amountError = fieldError('cash_expense_amount_error');
        var insufficient = !omitted && expenseAmount !== null && expenseAmount > current;
        if (insufficient) {
            setFieldError(expense.amount, 'cash_expense_amount_error', 'El monto supera el saldo disponible.');
        } else if (expense.amount.getAttribute('aria-invalid') === 'true'
            && amountError.textContent === 'El monto supera el saldo disponible.') {
            clearFieldError(expense.amount, 'cash_expense_amount_error');
        }
        if (missingAdmin) {
            balanceNote.textContent = 'Selecciona al administrador que recibió el efectivo; el saldo no cambia sin confirmar un retiro válido.';
        } else {
            balanceNote.textContent = omitted
                ? 'El registro documenta el retiro del turno de origen y no altera el saldo de esta sesión.'
                : 'El saldo se actualiza una sola vez, aunque se repita el envío.';
        }
        expense.submit.disabled = submitting || insufficient;
    }

    function validateAmount(input, errorId) {
        var amount = parseAmount(input);
        if (amount === null) {
            setFieldError(input, errorId, 'Ingresa un monto mayor que cero con hasta dos decimales.');
            return null;
        }
        clearFieldError(input, errorId);
        return amount;
    }

    function validateReason(input, errorId) {
        var reason = input.value.trim();
        if (!reason) {
            setFieldError(input, errorId, 'Escribe el motivo del movimiento.');
            return '';
        }
        clearFieldError(input, errorId);
        return reason;
    }

    function validateIncome() {
        clearErrors(incomeForm);
        var amount = validateAmount(income.amount, 'cash_income_amount_error');
        var reason = validateReason(income.reason, 'cash_income_reason_error');
        var type = selectedValue(income.typeGroup);
        var administratorId = '';
        if (type === 'admin_injection') {
            administratorId = income.admin.value;
            if (!administratorId) {
                setFieldError(income.admin, 'cash_income_admin_error',
                    'Selecciona al administrador que entregó el efectivo. El saldo no cambia.');
            }
        }
        if (amount === null || !reason || (type === 'admin_injection' && !administratorId)) return null;
        return {
            type: type,
            amount: amount,
            reason: reason,
            administrator_id: administratorId || null
        };
    }

    function validateExpense() {
        clearErrors(expenseForm);
        var amount = validateAmount(expense.amount, 'cash_expense_amount_error');
        var reason = validateReason(expense.reason, 'cash_expense_reason_error');
        var type = currentExpenseType();
        var administratorId = '';
        var originTurnId = '';
        var confirmed = false;

        if (isAdministratorWithdrawal()) {
            administratorId = expense.admin.value;
            if (!administratorId) {
                setFieldError(expense.admin, 'cash_expense_admin_error',
                    'Selecciona al administrador que recibió el efectivo. El saldo no cambia.');
            }
        }
        if (isOmittedWithdrawal()) {
            originTurnId = expense.originTurn.value;
            confirmed = expense.confirmOmitted.checked;
            if (!originTurnId) {
                setFieldError(expense.originTurn, 'cash_origin_turn_error', 'Selecciona el turno de origen.');
            }
            if (!confirmed) {
                setFieldError(expense.confirmOmitted, 'cash_confirm_omitted_error',
                    'Confirma que el retiro ocurrió con la caja anterior abierta.');
            }
        } else if (amount !== null && amount > Number(session.balance)) {
            setFieldError(expense.amount, 'cash_expense_amount_error', 'El monto supera el saldo disponible.');
        }

        if (amount === null || !reason
            || (isAdministratorWithdrawal() && !administratorId)
            || (isOmittedWithdrawal() && (!originTurnId || !confirmed))
            || (!isOmittedWithdrawal() && amount > Number(session.balance))) return null;
        return {
            type: type,
            amount: amount,
            reason: reason,
            administrator_id: administratorId || null,
            origin_turn_id: originTurnId || null,
            confirm_previous_open: confirmed
        };
    }

    function validateCorrection() {
        var firstInvalid = null;
        var movement = selectedCorrectionMovement();
        var amountText = correction.amount.value.trim();
        var amount = /^\d+(?:[,.]\d{1,2})?$/.test(amountText)
            ? Number(amountText.replace(',', '.')) : NaN;
        var reason = correction.reason.value.trim();

        [
            [correction.original, 'cash_correction_original_error'],
            [correction.amount, 'cash_correction_amount_error'],
            [correction.reason, 'cash_correction_reason_error']
        ].forEach(function (field) { clearFieldError(field[0], field[1]); });

        if (!movement) {
            setFieldError(correction.original, 'cash_correction_original_error',
                'Selecciona el registro original que contiene el error.');
            firstInvalid = firstInvalid || correction.original;
        }
        if (!Number.isFinite(amount) || amount < 0) {
            setFieldError(correction.amount, 'cash_correction_amount_error',
                'Ingresa el importe correcto, desde cero y con hasta dos decimales.');
            firstInvalid = firstInvalid || correction.amount;
        }
        if (!reason) {
            setFieldError(correction.reason, 'cash_correction_reason_error',
                'Describe el error comprobado y el motivo de la corrección.');
            firstInvalid = firstInvalid || correction.reason;
        }
        if (firstInvalid) {
            firstInvalid.focus();
            return null;
        }
        return {
            original_movement_id: movement.id,
            corrected_amount: Math.round(amount * 100) / 100,
            reason: reason
        };
    }

    function getRequestId(payload) {
        var fingerprint = JSON.stringify(payload);
        if (!requestId || requestFingerprint !== fingerprint) {
            requestFingerprint = fingerprint;
            requestId = service.createRequestId();
        }
        return requestId;
    }

    function clearRequestId() {
        requestId = null;
        requestFingerprint = null;
    }

    function setFormLoading(loadingState) {
        submitting = loadingState;
        income.submit.disabled = loadingState;
        expense.submit.disabled = loadingState;
        correction.submit.disabled = loadingState || correctionLoading
            || !(correctionData && correctionData.movements && correctionData.movements.length);
        document.getElementById('cash_income_cancel').disabled = loadingState;
        document.getElementById('cash_expense_cancel').disabled = loadingState;
        correction.cancel.disabled = loadingState;
        income.submit.textContent = loadingState ? 'Registrando…' : 'Confirmar ingreso';
        expense.submit.textContent = loadingState ? 'Registrando…' : (isOmittedWithdrawal() ? 'Registrar retiro omitido' : 'Confirmar egreso');
        correction.submit.textContent = loadingState ? 'Validando con el administrador…' : 'Solicitar corrección';
    }

    function showFeedback(message, isError, formType) {
        var feedback = formType === 'correction' ? correction.feedback : activeFeedback();
        feedback.textContent = message;
        feedback.classList.toggle('is-error', Boolean(isError));
        feedback.hidden = false;
    }

    function showClosedSession() {
        clearRequestId();
        setFormLoading(false);
        showNoSession();
    }

    function applyServerErrors(result, form) {
        var focusTarget = null;
        if (result.type === 'cash_closed') {
            showClosedSession();
            return;
        }
        if (result.type === 'insufficient_balance') {
            if (Number.isFinite(Number(result.current_balance))) {
                session.balance = Number(result.current_balance);
            }
            if (form === 'expense') {
                setFieldError(expense.amount, 'cash_expense_amount_error', 'El saldo disponible cambió. Revisa el monto.');
                focusTarget = expense.amount;
            }
            renderPreview();
            if (focusTarget) focusTarget.focus();
            return;
        }
        if (result.type === 'amount_invalid') {
            var amountInput = form === 'income' ? income.amount : expense.amount;
            setFieldError(amountInput, form === 'income' ? 'cash_income_amount_error' : 'cash_expense_amount_error',
                'Ingresa un monto mayor que cero con hasta dos decimales.');
            amountInput.focus();
        } else if (result.type === 'reason_required') {
            var reasonInput = form === 'income' ? income.reason : expense.reason;
            setFieldError(reasonInput, form === 'income' ? 'cash_income_reason_error' : 'cash_expense_reason_error',
                'Escribe el motivo del movimiento.');
            reasonInput.focus();
        } else if (result.type === 'administrator_required') {
            var adminInput = form === 'income' ? income.admin : expense.admin;
            var errorId = form === 'income' ? 'cash_income_admin_error' : 'cash_expense_admin_error';
            setFieldError(adminInput, errorId,
                'Selecciona el administrador requerido. El saldo no cambia.');
            adminInput.focus();
        } else if (result.type === 'origin_turn_required') {
            setFieldError(expense.originTurn, 'cash_origin_turn_error', 'Selecciona el turno de origen.');
            expense.originTurn.focus();
        } else if (result.type === 'confirmation_required') {
            setFieldError(expense.confirmOmitted, 'cash_confirm_omitted_error',
                'Confirma que el retiro ocurrió con la caja anterior abierta.');
            expense.confirmOmitted.focus();
        } else {
            showFeedback('No se pudo registrar el movimiento. Inténtalo de nuevo.', true);
        }
    }

    function applySuccess(result) {
        var data = result.data || {};
        var newBalance = Number(data.balance !== undefined ? data.balance : data.new_balance);
        if (!Number.isFinite(newBalance)) {
            showFeedback('No se pudo registrar el movimiento. Inténtalo de nuevo.', true);
            return false;
        }
        session.balance = newBalance;
        setStatus(true);
        incomeForm.reset();
        expenseForm.reset();
        clearErrors(incomeForm);
        clearErrors(expenseForm);
        income.feedback.hidden = true;
        expense.feedback.hidden = true;
        clearRequestId();
        renderPreview();
        showFeedback('Movimiento registrado. Saldo actualizado: ' + formatMoney(newBalance) + '.', false);
        return true;
    }

    function submitMovement(formType) {
        var form = formType === 'income' ? incomeForm : expenseForm;
        var payload = formType === 'income' ? validateIncome() : validateExpense();
        if (!payload || submitting || !session || !session.is_open) return;

        var fingerprintPayload = Object.assign({}, payload);
        payload.request_id = getRequestId(fingerprintPayload);
        var feedback = formType === 'income' ? income.feedback : expense.feedback;
        feedback.hidden = true;
        setFormLoading(true);
        var register = formType === 'income' ? service.registerIncome : service.registerExpense;

        register(payload).then(function (result) {
            if (result.type === 'success') {
                applySuccess(result);
            } else {
                applyServerErrors(result, formType);
            }
        }).catch(function () {
            showFeedback('No se pudo registrar el movimiento. Inténtalo de nuevo.', true);
        }).finally(function () {
            setFormLoading(false);
            renderPreview();
        });
    }

    function submitCorrection() {
        if (submitting || !session || !session.is_open) return;
        var payload = validateCorrection();
        if (!payload) return;
        payload.session_id = session.session_id || config.sessionId || null;
        var fingerprint = {
            session_id: payload.session_id,
            original_movement_id: payload.original_movement_id,
            corrected_amount: payload.corrected_amount,
            reason: payload.reason
        };
        payload.request_id = getRequestId(fingerprint);
        correction.feedback.hidden = true;
        setFormLoading(true);
        service.requestCorrection(payload).then(function (result) {
            if (result.type !== 'success') {
                if (result.type === 'cash_closed') {
                    showClosedSession();
                    return;
                }
                if (result.type === 'movement_required' || result.type === 'movement_not_found') {
                    setFieldError(correction.original, 'cash_correction_original_error',
                        'El registro original ya no está disponible. Actualiza la lista.');
                    correction.original.focus();
                } else if (result.type === 'amount_invalid') {
                    setFieldError(correction.amount, 'cash_correction_amount_error',
                        'Ingresa el importe correcto, desde cero y con hasta dos decimales.');
                    correction.amount.focus();
                } else if (result.type === 'reason_required') {
                    setFieldError(correction.reason, 'cash_correction_reason_error',
                        'Describe el error comprobado y el motivo de la corrección.');
                    correction.reason.focus();
                } else if (result.type === 'correction_not_allowed') {
                    showFeedback('Este tipo de registro no admite corrección desde esta pantalla.', true, 'correction');
                } else {
                    showFeedback(result.message || 'No se pudo guardar la corrección. Inténtalo de nuevo.', true, 'correction');
                }
                return;
            }

            var data = result.data || {};
            var balance = Number(data.balance);
            if (!data.correction || !Number.isFinite(balance)
                || !Array.isArray(correctionData && correctionData.corrections)) {
                showFeedback('El servidor no confirmó todos los datos de la corrección. Reintenta o consulta el historial.', true, 'correction');
                return;
            }
            session.balance = balance;
            if (data.original_movement) {
                var existing = correctionData.movements.find(function (item) {
                    return item.id === data.original_movement.id;
                });
                if (existing) existing.current_amount = data.original_movement.current_amount;
            }
            correctionData.corrections.push(data.correction);
            setStatus(true);
            correctionForm.reset();
            correction.feedback.hidden = true;
            clearErrors(correctionForm);
            clearRequestId();
            renderCorrectionOptions();
            renderPreview();
            var effect = data.correction.effect || 'Efecto informado por el servidor';
            showFeedback('Corrección registrada y vinculada al original. '
                + effect + '. Saldo actualizado: ' + formatMoney(balance) + '.', false, 'correction');
        }).catch(function () {
            showFeedback('No se pudo guardar la corrección. Inténtalo de nuevo.', true, 'correction');
        }).finally(function () {
            setFormLoading(false);
            renderPreview();
        });
    }

    function updateTab(nextTab, focus) {
        currentTab = nextTab;
        var incomeActive = nextTab === 'income';
        var expenseActive = nextTab === 'expense';
        var correctionActive = nextTab === 'correction';
        var incomeTab = document.getElementById('cash_income_tab');
        var expenseTab = document.getElementById('cash_expense_tab');
        var correctionTab = document.getElementById('cash_correction_tab');
        [
            [incomeTab, incomeActive],
            [expenseTab, expenseActive],
            [correctionTab, correctionActive]
        ].forEach(function (entry) {
            entry[0].classList.toggle('is-active', entry[1]);
            entry[0].setAttribute('aria-selected', String(entry[1]));
            entry[0].tabIndex = entry[1] ? 0 : -1;
        });
        incomeForm.hidden = !incomeActive;
        expenseForm.hidden = !expenseActive;
        correctionForm.hidden = !correctionActive;
        incomeForm.setAttribute('aria-hidden', String(!incomeActive));
        expenseForm.setAttribute('aria-hidden', String(!expenseActive));
        correctionForm.setAttribute('aria-hidden', String(!correctionActive));
        income.feedback.hidden = true;
        expense.feedback.hidden = true;
        correction.feedback.hidden = true;
        if (correctionActive) loadCorrectionData();
        renderPreview();
        if (focus) (incomeActive ? incomeTab : (expenseActive ? expenseTab : correctionTab)).focus();
    }

    function handleTabKeydown(event) {
        var keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
        if (!keys.includes(event.key)) return;
        event.preventDefault();
        var tabs = ['income', 'expense', 'correction'];
        var index = tabs.indexOf(currentTab);
        if (event.key === 'Home') index = 0;
        else if (event.key === 'End') index = tabs.length - 1;
        else index = (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
        updateTab(tabs[index], true);
    }

    function cancelForm(formType) {
        clearErrors(formType === 'income' ? incomeForm
            : (formType === 'expense' ? expenseForm : correctionForm));
        income.feedback.hidden = true;
        expense.feedback.hidden = true;
        correction.feedback.hidden = true;
        clearRequestId();
        window.setTimeout(function () {
            if (formType === 'correction') {
                renderCorrectionOptions();
            }
            renderPreview();
        }, 0);
    }

    document.getElementById('cash_income_tab').addEventListener('click', function () {
        updateTab('income', false);
    });
    document.getElementById('cash_expense_tab').addEventListener('click', function () {
        updateTab('expense', false);
    });
    document.getElementById('cash_correction_tab').addEventListener('click', function () {
        updateTab('correction', false);
    });
    document.getElementById('cash_income_tab').addEventListener('keydown', handleTabKeydown);
    document.getElementById('cash_expense_tab').addEventListener('keydown', handleTabKeydown);
    document.getElementById('cash_correction_tab').addEventListener('keydown', handleTabKeydown);
    incomeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        submitMovement('income');
    });
    expenseForm.addEventListener('submit', function (event) {
        event.preventDefault();
        submitMovement('expense');
    });
    incomeForm.addEventListener('reset', function () { cancelForm('income'); });
    expenseForm.addEventListener('reset', function () { cancelForm('expense'); });
    correctionForm.addEventListener('submit', function (event) {
        event.preventDefault();
        submitCorrection();
    });
    correctionForm.addEventListener('reset', function () { cancelForm('correction'); });

    income.amount.addEventListener('input', function () {
        clearFieldError(income.amount, 'cash_income_amount_error');
        renderPreview();
    });
    income.reason.addEventListener('input', function () {
        clearFieldError(income.reason, 'cash_income_reason_error');
    });
    income.admin.addEventListener('change', function () {
        clearFieldError(income.admin, 'cash_income_admin_error');
        renderPreview();
    });
    income.typeGroup.forEach(function (radio) {
        radio.addEventListener('change', function () {
            clearFieldError(income.admin, 'cash_income_admin_error');
            renderPreview();
        });
    });

    expense.amount.addEventListener('input', function () {
        clearFieldError(expense.amount, 'cash_expense_amount_error');
        renderPreview();
    });
    expense.reason.addEventListener('input', function () {
        clearFieldError(expense.reason, 'cash_expense_reason_error');
    });
    expense.admin.addEventListener('change', function () {
        clearFieldError(expense.admin, 'cash_expense_admin_error');
        renderPreview();
    });
    expense.originTurn.addEventListener('change', function () {
        clearFieldError(expense.originTurn, 'cash_origin_turn_error');
        renderPreview();
    });
    expense.confirmOmitted.addEventListener('change', function () {
        clearFieldError(expense.confirmOmitted, 'cash_confirm_omitted_error');
    });
    expense.typeGroup.forEach(function (radio) {
        radio.addEventListener('change', function () {
            clearErrors(expenseForm);
            expense.feedback.hidden = true;
            expense.submit.textContent = isOmittedWithdrawal() ? 'Registrar retiro omitido' : 'Confirmar egreso';
            renderPreview();
        });
    });
    correction.original.addEventListener('change', function () {
        clearFieldError(correction.original, 'cash_correction_original_error');
        renderCorrectionDetail();
    });
    correction.amount.addEventListener('input', function () {
        clearFieldError(correction.amount, 'cash_correction_amount_error');
    });
    correction.reason.addEventListener('input', function () {
        clearFieldError(correction.reason, 'cash_correction_reason_error');
    });
    retryButton.addEventListener('click', loadWorkspace);

    loadWorkspace();
}());
