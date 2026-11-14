# Informe de Validación — Paso 7 del Sprint 3 (Apertura de Caja y Suplencias, HU 11, EP-07 a EP-09)

**Proyecto:** `C:\xampp\htdocs\Amazon-Market`  
**Fecha:** 2026-11-12 (`America/Lima`, `-05:00`)  
**Estado:** **PASO 7 COMPLETO**

---

## 1. Alcance Implementado y Relación con HU 11

Se implementaron exclusivamente los endpoints de apertura de caja y suplencias correspondientes al Paso 7 (HU 11):

| Endpoint | Método y Ruta | Controlador | Rol y Permiso | Descripción |
| :--- | :--- | :--- | :--- | :--- |
| **EP-07** | `GET /api/caja/turno-hoy` | `CajaApiController::get` | Vendedor (`id_rol = 2`) + `turnos.abrir` | Programación propia de hoy (`within_hours`, `outside_hours`, `no_shift`), catálogo activo disponible y `requires_authorization_to_open: false`. |
| **EP-08** | `GET /api/caja/estado` | `CajaApiController::get` | Vendedor (`id_rol = 2`) + `turnos.abrir` | Estado de la única caja física (Casos A, B, C y D), ocultando cifras financieras si está ocupada por otro vendedor. |
| **EP-09** | `POST /api/caja/abrir` | `CajaApiController::post` | Vendedor (`id_rol = 2`) + `turnos.abrir` + CSRF + `CAJA_ABRIR` | Apertura de sesión única `ABIERTA`, snapshot de turno/horario, cálculo exacto en céntimos de referencia y diferencia, suplencias sin credenciales admin e idempotencia. |

No se implementaron endpoints de pasos posteriores (movimientos, correcciones, ventas-caja, arqueos, cierres, autorizaciones de cierre ni integración frontend).

---

## 2. Correcciones de Validación Monetaria y Claves Repetidas (EP-09)

### A) Precisión decimal de literales numéricos JSON
- **Causa:** `sprintf('%.10F', $valor)` redondeaba floats como `1.00000000001` a `1.00` antes de validar decimales, y `json_decode()` convertía literales largos a `float` binario.
- **Corrección:** Extracción del literal numérico crudo desde el JSON recibido antes de `json_decode()`, validación exacta sin `sprintf`, y cálculos monetarios en céntimos enteros (`parseDecimalACentimos()` / `centimosADecimal()`).

### B) Discrepancia por clave `cash_counted` repetida en el objeto raíz
- **Causa:** `JsonDecimalExtractor::extractTopLevelNumberLiteral()` retornaba inmediatamente en la primera coincidencia de `cash_counted`, mientras `json_decode()` conservaba la última aparición. Esto permitía aceptar peticiones ambiguas como `{"cash_counted":1,"cash_counted":999}`, `{"cash_counted":"1","cash_counted":1.0000000000000000001}` o `{"cash_counted":1,"cash\u005fcounted":999}`.
- **Corrección:**
  - En [Libraries/Core/JsonDecimalExtractor.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/JsonDecimalExtractor.php), `inspectTopLevelKey()` recorre todo el objeto JSON raíz decodificando las claves (incluidos escapes Unicode como `\u005f`), ignorando objetos/arreglos anidados y texto dentro de cadenas, y contabilizando las apariciones en raíz (`count` y `number_literal`).
  - En [Libraries/Core/ApiController.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/ApiController.php), `inspectRawJsonField('cash_counted')` entrega el conteo y literal a [Controllers/API/CajaApiController.php](file:///c:/xampp/htdocs/Amazon-Market/Controllers/API/CajaApiController.php).
  - En [Services/CajaService.php](file:///c:/xampp/htdocs/Amazon-Market/Services/CajaService.php), si `cash_counted` aparece más de una vez en el objeto raíz, `abrirCaja()` rechaza inmediatamente (antes de `ControlSolicitudService`) con **HTTP `422`**, código **`VALIDATION_ERROR`** y `errors.cash_counted`.

---

## 3. Archivos Creados y Modificados

### Archivos nuevos
- [Libraries/Core/JsonDecimalExtractor.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/JsonDecimalExtractor.php)
- [Models/CajaModel.php](file:///c:/xampp/htdocs/Amazon-Market/Models/CajaModel.php)
- [Services/CajaService.php](file:///c:/xampp/htdocs/Amazon-Market/Services/CajaService.php)
- [Controllers/API/CajaApiController.php](file:///c:/xampp/htdocs/Amazon-Market/Controllers/API/CajaApiController.php)
- [tests/sprint3/worker_concurrencia_paso7.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/worker_concurrencia_paso7.php)
- [tests/sprint3/paso7_apertura_caja_test.php](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso7_apertura_caja_test.php)

### Archivos modificados
- [Libraries/Core/ApiController.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/ApiController.php) (`requireVendedorActivo()`, `$rawRequestBody`, `inspectRawJsonField()`, `getRawJsonNumberField()`)
- [Services/ProgramacionService.php](file:///c:/xampp/htdocs/Amazon-Market/Services/ProgramacionService.php) (`obtenerTurnosProgramadosEnFecha()`)
- [index.php](file:///c:/xampp/htdocs/Amazon-Market/index.php) (`'caja' => 'CajaApiController'` en `$apiMap`)

---

## 4. Evidencia Antes y Después de las Correcciones

### Antes de corregir (`amazon_market_sprint3_paso7_dup_before_20261112_2143`)
- `C-13k` (`"cash_counted":1,"cash_counted":999` en JSON crudo): devolvió **`HTTP 201` (`OPENED`)**, creó `1` sesión (`saldo_inicial = 1.00`), actualizó `caja_fisica_guardia.id_sesion_abierta_actual = 1` y ocupó la referencia en `control_solicitud_idempotencia`.

### Después de corregir (`amazon_market_sprint3_paso7_dup_after_20261112_2145`)
- Todas las combinaciones de clave repetida (`C-13k` a `C-13p`: número+número, número+cadena, cadena+número largo, dos valores iguales, literal+`\u005f`, `\u005f`+literal) devolvieron **`HTTP 422`** con **`code: "VALIDATION_ERROR"`** y `errors.cash_counted`.
- **Ausencia de escrituras comprobada:** `0` filas en `sesion_caja`, `caja_fisica_guardia.id_sesion_abierta_actual = NULL`, `0` registros en `control_solicitud_idempotencia` para esas referencias, y la referencia de `C-13k` se reutilizó inmediatamente en `C-14` abriendo correctamente con `201 OPENED`.
- Todas las pruebas de precisión decimal (`1.00000000001`, `0.00000000001`, `1.0000000000000000001`, `1.001` como número y cadena) continuaron devolviendo **`422 INVALID_AMOUNT`** sin escrituras.

---

## 5. Bases de Ensayo, Comandos Ejecutados y Resultados Completos

- **Respaldo restaurado:** `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql`
- **Base antes de corrección de duplicados:** `amazon_market_sprint3_paso7_dup_before_20261112_2143`
- **Base definitiva post-corrección Paso 7:** `amazon_market_sprint3_paso7_dup_after_20261112_2145`
- **Bases de regresión Paso 6 + HU 18:**
  - `amazon_market_sprint3_paso7_reg_paso6_20261112_2146`
  - `amazon_market_sprint3_migration_test_paso7_hu18_20261112_2146`
- **`.env` real y `amazon_market`:** Intactos.

### Comandos y códigos de salida
1. **Revisión estática (`php -l` y `git diff --check`):**
   ```powershell
   foreach ($f in @("Libraries\Core\JsonDecimalExtractor.php","Libraries\Core\ApiController.php","Controllers\API\CajaApiController.php","Services\CajaService.php","tests\sprint3\paso7_apertura_caja_test.php")) { C:\xampp\php\php.exe -l $f; if ($LASTEXITCODE -ne 0) { exit 1 } }; git diff --check
   # Exit code: 0
   ```
2. **Suite integral Paso 7 (`214 / 214` aserciones superadas, salida `0`):**
   ```powershell
   $env:TEST_DB_NAME = 'amazon_market_sprint3_paso7_dup_after_20261112_2145'
   $env:TEST_HTTP_PORT = '8094'
   C:\xampp\php\php.exe tests\sprint3\paso7_apertura_caja_test.php
   # Exit code: 0 (214 / 214)
   ```
   - **Pruebas HTTP (`PRE`, `A`, `B`, `C`, `D-01..D-07`, `E`, `G-01`):** `168` aserciones superadas.
   - **Pruebas directas de validador, extractor y rollback (`D-08`, `V-01..V-30`):** `31` aserciones superadas.
   - **Concurrencia multi-proceso (`F-01..F-07` con `worker_concurrencia_paso7.php`):** `7` aserciones superadas.
   - **Comprobaciones SQL de conservación (`G-02..G-08`):** `8` aserciones superadas (`250` ventas con `id_sesion_caja = NULL`; `amazon_market` intacta con `250` ventas, `80` compras, `108` productos, `2497` `stock_actual`, `470` `kardex`).
3. **Regresión completa Paso 6 + HU 18 (`116 / 116` + `11 / 11` HU 18, salida `0`):**
   ```powershell
   $env:TEST_DB_NAME = 'amazon_market_sprint3_paso7_reg_paso6_20261112_2146'
   $env:HU18_DB_NAME = 'amazon_market_sprint3_migration_test_paso7_hu18_20261112_2146'
   $env:TEST_HTTP_PORT = '8095'
   C:\xampp\php\php.exe tests\sprint3\paso6_turnos_programacion_test.php
   # Exit code: 0 (116 / 116)
   ```

---

## 6. Limitaciones y Pendientes Concretos

- Para probar aperturas posteriores a un cierre real sin implementar anticipadamente `EP-20` (Paso 11), la suite utiliza `cerrarSesionFixture()` en la copia aislada respetando todos los triggers aprobados.
- La integración del frontend corresponde al Paso 13.
