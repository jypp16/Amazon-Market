# Acuerdos y plan del backend del Sprint 3

Fecha de los acuerdos consolidados: 12 de noviembre de 2026.

Este archivo recoge las decisiones tomadas en la conversación y el orden de implementación del backend de Amazon Market. Es la referencia de trabajo para los acuerdos que amplían o cambian las HU 11 a 19. La actualización de los documentos Word queda para después y no es un requisito previo para iniciar la implementación.

Cuando un Word o el contrato frontend todavía describa una regla diferente de las acordadas aquí, deberá alinearse con este archivo. Una regla que no esté definida no debe darse por aprobada: se consulta al usuario cuando afecte al paso que se esté desarrollando.

La creación de este documento no ejecuta migraciones ni implementa el backend.

## 1. Alcance y conservación de datos

- Implementar turnos, programación semanal, sesiones de caja, movimientos, correcciones vinculadas, arqueos, cierres, autorizaciones de cierre, consultas y reportes.
- Trabajar sobre una sola caja física, utilizada mediante sesiones sucesivas.
- Conservar los datos existentes: usuarios, productos, existencias, ventas, compras, detalles, correlativos e historiales.
- No ejecutar limpiezas, reinicios de saldos ni procedimientos que eliminen operaciones existentes.
- Guardar todos los scripts SQL nuevos de este sprint en `database/tercerSprint`, con numeración e instrucciones de ejecución.
- Actualizar la estructura mediante migraciones incrementales, ensayadas primero sobre una copia restaurada de la base.
- Conservar el historial comercial anterior sin atribuirle aperturas o sesiones de caja que no quedaron registradas originalmente.
- Excluir ticket individual de cierre, devoluciones y redondeo comercial. Los importes conservan precisión de dos decimales.
- Mantener la consulta administrativa y la exportación de reportes de cierres a PDF y Excel. El usuario entregará sus diseños en el paso correspondiente.

## 2. Roles y responsabilidades

### Vendedor

- Abrir su sesión, seleccionar el turno que atenderá y confirmar el efectivo contado.
- Registrar ingresos y egresos de su sesión abierta.
- Identificar al administrador que entregó o recibió efectivo cuando el tipo de movimiento lo requiera. Identificarlo no equivale a solicitar sus credenciales.
- Registrar correcciones de movimientos propios mediante registros adicionales vinculados; no editar ni eliminar el original.
- Declarar y registrar un retiro omitido con su motivo y referencias.
- Realizar arqueos, registrar observaciones y cerrar su sesión.
- Consultar su sesión activa. La recuperación del resultado de un cierre propio ante un fallo no habilita una consulta histórica general.

### Administrador

- Gestionar los turnos de atención y la programación semanal de los vendedores.
- Autorizar exclusivamente los cierres anticipados que requieran excepción según la regla de quince minutos.
- Consultar sesiones actuales e históricas, sus movimientos y las autorizaciones registradas.
- Consultar y exportar los reportes de cierres.

El servidor valida el rol y los permisos en cada operación. También valida la propiedad de las sesiones y los movimientos. La interfaz no concede permisos por sí sola.

## 3. Caja física y sesiones

- Existe una sola caja física y no puede tener dos sesiones abiertas simultáneamente, aunque pertenezcan a vendedores diferentes.
- Cada sesión identifica al vendedor que realmente queda a cargo del efectivo y al turno que atenderá.
- Antes de abrir la sesión del siguiente turno, debe cerrarse la sesión anterior.
- Un vendedor puede atender varios turnos en un mismo día. Debe cerrar la sesión del primero y abrir otra para el siguiente.
- Existe un cierre definitivo por sesión; pueden existir varios cierres durante un día.
- Cada sesión conserva su propio saldo inicial, movimientos, arqueos y cierre.

## 4. Turnos y programación semanal

- Un vendedor puede tener varios turnos asignados en un mismo día, por ejemplo mañana y tarde.
- La programación permite seleccionar los días y los turnos correspondientes. Ya no se limita a un único turno fijo por vendedor.
- La primera programación establece una base que sigue vigente en las semanas siguientes mientras no se registre un cambio.
- Se permite duplicar una programación semanal existente, conservando sus días y turnos.
- Un cambio con alcance `Solo esta semana` afecta únicamente a esa semana; después vuelve a aplicarse la base vigente.
- Un cambio con alcance `Desde esta semana en adelante` modifica la programación desde la semana seleccionada y conserva las semanas anteriores.
- Los cambios de programación no alteran el historial efectivo de aperturas y cierres.

## 5. Apertura y suplencias

- Se permite abrir fuera del horario o sin programación, sin credenciales administrativas.
- Esa posibilidad no omite los controles de usuario autorizado, importe válido y caja sin otra sesión abierta.
- Al abrir, el vendedor selecciona un turno existente que corresponda a la atención que realizará, incluida una suplencia.
- La sesión conserva el turno y su horario de referencia desde la apertura. El cierre no determina nuevamente ese turno según la hora actual del reloj.
- La responsabilidad corresponde al vendedor que abrió, no al vendedor que originalmente estaba programado.
- Abrir una suplencia no modifica automáticamente la programación semanal.
- Se conservan por separado el horario previsto y las fechas y horas reales de apertura y cierre.

### Primera apertura

Si no existe un cierre anterior:

- El campo para ingresar el efectivo contado permanece disponible.
- No se muestran un importe de referencia anterior ni una diferencia de apertura.
- El importe contado y confirmado se convierte en el saldo inicial, admitiendo desde cero con hasta dos decimales.
- No se calcula una diferencia contra un cero supuesto como cierre anterior.

### Aperturas posteriores

- La referencia es el efectivo contado que quedó confirmado en el cierre anterior de la caja.
- El nuevo vendedor confirma el efectivo que realmente recibe.
- Su saldo inicial es el conteo confirmado, aunque difiera de la referencia.
- La diferencia de apertura se registra por separado y no genera un egreso ni modifica el cierre anterior.

Ejemplo: cierre anterior contado en S/ 200,00 y apertura contada en S/ 190,00. La nueva sesión inicia con S/ 190,00 y registra una diferencia de apertura de -S/ 10,00.

## 6. Movimientos y correcciones

### Ingresos y egresos actuales

- Solo se registran sobre la sesión propia y abierta del vendedor.
- Al consultar el estado de la sesión actual para movimientos (`GET /api/caja/sesion-actual`), cuando no exista una sesión propia abierta (caja cerrada o abierta por otro vendedor), `current_balance` y `balance` devuelven `null` porque el saldo no aplica (`is_open = false`, `is_owner = false`, `session_id = null`, sin datos financieros ajenos). Una sesión propia abierta con saldo real cero devuelve `0.00`.
- Se exigen importe positivo, hasta dos decimales y motivo con contenido.
- Las inyecciones identifican al administrador que entregó el efectivo; los retiros identifican al que lo recibió.
- Los egresos que afectan al efectivo actual no pueden superar el saldo disponible. Un egreso igual al saldo es válido y deja el saldo en cero.
- El movimiento y su efecto se confirman conjuntamente y una sola vez.
- No se solicitan credenciales administrativas para estos movimientos.

### Correcciones de movimientos propios

- No se edita ni elimina el movimiento original.
- Se agrega un registro de corrección vinculado al original, identificando el error y el motivo de la corrección.
- El servidor verifica que el original pertenezca al vendedor autenticado y determina el efecto correcto en el saldo.
- La corrección afecta al saldo por el ajuste necesario, no aplicando otra vez todo el importe original.
- Las correcciones sucesivas conservan sus relaciones y el historial completo.
- No se piden credenciales administrativas para corregir movimientos propios.
- Una sesión cerrada no admite nuevos movimientos ni correcciones con efecto sobre sus cifras confirmadas.
- Un faltante físico sin causa identificada no se transforma en una corrección o egreso para hacer coincidir el saldo.

Ejemplo: un ingreso de S/ 50,00 debió registrarse por S/ 30,00. El original se conserva y la corrección vinculada reduce el saldo en S/ 20,00.

### Retiro omitido del turno anterior

- Basta la declaración del vendedor; no se exige confirmación administrativa adicional ni credenciales del administrador.
- El vendedor declara que el retiro ocurrió mientras la sesión anterior estaba abierta e identifica monto, motivo, administrador que recibió el efectivo y turno de origen.
- El registro conserva la relación con el turno de origen y la sesión en la que se documentó posteriormente.
- Si la salida ya está reflejada en el efectivo contado al abrir, el registro tiene impacto cero en el saldo actual.
- La declaración debe dejar constancia de esa circunstancia para aplicar el impacto cero; el tipo de movimiento por sí solo no la demuestra.
- El cierre anterior conserva sus cifras originales.
- Si el vendedor no puede afirmar que hubo un retiro real, la diferencia permanece como faltante o sobrante en la apertura o el arqueo correspondiente, sin generar un egreso.

Ejemplo: el turno anterior esperaba S/ 500,00 y cerró contando S/ 200,00. El entrante abre con S/ 200,00 y declara un retiro omitido de S/ 300,00 ya reflejado en ese conteo. Se vincula el retiro al turno anterior y la sesión actual mantiene S/ 200,00.

## 7. Ventas, saldo y arqueo

- Cada nueva venta confirmada identifica la sesión de caja correspondiente.
- Las ventas confirmadas en efectivo aumentan el saldo; tarjeta y Yape/Plin se muestran separados y no aumentan el efectivo.
- No se registran nuevamente como ingresos manuales las ventas ya incorporadas desde su confirmación.
- El esperado se obtiene del saldo inicial real, más ventas en efectivo e ingresos que afectan la sesión, menos egresos que la afectan.
- El conteo del arqueo admite cero y hasta dos decimales.
- La diferencia es contado menos esperado. Si es distinta de cero, se exige observación con contenido.
- Cada arqueo se conserva. Una corrección de conteo requiere guardar otro arqueo, sin reemplazar el anterior.
- Las operaciones posteriores dejan desactualizado el arqueo para el cierre conforme al control de vigencia de la sesión.
- La interfaz debe advertir los cambios de conteo u observación sin guardar antes de cerrar y mostrar qué arqueo se utilizará.
- No se presenta como guardado un arqueo construido únicamente en el navegador: se utiliza la referencia confirmada por el servidor.

## 8. Cierre y autorización de excepción

- Solo se cierra la sesión propia, abierta y con un arqueo vigente que incluya las operaciones confirmadas.
- La hora de salida usada para evaluar el cierre es la del turno asociado a esa sesión al abrir.
- Se requieren credenciales del administrador únicamente cuando el cierre ocurre antes de los quince minutos previos a esa salida.
- Desde el instante situado quince minutos antes de la salida, incluido ese instante, el cierre no necesita credenciales administrativas.
- Después de la hora de salida tampoco se solicitan credenciales. No existe un límite posterior de treinta minutos para pedirlas.
- La hora de salida es una referencia, no provoca un cierre automático.
- La autorización anticipada exige correo, contraseña y motivo del administrador, validados en el servidor contra la BD y su condición de administrador activo.
- No se utilizan códigos de autorización ni se cambia la sesión del vendedor por la del administrador.
- La autorización corresponde a ese vendedor y a ese cierre; no elimina el requisito de arqueo vigente y se utiliza al confirmar la operación.
- Se conserva evidencia consultable de administrador, vendedor, operación, motivo, fecha y hora.
- Se registran el cierre real y las cifras confirmadas; después se bloquean nuevas ventas y movimientos sobre esa sesión.
- Ante reintento o pérdida de conexión se recupera el cierre confirmado, sin duplicarlo ni anunciar éxito antes de comprobarlo.
- Se muestra el resumen del cierre. No se implementa un ticket individual imprimible o descargable.

### Ejemplo de suplencia

Juan cubre el turno mañana que termina a las 12:00. Selecciona ese turno al abrir y queda como responsable de la sesión.

| Hora real de cierre | Credenciales administrativas |
| --- | --- |
| Antes de las 11:45 | Obligatorias |
| Desde las 11:45 hasta las 12:00 | No se solicitan |
| Después de las 12:00 | No se solicitan |

Aunque cierre después de que comience el turno tarde, la sesión sigue vinculada al turno mañana. Para atender el siguiente turno debe cerrar y abrir otra sesión.

## 9. Consulta y reportes

- El vendedor consulta su sesión activa y sus movimientos.
- El administrador consulta sesiones actuales e históricas mediante los filtros correspondientes y selecciona la sesión concreta cuando existen varias.
- El detalle muestra fecha, hora, tipo, motivo o referencia, importe, efecto y responsable, con orden cronológico y paginación.
- Los totales corresponden al conjunto completo consultado, no solo a la página visible.
- Una sesión sin movimientos conserva su saldo inicial; los totales de movimientos son cero.
- Una búsqueda sin resultados no se sustituye por información de otra fecha o sesión.
- Los reportes de cierres conservan separados los importes y las diferencias de apertura y cierre, así como los medios de pago.
- Las correcciones y los retiros omitidos conservan sus referencias sin reescribir los cierres anteriores.
- Los reportes y las exportaciones requieren acceso administrativo y son de solo lectura.
- PDF y Excel incluirán el conjunto filtrado y las cifras de la consulta. Su presentación se implementará cuando el usuario entregue los diseños en ese paso.
- Ante un fallo de exportación se conservan los filtros y se permite reintentar.

## 10. Integridad y acuerdos técnicos

- Reutilizar la arquitectura, autenticación, permisos, protección CSRF, servicios de ventas y control de solicitudes del proyecto cuando cubran la necesidad.
- Validar importes y realizar cálculos exactos con dos decimales. No aplicar ajustes comerciales por denominación de monedas.
- Registrar fechas y horas reales en el servidor y mostrar los horarios de la tienda en `America/Lima`.
- Confirmar de forma conjunta cada operación y sus efectos. Coordinar ventas, caja, stock y Kardex.
- Validar saldo y estado vigentes ante operaciones simultáneas y evitar registros duplicados por reintentos.
- Probar cada módulo antes de integrarlo y conservar evidencia de entradas, respuestas y saldos.
- Definir el contrato final de rutas, campos y errores durante el paso correspondiente. Los nombres actuales de los mocks no obligan a mantener un contrato incompatible con el backend existente.
- Consultar al usuario las decisiones funcionales que aparezcan y no estén resueltas en estos acuerdos. Los diseños de PDF y Excel y las metas de rendimiento aún no concretadas se tratarán en sus pasos respectivos.

## 11. Relación con las HU

| HU | Acuerdo que debe guiar su implementación |
| --- | --- |
| HU 11 | Una sola caja, apertura con conteo real, primera apertura sin referencia, apertura sin autorización y turno atendido seleccionado, incluida suplencia. |
| HU 12 | Arqueos conservados, diferencia y observación, control de vigencia y referencia persistida. |
| HU 13 | Un cierre definitivo por sesión, horario asociado desde la apertura, cierre real, resumen y recuperación sin ticket individual. |
| HU 14 | Credenciales únicamente para cierre anterior a los quince minutos previos a la salida; autorización registrada sin cambiar la sesión del vendedor. |
| HU 15 | Ingresos propios y correcciones adicionales vinculadas, sin editar originales ni pedir credenciales administrativas. |
| HU 16 | Egresos propios, correcciones vinculadas y retiro omitido sustentado en la declaración del vendedor con efecto condicionado a lo reflejado en apertura. |
| HU 17 | Consulta propia activa o administrativa histórica, selección de sesión, responsables y totales completos. |
| HU 18 | Varios turnos por día, base semanal vigente, duplicación y alcance temporal o permanente. |
| HU 19 | Reportes administrativos y exportaciones PDF/Excel según los diseños que entregará el usuario. |

La actualización formal de los Word queda pendiente para una etapa posterior. Este archivo no debe presentar como vigentes las restricciones de esos documentos que fueron sustituidas expresamente en la conversación.

## 12. Plan de acción actualizado

1. **Consolidar los acuerdos en este Markdown.** Usar este archivo como referencia del Sprint 3 y mantenerlo actualizado ante nuevas decisiones. Dejar la edición de los Word para después. Este paso queda cubierto con la creación del documento.
2. **Definir permisos por rol y operación.** Traducir las responsabilidades de vendedor y administrador en controles de las APIs, incluyendo propiedad de sesiones y movimientos.
3. **Inspeccionar la base y preparar su conservación.** Confirmar esquema y versión, registrar conteos y existencias, obtener respaldo externo y verificar su restauración en una base separada.
4. **Definir el contrato de las APIs.** Establecer rutas, campos, respuestas, errores, importes, fechas, paginación y recuperación de solicitudes, reutilizando la arquitectura existente.
5. **Preparar y ensayar migraciones incrementales.** Guardar todos los scripts SQL en `database/tercerSprint`, con orden e instrucciones. Probarlos sobre la copia restaurada y comprobar que conservan los datos anteriores.
6. **Implementar turnos y programación semanal.** Incorporar varios turnos por día, base vigente, duplicación y cambios semanales o permanentes, preservando el historial.
7. **Implementar la apertura y suplencias.** Registrar responsable real, turno atendido, horario de referencia, conteo y diferencia cuando exista referencia anterior; controlar una sola sesión abierta.
8. **Implementar movimientos y correcciones.** Registrar ingresos, egresos, retiros omitidos y correcciones propias vinculadas, conservando originales y evitando dobles efectos sobre el saldo.
9. **Integrar las ventas con caja.** Asociar nuevas ventas a la sesión, separar medios de pago y coordinar confirmación, saldo, stock y Kardex.
10. **Implementar el arqueo.** Calcular esperado y diferencia, exigir observación cuando corresponda y mantener historial y vigencia.
11. **Implementar cierre y autorización anticipada.** Aplicar la regla de quince minutos, confirmar cifras y estado conjuntamente y recuperar cierres ante reintentos.
12. **Implementar consultas y datos del reporte.** Aplicar permisos, filtros, selección de sesión, paginación, responsables y diferencias separadas.
13. **Conectar el frontend a las APIs reales.** Sustituir mocks por módulo, retirar credenciales de apertura y correcciones, y comprobar todos los estados de respuesta con ambos roles.
14. **Implementar PDF y Excel con los diseños entregados.** Revisar la presentación con el usuario en este paso, reutilizar las herramientas existentes y exportar el conjunto filtrado completo.
15. **Validar el recorrido completo y desplegar conservando los datos.** Probar roles, concurrencia, reintentos, suplencias, varios turnos y regresiones de ventas e inventario. Comparar datos antes y después de migrar y seguir un procedimiento de recuperación verificado.

Cada paso se verifica antes de darlo por terminado. Si aparece una duda funcional no resuelta, se consulta antes de implementar esa regla.
