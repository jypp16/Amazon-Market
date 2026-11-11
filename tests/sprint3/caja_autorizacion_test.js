const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const serviceSource = fs.readFileSync('Assets/js/cajaService.js', 'utf8');
const demoSource = fs.readFileSync('Assets/js/cajaDemoService.js', 'utf8');
const screenSource = fs.readFileSync('Assets/js/caja.js', 'utf8');

function createService(scenario, apiRequest) {
    const window = {
        CAJA_SERVICE_CONFIG: { mockEnabled: true, mockScenario: scenario, referenceAmount: 200 },
        CAJA_WORKFLOWS_CONFIG: { mockEnabled: true, scenario, sellerUsername: 'seller' },
        setTimeout,
        crypto: { randomUUID: () => 'request-unique-test' }
    };
    const context = { window, Api: { request: apiRequest || (() => Promise.reject(new Error('Unexpected API request'))) } };
    vm.createContext(context);
    vm.runInContext(demoSource, context);
    vm.runInContext(serviceSource, context);
    return window.CajaService;
}

class Element {
    constructor(id) {
        this.id = id;
        this.value = '';
        this.textContent = '';
        this.hidden = false;
        this.disabled = false;
        this.validity = { valid: true };
        this.attributes = {};
        this.listeners = {};
        this.parentElement = null;
        this.dataset = {};
        this.offsetParent = {};
        this.className = '';
        this.classNames = new Set();
        this.classList = {
            add: name => this.classNames.add(name),
            remove: name => this.classNames.delete(name),
            contains: name => this.classNames.has(name),
            toggle: (name, force) => {
                if (force === undefined ? !this.classNames.has(name) : force) this.classNames.add(name);
                else this.classNames.delete(name);
            }
        };
    }

    addEventListener(name, callback) { this.listeners[name] = callback; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    getAttribute(name) { return this.attributes[name] || null; }
    focus() { global.document.activeElement = this; }
    querySelector(selector) { return selector === '[role="dialog"]' ? global.elements.cash_auth_dialog : null; }
    querySelectorAll() {
        return [
            global.elements.cash_admin_username,
            global.elements.cash_admin_password,
            global.elements.cash_auth_reason,
            global.elements.cash_auth_cancel,
            global.elements.cash_auth_submit
        ];
    }
    appendChild(child) { this.children = this.children || []; this.children.push(child); }
    async dispatch(name, event) {
        const handler = this.listeners[name];
        if (!handler) throw new Error('No handler for ' + this.id + ':' + name);
        return handler(Object.assign({
            preventDefault() { this.defaultPrevented = true; }
        }, event || {}));
    }
}

function createScreen(service) {
    global.elements = {};
    global.document = {
        activeElement: null,
        listeners: {},
        body: new Element('body'),
        getElementById(id) {
            if (!global.elements[id]) global.elements[id] = new Element(id);
            return global.elements[id];
        },
        querySelector(selector) {
            return selector === '.app-layout' ? global.elements.app_layout : null;
        },
        addEventListener(name, callback) { this.listeners[name] = callback; }
    };
    global.document.body.classList = new Element('body').classList;

    const el = global.document.getElementById.bind(global.document);
    el('cash_opening_form').dataset.reference = '200';
    el('cash_counted').value = '190';
    Object.defineProperty(el('cash_counted'), 'valueAsNumber', {
        get() { return Number(this.value); }
    });
    el('cash_summary_difference').parentElement = el('cash_summary_difference_row');
    el('cash_auth_overlay').hidden = true;
    el('cash_auth_overlay').querySelector = () => el('cash_auth_dialog');
    el('cash_auth_dialog').querySelectorAll = () => [
        el('cash_admin_username'), el('cash_admin_password'), el('cash_auth_reason'),
        el('cash_auth_cancel'), el('cash_auth_submit')
    ];
    el('cash_opening_feedback').hidden = true;
    el('cash_auth_feedback').hidden = true;
    el('app_layout').inert = false;
    global.document.activeElement = el('cash_opening_confirm');

    const context = {
        window: { CajaService: service },
        CajaService: service,
        document: global.document,
        Number,
        Intl,
        Date,
        Array
    };
    vm.createContext(context);
    vm.runInContext(screenSource, context);
    return { document: global.document, elements: global.elements };
}

async function testMockScenarios() {
    const directSuccess = await createService('success').requestOpening({ cash_counted: 190 });
    assert.equal(directSuccess.type, 'opened');
    console.log('[PASS] Mock: apertura directa confirmada.');

    const authService = createService('authorization_required');
    const required = await authService.requestOpening({ cash_counted: 190 });
    assert.equal(required.type, 'authorization_required');
    assert.deepEqual(JSON.parse(JSON.stringify(required.schedule)), { start: '08:00', end: '14:00' });
    const authorized = await authService.authorizeAndOpen({
        request_id: 'same-request',
        cash_counted: 190,
        admin_username: 'admin',
        admin_password: 'secret',
        exception_reason: 'Inventario urgente'
    });
    assert.equal(authorized.type, 'opened');
    console.log('[PASS] Mock: autorización requerida con horario dinámico y apertura autorizada.');

    const invalidService = createService('invalid_credentials');
    await invalidService.requestOpening({ cash_counted: 190 });
    assert.equal((await invalidService.authorizeAndOpen({ admin_username: 'x' })).type, 'authorization_denied');
    console.log('[PASS] Mock: credenciales inválidas / usuario sin rol reciben rechazo genérico.');

    const motiveService = createService('motive_required');
    await motiveService.requestOpening({ cash_counted: 190 });
    assert.equal((await motiveService.authorizeAndOpen({ admin_username: 'admin' })).type, 'motive_required');
    console.log('[PASS] Mock: el servidor puede rechazar un motivo obligatorio.');

    assert.equal((await createService('already_open').requestOpening({ cash_counted: 190 })).type, 'already_open');
    console.log('[PASS] Mock: caja ya abierta no requiere autorización.');

    const errorService = createService('server_error');
    await errorService.requestOpening({ cash_counted: 190 });
    assert.equal((await errorService.authorizeAndOpen({ admin_username: 'admin' })).type, 'error');
    console.log('[PASS] Mock: error interno durante la autorización.');
}

async function testDialogFlow() {
    let requestIdCount = 0;
    let authorizationCalls = [];
    let resolveAuthorization;
    let lastAuthorizationPayload;
    const service = {
        createRequestId: () => 'open-' + (++requestIdCount),
        requestOpening: async payload => {
            assert.equal(payload.request_id, 'open-1');
            return { type: 'authorization_required', schedule: { start: '07:15', end: '11:45' } };
        },
        authorizeAndOpen: payload => {
            lastAuthorizationPayload = payload;
            authorizationCalls.push({
                request_id: payload.request_id,
                admin_username: payload.admin_username,
                exception_reason: payload.exception_reason
            });
            if (authorizationCalls.length === 1) return Promise.resolve({ type: 'authorization_denied' });
            if (authorizationCalls.length === 2) {
                return new Promise((resolve, reject) => {
                    resolveAuthorization = { resolve, reject };
                });
            }
            return Promise.resolve({
                type: 'opened',
                data: { opening_balance: 190, opening_difference: -10, opened_at: '2026-11-10T18:00:00Z' }
            });
        }
    };
    const screen = createScreen(service);
    const { document, elements } = screen;
    await elements.cash_opening_form.dispatch('submit');

    assert.equal(elements.cash_auth_overlay.hidden, false);
    assert.match(elements.cash_auth_warning.textContent, /07:15 – 11:45/);
    assert.equal(elements.cash_opening_status.textContent, 'Fuera de horario');
    assert.equal(elements.cash_schedule_status.textContent, 'Fuera del horario');
    assert.equal(elements.app_layout.inert, true);
    assert.equal(document.activeElement, elements.cash_admin_username);
    console.log('[PASS] La ventana obedece el requisito del servicio, muestra el horario y enfoca el primer campo.');

    await elements.cash_auth_form.dispatch('submit');
    assert.equal(elements.cash_auth_reason_error.textContent, 'Escribe el motivo de la excepción.');
    assert.equal(authorizationCalls.length, 0);
    assert.equal(document.activeElement, elements.cash_admin_username);
    console.log('[PASS] Validación local muestra errores asociados antes de enviar.');

    elements.cash_admin_username.value = 'admin';
    elements.cash_admin_password.value = 'not-logged';
    elements.cash_auth_reason.value = 'Motivo de prueba';
    await elements.cash_auth_form.dispatch('submit');
    assert.equal(elements.cash_auth_feedback.textContent, 'No se pudo autorizar. Verifica los datos.');
    assert.equal(elements.cash_admin_password.value, '');
    assert.equal(elements.cash_auth_reason.value, 'Motivo de prueba');
    assert.equal(authorizationCalls[0].request_id, 'open-1');
    console.log('[PASS] Credenciales se limpian después de rechazo, se conserva motivo y se reutiliza el identificador.');

    elements.cash_admin_password.value = 'temporary';
    const pending = elements.cash_auth_form.dispatch('submit');
    assert.equal(elements.cash_auth_cancel.disabled, true);
    assert.equal(elements.cash_auth_submit.disabled, true);
    assert.equal(elements.cash_auth_dialog.getAttribute('aria-busy'), 'true');
    resolveAuthorization.reject(new Error('Network unavailable'));
    await pending;
    assert.equal(elements.cash_auth_overlay.hidden, false);
    assert.equal(elements.cash_admin_password.value, '');
    assert.equal(elements.cash_auth_reason.value, 'Motivo de prueba');
    assert.equal(elements.cash_auth_cancel.disabled, false);
    assert.equal(lastAuthorizationPayload.admin_password, '');
    console.log('[PASS] Error de servidor mantiene ventana y motivo, limpia contraseña y evita doble envío.');

    elements.cash_admin_password.value = 'temporary';
    await elements.cash_auth_form.dispatch('submit');
    assert.equal(elements.cash_auth_overlay.hidden, true);
    assert.equal(elements.app_layout.inert, false);
    assert.equal(document.activeElement, elements.cash_opening_feedback);
    assert.equal(elements.cash_opening_status.textContent, 'Caja abierta');
    assert.equal(elements.cash_summary_balance.textContent, 'S/ 190,00');
    assert.equal(elements.cash_summary_difference.textContent, '−S/ 10,00');
    assert.equal(elements.cash_admin_password.value, '');
    assert.equal(authorizationCalls[1].request_id, authorizationCalls[2].request_id);
    assert.equal(authorizationCalls[0].request_id, authorizationCalls[2].request_id);
    console.log('[PASS] Apertura autorizada cierra el diálogo, actualiza resumen y mantiene el mismo identificador.');
}

async function testCancelAndAlreadyOpen() {
    const service = {
        createRequestId: () => 'cancel-request',
        requestOpening: async () => ({ type: 'authorization_required', schedule: { start: '08:00', end: '14:00' } }),
        authorizeAndOpen: async () => ({ type: 'opened', data: {} })
    };
    const { document, elements } = createScreen(service);
    await elements.cash_opening_form.dispatch('submit');
    const escape = document.listeners.keydown;
    let prevented = false;
    escape({ key: 'Escape', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(elements.cash_auth_overlay.hidden, true);
    assert.equal(elements.app_layout.inert, false);
    assert.equal(document.activeElement, elements.cash_counted);
    assert.equal(elements.cash_opening_status.textContent, 'Fuera de horario');
    console.log('[PASS] Escape cancela fuera de carga, restituye foco y no abre la caja.');

    const alreadyOpenService = {
        createRequestId: () => 'already-open-request',
        requestOpening: async () => ({ type: 'already_open' }),
        authorizeAndOpen: async () => ({ type: 'already_open' })
    };
    const already = createScreen(alreadyOpenService);
    await already.elements.cash_opening_form.dispatch('submit');
    assert.equal(already.elements.cash_auth_overlay.hidden, true);
    assert.match(already.elements.cash_opening_feedback.textContent, /Ya tienes una caja abierta/);
    assert.equal(already.elements.cash_opening_status.textContent, 'Caja abierta');
    assert.equal(already.elements.cash_opening_confirm.disabled, true);
    console.log('[PASS] Caja ya abierta se informa fuera del diálogo de autorización.');
}

async function testNewAmountGetsNewRequestId() {
    const requestIds = [];
    let id = 0;
    const service = {
        createRequestId: () => 'amount-attempt-' + (++id),
        requestOpening: async payload => {
            requestIds.push({ id: payload.request_id, amount: payload.cash_counted });
            return { type: 'error' };
        },
        authorizeAndOpen: async () => ({ type: 'error' })
    };
    const { elements } = createScreen(service);
    await elements.cash_opening_form.dispatch('submit');
    elements.cash_counted.value = '210';
    await elements.cash_counted.dispatch('input');
    await elements.cash_opening_form.dispatch('submit');
    assert.deepEqual(requestIds, [
        { id: 'amount-attempt-1', amount: 190 },
        { id: 'amount-attempt-2', amount: 210 }
    ]);
    console.log('[PASS] Cambiar el efectivo inicia un intento nuevo con identificador distinto.');
}

async function testApiContractAdapter() {
    let captured;
    const context = { window: { CAJA_SERVICE_CONFIG: { mockEnabled: false }, setTimeout }, Api: {
        request: async (endpoint, options) => {
            captured = { endpoint, options };
            return {
                ok: true,
                status: 201,
                data: { code: 'OPENED', data: { opening_balance: 190, opening_difference: -10 } }
            };
        }
    } };
    vm.createContext(context);
    vm.runInContext(serviceSource, context);
    const realService = context.window.CajaService;
    const result = await realService.requestOpening({
        request_id: 'stable-id',
        cash_counted: 190,
        admin_username: 'admin',
        admin_password: 'never-log',
        exception_reason: 'Test'
    });
    assert.equal(result.type, 'opened');
    assert.equal(captured.endpoint, 'caja/abrir');
    assert.equal(captured.options.headers['X-Request-Reference'], 'stable-id');
    assert.equal(captured.options.body.admin_password, 'never-log');
    console.log('[PASS] Adaptador real usa Api.request/CSRF, mismo ID en cabecera y carga de autorización.');
}

(async function main() {
    await testMockScenarios();
    await testDialogFlow();
    await testCancelAndAlreadyOpen();
    await testNewAmountGetsNewRequestId();
    await testApiContractAdapter();
}()).catch(error => {
    console.error('[FAIL] ' + error.message);
    process.exitCode = 1;
});
