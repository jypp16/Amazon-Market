# Validación Paso 8 Sprint 3 — Movimientos, Retiros Omitidos y Correcciones Vinculadas (EP-10 a EP-16)

## 1. Resumen Ejecutivo

- **Alcance implementado:** Endpoints `EP-10` a `EP-16` (`GET /api/caja/sesion-actual`, `GET /api/caja/administradores`, `GET /api/caja/turnos-anteriores`, `POST /api/caja/ingresos`, `POST /api/caja/egresos`, `GET /api/caja/movimientos/corregibles`, `POST /api/caja/movimientos/correcciones`), migración incremental SQL `07_ajuste_check_correccion_cero_sprint3.sql` con su runner `ejecutar_ajuste_paso8_sprint3.php`, actualización documental en `CONTRATO_API_SPRINT_3.md` y `ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md`, y suite integral HTTP + concurrencia + idempotencia + conservación de datos.
- **Suite Paso 8 (`tests/sprint3/paso8_movimientos_correcciones_test.php`):** **144 / 144** aserciones superadas (código de salida `0`).
- **Regresión Paso 7 (`tests/sprint3/paso7_apertura_caja_test.php`):** **214 / 214** aserciones superadas (código de salida `0`).
- **Regresión Paso 6 + HU 18 (`tests/sprint3/paso6_turnos_programacion_test.php`):** **116 / 116** aserciones superadas, incluyendo **11 / 11** casos del verificador oficial `verificar_baja_turnos_programacion_sprint3.php` (código de salida `0`).
- **Integridad de `amazon_market`:** **32** tablas originales intactas (`venta = 250`, `compra = 80`, `producto = 108`, `kardex = 470`), sin modificaciones sobre `.env` ni sobre bases de verificación previas.

---

## 2. Trazabilidad Historia / Endpoint / Regla / Archivo / Prueba

| Elemento | Regla / Decisión Implementada | Archivos Modificados / Creados | Cobertura en Pruebas |
|---|---|---|---|
| Decisión EP-10 (`null` sin sesión propia) | Cuando la caja está cerrada o abierta por otro vendedor, `is_open = false`, `is_owner = false`, `session_id = null`, `current_balance = null`, `balance = null` y sin datos ajenos. Con sesión propia y saldo `0.00`, devuelve `0.0`. | `CONTRATO_API_SPRINT_3.md`, `ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md`, `Services/CajaService.php`, `Models/CajaModel.php` | `paso8_movimientos_correcciones_test.php` (`CONSULTAS`: 11 aserciones EP-10 cerrada / propia 0.00 / ajena) |
| Permisos y autorización por ruta | `EP-07..EP-09` conservan `turnos.abrir`; `EP-10..EP-16` exigen `turnos.movimientos`. Rechazo explícito de Administrador (`403 FORBIDDEN`), usuario inactivo en BD (`401 AUTH_REQUIRED`), CSRF (`403 CSRF_INVALID`), JSON inválido (`400`), `Content-Type` (`415`). | `Controllers/API/CajaApiController.php` (`authorizeController`, `extraerSegmentoCaja`) | `paso8_movimientos_correcciones_test.php` (`SEGURIDAD`: 30 aserciones) + regresión `paso7_apertura_caja_test.php` |
| `EP-11` (`GET /api/caja/administradores`) | Exige sesión propia abierta (`409 CASH_SESSION_CLOSED`, `403 SESSION_NOT_OWNED`). Devuelve solo `id` y `name` de administradores activos (incluyendo sin correo), sin exponer `email`, `username`, DNI, teléfono, dirección ni `password_hash`. | `Models/CajaModel.php`, `Services/CajaService.php`, `Controllers/API/CajaApiController.php` | `paso8_movimientos_correcciones_test.php` (`SEGURIDAD` / `CONSULTAS`) |
| `EP-12` (`GET /api/caja/turnos-anteriores`) | Exige sesión propia abierta. Devuelve hasta las últimas 20 sesiones cerradas anteriores a la apertura actual ordenadas por `fecha_hora_cierre DESC, id_sesion_caja DESC`, `page_size`/`limit` (`1..20`, default `10`), `shortage = max(expected - counted, 0)`. | `Models/CajaModel.php`, `Services/CajaService.php`, `Controllers/API/CajaApiController.php` | `paso8_movimientos_correcciones_test.php` (`CONSULTAS`: límite 20, orden, cálculo de `shortage`) |
| `EP-13` (`POST /api/caja/ingresos`) | Admite `manual_income` (y alias `manual`), `admin_injection`. Valida importe positivo `<= 99999999.99` (máx. 2 decimales exactos con `JsonDecimalExtractor`), motivo, `administrator_id` activo según tipo, actualiza `total_ingresos`, `efectivo_esperado_sistema`, `version_caja` e invalida arqueo vigente. | `Models/CajaModel.php`, `Services/CajaService.php`, `Controllers/API/CajaApiController.php` | `paso8_movimientos_correcciones_test.php` (`MOVIMIENTOS`, `ARQUEOS`, `IDEMPOTENCIA`) |
| `EP-14` (`POST /api/caja/egresos`) | Admite `cash_out`, `admin_withdrawal`, `omitted_withdrawal`. Egresos actuales validan saldo (`409 INSUFFICIENT_BALANCE` si excede por `0.01`, permitido dejar `0.00`). Retiro omitido exige booleanos reales `true`, origen en top 20 cerrado anterior, impacto `0.00` en saldo actual, conserva íntegra la sesión de origen e invalida arqueo vigente. | `Models/CajaModel.php`, `Services/CajaService.php`, `Controllers/API/CajaApiController.php` | `paso8_movimientos_correcciones_test.php` (`MOVIMIENTOS`, `RETIRO_OMITIDO`, `CONCURRENCIA`) |
| `EP-15` (`GET /api/caja/movimientos/corregibles`) | Valida `session_id`/`sesion_id` igual a la sesión propia abierta (`403 SESSION_NOT_OWNED` si ajena/cerrada). Devuelve solo tipos elegibles (`manual_income`, `admin_injection`, `cash_out`, `admin_withdrawal`) con `amount` original inmutable, `current_amount` vigente e historial de correcciones. | `Models/CajaModel.php`, `Services/CajaService.php`, `Controllers/API/CajaApiController.php` | `paso8_movimientos_correcciones_test.php` (`CONSULTAS`, `SEGURIDAD`) |
| `EP-16` (`POST /api/caja/movimientos/correcciones`) | Inmutabilidad del movimiento original; cadena de eslabones (`correccion_movimiento_caja`); cálculo contra `current_amount` vigente; admite `corrected_amount >= 0.00` y corrección posterior desde `0.00`; rechaza sin cambio (`422 NO_CORRECTION_CHANGE`), tipos no elegibles (`409 CORRECTION_NOT_ALLOWED`) y saldo negativo (`409 INSUFFICIENT_BALANCE`). | `Models/CajaModel.php`, `Services/CajaService.php`, `Controllers/API/CajaApiController.php`, `database/tercerSprint/07_ajuste_check_correccion_cero_sprint3.sql` | `paso8_movimientos_correcciones_test.php` (`CORRECCIONES`, `CONCURRENCIA`, `IDEMPOTENCIA`) |
| Compatibilidad SQL `0.00` en correcciones | Migración incremental `07` reemplaza `chk_correccion_importes_y_motivo` permitiendo `monto_vigente_previo >= 0.00` y `monto_corregido >= 0.00`, manteniendo `monto_original > 0.00`, `monto_corregido <> monto_vigente_previo` y `efecto_saldo <> 0.00`. | `database/tercerSprint/07_ajuste_check_correccion_cero_sprint3.sql`, `database/tercerSprint/tools/ejecutar_ajuste_paso8_sprint3.php`, `database/tercerSprint/README.md` | Verificación dual: actualización sobre `01..06` previo y ejecución directa en copias nuevas |

---

## 3. Bases de Datos de Ensayo Utilizadas (Aisladas de `amazon_market`)

Todas las pruebas se ejecutaron sobre copias desechables restauradas desde `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql`:

1. `amazon_market_sprint3_paso8_http_20261112_2206` — Suite integral Paso 8 (`EP-10`..`EP-16`, HTTP + concurrencia multiproceso + idempotencia + conservación).
2. `amazon_market_sprint3_paso8_reg7_20261112_2159` — Regresión completa Paso 7 (`214 / 214`).
3. `amazon_market_sprint3_paso8_upgrade07_20261112` — Verificación de actualización de migración `07` sobre una base que ya tenía `01..06` completada (`06_COMPLETADA`) + regresión Paso 6 (`116 / 116`).
4. `amazon_market_sprint3_migration_test_paso8_hu18_20261112` — Verificación oficial HU 18 (`11 / 11` casos) invocada dentro de la regresión Paso 6.

---

## 4. Comandos Ejecutados y Resultados Reales

| Propósito | Comando | Código de salida | Resultado observado |
|---|---|---|---|
| Sintaxis PHP | `C:\xampp\php\php.exe -l` sobre `Models\CajaModel.php`, `Services\CajaService.php`, `Controllers\API\CajaApiController.php`, `database\tercerSprint\tools\ejecutar_ajuste_paso8_sprint3.php`, `tests\sprint3\worker_concurrencia_paso8.php`, `tests\sprint3\paso8_movimientos_correcciones_test.php` | `0` | `No syntax errors detected` en los 6 archivos |
| Integridad `git diff --check` | `git diff --check` | `0` | Sin errores de formato / espacios finales |
| Migración `01..06` + ajuste `07` (upgrade) | `php database\tercerSprint\tools\ejecutar_migracion_sprint3.php amazon_market_sprint3_paso8_upgrade07_20261112` seguido de `php database\tercerSprint\tools\ejecutar_ajuste_paso8_sprint3.php amazon_market_sprint3_paso8_upgrade07_20261112` | `0` | `chk_correccion_importes_y_motivo` actualizado de `> 0.00` a `>= 0.00` en `monto_vigente_previo` y `monto_corregido` |
| Suite Paso 8 (HTTP real + concurrencia + DB) | `$env:TEST_DB_NAME='amazon_market_sprint3_paso8_http_20261112_2206'; $env:TEST_HTTP_PORT='8096'; C:\xampp\php\php.exe tests\sprint3\paso8_movimientos_correcciones_test.php` | `0` | **144 / 144** aserciones superadas (`PRE`: 2, `SEGURIDAD`: 30, `CONSULTAS`: 30, `MOVIMIENTOS`: 27, `ARQUEOS`: 2, `RETIRO_OMITIDO`: 12, `CORRECCIONES`: 20, `CONCURRENCIA`: 5, `IDEMPOTENCIA`: 11, `CONSERVACION`: 5) |
| Regresión Paso 7 | `$env:TEST_DB_NAME='amazon_market_sprint3_paso8_reg7_20261112_2159'; $env:TEST_HTTP_PORT='8099'; C:\xampp\php\php.exe tests\sprint3\paso7_apertura_caja_test.php` | `0` | **214 / 214** aserciones superadas |
| Regresión Paso 6 + HU 18 | `$env:TEST_DB_NAME='amazon_market_sprint3_paso8_upgrade07_20261112'; $env:HU18_DB_NAME='amazon_market_sprint3_migration_test_paso8_hu18_20261112'; $env:TEST_HTTP_PORT='8098'; C:\xampp\php\php.exe tests\sprint3\paso6_turnos_programacion_test.php` | `0` | **116 / 116** aserciones superadas (incluye **11 / 11** en `verificar_baja_turnos_programacion_sprint3.php`) |
| Integridad `amazon_market` | Consulta directa sobre `information_schema.TABLES` y tablas `venta`, `compra`, `producto`, `kardex` | `0` | `32` tablas, `ventas = 250`, `compras = 80`, `productos = 108`, `kardex = 470` |

---

## 5. Evidencia de Concurrencia, Idempotencia y Conservación de Datos

- **Concurrencia real multiproceso (2 procesos `php -S` independientes compartiendo BD y directorio de sesiones):**
  1. Dos egresos paralelos de `80.00` contra saldo disponible `100.00`: exactamente uno responde `201` y el otro `409 INSUFFICIENT_BALANCE`, dejando saldo final exacto de `20.00`.
  2. Dos correcciones paralelas al mismo movimiento hacia el mismo monto objetivo: exactamente una responde `201` y la competidora `422 NO_CORRECTION_CHANGE` (sin doble ajuste ni doble conteo).
  3. Dos reintentos HTTP simultáneos con la misma `request_reference`: exactamente una confirmación efectiva (`saldo 5.00 + 15.00 = 20.00`).
- **Idempotencia:**
  - Replay de `POST /api/caja/ingresos` devuelve `200 OPERATION_ALREADY_CONFIRMED` con `idempotent_replay = true`, sin incrementar `version_caja` y sin invalidar el arqueo vigente.
  - Reutilización de referencia con payload distinto devuelve `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
  - Replay posterior al cierre de la sesión original y apertura de una nueva sesión devuelve `200` con el `session_id` original sin alterar el saldo de la nueva sesión abierta.
- **Conservación de datos históricos:**
  - En la base de pruebas de Paso 8: `250` ventas conservan `id_sesion_caja IS NULL`; `venta = 250`, `compra = 80`, `producto = 108`, `kardex = 470`.
  - En `amazon_market`: `32` tablas y conteos originales intactos (`250`, `80`, `108`, `470`).

---

## 6. Limitaciones y Pasos Posteriores Fuera de Alcance

- No se implementan en este paso: integración de nuevas ventas POS con caja (Paso 9), endpoints de arqueo (Paso 10), cierre y autorización anticipada (Paso 11), reportes posteriores, ni conexión del frontend (Paso 13).
- No se editaron archivos Word (`.docx`) ni `.env`, ni se ejecutó `git commit`.
