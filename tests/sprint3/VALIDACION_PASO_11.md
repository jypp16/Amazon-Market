# VALIDACIÓN PASO 11 - SPRINT 3: CIERRE DEFINITIVO DE CAJA, AUTORIZACIÓN ANTICIPADA Y RECUPERACIÓN DEL RESULTADO

## 1. Alcance Implementado

- **EP-20 (`POST /api/caja/cerrar`)**: Cierre definitivo de la sesión de caja abierta del vendedor autenticado (`id_rol = 2`, permiso `turnos.cerrar`).
  - Orden de bloqueos transaccional: `caja_fisica_guardia` (`bloquearGuardiaCajaFisica()`) → `sesion_caja FOR UPDATE` (`obtenerSesionPorIdParaCierre()`) → `arqueo_caja FOR UPDATE` (`obtenerArqueoPorId()`).
  - Identificación de la sesión objetivo real a partir del `arqueo_id` persistido (`409 ARQUEO_MISSING` si no existe, `403 SESSION_NOT_OWNED` si pertenece a otro vendedor sin revelar datos financieros, `409 CASH_SESSION_CLOSED` si la sesión objetivo ya está cerrada y la referencia es nueva, `409 ARQUEO_STALE` si está reemplazado/desactualizado).
  - Evaluación horaria determinista bajo bloqueo en zona horaria `America/Lima` con timestamp único de cierre (`requires_authorization = ahora < fecha_hora_salida_referencia - 15 minutos`).
  - Autorización anticipada (`autorizacion_caja`): validación de `admin_email`, `admin_password` (sin trim) y `exception_reason` (1..250 chars) mediante `AuthService::verificarCredencialesAdministradorPorEmail()`, sin mutar la sesión PHP (`$_SESSION`) del vendedor.
  - Conservación de nulabilidad de `summary.opening_difference` (`null` en primera sesión histórica sin cierre previo; número `0`, positivo o negativo cuando existe cierre previo).
  - Idempotencia (`CAJA_CERRAR`) excluyendo `admin_password` y `password_hash` del `payload_hash` y del almacenamiento/respuesta.
- **EP-21 (`GET /api/caja/cierre?session_id={id}&referencia_solicitud={ref}`)**: Recuperación de solo lectura del resultado del cierre (`data` si existe confirmación `CAJA_CERRAR` del mismo usuario y sesión; `data: null` si la sesión propia sigue `ABIERTA`; `403 HISTORICAL_QUERY_NOT_ALLOWED` si está cerrada sin referencia coincidente; `403 SESSION_NOT_OWNED` si pertenece a otro vendedor; `404 CASH_SESSION_NOT_FOUND` si no existe).

## 2. Corrección de los Tres Pendientes Auditados

1. **Pendiente A — Apertura concurrente con cierre devolvía `500 INTERNAL_ERROR` (`APERTURA_CIERRE_ANTERIOR_INVALIDO`)**
   - **Causa comprobada**: Bajo el aislamiento efectivo `REPEATABLE READ` de MariaDB/InnoDB, `ControlSolicitudService::ejecutarConIdempotencia()` abría `beginTransaction()` y ejecutaba la lectura no bloqueante de `control_solicitud_idempotencia` **antes** de que `CajaService::abrirCaja()` adquiriera `caja_fisica_guardia FOR UPDATE`. Esa primera lectura fijaba la instantánea MVCC de la transacción cuando la sesión anterior aún estaba `ABIERTA`. Aunque `abrirCaja()` bloqueaba luego en `caja_fisica_guardia FOR UPDATE` y leía con `FOR UPDATE` el cierre ya confirmado (`CERRADA`), el trigger `trg_sesion_caja_before_insert_integridad` realiza un `SELECT ... FROM sesion_caja` sin `FOR UPDATE` (MariaDB prohíbe `FOR UPDATE` sobre la misma tabla en un trigger `BEFORE INSERT`, error `1442`), leyendo la instantánea MVCC obsoleta (`ABIERTA`).
   - **Solución aplicada**: En [ControlSolicitudService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/ControlSolicitudService.php), la lectura previa de idempotencia (ya serializada por `GET_LOCK`) se ejecuta antes de `beginTransaction()`, iniciando la transacción solo cuando la solicitud requiere escritura (`EN_PROCESO`). Así, ningún `SELECT` no bloqueante precede a `bloquearGuardiaCajaFisica()` en `abrirCaja()`, por lo que la vista MVCC se establece **después** de adquirir el bloqueo físico y tras el `COMMIT` del cierre concurrente, sin alterar el aislamiento global ni requerir cambios DDL.
2. **Pendiente B — Primer cierre convertía `opening_difference = null` en `0`**
   - **Causa comprobada**: En [CajaService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/CajaService.php), `cerrarCaja()` forzaba `(string)$sesion['diferencia_apertura']` antes de llamar a `decimalDbANumero()`, convirtiendo `NULL` en `''` y luego en `0.0`.
   - **Solución aplicada**: Preservar `null` cuando `$sesion['diferencia_apertura'] === null` y convertir únicamente cuando existe valor decimal real (`0`, positivo o negativo).
3. **Pendiente C — Validación de `EP-20` contra la sesión equivocada**
   - **Causa comprobada**: Dentro de la transacción de `cerrarCaja()`, se llamaba a `exigirSesionPropiaAbierta()` (que buscaba la sesión actualmente `ABIERTA`, por ejemplo sesión B) en lugar de resolver la sesión objetivo real desde `arqueo_id` (sesión A cerrada), devolviendo `409 ARQUEO_MISSING` en vez de `409 CASH_SESSION_CLOSED`.
   - **Solución aplicada**: Se agregó `CajaModel::obtenerSesionPorIdParaCierre()` en [CajaModel.php](file:///C:/xampp/htdocs/Amazon-Market/Models/CajaModel.php) y en [CajaService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/CajaService.php) se resuelve `id_sesion_caja` desde el `arqueo_id` tras `bloquearGuardiaCajaFisica()`, bloqueando `sesion_caja FOR UPDATE`, verificando propiedad (`403 SESSION_NOT_OWNED`), estado (`409 CASH_SESSION_CLOSED`), existencia (`409 ARQUEO_MISSING`) y vigencia (`409 ARQUEO_STALE` sobre `arqueo_caja FOR UPDATE`), preservando el orden global de bloqueos y dejando intacta la sesión B y el replay idempotente de A.

## 3. Archivos Modificados y Creados

- [AuthService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/AuthService.php)
- [ControlSolicitudService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/ControlSolicitudService.php)
- [CajaModel.php](file:///C:/xampp/htdocs/Amazon-Market/Models/CajaModel.php)
- [CajaApiController.php](file:///C:/xampp/htdocs/Amazon-Market/Controllers/API/CajaApiController.php)
- [CajaService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/CajaService.php)
- [paso11_cierre_autorizacion_test.php](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint3/paso11_cierre_autorizacion_test.php)
- [VALIDACION_PASO_11.md](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint3/VALIDACION_PASO_11.md)

## 4. Evidencia Antes / Después de los Tres Pendientes

- **Antes (auditoría + reproducción en `amazon_market_p11_repro_20261113`)**:
  - Pendiente A: `paso11_resultados.log` `94/95` (`[FAIL] Apertura vs Cierre`), `probe_concurrency.log` `HTTP 500 INTERNAL_ERROR` (`APERTURA_CIERRE_ANTERIOR_INVALIDO`).
  - Pendiente B: `DEFECTO_B: bd_diferencia_apertura=NULL ep20_opening_difference=0.0`.
  - Pendiente C: `DEFECTO_C: InventarioException: El arqueo especificado no existe para la sesión de caja actual. (ARQUEO_MISSING 409)` al cerrar con arqueo de A mientras B estaba abierta.
- **Después (en `amazon_market_p11_fix_20261113`)**:
  - Pendiente A: Worker adquiere `caja_fisica_guardia FOR UPDATE`, `POST /api/caja/abrir` espera contención real en MariaDB (`1.39s >= 1.0s`), cierre confirma y apertura responde `201 OPENED` con `id_sesion_cierre_anterior` y `reference_amount = 50.0` (Orden 1). Orden 2: apertura antes de cierre responde `409 CASH_ALREADY_OPEN`, cierre confirma `200 CLOSE_CONFIRMED`, y reintento con la misma referencia abre `201 OPENED` sin duplicaciones.
  - Pendiente B: `diferencia_apertura = NULL` en BD, `EP-20`, `respuesta_json`, replay de `EP-20` y `EP-21` (incluso tras nueva apertura) devuelven `opening_difference: null`; cierres posteriores distinguen `0`, `+10.5` y `-5.25` sin alterar `summary.difference` ni crear movimientos.
  - Pendiente C: `EP-20` con `arqueo_id` de sesión cerrada A y referencia nueva mientras B está abierta devuelve `409 CASH_SESSION_CLOSED` dejando B `ABIERTA`; replay con referencia original de A devuelve `200 OPERATION_ALREADY_CONFIRMED` con datos de A sin cerrar B; `arqueo_id` inexistente devuelve `409 ARQUEO_MISSING`; `arqueo_id` ajeno devuelve `403 SESSION_NOT_OWNED` con `data: null`.

## 5. Resultados de Pruebas (Bases Aisladas Migradas `01..09`)

| Suite | Base aislada | Resultado | Exit code |
|---|---|---|---|
| Paso 11 ampliado (`tests/sprint3/paso11_cierre_autorizacion_test.php`) | `amazon_market_p11_fix_20261113` | **129/129** | `0` |
| Paso 10 (`tests/sprint3/paso10_arqueos_test.php`) | `amazon_market_p11_reg10_20261113` | **154/154** | `0` |
| Paso 9 (`tests/sprint3/paso9_ventas_caja_test.php`) | `amazon_market_p11_reg9_20261113` | **107/107** | `0` |
| Paso 8 (`tests/sprint3/paso8_movimientos_correcciones_test.php`) | `amazon_market_p11_reg8_20261113` | **162/162** | `0` |
| Paso 7 (`tests/sprint3/paso7_apertura_caja_test.php`) | `amazon_market_p11_reg7_20261113` | **214/214** | `0` |
| Paso 6 + HU 18 (`tests/sprint3/paso6_turnos_programacion_test.php`) | `amazon_market_p11_reg6_20261113` | **116/116** | `0` |
| Sprint 2 (`tests/sprint2/fase4_ventas_kardex_test.php`) | `amazon_market_p11_regs2_20261113` | **14/14** | `0` |
| Sprint 2 (`tests/sprint2/fase8_pdf_test.php`) | `amazon_market_p11_regs2_20261113` | **21/21** | `0` |
| Sprint 2 (`tests/sprint2/regresion_http_test.php`) | `amazon_market_p11_regs2_20261113` | **22/22** | `0` |

## 6. Sintaxis, Diff y Conservación de `amazon_market`

- `php -l` sin errores en todos los archivos PHP modificados; `git diff --check` sin errores de formato.
- Comparación fila por fila de las **32 tablas** entre `amazon_market` y `amazon_market_p11_ref32_20261113` (restaurada del respaldo `amazon_market_sprint3_pre_step3_20261112_012312.sql`): `TABLES_CHECKED=32 DIFFS=0`.

---

## 7. Corrección de Regresión de Concurrencia en MariaDB/InnoDB (Deadlock `PRIMARY` vs `idx_sesion_estado_cierre_id`)

### 7.1 Causa Exacta del Deadlock Comprobada
- En la auditoría (`amazon_market_paso12_final_audit_20261113_093255/r11_deadlock.log`), `SHOW ENGINE INNODB STATUS` confirmó un deadlock real (`1213`) sobre `sesion_caja` entre:
  - **Transacción 1 (Movimiento / Arqueo / Venta)**: ejecutaba `CajaModel::obtenerSesionAbiertaActual(true)` (`SELECT ... FROM sesion_caja WHERE estado = 'ABIERTA' ORDER BY id_sesion_caja DESC LIMIT 1 FOR UPDATE`), adquiriendo bloqueo sobre el índice secundario `idx_sesion_estado_cierre_id` y esperando el bloqueo exclusivo sobre `PRIMARY`.
  - **Transacción 2 (`cerrarCaja`)**: ya poseía `caja_fisica_guardia` (`id=1 FOR UPDATE`) y `PRIMARY` de `sesion_caja`, y al ejecutar `UPDATE sesion_caja SET estado = 'CERRADA' ...` necesitaba bloqueo exclusivo sobre `idx_sesion_estado_cierre_id`.

### 7.2 Orden de Bloqueo Antes y Después
- **Antes (asimétrico)**:
  - `abrirCaja()` y `cerrarCaja()`: `control_solicitud_idempotencia` -> `caja_fisica_guardia (id=1 FOR UPDATE)` -> `sesion_caja (PRIMARY FOR UPDATE)` -> dependientes.
  - `registrarIngreso()`, `registrarEgreso()`, `corregirMovimiento()`, `guardarArqueo()`, `VentaService::procesarVenta()`: omitían `caja_fisica_guardia` y ejecutaban `obtenerSesionAbiertaActual(true)` bloqueando `idx_sesion_estado_cierre_id -> PRIMARY`.
- **Después (orden único unificado en [CajaModel.php](file:///c:/xampp/htdocs/Amazon-Market/Models/CajaModel.php#L126-L163))**:
  - Cuando `$forUpdate === true`, `obtenerSesionAbiertaActual(true)` adquiere primero `bloquearGuardiaCajaFisica()` (`caja_fisica_guardia WHERE id_caja_fisica = 1 FOR UPDATE`), resuelve el ID de sesión abierta sin bloquear `idx_sesion_estado_cierre_id` y bloquea `sesion_caja` exclusivamente por `PRIMARY KEY` mediante `obtenerSesionPorIdParaCierre($idAbierta, true)`, verificando `estado === 'ABIERTA'`.
  - Asimismo, en [worker_concurrencia_paso9.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/worker_concurrencia_paso9.php#L42) se alineó la adquisición de `caja_fisica_guardia FOR UPDATE` antes de `sesion_caja FOR UPDATE`.

### 7.3 Archivos Modificados en esta Corrección
- [CajaModel.php](file:///c:/xampp/htdocs/Amazon-Market/Models/CajaModel.php)
- [paso11_cierre_autorizacion_test.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso11_cierre_autorizacion_test.php) (secciones determinísticas `5I`–`5L` con barrera real en MariaDB para ambos órdenes en Movimiento vs Cierre, Arqueo vs Cierre, Venta vs Cierre y Corrección vs Cierre, reutilización de `referencia_solicitud` tras `ARQUEO_STALE`, verificación de `caja_fisica_guardia` y ausencia de deadlocks en `SHOW ENGINE INNODB STATUS`)
- [worker_concurrencia_paso9.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/worker_concurrencia_paso9.php)
- [VALIDACION_PASO_11.md](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/VALIDACION_PASO_11.md) y [VALIDACION_PASO_12.md](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/VALIDACION_PASO_12.md)

### 7.4 Reproducción Antes vs Después y Conteo Final de Pruebas (Copias Aisladas `01..09`)
- **Antes (evidencia de auditoría)**: Paso 11 `128/130` (salida `1`) / `128/129` (salida `1`) con `409 CONCURRENT_OPERATION_CONFLICT` por deadlock InnoDB en `Movimiento vs Cierre` y `Nuevo arqueo vs Cierre`.
- **Después**:
  - `tests/sprint3/paso11_cierre_autorizacion_test.php`: **160/160** (salida `0`, `Concurrencia: 53/53`, sin nuevos deadlocks en `SHOW ENGINE INNODB STATUS`).
  - `tests/sprint3/paso12_consultas_reportes_test.php`: **190/190** (salida `0`).
  - `tests/sprint3/paso10_arqueos_test.php`: **154/154** (salida `0`).
  - `tests/sprint3/paso9_ventas_caja_test.php`: **107/107** (salida `0`).
  - `tests/sprint3/paso8_movimientos_correcciones_test.php`: **162/162** (salida `0`).
  - `tests/sprint3/paso7_apertura_caja_test.php`: **214/214** (salida `0`).
  - `tests/sprint3/paso6_turnos_programacion_test.php` (+ HU 18): **116/116** (salida `0`).
  - Sprint 2 (`tests/sprint2/fase4_ventas_kardex_test.php` **14/14**, `tests/sprint2/fase8_pdf_test.php` **21/21**, `tests/sprint2/regresion_http_test.php` **22/22** usando `tests/sprint3/server_router_sprint3.php` sobre copia aislada migrada `01..09`): todos salida `0`.

### 7.5 Conservación y Pendientes Reales
- Base principal `amazon_market` intacta (**32** tablas); `.env` y respaldos sin modificar; cero cambios DDL.
- Pendiente real fuera de alcance actual: avance al **Paso 13** (frontend, exportaciones PDF/Excel y tickets).
