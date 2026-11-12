const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const serviceSource = fs.readFileSync('Assets/js/cajaConsultaService.js', 'utf8');
const screenSource = fs.readFileSync('Assets/js/cajaConsulta.js', 'utf8');
const viewSource = fs.readFileSync('Views/Caja/consultar.php', 'utf8');
const controllerSource = fs.readFileSync('Controllers/CajaController.php', 'utf8');
const headerSource = fs.readFileSync('Views/Header.php', 'utf8');
const footerSource = fs.readFileSync('Views/Footer.php', 'utf8');
const rbacSource = fs.readFileSync('Libraries/Middleware/RBACMiddleware.php', 'utf8');

function createService(scenario, enabled = true, pageSize = 4, apiRequest) {
    const window = {
        CAJA_CONSULTA_CONFIG: {
            mockEnabled: enabled,
            mockScenario: scenario,
            pageSize,
            defaultDate: '2026-11-10',
            mockLatencyMs: 0
        },
        setTimeout
    };
    const context = {
        window,
        Api: { request: apiRequest || (() => Promise.reject(new Error('API no debe llamarse en modo mock'))) },
        Promise,
        Number,
        Math,
        JSON
    };
    vm.createContext(context);
    vm.runInContext(serviceSource, context);
    return window.CajaConsultaService;
}

class Element {
    constructor(id) {
        this.id = id;
        this.value = '';
        this.textContent = '';
        this.hidden = true;
        this.disabled = false;
        this.children = [];
        this.listeners = {};
        this.attributes = {};
        this.className = '';
    }

    addEventListener(name, callback) { this.listeners[name] = callback; }
    appendChild(child) { this.children.push(child); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    async dispatch(name, event = {}) {
        assert.ok(this.listeners[name], 'Missing event listener: ' + this.id + ':' + name);
        return this.listeners[name](Object.assign({ preventDefault() {} }, event));
    }
}

function createScreen(isAdministrator, screenService) {
    const elements = {};
    const missing = new Set();
    const document = {
        getElementById(id) {
            if (missing.has(id)) return null;
            if (!elements[id]) elements[id] = new Element(id);
            return elements[id];
        },
        createElement(tag) { return new Element(tag); }
    };
    const ids = [
        'cash_query_filters', 'cash_query_date', 'cash_query_seller', 'cash_query_submit',
        'cash_query_sessions', 'cash_query_session_options', 'cash_query_turn_filter',
        'cash_query_turn',
        'cash_query_results', 'cash_query_loading', 'cash_query_error', 'cash_query_denied',
        'cash_query_no_session', 'cash_consulta_status', 'cash_query_empty_title',
        'cash_query_empty_text', 'cash_query_open_link', 'cash_query_opening',
        'cash_query_sales', 'cash_query_income', 'cash_query_expenses', 'cash_query_balance',
        'cash_query_other_tenders', 'cash_query_rows', 'cash_query_empty_movements',
        'cash_query_page_status', 'cash_query_page_number', 'cash_query_previous',
        'cash_query_next', 'cash_query_retry', 'cash_query_seller_error', 'cash_query_clear'
    ];
    ids.forEach(id => document.getElementById(id));
    elements.cash_query_results.hidden = true;
    elements.cash_query_loading.hidden = true;
    elements.cash_query_error.hidden = true;
    elements.cash_query_denied.hidden = true;
    elements.cash_query_no_session.hidden = true;
    if (isAdministrator) {
        elements.cash_query_date.value = '2026-11-10';
        elements.cash_query_filters.hidden = false;
    } else {
        [
            'cash_query_filters', 'cash_query_date', 'cash_query_seller',
            'cash_query_submit', 'cash_query_seller_error', 'cash_query_clear'
        ].forEach(id => missing.add(id));
    }
    const window = {
        CAJA_CONSULTA_CONFIG: {
            isAdministrator,
            pageSize: 2,
            defaultDate: '2026-11-10'
        },
        setTimeout
    };
    const context = {
        window, document, Promise, Number, Math, Array, Intl,
        service: screenService
    };
    window.CajaConsultaService = screenService;
    vm.createContext(context);
    vm.runInContext(screenSource, context);
    return { elements, document };
}

function sampleResult(page) {
    return {
        type: 'result',
        session: { seller: 'Luis', turn: 'Mañana' },
        summary: {
            opening_balance: 200, cash_sales: 80, income: 50, expenses: 30,
            current_balance: 300, card: 90, yape_plin: 0
        },
        movements: page === 1
            ? [{ id: 'm1', date: '2026-11-10', time: '09:00', type: 'cash_sale', amount: 80, effect: 'Suma', reference: 'Boleta' }]
            : [{ id: 'm2', date: '2026-11-09', time: '10:00', type: 'omitted_withdrawal', amount: 100, effect: 'Sin efecto: ya reflejado en la apertura' }],
        pagination: { page, total_pages: 2, total_movements: 3 }
    };
}

async function testAdministratorSessionAndEmptyDate() {
    const service = createService('admin_data');
    const sellers = await service.listSellers();
    assert.ok(sellers.length >= 1);
    const result = await service.query({
        date: '2026-11-10', seller_id: sellers[0].id, session_id: 'session-luis-2026-11-10', page: 1
    });
    assert.equal(result.type, 'result');
    assert.equal(result.session.seller, 'Luis');
    assert.equal(result.summary.current_balance, 300);
    assert.equal(result.movements.length, 4);
    assert.equal(result.movements[3].effect, 'Sin efecto: ya reflejado en la apertura');
    assert.equal(result.movements[0].date, '2026-11-10');

    const empty = await createService('admin_empty').listSessions({
        date: '2026-01-01', seller_id: 'seller-luis'
    });
    assert.equal(empty.length, 0);
    console.log('[PASS] Administrador recibe solo sesiones de la fecha y vendedor consultados.');
}

async function testMultipleSessionsNeedExplicitSelection() {
    const service = createService('multiple_sessions');
    const sessions = await service.listSessions({
        date: '2026-11-10', seller_id: 'seller-luis'
    });
    assert.equal(sessions.length, 2);
    assert.equal(sessions[0].status, 'Cerrada');
    assert.equal(sessions[1].status, 'Abierta');
    assert.notEqual(sessions[0].id, sessions[1].id);
    const first = await service.query({
        date: '2026-11-10', seller_id: 'seller-luis',
        session_id: sessions[0].id, page: 1
    });
    const second = await service.query({
        date: '2026-11-10', seller_id: 'seller-luis',
        session_id: sessions[1].id, page: 1
    });
    assert.equal(first.session.session_id, sessions[0].id);
    assert.equal(second.session.session_id, sessions[1].id);
    console.log('[PASS] Mock de varias cajas exige seleccionar una sesión específica.');
}

async function testSellerAndNoMovements() {
    const active = await createService('seller_active').getActiveSession();
    assert.equal(active.type, 'result');
    assert.equal(active.session.status, 'Abierta');

    const closed = await createService('seller_no_cash').getActiveSession();
    assert.equal(closed.type, 'no_session');

    const empty = await createService('no_movements').getActiveSession();
    assert.equal(empty.movements.length, 0);
    assert.equal(empty.summary.opening_balance, 200);
    assert.equal(empty.summary.current_balance, 200);
    assert.equal(empty.summary.cash_sales, 0);
    assert.equal(empty.summary.income, 0);
    assert.equal(empty.summary.expenses, 0);
    const pagedSeller = createService('multiple_pages', true, 4);
    const sellerFirst = await pagedSeller.getActiveSession({ page: 1 });
    const sellerSecond = await pagedSeller.getActiveSession({ page: 2 });
    assert.equal(sellerFirst.pagination.total_movements, 12);
    assert.equal(sellerSecond.pagination.page, 2);
    assert.notEqual(sellerFirst.movements[0].id, sellerSecond.movements[0].id);
    console.log('[PASS] Vendedor activo/sin caja y sesión abierta sin movimientos ni cambios del saldo inicial.');
}

async function testAccessDeniedErrorAndPagination() {
    assert.equal((await createService('access_denied').query({
        date: '2026-11-10', seller_id: 'seller-luis', page: 1
    })).type, 'access_denied');
    assert.equal((await createService('server_error').listSellers().catch(() => ({ type: 'error' }))).type, 'error');

    const pagedService = createService('multiple_pages', true, 4);
    const first = await pagedService.query({ date: '2026-11-10', seller_id: 'seller-luis', page: 1 });
    const second = await pagedService.query({ date: '2026-11-10', seller_id: 'seller-luis', page: 2 });
    const third = await pagedService.query({ date: '2026-11-10', seller_id: 'seller-luis', page: 3 });
    assert.equal(first.movements.length, 4);
    assert.equal(first.pagination.total_movements, 12);
    assert.equal(first.pagination.total_pages, 3);
    assert.equal(second.pagination.total_movements, first.pagination.total_movements);
    assert.equal(second.summary.current_balance, first.summary.current_balance);
    assert.equal(new Set(first.movements.concat(second.movements, third.movements).map(item => item.id)).size, 12);
    assert.deepEqual(first.movements.map(item => item.time), ['08:00', '08:30', '09:00', '09:30']);
    console.log('[PASS] Denegación, error de servicio y paginación sin duplicar movimientos ni recalcular totales.');
}

async function testApiAdapterAndOptions() {
    const calls = [];
    const service = createService('admin_data', false, 8, (endpoint, options) => {
        calls.push({ endpoint, options });
        if (endpoint.startsWith('caja/consulta/vendedores')) {
            return Promise.resolve({ ok: true, status: 200, data: { data: [{ id: 's1', name: 'Vendedor Uno' }] } });
        }
        if (endpoint.startsWith('caja/consulta/sesiones')) {
            return Promise.resolve({ ok: true, status: 200, data: { data: [{ id: 'session-1', status: 'Cerrada' }] } });
        }
        return Promise.resolve({ ok: true, status: 200, data: { data: {
            session: { seller: 'Vendedor Uno', turn: 'Tarde' },
            summary: { opening_balance: 1, cash_sales: 2, income: 3, expenses: 4, current_balance: 2, card: 5, yape_plin: 6 },
            movements: [],
            pagination: { page: 2, total_pages: 4, total_movements: 32 }
        } } });
    });
    assert.equal((await service.listSellers())[0].id, 's1');
    const sessions = await service.listSessions({ date: '2026-11-10', seller_id: 's1' });
    assert.equal(sessions[0].id, 'session-1');
    const result = await service.query({
        date: '2026-11-10', seller_id: 's1', session_id: 'session-1', page: 2
    });
    assert.equal(result.pagination.total_movements, 32);
    assert.match(calls[1].endpoint, /date=2026-11-10/);
    assert.match(calls[1].endpoint, /seller_id=s1/);
    assert.match(calls[2].endpoint, /session_id=session-1/);
    assert.match(calls[2].endpoint, /page=2/);

    assert.match(viewSource, /Saldo y movimientos de la sesión\. Solo lectura\./);
    assert.match(viewSource, /Fecha y hora/);
    assert.match(viewSource, /id="cash_query_turn_filter"/);
    const filterFormStart = viewSource.indexOf('<form class="cash-query-filters"');
    const filterFormEnd = viewSource.indexOf('</form>', filterFormStart);
    const turnFilterPosition = viewSource.indexOf('id="cash_query_turn_filter"');
    assert.ok(turnFilterPosition > filterFormStart && turnFilterPosition < filterFormEnd,
        'The turn selector belongs beside date and seller in the search filters.');
    assert.match(viewSource, /scope="col"/);
    assert.match(viewSource, /aria-live="polite"/);
    assert.match(viewSource, /Limpiar filtros/);
    assert.match(viewSource, /Acceso denegado/);
    assert.match(serviceSource, /Sin efecto: ya reflejado/);
    assert.match(screenSource, /is-unknown/);
    assert.match(controllerSource, /CAJA_CONSULTA_MOCK_ENABLED/);
    assert.match(headerSource, /Consultar caja/);
    assert.match(footerSource, /cajaConsultaService\.js/);
    assert.match(rbacSource, /'consultar'\s*=>\s*'turnos\.acceder'/);
    console.log('[PASS] Adaptador API, filtros parametrizados, estados de vista, acceso, rol y scripts.');
}

async function testAdministratorScreenFlow() {
    const calls = [];
    const sessionCalls = [];
    const screenService = {
        typeLabels: { cash_sale: 'Venta en efectivo', omitted_withdrawal: 'Retiro omitido' },
        listSellers: async () => [{ id: 's1', name: 'Luis' }, { id: 's2', name: 'María' }],
        listSessions: async filters => {
            sessionCalls.push(Object.assign({}, filters));
            return [{
                id: 'session-selected',
                seller: 'Luis',
                turn: 'Mañana',
                status: 'Cerrada',
                opened_at: '2026-11-09T08:00:00-05:00',
                closed_at: '2026-11-09T15:00:00-05:00'
            }];
        },
        query: async filters => {
            calls.push(Object.assign({}, filters));
            return sampleResult(filters.page);
        }
    };
    const { elements } = createScreen(true, screenService);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls.length, 0, 'Admin query should wait for explicit submit');
    elements.cash_query_date.value = '2026-11-09';
    elements.cash_query_seller.value = 's2';
    await elements.cash_query_filters.dispatch('submit');
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(sessionCalls[0], { date: '2026-11-09', seller_id: 's2' });
    assert.equal(calls.length, 0, 'Admin must select a session before loading its movements');
    assert.equal(elements.cash_query_sessions.hidden, false);
    assert.equal(elements.cash_query_turn_filter.hidden, true,
        'The turn filter stays hidden when the seller has only one turn.');
    await elements.cash_query_session_options.children[0].children[2].dispatch('click');
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(calls[0], {
        date: '2026-11-09', seller_id: 's2', session_id: 'session-selected', page: 1
    });
    assert.equal(elements.cash_query_results.hidden, false);
    assert.equal(elements.cash_query_balance.textContent, 'S/ 300,00');
    assert.equal(elements.cash_query_sales.textContent, '+S/ 80,00');

    elements.cash_query_date.value = '2026-11-08';
    elements.cash_query_seller.value = 's1';
    await elements.cash_query_next.dispatch('click');
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(calls[1], {
        date: '2026-11-09', seller_id: 's2', session_id: 'session-selected', page: 2
    });
    assert.equal(elements.cash_query_page_status.textContent, 'Mostrando 1 de 3 movimientos · Página 2 de 2');
    assert.equal(elements.cash_query_rows.children[0].children[4].textContent,
        'Sin efecto: ya reflejado en la apertura');
    assert.equal(elements.cash_query_rows.children[0].children[0].textContent, '09/11/2026 10:00');

    await elements.cash_query_filters.dispatch('reset');
    await new Promise(resolve => setTimeout(resolve, 1));
    assert.equal(elements.cash_query_results.hidden, true);
    assert.equal(elements.cash_query_seller.value, '');
    assert.equal(elements.cash_query_date.value, '2026-11-10');
    assert.equal(elements.cash_query_sessions.hidden, true);
    console.log('[PASS] Administrador consulta bajo demanda, pagina con filtros fijos y limpia resultados.');
}

async function testTurnFilterOnlyWhenMultipleTurnsExist() {
    const calls = [];
    const sessions = [
        {
            id: 'session-morning', seller: 'Luis', turn: 'Mañana', status: 'Cerrada',
            opened_at: '2026-11-09T08:00:00-05:00', closed_at: '2026-11-09T15:00:00-05:00'
        },
        {
            id: 'session-evening', seller: 'Luis', turn: 'Tarde', status: 'Abierta',
            opened_at: '2026-11-09T16:00:00-05:00', closed_at: null
        }
    ];
    const screenService = {
        typeLabels: {},
        listSellers: async () => [{ id: 'seller-luis', name: 'Luis' }],
        listSessions: async () => sessions,
        query: async filters => {
            calls.push(Object.assign({}, filters));
            return sampleResult(filters.page);
        }
    };
    const { elements } = createScreen(true, screenService);
    await new Promise(resolve => setImmediate(resolve));
    elements.cash_query_seller.value = 'seller-luis';
    await elements.cash_query_filters.dispatch('submit');
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(elements.cash_query_turn_filter.hidden, false);
    assert.equal(elements.cash_query_session_options.children.length, 2);
    assert.equal(elements.cash_query_turn.children.length, 3);
    elements.cash_query_turn.value = 'name:tarde';
    await elements.cash_query_turn.dispatch('change');
    assert.equal(elements.cash_query_session_options.children.length, 1);
    assert.match(elements.cash_query_session_options.children[0].children[0].textContent, /Tarde/);

    await elements.cash_query_session_options.children[0].children[2].dispatch('click');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls[0].session_id, 'session-evening');
    console.log('[PASS] El filtro aparece con turnos mañana y tarde y conserva la selección de sesión.');

    const sameTurnService = Object.assign({}, screenService, {
        listSessions: async () => sessions.map(session => Object.assign({}, session, { turn: 'Mañana' }))
    });
    const sameTurnScreen = createScreen(true, sameTurnService);
    await new Promise(resolve => setImmediate(resolve));
    sameTurnScreen.elements.cash_query_seller.value = 'seller-luis';
    await sameTurnScreen.elements.cash_query_filters.dispatch('submit');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(sameTurnScreen.elements.cash_query_turn_filter.hidden, true);
    console.log('[PASS] El filtro permanece oculto cuando las sesiones disponibles comparten un solo turno.');
}

async function testSellerScreenFlow() {
    let activeCalls = 0;
    const screenService = {
        typeLabels: {},
        listSellers: async () => { throw new Error('Vendedor no debe cargar filtros'); },
        query: async () => { throw new Error('Vendedor no debe buscar arbitrariamente'); },
        getActiveSession: async () => {
            activeCalls += 1;
            return { type: 'no_session' };
        }
    };
    const { elements } = createScreen(false, screenService);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(activeCalls, 1);
    assert.equal(elements.cash_query_no_session.hidden, false);
    assert.equal(elements.cash_query_empty_text.textContent,
        'Debes abrir una sesión de caja para consultar tu saldo y movimientos.');
    assert.equal(elements.cash_query_open_link.hidden, false);
    console.log('[PASS] Vendedor consulta solo su sesión activa y recibe enlace si no tiene caja.');
}

async function main() {
    await testAdministratorSessionAndEmptyDate();
    await testMultipleSessionsNeedExplicitSelection();
    await testSellerAndNoMovements();
    await testAccessDeniedErrorAndPagination();
    await testApiAdapterAndOptions();
    await testAdministratorScreenFlow();
    await testTurnFilterOnlyWhenMultipleTurnsExist();
    await testSellerScreenFlow();
}

main().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
