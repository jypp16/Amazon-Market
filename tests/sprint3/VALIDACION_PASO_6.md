# Informe de Validación — Paso 6 del Sprint 3 (Turnos y Programación Semanal, HU 18, EP-01 a EP-06)

**Proyecto:** `C:\xampp\htdocs\Amazon-Market`  
**Fecha:** 2026-11-12 (`America/Lima`, `-05:00`)  
**Estado:** **PASO 6 COMPLETO**

---

## 1. Archivos Modificados y Nuevos

### Archivos corregidos en esta sesión
- [Models/TurnoModel.php](file:///c:/xampp/htdocs/Amazon-Market/Models/TurnoModel.php)
- [Models/ProgramacionSemanalModel.php](file:///c:/xampp/htdocs/Amazon-Market/Models/ProgramacionSemanalModel.php)
- [Services/TurnoService.php](file:///c:/xampp/htdocs/Amazon-Market/Services/TurnoService.php)
- [Services/ProgramacionService.php](file:///c:/xampp/htdocs/Amazon-Market/Services/ProgramacionService.php)
- [tests/sprint3/paso6_turnos_programacion_test.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso6_turnos_programacion_test.php)

### Archivos nuevos del Paso 6 conservados
- [Controllers/API/TurnoApiController.php](file:///c:/xampp/htdocs/Amazon-Market/Controllers/API/TurnoApiController.php)
- [Controllers/API/ProgramacionApiController.php](file:///c:/xampp/htdocs/Amazon-Market/Controllers/API/ProgramacionApiController.php)
- [tests/sprint3/server_router_sprint3.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/server_router_sprint3.php)
- [tests/sprint3/worker_concurrencia_paso6.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/worker_concurrencia_paso6.php)

### Ajustes comunes auditados y conservados
- [Config/Config.php](file:///c:/xampp/htdocs/Amazon-Market/Config/Config.php) (`date_default_timezone_set('America/Lima')`)
- [Libraries/Core/Conexion.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/Conexion.php) (`SET SESSION time_zone = '-05:00'`)
- [Libraries/Core/ApiController.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/ApiController.php) (hook `authorizeController()`, `allowMultipartFormData`, manejo de `DELETE` sin cuerpo, `getHeader()`, respuestas de error estructuradas)
- [Libraries/Core/InventarioException.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/InventarioException.php) (`errors` como objeto vacío `{}` cuando no hay errores de campo)
- [Libraries/Core/Load.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/Load.php) (códigos `METHOD_NOT_ALLOWED` y `ENDPOINT_NOT_FOUND` en rutas API)
- [Libraries/Middleware/CSRFMiddleware.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Middleware/CSRFMiddleware.php) (`errors` en respuesta `CSRF_INVALID`)
- [Libraries/Middleware/RBACMiddleware.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Middleware/RBACMiddleware.php) (inclusión de `turnos.programar`, `turnos.consultar_historico`, `reportes.cierres_caja` en `esSlugExclusivoAdminSprint2()`, manteniendo el bypass general de `id_rol === 1`)
- [Services/ControlSolicitudService.php](file:///c:/xampp/htdocs/Amazon-Market/Services/ControlSolicitudService.php) (paso de `$idSolicitud` al callback y traducción de `SQLSTATE 40001` / códigos `1205`, `1213` a `409 CONCURRENT_OPERATION_CONFLICT`)
- [index.php](file:///c:/xampp/htdocs/Amazon-Market/index.php) (subrutas multi-segmento en `$params`, registro de `'turnos'` y `'programaciones'` en `$apiMap`, validación de sesión activa `401 AUTH_REQUIRED` previa a CSRF en mutaciones API)

---

## 2. Problemas Encontrados y Correcciones Aplicadas

| Defecto | Problema identificado | Corrección aplicada |
| :--- | :--- | :--- |
| **Defecto A (Modelos vs `Model`)** | `TurnoModel` y `ProgramacionSemanalModel` llamaban `parent::__construct($pdo)` (ignorando `$pdo` porque `Model::__construct()` no recibe parámetros), usaban `$this->conexion` (inexistente frente a `$this->conect`) y `TurnoModel::listarActivosConAsignadosEfectivos()` llamaba a `$this->select_all()` (inexistente). | En ambos modelos, `__construct(?PDO $pdo = null)` asigna `$this->conect = $pdo` cuando se recibe PDO inyectado (garantizando misma conexión y transacción entre servicio, idempotencia y modelo) o llama a `parent::__construct()` si es `null`. Se reemplazó `$this->conexion` por `$this->conect` y `select_all()` por `prepare/execute/fetchAll(PDO::FETCH_ASSOC)`. |
| **Defecto B (Columnas inexistentes)** | `TurnoModel` escribía `id_usuario_modificacion` (no existe en `turno`). `ProgramacionSemanalModel` leía/escribía `version`, `id_usuario_registro`, `id_solicitud`, `fecha_registro`, `fecha_reemplazo` (inexistentes en `programacion_semanal`). | Se mapeó `TurnoModel` al esquema real: `id_usuario_creacion`, `id_solicitud_creacion`, `id_solicitud_ultima_edicion`, `baja_en`. Se mapeó `ProgramacionSemanalModel` a `version_programacion`, `id_usuario_autor`, `id_solicitud_idempotencia`, `creado_en`, `reemplazada_en`, `vigencia_activa`, `id_programacion_previa`, conservando los nombres públicos del contrato API y el encadenamiento de versiones. |
| **Defecto C (Idempotencia)** | `TurnoService` y `ProgramacionService` devolvían el recurso plano desde el callback (sin `id_registro`, `http_code`, `response`) y luego leían `$ejecucion['data']` (inexistente en el retorno de `ControlSolicitudService`, que devuelve `http_code`, `idempotent_replay`, `response`). | Los callbacks ahora reciben `(PDO $pdo, int $idSolicitud)` y retornan `['id_registro' => ..., 'http_code' => ..., 'response' => <respuesta API completa con data.result_code>]`. Los servicios retornan `['http_code' => (int)$ejecucion['http_code'], 'response' => $ejecucion['response']]`, corrigiendo primera confirmación, `id_registro_recurso` y replay `200 OPERATION_ALREADY_CONFIRMED`. |
| **Defecto D (Batería de pruebas)** | `paso6_turnos_programacion_test.php` ejecutaba `DELETE FROM` sobre tablas protegidas por triggers (`turno`, `programacion_semanal`, `sesion_caja`, `control_solicitud_idempotencia`), consultaba columnas inexistentes (`estado` en `control_solicitud_idempotencia`, `version` en `programacion_semanal`, `guardia_sesion_abierta`/`fecha_apertura`/`fecha_cierre`/`monto_inicial_contado` en `sesion_caja`), consultaba `reporte_kardex_snapshot_item` (real: `reporte_consulta_snapshot_item`), esperaba estado `FALLIDA` tras rollback en simulación de deadlock, esperaba texto fijo en vez de JSON de `verificar_baja_turnos_programacion_sprint3.php` y fallaba `A-06` por asumir ausencia de bypass admin en `RBACMiddleware`. | Se eliminaron los `DELETE FROM` sobre historiales, se verificó copia limpia recién migrada, se aislaron los escenarios E.2 con vendedor/turnos dedicados, se corrigieron los nombres de columnas y tablas, se ajustó `D-13` (simulación de deadlock) para verificar rollback íntegro (`0` filas persistidas), se parseó el JSON del verificador oficial HU 18 en `E-01`, se ajustó `A-06` para validar el bloqueo a Vendedor incluso con `turnos.programar` en `rol_permiso`, se corrigió `F-03` a `/api/reportes/kardex` agregando `/api/ventas` y `/api/compras`, y se redirigieron pipes del servidor HTTP para evitar bloqueos. |

---

## 3. Rutas Implementadas (EP-01 a EP-06)

| Endpoint | Método y Ruta | Controlador | Rol / Permiso |
| :--- | :--- | :--- | :--- |
| **EP-01** | `GET /api/turnos` | `TurnoApiController::get` | Admin (`id_rol = 1`) + `turnos.programar` |
| **EP-02** | `POST /api/turnos` | `TurnoApiController::post` | Admin (`id_rol = 1`) + `turnos.programar` + CSRF + `TURNO_CREAR` |
| **EP-03** | `PUT /api/turnos/{id}` | `TurnoApiController::put` | Admin (`id_rol = 1`) + `turnos.programar` + CSRF + `TURNO_EDITAR` |
| **EP-04** | `DELETE /api/turnos/{id}` | `TurnoApiController::delete` | Admin (`id_rol = 1`) + `turnos.programar` + CSRF + `TURNO_BAJA` |
| **EP-05** | `GET /api/programaciones` | `ProgramacionApiController::get` | Admin (`id_rol = 1`) + `turnos.programar` |
| **EP-06** | `PUT /api/programaciones` | `ProgramacionApiController::put` | Admin (`id_rol = 1`) + `turnos.programar` + CSRF + `PROGRAMACION_HORARIO` |

---

## 4. Base y Servidor Aislados

- **Respaldo restaurado:** `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql`
- **Base de regresión HU 18 (CLI):** `amazon_market_sprint3_migration_test_paso6_hu18_20261112_2022` (migrada con `ejecutar_migracion_sprint3.php`, salida `0`).
- **Base de integración HTTP definitiva:** `amazon_market_sprint3_paso6_http_20261112_2029` (migrada con `ejecutar_migracion_sprint3.php`, salida `0`).
- **Servidor HTTP aislado:** `http://127.0.0.1:8093` usando [tests/sprint3/server_router_sprint3.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/server_router_sprint3.php), verificado por `/__test_env_verify` (`effective_db = amazon_market_sprint3_paso6_http_20261112_2029`, `php_timezone = America/Lima`, `db_timezone = -05:00`).
- **`.env` real:** Intacto apuntando a `amazon_market`.

---

## 5. Comandos Ejecutados y Códigos de Salida

1. **Verificación de sintaxis PHP:**
   ```powershell
   foreach ($f in @("Models\TurnoModel.php","Models\ProgramacionSemanalModel.php","Services\TurnoService.php","Services\ProgramacionService.php","Controllers\API\TurnoApiController.php","Controllers\API\ProgramacionApiController.php")) { C:\xampp\php\php.exe -l $f }
   # Exit code: 0 (No syntax errors detected)
   ```
2. **Migración oficial de copias nuevas:**
   ```powershell
   C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_migracion_sprint3.php amazon_market_sprint3_migration_test_paso6_hu18_20261112_2022
   # Exit code: 0 (COMPLETADA / 06_COMPLETADA)

   C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_migracion_sprint3.php amazon_market_sprint3_paso6_http_20261112_2029
   # Exit code: 0 (COMPLETADA / 06_COMPLETADA)
   ```
3. **Verificador oficial HU 18:**
   ```powershell
   C:\xampp\php\php.exe database/tercerSprint/tools/verificar_baja_turnos_programacion_sprint3.php --test-db=amazon_market_sprint3_migration_test_paso6_hu18_20261112_2022
   # Exit code: 0 ("cases": 11, "passed": 11, "failed": 0)
   ```
4. **Suite integral HTTP, persistencia, concurrencia, HU 18 y regresión:**
   ```powershell
   $env:TEST_DB_NAME = 'amazon_market_sprint3_paso6_http_20261112_2029'
   $env:HU18_DB_NAME = 'amazon_market_sprint3_migration_test_paso6_hu18_20261112_2022'
   $env:TEST_HTTP_PORT = '8093'
   C:\xampp\php\php.exe tests\sprint3\paso6_turnos_programacion_test.php
   # Exit code: 0 (116 / 116 aserciones superadas)
   ```

---

## 6. Resultados de Pruebas

| Categoría | Superadas | Fallidas | Cobertura principal |
| :--- | --: | --: | :--- |
| **PRE** | 4 | 0 | Entorno HTTP aislado, timezone `America/Lima` / `-05:00`, login HTTP y CSRF reales. |
| **A** | 39 | 0 | `401 AUTH_REQUIRED`, `403 FORBIDDEN_ADMIN_ONLY`, `403 CSRF_INVALID`, `400 INVALID_JSON`, `415`, `422 VALIDATION_ERROR` / `AMBIGUOUS_ALIAS_VALUES`, `405`, `404`. |
| **B** | 24 | 0 | Primer listado vacío (`[]`), creación EP-02 (`201 SHIFT_CREATED`), validaciones de nombre/horas, edición EP-03 sin mutar snapshots de `sesion_caja`, colisión `409 SHIFT_NAME_ALREADY_EXISTS`, bloqueo por sesión abierta `409 SHIFT_IN_USE`, baja lógica EP-04 (`200 SHIFT_DELETED`) y `404` tras baja. |
| **C** | 20 | 0 | `data: null` sin programación, validación lunes, `USER_NOT_FOUND`, `TARGET_NOT_SELLER`, excepción sin base, varios turnos el mismo día, preservación de excepción futura ante nueva base, historial en `programacion_semanal` (`version_programacion`, `id_programacion_previa`, `reemplazada_en`), duplicación vía EP-05 -> EP-06. |
| **D** | 13 | 0 | Replay `200 OPERATION_ALREADY_CONFIRMED` en EP-02/03/04/06 (incluyendo tras cambio de estado y normalización de alias), `409 IDEMPOTENCY_PAYLOAD_MISMATCH`, `409 REQUEST_IN_PROGRESS`, carreras paralelas reales con `worker_concurrencia_paso6.php` (`D-10`, `D-11`, `D-12`) y simulación de traducción de deadlock (`D-13`). |
| **E** | 9 | 0 | Verificador oficial HU 18 (`11/11` JSON) y coherencia HTTP entre `vendedores_asignados` (EP-01), `DELETE /api/turnos/{id}` (EP-04) y consultas históricas (EP-05). |
| **F** | 7 | 0 | Regresión `GET /api/usuarios`, `/api/productos`, `/api/reportes/kardex`, `/api/ventas`, `/api/compras`; conservación en copia (`108` productos, `2497` stock, `250` ventas, `80` compras, `470` kardex) y en `amazon_market`. |
| **TOTAL** | **116** | **0** | **100 % superadas** |

---

## 7. Respuestas Representativas (Sin Secretos)

- **Primer listado vacío (`GET /api/turnos`, `200 OK`):**
  ```json
  {
    "status": true,
    "code": "OK",
    "message": "Turnos obtenidos correctamente.",
    "data": []
  }
  ```
- **Creación (`POST /api/turnos`, `201 Created`):**
  `status: true`, `code: "SHIFT_CREATED"`, `idempotent_replay: false`, `data: { result_code: "SHIFT_CREATED", id, nombre, inicio, fin, estado: 1, vendedores_asignados: 0 }`.
- **Replay (`POST /api/turnos` / `PUT` / `DELETE`, `200 OK`):**
  `status: true`, `code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`, `data` idéntico al confirmado originalmente (incluyendo `result_code`).
- **Sin programación aplicable (`GET /api/programaciones`, `200 OK`):**
  `status: true`, `code: "OK"`, `data: null`.
- **Acceso de Vendedor (`403 FORBIDDEN_ADMIN_ONLY`):**
  `status: false`, `code: "FORBIDDEN_ADMIN_ONLY"`, `errors: {}`, `data: null`.

---

## 8. Persistencia y Conservación de Datos Verificada

- En `amazon_market_sprint3_paso6_http_20261112_2029`:
  - `control_solicitud_idempotencia` (`id_solicitud > 405`): `TURNO_CREAR` (`7` confirmadas con `id_registro_recurso`, `1` `EN_PROCESO` de prueba D-09), `TURNO_EDITAR` (`2` confirmadas con `id_registro_recurso`), `TURNO_BAJA` (`3` confirmadas con `id_registro_recurso`), `PROGRAMACION_HORARIO` (`10` confirmadas con `id_registro_recurso`).
  - `programacion_semanal`: `10` registros creados, `10` con `id_solicitud_idempotencia` no nulo y `2` encadenados mediante `id_programacion_previa`.
- En `amazon_market` (base original):
  - **Intacta:** `32` tablas base, `5` triggers, `6` usuarios, `2` roles, `31` permisos, `30` `rol_permiso`, `108` productos, `2497` unidades de stock, `250` ventas, `295` `detalle_venta`, `80` compras, `100` `detalle_compra`, `470` kardex, `75` `operacion_inventario`, `405` registros en `control_solicitud_idempotencia`, `2` snapshots y `150` ítems en `reporte_consulta_snapshot_item`.

---

## 9. Limitaciones

- La prueba `D-13` es una **simulación** de `PDOException` (`SQLSTATE 40001` / código `1213`) dentro del callback para validar la traducción a `409 CONCURRENT_OPERATION_CONFLICT` y el rollback transaccional; las carreras reales entre procesos paralelos se cubren en `D-10`, `D-11` y `D-12`.
- No se implementó ningún endpoint de los pasos 7 o posteriores (apertura, movimientos, arqueos, cierre, ventas-caja o frontend).
