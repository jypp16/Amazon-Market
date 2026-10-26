// Ejecuta los scripts reales con DOM y transporte controlados; no escribe en BD.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const script = file => fs.readFileSync(path.join(root, 'Assets/js', file), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
class Element {
  constructor() { this.value = ''; this.events = {}; this.children = []; this.style = {}; this.classList = { toggle() {} }; }
  addEventListener(name, cb) { this.events[name] = cb; }
  replaceChildren() { this.children = []; }
  appendChild(child) { this.children.push(child); }
  querySelectorAll() { return []; }
  setAttribute() {}
  focus() {}
}
async function saleRetry() {
  const elements = Object.fromEntries(['btn_procesar', 'id_cliente', 'id_tipo_comprobante', 'id_metodo_pago', 'loader_overlay'].map(id => [id, new Element()]));
  for (const id of ['id_cliente', 'id_tipo_comprobante', 'id_metodo_pago']) elements[id].value = '1';
  const bodies = [];
  const storage = new Map();
  const context = vm.createContext({ console, URLSearchParams, AbortController, FormData, crypto: require('node:crypto').webcrypto, TextEncoder,
    sessionStorage: { getItem: k => storage.get(k) || null, setItem: (k,v) => storage.set(k,v), removeItem: k => storage.delete(k) },
    document: { addEventListener() {}, getElementById: id => elements[id], querySelector: () => null },
    Modal: { confirm: async () => true, error: async () => {}, warning() {} },
    fetch: async (url, options) => { bodies.push(JSON.parse(options.body)); throw new Error('Respuesta perdida después de confirmar'); }
  });
  vm.runInContext(script('api.js'), context);
  vm.runInContext(script('venta.js'), context);
  vm.runInContext('carrito = [{id_producto:1,cantidad:2,precio_venta:5,precio:5}];', context);
  await vm.runInContext('procesarVenta()', context);
  await vm.runInContext('procesarVenta()', context);
  assert.equal(bodies.length, 2);
  assert.ok(bodies[0].referencia_solicitud);
  assert.equal(bodies[0].referencia_solicitud, bodies[1].referencia_solicitud, 'Reintentar venta debe conservar la referencia');
  const original = await vm.runInContext('Api.referenciaSolicitud("compra-prueba", {cantidad:2})', context);
  const other = await vm.runInContext('Api.referenciaSolicitud("compra-prueba", {cantidad:99999})', context);
  assert.notEqual(original, other);
  const retried = await vm.runInContext('Api.referenciaSolicitud("compra-prueba", {cantidad:2})', context);
  assert.equal(original, retried, 'Volver de A a B y a A debe conservar la intención pendiente de A');
  vm.runInContext('Api.confirmarSolicitud("compra-prueba")', context);
  const fresh = await vm.runInContext('Api.referenciaSolicitud("compra-prueba", {cantidad:2})', context);
  assert.notEqual(original, fresh, 'Una operación nueva tras confirmación usa otra referencia');
}
async function kardexDrafts() {
  const elements = {};
  const get = id => elements[id] ||= new Element();
  get('kardex-start-date').value = '2026-10-01';
  get('kardex-end-date').value = '2026-10-31';
  const requests = [];
  let init;
  const context = vm.createContext({ console, Intl, URLSearchParams, window: { clearTimeout, setTimeout },
    document: { getElementById: get, querySelector: get, createElement: () => new Element(), addEventListener: (event, cb) => { if(event === 'DOMContentLoaded') init = cb; } },
    Api: { get: async endpoint => {
      if (endpoint.startsWith('categorias')) return { ok: true, data: { status: true, data: [] } };
      requests.push(new URLSearchParams(endpoint.split('?')[1]));
      return {ok:true, data:{status:true,data:{referencia_consulta:'snapshot-original',items:[],pagination:{page:requests.length,pages:3,total:25}}}};
    } }
  });
  vm.runInContext(script('kardex.js'), context);
  init(); await flush();
  get('kardex-start-date').value = '2026-09-01';
  get('kardex-start-date').events.change();
  get('kardex-next-page').events.click(); await flush();
  assert.equal(requests[1].get('fecha_inicio'), '2026-10-01', 'Paginar no debe aplicar el borrador de fechas');
  assert.equal(requests[1].get('referencia_consulta'), 'snapshot-original');
  get('.kardex-search-button').events.click(); await flush();
  assert.equal(requests[2].get('fecha_inicio'), '2026-09-01');
  assert.equal(requests[2].has('referencia_consulta'), false, 'Consultar debe iniciar una consulta nueva');
}
(async () => {
  let failed = 0;
  for (const [name, test] of [['Venta conserva intención tras respuesta perdida', saleRetry], ['Kardex separa borrador y filtros aplicados', kardexDrafts]]) {
    try { await test(); console.log('[PASS]', name); } catch(error) { failed++; console.error('[FAIL]', name, error.message); }
  }
  process.exitCode = failed ? 1 : 0;
})();
