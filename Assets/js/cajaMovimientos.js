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
    if (!service || !workspace) return;

    var session = null;
    var administrators = [];
    var closedTurns = [];
    var currentTab = 'income';
    var submitting = false;
    var requestFingerprint = null;
    var requestId = null;
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
        return currentTab === 'income' ? income.feedback : expense.feedback;
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
        document.getElementById('cash_income_cancel').disabled = loadingState;
        document.getElementById('cash_expense_cancel').disabled = loadingState;
        income.submit.textContent = loadingState ? 'Registrando…' : 'Confirmar ingreso';
        expense.submit.textContent = loadingState ? 'Registrando…' : (isOmittedWithdrawal() ? 'Registrar retiro omitido' : 'Confirmar egreso');
    }

    function showFeedback(message, isError) {
        var feedback = activeFeedback();
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

    function updateTab(nextTab, focus) {
        currentTab = nextTab;
        var incomeActive = nextTab === 'income';
        var incomeTab = document.getElementById('cash_income_tab');
        var expenseTab = document.getElementById('cash_expense_tab');
        incomeTab.classList.toggle('is-active', incomeActive);
        expenseTab.classList.toggle('is-active', !incomeActive);
        incomeTab.setAttribute('aria-selected', String(incomeActive));
        expenseTab.setAttribute('aria-selected', String(!incomeActive));
        incomeTab.tabIndex = incomeActive ? 0 : -1;
        expenseTab.tabIndex = incomeActive ? -1 : 0;
        incomeForm.hidden = !incomeActive;
        expenseForm.hidden = incomeActive;
        incomeForm.setAttribute('aria-hidden', String(!incomeActive));
        expenseForm.setAttribute('aria-hidden', String(incomeActive));
        income.feedback.hidden = true;
        expense.feedback.hidden = true;
        renderPreview();
        if (focus) (incomeActive ? incomeTab : expenseTab).focus();
    }

    function handleTabKeydown(event) {
        var keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
        if (!keys.includes(event.key)) return;
        event.preventDefault();
        if (event.key === 'Home') updateTab('income', true);
        else if (event.key === 'End') updateTab('expense', true);
        else updateTab(currentTab === 'income' ? 'expense' : 'income', true);
    }

    function cancelForm(formType) {
        clearErrors(formType === 'income' ? incomeForm : expenseForm);
        income.feedback.hidden = true;
        expense.feedback.hidden = true;
        clearRequestId();
        window.setTimeout(renderPreview, 0);
    }

    document.getElementById('cash_income_tab').addEventListener('click', function () {
        updateTab('income', false);
    });
    document.getElementById('cash_expense_tab').addEventListener('click', function () {
        updateTab('expense', false);
    });
    document.getElementById('cash_income_tab').addEventListener('keydown', handleTabKeydown);
    document.getElementById('cash_expense_tab').addEventListener('keydown', handleTabKeydown);
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
    retryButton.addEventListener('click', loadWorkspace);

    loadWorkspace();
}());
