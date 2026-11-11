const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const serviceSource = fs.readFileSync('Assets/js/cajaArqueoService.js', 'utf8');
const demoSource = fs.readFileSync('Assets/js/cajaDemoService.js', 'utf8');
const screenSource = fs.readFileSync('Assets/js/cajaArqueo.js', 'utf8');
const viewSource = fs.readFileSync('Views/Caja/arqueo.php', 'utf8');
const authPartialSource = fs.readFileSync('Views/Caja/_autorizacion.php', 'utf8');

function createService(scenario, apiRequest) {
    let requestId = 0;
    const window = {
        CAJA_ARQUEO_CONFIG: { mockEnabled: scenario !== null, mockScenario: scenario || 'success', mockLatencyMs: 0 },
        CAJA_WORKFLOWS_CONFIG: { mockEnabled: scenario !== null, scenario: scenario || 'success', latencyMs: 0 },
        crypto: { randomUUID: () => 'arqueo-request-' + (++requestId) },
        setTimeout
    };
    const context = {
        window,
        Api: { request: apiRequest || (() => Promise.reject(new Error('API no debe llamarse en modo mock'))) },
        Intl,
        Date,
        Map,
        Number,
        Promise
    };
    vm.createContext(context);
    vm.runInContext(demoSource, context);
    vm.runInContext(serviceSource, context);
    return window.CajaArqueoService;
}

async function save(service, payload = {}) {
    const state = await service.getState();
    return service.saveArqueo(Object.assign({
        counted: state.expected,
        observation: '',
        version: state.version,
        request_id: 'save-' + (payload.request_id || 'unique')
    }, payload));
}

async function close(service, state, extra = {}) {
    const active = state.history.find(item => item.status === 'Vigente');
    return service.closeCash(Object.assign({
        arqueo_id: active && active.id,
        request_id: 'close-once'
    }, extra));
}

async function testBreakdownsAndPreviewCases() {
    for (const [scenario, expectedDifference, status] of [
        ['shortage', -5, 'Vigente'],
        ['surplus', 10, 'Vigente'],
        ['balanced', 0, 'Vigente'],
        ['stale', -5, 'Desactualizado']
    ]) {
        const state = await createService(scenario).getState();
        assert.equal(state.is_open, true);
        assert.equal(state.expected, 300);
        assert.equal(state.opening_fund + state.cash_sales + state.income - state.expenses, state.expected);
        const last = state.history[state.history.length - 1];
        assert.equal(last.difference, expectedDifference);
        assert.equal(last.status, status);
        assert.match(last.date, /^\d{4}-\d{2}-\d{2}$/);
    }
    console.log('[PASS] Estado de caja, desglose, faltante, sobrante, equilibrio y arqueo desactualizado.');
}

async function testSaveHistoryAndIdempotency() {
    const service = createService('success');
    const first = await save(service, { counted: 295, observation: 'Faltan monedas', request_id: 'same' });
    const again = await save(service, { counted: 295, observation: 'Faltan monedas', request_id: 'same' });
    assert.equal(first.type, 'saved');
    assert.deepEqual(JSON.parse(JSON.stringify(again)), JSON.parse(JSON.stringify(first)));
    const state = await service.getState();
    assert.deepEqual(JSON.parse(JSON.stringify(state.history.map(item => item.status))),
        ['Reemplazado', 'Reemplazado', 'Vigente']);
    console.log('[PASS] Guardar conserva historial, reemplaza vigencia y evita duplicados por reintento.');
}

async function testConcurrentChange() {
    const service = createService('concurrent_sale');
    const changed = await save(service, { counted: 295, observation: 'Conteo', request_id: 'initial' });
    assert.equal(changed.type, 'caja_changed');
    assert.equal(changed.expected, 310);
    const refreshed = await service.getState();
    assert.equal(refreshed.history.at(-1).status, 'Desactualizado');
    assert.equal((await save(service, { counted: 310, version: refreshed.version, request_id: 'new' })).type, 'saved');
    console.log('[PASS] Venta concurrente actualiza esperado/versión y requiere guardar un arqueo nuevo.');
}

async function testCloseAndAuthorization() {
    const service = createService('authorization_required');
    await save(service, { counted: 300, request_id: 'save-before-close' });
    let state = await service.getState();
    const required = await close(service, state);
    assert.equal(required.type, 'authorization_required');
    assert.deepEqual(JSON.parse(JSON.stringify(required.schedule)), { start: '08:00', end: '14:00' });
    const confirmed = await close(service, state, {
        admin_username: 'admin',
        admin_password: 'secret',
        exception_reason: 'Cierre solicitado'
    });
    assert.equal(confirmed.type, 'closed');
    assert.equal(confirmed.summary.counted, 300);
    state = await service.getState();
    assert.equal(state.is_open, false);
    console.log('[PASS] Cierre vigente y autorización de administrador fuera de horario.');
}

async function testSessionLostConnectionAndErrors() {
    const closedSession = await createService('session_closed').getState();
    assert.equal(closedSession.is_open, false);

    const lostService = createService('connection_lost');
    await save(lostService, { counted: 300, request_id: 'save-before-loss' });
    const beforeClose = await lostService.getState();
    await assert.rejects(close(lostService, beforeClose), /Conexión perdida/);
    const reconciled = await lostService.getState();
    assert.equal(reconciled.is_open, false);
    assert.equal(reconciled.closed_summary.counted, 300);

    assert.equal((await save(createService('server_error'), { counted: 300 })).type, 'error');
    const noActiveArqueo = await close(createService('stale'), await createService('stale').getState());
    assert.equal(noActiveArqueo.type, 'arqueo_stale');
    console.log('[PASS] Sesión cerrada, reconciliación tras pérdida de conexión y errores del servidor.');
}

function testMarkupAndAccessibility() {
    assert.match(viewSource, /Efectivo esperado/);
    assert.match(viewSource, /cash_count_amount/);
    assert.match(viewSource, /aria-live="polite"/);
    assert.match(viewSource, /Historial de arqueos/);
    assert.match(viewSource, /Fecha y hora/);
    assert.match(screenSource, /formatArqueoDateTime/);
    assert.match(viewSource, /id="cash_close_confirm"/);
    assert.match(viewSource, /Views\/Caja\/_autorizacion\.php/);
    assert.match(authPartialSource, /role="dialog" aria-modal="true"/);
    assert.match(authPartialSource, /type="password"/);
    assert.match(screenSource, /Desactualizado/);
    assert.match(screenSource, /Hubo cambios en la caja/);
    assert.match(screenSource, /No se pudo completar la operación/);
    console.log('[PASS] Vista cubre desglose, historial, cierres, accesibilidad y modal compartido.');
}

async function testApiAdapter() {
    const calls = [];
    const apiService = createService(null, (endpoint, options) => {
        calls.push({ endpoint, options });
        if (endpoint === 'caja/arqueo/estado') {
            return Promise.resolve({ data: { status: true, data: {
                is_open: true, is_owner: true, seller: 'Luis', turn: 'Mañana',
                opening_fund: 200, cash_sales: 80, income: 50, expenses: 30,
                expected: 300, card: 90, yape_plin: 40, version: 3, history: []
            } } });
        }
        return Promise.resolve({ data: { status: true, data: { summary: { counted: 300 } } } });
    });
    const state = await apiService.getState();
    assert.equal(state.expected, 300);
    const saved = await apiService.saveArqueo({ request_id: 'save-ref', counted: 300 });
    assert.equal(saved.type, 'saved');
    const closed = await apiService.closeCash({ request_id: 'close-ref' });
    assert.equal(closed.type, 'closed');
    assert.equal(calls[1].options.headers['X-Request-Reference'], 'save-ref');
    assert.equal(calls[2].options.headers['X-Request-Reference'], 'close-ref');
    console.log('[PASS] Adaptador real usa Api.request, normaliza respuestas e incluye referencias de idempotencia.');
}

async function main() {
    await testBreakdownsAndPreviewCases();
    await testSaveHistoryAndIdempotency();
    await testConcurrentChange();
    await testCloseAndAuthorization();
    await testSessionLostConnectionAndErrors();
    testMarkupAndAccessibility();
    await testApiAdapter();
}

main().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
