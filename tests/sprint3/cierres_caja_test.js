const assert = require('node:assert/strict');
const fs = require('node:fs');

const controller = fs.readFileSync('Controllers/ReporteController.php', 'utf8');
const listView = fs.readFileSync('Views/Reporte/cierresCaja.php', 'utf8');
const detailView = fs.readFileSync('Views/Reporte/detalleCierreCaja.php', 'utf8');
const reportIndex = fs.readFileSync('Views/Reporte/index.php', 'utf8');
const header = fs.readFileSync('Views/Header.php', 'utf8');
const rbac = fs.readFileSync('Libraries/Middleware/RBACMiddleware.php', 'utf8');

assert.match(reportIndex, /href="<\?= BASE_URL \?>\/Reporte\/cierresCaja"/);
assert.match(controller, /public function cierresCaja\(\)/);
assert.match(controller, /public function detalleCierreCaja\(\$id = ''\)/);
assert.match(listView, /Ver detalle/);
assert.match(listView, /Reporte\/detalleCierreCaja\/<\?= \(int\)\$cierre\['id'\] \?>/);
assert.match(detailView, /Volver al reporte/);
assert.match(detailView, /Reporte\/cierresCaja/);
assert.match(detailView, /Resumen de efectivo/);
assert.match(detailView, /Datos del cierre/);
assert.match(detailView, /Movimientos de la sesión/);
assert.match(detailView, /Fecha y hora/);
assert.match(detailView, /\$movimiento\['date'\].*closed_at/);
assert.match(controller, /'date' => '20\/10\/2026', 'time' => '09:40'/);
assert.match(header, /Reporte\/detalleCierreCaja/);
assert.match(rbac, /'cierrescaja'\s*=>\s*'reportes\.ver'/);
assert.match(rbac, /'detallecierrecaja'\s*=>\s*'reportes\.ver'/);
assert.match(listView, /Exportar PDF/);
assert.match(listView, /Exportar Excel/);
assert.match(listView, /Consultar/);
assert.match(listView, /disabled/);

console.log('[PASS] Tarjeta, listado y detalle están enlazados con retorno funcional.');
console.log('[PASS] Controles no solicitados como funcionales se mantienen solo visuales.');
console.log('[PASS] Las rutas de cierres usan el permiso existente reportes.ver.');
