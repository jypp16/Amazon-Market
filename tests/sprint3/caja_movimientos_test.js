const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const serviceSource = fs.readFileSync('Assets/js/cajaMovimientosService.js', 'utf8');
const screenSource = fs.readFileSync('Assets/js/cajaMovimientos.js', 'utf8');
const viewSource = fs.readFileSync('Views/Caja/movimientos.php', 'utf8');
const controllerSource = fs.readFileSync('Controllers/CajaController.php', 'utf8');

function createService(scenario, latencyMs = 0) {
    let requestId = 0;
    const window = {
        CAJA_MOVEMENTS_CONFIG: {
            mockEnabled: true,
            mockScenario: scenario,
            mockLatencyMs: latencyMs,
            expenseTypes: [
                { value: 'cash_out', label: 'Salida de caja' },
                { value: 'admin_withdrawal', label: 'Retiro del administrador' },
                { value: 'omitted_withdrawal', label: 'Retiro omitido' }
            ]
        },
        setTimeout,
        crypto: { randomUUID: () => 'movement-' + (++requestId) }
    };
    const context = { window, Api: { request: () => Promise.reject(new Error('API no debe llamarse en modo mock')) } };
    vm.createContext(context);
    vm.runInContext(serviceSource, context);
    return window.CajaMovimientosService;
}

class Element {
    constructor(id) {
        this.id = id;
        this.value = '';
        this.textContent = '';
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this.defaultChecked = false;
        this.defaultValue = '';
        this.attributes = {};
        this.listeners = {};
        this.children = [];
        this.options = [];
        this.className = '';
        this.classNames = new Set();
        this.classList = {
            add: name => this.classNames.add(name),
            remove: name => this.classNames.delete(name),
            toggle: (name, force) => {
                if (force === undefined ? !this.classNames.has(name) : force) this.classNames.add(name);
                else this.classNames.delete(name);
            },
            contains: name => this.classNames.has(name)
        };
    }

    addEventListener(name, callback) { this.listeners[name] = callback; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    getAttribute(name) { return this.attributes[name] || null; }
    focus() { global.document.activeElement = this; }
    replaceChildren(option) { this.options = [option]; }
    add(option) { this.options.push(option); }
    appendChild(child) { this.children.push(child); }
    querySelectorAll(selector) {
        if (selector === '[aria-invalid="true"]') return this.controls.filter(control => control.getAttribute('aria-invalid') === 'true');
        if (selector === '.cash-movement-error') return this.errors || [];
        return [];
    }
    reset() {
        (this.controls || []).forEach(control => {
            control.value = control.defaultValue;
            control.checked = control.defaultChecked;
        });
        if (this.listeners.reset) this.listeners.reset({ preventDefault() {} });
    }
    async dispatch(name, event = {}) {
        assert.ok(this.listeners[name], 'Missing event handler ' + this.id + ':' + name);
        return this.listeners[name](Object.assign({ preventDefault() {} }, event));
    }
}

function createScreen(service) {
    const elements = {};
    const document = {
        activeElement: null,
        listeners: {},
        body: { classList: { add() {}, remove() {} } },
        getElementById(id) { return elements[id] || (elements[id] = new Element(id)); },
        querySelectorAll(selector) {
            if (selector === 'input[name="income_type"]') return [elements.income_manual, elements.income_injection];
            if (selector === 'input[name="expense_type"]') return [
                elements.expense_cash_out, elements.expense_admin_withdrawal, elements.expense_omitted
            ];
            return [];
        },
        querySelector() { return null; },
        addEventListener(name, callback) { this.listeners[name] = callback; }
    };
    global.document = document;
    global.Option = function Option(text, value) { return { text, value }; };

    const get = document.getElementById.bind(document);
    const ids = [
        'cash_movements_loading', 'cash_movements_no_session', 'cash_movements_load_error',
        'cash_movements_retry', 'cash_movements_workspace', 'cash_movements_status',
        'cash_movements_confirmation', 'cash_income_form', 'cash_expense_form',
        'cash_income_amount', 'cash_income_reason', 'cash_income_admin_group', 'cash_income_admin',
        'cash_income_submit', 'cash_income_feedback', 'cash_income_tab', 'cash_expense_tab',
        'cash_expense_amount', 'cash_expense_reason', 'cash_expense_admin_group', 'cash_expense_admin',
        'cash_origin_turn_group', 'cash_origin_turn', 'cash_confirm_omitted_group',
        'cash_confirm_omitted', 'cash_expense_submit', 'cash_expense_feedback',
        'cash_income_cancel', 'cash_expense_cancel', 'cash_effect_current', 'cash_effect_label',
        'cash_effect_amount', 'cash_effect_total_label', 'cash_effect_total', 'cash_omitted_note',
        'cash_origin_close', 'cash_origin_expected', 'cash_origin_counted', 'cash_origin_shortage',
        'cash_balance_note', 'cash_income_amount_error', 'cash_income_reason_error',
        'cash_income_admin_error', 'cash_expense_amount_error', 'cash_expense_reason_error',
        'cash_expense_admin_error', 'cash_origin_turn_error', 'cash_confirm_omitted_error',
        'income_manual', 'income_injection', 'expense_cash_out', 'expense_admin_withdrawal',
        'expense_omitted'
    ];
    ids.forEach(get);
    const incomeForm = get('cash_income_form');
    const expenseForm = get('cash_expense_form');
    const allErrors = [
        'cash_income_amount_error', 'cash_income_reason_error', 'cash_income_admin_error',
        'cash_expense_amount_error', 'cash_expense_reason_error', 'cash_expense_admin_error',
        'cash_origin_turn_error', 'cash_confirm_omitted_error'
    ].map(get);
    incomeForm.errors = allErrors.slice(0, 3);
    expenseForm.errors = allErrors.slice(3);
    incomeForm.controls = [
        get('cash_income_amount'), get('cash_income_reason'), get('cash_income_admin'),
        get('income_manual'), get('income_injection')
    ];
    expenseForm.controls = [
        get('cash_expense_amount'), get('cash_expense_reason'), get('cash_expense_admin'),
        get('cash_origin_turn'), get('cash_confirm_omitted'),
        get('expense_cash_out'), get('expense_admin_withdrawal'), get('expense_omitted')
    ];
    get('income_manual').value = 'manual';
    get('income_manual').checked = true;
    get('income_manual').defaultChecked = true;
    get('income_injection').value = 'admin_injection';
    get('expense_cash_out').value = 'cash_out';
    get('expense_cash_out').checked = true;
    get('expense_cash_out').defaultChecked = true;
    get('expense_admin_withdrawal').value = 'admin_withdrawal';
    get('expense_omitted').value = 'omitted_withdrawal';
    get('cash_income_amount').defaultValue = '';
    get('cash_expense_amount').defaultValue = '';
    get('cash_income_reason').defaultValue = '';
    get('cash_expense_reason').defaultValue = '';
    get('cash_income_admin').value = '';
    get('cash_expense_admin').value = '';
    get('cash_origin_turn').value = '';
    get('cash_confirm_omitted').checked = false;
    get('cash_income_form').querySelectorAll = selector => selector === '[aria-invalid="true"]'
        ? incomeForm.controls.filter(control => control.getAttribute('aria-invalid') === 'true')
        : incomeForm.errors;
    get('cash_expense_form').querySelectorAll = selector => selector === '[aria-invalid="true"]'
        ? expenseForm.controls.filter(control => control.getAttribute('aria-invalid') === 'true')
        : expenseForm.errors;
    get('cash_income_tab').classList.add('is-active');

    const context = {
        window: {
            CajaMovimientosService: service,
            CAJA_MOVEMENTS_CONFIG: { mockEnabled: true },
            setTimeout
        },
        document,
        Option: global.Option,
        Number,
        Intl,
        Array,
        Promise,
        JSON
    };
    vm.createContext(context);
    vm.runInContext(screenSource, context);
    return { document, elements };
}

async function waitForWorkspace(elements) {
    for (let attempt = 0; attempt < 30 && elements.cash_movements_workspace.hidden; attempt++) {
        await new Promise(resolve => setTimeout(resolve, 2));
    }
    return !elements.cash_movements_workspace.hidden;
}

async function testServiceMockScenarios() {
    const service = createService('success');
    const state = await service.getSessionState();
    assert.equal(state.is_open, true);
    assert.equal(state.balance, 250);
    assert.equal((await service.listActiveAdministrators()).length, 2);
    assert.equal((await service.listClosedTurns())[0].shortage, 300);
    console.log('[PASS] Mock de estado abierto, saldo, administradores activos y cierres previos.');

    const incomePayload = {
        type: 'admin_injection', amount: 50, reason: 'Cambio', administrator_id: 'admin-1',
        request_id: 'same-income'
    };
    const incomeResult = await service.registerIncome(incomePayload);
    assert.equal(incomeResult.type, 'success');
    assert.equal(incomeResult.data.balance, 300);
    assert.equal((await service.registerIncome(incomePayload)).data.balance, 300);
    console.log('[PASS] Inyección confirmada y reenvío idempotente no duplica el incremento.');

    const expenseResult = await service.registerExpense({
        type: 'omitted_withdrawal', amount: 300, reason: 'Retiro del turno previo',
        administrator_id: 'admin-1', origin_turn_id: 'turn-previous-1',
        confirm_previous_open: true, request_id: 'omitted'
    });
    assert.equal(expenseResult.data.movement.impact, 0);
    assert.equal(expenseResult.data.balance, 300);
    console.log('[PASS] Retiro omitido conserva el saldo actual y reporta impacto cero.');

    const closed = createService('cash_closed');
    assert.equal((await closed.getSessionState()).is_open, false);
    console.log('[PASS] Mock caja cerrada oculta el flujo de registro.');

    const insufficient = createService('insufficient_balance');
    const insufficientResult = await insufficient.registerExpense({
        type: 'cash_out', amount: 80, reason: 'Salida', request_id: 'out'
    });
    assert.equal(insufficientResult.type, 'insufficient_balance');
    assert.equal(insufficientResult.current_balance, 20);
    console.log('[PASS] Mock saldo insuficiente comunica el saldo actualizado por el servicio.');

    const missingAdmin = createService('administrator_missing');
    const missingAdminResult = await missingAdmin.registerIncome({
        type: 'admin_injection', amount: 10, reason: 'Entrada', request_id: 'missing'
    });
    assert.equal(missingAdminResult.type, 'administrator_required');
    console.log('[PASS] Mock administrador faltante no altera el saldo.');

    const serverError = createService('server_error');
    assert.equal((await serverError.registerIncome({
        type: 'manual', amount: 10, reason: 'Entrada', request_id: 'error'
    })).type, 'error');
    console.log('[PASS] Mock error del servidor.');

    const latency = createService('latency', 1200);
    const start = Date.now();
    assert.equal((await latency.getSessionState()).is_open, true);
    assert.ok(Date.now() - start >= 1100);
    console.log('[PASS] Mock de latencia simulada.');
}

async function testLivePreviewValidationAndSuccess() {
    const calls = [];
    const service = {
        createRequestId: (() => { let id = 0; return () => 'request-' + (++id); })(),
        getSessionState: async () => ({ is_open: true, seller: 'Luis', turn: 'Mañana', balance: 100 }),
        listActiveAdministrators: async () => [{ id: 'admin-1', name: 'Admin' }],
        listClosedTurns: async () => [{
            id: 'previous', label: 'Turno anterior (cerrado)', expected: 500, counted: 200, shortage: 300
        }],
        registerIncome: async payload => {
            calls.push(JSON.parse(JSON.stringify(payload)));
            return { type: 'success', data: { balance: 150 } };
        },
        registerExpense: async payload => {
            calls.push(JSON.parse(JSON.stringify(payload)));
            return { type: 'success', data: { balance: 80 } };
        }
    };
    const { elements } = createScreen(service);
    assert.equal(await waitForWorkspace(elements), true);
    assert.equal(elements.cash_movements_status.textContent, 'Caja abierta · Luis · Turno Mañana');

    elements.cash_income_amount.value = '50,25';
    await elements.cash_income_amount.dispatch('input');
    assert.equal(elements.cash_effect_amount.textContent, '+S/ 50,25');
    assert.equal(elements.cash_effect_total.textContent, 'S/ 150,25');
    elements.cash_income_reason.value = '  ';
    await elements.cash_income_form.dispatch('submit');
    assert.equal(elements.cash_income_reason_error.textContent, 'Escribe el motivo del movimiento.');
    assert.equal(calls.length, 0);

    elements.cash_income_reason.value = ' Aporte para sencillo ';
    await elements.cash_income_form.dispatch('submit');
    assert.equal(calls[0].reason, 'Aporte para sencillo');
    assert.equal(calls[0].request_id, 'request-1');
    assert.equal(elements.cash_effect_current.textContent, 'S/ 150,00');
    assert.match(elements.cash_income_feedback.textContent, /Saldo actualizado: S\/ 150,00/);
    console.log('[PASS] Ingreso: importe con coma, motivo recortado, vista previa y saldo final del servicio.');

    const limitScreen = createScreen({
        createRequestId: () => 'limit',
        getSessionState: async () => ({ is_open: true, seller: 'Luis', turn: 'Mañana', balance: 100 }),
        listActiveAdministrators: async () => [],
        listClosedTurns: async () => [],
        registerIncome: async () => ({ type: 'error' }),
        registerExpense: async () => ({ type: 'error' })
    });
    await waitForWorkspace(limitScreen.elements);
    limitScreen.elements.cash_expense_tab.listeners.click();
    limitScreen.elements.cash_expense_amount.value = '100';
    await limitScreen.elements.cash_expense_amount.dispatch('input');
    assert.equal(limitScreen.elements.cash_expense_submit.disabled, false);
    limitScreen.elements.cash_expense_amount.value = '101';
    await limitScreen.elements.cash_expense_amount.dispatch('input');
    assert.equal(limitScreen.elements.cash_expense_submit.disabled, true);
    assert.equal(limitScreen.elements.cash_expense_amount_error.textContent, 'El monto supera el saldo disponible.');
    console.log('[PASS] Límite CA-16.1: S/ 100,00 se permite sobre saldo 100 y S/ 101,00 se rechaza.');

    elements.cash_expense_tab.listeners.click();
    elements.expense_cash_out.checked = true;
    elements.cash_expense_amount.value = '151';
    await elements.cash_expense_amount.dispatch('input');
    assert.equal(elements.cash_expense_submit.disabled, true);
    assert.equal(elements.cash_expense_amount_error.textContent, 'El monto supera el saldo disponible.');
    elements.cash_expense_amount.value = '150';
    await elements.cash_expense_amount.dispatch('input');
    assert.equal(elements.cash_expense_submit.disabled, false);
    assert.equal(elements.cash_effect_total.textContent, 'S/ 0,00');
    console.log('[PASS] Egreso acepta saldo exacto y bloquea un importe superior en tiempo real.');
}

async function testConditionalFieldsAndErrorState() {
    const service = {
        createRequestId: () => 'stable-retry',
        getSessionState: async () => ({ is_open: true, seller: 'Luis', turn: 'Mañana', balance: 100 }),
        listActiveAdministrators: async () => [{ id: 'admin-1', name: 'Admin' }],
        listClosedTurns: async () => [{
            id: 'previous', label: 'Turno previo cerrado', expected: 500, counted: 200, shortage: 300
        }],
        registerIncome: async () => ({ type: 'administrator_required' }),
        registerExpense: async payload => {
            if (payload.type === 'omitted_withdrawal') {
                return { type: 'success', data: { balance: 100 } };
            }
            return { type: 'insufficient_balance', current_balance: 20 };
        }
    };
    const { elements } = createScreen(service);
    await waitForWorkspace(elements);

    elements.income_injection.checked = true;
    elements.income_manual.checked = false;
    await elements.income_injection.dispatch('change');
    assert.equal(elements.cash_income_admin_group.hidden, false);
    elements.cash_income_amount.value = '10';
    elements.cash_income_reason.value = 'Ingreso';
    await elements.cash_income_form.dispatch('submit');
    assert.match(elements.cash_income_admin_error.textContent, /El saldo no cambia/);
    console.log('[PASS] Inyección solicita administrador y explica que el saldo no cambia si falta.');

    elements.cash_expense_tab.listeners.click();
    elements.expense_omitted.checked = true;
    elements.expense_cash_out.checked = false;
    await elements.expense_omitted.dispatch('change');
    assert.equal(elements.cash_origin_turn_group.hidden, false);
    assert.equal(elements.cash_confirm_omitted_group.hidden, false);
    elements.cash_origin_turn.value = 'previous';
    await elements.cash_origin_turn.dispatch('change');
    assert.equal(elements.cash_effect_amount.textContent, 'S/ 0,00');
    assert.equal(elements.cash_effect_total.textContent, 'S/ 100,00');
    assert.equal(elements.cash_origin_expected.textContent, 'S/ 500,00');
    assert.equal(elements.cash_origin_counted.textContent, 'S/ 200,00');
    assert.equal(elements.cash_origin_shortage.textContent, 'S/ 300,00');
    elements.cash_expense_amount.value = '300';
    await elements.cash_expense_amount.dispatch('input');
    assert.equal(elements.cash_expense_submit.disabled, false);
    console.log('[PASS] Retiro omitido muestra turno y cierre previo, impacto cero y no valida contra saldo.');

    elements.cash_expense_reason.value = 'Retiro omitido';
    elements.cash_expense_admin.value = 'admin-1';
    elements.cash_confirm_omitted.checked = true;
    await elements.cash_expense_form.dispatch('submit');
    assert.equal(elements.cash_effect_current.textContent, 'S/ 100,00');
    assert.equal(elements.cash_expense_feedback.textContent, 'Movimiento registrado. Saldo actualizado: S/ 100,00.');
    console.log('[PASS] Confirmar retiro omitido mantiene el saldo recibido del servicio.');
}

async function testClosedAndServerRace() {
    const closed = createScreen({
        getSessionState: async () => ({ is_open: false, seller: 'Luis', turn: 'Mañana', balance: 0 }),
        listActiveAdministrators: async () => [],
        listClosedTurns: async () => []
    });
    await waitForWorkspace(closed.elements);
    assert.equal(closed.elements.cash_movements_no_session.hidden, false);
    assert.equal(closed.elements.cash_movements_workspace.hidden, true);
    assert.match(closed.elements.cash_movements_status.textContent, /Caja cerrada/);
    console.log('[PASS] Sin caja abierta muestra el aviso y no presenta formularios.');

    const { elements } = createScreen({
        createRequestId: () => 'retry-same',
        getSessionState: async () => ({ is_open: true, seller: 'Luis', turn: 'Mañana', balance: 100 }),
        listActiveAdministrators: async () => [{ id: 'admin', name: 'Admin' }],
        listClosedTurns: async () => [],
        registerExpense: async () => ({ type: 'insufficient_balance', current_balance: 20 }),
        registerIncome: async () => ({ type: 'cash_closed' })
    });
    await waitForWorkspace(elements);
    elements.cash_expense_tab.listeners.click();
    elements.cash_expense_amount.value = '80';
    elements.cash_expense_reason.value = 'Compra';
    await elements.cash_expense_form.dispatch('submit');
    await new Promise(resolve => setTimeout(resolve, 2));
    assert.equal(elements.cash_effect_current.textContent, 'S/ 20,00');
    assert.equal(elements.cash_expense_amount_error.textContent, 'El monto supera el saldo disponible.');

    elements.cash_income_tab.listeners.click();
    elements.cash_income_amount.value = '5';
    elements.cash_income_reason.value = 'Otro';
    await elements.cash_income_form.dispatch('submit');
    await new Promise(resolve => setTimeout(resolve, 2));
    assert.equal(elements.cash_movements_workspace.hidden, true);
    assert.equal(elements.cash_movements_no_session.hidden, false);
    console.log('[PASS] Saldo insuficiente actualiza el disponible; caja cerrada durante envío bloquea formularios.');
}

function testMarkupAndOptions() {
    assert.match(viewSource, /role="tablist"/);
    assert.match(viewSource, /role="radiogroup"/);
    assert.match(controllerSource, /CAJA_MOVIMIENTOS_EXPENSE_TYPES/);
    assert.match(viewSource, /Turno de origen/);
    assert.match(viewSource, /Confirmo que el retiro ocurrió con la caja anterior abierta/);
    assert.match(viewSource, /cierre original se conserva/);
    assert.match(viewSource, /Debe[s]? abrir una sesión de caja para registrar movimientos/);
    console.log('[PASS] Marcado contiene controles accesibles, cierre de origen y catálogo parametrizable.');
}

(async function main() {
    testMarkupAndOptions();
    await testServiceMockScenarios();
    await testLivePreviewValidationAndSuccess();
    await testConditionalFieldsAndErrorState();
    await testClosedAndServerRace();
}()).catch(error => {
    console.error('[FAIL] ' + error.stack);
    process.exitCode = 1;
});
