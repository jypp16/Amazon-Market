// Pruebas unitarias del contrato del cliente; no sustituyen las pruebas HTTP/navegador.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

(async () => {
    let reply, sent, confirmed = [], scope;
    const api = {
        request: async (endpoint, options) => { sent = {endpoint, options}; return reply; },
        referenciaSolicitud: async (s) => { scope = s; return 'reference_p13_contract'; },
        confirmarSolicitud: s => confirmed.push(s)
    };
    const context = {window: {}, Api: api, API_URL: '/api', AUTH_USER_ID: 77};
    vm.createContext(context);
    for (const file of ['cajaMovimientosService', 'cajaArqueoService', 'cajaConsultaService']) {
        vm.runInContext(fs.readFileSync(`Assets/js/${file}.js`, 'utf8'), context);
    }
    const movements = context.window.CajaMovimientosService;
    reply = {ok:true, status:200, data:{status:true, data:{is_open:false, is_owner:false, session_id:null, current_balance:null, balance:null}}};
    assert.equal((await movements.getSessionState()).balance, null);
    reply.data.data = {is_open:true, is_owner:true, session_id:13, current_balance:0, balance:0};
    assert.equal((await movements.getSessionState()).balance, 0);
    reply = {ok:false, status:409, data:{status:false, code:'INSUFFICIENT_BALANCE', data:{current_balance:0}}};
    assert.equal((await movements.registerExpense({type:'cash_out',amount:1,reason:'Prueba'})).type, 'insufficient_balance');
    assert.equal(confirmed.length, 0);
    assert.equal(scope, 'caja/egresos:13');
    reply = {ok:true,status:200,data:{status:true,code:'OPERATION_ALREADY_CONFIRMED',data:{id:15}}};
    assert.equal((await movements.registerIncome({amount:1, reason:'Prueba',request_id:'discarded'})).type,'success');
    assert.equal(sent.options.body.referencia_solicitud, 'reference_p13_contract');
    assert.equal(sent.options.body.request_id, undefined);
    assert.equal(confirmed.at(-1), 'caja/ingresos:13');
    const arqueos = context.window.CajaArqueoService;
    reply = {ok:true,status:200,data:{status:true,data:{is_open:true,session_id:13,expected:0,version:4}}};
    assert.equal((await arqueos.getState()).expected,0);
    reply = {ok:false,status:409,data:{status:false,code:'CASH_CHANGED',data:{}}};
    assert.equal((await arqueos.saveArqueo({counted:0,version:4})).type,'cash_changed');
    assert.equal(scope,'caja-arqueo:13');
    reply = {ok:true,status:200,data:{status:true,code:'NO_OPEN_SESSION',data:{type:'no_session'}}};
    assert.equal((await context.window.CajaConsultaService.getActiveSession()).type,'no_session');
    reply = {ok:false,status:403,data:{status:false,code:'FORBIDDEN'}};
    assert.equal((await context.window.CajaConsultaService.getActiveSession()).type,'access_denied');
    console.log('PASS: 14 comprobaciones de adaptadores (null/cero, errores, reintentos, vigencia, permisos).');
})().catch(error => {console.error(error); process.exit(1);});
