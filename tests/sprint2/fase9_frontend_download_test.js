const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const kardexScript = fs.readFileSync('Assets/js/kardex.js', 'utf8');
const variationsScript = fs.readFileSync('Assets/js/variaciones.js', 'utf8');
const apiSource = fs.readFileSync('Assets/js/api.js', 'utf8');

assert.match(kardexScript, /exportExcelBtn\.addEventListener\('click', exportarKardexExcel\)/);
assert.match(kardexScript, /Api\.downloadExcel\('reportes\/exportar-excel\?'/);
assert.match(kardexScript, /referencia_consulta: currentConsultaRef/);
assert.match(kardexScript, /QUERY_REFERENCE_EXPIRED.*QUERY_FILTERS_MISMATCH/s);
assert.match(kardexScript, /exportExcelBtn\.disabled = !isExportableCurrent/);
assert.match(variationsScript, /exportExcelBtn\.addEventListener\('click', exportarVariacionesExcel\)/);
assert.match(variationsScript, /Api\.downloadExcel\('reportes\/exportar-excel\?'/);
assert.match(variationsScript, /sentido/);
assert.match(variationsScript, /QUERY_REFERENCE_EXPIRED.*QUERY_FILTERS_MISMATCH/s);
console.log('[PASS] [F9-UI-01] Botones Excel conservan filtros aplicados y referencia de consulta en ambos reportes.');

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
    URL: { createObjectURL: () => 'blob:xlsx-test', revokeObjectURL() {} },
    window: { setTimeout() {} }
};
vm.createContext(context);
vm.runInContext(apiSource + '\nglobalThis.__ApiForTest = Api;', context);

async function main() {
    const fakeZip = Uint8Array.from([...Buffer.from('PK\x03\x04'), ...Buffer.from('[Content_Types].xml xl/workbook.xml')]);
    mockResponse = {
        ok: true,
        status: 200,
        headers: { get: key => key === 'content-type'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'attachment; filename="Reporte_Kardex_2026-10-25_181900.xlsx"' },
        blob: async () => ({ arrayBuffer: async () => fakeZip.buffer, size: fakeZip.byteLength })
    };
    const success = await context.__ApiForTest.downloadExcel('reportes/exportar-excel?tipo=kardex', 'fallback.xlsx');
    assert.equal(success.ok, true);
    assert.equal(clicks.length, 1);
    assert.equal(clicks[0].download, 'Reporte_Kardex_2026-10-25_181900.xlsx');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.match(requests[0].options.headers.Accept, /spreadsheetml/);
    console.log('[PASS] [F9-UI-02] Descarga XLSX usa sesión actual, nombre del API y valida el paquete Excel.');

    mockResponse = {
        ok: false,
        status: 410,
        headers: { get: key => key === 'content-type' ? 'application/json; charset=utf-8' : null },
        json: async () => ({ code: 'QUERY_REFERENCE_EXPIRED', message: 'La referencia venció.' })
    };
    const failure = await context.__ApiForTest.downloadExcel('reportes/exportar-excel?tipo=kardex', 'fallback.xlsx');
    assert.equal(failure.ok, false);
    assert.equal(failure.status, 410);
    assert.equal(failure.data.code, 'QUERY_REFERENCE_EXPIRED');
    assert.equal(clicks.length, 1);
    console.log('[PASS] [F9-UI-03] Respuestas JSON de error se devuelven al estado de la pantalla.');

    mockResponse = {
        ok: true,
        status: 200,
        headers: { get: key => key === 'content-type'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : null },
        blob: async () => ({ arrayBuffer: async () => Uint8Array.from(Buffer.from('PK\x03\x04not-an-xlsx')).buffer, size: 15 })
    };
    const invalid = await context.__ApiForTest.downloadExcel('reportes/exportar-excel?tipo=kardex', 'fallback.xlsx');
    assert.equal(invalid.ok, false);
    assert.equal(clicks.length, 1);
    console.log('[PASS] [F9-UI-04] Un ZIP que no contiene los componentes mínimos OOXML no se descarga.');
}

main().catch(error => {
    console.error('[FAIL] ' + error.message);
    process.exitCode = 1;
});
