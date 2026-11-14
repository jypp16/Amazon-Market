const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');

(async () => {
    const calls = [], pending = [], storage = new Map();
    const response = () => ({ok:true, status:200, headers:{get:()=> 'application/json'}, json:async()=> ({status:true,data:{}})});
    function createClient(userId) {
        const context = {
            API_URL:'/api', AUTH_USER_ID:userId, TextEncoder, crypto:webcrypto,
            AbortController, FormData, setTimeout, clearTimeout,
            document:{querySelector:()=> ({getAttribute:()=> 'csrf'})},
            sessionStorage:{getItem:k=>storage.get(k), setItem:(k,v)=>storage.set(k,v), removeItem:k=>storage.delete(k)},
            fetch:async (url,config) => {
                calls.push({url,config});
                if (url.endsWith('pending')) return new Promise(resolve=>pending.push(()=>resolve(response())));
                return response();
            }
        };
        vm.createContext(context);
        vm.runInContext(fs.readFileSync('Assets/js/api.js','utf8')+';globalThis.client=Api;',context);
        return context.client;
    }
    const api = createClient(77);
    await api.delete('turnos/1',{headers:{'X-Request-Reference':'reference_12345678'}});
    assert.equal(calls.at(-1).config.headers['X-Request-Reference'],'reference_12345678');
    assert.equal(calls.at(-1).config.headers['X-CSRF-Token'],'csrf');

    const p1 = api.post('pending',{a:1}), p2 = api.post('pending',{a:2});
    assert.equal(calls.at(-2).config.signal.aborted,false);
    pending.splice(0).forEach(resolve=>resolve());
    await Promise.all([p1,p2]);

    const ref = await api.referenciaSolicitud('test',{a:1});
    assert.equal(ref,await api.referenciaSolicitud('test',{a:1}));
    assert.equal(ref,await createClient(77).referenciaSolicitud('test',{a:1}), 'Recargar conserva la referencia');
    assert.notEqual(ref,await createClient(78).referenciaSolicitud('test',{a:1}), 'Otro usuario tiene otra identidad');
    api.confirmarSolicitud('test');
    assert.notEqual(ref,await api.referenciaSolicitud('test',{a:1}));

    const s1 = api.get('pending'), s2 = api.get('pending');
    assert.equal(calls.at(-2).config.signal.aborted,true);
    pending.shift()();
    await s1;
    const secondSignal = calls.at(-1).config.signal;
    const s3 = api.get('pending');
    assert.equal(secondSignal.aborted,true,'Una consulta antigua no borra el controlador de la nueva');
    pending.splice(0).forEach(resolve=>resolve());
    await Promise.all([s2,s3]);
    console.log('PASS: headers DELETE, CSRF, escrituras independientes, persistencia tras recarga, separación por usuario y carreras GET.');
})().catch(error => {console.error(error); process.exit(1);});
