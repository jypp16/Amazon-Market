# Validación final del Paso 14 — Sprint 3

**Estado: PASO 14 COMPLETO.** Implementación y verificación final realizadas el 13/11/2026 (America/Lima). Se puede continuar con el Paso 15; no se ha desplegado ni se han ejecutado migraciones sobre la base principal.

## Alcance y respaldo

HU 19 y EP-24C/D de CONTRATO_API_SPRINT_3.md; acuerdos de consultas y reportes. Las referencias de correcciones y retiros omitidos conservan las reglas de HU 15/16. Diseños originales, sin modificaciones:

- .docs/reportes3sprint/Reporte_cierres_de_caja_ejemplo.pdf
- .docs/reportes3sprint/Reporte_cierres_de_caja_ejemplo.xlsx

No se añadieron nuevas HU ni migraciones. No hay ticket de cierre, devolución, redondeo ni gestión de varias cajas físicas.

## Implementación terminada

- GET /api/reportes/exportar-pdf?tipo=cierres-caja y GET /api/reportes/exportar-excel?tipo=cierres-caja.
- Administrador activo con asignación efectiva de **ambos** permisos: reportes.cierres_caja y reportes.exportar. El nuevo método RBACMiddleware::tienePermisoAsignado permite comprobarlos sin el bypass administrativo; se aplica solo a estas exportaciones y sus botones. El comportamiento general de RBAC se conserva. reportes.ver no es un requisito adicional.
- Archivos completos antes de responder; MIME, attachment, longitud, no-store y nosniff correctos. Los fallos devuelven JSON y no exponen SQL.
- El cliente valida la firma PDF y el contenedor OpenXML. La variable inexistente expectedType quedó corregida.
- PDF A4 vertical con encabezado, tarjetas, medios de pago, desglose por cierre, movimientos y vínculos, totales y paginación. Se conservan textos largos y palabras sin espacios. Los totales y la interpretación se mantienen juntos. El signo menos usa un carácter compatible con la fuente.
- Excel con Resumen, Cierres (18 columnas) y Movimientos (11 columnas), valores monetarios numéricos, dos decimales incluso en totales, filtros, paneles B2/A2, impresión A4 horizontal y ancho ajustado a una página. Incluye Total ventas y roles Entregó/Recibió.
- Las cifras exportadas son valores confirmados del backend. No se copiaron fórmulas de ejemplo limitadas a las filas 4/1000: las correcciones y retiros omitidos no se suman dos veces. Los totales se refieren al conjunto congelado exportado.
- Se exportan todas las filas del snapshot, independientemente de la página visible. Sin snapshot se captura una consulta nueva consistente. El detalle usa session_id para exportar un solo cierre.
- Cada nuevo snapshot congela también movimientos y linked_omitted_withdrawals. El vínculo informativo aparece en el cierre de origen sin cambiar sus importes. Los snapshots antiguos sin detalle suficiente responden SNAPSHOT_NOT_FOUND y piden una nueva consulta; no se mezclan con datos actuales ni se eliminan.
- Filtros aplicados, controles ocupados, errores, reintento y actualización explícita tras vencimiento conectados en listado y detalle.

## Resultado final de pruebas

Todas las filas siguientes corresponden a ejecuciones de esta revisión y terminaron con **salida 0**.

| Batería | Resultado |
| --- | --- |
| paso14_exportaciones_test.php | **88 PASS / 0 FAIL** |
| paso14_generadores_test.php | **15 PASS / 0 FAIL** |
| paso14_descarga_test.js | PDF válido y rechazo de firma inválida: PASS |
| paso13_cliente_test.js | PASS, incluido reintento, usuario, cabeceras y carreras GET |
| paso13_adaptadores_test.js | **14 comprobaciones PASS** |
| paso12_consultas_reportes_test.php | **190/190** |
| paso11_cierre_autorizacion_test.php | **160/160**, incluidas 53 comprobaciones de concurrencia |
| paso10_arqueos_test.php | **154/154** |
| paso9_ventas_caja_test.php | **107/107** |
| paso8_movimientos_correcciones_test.php | **162/162** |
| paso7_apertura_caja_test.php | **214/214** |
| paso6_turnos_programacion_test.php + HU 18 | **116/116**, incluido runner oficial HU 18 |
| Sprint 2: fase4_ventas_kardex_test.php | **14/14** |
| Sprint 2: fase8_pdf_test.php | **21/21** |
| Sprint 2: regresion_http_test.php | **22/22** |
| PHP lint, JavaScript y git diff --check | Sin errores |

Los detalles de resultados están en RESULTADOS_PASO_14.json. Logs completos de las ejecuciones, incluidos intentos fallidos antes de corregir el entorno: C:/Users/LOQ/AppData/Local/Temp/am14final_1794620538378.

### Cobertura HTTP y financiera

La batería de aceptación se ejecuta contra PHP/MariaDB reales. Usa las fixtures del Paso 12 y añade **1.005 ingresos por API**, corrección a cero y corrección sucesiva, inyección, retiro y once cierres adicionales. No introduce sesiones ni movimientos financieros artificiales mediante SQL.

Verifica:

- Conjunto con más de una página, segunda página y exportación de todos los cierres y más de 1.000 movimientos.
- Reconciliación del XLSX con totales API: esperado, contado, diferencias de cierre y apertura, efectivo, tarjeta, Yape/Plin, ingresos y egresos.
- Importes, texto largo y contenido que comienza con fórmula conservado como texto.
- Anónimo, vendedor, administrador inactivo, snapshot ajeno/inexistente/vencido, filtros incompatibles, fechas imposibles/rango invertido, snapshot no entero y alias contradictorios.
- Retirada independiente de cada permiso obligatorio: 403 y botón oculto. Retirada de reportes.ver: descarga 200 y botón disponible.
- Sesión individual cerrada, rechazo de abierta y conjunto vacío exportable sin datos de otro período.
- Snapshot inmutable tras registrar un retiro omitido posterior; una captura nueva incorpora la referencia sin alterar el cierre original.
- Compatibilidad con snapshots antiguos creada mediante nuevas fixtures: no se altera ningún ítem congelado ni se deshabilitan sus triggers.
- Fallo real controlado en la renovación del snapshot mediante un trigger exclusivo de la copia de ensayo: JSON PDF_GENERATION_FAILED/EXCEL_GENERATION_FAILED sin trazas; al retirar ese trigger, reintento exitoso en ambos formatos.
- Vencimiento lógico conserva el registro físicamente.

### Navegador y revisión de archivos

Chrome autenticado contra http://127.0.0.1:8396, conectado exclusivamente a amazon_market_sprint3_final14_exp_1794620822194:

- Descarga PDF/XLSX del listado y del detalle, guardadas realmente en disco.
- Cambiar el formulario sin consultar no altera los filtros de exportación aplicados.
- Error JSON simulado una sola vez para comprobar la reacción de la interfaz; el reintento descarga un XLSX real, conservando filtros. Este caso se distingue del fallo real del servidor probado por la batería PHP.
- Snapshot vencido realmente en la copia: aviso, filtros conservados y botones bloqueados hasta pulsar Consultar; nueva captura y descarga exitosas.

El PDF completo de carga contiene **72 páginas**, todas renderizadas y revisadas en un montaje; no hay páginas vacías. Se verificó por extracción el conjunto completo de **1.004 motivos numerados**, además del motivo inicial largo, correcciones y la diferencia no aplicable. Los totales están en la última página. Se revisaron también el reporte vacío y el individual.

El XLSX descargado se abrió con openpyxl: tres hojas, 14 cierres, 1.017 movimientos, paneles, orientación horizontal y formato monetario. Sus importes se reconciliaron con la consulta en la batería PHP. Abrir por código y revisar OpenXML no se presenta como una sesión interactiva de Microsoft Excel.

Artefactos finales de aceptación: C:/Users/LOQ/AppData/Local/Temp/am14final_1794620538378/p14_acceptance_9399ee8f7a10. Evidencia de Chrome y PDF final: C:/Users/LOQ/AppData/Local/Temp/am14final_1794620538378.

## Entorno y repetición

Cada regresión se ejecutó sobre una copia nueva del respaldo previo al Sprint 3 con SHA-256:

7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D

Migraciones oficiales 01..09 con salida 0. Bases usadas:

- 11: amazon_market_sprint3_final14_p11_1794620641544
- 10: amazon_market_sprint3_final14_p10_1794620808236
- 9: amazon_market_sprint3_final14_p9_1794620822516
- 8: amazon_market_sprint3_final14_p8_1794620838625
- 7: amazon_market_sprint3_final14_p7_1794620874283
- 7: amazon_market_sprint3_final14_r7_1794620960923
- 6: amazon_market_sprint3_final14_r6_1794620978576
- 11: amazon_market_sprint3_final14_r11_1794621006530
- 12/14: amazon_market_sprint3_final14_complete_1794621341433
- Sprint2: amazon_market_sprint3_final14_s2retry_1794620961255

La regresión HU 18 usa una copia adicional con el prefijo obligatorio amazon_market_sprint3_migration_test_. No se reutilizaron bases de auditorías anteriores.

Para repetir la aceptación, preparar una copia NUEVA migrada 01..09 con nombre amazon_market_sprint3_final14_<etiqueta>_<timestamp>, exportar TEST_DB_NAME/DB_NAME a esa copia y usar un PHPRC privado con session.save_path y sys_temp_dir escribibles. Después, desde la raíz:

~~~powershell
& C:\xampp\php\php.exe tests/sprint3/paso12_consultas_reportes_test.php
& C:\xampp\php\php.exe tests/sprint3/paso14_exportaciones_test.php
& C:\xampp\php\php.exe tests/sprint3/paso14_generadores_test.php
& 'C:\Program Files\nodejs\node.exe' tests/sprint3/paso14_descarga_test.js
~~~

El Paso 14 de aceptación exige que las fixtures del Paso 12 provengan de esa misma ejecución; rechaza nombres de base ajenos al patrón de ensayo. No se debe repetir sobre una base utilizada con fixtures acumuladas. Los scripts guardan su evidencia y terminan sus servidores en finally.

### Correcciones del entorno de prueba

Los primeros intentos detectaron problemas del lanzador, no se contaron como aprobados. Se corrigieron:

- COMSPEC y rutas de Windows en el entorno privado.
- Lanzamiento directo por arreglo de argumentos en Pasos 6, 11 y 12, evitando el cmd intermedio y servidores huérfanos.
- Propagación de PHPRC a servidor y workers de Pasos 6/7. Antes se usaba C:\xampp\tmp y los warnings impedían leer el JSON de concurrencia.
- Directorio privado compartido de sesiones para las suites Sprint 2 y su servidor.

Después se repitieron las suites afectadas sobre copias nuevas y todas terminaron con salida 0. No se cambiaron expectativas para ocultar fallos.

## Conservación y cierre

- Comparación de filas de las 32 tablas originales contra la copia de referencia: **32/32 idénticas, cero diferencias**, salida 0.
- Ventas 250, compras 80, productos 108, stock 2497 y kardex 470.
- El hash del .env real es idéntico al capturado antes de esta ejecución.
- Diseños y respaldo originales intactos. Sin commits ni nuevas migraciones.
- Se cerraron el navegador y los servidores propios de ensayo; las bases y evidencias de prueba se conservan.

**No quedan pendientes del Paso 14.** El Paso 15 corresponde a la validación final de entrega y despliegue conservando datos; este cierre no autoriza ni anuncia un despliegue.
