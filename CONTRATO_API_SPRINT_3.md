# Contrato Definitivo de las APIs — Sprint 3

**Proyecto:** Amazon Market (`C:\xampp\htdocs\Amazon-Market`)  
**Rama:** `feature/caja` (commit `c29be5a39799379e1af12d2d49ed7128bab25e1c`)  
**Fecha:** 12 de noviembre de 2026  
**Etapa del plan:** Paso 4 — Definir el contrato definitivo de las APIs  

---

## 1. Alcance y Fuentes

Este documento establece el **contrato definitivo de comunicación entre el frontend y el backend** para todas las operaciones del Sprint 3 (HU 11 a HU 19: catálogo de turnos, programación semanal, apertura de la única caja física, suplencias, ingresos, egresos, retiros omitidos, correcciones vinculadas, arqueos, cierres, autorizaciones de cierre anticipado, consultas de sesiones, reportes de cierres, exportaciones PDF/Excel e integración transversal con Ventas POS).

### 1.1. Orden de prioridad normativa
1. **Acuerdos funcionales aprobados:** [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
2. **Matriz de roles, permisos y propiedad:** [MATRIZ_PERMISOS_SPRINT_3.md](MATRIZ_PERMISOS_SPRINT_3.md).
3. **Hechos técnicos verificados en la base real (`amazon_market`, MariaDB 10.4.32):** [INSPECCION_Y_RESPALDO_BD_SPRINT_3.md](INSPECCION_Y_RESPALDO_BD_SPRINT_3.md).
4. **Arquitectura e implementación existente del backend:** [index.php](index.php), [Libraries/Core/Load.php](Libraries/Core/Load.php), [Libraries/Core/ApiController.php](Libraries/Core/ApiController.php), [Libraries/Core/InventarioException.php](Libraries/Core/InventarioException.php), [Libraries/Middleware/AuthMiddleware.php](Libraries/Middleware/AuthMiddleware.php), [Libraries/Middleware/RBACMiddleware.php](Libraries/Middleware/RBACMiddleware.php), [Services/ControlSolicitudService.php](Services/ControlSolicitudService.php), [Services/AuthService.php](Services/AuthService.php), [Services/VentaService.php](Services/VentaService.php), [Controllers/API/VentaApiController.php](Controllers/API/VentaApiController.php) y [Controllers/API/ReporteApiController.php](Controllers/API/ReporteApiController.php).
5. **Consumidores frontend actuales (a adaptar en el Paso 13):** [CONTRATO_FRONTEND.md](CONTRATO_FRONTEND.md), [Assets/js/api.js](Assets/js/api.js), [Assets/js/cajaService.js](Assets/js/cajaService.js), [Assets/js/cajaMovimientosService.js](Assets/js/cajaMovimientosService.js), [Assets/js/cajaArqueoService.js](Assets/js/cajaArqueoService.js), [Assets/js/cajaConsultaService.js](Assets/js/cajaConsultaService.js) y [Assets/js/usuarios-horarios-mock.js](Assets/js/usuarios-horarios-mock.js).

### 1.2. Distinción de estados de implementación en este documento
- **Implementado y verificado en código:** El enrutador base en [index.php](index.php), `ApiController`, `AuthMiddleware`, `CSRFMiddleware`, `ControlSolicitudService`, `VentaService` (Sprints 1 y 2) y `ReporteApiController` (Kardex y Variaciones).
- **Contrato nuevo a implementar en los Pasos 6 a 12:** Los controladores `TurnoApiController`, `ProgramacionApiController`, `CajaApiController`, las rutas de cierres en `ReporteApiController` y la asociación de sesión de caja en `VentaService::procesarVenta()`.
- **Adaptación técnica futura:** Los ajustes en `index.php` (enrutamiento de subrutas), `RBACMiddleware` (permisos del Sprint 3), `ControlSolicitudService` (exclusión de `admin_password`), `Config/Config.php` / `Conexion.php` (zona horaria `America/Lima`), las migraciones SQL en `database/tercerSprint` (Paso 5), la conexión de adaptadores JS (Paso 13) y el diseño visual de PDF/Excel (Paso 14).
- **Exclusiones expresas:** No se implementa ticket individual imprimible o descargable de cierre, ni devoluciones, ni redondeo comercial.

---

## 2. Convenciones

### 2.1. Enrutamiento real (`index.php` y `Libraries/Core/Load.php`) y adaptación requerida

1. **Comportamiento actual verificado en código:**
   - En [index.php](index.php), cuando `$arrUrl[0] === 'api'`, se ejecuta `array_shift($arrUrl)`. El segmento `$arrUrl[0]` identifica el recurso (`$resource`), y el verbo HTTP (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`) selecciona el método público del controlador (`get($params)`, `post($params)`, `put($params)`, `patch($params)`, `delete($params)`).
   - Actualmente la línea 45 de [index.php](index.php) asigna:
     ```php
     $params = $arrUrl[2] ?? $arrUrl[1] ?? '';
     ```
     Con esa expresión, una URL de un subsegmento tras el recurso (`/api/usuarios/4` $\rightarrow$ `$arrUrl = ['usuarios', '4']`) entrega `$params = '4'`, pero una URL con dos subsegmentos (`/api/caja/movimientos/corregibles` $\rightarrow$ `$arrUrl = ['caja', 'movimientos', 'corregibles']`) descarta `'movimientos'` y entrega solo `'corregibles'`.
2. **Adaptación técnica requerida en el Paso 6:**
   - Sustituir la línea 45 de [index.php](index.php) por:
     ```php
     $params = implode('/', array_filter(array_slice($arrUrl, 1), static fn($seg) => $seg !== ''));
     ```
   - **Evidencia de compatibilidad y pruebas de regresión requeridas:**
     - Para rutas sin subsegmentos (`/api/ventas` $\rightarrow$ `$arrUrl = ['ventas']`): `array_slice($arrUrl, 1)` es `[]` $\rightarrow$ `$params = ''` (idéntico al actual).
     - Para rutas con 1 subsegmento (`/api/usuarios/4`, `/api/inventario/bajas`, `/api/reportes/kardex`): `array_slice($arrUrl, 1)` tiene 1 elemento $\rightarrow$ `$params = '4'`, `'bajas'`, `'kardex'` (idéntico al actual).
     - Para rutas nuevas con 2 subsegmentos (`/api/caja/movimientos/corregibles`, `/api/caja/consulta/sesiones`, `/api/reportes/cierres-caja/15`): `$params` recibe `'movimientos/corregibles'`, `'consulta/sesiones'`, `'cierres-caja/15'`.
     - *Comprobación de regresión obligatoria en el Paso 6:* Verificar que ninguna llamada existente en `Assets/js/*.js` invoque `/api/{recurso}/{sub1}/{sub2}` esperando que `sub1` se descarte (la equivalencia de estos ejemplos no sustituye una prueba de regresión HTTP de todos los consumidores existentes).
   - Registrar en `$apiMap` de [index.php](index.php):
     - `'turnos' => 'TurnoApiController'`
     - `'programaciones' => 'ProgramacionApiController'`
     - `'caja' => 'CajaApiController'`

### 2.2. Envoltorio JSON del servidor frente al wrapper de `Assets/js/api.js`

#### A. Respuesta HTTP cruda emitida por el servidor (`ApiController::sendJsonResponse`)
- **Éxito (`200 OK` o `201 Created`):**
```json
{
  "status": true,
  "code": "CODIGO_ESTABLE",
  "idempotent_replay": false,
  "message": "Mensaje descriptivo en español.",
  "data": {}
}
```
  - En operaciones de escritura (`POST`, `PUT`, `DELETE`) protegidas por `ControlSolicitudService`:
    - **Primera confirmación (`idempotent_replay: false`):** Devuelve el código HTTP de éxito del endpoint (`201 Created` o `200 OK`) y `code` específico de la operación (`SHIFT_CREATED`, `SHIFT_UPDATED`, `SHIFT_DELETED`, `SCHEDULE_SAVED`, `OPENED`, `MOVEMENT_REGISTERED`, `CORRECTION_SAVED`, `ARQUEO_SAVED`, `CLOSE_CONFIRMED`, `SALE_CREATED`). Además, `data.result_code` conserva siempre ese mismo código específico.
    - **Reintento idempotente (`idempotent_replay: true`):** Conforme a la implementación real de [Services/ControlSolicitudService.php](Services/ControlSolicitudService.php), devuelve siempre **HTTP `200 OK`**, `"code": "OPERATION_ALREADY_CONFIRMED"`, `"idempotent_replay": true` y en `"data"` el objeto `data` original confirmado (incluyendo `data.result_code`).
- **Error (`400`, `401`, `403`, `404`, `405`, `409`, `415`, `422`, `500`):**
```json
{
  "status": false,
  "code": "CODIGO_ERROR_ESTABLE",
  "message": "Mensaje claro para el usuario.",
  "errors": {
    "campo": "Detalle del error de validación en este campo."
  },
  "data": null
}
```
  - `errors` se incluye cuando existen rechazos por campo (`422`); en errores generales sin desglose por campo se envía `{}`.
  - `data` es `null` salvo cuando el cliente requiere contexto del servidor para actualizar la interfaz (`current_balance` en `INSUFFICIENT_BALANCE`, `expected` y `version` en `CASH_CHANGED`, `schedule` en `AUTHORIZATION_REQUIRED`, `occupied_by` en `CASH_ALREADY_OPEN`).

#### B. Envoltorio del cliente en `Assets/js/api.js`
- `Api.request()` ([Assets/js/api.js](Assets/js/api.js)) envuelve la respuesta HTTP del servidor en:
  `{ ok: response.ok, status: response.status, data: <JSON_DEL_SERVIDOR> }`.
- Todos los ejemplos de esta especificación muestran el **JSON crudo devuelto por el servidor**.

### 2.3. Nombres canónicos (`snake_case`), identificadores y política estricta de alias

1. **Regla general de nombres:**
   - Las claves nuevas de caja, parámetros y campos canónicos utilizan **`snake_case`**; EP-25 conserva la respuesta heredada de ventas más su extensión documentada (p. ej. `usuario_id`, `semana_inicio`, `turno_id`, `vendedores_asignados`, `tiene_programacion_base`, `tiene_semana_anterior`, `referencia_solicitud`, `session_id`).
   - Quedan eliminados del contrato definitivo los nombres `camelCase`, `PascalCase` o con espacios procedentes de los mocks (`usuarioId`, `semanaInicio`, `turnoId`, `vendedoresAsignados`, `"tiene ProgramacionBase"`, `ScheduledEnd`).
2. **Identificadores de recursos:**
   - Todos los identificadores persistidos (`id`, `session_id`, `shift_id`, `turno_id`, `usuario_id`, `seller_id`, `administrator_id`, `movement_id`, `original_movement_id`, `correction_id`, `arqueo_id`, `origin_session_id`, `id_venta`) son **enteros positivos (`INT`, $\ge 1$)** asignados por la base de datos.
   - El servidor rechaza identificadores locales ficticios generados en el navegador (`"session-123"`, `"movement-1"`, `"arqueo-local"`).
3. **Política de alias de entrada por compatibilidad con adaptadores existentes:**
   - Obligatorio en las tablas significa obligatorio en la entrada normalizada; la referencia puede proceder de cabecera o cuerpo según 8.1. No se exige duplicarla.
   - Las respuestas del servidor **solo** emiten los nombres canónicos en `snake_case`.
   - En la entrada (`query` o `body`), únicamente se admiten los siguientes alias documentados:

| Nombre canónico (`snake_case`) | Alias de entrada admitido | Endpoints donde se admite |
| :--- | :--- | :--- |
| `referencia_solicitud` | `request_id` (y cabecera `X-Request-Reference`) | POST/PUT con idempotencia y GET EP-21; DELETE EP-04 solo cabecera |
| `usuario_id` | `usuarioId` | `EP-05`, `EP-06` |
| `semana_inicio` | `semanaInicio` | `EP-05`, `EP-06` |
| `turno_id` | `turnoId` | `EP-06` (`turnos[].turno_id`) |
| `session_id` | `sesion_id` | `EP-15`, `EP-16`, `EP-18`, `EP-21`, `EP-24C`, `EP-24D` |
| `origin_session_id` | `origin_turn_id` | `EP-14` (`omitted_withdrawal`) |
| `confirmed_reflected_in_opening` | `confirm_previous_open` | `EP-14` (`omitted_withdrawal`) |
| `page_size` | `limit` | `EP-12` (1..20), `EP-22`, `EP-23C`, `EP-24A`, `EP-24B` (1..100) |
| valor de `type`: `manual_income` | valor `manual` | EP-13; normalizar antes de hash |
| `turno_id` | `turno` | `EP-24A`, `EP-24C`, `EP-24D` |
| `vendedor_id` | `vendedor` | `EP-24A`, `EP-24C`, `EP-24D` |

   - **Regla determinista cuando llegan simultáneamente el campo canónico y su alias:**
     1. El servidor normaliza la entrada **antes** de validar la lógica de negocio y **antes** de calcular `ControlSolicitudService::calcularHashPayload()`.
     2. Si llegan ambos (p. ej. `usuario_id` y `usuarioId`, o `referencia_solicitud` y `request_id`, o cabecera `X-Request-Reference` y cuerpo `referencia_solicitud`) y sus valores normalizados son **idénticos**, se conserva únicamente la clave canónica y se descarta el alias.
     3. Si llegan ambos y sus valores son **distintos**, el servidor rechaza inmediatamente la petición con **`422 AMBIGUOUS_ALIAS_VALUES`** indicando el campo en conflicto en `errors`, sin ejecutar la operación.

Los campos no documentados de nuevas escrituras se rechazan con `422 VALIDATION_ERROR`; el vendedor no puede inyectar propietario, saldos, impacto ni fecha. En apertura/movimientos/correcciones, campos de credenciales administrativas no admitidos se rechazan y nunca se registran. La compatibilidad de campos de ventas existentes se conserva conforme a EP-25.

### 2.4. Importes monetarios, precisión exacta y capacidad técnica

1. **Formato en solicitudes y respuestas:**
   - **En solicitudes (`POST`/`PUT`):** Se aceptan números JSON finitos (`190`, `190.5`, `190.50`) o cadenas numéricas con punto decimal (`"190.50"`), con un máximo de 2 decimales (`^\d+(\.\d{1,2})?$`). Se rechazan valores negativos en entradas de usuario, `NaN`, `Infinity`, comas, símbolos monetarios (`"S/"`) o más de 2 decimales con `422 INVALID_AMOUNT`.
   - **En respuestas JSON:** Todos los importes monetarios se devuelven como **números JSON con un máximo de 2 decimales obtenidos de resultados calculados en céntimos exactos** (`190.0`, `190.55`, `-10.0`, `0.0`). El formateo visual (`"S/ 190,00"`) corresponde exclusivamente al frontend.
2. **Cálculo exacto sin redondeo comercial:**
   - Todos los cálculos de caja y ventas en el servidor se realizan en **céntimos enteros (`INT` de 64 bits)** (parseo decimal validado a céntimos, sin multiplicar primero un float). La integración conservará la aritmética en céntimos de ventas, revisando su conversión actual; esta exigencia nueva no afirma que el conversor heredado ya sea exacto para toda entrada.
   - **Exclusión de redondeo comercial:** No se aplica redondeo a `0.05` ni `0.10`. Una diferencia de `0.01` (`1` céntimo) entre contado y esperado es una diferencia distinta de cero y exige observación en el arqueo.
3. **Capacidad técnica vs. límites por operación y acumulados:**
   - **Capacidad técnica de persistencia (`DECIMAL(10,2)`):** Las nuevas columnas monetarias se especifican como `DECIMAL(10,2)`; las tablas existentes conservan sus definiciones reales y se inspeccionarán antes de migrar. `DECIMAL(10,2)` tiene un máximo técnico exacto es **`99999999.99`** (`99,999,999.99`, 8 dígitos enteros y 2 decimales). **No se impone ningún tope de negocio arbitrario menor (como `999,999.99`)** dado que no fue acordado como restricción funcional.
   - **Límites por operación de entrada:**
     - Efectivo contado en apertura (`cash_counted` en `EP-09`), efectivo contado en arqueo (`counted` en `EP-19`) e importe corregido (`corrected_amount` en `EP-16`): **$\ge 0.00$** y $\le 99999999.99$. Si es $< 0.00$ o tiene $> 2$ decimales $\rightarrow$ `422 INVALID_AMOUNT`. Si supera `99999999.99` $\rightarrow$ `422 AMOUNT_OUT_OF_RANGE`.
     - Importe de ingreso manual, inyección de administrador, salida de caja, retiro de administrador y retiro omitido (`amount` en `EP-13` y `EP-14`): **$\ge 0.01$** y $\le 99999999.99$. Si es $\le 0.00$ o tiene $> 2$ decimales $\rightarrow$ `422 INVALID_AMOUNT`. Si supera `99999999.99` $\rightarrow$ `422 AMOUNT_OUT_OF_RANGE`.
   - **Límites de acumulados y resultados calculados:**
     - En egresos con efecto sobre el saldo actual (`cash_out`, `admin_withdrawal`) y en correcciones que reducen el saldo: el saldo resultante de efectivo esperado debe cumplir **`current_balance >= 0.00`**. Un egreso exactamente igual al saldo disponible (`amount == current_balance`) es válido y deja `current_balance = 0.00`; si lo supera por al menos `0.01`, se rechaza con `409 INSUFFICIENT_BALANCE`.
     - Si una suma acumulada en `sesion_caja` superara el límite técnico de `DECIMAL(10,2)` (`99999999.99`), la transacción se aborta con `409 BALANCE_OVERFLOW`.
     - Los valores negativos solo existen en campos calculados por el servidor: `opening_difference`, `difference` (arqueo), `closing_difference`, `impact` de egresos y `balance_effect` reductor en correcciones.

### 2.5. Fechas calendario, horas, timestamps y zona horaria (`America/Lima`)

1. **Zona horaria oficial:** `America/Lima` (`UTC-05:00` permanente, sin horario de verano).
2. **Formatos canónicos obligatorios:**
   - **Fechas calendario (`date`, `semana_inicio`, `desde`, `hasta`):** `YYYY-MM-DD` (ej. `"2026-11-12"`). Se valida tanto la expresión regular `^\d{4}-\d{2}-\d{2}$` como su existencia real mediante `checkdate()`.
   - **Validación única de `semana_inicio`:** En todos los endpoints de programación semanal (`EP-05`, `EP-06`), `semana_inicio` **debe ser obligatoriamente un lunes (`(int)$dt->format('N') === 1`)**. El servidor **no normaliza silenciosamente** fechas de martes a domingo: si `semana_inicio` no es lunes, rechaza la solicitud con **`422 INVALID_WEEK_START_DATE`**.
   - **Horas de turnos (`inicio`, `fin`, `start`, `end`):** `HH:mm` en formato de 24 horas (`00:00` a `23:59`).
   - **Marcas temporales completas (`opened_at`, `closed_at`, `recorded_at`, `authorized_at`, `reference_date`, `generated_at`):** Formato **ISO-8601 con zona explícita de Lima** `YYYY-MM-DDTHH:mm:ss-05:00` (ej. `"2026-11-11T22:02:15-05:00"`). Ningún campo temporal canónico usa formato visual `"dd/mm/yyyy HH:mm"`.
3. **Reloj del servidor:** Todas las marcas temporales de operaciones reales se toman del reloj del servidor en `America/Lima`.

### 2.6. Campos ausentes frente a `null`, listados vacíos, paginación y filtros

1. **Campos ausentes frente a `null`:**
   - En las **respuestas JSON**, cada proyección mantiene las claves de su esquema específico (no todos los endpoints devuelven el recurso completo): cuando un valor opcional o condicional no aplica (p. ej. `reference_amount`, `reference_date`, `reference_turn` y `opening_difference` en la primera apertura histórica; `closed_at` en una sesión abierta; `administrator_id` en un ingreso manual simple), la clave **se incluye con valor `null`** (nunca se omite ni se sustituye `reference_amount` por `0.00` en la primera apertura).
   - En las **solicitudes JSON**, los campos condicionales que no aplican (p. ej. `admin_email`, `admin_password` y `exception_reason` cuando el cierre ocurre dentro de los 15 minutos previos a la salida o después de la salida) pueden omitirse o enviarse en `null`.
2. **Listados vacíos:**
   - Cuando una consulta válida no tiene registros que cumplan el filtro, el servidor responde **`200 OK`** con arreglo vacío `[]` y totales en `0.00`. **Prohibido** devolver datos de otra fecha o de otra sesión para rellenar una consulta vacía.
3. **Paginación, orden y desempate determinista:**
   - Parámetros: `page` (entero $\ge 1$, por defecto `1`) y `page_size` (alias `limit`, entero entre `1` y `100`, por defecto `10`). Si `page < 1` o `page_size < 1` o `page_size > 100`, responde `422 VALIDATION_ERROR`.
   - **Orden y desempate:**
     - Movimientos de una sesión (`EP-15`, `EP-22`, `EP-23C`, `EP-24B`): orden cronológico ascendente `ORDER BY fecha_hora_registro ASC, id_movimiento_caja ASC`.
     - Historial de arqueos de una sesión (`EP-17`, `EP-18`): orden cronológico ascendente `ORDER BY fecha_hora_arqueo ASC, id_arqueo_caja ASC`.
     - Sesiones de un día en consulta administrativa (`EP-23B`): `ORDER BY fecha_hora_apertura ASC, id_sesion_caja ASC`.
     - Reporte de cierres de caja (`EP-24A`): `ORDER BY fecha_hora_cierre DESC, id_sesion_caja DESC`.
   - **Totales completos frente a página visible:** Los objetos `summary` / `totals` de las consultas y reportes se calculan en el servidor sobre **todo el conjunto que cumple el filtro** (toda la sesión o todo el período filtrado), nunca sumando únicamente las filas de la página actual.
4. **Criterio de fechas en filtros:**
   - En **Consulta administrativa de sesiones (`EP-23B`)**: el parámetro `date` (`YYYY-MM-DD`) filtra por la **fecha local de apertura** (`DATE(fecha_hora_apertura) = :date` en `America/Lima`).
   - En **Reporte administrativo de cierres (`EP-24A`, `EP-24C`, `EP-24D`)**: los parámetros `desde` y `hasta` (`YYYY-MM-DD`) son **inclusivos** (`fecha_hora_cierre >= :desde 00:00:00 AND fecha_hora_cierre <= :hasta 23:59:59` en `America/Lima`) y filtran por la **fecha local de cierre** de sesiones en estado `'CERRADA'`.

---

## 3. Catálogo de Endpoints

| ID Endpoint | Operación Matriz | HU | Método | Ruta exacta relativa a `/api/` | Controlador destino | Rol exigido | Permiso RBAC exacto |
| :-: | :-: | :-: | :-: | :--- | :--- | :--- | :--- |
| **EP-01** | OP-01, OP-02 | HU 18 | `GET` | `turnos` | `TurnoApiController::get` | Admin (`1`) | `turnos.programar` |
| **EP-02** | OP-02 | HU 18 | `POST` | `turnos` | `TurnoApiController::post` | Admin (`1`) | `turnos.programar` |
| **EP-03** | OP-02 | HU 18 | `PUT` | `turnos/{id}` | `TurnoApiController::put` | Admin (`1`) | `turnos.programar` |
| **EP-04** | OP-02 | HU 18 | `DELETE` | `turnos/{id}` | `TurnoApiController::delete` | Admin (`1`) | `turnos.programar` |
| **EP-05** | OP-01, OP-03 | HU 18 | `GET` | `programaciones` | `ProgramacionApiController::get` | Admin (`1`) | `turnos.programar` |
| **EP-06** | OP-03 | HU 18 | `PUT` | `programaciones` | `ProgramacionApiController::put` | Admin (`1`) | `turnos.programar` |
| **EP-07** | OP-01 | HU 11 | `GET` | `caja/turno-hoy` | `CajaApiController::get` | Vendedor (`2`) | `turnos.abrir` |
| **EP-08** | OP-04 | HU 11 | `GET` | `caja/estado` | `CajaApiController::get` | Vendedor (`2`) | `turnos.abrir` |
| **EP-09** | OP-05, OP-14 | HU 11 | `POST` | `caja/abrir` | `CajaApiController::post` | Vendedor (`2`) | `turnos.abrir` |
| **EP-10** | OP-06, OP-07, OP-08 | HU 15, 16 | `GET` | `caja/sesion-actual` | `CajaApiController::get` | Vendedor (`2`) | `turnos.movimientos` |
| **EP-11** | OP-06 | HU 15, 16 | `GET` | `caja/administradores` | `CajaApiController::get` | Vendedor (`2`) | `turnos.movimientos` |
| **EP-12** | OP-08 | HU 16 | `GET` | `caja/turnos-anteriores` | `CajaApiController::get` | Vendedor (`2`) | `turnos.movimientos` |
| **EP-13** | OP-07, OP-14 | HU 15 | `POST` | `caja/ingresos` | `CajaApiController::post` | Vendedor (`2`) | `turnos.movimientos` |
| **EP-14** | OP-08, OP-14 | HU 16 | `POST` | `caja/egresos` | `CajaApiController::post` | Vendedor (`2`) | `turnos.movimientos` |
| **EP-15** | OP-09 | HU 15, 16 | `GET` | `caja/movimientos/corregibles` | `CajaApiController::get` | Vendedor (`2`) | `turnos.movimientos` |
| **EP-16** | OP-10, OP-14 | HU 15, 16 | `POST` | `caja/movimientos/correcciones` | `CajaApiController::post` | Vendedor (`2`) | `turnos.movimientos` |
| **EP-17** | OP-11 | HU 12 | `GET` | `caja/arqueo/estado` | `CajaApiController::get` | Vendedor (`2`) | `turnos.arqueo` |
| **EP-18** | OP-11 | HU 12 | `GET` | `caja/arqueos` | `CajaApiController::get` | Vendedor (`2`) | `turnos.arqueo` |
| **EP-19** | OP-11, OP-14 | HU 12 | `POST` | `caja/arqueo` | `CajaApiController::post` | Vendedor (`2`) | `turnos.arqueo` |
| **EP-20** | OP-12, OP-13, OP-14 | HU 13, 14 | `POST` | `caja/cerrar` | `CajaApiController::post` | Solicitante: Vendedor (`2`)<br>Autorizador (si anticipado): Admin (`1`) | `turnos.cerrar` |
| **EP-21** | OP-14 | HU 13 | `GET` | `caja/cierre` | `CajaApiController::get` | Vendedor (`2`) | `turnos.cerrar` |
| **EP-22** | OP-15 | HU 17 | `GET` | `caja/consulta/activa` | `CajaApiController::get` | Vendedor (`2`) | `turnos.acceder` |
| **EP-23A** | OP-16 | HU 17 | `GET` | `caja/consulta/vendedores` | `CajaApiController::get` | Admin (`1`) | `turnos.consultar_historico` |
| **EP-23B** | OP-16 | HU 17 | `GET` | `caja/consulta/sesiones` | `CajaApiController::get` | Admin (`1`) | `turnos.consultar_historico` |
| **EP-23C** | OP-16 | HU 17, 14 | `GET` | `caja/consulta` | `CajaApiController::get` | Admin (`1`) | `turnos.consultar_historico` |
| **EP-24A** | OP-17 | HU 19 | `GET` | `reportes/cierres-caja` | `ReporteApiController::get` | Admin (`1`) | `reportes.cierres_caja` |
| **EP-24B** | OP-17 | HU 19 | `GET` | `reportes/cierres-caja/{id}` | `ReporteApiController::get` | Admin (`1`) | `reportes.cierres_caja` |
| **EP-24C** | OP-17 | HU 19 | `GET` | `reportes/exportar-pdf?tipo=cierres-caja` | `ReporteApiController::get` | Admin (`1`) | `reportes.cierres_caja` **y** `reportes.exportar` |
| **EP-24D** | OP-17 | HU 19 | `GET` | `reportes/exportar-excel?tipo=cierres-caja` | `ReporteApiController::get` | Admin (`1`) | `reportes.cierres_caja` **y** `reportes.exportar` |
| **EP-25** | Transversal | HU 11–17 | `POST` | `ventas` | `VentaApiController::post` | Vendedor (`2`) | `ventas.procesar` + sesión propia `ABIERTA` |

---

## 4. Especificaciones Completas

Los ejemplos son escenarios independientes; no constituyen una única secuencia temporal. Todos los nombres de personas son ilustrativos. Las reglas comunes de las secciones 2 y 5–9 forman parte de cada endpoint, incluso si su lista local de errores destaca solo los específicos.

### A. Turnos y Programación Semanal (HU 18 y soporte HU 11)

---

#### EP-01. Listar catálogo administrativo de turnos
1. **Identificador y operación de la matriz:** `EP-01` — `OP-01` y `OP-02`.
2. **HU y acuerdo que lo justifican:** HU 18; Sección 4 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Obtener la lista de todos los turnos activos registrados en el sistema junto con la cantidad de vendedores distintos actualmente asignados a cada turno (`vendedores_asignados`), para el modal administrativo de gestión de turnos y programación semanal.
4. **Método y ruta exacta:** `GET /api/turnos`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.programar` (`requireAdminActivo('turnos.programar')`).
6. **Autenticación y CSRF:** Sesión autenticada activa en BD (`AuthMiddleware::verificarUsuarioActivoEnSesion()`). No requiere cabecera CSRF por ser `GET`.
7. **Propiedad y estado requerido:** No aplica propiedad individual (catálogo global administrado por `id_rol = 1`). No requiere sesión de caja abierta.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Ninguno. Cabecera `Accept: application/json`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Sin campos de entrada.
10. **Validaciones y condiciones:** Usuario activo en BD con `id_rol === 1` y permiso `turnos.programar`. Orden de respuesta: `ORDER BY hora_inicio ASC, id_turno ASC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Turnos obtenidos correctamente.",
  "data": [
    {
      "id": 1,
      "nombre": "Mañana",
      "inicio": "07:00",
      "fin": "15:00",
      "estado": 1,
      "vendedores_asignados": 2
    },
    {
      "id": 2,
      "nombre": "Tarde",
      "inicio": "15:00",
      "fin": "22:00",
      "estado": 1,
      "vendedores_asignados": 1
    }
  ]
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`: Sesión inexistente o usuario desactivado en BD.
    - `403 FORBIDDEN_ADMIN_ONLY`: Usuario con `id_rol !== 1` o sin permiso `turnos.programar`.
    - `500 INTERNAL_ERROR`: Error inesperado de base de datos.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno (solo lectura).
14. **Concurrencia y bloqueos necesarios:** Lectura consistente sin bloqueo.
15. **Idempotencia y recuperación:** Operación `GET` naturalmente idempotente.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* Administrador (`id_rol = 1`) invoca `GET /api/turnos` $\rightarrow$ `200 OK` con arreglo de turnos en `data`.
    - *Rechazo:* Vendedor (`id_rol = 2`) invoca `GET /api/turnos` $\rightarrow$ `403 FORBIDDEN_ADMIN_ONLY`.

---

#### EP-02. Crear turno de atención
1. **Identificador y operación de la matriz:** `EP-02` — `OP-02`.
2. **HU y acuerdo que lo justifican:** HU 18; Sección 4 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Registrar un nuevo turno de atención en el catálogo con nombre único e intervalo horario válido (`fin > inicio`) dentro del mismo día.
4. **Método y ruta exacta:** `POST /api/turnos`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.programar` (`requireAdminActivo('turnos.programar')`).
6. **Autenticación y CSRF:** Sesión activa en BD + cabecera obligatoria `X-CSRF-Token`.
7. **Propiedad y estado requerido:** Catálogo global de configuración. Independiente del estado de la sesión de caja.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`; referencia por `X-Request-Reference` o cuerpo (al menos una ubicación; deben coincidir si llegan ambas).
   - Cuerpo JSON: `{ "referencia_solicitud", "nombre", "inicio", "fin" }`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Campo canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `referencia_solicitud` | `request_id` | `string` | Sí | No | `16..64` caracteres `^[A-Za-z0-9_-]{16,64}$`. |
| `nombre` | — | `string` | Sí | No | `1..60` caracteres tras `trim()`. Único entre turnos activos (`LOWER(TRIM(nombre))`). |
| `inicio` | — | `string` | Sí | No | Formato 24h `HH:mm` (`^([01]\d\|2[0-3]):[0-5]\d$`). |
| `fin` | — | `string` | Sí | No | Formato 24h `HH:mm`, estrictamente posterior a `inicio` (`fin > inicio`). |

10. **Validaciones y condiciones:**
    - `referencia_solicitud` válida (`422 VALIDATION_ERROR`).
    - `nombre` no vacío, longitud `1..60`, sin duplicado activo (`409 SHIFT_NAME_ALREADY_EXISTS`).
    - `inicio` y `fin` en formato `HH:mm` de 24h con `fin > inicio` (`422 INVALID_SHIFT_HOURS`).
11. **HTTP de éxito y JSON completo (`201 Created`, o `200 OK` en replay idempotente):**
```json
{
  "status": true,
  "code": "SHIFT_CREATED",
  "idempotent_replay": false,
  "message": "Turno creado correctamente.",
  "data": {
    "result_code": "SHIFT_CREATED",
    "id": 3,
    "nombre": "Noche",
    "inicio": "15:00",
    "fin": "22:30",
    "estado": 1,
    "vendedores_asignados": 0
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN_ADMIN_ONLY`.
    - `409 SHIFT_NAME_ALREADY_EXISTS`: Ya existe un turno activo con ese nombre.
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 AMBIGUOUS_ALIAS_VALUES`, `422 INVALID_SHIFT_HOURS`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno sobre sesiones de caja.
14. **Concurrencia y bloqueos necesarios:** `ControlSolicitudService` (`GET_LOCK` por usuario/operación/referencia) + índice único en BD sobre nombre de turno activo.
15. **Idempotencia y recuperación:** Protegido con `ControlSolicitudService` (`tipo_operacion = 'TURNO_CREAR'`). Si se reintenta con la misma `referencia_solicitud` y los mismos datos, devuelve `200 OK` con `code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true` y el turno creado en `data`.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida:*
      ```json
{
  "referencia_solicitud": "am_turno_crear_20261112_0001",
  "nombre": "Noche",
  "inicio": "15:00",
  "fin": "22:30"
}
      ```
    - *Rechazo por horario invertido (`inicio: "15:00", fin: "14:00"`):* `422 INVALID_SHIFT_HOURS` (`errors.fin: "La hora de fin debe ser posterior a la hora de inicio."`).
    - *Rechazo por nombre duplicado (`nombre: "Mañana"`):* `409 SHIFT_NAME_ALREADY_EXISTS`.

---

#### EP-03. Editar turno de atención existente
1. **Identificador y operación de la matriz:** `EP-03` — `OP-02`.
2. **HU y acuerdo que lo justifican:** HU 18 y HU 11; Secciones 4 y 5 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Actualizar el nombre y/o las horas de inicio y fin de un turno existente, conservando las asignaciones semanales de vendedores y **sin alterar el horario de referencia (`hora_inicio_referencia`, `hora_fin_referencia`) guardado en sesiones de caja ya abiertas o cerradas**.
4. **Método y ruta exacta:** `PUT /api/turnos/{id}`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.programar` (`requireAdminActivo('turnos.programar')`).
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:** Catálogo global. Independiente del estado de la sesión de caja.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Parámetro de ruta: `id` (`int`, $\ge 1$, obligatorio).
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`.
   - Cuerpo JSON: `{ "nombre", "inicio", "fin", "referencia_solicitud" }`. La referencia es obligatoria según sección 8.1.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `id` entero $\ge 1$; `nombre`, `inicio`, `fin` con los mismos tipos y límites de `EP-02`.
10. **Validaciones y condiciones:**
    - Que el turno `{id}` exista y esté activo (`404 SHIFT_NOT_FOUND`).
    - Que `nombre` no colisione con otro turno activo distinto de `{id}` (`409 SHIFT_NAME_ALREADY_EXISTS`).
    - Que `inicio` y `fin` sean horas `HH:mm` válidas con `fin > inicio` (`422 INVALID_SHIFT_HOURS`).
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "SHIFT_UPDATED",
  "idempotent_replay": false,
  "message": "Turno actualizado correctamente.",
  "data": {
    "result_code": "SHIFT_UPDATED",
    "id": 1,
    "nombre": "Mañana",
    "inicio": "08:00",
    "fin": "14:00",
    "estado": 1,
    "vendedores_asignados": 2
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN_ADMIN_ONLY`.
    - `404 SHIFT_NOT_FOUND`: El turno `{id}` no existe.
    - `409 SHIFT_NAME_ALREADY_EXISTS`: Otro turno ya usa ese nombre.
    - `422 INVALID_SHIFT_HOURS`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno sobre `sesion_caja` (las sesiones abiertas o cerradas conservan sus columnas snapshot `nombre_turno_referencia`, `hora_inicio_referencia`, `hora_fin_referencia`).
14. **Concurrencia y bloqueos necesarios:** Bloqueo de fila `SELECT ... FOR UPDATE` sobre `turno WHERE id_turno = :id`.
15. **Idempotencia y recuperación:** Protegido por `ControlSolicitudService` (`tipo_operacion = 'TURNO_EDITAR'`) y referencia obligatoria. El replay devuelve el resultado original antes de evaluar el estado actual del catálogo.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `PUT /api/turnos/1` con `{ "referencia_solicitud": "am_turno_edit_00000001", "nombre": "Mañana", "inicio": "08:00", "fin": "14:00" }` $\rightarrow$ `200 OK`.
    - *Rechazo:* `PUT /api/turnos/999` $\rightarrow$ `404 SHIFT_NOT_FOUND`.

---

#### EP-04. Eliminar turno de atención
1. **Identificador y operación de la matriz:** `EP-04` — `OP-02`.
2. **HU y acuerdo que lo justifican:** HU 18; Sección 4 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Dar de baja lógica (`estado = 0`) un turno sin asignaciones vigentes/futuras ni sesión abierta asociada. Nunca se elimina físicamente: conserva las referencias históricas de sesiones y programaciones.
4. **Método y ruta exacta:** `DELETE /api/turnos/{id}`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.programar` (`requireAdminActivo('turnos.programar')`).
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:** No puede eliminarse un turno que tenga vendedores asignados o una sesión `ABIERTA`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Ruta `id` entero positivo y cabeceras `X-CSRF-Token` y `X-Request-Reference` obligatorias. Sin cuerpo JSON.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `id` entero positivo; referencia de cabecera cadena 16..64 según sección 8.1. Sin nulos.
10. **Validaciones y condiciones:**
    - Que el turno `{id}` exista y esté activo (`404 SHIFT_NOT_FOUND`).
    - Que `vendedores_asignados === 0` en programaciones vigentes/futuras (`409 SHIFT_HAS_ASSIGNED_SELLERS`).
    - Que no exista una sesión `ABIERTA` asociada a ese turno (`409 SHIFT_IN_USE`).
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "SHIFT_DELETED",
  "idempotent_replay": false,
  "message": "Turno eliminado correctamente.",
  "data": {
    "result_code": "SHIFT_DELETED",
    "eliminado": true,
    "id": 3
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `422 VALIDATION_ERROR`, `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH` según transporte/idempotencia.
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN_ADMIN_ONLY`.
    - `404 SHIFT_NOT_FOUND`: El turno no existe o ya fue eliminado.
    - `409 SHIFT_HAS_ASSIGNED_SELLERS`: `"No se puede eliminar: hay vendedores asignados a este turno."`
    - `409 SHIFT_IN_USE`: `"No se puede eliminar el turno mientras exista una sesión de caja abierta asociada a él."`
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Transacción con `SELECT ... FOR UPDATE` sobre la fila de `turno` antes de verificar asignaciones en `programacion_semanal_detalle` y sesiones en `sesion_caja`.
15. **Idempotencia y recuperación:** Protegido por `ControlSolicitudService`, `tipo_operacion = 'TURNO_BAJA'`. Mismo identificador confirmado: replay `200 OPERATION_ALREADY_CONFIRMED`. Otra referencia sobre turno inactivo: `404 SHIFT_NOT_FOUND`.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `DELETE /api/turnos/3` (sin vendedores asignados) $\rightarrow$ `200 SHIFT_DELETED`.
    - *Rechazo:* `DELETE /api/turnos/1` (con 2 vendedores asignados) $\rightarrow$ `409 SHIFT_HAS_ASSIGNED_SELLERS`.

---

#### EP-05. Consultar programación semanal de un vendedor
1. **Identificador y operación de la matriz:** `EP-05` — `OP-01` y `OP-03`.
2. **HU y acuerdo que lo justifican:** HU 18; Sección 4 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Resolver en el servidor qué programación rige para un vendedor (`usuario_id`) en una semana determinada (`semana_inicio`, lunes `YYYY-MM-DD`), aplicando la regla de continuidad:
   - Si existe una excepción temporal vigente (`alcance = 'semana'`) para esa semana exacta, devuelve dicha excepción.
   - En caso contrario, devuelve la programación base (`alcance = 'desde'`) más reciente cuya `semana_inicio <= semana_consultada`.
   - Si el vendedor aún no tiene ninguna programación aplicable para esa semana, devuelve `data: null`.
4. **Método y ruta exacta:** `GET /api/programaciones?usuario_id={id}&semana_inicio={YYYY-MM-DD}`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.programar` (`requireAdminActivo('turnos.programar')`).
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:** El `usuario_id` consultado debe existir en `usuario` y tener rol Vendedor (`id_rol = 2`). Independiente del estado de la sesión de caja.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Query string: `usuario_id` (alias admitido: `usuarioId`) y `semana_inicio` (alias admitido: `semanaInicio`).
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Parámetro canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `usuario_id` | `usuarioId` | `int` | Sí | No | Entero $\ge 1$. Debe existir en `usuario` con `id_rol = 2`. |
| `semana_inicio` | `semanaInicio` | `string` | Sí | No | Fecha `YYYY-MM-DD` que **debe corresponder estrictamente a un lunes (`ISO-8601 N = 1`)**. |

10. **Validaciones y condiciones:**
    - Regla de alias: si llegan `usuario_id` y `usuarioId` con valores distintos (o `semana_inicio` y `semanaInicio` con valores distintos), rechaza con `422 AMBIGUOUS_ALIAS_VALUES`.
    - `usuario_id` existe (`404 USER_NOT_FOUND`) y tiene `id_rol = 2` (`422 TARGET_NOT_SELLER`).
    - `semana_inicio` es una fecha calendario válida y es lunes (`422 INVALID_WEEK_START_DATE`).
11. **HTTP de éxito y JSON completo (`200 OK`):**
    - **Caso A — Existe programación aplicable para esa semana:**
```json
{
  "status": true,
  "code": "OK",
  "message": "Programación obtenida correctamente.",
  "data": {
    "usuario_id": 4,
    "semana_inicio": "2026-11-09",
    "alcance": "desde",
    "origen_semana_inicio": "2026-11-02",
    "tiene_programacion_base": true,
    "tiene_semana_anterior": true,
    "turnos": [
      {
        "turno_id": 1,
        "nombre": "Mañana",
        "inicio": "07:00",
        "fin": "15:00",
        "dias": [
          0,
          1,
          2,
          3,
          4
        ]
      },
      {
        "turno_id": 2,
        "nombre": "Tarde",
        "inicio": "15:00",
        "fin": "22:00",
        "dias": [
          0,
          1,
          2,
          3,
          4
        ]
      }
    ]
  }
}
```
    - **Caso B — El vendedor aún no tiene ninguna programación base ni excepción aplicable para esa semana:**
```json
{
  "status": true,
  "code": "OK",
  "message": "El vendedor aún no tiene programación registrada para esta semana.",
  "data": null
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`.
    - `404 USER_NOT_FOUND`: El `usuario_id` no existe.
    - `422 AMBIGUOUS_ALIAS_VALUES`: Se enviaron alias contradictorios.
    - `422 TARGET_NOT_SELLER`: El usuario indicado no tiene rol Vendedor (`id_rol = 2`).
    - `422 INVALID_WEEK_START_DATE`: `semana_inicio` no es una fecha válida o no es lunes.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/programaciones?usuario_id=4&semana_inicio=2026-11-09` (`2026-11-09` es lunes) $\rightarrow$ `200 OK`.
    - *Rechazo por fecha que no es lunes:* `GET /api/programaciones?usuario_id=4&semana_inicio=2026-11-11` (miércoles) $\rightarrow$ `422 INVALID_WEEK_START_DATE`.
    - *Rechazo por consultar a un Administrador:* `GET /api/programaciones?usuario_id=1&semana_inicio=2026-11-09` $\rightarrow$ `422 TARGET_NOT_SELLER`.

---

#### EP-06. Guardar o duplicar programación semanal de un vendedor
1. **Identificador y operación de la matriz:** `EP-06` — `OP-03`.
2. **HU y acuerdo que lo justifican:** HU 18; Sección 4 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Guardar la programación semanal de un vendedor admitiendo **varios turnos en un mismo día** (ej. Mañana y Tarde el lunes), así como el resultado de duplicar una semana existente, aplicando el alcance elegido:
   - Primera programación histórica del vendedor: se guarda como base vigente (`alcance = "desde"`).
   - `alcance = "semana"` (`Solo esta semana`): registra o actualiza una excepción temporal únicamente para `semana_inicio`; al terminar esa semana vuelve a aplicarse la base vigente.
   - `alcance = "desde"` (`Desde esta semana en adelante`): establece una nueva regla base a partir de `semana_inicio` en adelante y **conserva intactas todas las semanas anteriores (`< semana_inicio`) y el historial de aperturas y cierres reales**. *(Ver secciones 8.3 y 13: las excepciones semanales existentes conservan su prioridad).*
4. **Método y ruta exacta:** `PUT /api/programaciones`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.programar` (`requireAdminActivo('turnos.programar')`).
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:** El `usuario_id` objetivo debe existir en `usuario`, estar activo (`estado = 1`) y tener rol Vendedor (`id_rol = 2`). Independiente del estado de la caja.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`; referencia en `X-Request-Reference` o cuerpo según sección 8.1.
   - Cuerpo JSON: `{ "referencia_solicitud", "usuario_id", "semana_inicio", "alcance", "turnos": [{ "turno_id", "dias" }] }`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Campo canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `referencia_solicitud` | `request_id` | `string` | Sí | No | `16..64` caracteres `^[A-Za-z0-9_-]{16,64}$`. |
| `usuario_id` | `usuarioId` | `int` | Sí | No | Entero $\ge 1$, vendedor activo (`id_rol = 2`, `estado = 1`). |
| `semana_inicio` | `semanaInicio` | `string` | Sí | No | Fecha `YYYY-MM-DD` que **debe ser estrictamente lunes (`N = 1`)**. |
| `alcance` | — | `string` | Sí | No | `'semana'` o `'desde'`. Si el vendedor no posee ninguna base previa anterior o igual a `semana_inicio`, debe ser `'desde'`. |
| `turnos` | — | `array` | Sí | No | Arreglo no vacío de turnos activos distintos; no se establece un máximo de negocio de diez turnos. |
| `turnos[].turno_id` | `turnoId` | `int` | Sí | No | Entero $\ge 1$, turno activo en `turno`. No puede repetirse el mismo `turno_id` dentro del arreglo `turnos`. |
| `turnos[].dias` | — | `int[]` | Sí | No | Arreglo de `1..7` enteros distintos entre `0` (lunes) y `6` (domingo). Distintos turnos pueden compartir los mismos días. |

10. **Validaciones y condiciones:**
    - Normalización y verificación de no contradicción entre claves canónicas y alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - `usuario_id` existe (`404 USER_NOT_FOUND`), está activo y tiene `id_rol = 2` (`422 TARGET_NOT_SELLER`).
    - `semana_inicio` es fecha válida y es lunes (`422 INVALID_WEEK_START_DATE`).
    - `alcance` en `['semana', 'desde']` (`422 VALIDATION_ERROR`).
    - `turnos` no vacío; ningún `turno_id` repetido (`422 DUPLICATE_SHIFT_IN_SCHEDULE`); todos los `turno_id` existen y están activos (`422 INVALID_SHIFT`); cada `dias` contiene al menos un entero en `0..6` (`422 INVALID_SCHEDULE_DAYS`).
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "SCHEDULE_SAVED",
  "idempotent_replay": false,
  "message": "Programación semanal guardada correctamente.",
  "data": {
    "result_code": "SCHEDULE_SAVED",
    "usuario_id": 4,
    "semana_inicio": "2026-11-09",
    "alcance": "semana",
    "tiene_programacion_base": true,
    "turnos": [
      {
        "turno_id": 1,
        "nombre": "Mañana",
        "inicio": "07:00",
        "fin": "15:00",
        "dias": [
          0,
          1,
          2,
          3,
          4
        ]
      },
      {
        "turno_id": 2,
        "nombre": "Tarde",
        "inicio": "15:00",
        "fin": "22:00",
        "dias": [
          0,
          1,
          2,
          3,
          4
        ]
      }
    ]
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN_ADMIN_ONLY`.
    - `404 USER_NOT_FOUND`.
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 AMBIGUOUS_ALIAS_VALUES`, `422 TARGET_NOT_SELLER`, `422 INVALID_WEEK_START_DATE`, `422 INVALID_SHIFT`, `422 DUPLICATE_SHIFT_IN_SCHEDULE`, `422 INVALID_SCHEDULE_DAYS`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno sobre las sesiones de caja abiertas o cerradas.
14. **Concurrencia y bloqueos necesarios:** Se bloquea primero la fila del vendedor en `usuario` (`SELECT id_usuario FROM usuario WHERE id_usuario = :id FOR UPDATE`) para serializar modificaciones concurrentes de programación sobre el mismo vendedor incluso cuando aún no tenía filas en `programacion_semanal`.
15. **Idempotencia y recuperación:** Protegido por `ControlSolicitudService` (`tipo_operacion = 'PROGRAMACION_HORARIO'`). Reintentos con la misma `referencia_solicitud` devuelven `200 OK` con `code: "OPERATION_ALREADY_CONFIRMED"` y `idempotent_replay: true`.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida (varios turnos el mismo día):*
      ```json
{
  "referencia_solicitud": "am_prog_20261109_vendedor4_001",
  "usuario_id": 4,
  "semana_inicio": "2026-11-09",
  "alcance": "desde",
  "turnos": [
    {
      "turno_id": 1,
      "dias": [
        0,
        1,
        2,
        3,
        4
      ]
    },
    {
      "turno_id": 2,
      "dias": [
        0,
        1,
        2,
        3,
        4
      ]
    }
  ]
}
      ```
    - *Rechazo por turno repetido en el arreglo (`turno_id: 1` dos veces):* `422 DUPLICATE_SHIFT_IN_SCHEDULE`.
    - *Rechazo por `semana_inicio` en martes (`"2026-11-10"`):* `422 INVALID_WEEK_START_DATE`.

---

### B. Apertura de Sesión de Caja y Suplencias (HU 11)

---

#### EP-07. Consultar turnos disponibles para apertura y programación propia de hoy
1. **Identificador y operación de la matriz:** `EP-07` — `OP-01`.
2. **HU y acuerdo que lo justifican:** HU 11; Sección 5 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Entregar al vendedor autenticado que va a abrir caja:
   1. Su programación propia para la fecha actual del servidor en `America/Lima` (`has_shift_today`, `status`: `'within_hours' | 'outside_hours' | 'no_shift'`, `shift` sugerido y `scheduled_shifts` con todos sus turnos programados hoy).
   2. El catálogo completo de turnos activos (`available_shifts`) para que **seleccione el turno que realmente atenderá**, incluida una **suplencia** o apertura fuera de horario.
   3. El indicador `requires_authorization_to_open: false` (la apertura jamás pide credenciales administrativas).
4. **Método y ruta exacta:** `GET /api/caja/turno-hoy`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.abrir`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - **Regla anti-suplantación:** El servidor consulta exclusivamente la programación de `$this->authenticatedUserId`. Si el cliente envía `?vendedor_id=X` con un valor distinto de `$this->authenticatedUserId`, el servidor lo rechaza con `403 FORBIDDEN` (y si es igual a `$this->authenticatedUserId` o se omite, opera sobre `$this->authenticatedUserId`).
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Sin parámetros obligatorios.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `vendedor_id` opcional en query (`int`, si se envía debe coincidir con `$this->authenticatedUserId`).
10. **Validaciones y condiciones:** Usuario activo en BD con `id_rol === 2` y permiso `turnos.abrir`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Información de turnos para apertura obtenida.",
  "data": {
    "vendedor_id": 4,
    "server_date": "2026-11-12",
    "server_time": "08:05",
    "status": "within_hours",
    "has_shift_today": true,
    "shift": {
      "id": 1,
      "name": "Mañana",
      "label": "Mañana · 07:00–15:00",
      "start": "07:00",
      "end": "15:00"
    },
    "scheduled_shifts": [
      {
        "id": 1,
        "name": "Mañana",
        "label": "Mañana · 07:00–15:00",
        "start": "07:00",
        "end": "15:00"
      }
    ],
    "available_shifts": [
      {
        "id": 1,
        "name": "Mañana",
        "label": "Mañana · 07:00–15:00",
        "start": "07:00",
        "end": "15:00",
        "is_scheduled_for_seller": true
      },
      {
        "id": 2,
        "name": "Tarde",
        "label": "Tarde · 15:00–22:00",
        "start": "15:00",
        "end": "22:00",
        "is_scheduled_for_seller": false
      }
    ],
    "recommended_shift_id": 1,
    "requires_authorization_to_open": false
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`: Sesión no válida.
    - `403 FORBIDDEN`: Rol distinto de Vendedor (`id_rol !== 2`), falta de permiso `turnos.abrir` o intento de consultar `vendedor_id` ajeno.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido (vendedor sin turno hoy que hará suplencia):* `GET /api/caja/turno-hoy` $\rightarrow$ `200 OK` con `has_shift_today: false`, `status: "no_shift"`, `shift: null`, `scheduled_shifts: []`, `available_shifts: [...]` (todos los turnos activos) y `requires_authorization_to_open: false`.
    - *Rechazo:* Vendedor `id=4` invoca `GET /api/caja/turno-hoy?vendedor_id=6` $\rightarrow$ `403 FORBIDDEN`.

---

#### EP-08. Consultar estado de la única caja física y referencia mínima de apertura
1. **Identificador y operación de la matriz:** `EP-08` — `OP-04`.
2. **HU y acuerdo que lo justifican:** HU 11; Secciones 3 y 5 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Informar al vendedor el estado actual de la única caja física antes de abrir:
   - **Caso A (Caja cerrada y primera apertura histórica del sistema):** `is_open: false`, `has_previous_close: false`, `reference_amount: null`, `reference_date: null`, `reference_turn: null` (jamás un cero supuesto como cierre anterior).
   - **Caso B (Caja cerrada con cierre anterior confirmado):** `is_open: false`, `has_previous_close: true` y **únicamente los 3 escalares de referencia** del último cierre confirmado (`reference_amount`, `reference_date` en formato ISO-8601 `-05:00`, `reference_turn`).
   - **Caso C (Caja abierta por el propio vendedor autenticado):** `is_open: true`, `is_owner: true`, `session_id`, turno atendido, horario de referencia y `counted_amount` (`opening_balance`).
   - **Caso D (Caja abierta por otro vendedor):** `is_open: true`, `is_owner: false`, `occupied_by: { seller_name, shift_name, opened_at }`, ocultando todos los importes financieros (`reference_amount: null`, `counted_amount: null`).
4. **Método y ruta exacta:** `GET /api/caja/estado`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.abrir`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - Compara `sesion_abierta.id_usuario_vendedor === $this->authenticatedUserId`.
   - Si recibe `vendedor_id` distinto de `$this->authenticatedUserId`, rechaza con `403 FORBIDDEN`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Sin parámetros obligatorios.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `vendedor_id` opcional en query (`int`, si se envía debe ser igual a `$this->authenticatedUserId`).
10. **Validaciones y condiciones:** Usuario activo con `id_rol === 2` y permiso `turnos.abrir`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
    - **Caso A — Primera apertura histórica (condición inicial real de `amazon_market` con `0` sesiones previas):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Estado de caja obtenido (primera apertura sin referencia previa).",
  "data": {
    "is_open": false,
    "is_owner": false,
    "session_id": null,
    "has_previous_close": false,
    "reference_amount": null,
    "reference_date": null,
    "reference_turn": null,
    "counted_amount": null,
    "occupied_by": null
  }
}
```
    - **Caso B — Caja cerrada con referencia del último cierre confirmado (`reference_date` en ISO-8601 canónico):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Estado de caja obtenido.",
  "data": {
    "is_open": false,
    "is_owner": false,
    "session_id": null,
    "has_previous_close": true,
    "reference_amount": 200.0,
    "reference_date": "2026-11-11T22:02:15-05:00",
    "reference_turn": "Tarde",
    "counted_amount": null,
    "occupied_by": null
  }
}
```
    - **Caso D — Caja física ya abierta por otro vendedor (`is_owner: false`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "La caja física tiene una sesión abierta por otro vendedor.",
  "data": {
    "is_open": true,
    "is_owner": false,
    "session_id": 14,
    "has_previous_close": true,
    "reference_amount": null,
    "reference_date": null,
    "reference_turn": null,
    "counted_amount": null,
    "occupied_by": {
      "seller_name": "María",
      "shift_name": "Mañana",
      "opened_at": "2026-11-12T07:02:10-05:00"
    }
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/estado` $\rightarrow$ `200 OK`.
    - *Rechazo:* Administrador (`id_rol = 1`) o vendedor inactivo invoca `GET /api/caja/estado` $\rightarrow$ `403 FORBIDDEN` / `401 AUTH_REQUIRED`.

---

#### EP-09. Abrir sesión de caja propia (incluida suplencia o apertura fuera de horario)
1. **Identificador y operación de la matriz:** `EP-09` — `OP-05` y `OP-14`.
2. **HU y acuerdo que lo justifican:** HU 11; Secciones 3 y 5 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Abrir una nueva sesión en la única caja física bajo la responsabilidad del vendedor autenticado (`id_usuario_vendedor = $this->authenticatedUserId`), seleccionando el turno activo que atenderá (`shift_id`), fijando en la sesión el snapshot de su horario de referencia (`nombre_turno_referencia`, `hora_inicio_referencia`, `hora_fin_referencia`, `fecha_hora_salida_referencia` completa según 5.3), estableciendo el efectivo contado (`cash_counted`) como `opening_balance` ($\ge 0.00$) y, únicamente cuando exista un cierre anterior confirmado, calculando por separado `opening_difference = cash_counted - reference_amount` sin generar ningún egreso ni modificar el cierre anterior.
4. **Método y ruta exacta:** `POST /api/caja/abrir`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.abrir`.
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:**
   - El titular de la sesión creada es siempre `$this->authenticatedUserId` (incluso si abre en suplencia del turno programado de otro vendedor).
   - La única caja física **no debe tener ninguna sesión en estado `'ABIERTA'`**.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`; referencia en `X-Request-Reference` o cuerpo según sección 8.1.
   - Cuerpo JSON: `{ "referencia_solicitud", "shift_id", "cash_counted" }`.
   - **Prohibición de credenciales administrativas:** La apertura **nunca** solicita ni valida `admin_email`, `admin_username`, `admin_password` ni `exception_reason`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Campo canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `referencia_solicitud` | `request_id` | `string` | Sí | No | `16..64` caracteres `^[A-Za-z0-9_-]{16,64}$`. |
| `shift_id` | `turno_id` | `int` | Sí | No | Entero $\ge 1$. Debe existir en `turno` con `estado = 1`. |
| `cash_counted` | — | `number\|string` | Sí | No | Decimal exacto hasta 2 decimales, **$\ge 0.00$** y $\le 99999999.99$. |

10. **Validaciones y condiciones:**
    - Verificación de no contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - `referencia_solicitud` válida (`422 VALIDATION_ERROR`).
    - `shift_id` corresponde a un turno activo (`422 INVALID_SHIFT`).
    - `cash_counted` numérico finito $\ge 0.00$ con máximo 2 decimales (`422 INVALID_AMOUNT`) y $\le 99999999.99$ (`422 AMOUNT_OUT_OF_RANGE`).
    - Inexistencia de otra sesión `ABIERTA` en la caja física (`409 CASH_ALREADY_OPEN`).
11. **HTTP de éxito y JSON completo (`201 Created`, o `200 OK` en replay idempotente):**
    - **Caso A — Primera apertura histórica (sin cierre anterior; `cash_counted = 150.00`):**
```json
{
  "status": true,
  "code": "OPENED",
  "idempotent_replay": false,
  "message": "Sesión de caja abierta correctamente.",
  "data": {
    "result_code": "OPENED",
    "session_id": 1,
    "seller_id": 4,
    "seller_name": "Alex Garcia",
    "shift": {
      "id": 1,
      "name": "Mañana",
      "start": "07:00",
      "end": "15:00",
      "label": "Mañana · 07:00–15:00"
    },
    "is_substitution": false,
    "opened_outside_schedule": false,
    "has_previous_close": false,
    "reference_amount": null,
    "reference_date": null,
    "reference_turn": null,
    "opening_balance": 150.0,
    "opening_difference": null,
    "opened_at": "2026-11-12T07:04:12-05:00",
    "version": 1,
    "status": "Abierta"
  }
}
```
    - **Caso B — Apertura posterior con cierre previo de `200.00` y conteo entrante de `190.00` (en suplencia):**
```json
{
  "status": true,
  "code": "OPENED",
  "idempotent_replay": false,
  "message": "Sesión de caja abierta correctamente.",
  "data": {
    "result_code": "OPENED",
    "session_id": 15,
    "seller_id": 4,
    "seller_name": "Alex Garcia",
    "shift": {
      "id": 2,
      "name": "Tarde",
      "start": "15:00",
      "end": "22:00",
      "label": "Tarde · 15:00–22:00"
    },
    "is_substitution": true,
    "opened_outside_schedule": true,
    "has_previous_close": true,
    "reference_amount": 200.0,
    "reference_date": "2026-11-11T22:02:15-05:00",
    "reference_turn": "Tarde",
    "opening_balance": 190.0,
    "opening_difference": -10.0,
    "opened_at": "2026-11-12T15:03:10-05:00",
    "version": 1,
    "status": "Abierta"
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN`.
    - `409 CASH_ALREADY_OPEN`: Ya existe una sesión abierta en la única caja física (incluye `data.occupied_by`).
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 AMBIGUOUS_ALIAS_VALUES`, `422 INVALID_SHIFT`, `422 INVALID_AMOUNT`, `422 AMOUNT_OUT_OF_RANGE`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:**
    - Crea la fila en `sesion_caja` con `estado = 'ABIERTA'`, `saldo_inicial = cash_counted`, `efectivo_esperado_actual = cash_counted`, acumuladores de ventas/ingresos/egresos en `0.00`, `version_caja = 1` y sin arqueos aún.
    - No modifica el cierre anterior ni la programación semanal.
14. **Concurrencia y bloqueos necesarios:**
    - Dado que `SELECT ... FOR UPDATE` sobre `sesion_caja WHERE estado = 'ABIERTA'` no bloquea cuando aún existen `0` filas abiertas, el servidor adquiere dentro de la operación un bloqueo exclusivo de caja física mediante `SELECT GET_LOCK('am_caja_fisica_unica', 5)` (además de una columna generada/índice único sobre sesión activa en BD) y lee el último cierre confirmado con `SELECT ... FROM sesion_caja WHERE estado = 'CERRADA' ORDER BY id_sesion_caja DESC LIMIT 1 FOR UPDATE`. Así, si dos vendedores intentan abrir al mismo tiempo, las aperturas se serializan estrictamente: la primera confirma `201 OPENED` y la segunda recibe `409 CASH_ALREADY_OPEN`.
15. **Idempotencia y recuperación:**
    - `ControlSolicitudService` (`tipo_operacion = 'CAJA_ABRIR'`) se evalúa **antes** de verificar `CASH_ALREADY_OPEN`. Si el vendedor que acaba de abrir pierde la respuesta de red y reenvía el mismo `request_id` con el mismo `shift_id` y `cash_counted`, recibe `200 OK` (`code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`, `data.result_code: "OPENED"`) con su sesión abierta, en lugar de un falso `409 CASH_ALREADY_OPEN`.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida:*
      ```json
{
  "referencia_solicitud": "am_4f82c109e7a14b239012cd34ef56ab78",
  "shift_id": 1,
  "cash_counted": 190.0
}
      ```
    - *Rechazo por importe negativo (`cash_counted: -5`):* `422 INVALID_AMOUNT`.
    - *Rechazo por caja ya abierta:* `409 CASH_ALREADY_OPEN`.


---

### C. Movimientos Manuales, Retiros Omitidos y Correcciones Vinculadas (HU 15 y HU 16)

---

#### EP-10. Consultar estado y saldo actual de la sesión propia para el módulo de movimientos
1. **Identificador y operación de la matriz:** `EP-10` — `OP-06`, `OP-07`, `OP-08`.
2. **HU y acuerdo que lo justifican:** HU 15 y HU 16; Sección 6 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Devolver al vendedor autenticado el estado actual de su sesión abierta (`is_open`, `is_owner`, `session_id`, `seller`, `turn`, `current_balance`, `version`) para inicializar y refrescar la pantalla `/Caja/movimientos` (`CajaMovimientosService.getSessionState()`).
4. **Método y ruta exacta:** `GET /api/caja/sesion-actual`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.movimientos`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - Consulta la sesión en estado `'ABIERTA'` de la única caja física:
     - Si existe y `id_usuario_vendedor === $this->authenticatedUserId`: devuelve `is_open: true`, `is_owner: true` y su saldo actual de efectivo (`current_balance` y `balance`).
     - Si no existe sesión abierta o pertenece a otro vendedor: devuelve `is_open: false`, `is_owner: false`, `session_id: null`, `current_balance: 0.0` (nunca expone el saldo de otro vendedor).
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Ninguno.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Sin campos de entrada.
10. **Validaciones y condiciones:** Usuario activo con `id_rol === 2` y permiso `turnos.movimientos`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Estado de la sesión activa obtenido correctamente.",
  "data": {
    "is_open": true,
    "is_owner": true,
    "session_id": 15,
    "seller_id": 4,
    "seller": "Alex Garcia",
    "turn": "Mañana",
    "shift_label": "Mañana · 07:00–15:00",
    "opening_balance": 190.0,
    "cash_sales": 60.0,
    "income": 50.0,
    "expenses": 20.0,
    "current_balance": 280.0,
    "balance": 280.0,
    "version": 4
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido (con sesión propia abierta):* `GET /api/caja/sesion-actual` $\rightarrow$ `200 OK` con `is_open: true, current_balance: 280.0`.
    - *Válido (con caja cerrada):* `GET /api/caja/sesion-actual` $\rightarrow$ `200 OK` con `is_open: false, session_id: null, current_balance: 0.0`.
    - *Rechazo:* Administrador (`id_rol = 1`) invoca `GET /api/caja/sesion-actual` $\rightarrow$ `403 FORBIDDEN`.

---

#### EP-11. Consultar administradores activos para identificar entregas o recepciones de efectivo
1. **Identificador y operación de la matriz:** `EP-11` — `OP-06`.
2. **HU y acuerdo que lo justifican:** HU 15 y HU 16; Sección 2 y Sección 6 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Devolver al vendedor con sesión abierta la proyección mínima (`id`, `name`) de los usuarios con rol Administrador (`id_rol = 1`) y `estado = 1` en base de datos, para seleccionar qué administrador entregó el dinero en una inyección (`admin_injection`) o qué administrador recibió el dinero en un retiro (`admin_withdrawal` / `omitted_withdrawal`), sin exponer `GET /api/usuarios` ni solicitar credenciales.
4. **Método y ruta exacta:** `GET /api/caja/administradores`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.movimientos`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - Exige que el vendedor solicitante tenga una sesión propia en estado `'ABIERTA'` (`409 CASH_SESSION_CLOSED` si la caja está cerrada, o `403 SESSION_NOT_OWNED` si está abierta por otro vendedor).
   - **Proyección estricta de seguridad:** Solo devuelve `id` (`id_usuario`) y `name` (`nombre`). **Prohibido incluir `email`, `dni`, `telefono`, `direccion`, `username` o `password_hash`.**
   - *Compatibilidad con la base real (`amazon_market`):* En el Paso 3 se comprobó que `id_usuario = 1` (`admin`) tiene `email = NULL` e `id_usuario = 9` (`Superuser`) tiene `email` definido. Como este endpoint solo identifica nominalmente al administrador que entregó o recibió efectivo (sin autenticar por correo), ambos administradores activos aparecen válidamente en el listado.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Ninguno.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Sin campos de entrada.
10. **Validaciones y condiciones:** Sesión propia `'ABIERTA'` y rol Vendedor con `turnos.movimientos`. Orden: `ORDER BY nombre ASC, id_usuario ASC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Administradores activos obtenidos correctamente.",
  "data": [
    {
      "id": 1,
      "name": "Administrador Principal"
    },
    {
      "id": 9,
      "name": "Superuser Demo"
    }
  ]
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`, `409 CASH_SESSION_CLOSED`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* Vendedor con sesión abierta invoca `GET /api/caja/administradores` $\rightarrow$ `200 OK` con lista `[{ id, name }]`.
    - *Rechazo:* Vendedor sin sesión abierta invoca `GET /api/caja/administradores` $\rightarrow$ `409 CASH_SESSION_CLOSED`.

---

#### EP-12. Consultar sesiones cerradas anteriores elegibles para vincular un retiro omitido
1. **Identificador y operación de la matriz:** `EP-12` — `OP-08`.
2. **HU y acuerdo que lo justifican:** HU 16; Sección 6 ("Retiro omitido del turno anterior") de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Devolver al vendedor con sesión abierta la lista acotada de sesiones cerradas anteriores de la caja física con la referencia mínima necesaria (`id`, `label`, `date`, `turn`, `closed_at`, `expected`, `counted`, `shortage`) para seleccionar el turno de origen al declarar un **retiro omitido**, incluso si esa sesión anterior fue atendida por otro vendedor, sin dar acceso general al historial de movimientos ni permitir modificar la sesión cerrada.
4. **Método y ruta exacta:** `GET /api/caja/turnos-anteriores`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.movimientos`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - El vendedor solicitante debe tener una sesión propia en estado `'ABIERTA'` (`409 CASH_SESSION_CLOSED` / `403 SESSION_NOT_OWNED`).
   - Las sesiones listadas pertenecen a la única caja física y tienen `estado = 'CERRADA'`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Query params opcionales: `estado` (opcional, `'cerrado'`), `page_size` (alias `limit`, entero `1..20`, por defecto `10`).
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `page_size` (`limit`): entero `1..20`, por defecto `10`.
10. **Validaciones y condiciones:** Sesión propia `'ABIERTA'`; `page_size` entre `1` y `20` (`422 VALIDATION_ERROR`). Orden: `ORDER BY fecha_hora_cierre DESC, id_sesion_caja DESC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
    - **Caso A — Existen sesiones cerradas previas:**
```json
{
  "status": true,
  "code": "OK",
  "message": "Turnos cerrados anteriores obtenidos correctamente.",
  "data": [
    {
      "id": 14,
      "date": "2026-11-11",
      "turn": "Tarde",
      "closed_at": "2026-11-11T22:02:15-05:00",
      "label": "Sesión #14 · 2026-11-11 · Turno Tarde (cerrado)",
      "expected": 500.0,
      "counted": 200.0,
      "shortage": 300.0
    }
  ]
}
```
    - **Caso B — Primera sesión histórica del sistema (aún no existe ninguna sesión cerrada en BD):**
```json
{
  "status": true,
  "code": "OK",
  "message": "No existen turnos cerrados anteriores registrados.",
  "data": []
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`, `409 CASH_SESSION_CLOSED`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/turnos-anteriores?estado=cerrado` $\rightarrow$ `200 OK` con arreglo en `data`.
    - *Rechazo:* Vendedor con caja cerrada invoca `GET /api/caja/turnos-anteriores` $\rightarrow$ `409 CASH_SESSION_CLOSED`.

---

#### EP-13. Registrar ingreso manual o inyección de administrador
1. **Identificador y operación de la matriz:** `EP-13` — `OP-07` y `OP-14`.
2. **HU y acuerdo que lo justifican:** HU 15; Sección 6 ("Ingresos y egresos actuales") de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Registrar en la sesión propia abierta del vendedor un **ingreso manual** (`manual_income`, alias de entrada `manual`) o una **inyección de efectivo entregada por un administrador** (`admin_injection`), aumentando el efectivo esperado en `+amount`, incrementando `version_caja` e invalidando cualquier arqueo vigente previo. Identificar al administrador en una inyección **no solicita sus credenciales**.
4. **Método y ruta exacta:** `POST /api/caja/ingresos`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.movimientos`.
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:**
   - La sesión abierta de la caja física debe pertenecer al vendedor autenticado (`sesion_caja.id_usuario_vendedor === $this->authenticatedUserId`).
   - Si no hay sesión abierta: `409 CASH_SESSION_CLOSED`. Si la sesión abierta pertenece a otro vendedor: `403 SESSION_NOT_OWNED`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`; referencia en `X-Request-Reference` o cuerpo según sección 8.1.
   - Cuerpo JSON: `{ "referencia_solicitud", "type", "amount", "reason", "administrator_id"? }`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Campo canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `referencia_solicitud` | `request_id` | `string` | Sí | No | `16..64` caracteres `^[A-Za-z0-9_-]{16,64}$`. |
| `type` | — | `string` | Sí | No | `'manual_income'` (se admite alias `'manual'` y se normaliza a `'manual_income'`) o `'admin_injection'`. |
| `amount` | — | `number\|string` | Sí | No | Decimal exacto hasta 2 decimales, **$\ge 0.01$** y $\le 99999999.99$. |
| `reason` | — | `string` | Sí | No | Texto tras `trim()` de `1..250` caracteres (motivo con contenido). |
| `administrator_id` | — | `int\|null` | Condicional | Sí | **Obligatorio** ($\ge 1$, usuario activo con `id_rol = 1`) cuando `type === 'admin_injection'`. En `'manual_income'` es `null` (si se envía un valor no nulo en `'manual_income'`, se rechaza con `422 VALIDATION_ERROR`). |

10. **Validaciones y condiciones:**
    - No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - Sesión propia abierta (`409 CASH_SESSION_CLOSED` / `403 SESSION_NOT_OWNED`).
    - `type` válido (`422 INVALID_MOVEMENT_TYPE`).
    - `amount >= 0.01` con hasta 2 decimales (`422 INVALID_AMOUNT`) y $\le 99999999.99$ (`422 AMOUNT_OUT_OF_RANGE`).
    - `reason` con contenido no vacío tras `trim()` (`422 MOTIVE_REQUIRED`).
    - Si `type === 'admin_injection'`: `administrator_id` es obligatorio y debe corresponder en BD a un usuario con `id_rol = 1` y `estado = 1` (`422 ADMINISTRATOR_REQUIRED`).
11. **HTTP de éxito y JSON completo (`201 Created`, o `200 OK` en replay idempotente):**
```json
{
  "status": true,
  "code": "MOVEMENT_REGISTERED",
  "idempotent_replay": false,
  "message": "Ingreso registrado correctamente.",
  "data": {
    "result_code": "MOVEMENT_REGISTERED",
    "movement": {
      "id": 21,
      "session_id": 15,
      "type": "admin_injection",
      "label": "Inyección",
      "amount": 50.0,
      "current_amount": 50.0,
      "impact": 50.0,
      "effect": "Suma",
      "reason": "Sencillo entregado para cambio de turno mañana",
      "reference": "Sencillo entregado para cambio de turno mañana · Entregó: Superuser Demo",
      "administrator_id": 9,
      "administrator_name": "Superuser Demo",
      "responsible": "Alex Garcia",
      "recorded_at": "2026-11-12T09:15:00-05:00",
      "status": "Confirmado"
    },
    "balance": 240.0,
    "current_balance": 240.0,
    "version": 2
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`.
    - `409 CASH_SESSION_CLOSED`: No hay sesión abierta para el vendedor.
    - `409 BALANCE_OVERFLOW`: El nuevo saldo superaría el límite técnico de `DECIMAL(10,2)`.
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 AMBIGUOUS_ALIAS_VALUES`, `422 INVALID_MOVEMENT_TYPE`, `422 INVALID_AMOUNT`, `422 AMOUNT_OUT_OF_RANGE`, `422 MOTIVE_REQUIRED`, `422 ADMINISTRATOR_REQUIRED`.
13. **Efectos sobre saldo, estado, versión y arqueos:**
    - Incrementa `sesion_caja.total_ingresos` y `sesion_caja.efectivo_esperado_actual` en `+amount`.
    - Incrementa `sesion_caja.version_caja = version_caja + 1`.
    - Si existía un arqueo en estado `'Vigente'`, pasa automáticamente a `'Desactualizado'`.
14. **Concurrencia y bloqueos necesarios:**
    - Bloquea la fila de `sesion_caja` con `SELECT ... FOR UPDATE` antes de insertar el movimiento y actualizar el saldo.
15. **Idempotencia y recuperación:**
    - Protegido por `ControlSolicitudService` (`tipo_operacion = 'CAJA_INGRESO'`). Antes de calcular el hash, el alias `type = "manual"` se normaliza a `"manual_income"` y `request_id` a `referencia_solicitud`. Reintentos devuelven `200 OK` (`code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`).
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida (`admin_injection`):*
      ```json
{
  "referencia_solicitud": "am_ing_20261112_0001_a1b2c3d4",
  "type": "admin_injection",
  "amount": 50.0,
  "reason": "Sencillo entregado para cambio de turno mañana",
  "administrator_id": 9
}
      ```
    - *Rechazo por `admin_injection` sin `administrator_id`:* `422 ADMINISTRATOR_REQUIRED`.
    - *Rechazo por `amount: 0`:* `422 INVALID_AMOUNT`.

---

#### EP-14. Registrar salida de caja, retiro de administrador o retiro omitido del turno anterior
1. **Identificador y operación de la matriz:** `EP-14` — `OP-08` y `OP-14`.
2. **HU y acuerdo que lo justifican:** HU 16; Sección 6 ("Ingresos y egresos actuales" y "Retiro omitido del turno anterior") de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Registrar en la sesión propia abierta del vendedor uno de los tres tipos de salida/declaración de efectivo, **sin solicitar credenciales administrativas en ninguno de ellos**:
   - **Subtipo 1 — Salida de caja (`type = "cash_out"`):** Gasto operativo menor en efectivo. Descuenta `-amount` del efectivo esperado actual (`impact = -amount`), verificando que `amount <= current_balance` (un egreso igual al saldo disponible es válido y deja `current_balance = 0.00`).
   - **Subtipo 2 — Retiro de administrador (`type = "admin_withdrawal"`):** Entrega de efectivo de la sesión actual a un administrador activo (`administrator_id` obligatorio). Descuenta `-amount` del efectivo esperado actual (`impact = -amount`), verificando que `amount <= current_balance`.
   - **Subtipo 3 — Retiro omitido del turno anterior (`type = "omitted_withdrawal"`):** Documenta en la sesión actual un retiro de efectivo ocurrido mientras una sesión anterior (`origin_session_id`, cerrada) estaba abierta. Exige identificar `amount`, `reason`, `administrator_id` (administrador activo que recibió el efectivo), `origin_session_id` (sesión cerrada de origen) y **dos declaraciones explícitas y separadas**:
     1. `declared_occurred_in_open_origin_session = true`: que el retiro ocurrió mientras la sesión de origen estaba abierta.
     2. `confirmed_reflected_in_opening = true` (alias `confirm_previous_open`): que esa salida ya estaba reflejada en el efectivo contado al abrir la sesión actual.
     - **Efecto sobre el saldo actual y sobre la sesión anterior:** Con ambas declaraciones en `true`, se registra el movimiento vinculado a `origin_session_id` con **impacto cero (`impact = 0.00`, `effect = "Sin efecto: ya reflejado en la apertura"`)** sobre el saldo actual de la sesión, y **la sesión cerrada de origen conserva intactas todas sus cifras originales**. Si alguna de las dos declaraciones no es `true`, el servidor rechaza la solicitud con `422 CONFIRMATION_REQUIRED` (no inventa un descuento actual sobre la sesión entrante).
4. **Método y ruta exacta:** `POST /api/caja/egresos`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.movimientos`.
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:**
   - La sesión activa actual debe estar en estado `'ABIERTA'` y pertenecer a `$this->authenticatedUserId` (`409 CASH_SESSION_CLOSED` / `403 SESSION_NOT_OWNED`).
   - En `omitted_withdrawal`, `origin_session_id` debe existir cerrado antes de la apertura actual y pertenecer al conjunto elegible de EP-12/sección 5.4 (puede ser de otro vendedor relevado).
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`; referencia en `X-Request-Reference` o cuerpo según sección 8.1.
   - Cuerpo JSON: `{ "referencia_solicitud", "type", "amount", "reason", "administrator_id"?, "origin_session_id"?, "declared_occurred_in_open_origin_session"?, "confirmed_reflected_in_opening"? }`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Campo canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `referencia_solicitud` | `request_id` | `string` | Sí | No | `16..64` caracteres `^[A-Za-z0-9_-]{16,64}$`. |
| `type` | — | `string` | Sí | No | `'cash_out'`, `'admin_withdrawal'` o `'omitted_withdrawal'`. |
| `amount` | — | `number\|string` | Sí | No | Decimal exacto hasta 2 decimales, **$\ge 0.01$** y $\le 99999999.99$. En `'cash_out'` y `'admin_withdrawal'`, además `amount <= current_balance`. |
| `reason` | — | `string` | Sí | No | Texto tras `trim()` de `1..250` caracteres. |
| `administrator_id` | — | `int\|null` | Condicional | Sí | **Obligatorio** ($\ge 1$, usuario activo con `id_rol = 1`) en `'admin_withdrawal'` y `'omitted_withdrawal'`. `null` en `'cash_out'`. |
| `origin_session_id` | `origin_turn_id` | `int\|null` | Condicional | Sí | **Obligatorio** ($\ge 1$, sesión existente cerrada antes de apertura actual y dentro del conjunto de EP-12) cuando `type === 'omitted_withdrawal'`. `null` en los demás tipos. |
| `declared_occurred_in_open_origin_session` | — | `bool\|null` | Condicional | Sí | Obligatorio booleano `true` cuando `type === 'omitted_withdrawal'`. Ausencia, `null` o `false`: `422 CONFIRMATION_REQUIRED`; el adaptador enviará ambas declaraciones explícitamente. |
| `confirmed_reflected_in_opening` | `confirm_previous_open` | `bool\|null` | Condicional | Sí | **Obligatorio `true`** cuando `type === 'omitted_withdrawal'`. |

10. **Validaciones y condiciones:**
    - No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - Sesión propia `'ABIERTA'` (`409 CASH_SESSION_CLOSED` / `403 SESSION_NOT_OWNED`).
    - `type` en `['cash_out', 'admin_withdrawal', 'omitted_withdrawal']` (`422 INVALID_MOVEMENT_TYPE`).
    - `amount >= 0.01` con hasta 2 decimales (`422 INVALID_AMOUNT`) y $\le 99999999.99$ (`422 AMOUNT_OUT_OF_RANGE`).
    - `reason` no vacío (`422 MOTIVE_REQUIRED`).
    - En `admin_withdrawal` y `omitted_withdrawal`: `administrator_id` existe, tiene `id_rol = 1` y `estado = 1` (`422 ADMINISTRATOR_REQUIRED`).
    - En `cash_out` y `admin_withdrawal`: con `sesion_caja` bloqueada `FOR UPDATE`, verifica `amount <= efectivo_esperado_actual`. Si `amount > efectivo_esperado_actual`, rechaza con `409 INSUFFICIENT_BALANCE` devolviendo `data: { "current_balance": <saldo_actual> }`.
    - En `omitted_withdrawal`:
      - Verifica que `origin_session_id` esté cerrado antes de apertura actual y en conjunto elegible EP-12 (`422 ORIGIN_TURN_REQUIRED`).
      - Verifica que `confirmed_reflected_in_opening === true` y `declared_occurred_in_open_origin_session === true` (`422 CONFIRMATION_REQUIRED`).
11. **HTTP de éxito y JSON completo (`201 Created`, o `200 OK` en replay idempotente):**
    - **Caso A — Egreso con efecto en saldo actual (`admin_withdrawal` por `30.00`):**
```json
{
  "status": true,
  "code": "MOVEMENT_REGISTERED",
  "idempotent_replay": false,
  "message": "Egreso registrado correctamente.",
  "data": {
    "result_code": "MOVEMENT_REGISTERED",
    "movement": {
      "id": 22,
      "session_id": 15,
      "type": "admin_withdrawal",
      "label": "Retiro",
      "amount": 30.0,
      "current_amount": 30.0,
      "impact": -30.0,
      "effect": "Resta",
      "reason": "Retiro parcial de efectivo a bóveda",
      "reference": "Retiro parcial de efectivo a bóveda · Recibió: Superuser Demo",
      "administrator_id": 9,
      "administrator_name": "Superuser Demo",
      "origin_session_id": null,
      "declared_occurred_in_open_origin_session": null,
      "confirmed_reflected_in_opening": null,
      "responsible": "Alex Garcia",
      "recorded_at": "2026-11-12T10:20:00-05:00",
      "status": "Confirmado"
    },
    "balance": 210.0,
    "current_balance": 210.0,
    "version": 3
  }
}
```
    - **Caso B — Retiro omitido del turno anterior (`omitted_withdrawal` por `300.00`, impacto `0.00`):**
```json
{
  "status": true,
  "code": "MOVEMENT_REGISTERED",
  "idempotent_replay": false,
  "message": "Retiro omitido registrado correctamente sin alterar el saldo actual.",
  "data": {
    "result_code": "MOVEMENT_REGISTERED",
    "movement": {
      "id": 23,
      "session_id": 15,
      "type": "omitted_withdrawal",
      "label": "Retiro omitido",
      "amount": 300.0,
      "current_amount": 300.0,
      "impact": 0.0,
      "effect": "Sin efecto: ya reflejado en la apertura",
      "reason": "Retiro entregado anoche durante el turno tarde no anotado antes de cerrar",
      "reference": "Sesión #14 (2026-11-11 · Tarde) · Recibió: Superuser Demo · registrado hoy",
      "administrator_id": 9,
      "administrator_name": "Superuser Demo",
      "origin_session_id": 14,
      "declared_occurred_in_open_origin_session": true,
      "confirmed_reflected_in_opening": true,
      "responsible": "Alex Garcia",
      "recorded_at": "2026-11-12T10:35:00-05:00",
      "status": "Confirmado"
    },
    "balance": 210.0,
    "current_balance": 210.0,
    "version": 4
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`.
    - `409 CASH_SESSION_CLOSED`: No hay sesión abierta.
    - `409 INSUFFICIENT_BALANCE`: En `cash_out` o `admin_withdrawal`, `amount > current_balance` (devuelve `data: { "current_balance": 210.0 }`).
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 AMBIGUOUS_ALIAS_VALUES`, `422 INVALID_MOVEMENT_TYPE`, `422 INVALID_AMOUNT`, `422 AMOUNT_OUT_OF_RANGE`, `422 MOTIVE_REQUIRED`, `422 ADMINISTRATOR_REQUIRED`, `422 ORIGIN_TURN_REQUIRED`, `422 CONFIRMATION_REQUIRED`.
13. **Efectos sobre saldo, estado, versión y arqueos:**
    - En `cash_out` y `admin_withdrawal`: incrementa `sesion_caja.total_egresos` en `+amount` y reduce `sesion_caja.efectivo_esperado_actual` en `-amount`.
    - En `omitted_withdrawal`: `efectivo_esperado_actual` cambia en `0.00` (y la sesión `origin_session_id = 14` no sufre ninguna modificación en sus columnas).
    - En los tres subtipos: incrementa `sesion_caja.version_caja = version_caja + 1` y marca como `'Desactualizado'` cualquier arqueo `'Vigente'` previo de la sesión actual.
14. **Concurrencia y bloqueos necesarios:**
    - `SELECT ... FOR UPDATE` sobre la fila de `sesion_caja` activa para serializar egresos simultáneos e impedir que dos retiros paralelos dejen el saldo en negativo.
15. **Idempotencia y recuperación:**
    - Protegido por `ControlSolicitudService` (`tipo_operacion = 'CAJA_EGRESO'`). Reintentos con la misma `referencia_solicitud` devuelven `200 OK` (`code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`).
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida (`omitted_withdrawal`):*
      ```json
{
  "referencia_solicitud": "am_egr_20261112_0002_e5f6a7b8",
  "type": "omitted_withdrawal",
  "amount": 300.0,
  "reason": "Retiro entregado anoche durante el turno tarde no anotado antes de cerrar",
  "administrator_id": 9,
  "origin_session_id": 14,
  "declared_occurred_in_open_origin_session": true,
  "confirmed_reflected_in_opening": true
}
      ```
    - *Rechazo por saldo insuficiente (`current_balance = 210.00`, `type = "cash_out"`, `amount = 210.01`):* `409 INSUFFICIENT_BALANCE` con `data: { "current_balance": 210.0 }`.
    - *Aceptación por monto exactamente igual al saldo (`current_balance = 210.00`, `type = "cash_out"`, `amount = 210.00`):* `201 MOVEMENT_REGISTERED` con `current_balance: 0.0`.

---

#### EP-15. Consultar movimientos propios corregibles e historial de correcciones de la sesión activa
1. **Identificador y operación de la matriz:** `EP-15` — `OP-09`.
2. **HU y acuerdo que lo justifican:** HU 15 y HU 16; Sección 6 ("Correcciones de movimientos propios") de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Devolver al vendedor autenticado:
   1. `movements`: Los movimientos originales elegibles para corrección pertenecientes a su sesión `ABIERTA` actual y registrados por él mismo (`manual_income`, `admin_injection`, `cash_out`, `admin_withdrawal`), mostrando tanto su importe original inmutable (`amount`) como su importe actualmente efectivo (`current_amount`) tras cualquier corrección previa. **Excluye ventas POS (`cash_sale`), retiros omitidos (`omitted_withdrawal`) y registros de corrección (`correction`).**
   2. `corrections`: La cadena cronológica completa de correcciones vinculadas registradas en la sesión actual.
4. **Método y ruta exacta:** `GET /api/caja/movimientos/corregibles`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.movimientos`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - Exige que exista una sesión en estado `'ABIERTA'` cuyo `id_usuario_vendedor === $this->authenticatedUserId` (`409 CASH_SESSION_CLOSED` / `403 SESSION_NOT_OWNED`).
   - Si el cliente envía `?session_id=X` (o `sesion_id=X`) y `X` no coincide con el `id_sesion_caja` de la sesión abierta propia, responde `403 SESSION_NOT_OWNED`; no revela el estado de una sesión ajena.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** `session_id` (alias `sesion_id`) opcional en query string.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `session_id`: entero $\ge 1$ opcional.
10. **Validaciones y condiciones:** Sesión propia `'ABIERTA'`; no contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`). Orden: `ORDER BY fecha_hora_registro ASC, id_movimiento_caja ASC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Movimientos corregibles y correcciones obtenidos correctamente.",
  "data": {
    "session_id": 15,
    "movements": [
      {
        "id": 21,
        "type": "admin_injection",
        "label": "Inyección",
        "amount": 50.0,
        "current_amount": 30.0,
        "reference": "Sencillo entregado para cambio de turno mañana · Entregó: Superuser Demo",
        "reason": "Sencillo entregado para cambio de turno mañana",
        "recorded_at": "2026-11-12T09:15:00-05:00",
        "status": "Corregido"
      },
      {
        "id": 22,
        "type": "admin_withdrawal",
        "label": "Retiro",
        "amount": 30.0,
        "current_amount": 30.0,
        "reference": "Retiro parcial de efectivo a bóveda · Recibió: Superuser Demo",
        "reason": "Retiro parcial de efectivo a bóveda",
        "recorded_at": "2026-11-12T10:20:00-05:00",
        "status": "Confirmado"
      }
    ],
    "corrections": [
      {
        "id": 1,
        "original_movement_id": 21,
        "previous_correction_id": null,
        "original_reference": "Sencillo entregado para cambio de turno mañana · Entregó: Superuser Demo",
        "original_amount": 50.0,
        "previous_corrected_amount": 50.0,
        "corrected_amount": 30.0,
        "balance_effect": -20.0,
        "effect": "Resta S/ 20,00",
        "reason": "Se entregaron 3 billetes de S/ 10,00 (S/ 30,00) y por error se digitó S/ 50,00",
        "status": "Confirmada",
        "recorded_at": "2026-11-12T10:40:00-05:00",
        "responsible": "Alex Garcia"
      }
    ]
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`, `409 CASH_SESSION_CLOSED`, `422 AMBIGUOUS_ALIAS_VALUES`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/movimientos/corregibles?session_id=15` $\rightarrow$ `200 OK`.
    - *Rechazo:* `GET /api/caja/movimientos/corregibles?session_id=14` (sesión distinta de la activa propia) $\rightarrow$ `403 SESSION_NOT_OWNED`.

---

#### EP-16. Registrar corrección vinculada sobre un movimiento propio de la sesión activa
1. **Identificador y operación de la matriz:** `EP-16` — `OP-10` y `OP-14`.
2. **HU y acuerdo que lo justifican:** HU 15 y HU 16; Sección 6 ("Correcciones de movimientos propios") de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Corregir un ingreso o egreso manual propio de la sesión activa **sin editar ni eliminar la fila original** y **sin solicitar credenciales administrativas**, insertando un nuevo registro de corrección vinculado a `original_movement_id` (y eslabonado a `previous_correction_id` si hubo una corrección previa sobre el mismo movimiento original):
   - Sea $M_{\text{vigente}}$ el importe actualmente efectivo del movimiento original (`current_amount`, que inicialmente es igual a `original.amount` y tras una corrección previa es el `corrected_amount` del último eslabón) y sea $M_{\text{correcto}}$ el `corrected_amount` enviado ($\ge 0.00$):
     - Si el movimiento original es de ingreso (`manual_income`, `admin_injection`):
       $$\text{balance\_effect} = M_{\text{correcto}} - M_{\text{vigente}}$$
     - Si el movimiento original es de egreso (`cash_out`, `admin_withdrawal`):
       $$\text{balance\_effect} = M_{\text{vigente}} - M_{\text{correcto}}$$
   - De este modo, **el efecto se calcula siempre contra el importe actualmente efectivo ($M_{\text{vigente}}$) y nunca reaplica todo el importe original**.
4. **Método y ruta exacta:** `POST /api/caja/movimientos/correcciones`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.movimientos`.
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:**
   - La sesión debe estar en estado `'ABIERTA'` y pertenecer al vendedor autenticado (`sesion_caja.id_usuario_vendedor === $this->authenticatedUserId`).
   - El movimiento `original_movement_id` debe pertenecer a esa misma sesión abierta (`movimiento.id_sesion_caja === sesion_abierta.id_sesion_caja`) y haber sido registrado por el mismo vendedor (`movimiento.id_usuario_registro === $this->authenticatedUserId`).
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`; referencia en `X-Request-Reference` o cuerpo según sección 8.1.
   - Cuerpo JSON: `{ "referencia_solicitud", "session_id"?, "original_movement_id", "corrected_amount", "reason" }`.
   - **Prohibición de credenciales administrativas:** No se exigen ni validan `admin_username`, `admin_email` ni `admin_password`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Campo canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `referencia_solicitud` | `request_id` | `string` | Sí | No | `16..64` caracteres `^[A-Za-z0-9_-]{16,64}$`. |
| `session_id` | `sesion_id` | `int\|null` | No | Sí | Entero $\ge 1$. Si se envía, debe coincidir con la sesión abierta propia (`403 SESSION_NOT_OWNED` / `409 CASH_SESSION_CLOSED`). |
| `original_movement_id` | — | `int` | Sí | No | Entero $\ge 1$. ID del movimiento original elegible en la sesión activa. |
| `corrected_amount` | — | `number\|string` | Sí | No | Decimal exacto hasta 2 decimales, **$\ge 0.00$** y $\le 99999999.99$. Debe ser distinto del `current_amount` vigente del movimiento (`corrected_amount != current_amount`). |
| `reason` | — | `string` | Sí | No | Texto tras `trim()` de `1..250` caracteres identificando el error y el motivo de la corrección. |

10. **Validaciones y condiciones:**
    - No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - Sesión propia `'ABIERTA'` (`409 CASH_SESSION_CLOSED` / `403 SESSION_NOT_OWNED`).
    - `original_movement_id` existe (`404 MOVEMENT_NOT_FOUND`), pertenece a la sesión abierta y al vendedor autenticado (`403 SESSION_NOT_OWNED` si es ajeno, `409 CASH_SESSION_CLOSED` si es propio cerrado).
    - El movimiento original es de tipo elegible (`manual_income`, `admin_injection`, `cash_out`, `admin_withdrawal`); si es `cash_sale`, `omitted_withdrawal` o `correction`, se rechaza con `409 CORRECTION_NOT_ALLOWED`.
    - `corrected_amount >= 0.00` con hasta 2 decimales (`422 INVALID_AMOUNT`), $\le 99999999.99$ (`422 AMOUNT_OUT_OF_RANGE`) y `corrected_amount != current_amount` (`422 NO_CORRECTION_CHANGE`).
    - `reason` no vacío (`422 MOTIVE_REQUIRED`).
    - Si `balance_effect < 0` y `efectivo_esperado_actual + balance_effect < 0.00`, se rechaza con `409 INSUFFICIENT_BALANCE` devolviendo `data: { "current_balance": <efectivo_esperado_actual> }`.
11. **HTTP de éxito y JSON completo (`201 Created`, o `200 OK` en replay idempotente):**
    - *Ejemplo de segunda corrección sucesiva sobre el movimiento `#21` (original `50.00` $\rightarrow$ primera corrección a `30.00` $\rightarrow$ segunda corrección a `35.00`, por lo que `previous_corrected_amount = 30.0` y `balance_effect = +5.0`):*
```json
{
  "status": true,
  "code": "CORRECTION_SAVED",
  "idempotent_replay": false,
  "message": "Corrección vinculada registrada correctamente.",
  "data": {
    "result_code": "CORRECTION_SAVED",
    "correction": {
      "id": 2,
      "original_movement_id": 21,
      "previous_correction_id": 1,
      "original_reference": "Sencillo entregado para cambio de turno mañana · Entregó: Superuser Demo",
      "original_amount": 50.0,
      "previous_corrected_amount": 30.0,
      "corrected_amount": 35.0,
      "balance_effect": 5.0,
      "effect": "Suma S/ 5,00",
      "reason": "Se verificó moneda adicional de S/ 5,00 en la bolsa de sencillo entregada",
      "status": "Confirmada",
      "recorded_at": "2026-11-12T10:48:00-05:00",
      "responsible": "Alex Garcia"
    },
    "original_movement": {
      "id": 21,
      "type": "admin_injection",
      "label": "Inyección",
      "amount": 50.0,
      "current_amount": 35.0,
      "status": "Corregido"
    },
    "balance": 195.0,
    "current_balance": 195.0,
    "version": 6
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`.
    - `404 MOVEMENT_NOT_FOUND`: El `original_movement_id` no existe.
    - `409 CASH_SESSION_CLOSED`: La sesión está cerrada.
    - `409 CORRECTION_NOT_ALLOWED`: Se intentó corregir una venta POS, un retiro omitido o un registro de corrección.
    - `409 INSUFFICIENT_BALANCE`: El ajuste reductor dejaría el efectivo esperado en negativo (`data.current_balance`).
    - `409 BALANCE_OVERFLOW`: El ajuste superaría el límite técnico de `DECIMAL(10,2)`.
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 AMBIGUOUS_ALIAS_VALUES`, `422 INVALID_AMOUNT`, `422 AMOUNT_OUT_OF_RANGE`, `422 NO_CORRECTION_CHANGE`, `422 MOTIVE_REQUIRED`.
13. **Efectos sobre saldo, estado, versión y arqueos:**
    - Conserva intacto `movimiento_caja.monto` del registro original (`50.00`), no actualiza la fila original: obtiene `current_amount = 35.00` de la última corrección confirmada, e inserta el registro de corrección vinculado al original (`id_movimiento_original = 21`) y al eslabón anterior (`id_correccion_previa = 1`), ajusta el acumulado neto de ingresos o egresos y `efectivo_esperado_actual` en `balance_effect` (`+5.00`), incrementa `version_caja = version_caja + 1` y pasa a `'Desactualizado'` cualquier arqueo `'Vigente'` previo.
14. **Concurrencia y bloqueos necesarios:**
    - Toma `SELECT ... FOR UPDATE` sobre `sesion_caja` y luego `SELECT ... FOR UPDATE` sobre la fila original en `movimiento_caja`. Así, dos correcciones concurrentes sobre el mismo movimiento se serializan y la segunda calcula su efecto sobre el `current_amount` derivado de la corrección confirmada por la primera (o es rechazada con `422 NO_CORRECTION_CHANGE` si enviaba el mismo `corrected_amount`).
15. **Idempotencia y recuperación:**
    - Protegido por `ControlSolicitudService` (`tipo_operacion = 'CAJA_CORRECCION'`). Reintentos con la misma `referencia_solicitud` devuelven `200 OK` (`code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`).
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida:*
      ```json
{
  "referencia_solicitud": "am_corr_20261112_0001_f1e2d3c4",
  "session_id": 15,
  "original_movement_id": 21,
  "corrected_amount": 35.0,
  "reason": "Se verificó moneda adicional de S/ 5,00 en la bolsa de sencillo entregada"
}
      ```
    - *Rechazo por intentar corregir un retiro omitido (`id = 23`):* `409 CORRECTION_NOT_ALLOWED`.
    - *Rechazo por enviar el mismo importe que ya está vigente (`corrected_amount = 35.00` otra vez):* `422 NO_CORRECTION_CHANGE`.

---

### D. Arqueo de Caja y Cierre de Turno con Regla de 15 Minutos (HU 12, HU 13 y HU 14)

---

#### EP-17. Consultar estado de arqueo, totales del sistema, historial de arqueos y evaluación horaria de cierre
1. **Identificador y operación de la matriz:** `EP-17` — `OP-11`.
2. **HU y acuerdo que lo justifican:** HU 12, HU 13 y HU 14; Secciones 7 y 8 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Proveer a la vista `/Caja/arqueo` (`CajaArqueoService.getState()`) en una sola lectura consistente:
   1. El desglose financiero exacto de la sesión propia (`opening_fund`, `cash_sales`, `income`, `expenses`, `expected`, y por separado `card`, `yape_plin`), donde:
      $$\text{expected} = \text{opening\_fund} + \text{cash\_sales} + \text{income} - \text{expenses}$$
      *(Las ventas con `card` y `yape_plin`, así como los registros de `omitted_withdrawal` con impacto `0.00`, no alteran `expected`).*
   2. La `version` actual de la sesión (`version_caja`).
   3. El historial cronológico completo de arqueos guardados en la sesión (`history`), con sus identificadores reales de BD y su estado (`'Vigente'`, `'Reemplazado'` o `'Desactualizado'`).
   4. La evaluación en vivo de la **regla de los 15 minutos** (`schedule`) calculada por el servidor contra el `reference_schedule.end` (`hora_fin_referencia`) guardado en la sesión en el momento de su apertura.
4. **Método y ruta exacta:** `GET /api/caja/arqueo/estado`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.arqueo`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - Si existe una sesión `'ABIERTA'` cuyo `id_usuario_vendedor === $this->authenticatedUserId`: devuelve `is_open: true`, `is_owner: true` y todos sus totales y arqueos.
   - Si existe una sesión `'ABIERTA'` de otro vendedor: devuelve `is_open: true`, `is_owner: false`, ocultando todos los importes y arqueos ajenos.
   - Si la caja está cerrada: devuelve `is_open: false`, `is_owner: false`, identificadores, cifras y `schedule` en `null`, `history: []`, `has_valid_arqueo: false`, `active_arqueo_id: null` y `closed_summary: null`. El cierre se recupera exclusivamente mediante EP-21 y su referencia de operación.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Ninguno.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Sin campos de entrada.
10. **Validaciones y condiciones:** Usuario activo con `id_rol === 2` y permiso `turnos.arqueo`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Estado de arqueo obtenido correctamente.",
  "data": {
    "is_open": true,
    "is_owner": true,
    "session_id": 15,
    "seller_id": 4,
    "seller": "Alex Garcia",
    "turn": "Mañana",
    "reference_schedule": {
      "shift_id": 1,
      "shift_name": "Mañana",
      "start": "07:00",
      "end": "12:00",
      "label": "Mañana · 07:00–12:00"
    },
    "opening_fund": 190.0,
    "cash_sales": 60.0,
    "income": 35.0,
    "expenses": 30.0,
    "expected": 255.0,
    "card": 45.0,
    "yape_plin": 20.0,
    "omitted_withdrawals_zero_impact_total": 300.0,
    "version": 6,
    "active_arqueo_id": 8,
    "has_valid_arqueo": true,
    "history": [
      {
        "id": 7,
        "session_id": 15,
        "date": "2026-11-12",
        "time": "10:10",
        "recorded_at": "2026-11-12T10:10:05-05:00",
        "expected": 300.0,
        "counted": 300.0,
        "difference": 0.0,
        "observation": null,
        "status": "Desactualizado",
        "version": 5
      },
      {
        "id": 8,
        "session_id": 15,
        "date": "2026-11-12",
        "time": "11:43",
        "recorded_at": "2026-11-12T11:43:12-05:00",
        "expected": 255.0,
        "counted": 250.0,
        "difference": -5.0,
        "observation": "Falta moneda de S/ 5,00 en sencillo",
        "status": "Vigente",
        "version": 6
      }
    ],
    "schedule": {
      "scheduled_end": "12:00",
      "current_time": "11:46",
      "tolerance_minutes": 15,
      "early_threshold": "11:45",
      "minutes_remaining_to_end": 14,
      "requires_authorization": false,
      "reason": null
    },
    "closed_summary": null
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/arqueo/estado` $\rightarrow$ `200 OK`.
    - *Rechazo:* Administrador (`id_rol = 1`) invoca `GET /api/caja/arqueo/estado` $\rightarrow$ `403 FORBIDDEN`.

---

#### EP-18. Listar historial de arqueos de la sesión propia
1. **Identificador y operación de la matriz:** `EP-18` — `OP-11`.
2. **HU y acuerdo que lo justifican:** HU 12; Sección 7 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Devolver el historial cronológico completo de arqueos persistidos por el servidor para la sesión propia del vendedor (`CajaArqueoService.getArqueos(sesionId)`), indicando el estado actual de cada arqueo (`'Vigente'`, `'Reemplazado'` o `'Desactualizado'`).
4. **Método y ruta exacta:** `GET /api/caja/arqueos?session_id={id}`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.arqueo`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - La sesión consultada debe pertenecer a `$this->authenticatedUserId` (`sesion_caja.id_usuario_vendedor === $this->authenticatedUserId`) y ser su sesión actualmente `'ABIERTA'`. Una sesión propia cerrada se rechaza con `409 CASH_SESSION_CLOSED`; EP-21 recupera el resultado del cierre. Si pertenece a otro vendedor: `403 SESSION_NOT_OWNED`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Query param: `session_id` (alias admitido: `sesion_id`, opcional; si se omite usa la sesión abierta propia).
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `session_id` (`sesion_id`): entero $\ge 1$ opcional.
10. **Validaciones y condiciones:** No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`); titularidad de la sesión (`403 SESSION_NOT_OWNED`). Orden: `ORDER BY fecha_hora_arqueo ASC, id_arqueo_caja ASC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Historial de arqueos obtenido correctamente.",
  "data": {
    "session_id": 15,
    "version": 6,
    "active_arqueo_id": 8,
    "history": [
      {
        "id": 7,
        "session_id": 15,
        "date": "2026-11-12",
        "time": "10:10",
        "recorded_at": "2026-11-12T10:10:05-05:00",
        "expected": 300.0,
        "counted": 300.0,
        "difference": 0.0,
        "observation": null,
        "status": "Desactualizado",
        "version": 5
      },
      {
        "id": 8,
        "session_id": 15,
        "date": "2026-11-12",
        "time": "11:43",
        "recorded_at": "2026-11-12T11:43:12-05:00",
        "expected": 255.0,
        "counted": 250.0,
        "difference": -5.0,
        "observation": "Falta moneda de S/ 5,00 en sencillo",
        "status": "Vigente",
        "version": 6
      }
    ]
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`, `404 CASH_SESSION_NOT_FOUND`, `409 CASH_SESSION_CLOSED`, `422 AMBIGUOUS_ALIAS_VALUES`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/arqueos?session_id=15` $\rightarrow$ `200 OK`.
    - *Rechazo:* Vendedor `id=4` invoca `GET /api/caja/arqueos?session_id=14` (sesión de María `id=6`) $\rightarrow$ `403 SESSION_NOT_OWNED`.

---

#### EP-19. Guardar arqueo de efectivo sobre la sesión propia abierta
1. **Identificador y operación de la matriz:** `EP-19` — `OP-11` y `OP-14`.
2. **HU y acuerdo que lo justifican:** HU 12; Sección 7 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Persistir un nuevo arqueo de efectivo sobre la sesión propia `'ABIERTA'` del vendedor autenticado, verificando que la `version` enviada coincida con la `version_caja` actual de la sesión, calculando en el servidor `difference = counted - expected`, exigiendo `observation` con contenido cuando `difference != 0.00`, dejando como `'Reemplazado'` al anterior que estuviera vigente y conservando los ya desactualizados (sin borrarlos) y registrando el nuevo arqueo con estado `'Vigente'` e identificador real de base de datos.
4. **Método y ruta exacta:** `POST /api/caja/arqueo`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.arqueo`.
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:**
   - La sesión debe estar en estado `'ABIERTA'` y pertenecer a `$this->authenticatedUserId` (`409 CASH_SESSION_CLOSED` / `403 SESSION_NOT_OWNED`).
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`; referencia en `X-Request-Reference` o cuerpo según sección 8.1.
   - Cuerpo JSON: `{ "referencia_solicitud", "counted", "observation"?, "version" }`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Campo canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `referencia_solicitud` | `request_id` | `string` | Sí | No | `16..64` caracteres `^[A-Za-z0-9_-]{16,64}$`. |
| `counted` | — | `number\|string` | Sí | No | Decimal exacto hasta 2 decimales, **$\ge 0.00$** y $\le 99999999.99$. |
| `observation` | — | `string\|null` | Condicional | Sí | Texto `0..500` caracteres tras `trim()`. **Obligatorio con contenido no vacío (`1..500` caracteres)** cuando `counted != expected` (`abs(counted - expected) >= 0.01`). |
| `version` | — | `int` | Sí | No | Entero $\ge 1$. Debe coincidir exactamente con `sesion_caja.version_caja` actual. |

10. **Validaciones y condiciones:**
    - No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - Sesión propia en estado `'ABIERTA'` (`409 CASH_SESSION_CLOSED` / `403 SESSION_NOT_OWNED`).
    - `counted >= 0.00` con máximo 2 decimales (`422 INVALID_AMOUNT`) y $\le 99999999.99$ (`422 AMOUNT_OUT_OF_RANGE`).
    - Con `sesion_caja` bloqueada `FOR UPDATE`, verifica `version === (int)$sesion['version_caja']`. Si difiere (porque entró una venta o movimiento mientras el vendedor digitaba), rechaza con **`409 CASH_CHANGED`** devolviendo en `data` el nuevo `expected` y la nueva `version`.
    - Calcula `difference` restando los céntimos exactos de `counted` y `expected`, sin redondear entradas inválidas. Si `difference != 0.00` y `trim((string)$observation) === ''`, rechaza con **`422 OBSERVATION_REQUIRED`**.
11. **HTTP de éxito y JSON completo (`201 Created`, o `200 OK` en replay idempotente):**
```json
{
  "status": true,
  "code": "ARQUEO_SAVED",
  "idempotent_replay": false,
  "message": "Arqueo guardado correctamente.",
  "data": {
    "result_code": "ARQUEO_SAVED",
    "arqueo": {
      "id": 8,
      "session_id": 15,
      "date": "2026-11-12",
      "time": "11:43",
      "recorded_at": "2026-11-12T11:43:12-05:00",
      "expected": 255.0,
      "counted": 250.0,
      "difference": -5.0,
      "observation": "Falta moneda de S/ 5,00 en sencillo",
      "status": "Vigente",
      "version": 6
    },
    "expected": 255.0,
    "version": 6,
    "history": [
      {
        "id": 7,
        "session_id": 15,
        "date": "2026-11-12",
        "time": "10:10",
        "recorded_at": "2026-11-12T10:10:05-05:00",
        "expected": 300.0,
        "counted": 300.0,
        "difference": 0.0,
        "observation": null,
        "status": "Desactualizado",
        "version": 5
      },
      {
        "id": 8,
        "session_id": 15,
        "date": "2026-11-12",
        "time": "11:43",
        "recorded_at": "2026-11-12T11:43:12-05:00",
        "expected": 255.0,
        "counted": 250.0,
        "difference": -5.0,
        "observation": "Falta moneda de S/ 5,00 en sencillo",
        "status": "Vigente",
        "version": 6
      }
    ]
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`.
    - `409 CASH_SESSION_CLOSED`: La sesión ya está cerrada.
    - `409 CASH_CHANGED`: La versión de la sesión cambió antes de guardar el arqueo (`data: { "expected": 270.0, "version": 7 }`).
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 AMBIGUOUS_ALIAS_VALUES`, `422 INVALID_AMOUNT`, `422 AMOUNT_OUT_OF_RANGE`, `422 OBSERVATION_REQUIRED`.
13. **Efectos sobre saldo, estado, versión y arqueos:**
    - No modifica `efectivo_esperado_actual` ni incrementa `version_caja`.
    - Pasa los arqueos previos no desactualizados de la sesión a `'Reemplazado'`, inserta el nuevo arqueo con `estado = 'Vigente'` y `version_caja_evaluada = sesion_caja.version_caja`, y actualiza `sesion_caja.id_ultimo_arqueo = id`.
14. **Concurrencia y bloqueos necesarios:**
    - Bloquea `sesion_caja` con `SELECT ... FOR UPDATE` para verificar que `version` coincida exactamente con `version_caja` y que ninguna venta o movimiento esté modificando el saldo esperado al mismo tiempo.
15. **Idempotencia y recuperación:**
    - Protegido por `ControlSolicitudService` (`tipo_operacion = 'CAJA_ARQUEO'`). Reintentos con la misma `referencia_solicitud` devuelven `200 OK` (`code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`) sin insertar un segundo arqueo.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida:*
      ```json
{
  "referencia_solicitud": "am_arq_20261112_0001_9a8b7c6d",
  "counted": 250.0,
  "observation": "Falta moneda de S/ 5,00 en sencillo",
  "version": 6
}
      ```
    - *Rechazo por diferencia distinta de cero sin observación (`counted: 250.00`, `expected: 255.00`, `observation: ""`):* `422 OBSERVATION_REQUIRED`.
    - *Rechazo por versión desactualizada (`version: 5` cuando `version_caja` es `6`):* `409 CASH_CHANGED` con `data: { "expected": 255.0, "version": 6 }`.

---

#### EP-20. Confirmar cierre definitivo de sesión de caja (con autorización condicional antes de 15 minutos previos a la salida)
1. **Identificador y operación de la matriz:** `EP-20` — `OP-12`, `OP-13` y `OP-14`.
2. **HU y acuerdo que lo justifican:** HU 13 y HU 14; Sección 8 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Confirmar de manera atómica e irreversible el único cierre definitivo de la sesión propia `'ABIERTA'` del vendedor autenticado, verificando que el `arqueo_id` indicado exista en BD, pertenezca a la sesión y siga `'Vigente'` (`arqueo.version_caja_evaluada === sesion_caja.version_caja`), y aplicando la **regla exacta de los 15 minutos** sobre el instante de salida $T_{\text{salida}}$ del turno asociado a la sesión en el momento de su apertura (`America/Lima`):
   - **Caso 1 — Antes de los 15 minutos previos a la salida ($T_{\text{actual}} < T_{\text{salida}} - 15\text{ min}$, ej. hasta las `11:44:59` para una salida a las `12:00:00`):**
     - **Requiere autorización administrativa.**
     - Si la solicitud no incluye credenciales de administrador (`admin_email` y `admin_password` ausentes o nulos), el servidor **no cierra la sesión** y responde **`409 AUTHORIZATION_REQUIRED`** con `data.schedule` para que la interfaz abra el modal de autorización de cierre anticipado.
     - Si la solicitud incluye credenciales, el servidor exige `admin_email`, `admin_password` y `exception_reason` con contenido (`1..250` caracteres), valida mediante `AuthService::verificarCredencialesAdministradorPorEmail()` (sin llamar a `AuthService::login()` y **sin modificar ni regenerar `$_SESSION` del vendedor**) que `admin_email` pertenezca a un usuario en BD con `id_rol = 1`, `estado = 1` e `id_usuario !== $this->authenticatedUserId`, verifica `password_verify()`, y guarda conjuntamente en la misma transacción el registro en `autorizacion_caja` (sin guardar jamás la contraseña) y el cierre definitivo de la sesión.
   - **Caso 2 — Desde el instante exacto de los 15 minutos previos a la salida y cualquier hora posterior ($T_{\text{actual}} \ge T_{\text{salida}} - 15\text{ min}$, ej. `11:45:00`, `12:00:00` o `13:30:00` para una salida a las `12:00:00`):**
     - **No requiere credenciales administrativas ni motivo de excepción** (no existe límite posterior de 30 minutos).
4. **Método y ruta exacta:** `POST /api/caja/cerrar`.
5. **Rol y permisos concretos:**
   - **Solicitante autenticado en sesión (`$_SESSION['idUser']`):** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.cerrar`.
   - **Autorizador en cuerpo (solo cuando $T_{\text{actual}} < T_{\text{salida}} - 15\text{ min}$):** Usuario activo en BD con `id_rol = 1` (Administrador) e `id_usuario !== $this->authenticatedUserId`.
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:**
   - La sesión debe pertenecer a `$this->authenticatedUserId` (`403 SESSION_NOT_OWNED`).
   - La sesión debe estar `'ABIERTA'` (`409 CASH_SESSION_CLOSED`, salvo que se trate de la recuperación idempotente del cierre propio ya confirmado con la misma `referencia_solicitud`).
   - El `arqueo_id` enviado debe ser un entero positivo persistido en `arqueo_caja` para esa sesión, estar en estado `'Vigente'` y tener `version_caja_evaluada === sesion_caja.version_caja` (`409 ARQUEO_MISSING` si no existe arqueo o `409 ARQUEO_STALE` si quedó desactualizado o reemplazado). **La autorización administrativa nunca permite saltarse el requisito de arqueo vigente.**
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Cabeceras: `Content-Type: application/json`, `X-CSRF-Token`; referencia en `X-Request-Reference` o cuerpo según sección 8.1.
   - Cuerpo JSON: `{ "referencia_solicitud", "arqueo_id", "admin_email"?, "admin_password"?, "exception_reason"? }`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**

| Campo canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `referencia_solicitud` | `request_id` | `string` | Sí | No | `16..64` caracteres `^[A-Za-z0-9_-]{16,64}$`. |
| `arqueo_id` | — | `int` | Sí | No | Entero $\ge 1$. ID real en `arqueo_caja` del arqueo `'Vigente'` de la sesión. (No se aceptan IDs locales ficticios). |
| `admin_email` | — | `string\|null` | Condicional | Sí | **Obligatorio** si $T_{\text{actual}} < T_{\text{salida}} - 15\text{ min}$. Correo electrónico válido (`1..150` chars, `FILTER_VALIDATE_EMAIL`) de un Administrador activo. **No se acepta `admin_username`.** |
| `admin_password` | — | `string\|null` | Condicional | Sí | **Obligatorio** si $T_{\text{actual}} < T_{\text{salida}} - 15\text{ min}$. Contraseña en texto plano (`1..128` chars) validada en memoria con `password_verify()` y **jamás almacenada ni devuelta**. |
| `exception_reason` | — | `string\|null` | Condicional | Sí | **Obligatorio** si $T_{\text{actual}} < T_{\text{salida}} - 15\text{ min}$. Texto tras `trim()` de `1..250` caracteres (motivo con contenido). |

10. **Validaciones y condiciones (orden estricto en servidor):**
    1. Verificar no contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`) y formato de `referencia_solicitud`.
    2. Consultar primero en `ControlSolicitudService` si `(id_usuario, 'CAJA_CERRAR', referencia_solicitud)` ya está `'CONFIRMADA'` (ver punto 15: esto recupera el cierre propio confirmado antes de rechazar por `CASH_SESSION_CLOSED`).
    3. Con bloqueo `SELECT ... FOR UPDATE` sobre `sesion_caja`:
       - Verificar titularidad (`403 SESSION_NOT_OWNED`) y que `estado === 'ABIERTA'` (`409 CASH_SESSION_CLOSED`).
       - Verificar que `arqueo_id` sea un entero válido perteneciente a la sesión (`409 ARQUEO_MISSING` si no existe) y que su `estado === 'Vigente'` y `version_caja_evaluada === (int)$sesion['version_caja']` (`409 ARQUEO_STALE` si hubo ventas/movimientos posteriores o fue reemplazado).
    4. Evaluar horario en `America/Lima` contra `fecha_hora_salida_referencia` completa de la sesión (no contra una hora reubicada al día actual):
       - Si $T_{\text{actual}} < T_{\text{salida}} - 15\text{ min}$:
         - Si `admin_email` y `admin_password` no fueron enviados: abortar sin cerrar y devolver `409 AUTHORIZATION_REQUIRED` con `data.schedule`.
         - Si `trim((string)$exception_reason) === ''`: devolver `422 MOTIVE_REQUIRED`.
         - Si `admin_email` no tiene formato de correo válido: devolver `422 INVALID_ADMIN_EMAIL`.
         - Validar en BD al autorizador por `LOWER(TRIM(email))`: si no existe, `estado !== 1`, `id_rol !== 1`, `id_usuario === $this->authenticatedUserId` o `password_verify()` es `false`, abortar sin cerrar la sesión y sin destruir `$_SESSION` del vendedor, devolviendo **`403 INVALID_ADMIN_CREDENTIALS`**.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "CLOSE_CONFIRMED",
  "idempotent_replay": false,
  "message": "Cierre de sesión de caja confirmado correctamente.",
  "data": {
    "result_code": "CLOSE_CONFIRMED",
    "session_id": 15,
    "status": "Cerrada",
    "seller_id": 4,
    "seller": "Alex Garcia",
    "turn": "Mañana",
    "reference_schedule": {
      "shift_id": 1,
      "shift_name": "Mañana",
      "start": "07:00",
      "end": "12:00",
      "label": "Mañana · 07:00–12:00"
    },
    "opened_at": "2026-11-12T07:04:12-05:00",
    "closed_at": "2026-11-12T11:44:10-05:00",
    "arqueo_id": 8,
    "summary": {
      "opening_fund": 190.0,
      "opening_difference": -10.0,
      "cash_sales": 60.0,
      "card": 45.0,
      "yape_plin": 20.0,
      "income": 35.0,
      "expenses": 30.0,
      "expected": 255.0,
      "counted": 250.0,
      "difference": -5.0,
      "observation": "Falta moneda de S/ 5,00 en sencillo",
      "closed_at": "2026-11-12T11:44:10-05:00"
    },
    "authorization": {
      "required": true,
      "id": 1,
      "administrator_id": 9,
      "administrator_name": "Superuser Demo",
      "exception_reason": "Cita médica urgente del vendedor",
      "scheduled_end": "12:00",
      "actual_close_time": "11:44",
      "minutes_before_end": 16,
      "authorized_at": "2026-11-12T11:44:10-05:00"
    }
  }
}
```
    *(Cuando el cierre se confirma desde `salida - 15 min` en adelante, `authorization` es `null`).*
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`: La sesión del vendedor en `$_SESSION` no está activa.
    - `403 CSRF_INVALID`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`.
    - `403 INVALID_ADMIN_CREDENTIALS`: Las credenciales del administrador autorizador para el cierre anticipado son inválidas, el usuario no es Administrador activo o se intentó autoautorización. **No cierra la sesión del vendedor.**
    - `409 CASH_SESSION_CLOSED`: La sesión ya se encontraba cerrada por otra solicitud distinta.
    - `409 ARQUEO_MISSING`: No existe un arqueo persistido en la sesión.
    - `409 ARQUEO_STALE`: El `arqueo_id` está `'Desactualizado'` o `'Reemplazado'` porque `version_caja` cambió.
    - `409 AUTHORIZATION_REQUIRED`: El cierre ocurre antes de `salida - 15 min` y no se enviaron credenciales de administrador; devuelve `data: { "schedule": { "scheduled_end": "12:00", "current_time": "11:44", "early_threshold": "11:45", "requires_authorization": true } }`.
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 AMBIGUOUS_ALIAS_VALUES`, `422 INVALID_ADMIN_EMAIL`, `422 MOTIVE_REQUIRED`.
13. **Efectos sobre saldo, estado, versión y arqueos:**
    - Cambia `sesion_caja.estado` a `'CERRADA'`, registra `fecha_hora_cierre`, `id_arqueo_cierre`, congela `efectivo_esperado_cierre`, `efectivo_contado_cierre` (`counted`, que será el `reference_amount` de la próxima apertura) y `diferencia_cierre`, e incrementa `version_caja = version_caja + 1`.
    - Si requirió autorización anticipada, inserta en la misma transacción la fila en `autorizacion_caja`.
    - Bloquea inmediatamente cualquier nueva venta POS, movimiento, corrección o arqueo sobre esa sesión.
14. **Concurrencia y bloqueos necesarios:**
    - Toma `SELECT ... FOR UPDATE` sobre `sesion_caja`.
    - **Venta contra cierre:** Si `VentaService::procesarVenta()` confirmó una venta un instante antes, incrementó `sesion_caja.version_caja`, por lo que `EP-20` detecta `arqueo.version_caja_evaluada !== sesion_caja.version_caja` y rechaza el cierre con `409 ARQUEO_STALE`. Si `EP-20` confirma el cierre primero, `sesion_caja.estado` pasa a `'CERRADA'` y la venta concurrente es rechazada con `409 CASH_SESSION_CLOSED` antes de descontar stock o insertar en Kardex.
    - **Cierre contra cierre:** Serializado por `GET_LOCK` de `ControlSolicitudService` (si tienen la misma `referencia_solicitud`) y por `SELECT ... FOR UPDATE` sobre `sesion_caja` (si tienen distinta referencia).
15. **Idempotencia y recuperación:**
    - Protegido por `ControlSolicitudService` (`tipo_operacion = 'CAJA_CERRAR'`).
    - **Exclusión obligatoria de secretos:** `admin_password` se excluye de `calcularHashPayload()` y **nunca** se persiste en `control_solicitud_idempotencia`.
    - Si el cierre fue confirmado en BD pero se perdió la respuesta HTTP, al reintentar con la misma `referencia_solicitud` `ControlSolicitudService` recupera y devuelve `200 OK` (`code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`) con el objeto `data` completo del cierre confirmado **antes** de evaluar si la sesión sigue `'ABIERTA'`.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida a las `11:45:00` (salida `12:00:00`, sin credenciales):*
      ```json
{
  "referencia_solicitud": "am_cie_20261112_0001_c1d2e3f4",
  "arqueo_id": 8
}
      ```
    - *Solicitud válida a las `11:44:10` (salida `12:00:00`, con autorización de administrador):*
      ```json
{
  "referencia_solicitud": "am_cie_20261112_0002_a9b8c7d6",
  "arqueo_id": 8,
  "admin_email": "superuser.demo@amazonmarket.local",
  "admin_password": "ClaveFicticiaAdmin#2026",
  "exception_reason": "Cita médica urgente del vendedor"
}
      ```
    - *Rechazo a las `11:44:10` sin credenciales:* `409 AUTHORIZATION_REQUIRED`.
    - *Rechazo a las `11:40:00` con credenciales válidas pero `arqueo_id` desactualizado:* `409 ARQUEO_STALE` (no cierra la sesión ni guarda la autorización).

---

#### EP-21. Recuperar resumen del cierre confirmado de una sesión propia (`getCierre`)
1. **Identificador y operación de la matriz:** `EP-21` — `OP-14`.
2. **HU y acuerdo que lo justifican:** HU 13; Sección 2 y Sección 8 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Permitir a la pantalla `/Caja/arqueo` del vendedor (`CajaArqueoService.getCierre(sesionId)`) verificar y recuperar el resumen del cierre confirmado de su propia sesión tras confirmar el cierre o tras una caída de conexión, sin habilitar un historial general de sesiones cerradas.
4. **Método y ruta exacta:** `GET /api/caja/cierre?session_id={id}&referencia_solicitud={ref}`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.cerrar`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - La sesión `{id}` debe pertenecer estrictamente a `$this->authenticatedUserId` (`sesion_caja.id_usuario_vendedor === $this->authenticatedUserId`). Si pertenece a otro vendedor, responde `403 SESSION_NOT_OWNED`.
   - **Límite de alcance anti-historial:** Exige la referencia de la operación concreta de cierre además de `session_id`. Busca por usuario autenticado, `CAJA_CERRAR` y referencia confirmada, y verifica que la respuesta guardada corresponda a esa sesión. No permite listar cierres ni aplica límite por fecha, medianoche o apertura posterior. Si no existe confirmación y la sesión propia sigue abierta devuelve `data: null`; si está cerrada sin referencia confirmada concordante devuelve `403 HISTORICAL_QUERY_NOT_ALLOWED`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Query obligatoria: `session_id` (alias `sesion_id`, entero $\ge 1$) y `referencia_solicitud` (alias `request_id`, cadena de 16..64 caracteres según sección 8.1).
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `session_id` (`sesion_id`): entero $\ge 1$; `referencia_solicitud`: cadena 16..64, ambos obligatorios.
10. **Validaciones y condiciones:**
    - Validar referencia 16..64 y buscar confirmación por autenticado/CAJA_CERRAR/referencia; comprobar session_id de respuesta confirmada. No limitar por día ni por última sesión del vendedor.
    - No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - `session_id` entero $\ge 1$ (`422 VALIDATION_ERROR`).
    - Existencia de la sesión (`404 CASH_SESSION_NOT_FOUND`) y titularidad de `$this->authenticatedUserId` (`403 SESSION_NOT_OWNED`).
11. **HTTP de éxito y JSON completo (`200 OK`):**
    - **Caso A — El cierre ya fue confirmado y persistido:**
```json
{
  "status": true,
  "code": "OK",
  "message": "Resumen de cierre recuperado correctamente.",
  "data": {
    "session_id": 15,
    "status": "Cerrada",
    "summary": {
      "opening_fund": 190.0,
      "opening_difference": -10.0,
      "cash_sales": 60.0,
      "card": 45.0,
      "yape_plin": 20.0,
      "income": 35.0,
      "expenses": 30.0,
      "expected": 255.0,
      "counted": 250.0,
      "difference": -5.0,
      "observation": "Falta moneda de S/ 5,00 en sencillo",
      "closed_at": "2026-11-12T11:44:10-05:00"
    }
  }
}
```
    - **Caso B — La sesión propia sigue abierta (el cierre aún no se ha confirmado en BD):**
```json
{
  "status": true,
  "code": "OK",
  "message": "La sesión aún se encuentra abierta.",
  "data": null
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`, `403 SESSION_NOT_OWNED`, `403 HISTORICAL_QUERY_NOT_ALLOWED`, `404 CASH_SESSION_NOT_FOUND`, `422 AMBIGUOUS_ALIAS_VALUES`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno. (No incluye ni genera ticket individual imprimible/descargable).
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Es el endpoint de lectura complementario para recuperar el resultado tras pérdida de conexión en `EP-20`.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/cierre?session_id=15&referencia_solicitud=am_cie_20261112_0001_c1d2e3f4` $\rightarrow$ `200 OK` con `data.summary`.
    - *Rechazo por sesión de otro vendedor:* `GET /api/caja/cierre?session_id=14&referencia_solicitud=am_cie_20261112_0001_c1d2e3f4` $\rightarrow$ `403 SESSION_NOT_OWNED`.


---

### E. Consultas de Sesiones y Reportes de Cierres (HU 17 y HU 19)

---

#### EP-22. Consultar sesión activa propia y sus movimientos paginados (Vista Vendedor en `/Caja/consultar`)
1. **Identificador y operación de la matriz:** `EP-22` — `OP-15`.
2. **HU y acuerdo que lo justifican:** HU 17; Sección 9 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Entregar al **Vendedor** autenticado (`CajaConsultaService.getActiveSession()`) el resumen completo de su **única sesión actualmente `'ABIERTA'`** (`summary` calculado sobre toda la sesión, separando efectivo de `card` y `yape_plin`, y conservando `current_balance = opening_balance` con acumuladores en `0.00` cuando aún no hay movimientos) más la página solicitada de sus movimientos en orden cronológico. Si el vendedor no tiene una sesión abierta en ese instante, devuelve estado sin sesión activa sin exponer sesiones cerradas.
4. **Método y ruta exacta:** `GET /api/caja/consulta/activa?page={p}&page_size={s}`.
5. **Rol y permisos concretos:** Exclusivo Vendedor activo (`id_rol = 2`) con permiso `turnos.acceder`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:**
   - Filtra estrictamente por `sesion_caja.id_usuario_vendedor === $this->authenticatedUserId` y `sesion_caja.estado === 'ABIERTA'`.
   - No admite filtros por `seller_id`, `date` ni `session_id` (si se envían, se rechaza con `403 FORBIDDEN`).
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Query params opcionales: `page` (`int`, $\ge 1$, por defecto `1`), `page_size` (alias `limit`, `int`, `1..100`, por defecto `10`).
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `page`: entero $\ge 1$; `page_size` (`limit`): entero `1..100`.
10. **Validaciones y condiciones:**
    - No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - `page >= 1` y `1 <= page_size <= 100` (`422 VALIDATION_ERROR`).
    - Orden de movimientos: `ORDER BY fecha_hora_registro ASC, id_movimiento_caja ASC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
    - **Caso A — El vendedor tiene su sesión abierta:**
```json
{
  "status": true,
  "code": "OK",
  "message": "Consulta de sesión activa obtenida correctamente.",
  "data": {
    "type": "result",
    "has_active_session": true,
    "session": {
      "id": 15,
      "session_id": 15,
      "seller_id": 4,
      "seller": "Alex Garcia",
      "turn": "Mañana",
      "reference_schedule": {
        "shift_id": 1,
        "shift_name": "Mañana",
        "start": "07:00",
        "end": "15:00",
        "label": "Mañana · 07:00–15:00"
      },
      "status": "Abierta",
      "opened_at": "2026-11-12T07:04:12-05:00",
      "closed_at": null,
      "opening_difference": -10.0
    },
    "summary": {
      "opening_balance": 190.0,
      "cash_sales": 60.0,
      "income": 35.0,
      "expenses": 30.0,
      "current_balance": 255.0,
      "card": 45.0,
      "yape_plin": 20.0
    },
    "movements": [
      {
        "id": 20,
        "date": "2026-11-12",
        "time": "08:30",
        "recorded_at": "2026-11-12T08:30:10-05:00",
        "type": "cash_sale",
        "label": "Venta en efectivo",
        "reference": "Boleta B001-000319",
        "amount": 60.0,
        "impact": 60.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": null
      },
      {
        "id": 21,
        "date": "2026-11-12",
        "time": "09:15",
        "recorded_at": "2026-11-12T09:15:00-05:00",
        "type": "admin_injection",
        "label": "Inyección",
        "reference": "Sencillo entregado · Entregó: Superuser Demo",
        "amount": 50.0,
        "current_amount": 35.0,
        "impact": 50.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo"
      },
      {
        "id": 22,
        "date": "2026-11-12",
        "time": "10:20",
        "recorded_at": "2026-11-12T10:20:00-05:00",
        "type": "admin_withdrawal",
        "label": "Retiro",
        "reference": "Retiro parcial · Recibió: Superuser Demo",
        "amount": 30.0,
        "impact": -30.0,
        "effect": "Resta",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo"
      },
      {
        "id": 23,
        "date": "2026-11-12",
        "time": "10:35",
        "recorded_at": "2026-11-12T10:35:00-05:00",
        "type": "omitted_withdrawal",
        "label": "Retiro omitido",
        "reference": "Sesión #14 · Retiro anterior declarado y ya reflejado al abrir",
        "amount": 300.0,
        "impact": 0.0,
        "effect": "Sin efecto: ya reflejado en la apertura",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo",
        "origin_session_id": 14
      },
      {
        "id": 24,
        "date": "2026-11-12",
        "time": "10:40",
        "recorded_at": "2026-11-12T10:40:00-05:00",
        "type": "correction",
        "label": "Corrección",
        "reference": "Corrección #1 del movimiento #21: de 50.00 a 30.00",
        "amount": 20.0,
        "impact": -20.0,
        "effect": "Resta",
        "responsible": "Alex Garcia",
        "administrator_name": null,
        "original_movement_id": 21,
        "correction_id": 1
      },
      {
        "id": 25,
        "date": "2026-11-12",
        "time": "10:48",
        "recorded_at": "2026-11-12T10:48:00-05:00",
        "type": "correction",
        "label": "Corrección",
        "reference": "Corrección #2 del movimiento #21: de 30.00 a 35.00",
        "amount": 5.0,
        "impact": 5.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": null,
        "original_movement_id": 21,
        "correction_id": 2
      }
    ],
    "pagination": {
      "page": 1,
      "page_size": 10,
      "total_pages": 1,
      "total_items": 6,
      "total_movements": 6
    }
  }
}
```
    - **Caso B — El vendedor no tiene sesión abierta actualmente:**
```json
{
  "status": true,
  "code": "NO_OPEN_SESSION",
  "message": "No tienes una sesión de caja abierta actualmente.",
  "data": {
    "type": "no_session",
    "has_active_session": false,
    "session": null,
    "summary": null,
    "movements": [],
    "pagination": {
      "page": 1,
      "page_size": 10,
      "total_pages": 0,
      "total_items": 0,
      "total_movements": 0
    }
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN`, `422 AMBIGUOUS_ALIAS_VALUES`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/consulta/activa?page=1&page_size=10` $\rightarrow$ `200 OK`.
    - *Rechazo:* Vendedor intenta filtrar otra sesión `GET /api/caja/consulta/activa?session_id=14` $\rightarrow$ `403 FORBIDDEN`.

---

#### EP-23A. Listar vendedores para el filtro de consulta histórica de caja (Administrador)
1. **Identificador y operación de la matriz:** `EP-23A` — `OP-16`.
2. **HU y acuerdo que lo justifican:** HU 17; Sección 9 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Entregar al **Administrador** autenticado (`CajaConsultaService.listSellers()`) la lista de vendedores (`id_rol = 2`) para el selector de filtro en `/Caja/consultar`.
4. **Método y ruta exacta:** `GET /api/caja/consulta/vendedores`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.consultar_historico` (`requireAdminActivo('turnos.consultar_historico')`).
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:** Alcance administrativo global de solo lectura.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Ninguno.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Sin campos de entrada.
10. **Validaciones y condiciones:** Usuario activo con `id_rol === 1` y permiso `turnos.consultar_historico`. Orden: `ORDER BY nombre ASC, id_usuario ASC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Vendedores obtenidos correctamente.",
  "data": [
    {
      "id": 4,
      "name": "Alex Garcia",
      "username": "alex",
      "estado": 1
    },
    {
      "id": 6,
      "name": "María López",
      "username": "maria",
      "estado": 1
    }
  ]
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* Administrador invoca `GET /api/caja/consulta/vendedores` $\rightarrow$ `200 OK`.
    - *Rechazo:* Vendedor (`id_rol = 2`) invoca `GET /api/caja/consulta/vendedores` $\rightarrow$ `403 FORBIDDEN_ADMIN_ONLY`.

---

#### EP-23B. Listar sesiones de caja por fecha de apertura y vendedor (Paso 1 de Consulta Administrativa)
1. **Identificador y operación de la matriz:** `EP-23B` — `OP-16`.
2. **HU y acuerdo que lo justifican:** HU 17; Sección 9 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Devolver al **Administrador** (`CajaConsultaService.listSessions({ date, seller_id })`) todas las sesiones de caja (abiertas o cerradas) cuya **fecha local de apertura (`America/Lima`)** sea `date` y cuyo vendedor responsable sea `seller_id`, para que el administrador **seleccione explícitamente qué sesión consultar** cuando existen varias aperturas en un mismo día (por ejemplo, turno Mañana y turno Tarde). Si no existen sesiones para esa fecha y vendedor, devuelve lista vacía `[]` sin cargar datos de otra fecha.
4. **Método y ruta exacta:** `GET /api/caja/consulta/sesiones?date={YYYY-MM-DD}&seller_id={id}`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.consultar_historico` (`requireAdminActivo('turnos.consultar_historico')`).
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:** Alcance administrativo global de solo lectura (incluye sesiones `'ABIERTA'` y `'CERRADA'`).
8. **Parámetros de ruta, query, cabeceras y cuerpo:**

| Parámetro canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `date` | — | `string` | Sí | No | Fecha `YYYY-MM-DD` válida (filtra por `DATE(fecha_hora_apertura)` en `America/Lima`). |
| `seller_id` | — | `int` | Sí | No | Entero $\ge 1$ (filtra por `sesion_caja.id_usuario_vendedor`). |
| `turn_id` | — | `int\|null` | No | Sí | Entero $\ge 1$ opcional para filtrar por turno. |

9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Ver tabla anterior.
10. **Validaciones y condiciones:**
    - `date` con formato y fecha calendario válidos (`422 INVALID_DATE`).
    - `seller_id` entero $\ge 1` (`422 VALIDATION_ERROR`).
    - Orden determinista: `ORDER BY fecha_hora_apertura ASC, id_sesion_caja ASC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
    - **Caso A — Vendedor con dos sesiones en la misma fecha (turno Mañana cerrado y turno Tarde abierto):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Sesiones obtenidas correctamente.",
  "data": [
    {
      "id": 15,
      "session_id": 15,
      "seller_id": 4,
      "seller": "Alex Garcia",
      "turn_id": 1,
      "turn": "Mañana",
      "status": "Cerrada",
      "opened_at": "2026-11-12T07:04:12-05:00",
      "closed_at": "2026-11-12T11:44:10-05:00"
    },
    {
      "id": 16,
      "session_id": 16,
      "seller_id": 4,
      "seller": "Alex Garcia",
      "turn_id": 2,
      "turn": "Tarde",
      "status": "Abierta",
      "opened_at": "2026-11-12T15:02:00-05:00",
      "closed_at": null
    }
  ]
}
```
    - **Caso B — Sin aperturas en la fecha consultada:**
```json
{
  "status": true,
  "code": "OK",
  "message": "No se encontraron sesiones para la fecha y el vendedor seleccionados.",
  "data": []
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`, `422 INVALID_DATE`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/consulta/sesiones?date=2026-11-12&seller_id=4` $\rightarrow$ `200 OK`.
    - *Rechazo:* Vendedor (`id_rol = 2`) invoca `GET /api/caja/consulta/sesiones?date=2026-11-12&seller_id=4` $\rightarrow$ `403 FORBIDDEN_ADMIN_ONLY`.

---

#### EP-23C. Consultar detalle completo, movimientos paginados y autorizaciones de una sesión seleccionada (Paso 2 de Consulta Administrativa)
1. **Identificador y operación de la matriz:** `EP-23C` — `OP-16`.
2. **HU y acuerdo que lo justifican:** HU 17 y HU 14; Secciones 8 y 9 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Entregar al **Administrador** (`CajaConsultaService.query({ session_id, page, page_size })`) el detalle completo de la sesión `session_id` explícitamente seleccionada:
   - Metadatos de la sesión (`session_id`, vendedor responsable real que abrió, turno atendido, horario de referencia, `opened_at`, `closed_at`, `reference_amount`, `opening_balance`, `opening_difference`, `closing_difference`).
   - `summary`: Totales completos de toda la sesión (no solo de la página visible), separando efectivo de `card` y `yape_plin`. Si la sesión no tiene movimientos, conserva `opening_balance` y muestra `0.00` en los acumulados de movimientos.
   - `authorizations`: Evidencia completa de las autorizaciones administrativas registradas sobre esa sesión (administrador autorizador, vendedor, operación `'CIERRE_ANTICIPADO'`, motivo, fecha y hora).
   - `movements`: Página solicitada de movimientos en orden cronológico con sus referencias a correcciones vinculadas y retiros omitidos.
4. **Método y ruta exacta:** `GET /api/caja/consulta?session_id={id}&page={p}&page_size={s}`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `turnos.consultar_historico` (`requireAdminActivo('turnos.consultar_historico')`).
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:** Alcance administrativo global de solo lectura (admite sesiones `'ABIERTA'` y `'CERRADA'`).
8. **Parámetros de ruta, query, cabeceras y cuerpo:**

| Parámetro canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `session_id` | `sesion_id` | `int` | Sí | No | Entero $\ge 1$. ID de la sesión de caja seleccionada. |
| `page` | — | `int` | No | Sí | Entero $\ge 1$, por defecto `1`. |
| `page_size` | `limit` | `int` | No | Sí | Entero `1..100`, por defecto `10`. |

9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Ver tabla anterior.
10. **Validaciones y condiciones:**
    - No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - `session_id` obligatorio entero $\ge 1` (`422 VALIDATION_ERROR`) y existente en `sesion_caja` (`404 CASH_SESSION_NOT_FOUND`).
    - `page >= 1` y `1 <= page_size <= 100` (`422 VALIDATION_ERROR`).
    - Orden de movimientos: `ORDER BY fecha_hora_registro ASC, id_movimiento_caja ASC`.
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Detalle de sesión obtenido correctamente.",
  "data": {
    "type": "result",
    "session": {
      "id": 15,
      "session_id": 15,
      "seller_id": 4,
      "seller": "Alex Garcia",
      "turn_id": 1,
      "turn": "Mañana",
      "reference_schedule": {
        "shift_id": 1,
        "shift_name": "Mañana",
        "start": "07:00",
        "end": "12:00",
        "label": "Mañana · 07:00–12:00"
      },
      "status": "Cerrada",
      "opened_at": "2026-11-12T07:04:12-05:00",
      "closed_at": "2026-11-12T11:44:10-05:00",
      "has_previous_close": true,
      "reference_amount": 200.0,
      "opening_balance": 190.0,
      "opening_difference": -10.0,
      "counted_at_close": 250.0,
      "closing_difference": -5.0
    },
    "summary": {
      "opening_balance": 190.0,
      "cash_sales": 60.0,
      "income": 35.0,
      "expenses": 30.0,
      "current_balance": 255.0,
      "expected_cash": 255.0,
      "counted_cash": 250.0,
      "closing_difference": -5.0,
      "card": 45.0,
      "yape_plin": 20.0
    },
    "authorizations": [
      {
        "id": 1,
        "session_id": 15,
        "operation": "CIERRE_ANTICIPADO",
        "seller_id": 4,
        "seller_name": "Alex Garcia",
        "administrator_id": 9,
        "administrator_name": "Superuser Demo",
        "administrator_email": "superuser.demo@amazonmarket.local",
        "reason": "Cita médica urgente del vendedor",
        "scheduled_end": "12:00",
        "actual_close_time": "11:44",
        "minutes_before_end": 16,
        "authorized_at": "2026-11-12T11:44:10-05:00"
      }
    ],
    "movements": [
      {
        "id": 20,
        "date": "2026-11-12",
        "time": "08:30",
        "recorded_at": "2026-11-12T08:30:10-05:00",
        "type": "cash_sale",
        "label": "Venta en efectivo",
        "reference": "Boleta B001-000319",
        "amount": 60.0,
        "impact": 60.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": null
      },
      {
        "id": 21,
        "date": "2026-11-12",
        "time": "09:15",
        "recorded_at": "2026-11-12T09:15:00-05:00",
        "type": "admin_injection",
        "label": "Inyección",
        "reference": "Sencillo entregado · Entregó: Superuser Demo",
        "amount": 50.0,
        "current_amount": 35.0,
        "impact": 50.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo"
      },
      {
        "id": 22,
        "date": "2026-11-12",
        "time": "10:20",
        "recorded_at": "2026-11-12T10:20:00-05:00",
        "type": "admin_withdrawal",
        "label": "Retiro",
        "reference": "Retiro parcial · Recibió: Superuser Demo",
        "amount": 30.0,
        "impact": -30.0,
        "effect": "Resta",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo"
      },
      {
        "id": 23,
        "date": "2026-11-12",
        "time": "10:35",
        "recorded_at": "2026-11-12T10:35:00-05:00",
        "type": "omitted_withdrawal",
        "label": "Retiro omitido",
        "reference": "Sesión #14 · Retiro anterior declarado y ya reflejado al abrir",
        "amount": 300.0,
        "impact": 0.0,
        "effect": "Sin efecto: ya reflejado en la apertura",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo",
        "origin_session_id": 14
      },
      {
        "id": 24,
        "date": "2026-11-12",
        "time": "10:40",
        "recorded_at": "2026-11-12T10:40:00-05:00",
        "type": "correction",
        "label": "Corrección",
        "reference": "Corrección #1 del movimiento #21: de 50.00 a 30.00",
        "amount": 20.0,
        "impact": -20.0,
        "effect": "Resta",
        "responsible": "Alex Garcia",
        "administrator_name": null,
        "original_movement_id": 21,
        "correction_id": 1
      },
      {
        "id": 25,
        "date": "2026-11-12",
        "time": "10:48",
        "recorded_at": "2026-11-12T10:48:00-05:00",
        "type": "correction",
        "label": "Corrección",
        "reference": "Corrección #2 del movimiento #21: de 30.00 a 35.00",
        "amount": 5.0,
        "impact": 5.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": null,
        "original_movement_id": 21,
        "correction_id": 2
      }
    ],
    "pagination": {
      "page": 1,
      "page_size": 10,
      "total_pages": 1,
      "total_items": 6,
      "total_movements": 6
    }
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`, `404 CASH_SESSION_NOT_FOUND`, `422 AMBIGUOUS_ALIAS_VALUES`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/caja/consulta?session_id=15&page=1&page_size=10` $\rightarrow$ `200 OK`.
    - *Rechazo:* `GET /api/caja/consulta?session_id=9999` $\rightarrow$ `404 CASH_SESSION_NOT_FOUND`.

---

#### EP-24A. Consultar reporte administrativo de cierres de caja (filtrable y paginado)
1. **Identificador y operación de la matriz:** `EP-24A` — `OP-17`.
2. **HU y acuerdo que lo justifican:** HU 19; Sección 9 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Proveer a `/Reporte/cierresCaja` el listado paginado de sesiones en estado `'CERRADA'` filtrado por rango inclusivo de **fecha local de cierre (`desde`, `hasta`)**, `turno_id` y `vendedor_id`, junto con los totales consolidados de todo el período filtrado (`totals`), separando diferencias de apertura y cierre, y medios de pago (`cash_sales`, `card_sales`, `yape_plin_sales`), además de registrar un `snapshot_id` en `reporte_consulta_snapshot` para reproducción exacta al exportar.
4. **Método y ruta exacta:** `GET /api/reportes/cierres-caja`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `reportes.cierres_caja` (`requireAdminActivo('reportes.cierres_caja')`).
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:** Alcance administrativo global de solo lectura sobre sesiones con `estado = 'CERRADA'`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**

| Parámetro canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `desde` | — | `string` | No | Sí | Fecha `YYYY-MM-DD` (inicio inclusivo por `DATE(fecha_hora_cierre)` en `America/Lima`). Por defecto: primer día del mes actual. |
| `hasta` | — | `string` | No | Sí | Fecha `YYYY-MM-DD` (fin inclusivo por `DATE(fecha_hora_cierre)` en `America/Lima`, con `desde <= hasta`). Por defecto: fecha actual. |
| `turno_id` | `turno` | `int\|null` | No | Sí | Entero $\ge 1$ opcional para filtrar por turno atendido. |
| `vendedor_id` | `vendedor` | `int\|null` | No | Sí | Entero $\ge 1$ opcional para filtrar por vendedor responsable. |
| `snapshot_id` | — | `int` | No | No | Snapshot propio de tipo cierres_caja; si ausente crea uno. Ver 9.2. |
| `page` | — | `int` | No | Sí | Entero $\ge 1$, por defecto `1`. |
| `page_size` | `limit` | `int` | No | Sí | Entero `1..100`, por defecto `10`. |

9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Ver tabla anterior.
10. **Validaciones y condiciones:**
    - No contradicción de alias (`422 AMBIGUOUS_ALIAS_VALUES`).
    - `desde` y `hasta` fechas válidas con `desde <= hasta` (`422 INVALID_DATE_RANGE`).
    - `page >= 1` y `1 <= page_size <= 100` (`422 VALIDATION_ERROR`).
    - Orden: `ORDER BY fecha_hora_cierre DESC, id_sesion_caja DESC`. Si se envía snapshot, validar propietario, tipo, filtros y vigencia según sección 9.2 (`404 SNAPSHOT_NOT_FOUND`, `409 SNAPSHOT_FILTER_MISMATCH`).
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Reporte de cierres de caja obtenido correctamente.",
  "data": {
    "snapshot_id": 31,
    "generated_at": "2026-11-12T12:10:00-05:00",
    "expires_at": "2026-11-12T12:40:00-05:00",
    "filters": {
      "desde": "2026-11-01",
      "hasta": "2026-11-12",
      "turno_id": null,
      "vendedor_id": null
    },
    "totals": {
      "total_closures": 1,
      "opening_fund_total": 190.0,
      "opening_difference_net": -10.0,
      "cash_sales_total": 60.0,
      "card_sales_total": 45.0,
      "yape_plin_sales_total": 20.0,
      "income_total": 35.0,
      "expenses_total": 30.0,
      "expected_cash_total": 255.0,
      "counted_cash_total": 250.0,
      "closing_difference_net": -5.0
    },
    "closures": [
      {
        "id": 15,
        "session_id": 15,
        "code": "CIE-0015",
        "opened_at": "2026-11-12T07:04:12-05:00",
        "closed_at": "2026-11-12T11:44:10-05:00",
        "closing_date": "2026-11-12",
        "closing_time": "11:44",
        "shift_id": 1,
        "turn": "Mañana",
        "seller_id": 4,
        "seller": "Alex Garcia",
        "has_previous_close": true,
        "reference_amount": 200.0,
        "opening_fund": 190.0,
        "opening_difference": -10.0,
        "cash_sales": 60.0,
        "card_sales": 45.0,
        "yape_plin_sales": 20.0,
        "income": 35.0,
        "expenses": 30.0,
        "expected": 255.0,
        "counted": 250.0,
        "closing_difference": -5.0,
        "observation": "Falta moneda de S/ 5,00 en sencillo",
        "has_early_close_authorization": true,
        "authorized_by_admin": "Superuser Demo",
        "has_omitted_withdrawals": true
      }
    ],
    "pagination": {
      "page": 1,
      "page_size": 10,
      "total_pages": 1,
      "total_items": 1,
      "total_closures": 1
    }
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`, `422 AMBIGUOUS_ALIAS_VALUES`, `422 INVALID_DATE_RANGE`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno sobre `sesion_caja`; registra el snapshot de consulta en `reporte_consulta_snapshot`.
14. **Concurrencia y bloqueos necesarios:** Solo lectura sobre `sesion_caja`.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/reportes/cierres-caja?desde=2026-11-01&hasta=2026-11-12&page=1&page_size=10` $\rightarrow$ `200 OK`.
    - *Rechazo por rango invertido (`desde=2026-11-12&hasta=2026-11-01`):* `422 INVALID_DATE_RANGE`.

---

#### EP-24B. Consultar detalle completo de un cierre de caja para `/Reporte/detalleCierreCaja`
1. **Identificador y operación de la matriz:** `EP-24B` — `OP-17`.
2. **HU y acuerdo que lo justifican:** HU 19; Sección 9 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Entregar al **Administrador** el detalle completo de una sesión cerrada específica (`{id}`): importes y diferencias de apertura y cierre separadas, desglose de medios de pago (`cash_sales`, `card_sales`, `yape_plin_sales`), arqueos registrados, autorizaciones de cierre anticipado, y listado de movimientos con sus referencias a correcciones vinculadas y retiros omitidos.
4. **Método y ruta exacta:** `GET /api/reportes/cierres-caja/{id}`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con permiso `reportes.cierres_caja` (`requireAdminActivo('reportes.cierres_caja')`).
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:** Alcance administrativo global sobre sesiones con `estado = 'CERRADA'`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Parámetro de ruta: `id` (`int`, $\ge 1$, obligatorio).
   - Query params opcionales: `page` (`int`, $\ge 1$, defecto `1`), `page_size` (alias `limit`, `int`, `1..100`, defecto `20`).
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** `id`: entero $\ge 1$; `page`: entero $\ge 1$; `page_size`: entero `1..100`.
10. **Validaciones y condiciones:**
    - La sesión `{id}` debe existir (`404 CASH_SESSION_NOT_FOUND`) y estar en estado `'CERRADA'` (`409 CASH_SESSION_NOT_CLOSED` si sigue abierta).
11. **HTTP de éxito y JSON completo (`200 OK`):**
```json
{
  "status": true,
  "code": "OK",
  "message": "Detalle de cierre de caja obtenido correctamente.",
  "data": {
    "closure": {
      "id": 15,
      "session_id": 15,
      "code": "CIE-0015",
      "seller_id": 4,
      "seller": "Alex Garcia",
      "shift_id": 1,
      "turn": "Mañana",
      "reference_schedule": {
        "shift_id": 1,
        "shift_name": "Mañana",
        "start": "07:00",
        "end": "12:00",
        "label": "Mañana · 07:00–12:00"
      },
      "opened_at": "2026-11-12T07:04:12-05:00",
      "closed_at": "2026-11-12T11:44:10-05:00",
      "has_previous_close": true,
      "reference_amount": 200.0,
      "opening_fund": 190.0,
      "opening_difference": -10.0,
      "cash_sales": 60.0,
      "card_sales": 45.0,
      "yape_plin_sales": 20.0,
      "income": 35.0,
      "expenses": 30.0,
      "expected": 255.0,
      "counted": 250.0,
      "closing_difference": -5.0,
      "observation": "Falta moneda de S/ 5,00 en sencillo"
    },
    "authorization": {
      "id": 1,
      "operation": "CIERRE_ANTICIPADO",
      "administrator_id": 9,
      "administrator_name": "Superuser Demo",
      "administrator_email": "superuser.demo@amazonmarket.local",
      "reason": "Cita médica urgente del vendedor",
      "scheduled_end": "12:00",
      "actual_close_time": "11:44",
      "minutes_before_end": 16,
      "authorized_at": "2026-11-12T11:44:10-05:00"
    },
    "arqueos": [
      {
        "id": 8,
        "recorded_at": "2026-11-12T11:43:12-05:00",
        "expected": 255.0,
        "counted": 250.0,
        "difference": -5.0,
        "observation": "Falta moneda de S/ 5,00 en sencillo",
        "status": "Vigente",
        "version": 6
      }
    ],
    "movements": [
      {
        "id": 20,
        "date": "2026-11-12",
        "time": "08:30",
        "recorded_at": "2026-11-12T08:30:10-05:00",
        "type": "cash_sale",
        "label": "Venta en efectivo",
        "reference": "Boleta B001-000319",
        "amount": 60.0,
        "impact": 60.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": null
      },
      {
        "id": 21,
        "date": "2026-11-12",
        "time": "09:15",
        "recorded_at": "2026-11-12T09:15:00-05:00",
        "type": "admin_injection",
        "label": "Inyección",
        "reference": "Sencillo entregado · Entregó: Superuser Demo",
        "amount": 50.0,
        "current_amount": 35.0,
        "impact": 50.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo"
      },
      {
        "id": 22,
        "date": "2026-11-12",
        "time": "10:20",
        "recorded_at": "2026-11-12T10:20:00-05:00",
        "type": "admin_withdrawal",
        "label": "Retiro",
        "reference": "Retiro parcial · Recibió: Superuser Demo",
        "amount": 30.0,
        "impact": -30.0,
        "effect": "Resta",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo"
      },
      {
        "id": 23,
        "date": "2026-11-12",
        "time": "10:35",
        "recorded_at": "2026-11-12T10:35:00-05:00",
        "type": "omitted_withdrawal",
        "label": "Retiro omitido",
        "reference": "Sesión #14 · Retiro anterior declarado y ya reflejado al abrir",
        "amount": 300.0,
        "impact": 0.0,
        "effect": "Sin efecto: ya reflejado en la apertura",
        "responsible": "Alex Garcia",
        "administrator_name": "Superuser Demo",
        "origin_session_id": 14
      },
      {
        "id": 24,
        "date": "2026-11-12",
        "time": "10:40",
        "recorded_at": "2026-11-12T10:40:00-05:00",
        "type": "correction",
        "label": "Corrección",
        "reference": "Corrección #1 del movimiento #21: de 50.00 a 30.00",
        "amount": 20.0,
        "impact": -20.0,
        "effect": "Resta",
        "responsible": "Alex Garcia",
        "administrator_name": null,
        "original_movement_id": 21,
        "correction_id": 1
      },
      {
        "id": 25,
        "date": "2026-11-12",
        "time": "10:48",
        "recorded_at": "2026-11-12T10:48:00-05:00",
        "type": "correction",
        "label": "Corrección",
        "reference": "Corrección #2 del movimiento #21: de 30.00 a 35.00",
        "amount": 5.0,
        "impact": 5.0,
        "effect": "Suma",
        "responsible": "Alex Garcia",
        "administrator_name": null,
        "original_movement_id": 21,
        "correction_id": 2
      }
    ],
    "pagination": {
      "page": 1,
      "page_size": 20,
      "total_pages": 1,
      "total_items": 6,
      "total_movements": 6
    }
  }
}
```
12. **Errores HTTP y códigos estables aplicables:** `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`, `404 CASH_SESSION_NOT_FOUND`, `409 CASH_SESSION_NOT_CLOSED`, `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno.
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Solo lectura.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/reportes/cierres-caja/15` $\rightarrow$ `200 OK`.
    - *Rechazo:* Vendedor (`id_rol = 2`) invoca `GET /api/reportes/cierres-caja/15` $\rightarrow$ `403 FORBIDDEN_ADMIN_ONLY`.

---

#### EP-24C. Exportar reporte administrativo de cierres de caja a PDF
1. **Identificador y operación de la matriz:** `EP-24C` — `OP-17`.
2. **HU y acuerdo que lo justifican:** HU 19; Sección 9 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Generar y descargar el archivo **PDF** del reporte administrativo de cierres de caja correspondiente al **conjunto filtrado completo** (todas las sesiones cerradas que cumplen el filtro o el `snapshot_id`, sin recortar a la página visible de la tabla). *(La presentación visual del PDF se implementará en el Paso 14 cuando el usuario entregue el diseño).*
4. **Método y ruta exacta:** `GET /api/reportes/exportar-pdf?tipo=cierres-caja`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con **ambos permisos** `reportes.cierres_caja` **y** `reportes.exportar`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:** Alcance administrativo global sobre sesiones `'CERRADA'`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:**

| Parámetro canónico | Alias admitido | Tipo | Obligatorio | Nulable | Formato y límites |
| :--- | :--- | :--- | :-: | :-: | :--- |
| `tipo` | — | `string` | Sí | No | Valor exacto `'cierres-caja'`. |
| `snapshot_id` | — | `int\|null` | No | Sí | Entero $\ge 1$ devuelto por `EP-24A`. Si se envía, exporta exactamente el conjunto capturado en ese snapshot. |
| `desde` / `hasta` | — | `string` | No | Sí | Fechas `YYYY-MM-DD` (`desde <= hasta`). Usados si no se envía `snapshot_id`. |
| `turno_id` | `turno` | `int\|null` | No | Sí | Entero $\ge 1$ opcional. |
| `vendedor_id` | `vendedor` | `int\|null` | No | Sí | Entero $\ge 1$ opcional. |
| `session_id` | `sesion_id` | `int\|null` | No | Sí | Entero $\ge 1$ opcional (si se exporta desde la vista de detalle de un cierre administrativo `/Reporte/detalleCierreCaja/{id}`). |

9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Ver tabla anterior.
10. **Validaciones y condiciones:**
    - Si se envía `snapshot_id`, debe existir en `reporte_consulta_snapshot` con `tipo_reporte = 'cierres_caja'`, creado por el administrador autenticado y vigente (`404 SNAPSHOT_NOT_FOUND`); no basta conocer el ID. Se aplica la sección 9.2 para filtros, vigencia y reintento.
    - Si se envían `desde` y `hasta`, deben ser fechas válidas con `desde <= hasta` (`422 INVALID_DATE_RANGE`).
11. **HTTP de éxito y respuesta binaria (`200 OK`):**
    - Cabeceras HTTP:
      - `Content-Type: application/pdf`
      - `Content-Disposition: attachment; filename="Reporte_Cierres_Caja_YYYY-MM-DD_HHmmss.pdf"`
      - `Cache-Control: private, no-store, max-age=0`
      - `X-Content-Type-Options: nosniff`
      - `Content-Length: <bytes>`
    - Cuerpo: flujo binario `%PDF-1.x ...` (consumido por `Api.download()` en [Assets/js/api.js](Assets/js/api.js)).
12. **Errores HTTP y códigos estables aplicables (devueltos en JSON con `Content-Type: application/json`):**
    - `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`, `404 SNAPSHOT_NOT_FOUND`, `409 SNAPSHOT_FILTER_MISMATCH`, `422 INVALID_DATE_RANGE`, `500 PDF_GENERATION_FAILED`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno (solo lectura del negocio; puede capturar/renovar metadatos de snapshot conforme a 9.2).
14. **Concurrencia y bloqueos necesarios:** Lectura consistente sin bloqueo sobre `sesion_caja`.
15. **Idempotencia y recuperación:** Si la generación falla o se pierde la conexión, `Api.download()` recibe el error JSON sin recargar la página, conservando los filtros en la interfaz para reintentar inmediatamente.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/reportes/exportar-pdf?tipo=cierres-caja&desde=2026-11-01&hasta=2026-11-12` $\rightarrow$ `200 OK` (`application/pdf`).
    - *Rechazo:* Vendedor (`id_rol = 2`) invoca `GET /api/reportes/exportar-pdf?tipo=cierres-caja` $\rightarrow$ `403 FORBIDDEN_ADMIN_ONLY` (`application/json`).

---

#### EP-24D. Exportar reporte administrativo de cierres de caja a Excel (`.xlsx`)
1. **Identificador y operación de la matriz:** `EP-24D` — `OP-17`.
2. **HU y acuerdo que lo justifican:** HU 19; Sección 9 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Generar y descargar el libro **Excel OpenXML (`.xlsx`)** del reporte administrativo de cierres de caja correspondiente al **conjunto filtrado completo** (todas las sesiones cerradas que cumplen el filtro o el `snapshot_id`). *(La estructura visual del libro se implementará en el Paso 14 cuando el usuario entregue el diseño).*
4. **Método y ruta exacta:** `GET /api/reportes/exportar-excel?tipo=cierres-caja`.
5. **Rol y permisos concretos:** Exclusivo Administrador activo (`id_rol = 1`) con **ambos permisos** `reportes.cierres_caja` **y** `reportes.exportar`.
6. **Autenticación y CSRF:** Sesión activa en BD.
7. **Propiedad y estado requerido:** Alcance administrativo global sobre sesiones `'CERRADA'`.
8. **Parámetros de ruta, query, cabeceras y cuerpo:** Mismos parámetros que `EP-24C` (`tipo=cierres-caja`, `snapshot_id`, `desde`, `hasta`, `turno_id`, `vendedor_id`, `session_id`).
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:** Mismos que `EP-24C`.
10. **Validaciones y condiciones:** Mismas que `EP-24C`.
11. **HTTP de éxito y respuesta binaria (`200 OK`):**
    - Cabeceras HTTP (compatibles con la validación ZIP/OpenXML de `Api.downloadExcel()` en [Assets/js/api.js](Assets/js/api.js)):
      - `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
      - `Content-Disposition: attachment; filename="Reporte_Cierres_Caja_YYYY-MM-DD_HHmmss.xlsx"`
      - `Cache-Control: private, no-store, max-age=0`
      - `X-Content-Type-Options: nosniff`
      - `Content-Length: <bytes>`
    - Cuerpo: archivo binario `.xlsx` válido (cabecera `PK\x03\x04` con `[Content_Types].xml` y `xl/workbook.xml`).
12. **Errores HTTP y códigos estables aplicables (devueltos en JSON con `Content-Type: application/json`):**
    - `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`, `404 SNAPSHOT_NOT_FOUND`, `409 SNAPSHOT_FILTER_MISMATCH`, `422 INVALID_DATE_RANGE`, `500 EXCEL_GENERATION_FAILED`.
13. **Efectos sobre saldo, estado, versión y arqueos:** Ninguno (solo lectura del negocio; puede capturar/renovar metadatos de snapshot conforme a 9.2).
14. **Concurrencia y bloqueos necesarios:** Solo lectura.
15. **Idempotencia y recuperación:** Si ocurre un error, devuelve JSON de error sin perder los filtros de la pantalla para permitir reintentar.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Válido:* `GET /api/reportes/exportar-excel?tipo=cierres-caja&snapshot_id=31` $\rightarrow$ `200 OK` (`.xlsx`).
    - *Rechazo:* Vendedor (`id_rol = 2`) invoca `GET /api/reportes/exportar-excel?tipo=cierres-caja` $\rightarrow$ `403 FORBIDDEN_ADMIN_ONLY`.

---

### F. Integración Transversal con Ventas POS (Sección 7 de Acuerdos)

---

#### EP-25. Confirmar venta POS vinculada atómicamente a la sesión de caja propia abierta (`POST /api/ventas`)
1. **Identificador y operación de la matriz:** `EP-25` — Integración transversal con `OP-05`, `OP-07`, `OP-11`, `OP-12`, `OP-15`, `OP-16`, `OP-17`.
2. **HU y acuerdo que lo justifican:** HU 11 a HU 19; Sección 1, Sección 2 y Sección 7 de [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md).
3. **Propósito:** Coordinar en **una sola transacción atómica** la confirmación de cada nueva venta en [Services/VentaService.php](Services/VentaService.php) con la sesión de caja activa:
   - Exigir que exista una sesión en estado `'ABIERTA'` cuyo titular sea el vendedor autenticado (`sesion_caja.id_usuario_vendedor === $this->authenticatedUserId`).
   - Asociar la nueva venta a esa sesión (`venta.id_sesion_caja = sesion_abierta.id_sesion_caja`).
   - Según el método de pago (`id_metodo_pago` en tabla `metodo_pago`):
     - `1` (`Efectivo`): aumenta `sesion_caja.total_ventas_efectivo` y `sesion_caja.efectivo_esperado_actual` en `+total`, y registra la línea trazable de tipo `VENTA_EFECTIVO` (`cash_sale`) en `movimiento_caja` (sin duplicarla jamás como ingreso manual).
     - `2` (`Tarjeta`): aumenta `sesion_caja.total_ventas_tarjeta` en `+total` (separado del efectivo; no suma a `efectivo_esperado_actual`).
     - `3` (`Yape / Plin`): aumenta `sesion_caja.total_ventas_yape_plin` en `+total` (separado del efectivo; no suma a `efectivo_esperado_actual`).
   - Incrementar `sesion_caja.version_caja = version_caja + 1` y pasar a `'Desactualizado'` cualquier arqueo `'Vigente'` previo de la sesión.
   - Si el cierre de la sesión se confirma primero, rechazar la venta con `409 CASH_SESSION_CLOSED` **sin modificar stock, sin avanzar el correlativo de comprobante y sin insertar en Kardex**.
   - Conservar las **250 ventas históricas existentes (`id_venta` `124..373`) con `id_sesion_caja = NULL`** sin asignarles sesiones ficticias.
4. **Método y ruta exacta:** `POST /api/ventas`.
5. **Rol y permisos concretos:**
   - Permiso RBAC: `ventas.procesar` (`id_permiso = 15`).
   - Rol operativo y titularidad de sesión de caja: Como la apertura de sesión (`EP-09`, `turnos.abrir`) corresponde exclusivamente al rol **Vendedor (`id_rol = 2`)** y toda nueva venta exige ser el titular de la sesión `'ABIERTA'` (`sesion_caja.id_usuario_vendedor === $this->authenticatedUserId`), en la operación del Sprint 3 solo el **Vendedor (`id_rol = 2`) titular de la sesión abierta** puede confirmar ventas POS. *(No se elimina `ventas.procesar` de `rol_permiso` para `id_rol = 1`, el dominio exige explícitamente rol Vendedor y devuelve `403 FORBIDDEN` al Administrador antes de consultar la caja).*
6. **Autenticación y CSRF:** Sesión activa en BD + `X-CSRF-Token`.
7. **Propiedad y estado requerido:**
   - Sesión propia en estado `'ABIERTA'` (`sesion_caja.id_usuario_vendedor === $this->authenticatedUserId`).
8. **Parámetros de ruta, query, cabeceras y cuerpo:**
   - Conserva íntegramente el cuerpo actual de `POST /api/ventas` ([VentaApiController.php](Controllers/API/VentaApiController.php) / [VentaService.php](Services/VentaService.php)):
     `{ "referencia_solicitud", "id_cliente", "id_tipo_comprobante", "id_metodo_pago", "productos": [{ "id_producto", "cantidad" }] }`.
   - El cliente **no envía `id_sesion_caja`**: el servidor lo determina exclusivamente desde la sesión `'ABIERTA'` de `$this->authenticatedUserId`.
9. **Tipo, obligatoriedad, nulabilidad, formato y límites:**
   - `referencia_solicitud` (`request_id`): `string` `16..64` chars obligatorio.
   - `id_cliente`: `int` $\ge 1$ obligatorio (cliente activo; si `id_tipo_comprobante = 2` Factura, exige RUC `id_tipo_documento = 2`).
   - `id_tipo_comprobante`: `int` (`1` Boleta, `2` Factura).
   - `id_metodo_pago`: `int` (`1` Efectivo, `2` Tarjeta, `3` Yape / Plin).
   - `productos`: arreglo no vacío de `{ id_producto: int >= 1, cantidad: int >= 1 }`.
10. **Validaciones y condiciones (orden dentro de la transacción de `VentaService::procesarVenta()`):**
    1. Verificar sesión de caja con `SELECT ... FROM sesion_caja WHERE estado = 'ABIERTA' LIMIT 1 FOR UPDATE`:
       - Si no existe ninguna fila: lanzar `InventarioException` con HTTP `409` y `code: "CASH_SESSION_CLOSED"` (*"No tienes una sesión de caja abierta. Abre tu caja antes de registrar ventas."*).
       - Si existe pero `(int)$sesion['id_usuario_vendedor'] !== $idUsuario`: lanzar `InventarioException` con HTTP `403` y `code: "SESSION_NOT_OWNED"` (*"La sesión de caja abierta pertenece a otro vendedor."*).
    2. Bloquear productos en orden ascendente (`bloquearProductosOrdenados`), validar stock entero suficiente, avanzar correlativo en `serie_comprobante`, insertar `venta` con `id_sesion_caja`, insertar `detalle_venta` y movimientos en `kardex`, insertar fila en `movimiento_caja` (si es Efectivo) y actualizar acumuladores y `version_caja` en `sesion_caja`.
11. **HTTP de éxito y JSON completo (`201 Created`, o `200 OK` en replay idempotente):**
```json
{
  "status": true,
  "code": "SALE_CREATED",
  "idempotent_replay": false,
  "message": "Venta registrada con éxito.",
  "data": {
    "result_code": "SALE_CREATED",
    "id_venta": 374,
    "id_sesion_caja": 15,
    "serie": "B001",
    "numero": "00000319",
    "id_metodo_pago": 1,
    "metodo_pago": "Efectivo",
    "total": 60.0,
    "current_cash_balance": 250.0,
    "version_caja": 2
  }
}
```
12. **Errores HTTP y códigos estables aplicables:**
    - `401 AUTH_REQUIRED`, `403 CSRF_INVALID`, `403 FORBIDDEN`.
    - `403 SESSION_NOT_OWNED`: La caja física está abierta por otro vendedor.
    - `409 CASH_SESSION_CLOSED`: No hay ninguna sesión de caja abierta.
    - `409 INSUFFICIENT_STOCK`: Stock insuficiente en alguno de los productos.
    - `409 REQUEST_IN_PROGRESS`, `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
    - `422 VALIDATION_ERROR`.
13. **Efectos sobre saldo, estado, versión y arqueos:**
    - Vincula `venta.id_sesion_caja = sesion_abierta.id_sesion_caja`.
    - Si `id_metodo_pago === 1` (`Efectivo`): suma `+total` a `total_ventas_efectivo` y a `efectivo_esperado_actual`, e inserta movimiento `VENTA_EFECTIVO` en `movimiento_caja`.
    - Si `id_metodo_pago === 2` (`Tarjeta`): suma `+total` a `total_ventas_tarjeta` (`efectivo_esperado_actual` no cambia).
    - Si `id_metodo_pago === 3` (`Yape / Plin`): suma `+total` a `total_ventas_yape_plin` (`efectivo_esperado_actual` no cambia).
    - En los 3 métodos de pago: incrementa `version_caja = version_caja + 1` y pasa a `'Desactualizado'` cualquier arqueo `'Vigente'` previo de la sesión.
14. **Concurrencia y bloqueos necesarios:**
    - Orden estricto de bloqueo dentro de la transacción de venta: **1º `sesion_caja` (`FOR UPDATE`) $\rightarrow$ 2º `producto` en orden ascendente de `id_producto` (`FOR UPDATE`) $\rightarrow$ 3º `serie_comprobante` (`FOR UPDATE`)**.
    - El mismo lock de sesión serializa venta/cierre e impide efectos parciales posteriores al cierre. No garantiza ausencia de deadlocks de la transacción completa: aplicar rollback y CONCURRENT_OPERATION_CONFLICT según 8.2.
15. **Idempotencia y recuperación:**
    - Protegido por `ControlSolicitudService` (`tipo_operacion = 'VENTA'`). Si el vendedor reintenta la venta con la misma `referencia_solicitud` tras un corte de red (incluso si la caja se cerró después), recupera el resultado confirmado (`200 OK`, `code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`) sin descontar stock ni sumar a caja por segunda vez.
16. **Ejemplos válidos y casos de rechazo representativos:**
    - *Solicitud válida:*
      ```json
{
  "referencia_solicitud": "am_vta_20261112_0001_11223344",
  "id_cliente": 1,
  "id_tipo_comprobante": 1,
  "id_metodo_pago": 1,
  "productos": [
    {
      "id_producto": 16,
      "cantidad": 2
    }
  ]
}
      ```
    - *Rechazo cuando la sesión se cerró un instante antes:* `409 CASH_SESSION_CLOSED` (el stock del producto `16`, el correlativo `B001` y el Kardex permanecen intactos).

## 5. Esquemas compartidos

Los esquemas completan las tablas y ejemplos de sección 4. Cada endpoint devuelve una **proyección explícita**, no un recurso universal: los ejemplos fijan sus claves, y esta sección sus tipos y significado. No se añade currency a los ejemplos. Todos los importes representan PEN.

### 5.1. Tipos y estados

| Tipo | Definición |
| --- | --- |
| ID | Entero positivo persistido. No prefijos locales ni booleanos como enteros. |
| Dinero entrada | Número JSON finito o cadena decimal; máximo 2 decimales y límites de 2.4. No booleanos, arreglos, comas ni vacío. |
| Dinero salida | Número JSON de valor decimal calculado en céntimos exactos; 190, 190.0 y 190.50 equivalen. No se promete conservar ceros finales. |
| Fecha / Hora | YYYY-MM-DD real / HH:mm 24 horas. |
| Timestamp | YYYY-MM-DDTHH:mm:ss-05:00 generado por servidor, America/Lima. |
| Estado sesión BD/API | ABIERTA/CERRADA; etiqueta status Abierta/Cerrada. |
| Estado turno | Entero estado 1 activo, 0 inactivo. No cadenas ACTIVO/INACTIVO. |
| Estado arqueo API | Vigente, Reemplazado, Desactualizado. El arqueo usado al cerrar conserva su evidencia y versión evaluada. |
| Estado solicitud BD | EN_PROCESO, CONFIRMADA, FALLIDA; nunca COMPLETADA. |

El catálogo de esta versión conserva fin>inicio dentro del día (EP-02/03); este documento no introduce soporte de turnos nocturnos. Una sesión puede permanecer abierta después de medianoche sin cambiar su salida de referencia. Si se amplía el catálogo para cruzar medianoche deberá acordarse y actualizarse el contrato antes de implementar esa ampliación.

Conversión exacta: validar la representación decimal antes de construir céntimos; separar entero/fracción, completar fracción a 2 posiciones y operar enteros de 64 bits. No aceptar 3 decimales mediante round(). Para números JSON conservar el token decimal al validar o utilizar parser decimal equivalente; no deducir precisión original de un float ya redondeado. Esta es una adaptación futura, no implementación ya verificada.

### 5.2. Turno y programación — HU 18

TurnoResource EP-01/02/03: id ID, nombre cadena 1..60, inicio/fin Hora con fin>inicio, estado 0/1, vendedores_asignados entero>=0. EP-02/03 añaden data.result_code de la operación. La baja es lógica y conserva referencias históricas.

| ProgramacionSemanalResource | Tipo y significado |
| --- | --- |
| usuario_id | ID del vendedor. |
| semana_inicio | Fecha lunes consultada o modificada. |
| alcance | desde para base, semana para excepción puntual. |
| origen_semana_inicio | EP-05: fecha inicial de regla resuelta. |
| tiene_programacion_base | Booleano, base aplicable a la semana. |
| tiene_semana_anterior | EP-05: booleano, programación efectiva en semana previa. |
| turnos | Arreglo de {turno_id,nombre,inicio,fin,dias}. dias contiene enteros distintos 0=lunes a 6=domingo. Distintos turnos pueden compartir día. |
| result_code | EP-06: SCHEDULE_SAVED, conservado en replay. |

No se sustituye turnos[] por un único turno diario. Un día sin presencia en todos los dias representa descanso. Primera programación requiere alcance=desde. Duplicación: EP-05 lee semana origen; EP-06 guarda sus turnos/días en destino con referencia nueva y alcance elegido, validando de nuevo turnos activos; no copia IDs de versiones.

### 5.3. Apertura y sesión — HU 11

- EP-07: vendedor_id ID, server_date Fecha, server_time Hora; status within_hours/outside_hours/no_shift. scheduled_shifts y available_shifts son arreglos. shift/recommended_shift_id admiten null si no hay sugerencia. requires_authorization_to_open siempre false. available_shifts no revela otros vendedores.
- ShiftResource de caja: {id,name,start,end,label}; ReferenceScheduleResource: {shift_id,shift_name,start,end,label}. Son proyecciones explícitas, no alias de entrada. label es descriptivo, no se analiza para calcular horarios.
- EP-08: is_open/is_owner/has_previous_close booleanos; session_id ID|null; reference_amount Dinero|null, reference_date Timestamp|null, reference_turn texto|null, counted_amount Dinero|null, occupied_by objeto|null con seller_name/shift_name/opened_at. Una sesión ajena no expone sus cifras.
- EP-09: opening_balance Dinero>=0; opening_difference Dinero firmado|null; opened_at Timestamp; version entero>=1; status Abierta. is_substitution/opened_outside_schedule son indicadores informativos, no bloqueos.
- Primera apertura: referencia y diferencia null, sin comparación contra cero. Referencia cero solo cuando existe un cierre real contado en cero.
- Sesión persiste dueño id_usuario_vendedor, turno, nombre y horas de referencia y **fecha_hora_salida_referencia completa**: fecha local de apertura más hora fin del turno elegido. No se desplaza a otro día por cerrar después ni por abrir después del fin elegido. Una salida ya pasada permite cierre sin credenciales según la regla acordada. Otro turno requiere cerrar y abrir sesión.
- EP-10/17 cerrado o ajeno: cifras propias no aplicables null, arreglos vacíos, banderas de propiedad falsas. EP-17 closed_summary=null; solo EP-21 recupera cierre con referencia propia.

### 5.4. Movimientos y correcciones — HU 15/16

| type API | tipo_movimiento BD | Efecto original | Corregible |
| --- | --- | --- | --- |
| cash_sale | VENTA_EFECTIVO | +amount; una contabilización | No |
| manual_income | INGRESO_MANUAL | +amount | Sí |
| admin_injection | INYECCION_ADMIN | +amount; identifica entregante | Sí |
| cash_out | SALIDA_CAJA | -amount | Sí |
| admin_withdrawal | RETIRO_ADMIN | -amount; identifica receptor | Sí |
| omitted_withdrawal | RETIRO_OMITIDO | 0 con ambas declaraciones true | No |
| correction | CORRECCION | balance_effect firmado de registro adicional | No; se referencia siempre el original |

MovimientoCajaResource: id/session_id IDs; type de tabla; label/effect/reference/responsible textos; amount dinero original inmutable; current_amount importe derivado del último eslabón; impact efecto histórico de esa fila, no importe vigente del original; reason texto; recorded_at Timestamp; status Confirmado/Corregido. administrator_id/name, origin_session_id y declaraciones son null cuando no aplican. Las proyecciones compactas de EP-15/16 conservan las claves de sus ejemplos.

CorreccionMovimientoResource: id/original_movement_id IDs; previous_correction_id ID|null; original_reference texto; original_amount dinero inmutable; previous_corrected_amount dinero efectivo previo; corrected_amount>=0; balance_effect firmado; reason explica error y motivo; recorded_at Timestamp; responsible texto; status Confirmada; effect etiqueta. Ingreso: nuevo-vigente; egreso: vigente-nuevo. Acumulados = importes efectivos; listado contable = efectos originales más ajustes. No sumar current_amount del original y ajustes otra vez.

No actualizar monto, motivo, autor ni fecha del original; el importe vigente se deriva de correcciones. Registrar cada corrección como movimiento adicional vinculado a su detalle, con FK/relación única que evite contar dos veces su efecto. Los retiros omitidos tampoco alteran cifras cerradas.

EP-12 es una proyección técnica acotada: hasta últimas 20 sesiones cerradas **antes de la apertura actual**, page_size 1..20 default 10, sin cursor/paginación para ampliar al historial. id/date/turn/closed_at/label/expected/counted/shortage son los únicos datos; shortage=max(expected-counted,0). EP-14 valida origen en ese conjunto elegible de la apertura, aunque fuera de otro vendedor. No expone ventas, movimientos, autorizaciones ni directorio de usuarios. La causa no se deduce del faltante; requiere ambas declaraciones explícitas true.

### 5.5. Arqueo, horario y autorización — HU 12/13/14

ArqueoResource: {id,session_id,date,time,recorded_at,expected,counted,difference,observation,status,version}. date/time derivados de recorded_at; difference=counted-expected exacto; observation null o texto 1..500 obligatorio con diferencia!=0; version es la evaluada al contar. No se añaden modos ni tabla de denominaciones; helpers visuales de conteo no cambian esta entrada de monto directo.

ScheduleResource EP-17 y contexto EP-20: scheduled_end Hora conservada; current_time Hora del servidor; tolerance_minutes=15; early_threshold salida-15; minutes_remaining_to_end=max(0,ceil((salida-reloj)/60)), informativo; requires_authorization booleano **reloj<fecha_hora_salida_referencia-15 minutos**; reason null si no se requiere, o texto Cierre anterior al margen de quince minutos. El cálculo utiliza timestamps completos, no solo HH:mm ni minutos redondeados.

Salida 12:00: 11:44:59 exige credenciales; 11:45:00, 11:50, 12:00 y 12:35 no. Sesión aún abierta después de medianoche conserva salida original. No se introduce closing_observation adicional: arqueo tiene observation y autorización tiene exception_reason.

AutorizacionCierreResource: required=true, id, administrator_id, administrator_name, exception_reason 1..250, scheduled_end Hora, actual_close_time Hora, minutes_before_end entero informativo, authorized_at Timestamp; fila conserva sesión/vendedor/operación. EP-20 authorization=null si no hubo excepción; EP-23C authorizations=[] en ese caso. No códigos de autorización ni contraseñas persistidas/devueltas.

### 5.6. Consultas y paginación — HU 17/19

EP-22: {type,has_active_session,session,summary,movements,pagination}; sin activa propia: session=null, summary=null, movements=[], paginación vacía. Con sesión sin operaciones: expected conserva opening_balance, solo acumulados de operaciones en cero. EP-23C: {type,session,summary,authorizations,movements,pagination}, session_id explícito, no selección automática alternativa.

Resumen cierre: opening_fund/opening_difference/cash_sales/card/yape_plin/income/expenses/expected/counted/difference/observation/closed_at, según EP-20/21. En reporte EP-24A la proyección usa closing_difference, card_sales y yape_plin_sales; son nombres explícitos de esa proyección, no alias de entrada. Opening_difference puede ser null en primera sesión y no se confunde con diferencia final.

PaginationResource: page>=1, page_size 1..100, total_pages>=0, total_items>=0. total_movements/total_closures derivan de total_items en su contexto. Vacío: total_pages=0,total_items=0; se conserva page. Fuera de página: filas vacías y totales globales intactos. Default 10 salvo EP-24B default 20. EP-12 es arreglo acotado; EP-23B arreglo completo del día/vendedor, sin paginación añadida.

## 6. Catálogo unificado de errores y estados HTTP

Cada código tiene un solo HTTP. Los errores generales aplican en todos los endpoints donde se cumple la condición aunque su lista local destaque solo los específicos. Envoltorio: `{status:false,code,message,errors:{},data:null}` salvo contexto documentado. errors vacío debe serializarse como objeto, no arreglo. No usar ok:false como envoltorio del servidor.

| HTTP | Código | Condición |
| --- | --- | --- |
| 400 | `INVALID_JSON` | JSON ilegible/vacío donde se requiere. |
| 401 | `AUTH_REQUIRED` | Solicitante no autenticado, sesión vencida o usuario inactivo. |
| 403 | `CSRF_INVALID` | CSRF ausente o inválido en mutación. |
| 403 | `FORBIDDEN` | Permiso insuficiente o rol operativo distinto de Vendedor. |
| 403 | `FORBIDDEN_ADMIN_ONLY` | Se exige Administrador activo y permiso administrativo. |
| 403 | `HISTORICAL_QUERY_NOT_ALLOWED` | Recuperación de cierre sin referencia propia confirmada; no historial general. |
| 403 | `INVALID_ADMIN_CREDENTIALS` | Autorizador incorrecto/inactivo/no administrador; mantener login del vendedor. |
| 403 | `SESSION_NOT_OWNED` | Recurso ajeno; no revelar estado ni cifras. |
| 404 | `CASH_SESSION_NOT_FOUND` | Sesión no localizada dentro del alcance permitido. |
| 404 | `ENDPOINT_NOT_FOUND` | Ruta no existente. |
| 404 | `MOVEMENT_NOT_FOUND` | Original no localizado en alcance autorizado. |
| 404 | `SHIFT_NOT_FOUND` | Turno inexistente o inactivo. |
| 404 | `SNAPSHOT_NOT_FOUND` | Snapshot inexistente/ajeno/expirado/tipo incorrecto. |
| 404 | `USER_NOT_FOUND` | Usuario objetivo inexistente. |
| 405 | `METHOD_NOT_ALLOWED` | Verbo no admitido. |
| 409 | `ARQUEO_MISSING` | No existe arqueo persistido utilizable. |
| 409 | `ARQUEO_STALE` | Arqueo desactualizado/reemplazado o versión distinta; sin consumir autorización. |
| 409 | `AUTHORIZATION_REQUIRED` | Cierre anterior a salida-15 sin credenciales; data.schedule. |
| 409 | `BALANCE_OVERFLOW` | Acumulado fuera de capacidad; rollback de toda operación. |
| 409 | `CASH_ALREADY_OPEN` | Ya existe una sesión abierta global; occupied_by mínimo. |
| 409 | `CASH_CHANGED` | Versión de conteo obsoleta; data.expected y data.version. |
| 409 | `CASH_SESSION_CLOSED` | No existe sesión propia abierta o el objetivo propio ya cerró. |
| 409 | `CASH_SESSION_NOT_CLOSED` | Detalle de reporte solicitado para sesión abierta. |
| 409 | `CONCURRENT_OPERATION_CONFLICT` | Timeout/deadlock; rollback completo y reintento con misma referencia. |
| 409 | `CORRECTION_NOT_ALLOWED` | Venta, retiro omitido o corrección no son originales corregibles. |
| 409 | `IDEMPOTENCY_PAYLOAD_MISMATCH` | Misma referencia/usuario/tipo con datos funcionales distintos. |
| 409 | `INSUFFICIENT_BALANCE` | Salida/ajuste dejaría saldo negativo; data.current_balance. |
| 409 | `INSUFFICIENT_STOCK` | Stock insuficiente, sin efectos parciales de venta. |
| 409 | `REQUEST_IN_PROGRESS` | Misma solicitud concurrente en proceso. |
| 409 | `SHIFT_HAS_ASSIGNED_SELLERS` | Baja de turno con programación vigente/futura. |
| 409 | `SHIFT_IN_USE` | Baja de turno con sesión abierta. |
| 409 | `SHIFT_NAME_ALREADY_EXISTS` | Nombre de turno activo duplicado. |
| 409 | `SNAPSHOT_FILTER_MISMATCH` | Filtros explícitos distintos a snapshot. |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Mutación nueva con cuerpo distinto de application/json; DELETE sin cuerpo no exige Content-Type. |
| 422 | `ADMINISTRATOR_REQUIRED` | Identificación de entregante/receptor inválida. |
| 422 | `AMBIGUOUS_ALIAS_VALUES` | Alias/campo/cabecera normalizados con valores distintos. |
| 422 | `AMOUNT_OUT_OF_RANGE` | Importe superior a 99999999.99. |
| 422 | `CONFIRMATION_REQUIRED` | Falta alguna de las dos declaraciones true. |
| 422 | `DUPLICATE_SHIFT_IN_SCHEDULE` | Mismo turno repetido. |
| 422 | `INVALID_ADMIN_EMAIL` | Correo de autorizador con formato inválido. |
| 422 | `INVALID_AMOUNT` | No numérico, booleano, negativo, cero prohibido o más de 2 decimales. |
| 422 | `INVALID_DATE` | Fecha calendario inválida. |
| 422 | `INVALID_DATE_RANGE` | Rango inválido/invertido. |
| 422 | `INVALID_MOVEMENT_TYPE` | Tipo no admitido por ingresos/egresos. |
| 422 | `INVALID_SCHEDULE_DAYS` | Días vacíos, repetidos o fuera de 0..6. |
| 422 | `INVALID_SHIFT` | Turno elegido inexistente/inactivo. |
| 422 | `INVALID_SHIFT_HOURS` | Hora inválida o fin<=inicio. |
| 422 | `INVALID_WEEK_START_DATE` | Fecha inexistente o distinta de lunes. |
| 422 | `MOTIVE_REQUIRED` | Motivo requerido sin contenido. |
| 422 | `NO_CORRECTION_CHANGE` | Importe corregido igual al vigente. |
| 422 | `OBSERVATION_REQUIRED` | Arqueo con diferencia sin observación. |
| 422 | `ORIGIN_TURN_REQUIRED` | Origen no cerrado antes de apertura actual o fuera del conjunto elegible. |
| 422 | `TARGET_NOT_SELLER` | Objetivo no vendedor activo. |
| 422 | `VALIDATION_ERROR` | Tipos, requeridos, longitud, referencia o paginación inválidos; errors por campo. |
| 500 | `EXCEL_GENERATION_FAILED` | Fallo XLSX, devolver JSON y conservar filtros. |
| 500 | `INTERNAL_ERROR` | Error inesperado sin SQL, secretos ni stack. |
| 500 | `PDF_GENERATION_FAILED` | Fallo PDF, devolver JSON y conservar filtros. |

Validaciones comerciales heredadas de ventas siguen VentaService/InventarioException; este contrato añade los controles de caja sin renombrar globalmente otros módulos. La normalización de errores nuevos antes del dispatch y en CSRF debe implementarse expresamente: hoy no todos incluyen estas claves. No se presenta como conducta ya existente.

## 7. Roles, permisos y propiedad

### 7.1. Matriz definitiva

| Endpoints | Rol | Permiso exacto | Alcance |
| --- | --- | --- | --- |
| EP-01..06 | Administrador 1 | turnos.programar | Catálogo y programación. |
| EP-07..09 | Vendedor 2 | turnos.abrir | Selección/apertura propias. |
| EP-10..16 | Vendedor 2 | turnos.movimientos | Sesión propia; EP-11 identificación mínima; EP-12 origen acotado. |
| EP-17..19 | Vendedor 2 | turnos.arqueo | Arqueos de sesión propia abierta. |
| EP-20..21 | Vendedor 2 | turnos.cerrar | Cierre y recuperación por referencia. |
| EP-22 | Vendedor 2 | turnos.acceder | Sesión activa propia. |
| EP-23A/B/C | Administrador 1 | turnos.consultar_historico | Consulta actual/histórica y autorizaciones. |
| EP-24A/B | Administrador 1 | reportes.cierres_caja | Consulta de cierres. |
| EP-24C/D | Administrador 1 | reportes.cierres_caja y reportes.exportar | Ambas necesarias. |
| EP-25 | Vendedor 2 | ventas.procesar | Venta con sesión propia abierta. |

Cuatro controles: autenticación activa, permiso RBAC, rol de negocio y propiedad/estado. El bypass RBAC del Administrador no concede operaciones de Vendedor. No eliminar permisos existentes para conseguirlo: validar rol en dominio.

Identidad real: $_SESSION['idUser'] validada en BD y $this->authenticatedUserId; no inventar user_id. CSRF en POST/PUT/DELETE; GET no muta negocio (el snapshot puede persistir metadatos técnicos). Identificar administrador en movimiento no autentica al autorizador.

### 7.2. Proyecciones y recuperación

Vendedor solo consulta sesión propia activa en EP-10/15/17/18/22. No lista cierres recientes. EP-12 es proyección acotada para retiro omitido, no explorador histórico. EP-21 exige session_id y referencia propia de cierre confirmado: no caduca por cambiar fecha o abrir otra sesión. Conocer referencia no sustituye autenticación ni propiedad. Administrador usa EP-23/24 para supervisar; no endpoints operativos del Vendedor. ID ajeno no revela si la sesión está cerrada/abierta. Los IDs de vendedor enviados por cliente no sustituyen identidad del servidor.

## 8. Concurrencia, idempotencia y recuperación

### 8.1. Referencia y tipos definitivos

Canónica referencia_solicitud, alias request_id, cabecera X-Request-Reference: 16..64 con ^[A-Za-z0-9_-]{16,64}$. No se introduce Idempotency-Key. Se requiere al menos una ubicación y todos los valores presentes deben coincidir. EP-04 DELETE usa cabecera obligatoria. GET no exige referencia salvo EP-21 para identificar la operación recuperada.

| Escritura | tipo_operacion | HTTP original / result_code |
| --- | --- | --- |
| EP-02 | TURNO_CREAR | 201 / SHIFT_CREATED |
| EP-03 | TURNO_EDITAR | 200 / SHIFT_UPDATED |
| EP-04 | TURNO_BAJA | 200 / SHIFT_DELETED |
| EP-06 | PROGRAMACION_HORARIO | 200 / SCHEDULE_SAVED |
| EP-09 | CAJA_ABRIR | 201 / OPENED |
| EP-13 | CAJA_INGRESO | 201 / MOVEMENT_REGISTERED |
| EP-14 | CAJA_EGRESO | 201 / MOVEMENT_REGISTERED |
| EP-16 | CAJA_CORRECCION | 201 / CORRECTION_SAVED |
| EP-19 | CAJA_ARQUEO | 201 / ARQUEO_SAVED |
| EP-20 | CAJA_CERRAR | 200 / CLOSE_CONFIRMED |
| EP-25 | VENTA (existente) | 201 / SALE_CREATED |

Reutilizar ControlSolicitudService y su tupla (id_usuario,tipo_operacion,referencia_solicitud). Antes del hash: resolver alias; importes a representación canónica de céntimos; trim a textos funcionales; ordenar claves asociativas y turnos/días de programación. No trim ni normalización de contraseña: password_verify recibe valor original en memoria.

Excluir referencia, CSRF y credenciales de la identidad funcional. Cierre incluye sesión objetivo, arqueo y motivo de excepción; el administrador real queda como evidencia de la confirmación. No guardar payload con secretos ni hashes derivados de contraseña. La sesión objetivo de un replay es la registrada originalmente, no la nueva sesión activa del usuario. No añadir hora actual o saldo recalculado al hash.

Confirmada con misma identidad/hash: 200 OPERATION_ALREADY_CONFIRMED, idempotent_replay=true y data original con result_code. Distinto hash: 409 IDEMPOTENCY_PAYLOAD_MISMATCH. Simultánea en proceso: 409 REQUEST_IN_PROGRESS. Fallo: rollback sin confirmación falsa. La autorización y el cierre se confirman juntos.

Orden: autenticación/permiso/rol, transporte y alias, consulta idempotente con propiedad original, después validaciones actuales para una operación nueva. Recuperar confirmación antes de comprobar caja ABIERTA, stock actual o nuevas credenciales. Incluye replay de venta confirmada después del cierre y de cambios de stock. No volver a descontar ni pedir contraseña para recuperar un resultado ya confirmado.

Referencias confirmadas permanecen recuperables, sin caducidad de medianoche; no confundir con snapshots temporales de reportes. El helper Api.referenciaSolicitud debe recibir datos funcionales sin admin_password (ni cualquier otro secreto): hoy calcula huella del payload y la conserva en sessionStorage. No pasarle el formulario completo de autorización. Cliente conserva referencia hasta conocer el resultado; cambio de dato funcional usa otra referencia. La identidad del snapshot no autoriza ninguna mutación.

### 8.2. Bloqueos y vigencia

Misma conexión PDO y transacción para caja, venta, stock, correlativo, Kardex e intención/confirmación idempotente. Orden coherente: solicitud, guardia de apertura cuando aplique, sesión, original/arqueo/turno según operación, productos por ID ascendente y correlativo conforme al orden revisado de VentaService. Revisar todos los servicios antes de integrar. No prometer ausencia de deadlocks: timeout/deadlock revierte operación completa y devuelve CONCURRENT_OPERATION_CONFLICT.

| Carrera | Serialización y resultado |
| --- | --- |
| Apertura/apertura | Guardia global estable e índice único compatible con MariaDB para una ABIERTA global. No confiar en FOR UPDATE de conjunto vacío. Una confirma, otra CASH_ALREADY_OPEN; misma referencia replay. |
| Apertura/cierre | Solo usar cierre confirmado como referencia; no observar cifras parciales. Mientras siga ABIERTA, no abrir otra sesión. |
| Egreso/egreso | Lock sesión; segundo lee saldo confirmado por primero. 100 y dos salidas 80: una deja 20, otra INSUFFICIENT_BALANCE. |
| Corrección/corrección | Lock sesión y original como mutex; original inmutable, último eslabón leído bajo bloqueo. Ajuste de segunda contra vigente real. |
| Operación/arqueo | Ambos bloquean sesión. Cada venta de cualquier medio, movimiento incluso omitido impacto cero y corrección incrementa version_caja e invalida vigente. Replay no incrementa. |
| Arqueo/arqueo | Lock sesión; no incrementa version_caja, cambia id_ultimo_arqueo. Solo último vigente cierra. Conservar causas previas de invalidez/reemplazo. |
| Venta/cierre | Venta primero: cierre ARQUEO_STALE. Cierre primero: venta CASH_SESSION_CLOSED sin stock/correlativo/Kardex parciales. |
| Cierre/cierre | Misma referencia replay; otra referencia CASH_SESSION_CLOSED. Un cierre y como máximo una autorización consumida por sesión. |
| Cambio turno/baja/apertura/programación | Lock turno antes de usar/cambiar/bajar; apertura copia horario dentro de transacción. Cambios posteriores no alteran snapshot. |
| Programación/programación | Lock fila estable de vendedor antes de insertar versiones/detalles aunque no existieran filas de programación. |

Cierre verifica id_ultimo_arqueo=arqueo_id y versión coincidente bajo lock. Hora de cierre se toma bajo bloqueo al confirmar, no del navegador ni del inicio de una espera larga. Incremento final de versión al cerrar no invalida arqueo utilizado: conserva su versión evaluada y cifras congeladas. Admin válido no omite estas reglas.

### 8.3. Programación y precedencia confirmada

Resolver la base más reciente con semana_inicio<=consulta y después aplicar la excepción exacta de esa semana si existe. Una nueva base desde W no elimina ni reemplaza las excepciones semanales existentes: cada excepción conserva su prioridad en su semana y luego vuelve a la base vigente. Confirmado por el propietario en sección 13.1. Conservar todas las versiones anteriores. Primera programación requiere desde; semana sin base aplicable da VALIDATION_ERROR. Duplicación mediante EP-05 y EP-06 con alcance elegido y referencia nueva.

## 9. Consultas, filtros y descargas

### 9.1. Filtros y lecturas consistentes

| Endpoint | Filtro y alcance |
| --- | --- |
| EP-12 | Cerradas antes de apertura actual; conjunto acotado de 5.4. |
| EP-21 | session_id y referencia propia, sin filtro por día. |
| EP-22 | Sesión propia activa; movimientos recorded_at ASC, id ASC. |
| EP-23B | date y seller_id obligatorios; turn_id opcional; fecha local de apertura; todas las sesiones del día/vendedor en apertura ASC, id ASC. |
| EP-23C | session_id explícito; movimientos paginados de esa sesión en recorded_at ASC, id ASC. |
| EP-24A | desde/hasta inclusivos por fecha local de cierre; turno_id/vendedor_id opcionales; solo CERRADA; cierre DESC, id DESC. |
| EP-24B | ID de sesión cerrada de ruta; movimientos de ese cierre paginados; no es otro reporte por rango. |
| EP-24C/D | Mismos filtros/orden de EP-24A o snapshot propio concordante; todas las filas. session_id opcional limita a un cierre administrativo. |

Rango inclusivo equivale a >=desde 00:00:00 y <día_siguiente(hasta) 00:00:00, incluyendo fracciones. Defaults desde/hasta: primer día del mes actual y fecha actual, resueltos una vez en America/Lima y devueltos en filters. Query ausente usa default; valor mal formado/vacío no se convierte en cero o ID nulo válido.

Una lectura calcula filas/totales bajo el mismo snapshot transaccional. Sesión abierta puede cambiar entre peticiones: mostrar version y refrescar coherentemente. Totales abarcan todo el conjunto. Sin movimientos conserva saldo inicial y esperado; sin resultados no sustituir fecha/sesión.

### 9.2. Snapshot administrativo de reporte

Identificador definitivo **snapshot_id** entero positivo; tipo nuevo definitivo **cierres_caja**. Reutilizar cabecera reporte_consulta_snapshot (propietario/filtros/resumen/fechas) y una tabla nueva reporte_caja_snapshot_item para filas congeladas de cierres; no insertar sesiones como id_kardex en los ítems existentes.

EP-24A sin snapshot_id captura filtros normalizados, conjunto completo de filas proyectadas, totales, propietario, orden y generated_at. Devuelve snapshot_id y expires_at Timestamp. Páginas siguientes envían mismo ID y page/page_size. El conjunto se materializa completo, no solo la página ni una hora de corte sobre datos mutables. Debe conservar cifras y proyecciones para que posteriores declaraciones de retiro omitido no alteren el reporte ya visto.

Vigencia técnica: 30 minutos desde creación; cada lectura válida renueva a 30 minutos desde ese acceso, siguiendo el patrón existente de Models/KardexModel.php y Services/ReporteService.php. Devolver expires_at efectivo tras renovación. Expirado/ajeno/de tipo distinto/inexistente: 404 SNAPSHOT_NOT_FOUND. Los filtros se conservan en frontend para crear nueva consulta; no prometer que el nuevo conjunto sea idéntico. Esta expiración de reportes no aplica a referencias de operaciones confirmadas.

Con snapshot_id, filtros ausentes se toman del snapshot; filtros explícitos deben coincidir con normalizados guardados, incluido session_id si fue generado para detalle. Discrepancia: 409 SNAPSHOT_FILTER_MISMATCH. Page/page_size pueden cambiar sin modificar conjunto. Exportación con snapshot comprende todas sus filas; sin snapshot captura una lectura nueva consistente. No añadir snapshot ni descarga a EP-23B/C.

La rutina de snapshots de caja no debe ejecutar la limpieza global DELETE del helper heredado de Kardex ni afectar snapshots de otros reportes. Este sprint no elimina datos existentes. Un vencimiento lógico impide usar el snapshot pero no autoriza su eliminación física. La retención/limpieza física se decidirá aparte, sin incorporarla silenciosamente.

### 9.3. Descarga binaria

| Endpoint | Método/ruta | MIME de éxito | Nombre de archivo |
| --- | --- | --- | --- |
| EP-24C | GET /api/reportes/exportar-pdf?tipo=cierres-caja | application/pdf | Reporte_Cierres_Caja_YYYY-MM-DD_HHmmss.pdf |
| EP-24D | GET /api/reportes/exportar-excel?tipo=cierres-caja | application/vnd.openxmlformats-officedocument.spreadsheetml.sheet | Reporte_Cierres_Caja_YYYY-MM-DD_HHmmss.xlsx |

HTTP 200 binario completo, Content-Disposition attachment con nombre de tabla generado por servidor, Cache-Control private,no-store,max-age=0, X-Content-Type-Options nosniff. No JSON envolviendo un archivo exitoso. En fallo: HTTP del error y application/json con status:false; no archivo corrupto/HTML ni éxito anticipado. Conjunto vacío puede exportar encabezados y cero filas/totales; jamás otra fecha. No recortar por page_size.

Frontend conserva filtros y snapshot vigente para reintentar; si expiró debe consultar de nuevo conservando filtros. Diseño visual y librerías concretas se revisan con el usuario en paso 14. No ticket individual.

## 10. Mapeo frontend y adaptaciones futuras

La tabla describe componentes existentes y trabajo futuro. No afirma que las rutas de caja ya persistan en backend ni que los mocks prueben integración.

| Componente real | Adaptación futura | Paso |
| --- | --- | --- |
| index.php, Libraries/Core/Load.php | Subrutas completas y registro turnos/programaciones/caja; regresión de rutas existentes. | 6 |
| Libraries/Core/ApiController.php, AuthMiddleware, RBACMiddleware | Reutilizar idUser, usuario activo, permisos/CSRF; rol explícito y propiedad; normalizar errores nuevos sin cambiar sesión. | 6–12 |
| Assets/js/api.js | Distinguir wrapper ok/status/data del JSON servidor status/code/data; enviar X-Request-Reference desde referencia conservada y manejar Content-Type binario/error. No afirmar que hoy ya inyecte referencia. | 13–14 |
| Assets/js/usuarios-horarios-mock.js, Assets/js/usuarios.js | EP-01..06, turnos[] con dias 0..6, base, excepción, duplicación y varios turnos diarios. | 6,13 |
| Assets/js/cajaService.js, Assets/js/caja.js, Views/Caja/abrir.php | EP-07..09, selector turno, null inicial, sin autorización ni cifras demo; no ocultar errores de apertura. | 7,13 |
| Assets/js/cajaMovimientosService.js, Assets/js/cajaMovimientos.js | EP-10..16; identificar admin sin autenticar; declaraciones separadas; corrección adicional sin editar. Normalizar alias manual a manual_income. | 8,13 |
| Assets/js/cajaArqueoService.js, Assets/js/cajaArqueo.js | EP-17..21, version/ID real, advertir cambios sin guardar, horario conservado y umbral -15, recuperar con referencia. | 10–11,13 |
| Assets/js/cajaConsultaService.js, Assets/js/cajaConsulta.js | EP-22 vendedor; EP-23 administrador con sesión explícita; totales completos y paginación. | 12–13 |
| Controllers/ReporteController.php; Views/Reporte/cierresCaja.php y detalleCierreCaja.php | Sustituir demo por EP-24; filtros/snapshot_id/paginación/roles y diferencias separadas. | 12–14 |
| Services/VentaService.php, Controllers/API/VentaApiController.php | EP-25 conserva entrada/resultado heredado y añade caja, misma transacción; replay antes de efectos y validaciones mutables. | 9 |
| Services/ControlSolicitudService.php | Reutilizar identidad/transacción/replay, preparar payload funcional sin secretos, ampliar tipos mediante migración. | 6–11 |
| Config/Config.php, Libraries/Core/Conexion.php | Centralizar America/Lima y SET time_zone='-05:00' por conexión; no depender de Header.php para API. | 6 |

No se utilizan funciones/archivos functions_caja_apertura.js, functions_caja_arqueo.js ni functions_caja_cierre.js inexistentes en el checkout inspeccionado. La presentación puede conservar nombres locales, pero su adaptador debe mapearlos al único contrato de sección 4.

## 11. Condiciones para migraciones (database/tercerSprint)

### 11.1. Conservación y ensayo

Paso 5 prepara scripts incrementales numerados y README en **database/tercerSprint**, ensayados primero en copia restaurada separada. No aplica cambios a amazon_market en esta etapa documental. Respaldo con datos reales fuera de repositorio/htdocs. No ejecutar limpieza/reinicialización de sprints anteriores ni importar dump raíz como base vigente.

Base inspeccionada de solo lectura el 12 de noviembre de 2026; revalidar antes de migrar, sin convertir conteos históricos en restricciones permanentes:

| Objeto | Conteo |
| --- | ---: |
| Tablas base / triggers | 32 / 5 |
| usuario / rol | 6 / 2 |
| permiso / rol_permiso | 31 / 30 |
| producto / suma stock_actual | 108 / 2497 |
| venta / detalle_venta | 250 / 295 |
| compra / detalle_compra | 80 / 100 |
| kardex / operacion_inventario | 470 / 75 |
| control_solicitud_idempotencia | 405 |
| reporte_consulta_snapshot / ítems | 2 / 150 |

Dos administradores activos: ID 1 sin correo; ID 9 con correo. No publicar datos personales ni inventar correos. Cuenta 9 puede autorizar por correo si el propietario dispone de credenciales; configurar cuenta 1 no es requisito para cerrar especificación ni se ejecuta automáticamente.

Ensayo compara todas las tablas originales, filas, IDs/contenidos, correlativos, stock, archivos sprint2_archivo_*, motores, colaciones, índices/FK/triggers. Solo diferencias estructurales/permisos expresamente previstas. No crear sesiones ficticias para operaciones históricas. No borrar ni archivar filas previas.

MariaDB 10.4.32, InnoDB. Nuevas tablas utf8mb4_unicode_ci; conservar colaciones/tipos existentes; FK compatibles en tipo/signo. Dinero nuevo DECIMAL(10,2), comprobar overflow de acumulados. No tipo opcional mayor que cambie límites sin actualizar contrato. DDL tiene commits implícitos: fallo exige detener/diagnosticar, no prometer rollback global ni reejecutar a ciegas.

### 11.2. Estructuras mínimas

| Dominio | Obligaciones para diseño SQL del paso 5 |
| --- | --- |
| turno | id_turno, nombre, hora_inicio/hora_fin mapeadas a inicio/fin API, estado 0/1, unicidad activa compatible y baja lógica. |
| programación | Cabecera versionada por vendedor/semana lunes/alcance/autor/vigencia; detalle con turno y día 0..6. Unicidad por versión+turno+día, no vendedor+día que impida varios turnos. Conservar todas las versiones. |
| sesion_caja | id_usuario_vendedor, turno y horario snapshot, salida completa, apertura real, referencia cierre anterior nullable, conteo inicial y diferencia nullable, estado/version, acumulados por medio, último arqueo y cierre congelado. Guardia estable e índice único ABIERTA global. No añadir unicidad vendedor+turno+fecha que prohíba reapertura no acordada. |
| movimiento_caja | Original inmutable, sesión/tipo de 5.4/monto/efecto/motivo/autor/fecha; admin identificado nullable; venta vinculada cuando cash_sale y origen/declaraciones cuando omitido. Una línea de efectivo por venta, sin duplicidad. |
| correccion_movimiento_caja | Registro inmutable de sesión/original/eslabón previo/importe anterior-nuevo/efecto firmado/motivo/autor/fecha. Relación única con movimiento adicional de corrección; una contabilización. current_amount se deriva, no sobrescribe original. |
| arqueo_caja | Conteo/esperado/diferencia/observación, sesión/autor/versión evaluada/estado/causa de invalidez/fecha. Conservar cada conteo. Sin denominaciones nuevas. |
| autorizacion_caja | Sesión, cierre y solicitud concretos; vendedor/admin/motivo/salida prevista/fecha real. Una consumida por cierre y misma transacción. Sin columnas de password/código secreto. |
| venta | Añadir id_sesion_caja NULL con FK compatible. Todas las ventas previas conservan NULL. Nuevas exigen relación en servicio. No backfill ficticio ni NOT NULL sobre legado. |
| reporte_consulta_snapshot | Reutilizar cabecera y añadir tipo cierres_caja; propietario/filtros/resumen/fechas. Preservar registros existentes. |
| reporte_caja_snapshot_item | Nueva tabla específica con cabecera, orden, sesión de cierre y proyección congelada serializada validada. Reutilizar header, no id_kardex de ítems originales ni alterar sus 150 filas. |

Nombres físicos nuevos se fijan en SQL respetando esta tabla y se documentan en README; las proyecciones API no son columnas alternativas. Validación de rol/propiedad/estado corresponde también a servicio: FK no la implementa por sí solo. El diseño SQL conserva versiones y aplica la prioridad de excepciones confirmada en 13.1.

### 11.3. Ampliaciones exactas

ENUM actual control_solicitud_idempotencia.tipo_operacion: **BAJA, AJUSTE, COMPRA, CORRECCION_COMPRA, ANULACION_COMPRA, VENTA, ANULACION_VENTA**. Conservar siete y añadir los diez tipos nuevos de 8.1 (todos salvo VENTA ya existente). Conservar 405 filas y estados EN_PROCESO/CONFIRMADA/FALLIDA. No sustituir por AJUSTE_INVENTARIO o REPORTE_EXPORTACION inexistentes.

ENUM actual reporte_consulta_snapshot.tipo_reporte: **kardex, variaciones**; añadir únicamente **cierres_caja**. No reportes independientes de movimientos/diferencias/cierres anticipados no acordados.

| Nuevo permiso | Rol asignado | Uso |
| --- | --- | --- |
| turnos.movimientos | Vendedor 2 | EP-10..16 |
| turnos.arqueo | Vendedor 2 | EP-17..19 |
| turnos.programar | Administrador 1 | EP-01..06 |
| turnos.consultar_historico | Administrador 1 | EP-23A/B/C |
| reportes.cierres_caja | Administrador 1 | EP-24A/B/C/D |

Resolver ID por slug y WHERE NOT EXISTS; no asumir IDs libres ni borrar asignaciones anteriores. Reutilizar turnos.abrir/cerrar/acceder, ventas.procesar y reportes.exportar. Control de rol explícito evita que bypass admin conceda operación de vendedor.

PHP predeterminado Europe/Berlin y MariaDB SYSTEM efectivo -05:00: centralizar America/Lima y -05:00 por conexión en implementación, sin reescribir timestamps históricos. No migrar correos, contraseñas ni saldos.

## 12. Casos de aceptación y rechazo

**Pruebas futuras, no resultados de APIs ejecutadas.** Rutas/campos/códigos son los de las especificaciones anteriores.

| Caso | HU / endpoints | Escenario | Resultado verificable |
| --- | --- | --- | --- |
| CA-01 | 18; EP-02/03 | Crear/editar nombre 1..60 y horas 07:00..12:00 | SHIFT_CREATED/SHIFT_UPDATED, estado 1; replay conserva resultado. |
| CA-02 | 18; EP-02/03 | Nombre activo duplicado, fin<=inicio o referencia inválida | SHIFT_NAME_ALREADY_EXISTS, INVALID_SHIFT_HOURS o VALIDATION_ERROR según causa; sin escritura. |
| CA-03 | 18; EP-04 | Baja de turno asignado o en sesión abierta | SHIFT_HAS_ASSIGNED_SELLERS o SHIFT_IN_USE. Sin referencias activas, baja lógica conserva historia. |
| CA-04 | 18; EP-05/06 | semana_inicio miércoles 2026-11-11 | 422 INVALID_WEEK_START_DATE, no normalización silenciosa. |
| CA-05 | 18; EP-06 | Primera base con mañana+tarde el lunes | SCHEDULE_SAVED; ambos turnos ese día y semanas siguientes. |
| CA-06 | 18; EP-05/06 | Base habitual y excepción solo W+1 | W+1 excepción, W+2 base; historia anterior intacta. |
| CA-07 | 18; EP-05/06 | Duplicar semana origen a destino | Turnos/días copiados, referencia nueva, alcance elegido y activos revalidados. |
| CA-08 | 18; EP-05/06 | Excepción futura ya guardada, luego nueva base desde W | Excepción conserva prioridad en su semana; demás semanas usan nueva base; sin borrar versiones. |
| CA-09 | 11; EP-08/09 | Primera apertura, cash_counted=0 y sin cierre anterior | OPENED, opening_balance=0, reference_amount/opening_difference=null; comparación oculta. |
| CA-10 | 11; EP-09 | Referencia real 200, conteo nuevo 190 | Saldo inicial 190, diferencia -10, sin egreso ni cambio al cierre previo. |
| CA-11 | 11; EP-07/09 | Sin programación, descanso, fuera de horario o suplencia con turno activo | Apertura permitida sin credenciales; dueño real/horario snapshot y programación intacta. |
| CA-12 | 11; EP-09 | Dos aperturas concurrentes | Una OPENED y otra CASH_ALREADY_OPEN; una sola ABIERTA global. |
| CA-13 | 11/18; EP-09/20 | Mañana y tarde mismo vendedor | Cierra mañana antes de abrir tarde; sesiones/cifras separadas. |
| CA-14 | 15/16; EP-13/14 | Inyección 50 y retiro 30, admin identificado | +50/-30 con motivo; no contraseña admin. |
| CA-15 | 16; EP-14 | Saldo 80 y egreso 80/80.01 | 80 válido, saldo 0; 80.01 INSUFFICIENT_BALANCE. |
| CA-16 | 16; EP-12/14 | Retiro real anterior declarado y reflejado al abrir; origen de otro vendedor | MOVEMENT_REGISTERED, impacto 0, vínculo, cifras antiguas intactas. |
| CA-17 | 16; EP-14 | Declaración omitida/null/false o sin causa afirmada | CONFIRMATION_REQUIRED; no egreso artificial ni ajuste del cierre previo. |
| CA-18 | 15/16; EP-15/16 | Ingreso 50 corregido a 30 y luego 35 | Ajustes -20 y +5; original intacto, cadena íntegra, sin reaplicar todo importe. |
| CA-19 | 15/16; EP-16 | Ajeno, propio cerrado o no elegible | SESSION_NOT_OWNED, CASH_SESSION_CLOSED o CORRECTION_NOT_ALLOWED. |
| CA-20 | 12; EP-19 | Conteo 0 y diferencia 0.01 sin observación | Cero admisible; diferencia sin observación OBSERVATION_REQUIRED. |
| CA-21 | 12; EP-19/20 | Guardar arqueo, luego venta efectivo/tarjeta/digital o retiro omitido impacto 0 | Versión aumenta y cierre ARQUEO_STALE; nuevo arqueo conserva anteriores. |
| CA-22 | 12; EP-19 | Versión vieja o dos conteos válidos consecutivos | CASH_CHANGED con expected/version; solo último arqueo vigente cierra, historia intacta. |
| CA-23 | 13/14; EP-20 | Salida 12:00 y reloj 11:44:59 | Sin credenciales AUTHORIZATION_REQUIRED; con admin/motivo válidos cierre+autorización atómicos. |
| CA-24 | 13/14; EP-20 | Salida 12:00, reloj 11:45:00/11:50/12:00/12:35 | Sin credenciales en los cuatro casos, sin límite posterior ni cierre automático. |
| CA-25 | 13/14; EP-20 | Credenciales inválidas o arqueo viejo con admin válido | INVALID_ADMIN_CREDENTIALS o ARQUEO_STALE; sesión vendedor intacta, sin autorización consumida. |
| CA-26 | 13; EP-20/21 | Cierre confirmado sin respuesta; recuperar al día siguiente o tras otra apertura | Referencia propia devuelve original, sin duplicar y sin caducidad por fecha. |
| CA-27 | 13; EP-20 | Cierres concurrentes con referencias distintas | Uno confirmado y otro CASH_SESSION_CLOSED, un cierre/autorización consumida. |
| CA-28 | 11–17; EP-25 | Venta efectivo/tarjeta/digital | Todas vinculadas; solo efectivo aumenta caja; sin ingreso manual duplicado. |
| CA-29 | 11–17; EP-25/20 | Venta y cierre concurrentes | Venta primero invalida arqueo; cierre primero impide venta preservando stock/correlativo/Kardex. |
| CA-30 | 11–17; EP-25 | Replay venta confirmada tras cierre/cambio de stock | Recupera original antes de revalidar stock/sesión actuales; sin segundo descuento. |
| CA-31 | 17; EP-22 | Activa sin operaciones o sin sesión propia | Con activa conserva saldo inicial; sin activa no historial ni otra fecha. |
| CA-32 | 17; EP-23B/C | Varias sesiones o filtro vacío | Lista para selección explícita; vacío real; Vendedor FORBIDDEN_ADMIN_ONLY. |
| CA-33 | 19; EP-24A/B | Apertura -10 y cierre -5, varias páginas | Diferencias y medios separados; totales de todo conjunto. |
| CA-34 | 19; EP-24A/C/D | Exportar snapshot con >page_size, vacío, ajeno o filtros cambiados | Todas las filas; vacío sin datos ajenos; SNAPSHOT_NOT_FOUND o SNAPSHOT_FILTER_MISMATCH según causa. |
| CA-35 | 19; EP-24C/D | Fallo PDF/XLSX o snapshot vencido | Error JSON específico; filtros conservados; nueva consulta si expiró. |
| CA-36 | Todas; escrituras | Misma referencia/payload; payload distinto; simultánea | OPERATION_ALREADY_CONFIRMED, IDEMPOTENCY_PAYLOAD_MISMATCH, REQUEST_IN_PROGRESS. |
| CA-37 | Todas; alias | Canónico y alias coinciden o se contradicen | Normaliza iguales; distintos AMBIGUOUS_ALIAS_VALUES antes de mutar. |
| CA-38 | Todas; roles | Sin login, CSRF inválido, rol contrario o ID ajeno | AUTH_REQUIRED/CSRF_INVALID/error rol/SESSION_NOT_OWNED según condición, sin efectos. |
| CA-39 | 13/14; EP-20 | Sigue abierta tras medianoche o turno/programación editados | Conserva fecha_hora_salida_referencia original; no recalcular para día nuevo. |
| CA-40 | Todas; paso 5 futuro | Ensayo migración sobre copia | Filas/stock/IDs/correlativos/archivos previos conservados; ventas antiguas FK NULL; original sin cambios. |

## 13. Decisiones confirmadas y estado de revisión

### 13.1. Precedencia de programación confirmada en esta sesión

El propietario confirmó: **conservar el horario especial ya preparado para la próxima semana** aunque después se guarde un nuevo horario habitual con Desde esta semana en adelante.

Regla HU 18: una nueva base desde W cambia el horario habitual desde W, pero no elimina ni reemplaza automáticamente las excepciones semanales ya existentes. La excepción específica conserva prioridad en su semana; al terminar vuelve a la base que esté vigente. El administrador cambia una excepción expresamente guardando Solo esta semana sobre esa semana. Preservar cada versión y semanas anteriores.

Ejemplo: base inicial mañana; excepción en W+1 tarde; nueva base desde W mañana+tarde. Resultado W mañana+tarde, W+1 tarde, W+2 mañana+tarde. No se borra la excepción futura ni se exige copiar nuevamente horarios cada semana.

### 13.2. Pendientes de otras etapas que no bloquean este contrato

- Cuenta admin 1 sin correo: no se modifica por migración; admin 9 sí tiene correo. Si se quiere usar la cuenta 1 para autorizar, propietario define/actualiza su correo por el módulo correspondiente.
- Diseños PDF/Excel: usuario los entrega en paso 14; ahora se define transporte/datos/permisos, no presentación.
- Rendimiento: conservar el objetivo de autorización de HU 14 de 2 segundos y medir con entorno/carga documentados. Metas aún no concretadas se consultarán antes de validación de rendimiento. No se inventan cifras ni se afirma latencia medida.
- Ampliaciones como turnos que cruzan medianoche, denominaciones o otros reportes no se introducen mediante esquemas contradictorios. La sesión real que atraviesa medianoche sí está cubierta con su salida completa conservada.

### 13.3. Estado y límites de verificación

Contrato documental del paso 4 corregido para mantener rutas, tipos, permisos, errores, referencias y condiciones de migración coherentes. Cobertura EP-01..EP-25 con subdivisiones EP-23/24 y operaciones OP-01..OP-17; decisión de programación de 13.1 confirmada. No quedan decisiones funcionales bloqueantes identificadas para diseñar el esquema de este alcance.

La verificación documental comprueba cobertura, estructura, referencias internas, JSON y correspondencia con acuerdos/código/base inspeccionados. **No equivale a implementación ni a ejecución de APIs ni pruebas reales de concurrencia/rendimiento.** Paso 5 no iniciado. Solo se modifica este Markdown: sin cambios en Word, PHP/JS/configuración, esquema ni filas de BD.

Verificación realizada en este paso: 30 especificaciones con 16 puntos cada una; 13 secciones; 49 bloques JSON válidos; cobertura OP-01..OP-17; catálogo de errores coherente con los códigos usados; enlaces locales existentes; ejemplos contables y cronológicos revisados. Consulta de BD exclusivamente de lectura: 14 conteos de tablas, suma de stock 2497, 32 tablas base, 5 triggers y ENUM de operaciones concordantes con la inspección. No se ejecutaron APIs, migraciones ni pruebas de concurrencia/rendimiento.
