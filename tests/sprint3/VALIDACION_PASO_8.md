# Validación Paso 8 Sprint 3 — Movimientos, Retiros Omitidos y Correcciones Vinculadas (EP-10 a EP-16)

## 1. Resumen Ejecutivo

- **Alcance implementado y cerrado:** Endpoints `EP-10` a `EP-16` (`GET /api/caja/sesion-actual`, `GET /api/caja/administradores`, `GET /api/caja/turnos-anteriores`, `POST /api/caja/ingresos`, `POST /api/caja/egresos`, `GET /api/caja/movimientos/corregibles`, `POST /api/caja/movimientos/correcciones`), migraciones incrementales SQL `07` y `08` con sus runners CLI, actualización documental en `CONTRATO_API_SPRINT_3.md`, `ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md` y `database/tercerSprint/README.md`, y resolución de los dos pendientes de auditoría (**Pendiente A: referencia visible truncada** y **Pendiente B: concurrencia independiente de la sesión PHP**).
- **Suite Paso 8 ampliada (`tests/sprint3/paso8_movimientos_correcciones_test.php`):** **162 / 162** aserciones superadas (código de salida `0`).
- **Regresión Paso 7 (`tests/sprint3/paso7_apertura_caja_test.php`):** **214 / 214** aserciones superadas (código de salida `0`).
- **Regresión Paso 6 + HU 18 (`tests/sprint3/paso6_turnos_programacion_test.php`):** **116 / 116** aserciones superadas, incluyendo **11 / 11** casos del verificador oficial `verificar_baja_turnos_programacion_sprint3.php` (código de salida `0`).
- **Integridad de `amazon_market`:** **32** tablas originales intactas (`venta = 250`, `compra = 80`, `producto = 108`, `kardex = 470`), sin modificaciones sobre `.env`, respaldos ni bases de verificación previas.

---

## 2. Causa y Resolución de los Dos Pendientes de Auditoría

### 2.1 Pendiente A — Referencia visible truncada (`movimiento_caja.referencia_visible`)

- **Causa raíz:**
  - En `Services/CajaService.php`, `admin_injection` construye `$reason . ' · Entregó: ' . $adminName` y `admin_withdrawal` construye `$reason . ' · Recibió: ' . $adminName`.
  - Con el máximo contractual de `motivo` (`250` caracteres), el máximo de `usuario.nombre` (`100` caracteres) y el separador/etiqueta (`12` caracteres), la referencia alcanza **362 caracteres**.
  - La columna `movimiento_caja.referencia_visible` estaba definida como `VARCHAR(300)` en `04_estructura_movimientos_correcciones_y_ventas_sprint3.sql`. En conexiones XAMPP con `sql_mode` no estricto (`NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`), MariaDB truncaba silenciosamente a `300` caracteres; en modo estricto (`STRICT_TRANS_TABLES`/`STRICT_ALL_TABLES`) fallaría con error `1406`.
- **Evidencia antes de la corrección (`amazon_market_sprint3_paso8_defect_demo_20261112`, sin migración 08):**
  - La prueba HTTP con `motivo` de `250` caracteres Unicode y administrador de `100` caracteres Unicode reprodujo el truncamiento de `362` -> `300` caracteres tanto en `movimiento_caja.referencia_visible` como en `EP-15` para `admin_injection` y `admin_withdrawal`.
- **Solución implementada (sin recortar límites ni deshabilitar restricciones):**
  - Migración incremental `database/tercerSprint/08_ampliar_referencia_visible_movimiento_caja_sprint3.sql` + runner `database/tercerSprint/tools/ejecutar_ajuste_referencia_paso8_sprint3.php`: amplía `movimiento_caja.referencia_visible` a `VARCHAR(500)` (suficiente para `362` caracteres de inyección/retiro administrativo y `omitted_withdrawal`/`correction` con IDs multi-dígito).
  - Verificada la migración `08` tanto en actualización sobre una copia que ya tenía `01..07` y datos (`amazon_market_sprint3_paso8_defect_demo_20261112`: `movimiento_caja` conserva `6` filas antes y `6` después) como sobre copias nuevas restauradas desde respaldo (`01..06` + `07` + `08`).
- **Evidencia después de la corrección (`amazon_market_sprint3_paso8_final6_20261112`):**
  - `POST /api/caja/ingresos` (`admin_injection`, `250` chars Unicode + admin `100` chars Unicode): HTTP `201`, comparación exacta (`===`) de los `362` caracteres en respuesta HTTP, BD `referencia_visible`, `EP-15` y replay HTTP `200`.
  - `POST /api/caja/egresos` (`admin_withdrawal`, `250` chars Unicode + admin `100` chars Unicode): HTTP `201`, comparación exacta (`===`) de los `362` caracteres en respuesta HTTP, BD `referencia_visible`, `EP-15` y replay HTTP `200`.
  - Ejecución directa con `CajaService` sobre PDO en modo estricto (`STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`): persiste los `362` caracteres íntegros sin truncamiento ni error `1406`.

### 2.2 Pendiente B — Concurrencia independiente de la sesión PHP y defecto real descubierto

- **Por qué la concurrencia anterior era insuficiente:**
  - Aunque `paso8_movimientos_correcciones_test.php` lanzaba dos servidores `php -S` en paralelo, ambos workers compartían el mismo archivo `$cookieV1` (mismo `PHPSESSID`).
  - `Config/Config.php` invoca `session_start()`, que mantiene un bloqueo exclusivo (`flock`) sobre el archivo de sesión PHP hasta finalizar la petición. Eso serializaba las peticiones concurrentes del mismo vendedor en PHP antes de que llegaran a competir en MariaDB.
- **Corrección de la prueba y sincronización controlada:**
  - Se realizan dos inicios de sesión HTTP independientes del mismo vendedor (`vndp8a`), con archivos de cookies separados (`v1.cookie` y `v1_B.cookie`), tokens CSRF independientes y `PHPSESSID` distintos (verificados por hash SHA-256: `336e282f` vs `56db0e58`, sin exponer valores secretos).
  - Se implementó `runContendedParallelHttpWorkers()` en `tests/sprint3/paso8_movimientos_correcciones_test.php`, que mantiene temporalmente `SELECT id_sesion_caja FROM sesion_caja WHERE id_sesion_caja = :id FOR UPDATE` en una conexión de control mientras lanza ambos workers en paralelo, observa la contención real en `information_schema.INNODB_TRX` (`LOCK WAIT`) e `information_schema.PROCESSLIST`, y luego libera el bloqueo con `COMMIT` para que compitan simultáneamente en MariaDB.
- **Defecto real descubierto en backend y corregido:**
  - Al habilitar competencia real con dos `PHPSESSID` distintos sobre `EP-16` (`Dos correcciones concurrentes al mismo monto`), MariaDB reportó `Deadlock found when trying to get lock` (`1213` -> `409 CONCURRENT_OPERATION_CONFLICT`).
  - `SHOW ENGINE INNODB STATUS` demostró que `CajaModel::obtenerSesionAbiertaActual(true)` ejecutaba `SELECT ... FROM sesion_caja s INNER JOIN usuario u ON u.id_usuario = s.id_usuario_vendedor WHERE s.estado = 'ABIERTA' ... FOR UPDATE`, permitiendo que el optimizador alternara el orden de bloqueo (`usuario -> sesion_caja` vs `sesion_caja -> usuario`) entre dos transacciones concurrentes.
  - **Corrección en `Models/CajaModel.php`:** `obtenerSesionAbiertaActual()` ahora ejecuta el `FOR UPDATE` exclusivamente sobre `sesion_caja` y consulta `usuario.nombre` en un `SELECT` separado sin bloqueo (`FOR UPDATE`), eliminando la inversión de orden de bloqueo sin alterar el manejo de sesiones PHP de producción.
- **Evidencia de las nuevas pruebas de concurrencia (`amazon_market_sprint3_paso8_final6_20261112`):**
  - **Caso A (saldo `100.00`, dos egresos simultáneos de `80.00`):** observados hilos independientes en `LOCK WAIT` (`2554,2556,2555`), respuestas `[201, 409]` (`INSUFFICIENT_BALANCE`), saldo final exacto `20.00`.
  - **Caso B (dos correcciones concurrentes sobre el mismo original al mismo objetivo `40.00`):** observados hilos independientes en `LOCK WAIT` (`2559,2561,2560`), respuestas `[201, 422]` (`NO_CORRECTION_CHANGE`), sin deadlock ni doble aplicación.
  - **Caso C (dos solicitudes simultáneas con la misma `referencia_solicitud`):** observada contención real (`LOCK WAIT` hilo `2563`), respuestas `[200, 201]`, una única operación efectiva (`5.00 + 15.00 = 20.00`).

---

## 3. Archivos Modificados y Creados (Pendientes A y B + Paso 8)

- **Backend:**
  - `Models/CajaModel.php` (eliminado `INNER JOIN usuario ... FOR UPDATE` en `obtenerSesionAbiertaActual()` para prevenir deadlock por inversión de orden de bloqueo; métodos de Paso 8).
  - `Services/CajaService.php` (lógica `EP-10`..`EP-16`).
  - `Controllers/API/CajaApiController.php` (enrutamiento y permisos `turnos.abrir` vs `turnos.movimientos`).
- **Migraciones y documentación SQL:**
  - `database/tercerSprint/07_ajuste_check_correccion_cero_sprint3.sql` y `database/tercerSprint/tools/ejecutar_ajuste_paso8_sprint3.php`.
  - `database/tercerSprint/08_ampliar_referencia_visible_movimiento_caja_sprint3.sql` y `database/tercerSprint/tools/ejecutar_ajuste_referencia_paso8_sprint3.php`.
  - `database/tercerSprint/README.md` (secciones 6 y 7).
  - `CONTRATO_API_SPRINT_3.md` y `ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md` (decisión `null` en `EP-10`).
- **Pruebas:**
  - `tests/sprint3/paso8_movimientos_correcciones_test.php`
  - `tests/sprint3/worker_concurrencia_paso8.php`
  - `tests/sprint3/VALIDACION_PASO_8.md`

---

## 4. Bases de Datos de Ensayo, Comandos y Regresiones

Todas las bases se crearon limpias desde `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql`:

| Propósito | Base de datos / Alcance | Comando | Código de salida | Resultado |
|---|---|---|---|---|
| Demostración defecto Pendiente A (sin `08`) | `amazon_market_sprint3_paso8_defect_demo_20261112` | `paso8_movimientos_correcciones_test.php` | `1` (esperado) | Reproducido truncamiento `362 -> 300` en `referencia_visible` y `EP-15` |
| Upgrade `08` sobre base con `01..07` | `amazon_market_sprint3_paso8_defect_demo_20261112` | `php database\tercerSprint\tools\ejecutar_ajuste_referencia_paso8_sprint3.php amazon_market_sprint3_paso8_defect_demo_20261112` | `0` | `referencia_visible` ampliada a `varchar(500)`, `6` filas conservadas intactas |
| Suite Paso 8 ampliada (`01..08`) | `amazon_market_sprint3_paso8_final6_20261112` | `$env:TEST_DB_NAME='amazon_market_sprint3_paso8_final6_20261112'; $env:TEST_HTTP_PORT='8096'; C:\xampp\php\php.exe tests\sprint3\paso8_movimientos_correcciones_test.php` | `0` | **162 / 162** aserciones superadas (`PRE`: 3, `SEGURIDAD`: 30, `CONSULTAS`: 30, `MOVIMIENTOS`: 41, `ARQUEOS`: 2, `RETIRO_OMITIDO`: 12, `CORRECCIONES`: 20, `CONCURRENCIA`: 8, `IDEMPOTENCIA`: 11, `CONSERVACION`: 5) |
| Regresión Paso 7 (`01..08`) | `amazon_market_sprint3_paso8_reg7_final_20261112` | `$env:TEST_DB_NAME='amazon_market_sprint3_paso8_reg7_final_20261112'; $env:TEST_HTTP_PORT='8099'; C:\xampp\php\php.exe tests\sprint3\paso7_apertura_caja_test.php` | `0` | **214 / 214** aserciones superadas |
| Regresión Paso 6 + HU 18 (`01..08`) | `amazon_market_sprint3_paso8_reg6_final_20261112` + `amazon_market_sprint3_migration_test_paso8_hu18_final_20261112` | `$env:TEST_DB_NAME='amazon_market_sprint3_paso8_reg6_final_20261112'; $env:HU18_DB_NAME='amazon_market_sprint3_migration_test_paso8_hu18_final_20261112'; $env:TEST_HTTP_PORT='8098'; C:\xampp\php\php.exe tests\sprint3\paso6_turnos_programacion_test.php` | `0` | **116 / 116** aserciones superadas (incluye **11 / 11** en `verificar_baja_turnos_programacion_sprint3.php`) |
| Sintaxis PHP | 7 archivos modificados/nuevos | `C:\xampp\php\php.exe -l <archivo>` | `0` | `No syntax errors detected` en los 7 archivos |
| Integridad Git | Repositorio completo | `git diff --check` | `0` | Sin errores de formato |
| Conservación `amazon_market` | Base principal | Consulta `information_schema.TABLES` + conteos | `0` | `32` tablas, `ventas = 250`, `compras = 80`, `productos = 108`, `kardex = 470` |

---

## 5. Alcance Conservado

- No se avanzó al Paso 9 (ventas POS), Paso 10 (arqueos), Paso 11 (cierre), reportes ni frontend (Paso 13).
- No se modificaron `.env`, `amazon_market`, respaldos, archivos Word (`.docx`) ni bases de ensayos anteriores, y no se ejecutó `git commit`.
