# Caja del administrador sin horario — Validación

Fecha: 14/11/2026, America/Lima. Implementación aplicada en http://localhost/Amazon-Market y base amazon_market. Sin commits, sin cambios de .env, sin eliminación de filas ni tablas. Los documentos Word continúan pendientes; acuerdos y contrato Markdown actualizados.

## Resultado funcional

Administrador activo con permisos asignados puede abrir su propia sesión SIN_HORARIO, vender y registrar movimientos/correcciones propios, realizar arqueo y cerrar a cualquier hora. No se crea turno ficticio. Su horario y turno se almacenan NULL; modalidad inmutable. Vendedor conserva CON_TURNO, turno obligatorio y autorización antes de salida menos 15 minutos. Caja física única; ninguna facultad para operar o cerrar caja ajena. Arqueo vigente obligatorio para ambos roles.

Menú administrativo conserva Inicio e historial, incorpora POS, Abrir caja, Movimientos, Arqueo y cierre y Consultar mi caja. El POS exige caja propia para ambos roles. Consultas históricas y reportes incluyen responsables de ambos roles; filtro turno_id=sin_horario y PDF/Excel preservan la consulta consistente. Los aliases seller/vendedor se mantienen por compatibilidad.

## Verificación ejecutada

| Suite | Resultado | Base aislada |
|---|---|---|
| Nueva aceptación administrativa | 44/44, salida 0 | amazon_market_admin_test_202611141525 |
| Paso 7 apertura | 214/214, salida 0 | amazon_market_sprint3_deploy_rehearsal_20261114153501 |
| Paso 8 movimientos/correcciones | 163/163, salida 0 | amazon_market_sprint3_deploy_rehearsal_20261114153502 |
| Paso 9 ventas | 107/107, salida 0 | amazon_market_admin_test_202611141500 |
| Paso 10 arqueos | 154/154, salida 0 | amazon_market_sprint3_deploy_rehearsal_20261114153003 |
| Paso 11 cierre/concurrencia | 160/160, salida 0 | amazon_market_admin_test_202611141521 |
| Paso 12 consultas/reportes | 190/190, salida 0 | amazon_market_admin_test_202611141522 |
| Paso 14 exportaciones HTTP | 88/88, salida 0 | la misma copia de Paso 12 |

Las pruebas con escrituras operaron en copias independientes. La prueba inicial administrativa reprodujo 2 PASS / 5 FAIL por restricciones de rol. La suite final cubre ventas en efectivo/tarjeta/Yape-Plin, modalidad y horario NULL, validaciones de entradas, propiedad, arqueo desactualizado, idempotencia, permisos revocados incluso por ruta legacy, filtros y descargas, y reloj controlado para administrador y vendedor.

La revisión independiente detectó y permitió corregir: conexión PDO propia retenida durante drenaje, bypass administrativo por la ruta legacy, permiso incorrecto en consulta propia y menú basado en permisos globales en lugar de asignaciones efectivas. La revocación se prueba únicamente en la copia.

Los tests antiguos de rechazo administrativo se sustituyeron por rechazo del administrador sin permiso efectivo. Los supuestos de primera apertura y conteos fijos se adaptaron al estado real de cada respaldo: la base principal ya tenía 251 ventas (250 históricas sin sesión y una venta real con sesión), 2496 unidades de stock y 471 entradas de kardex. Nunca se borró esa operación para hacer coincidir una prueba antigua. Las comparaciones de conservación distinguen fuente y copia cuando usan respaldos de fechas diferentes.

## Navegador

Edge headless mediante CDP, contra servidor de ensayo 127.0.0.1:8398: login administrativo, apertura con 0.00, selector de turno oculto, etiqueta Administrador · Sin horario, arqueo vigente y cierre confirmado sin credenciales adicionales. Sin excepciones JavaScript observadas en la captura. Capturas en evidencia_admin_caja/ y resultados en admin_caja_gui*.json. El navegador integrado no inició por error del sandbox y playwright-cli no estaba disponible; se utilizó este navegador alternativo sin instalar paquetes.

## Migración y datos

SQL incremental: database/tercerSprint/12_caja_administrador_sin_horario.sql. Añade modalidad y nulabilidad condicionada, conserva SQL_MODE de los dos triggers de sesión reemplazados y todos sus controles no relacionados. Agrega permisos sin retirar asignaciones. Resto de triggers sin cambios. 43 tablas y 34 triggers después de aplicar.

Antes de migrar: respaldo externo amazon_market_post_sprint3_20261114_143604_115740c6.sql, SHA-256 903822230664d75f21bcd0ae0fc1b724d4fa537dd9ec22436a44e7df3ee1e000. Restaurado y comparado en am_s3_checkpoint_20261114_143604_115740c6.

Después: amazon_market_post_sprint3_20261114_150213_69ae7cc6.sql, SHA-256 663923a21fe857fc10e387e5466f88c27eb2a16ba9568a272d0faaee866f9972. Restaurado y comparado en am_s3_checkpoint_20261114_150213_69ae7cc6.

Ambos en C:/xampp/backups/Amazon-Market/Sprint3/Paso15. Informe de aplicación: amazon_market.admin12.json, VERIFIED con activated_at. Compara multiconjuntos SHA-256 de TODAS las filas previas en sus columnas originales y las definiciones de los 32 triggers no reemplazados. Conserva las sesiones previas como CON_TURNO y permite únicamente adiciones esperadas de permisos. La verificación posterior restauró las 43 tablas y los 34 triggers completos.

Se aplicó con mantenimiento HTTP503 y drenaje de conexiones, luego se sincronizó el código probado. Validaciones ready y active correctas: esquema, datos previos, .env, permisos, modalidad administrativa por servicio, Auth200, API sin sesión401 y archivos internos403. No se cargaron usuarios ni operaciones ficticias en la base principal.

## Reproducción y límites

Preparar una copia nueva desde respaldo con tests/sprint3/preparar_copia_admin_caja.php, aplicar el ejecutor de migración12 y ejecutar TEST_DB_NAME=<copia> C:/xampp/php/php.exe tests/sprint3/admin_caja_test.php. Este test rechaza la base principal. Para los escenarios de primera apertura de las suites antiguas se usó el respaldo previo al despliegue, migrado primero con 01..09 y luego 12. Los scripts 01..11 originales se conservan. El worktree convertido a CRLF no superaba sus hashes fijados: el paquete histórico se ejecutó desde el árbol principal con los bytes originales, sin alterar hashes ni omitir guardias.

No se implementaron actualizaciones en tiempo real ni cierre de sesiones ajenas. Los endpoints de programación permanecen administrativos y sus destinatarios siguen siendo vendedores. La nueva regla está vinculada a HU11–17 y HU19; HU18 mantiene su alcance. No se requiere otra acción SQL para utilizar la función: el administrador debe recargar la página.
