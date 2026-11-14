# Paso 13: conexión del frontend a las APIs reales

Fecha de revisión y ejecución: 13/11/2026. Se revisó el commit de partida `43a0283` y se completaron sus pendientes sin modificar el backend, ejecutar migraciones sobre la base principal ni avanzar al Paso 14.

## Correcciones de esta revisión

| Hallazgo comprobado | Corrección | Respaldo |
| --- | --- | --- |
| `Api.delete()` descartaba las opciones que contenían la referencia de solicitud. Los wrappers POST/PUT también descartaban esas opciones. | Los tres wrappers conservan las cabeceras y opciones. CSRF se incorpora desde el token de la página. | EP-02..EP-06; HU 18; contrato de idempotencia. |
| Una segunda escritura al mismo endpoint cancelaba la primera en el navegador. Una respuesta antigua podía además borrar el controlador de una consulta nueva. | Solo se cancelan GET; cada finalización limpia únicamente su propio controlador. | Confirmación única y recuperación de solicitudes, acuerdos sección 10. |
| Las referencias de movimientos desaparecían al recargar. Sus identidades no quedaban separadas por vendedor/sesión. | Referencias persistidas por usuario, API, operación y sesión. Una confirmación libera su referencia; un fallo de red la conserva. | HU 15/16, EP-13..EP-16. |
| El retiro omitido solo consultaba una de las dos declaraciones y daba por cierta la otra. | Se preguntan ambos hechos expresamente y se envían los dos booleanos canónicos. | HU 16 y acuerdos sección 6; EP-14. |
| La referencia de arqueo se compartía entre sesiones y se conservaba un fallback que construía un arqueo local sin ID real. | Identidad por sesión; éxito visual únicamente con ID persistido positivo. Se reconoce `CASH_CHANGED`. | HU 12, EP-17..EP-19, acuerdos sección 7. |
| Cambiar conteo u observación después de guardar no advertía antes del cierre. Al recargar tampoco se reponía la observación del arqueo. | Se recupera la observación y se bloquea el intento de cierre con cambios pendientes, solicitando guardar un nuevo arqueo. | Acuerdos sección 7; HU 12/13. |
| Si el POST de cierre se confirmaba pero su respuesta se perdía, la pantalla usaba una referencia anterior para recuperarlo. | La recuperación lee la referencia de la sesión actual, guardada antes del envío. | HU 13, EP-20/EP-21, acuerdos sección 8. |
| El aviso de cierre anticipado esperaba campos de horario que EP-17 no devuelve y mostraba guiones. El reporte usaba la zona horaria del navegador. | Se muestra `scheduled_end` real y el margen de quince minutos; reporte con `America/Lima`. | HU 13/14/19, contrato `ScheduleResource` y acuerdos de fechas. |
| Se normalizaban estados sin saldo a cero. | Se conserva `null` cuando el saldo no aplica y se distingue del cero real. | Decisión confirmada EP-10 y acuerdos sección 6. |

No se añadieron nuevas reglas de negocio ni nuevas HU. La base de esta revisión es el contrato y los acuerdos vigentes de la conversación, que tienen cambios posteriores al Word original.

## Entorno real de navegador

- Chrome mediante Playwright CLI, aplicación PHP real en `http://127.0.0.1:8370`.
- Base exclusiva: `amazon_market_sprint3_dlfinal_p12_20261113_184529`, restaurada desde el respaldo verificado y migrada `01..09` antes de insertar fixtures. El nombre conserva el prefijo admitido por las herramientas existentes; esta ejecución corresponde al Paso 13.
- `DB_NAME`, `TEST_DB_NAME`, `BASE_URL`, sesiones PHP e INI privados del servidor de ensayo. No se editó `.env`.
- Administrador y vendedor nuevos de ensayo, en contextos de navegador distintos. Turnos auxiliares `P13 pasado` y `P13 tarde` para probar cierres posteriores a la salida y cierres anticipados.
- Directorio de evidencia temporal: `C:\Users\LOQ\AppData\Local\Temp\amazon_market_p13_20261113_184529`. Contiene preparación, logs y segmentos de navegador. Los segmentos son una ejecución guiada con estado, no una suite autónoma independiente.

## Resultados observados por interfaz y HTTP real

| Recorrido | Resultado observado |
| --- | --- |
| Apertura sin credenciales administrativas | `201 OPENED`, sesión real, turno seleccionado y conteo 100. |
| Ingreso y consulta | Ingreso 10 confirmado; arqueo esperado 110 y resumen de cierre contado 110. |
| Cierre posterior a la salida y recarga | `CLOSE_CONFIRMED`; el resumen vuelve a recuperarse después de recargar. |
| Programación del vendedor | Guardado `PUT /api/programaciones` 200; primera base con alcance permanente, reflejada en el listado. |
| Corrección de ingreso propio a cero | `CORRECTION_SAVED`, importe corregido 0; original conservado. |
| Retiro omitido | `MOVEMENT_REGISTERED`, efecto 0 y ambas declaraciones verdaderas en la respuesta. |
| Autorización anticipada | Contraseña incorrecta: 403. Contraseña válida: cierre 200, autorización requerida y evidencia administrativa. El vendedor conserva su sesión y no se guarda la contraseña en sessionStorage. |
| Pérdida de respuesta de ingreso | Se ejecutó `route.fetch()` contra el backend y después se abortó la entrega al navegador. Primera confirmación 201; tras recargar y repetir: 200 `OPERATION_ALREADY_CONFIRMED` con la misma referencia. |
| Gestión de turnos | Creación 201; eliminación del turno sin asignaciones 200, tras confirmar el diálogo. |
| Consulta administrativa con varias sesiones | Cuatro sesiones seleccionables del mismo vendedor en la fecha; detalle visible de la sesión elegida. |
| Reporte de cierres | Tres cierres en el momento de consulta; detalle con contado 100 y datos reales. |
| Venta desde POS | Venta confirmada; total 33.90, efectivo esperado de 107 a 140.90; arqueo anterior visible como `Desactualizado`. El ticket de venta existente se conserva; no es un ticket de cierre. |
| Cambios sin guardar | Modificar la observación impide cerrar y muestra la advertencia antes de enviar el cierre. |
| Pérdida de respuesta del cierre, después de corregir | Confirmación real 200, respuesta abortada, resumen recuperado por EP-21 y conservado tras recargar. |
| Roles | API de reportes para vendedor: 403; estado de apertura para administrador: 403. Las páginas MVC redirigen a Home y por eso su respuesta final es 200; ese código no se usó como prueba del permiso API. |
| Consulta del vendedor después de cerrar | Estado visible sin sesión propia activa, sin datos de otro vendedor. |

La pérdida de respuesta se provocó después de ejecutar la operación real; no se reemplazó por un JSON simulado. Se detectó primero el fallo de recuperación de cierre y se repitió en una sesión nueva después de corregirlo.

## Pruebas repetibles y alcance de la evidencia

Desde la raíz:

```powershell
& 'C:\Program Files\nodejs\node.exe' tests/sprint3/paso13_cliente_test.js
& 'C:\Program Files\nodejs\node.exe' tests/sprint3/paso13_adaptadores_test.js
```

- Cliente compartido: cabeceras DELETE/CSRF, independencia de escrituras, persistencia e identidad de referencias por usuario, liberación tras confirmar y cancelación de consultas GET: PASS.
- Adaptadores: 14 comprobaciones de null/cero, saldo insuficiente, replay, referencias por sesión, arqueo desactualizado y acceso denegado: PASS.
- Estas pruebas unitarias usan dobles controlados. La prueba de integración está respaldada separadamente por la ejecución real descrita arriba.
- Se comprobaron sintaxis de JavaScript, PHP de las vistas modificadas y `git diff --check`.
- Los tests antiguos `caja_*_test.js`, `caja_workflows_mock_test.js` y similares describen los mocks anteriores y no se usaron como evidencia de conexión real. Los mocks se conservan como archivos históricos, fuera de la carga de las pantallas conectadas.
- No se volvió a ejecutar toda la batería backend de los Pasos 6..12 en esta revisión de frontend. Sus cifras históricas no se presentan como pruebas nuevas.

## Conservación y siguiente paso

Comparación de todas las filas de las 32 tablas originales frente a la copia de referencia `amazon_market_sprint3_verify_20261112_012430`: **32/32 idénticas**, cero tablas con diferencias. Conteos originales: ventas 250, compras 80, productos 108, stock 2497, kardex 470.

El Paso 13 queda completado para la conexión funcional revisada. Las exportaciones de cierres PDF/Excel corresponden al Paso 14 y requieren los diseños pendientes del usuario. El ticket individual de cierre se mantiene excluido. La validación final de despliegue y la batería integral del Sprint corresponden al Paso 15.
