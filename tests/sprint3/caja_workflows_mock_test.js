const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('Assets/js/cajaDemoService.js', 'utf8');

function createDemo(scenario, opening) {
    const window = {
        CAJA_WORKFLOWS_CONFIG: {
            mockEnabled: true,
            scenario,
            latencyMs: 0,
            sellerId: 'seller-1',
            sellerName: 'Vendedora',
            sellerUsername: 'seller'
        },
        setTimeout,
        crypto: { randomUUID: () => 'request-id' }
    };
    if (opening) window.CAJA_SERVICE_CONFIG = { sellerId: 'seller-1', referenceAmount: 200 };
    const context = { window, Date, Intl, Map, Number, Promise, JSON, Object, String };
    vm.createContext(context);
    vm.runInContext(source, context);
    return window.CajaDemoService;
}

async function testOpeningStates() {
    const noShift = createDemo('no_shift', true);
    assert.equal((await noShift.getTurnoDeHoy('seller-1')).status, 'no_shift');
    assert.equal((await noShift.getEstadoCaja('seller-1')).is_open, false);
    const selfAuthorization = await noShift.solicitarAutorizacion({
        operation: 'open',
        cash_counted: 190,
        admin_username: 'seller',
        admin_password: 'secret',
        exception_reason: 'Autoautorización rechazada'
    });
    assert.equal(selfAuthorization.type, 'authorization_denied');
    const opened = await noShift.solicitarAutorizacion({
        operation: 'open',
        cash_counted: 190,
        admin_username: 'admin',
        admin_password: 'secret',
        exception_reason: 'Cobertura autorizada'
    });
    assert.equal(opened.type, 'opened');
    assert.equal((await noShift.getEstadoCaja('seller-1')).is_open, true);

    const alreadyOpen = createDemo('already_open', true);
    assert.equal((await alreadyOpen.getEstadoCaja('seller-1')).is_open, true);
    console.log('[PASS] Mock de apertura: sin turno, autorización y caja ya abierta.');
}

async function testArqueoAndPersistedClose() {
    const demo = createDemo('no_arqueos', false);
    const state = await demo.getEstadoCaja('seller-1');
    assert.equal((await demo.getArqueos(state.session_id)).history.length, 0);
    const saved = await demo.guardarArqueo({
        request_id: 'save-1',
        counted: state.expected,
        observation: '',
        version: state.version
    });
    assert.equal(saved.type, 'saved');
    const closed = await demo.confirmarCierre({
        request_id: 'close-1',
        arqueo_id: saved.arqueo.id
    });
    assert.equal(closed.type, 'closed');
    assert.equal((await demo.getCierre(state.session_id)).summary.counted, state.expected);
    assert.equal((await demo.getEstadoCaja('seller-1')).is_open, false);
    console.log('[PASS] Mock de arqueo vacío, guardado y cierre recuperable.');
}

async function testConnectionLossReconciliation() {
    const demo = createDemo('connection_lost', false);
    const state = await demo.getEstadoCaja('seller-1');
    const saved = await demo.guardarArqueo({
        request_id: 'save-before-close',
        counted: state.expected,
        observation: '',
        version: state.version
    });
    await assert.rejects(demo.confirmarCierre({
        request_id: 'close-after-loss',
        arqueo_id: saved.arqueo.id
    }), /Conexión perdida/);
    assert.ok((await demo.getCierre(state.session_id)).summary);
    console.log('[PASS] Mock de cierre recupera el resultado persistido tras perder la conexión.');
}

(async function main() {
    await testOpeningStates();
    await testArqueoAndPersistedClose();
    await testConnectionLossReconciliation();
}()).catch(error => {
    console.error('[FAIL] ' + error.message);
    process.exitCode = 1;
});
