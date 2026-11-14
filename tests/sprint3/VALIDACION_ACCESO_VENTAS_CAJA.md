# Acceso del vendedor al punto de venta

Fecha: 13/11/2026 (America/Lima).

## Corrección

AuthController dirige al vendedor (rol 2) a /Caja/abrir tras un login correcto. Los demás roles mantienen /Home. VentaController consulta la sesión actual antes de cargar clientes, productos y formulario: sin sesión propia abierta entrega únicamente el aviso «Debes abrir caja para realizar una venta» y el enlace Abrir caja. La vista restringida no contiene pos_shelf ni pos_cart; venta.js ya omite inicializarse cuando falta pos_shelf. Con caja propia abierta entrega el POS habitual. Se conserva el control de rol/RBAC y la validación transaccional de VentaService (CASH_SESSION_CLOSED y SESSION_NOT_OWNED), que ya existía.

No se aplica la condición de caja al administrador. Su acceso al POS sigue regido por las restricciones de rol existentes; no se amplían sus permisos. La comprobación visual se realiza al solicitar /Venta o /Venta/index; cualquier pantalla antigua abierta continúa protegida por el rechazo de la API si la caja se cierra antes de confirmar.

## Evidencia ejecutada

Prueba nueva: tests/sprint3/pos_caja_acceso_test.php. Antes del cambio: 10 PASS / 6 FAIL. Después: **16 PASS / 0 FAIL**, salida 0. Verifica login de vendedor y administrador, ausencia de catálogo/carrito con caja cerrada, enlace de apertura, cache no-store, rechazo API sin efectos, apertura real en clon, caja ajena y reingreso con sesión de caja abierta. Compara también snapshot completo de amazon_market antes y después, sin diferencias.

Bases aisladas nuevas: amazon_market_sprint3_deploy_rehearsal_1794626000001 (reproducción), _1794626000002 (corrección), _1794626000003 (regresión), todas restauradas y migradas mediante el ejecutor de ensayo. Las contraseñas de fixtures solo se modifican en esas copias. No se modifica .env ni se migra la base principal.

Comandos de pruebas en PowerShell:

~~~powershell
$env:TEST_DB_NAME='amazon_market_sprint3_deploy_rehearsal_1794626000002'
C:/xampp/php/php.exe tests/sprint3/pos_caja_acceso_test.php
$env:TEST_DB_NAME='amazon_market_sprint3_deploy_rehearsal_1794626000003'
$env:TEST_HTTP_PORT='8386'
C:/xampp/php/php.exe tests/sprint3/paso9_ventas_caja_test.php
~~~

Regresión Paso 9: **107/107**, salida 0, incluyendo ventas, roles, cierre concurrente, rollback e idempotencia. Los helpers de login de los pasos 6..10 se adaptaron a los destinos válidos /Home y /Caja/abrir; el nuevo test exige específicamente el destino de cada rol. Sintaxis PHP y git diff --check correctos. Validación funcional HTTP y persistencia; no se afirma prueba visual manual en navegador.
