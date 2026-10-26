const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const kardexScript = fs.readFileSync('Assets/js/kardex.js', 'utf8');
const variationsScript = fs.readFileSync('Assets/js/variaciones.js', 'utf8');
const apiSource = fs.readFileSync('Assets/js/api.js', 'utf8');

assert.match(kardexScript, /exportPdfBtn\.addEventListener\('click', exportarKardexPdf\)/);
assert.match(kardexScript, /Api\.download\('reportes\/exportar-pdf\?'/);
assert.match(variationsScript, /exportPdfBtn\.addEventListener\('click', exportarVariacionesPdf\)/);
assert.match(variationsScript, /Api\.download\('reportes\/exportar-pdf\?'/);
console.log('[PASS] Botones de Kardex y Variaciones invocan la descarga PDF con los filtros aplicados.');

const requests = [];
const clicks = [];
let mockResponse;
const context = {
    API_URL: '/api',
    fetch: async (url, options) => {
        requests.push({ url, options });
        return mockResponse;
    },
    document: {
        querySelector: () => null,
        createElement: () => ({
            click() { clicks.push({ href: this.href, download: this.download }); },
            remove() {},
            style: {}
        }),
        body: { appendChild() {} }
    },
    URL: { createObjectURL: () => 'blob:pdf-test', revokeObjectURL() {} },
    window: { setTimeout() {} }
};
vm.createContext(context);
vm.runInContext(apiSource + '\nglobalThis.__ApiForTest = Api;', context);

async function main() {
    mockResponse = {
        ok: true,
        status: 200,
        headers: { get: key => key === 'content-type' ? 'application/pdf' : 'inline; filename="Reporte_Kardex_2026-10-25_181900.pdf"' },
        blob: async () => ({ type: 'application/pdf' })
    };
    const success = await context.__ApiForTest.download('reportes/exportar-pdf?tipo=kardex', 'fallback.pdf');
    assert.equal(success.ok, true);
    assert.equal(clicks.length, 1);
    assert.equal(clicks[0].download, 'Reporte_Kardex_2026-10-25_181900.pdf');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.match(requests[0].options.headers.Accept, /application\/pdf/);
    console.log('[PASS] Descarga binaria usa sesión actual y nombre de archivo enviado por el API.');

    mockResponse = {
        ok: false,
        status: 410,
        headers: { get: key => key === 'content-type' ? 'application/json; charset=utf-8' : null },
        json: async () => ({ code: 'QUERY_REFERENCE_EXPIRED', message: 'La referencia venció.' })
    };
    const failure = await context.__ApiForTest.download('reportes/exportar-pdf?tipo=kardex', 'fallback.pdf');
    assert.equal(failure.ok, false);
    assert.equal(failure.status, 410);
    assert.equal(failure.data.code, 'QUERY_REFERENCE_EXPIRED');
    assert.equal(clicks.length, 1);
    console.log('[PASS] Respuestas JSON de error se devuelven al estado de la pantalla y no se descargan.');
}

main().catch(error => {
    console.error('[FAIL] ' + error.message);
    process.exitCode = 1;
});
