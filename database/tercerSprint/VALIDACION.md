# Informe de Validación y Evidencia Reproducible — Migraciones Sprint 3 (Paso 5)

**Proyecto:** Amazon Market (`C:\xampp\htdocs\Amazon-Market`)  
**Etapa del plan:** Paso 5 — Preparar y ensayar las migraciones incrementales del Sprint 3 conservando todos los datos anteriores (incluye Correcciones de Auditoría 1 a 5)  
**Fecha y hora del ensayo definitivo (`America/Lima`, UTC-05:00):** `2026-11-12 15:12:00` – `2026-11-12 15:20:09`  
**Motor y versión:** `10.4.32-MariaDB` (`mariadb.org binary distribution`, `Win64`, motor `InnoDB`)  
**Intérprete PHP CLI:** `PHP 8.2.12 (cli)` (`C:\xampp\php\php.exe`)

---

## 1. Bases de Datos Involucradas y Protección de la Base Original

| Rol de la base de datos | Nombre exacto del esquema | Operaciones ejecutadas en el Paso 5 | Estado final verificado |
| :--- | :--- | :--- | :--- |
| **Base original de la aplicación** | `amazon_market` | **Solo lectura** (`SET SESSION TRANSACTION READ ONLY`) para comparar línea base y conservación. **0 DDL, 0 DML.** | **Intacta** (`32` tablas base, `5` triggers del Sprint 2, checksums idénticos al Paso 3). |
| **Copia de verificación del Paso 3** | `amazon_market_sprint3_verify_20261112_012430` | **Solo lectura** + bloqueo explícito en las herramientas. **0 DDL, 0 DML.** | **Intacta** (`32` tablas base, `5` triggers del Sprint 2, checksums idénticos). |
| **Ensayos históricos previos (conservados sin borrar)** | `amazon_market_sprint3_migration_test_20261112_140017`<br>`amazon_market_sprint3_migration_test_20261112_140630` | Conservadas intactas como evidencia histórica de las iteraciones anteriores sin reutilizar ni borrar sus esquemas. | Conservadas para auditoría. |
| **Nueva base de ensayo definitiva (con Correcciones 1 a 5)** | `amazon_market_sprint3_migration_test_20261112_151230` | Creada desde cero tras verificar inexistencia previa, restaurada sin errores desde el respaldo verificado y migrada en **una única sesión de conexión** (`CONNECTION_ID() = 447`) mediante `ejecutar_migracion_sprint3.php` (`EXIT_CODE = 0`). | **Completada y validada** (`43` tablas, `34` triggers con SHA-256 `95E3ECBC8C132F44FCA851E3DD20B955CF4216BA76F2ED29297649C09A63C0A3`, `100 %` de datos previos conservados, `56/56` casos superados). |
| **Base dedicada a pruebas de fallo controlado y bloqueo de sesión** | `amazon_market_sprint3_fail_sim_20261112_151230` | Creada y restaurada desde el respaldo verificado exclusivamente para demostrar detención inmediata al primer error (`--simulate-fail-step2`), no avance prematuro de `paso_actual`, rechazo de orden y rechazo de `GET_LOCK` en otra conexión. | En estado parcial controlado tras las pruebas negativas de las Correcciones 4 y 5 (`estado = EN_PROGRESO`, `paso_actual = 01_PREFLIGHT`, `0` tablas de pasos `03..05`). |

---

## 2. Verificación del Respaldo, Vigencia de la Línea Base y Conciliación de `kardex`

### 2.1. Comprobación del archivo de respaldo del Paso 3
- **Ruta:** `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql`
- **Existencia:** `True`
- **Tamaño:** `467692` bytes (coincide exactamente con los `467,692` bytes registrados en el Paso 3)
- **Hash SHA-256 calculado:** `7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D` (coincide bit a bit con el hash registrado en el Paso 3)
- **Inspección previa a la restauración:**
  - Directivas `USE` o `CREATE DATABASE` en el archivo: **`0`**
  - Referencias calificadas a `amazon_market.` en tablas o triggers: **`0`**

### 2.2. Comprobación de vigencia de `amazon_market` y conciliación exacta del conteo de `kardex`
1. **Vigencia de la base original:** Las `32` tablas de `amazon_market` presentan `0` diferencias frente a `amazon_market_sprint3_verify_20261112_012430`.
2. **Conciliación documentada del conteo de `kardex` (`470` vs `476`):**
   - El conteo real de filas en la tabla activa `kardex` tanto en `amazon_market`, en `amazon_market_sprint3_verify_20261112_012430`, en `sprint3_migracion_control.conteos_base_json` y en `sprint3_migracion_control.conteos_finales_json` como en la salida JSON de `verificar_conservacion_y_reglas_sprint3.php` es exactamente **`470` filas** (mientras que `sprint2_archivo_kardex` contiene **`26` filas**, sumando `496` movimientos históricos totales).
   - El número `476` que apareció en la tabla Markdown de la versión preliminar de `VALIDACION.md` fue un error tipográfico de transcripción manual en el documento Markdown (confusión visual con el dígito `6` de `26` en `sprint2_archivo_kardex`). En la tabla de la Sección 5 de este documento queda conciliado con el valor real verificado por SQL: **`470` filas**.

---

## 3. Resumen de las 5 Correcciones Aplicadas en el Paso 5

| # | Defecto auditado | Archivo(s) modificado(s) | Corrección aplicada |
| :-: | :--- | :--- | :--- |
| **1** | **Versión del arqueo al cerrar (`version_caja`)** | [03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql](03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql) | En `trg_sesion_caja_before_update_integridad`, al transicionar de `ABIERTA` a `CERRADA`, el arqueo de cierre se valida contra la versión previa al cierre (`v_arq_estado = 'VIGENTE' AND v_arq_version = OLD.version_caja`) y se exige incrementar la sesión en `+1` (`NEW.version_caja = OLD.version_caja + 1`, error `CIERRE_VERSION_INCREMENTO_REQUERIDO` si no se incrementa o salta a otro valor), conforme a `EP-20`. El arqueo de cierre permanece `VIGENTE` con su `version_caja_evaluada = OLD.version_caja` intacta. |
| **2** | **Observaciones de hasta 500 caracteres** | [03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql](03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql) | Se ampliaron `arqueo_caja.observacion`, `sesion_caja.observacion_cierre` y la variable local `v_arq_obs` de `VARCHAR(250)` a `VARCHAR(500)`. Se actualizaron `chk_arqueo_observacion_condicional` (`1..500` cuando `diferencia <> 0.00`, `<= 500` cuando `diferencia = 0.00`) y `chk_sesion_estado_cierre_montos` (`observacion_cierre IS NULL` mientras `ABIERTA`, `<= 500` cuando `CERRADA`). Se conservaron en `VARCHAR(250)` los motivos de `movimiento_caja`, `correccion_movimiento_caja` y `autorizacion_caja`. |
| **3** | **Congelamiento real NULL-safe de sesión `CERRADA`** | [03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql](03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql) | Se eliminaron todos los valores centinela (`-999999.99`, `'1900-01-01'`) y se reemplazaron por comparaciones NULL-safe reales (`NOT (OLD.col <=> NEW.col)`). Cuando `OLD.estado = 'CERRADA'`, se bloquean las **36 columnas** de `sesion_caja` (incluyendo `observacion_cierre`, `id_ultimo_arqueo`, `id_arqueo_cierre`, importes, horarios, referencias y estado), y en `trg_arqueo_caja_before_update_integridad` se bloquea cualquier cambio sobre arqueos cuya sesión padre esté `CERRADA`. Mientras está `ABIERTA`, las 19 columnas de identidad, apertura, horario y cierre anterior son inmutables (`<=>`). |
| **4** | **Ejecución en una sola conexión con `GET_LOCK` vigente y detención al primer error** | [tools/ejecutar_migracion_sprint3.php](tools/ejecutar_migracion_sprint3.php)<br>Scripts `01` a `06`<br>[README.md](README.md) | Se implementó `ejecutar_migracion_sprint3.php`, que ejecuta los scripts `01` a `06` dentro de una única sesión `mysql.exe --abort-source-on-error`. Los scripts `02` a `06` verifican que la conexión actual sea la titular del bloqueo adquirido en el paso `01` (`COALESCE(IS_USED_LOCK(v_lock_name), -1) = CONNECTION_ID()`, error `LOCK_MIGRACION_NO_VIGENTE`). |
| **5** | **Actualización de `paso_actual` únicamente al finalizar cada script** | Scripts `01` a `06` | En los scripts `01` a `05`, `UPDATE sprint3_migracion_control SET paso_actual = ...` se movió a un bloque `BEGIN NOT ATOMIC ... END$$` situado **al final del archivo**, después de todos los `CREATE TRIGGER`, verificando en `information_schema` que existan todas las tablas, columnas, FKs y triggers del paso antes de avanzar `paso_actual`. En el paso `06`, se comprueba que existan las `43` tablas y los `34` triggers antes de marcar `estado = 'COMPLETADA'`. |

---

## 4. Comandos Ejecutados y Códigos de Salida en la Nueva Base de Ensayo

### 4.1. Creación y restauración limpia de `amazon_market_sprint3_migration_test_20261112_151230`
```powershell
$dbTest = 'amazon_market_sprint3_migration_test_20261112_151230'
$dbFailSim = 'amazon_market_sprint3_fail_sim_20261112_151230'
$backup = 'C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql'

C:\xampp\mysql\bin\mysql.exe -u root -h 127.0.0.1 -P 3306 -e "CREATE DATABASE $dbTest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE $dbFailSim CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cmd.exe /c "C:\xampp\mysql\bin\mysql.exe -u root -h 127.0.0.1 -P 3306 --default-character-set=utf8mb4 $dbTest < `"$backup`""
# RESTORE_TEST_EXIT=0 (32 tablas base y 5 triggers restaurados)

cmd.exe /c "C:\xampp\mysql\bin\mysql.exe -u root -h 127.0.0.1 -P 3306 --default-character-set=utf8mb4 $dbFailSim < `"$backup`""
# RESTORE_FAILSIM_EXIT=0 (32 tablas base y 5 triggers restaurados)
```

### 4.2. Ejecución en una sola conexión (`ejecutar_migracion_sprint3.php`)
```powershell
C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_migracion_sprint3.php amazon_market_sprint3_migration_test_20261112_151230
# RUNNER_EXIT=0
```

| Paso | Script ejecutado | `CONNECTION_ID()` | `IS_USED_LOCK(...)` al iniciar | Estado y `paso_actual` al finalizar el archivo |
| :-: | :--- | :-: | :-: | :--- |
| **01** | [01_preflight_y_control_sprint3.sql](01_preflight_y_control_sprint3.sql) | `447` | `-1` *(adquiere `447`)* | `EN_PROGRESO \| 01_PREFLIGHT` |
| **02** | [02_estructura_turnos_y_programacion_sprint3.sql](02_estructura_turnos_y_programacion_sprint3.sql) | `447` | `447` | `EN_PROGRESO \| 02_TURNOS_Y_PROGRAMACION` |
| **03** | [03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql](03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql) | `447` | `447` | `EN_PROGRESO \| 03_SESIONES_ARQUEOS_AUTORIZACIONES` |
| **04** | [04_estructura_movimientos_correcciones_y_ventas_sprint3.sql](04_estructura_movimientos_correcciones_y_ventas_sprint3.sql) | `447` | `447` | `EN_PROGRESO \| 04_MOVIMIENTOS_CORRECCIONES_VENTAS` |
| **05** | [05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql](05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql) | `447` | `447` | `EN_PROGRESO \| 05_IDEMPOTENCIA_Y_REPORTES` |
| **06** | [06_configuracion_permisos_y_cierre_migracion_sprint3.sql](06_configuracion_permisos_y_cierre_migracion_sprint3.sql) | `447` | `447` *(libera al cerrar)* | `COMPLETADA \| 06_COMPLETADA` |

---

## 5. Comparación Antes / Después de las 32 Tablas Existentes (Conservación del 100 % de los Datos)

La herramienta [verificar_conservacion_y_reglas_sprint3.php](tools/verificar_conservacion_y_reglas_sprint3.php) comprobó en `amazon_market_sprint3_migration_test_20261112_151230` frente a `amazon_market` y `amazon_market_sprint3_verify_20261112_012430` el conteo de filas, el valor `AUTO_INCREMENT`, el `CHECKSUM TABLE ... EXTENDED` y la huella `SHA-256` canónica de todas las filas previas (`conservation_all_passed = true`):

| # | Tabla | Filas antes (`amazon_market`) | Filas después (`test_db`) | `AUTO_INCREMENT` antes / después | `CHECKSUM EXTENDED` antes / después | SHA-256 filas previas idéntico | Estado |
| --: | :--- | --: | --: | :---: | :---: | :-: | :-: |
| 1 | `categoria` | 6 | 6 | `7` / `7` | `3177129530` / `3177129530` | Sí | **OK** |
| 2 | `cliente` | 14 | 14 | `17` / `17` | `1808869450` / `1808869450` | Sí | **OK** |
| 3 | `compra` | 80 | 80 | `86` / `86` | `2863598311` / `2863598311` | Sí | **OK** |
| 4 | `compra_historial` | 0 | 0 | `4` / `4` | `0` / `0` | Sí | **OK** |
| 5 | `control_solicitud_idempotencia` | 405 | 405 | `406` / `406` | `222073421` / `222073421` | Sí | **OK** |
| 6 | `detalle_compra` | 100 | 100 | `116` / `116` | `1408780884` / `1408780884` | Sí | **OK** |
| 7 | `detalle_venta` | 295 | 295 | `1036` / `1036` | `1179342763` / `1179342763` | Sí | **OK** |
| 8 | `kardex` | **470** | **470** | `498` / `498` | `2535760088` / `2535760088` | Sí | **OK** |
| 9 | `metodo_pago` | 3 | 3 | `4` / `4` | `3125332202` / `3125332202` | Sí | **OK** |
| 10 | `operacion_inventario` | 75 | 75 | `76` / `76` | `107647376` / `107647376` | Sí | **OK** |
| 11 | `permiso` | 31 | **36** *(+5 nuevos)* | `32` / **`37`** | `2804841890` / `2738333541` *(esperado)* | Sí (`id <= 31`) | **OK** |
| 12 | `presentacion` | 3 | 3 | `4` / `4` | `101987507` / `101987507` | Sí | **OK** |
| 13 | `producto` | 108 | 108 | `109` / `109` | `2567928720` / `2567928720` | Sí | **OK** |
| 14 | `proveedor` | 3 | 3 | `4` / `4` | `2360316501` / `2360316501` | Sí | **OK** |
| 15 | `reporte_consulta_snapshot` | 2 | 2 | `30` / `30` | `730557223` / `730557223` | Sí | **OK** |
| 16 | `reporte_consulta_snapshot_item` | 150 | 150 | `NULL` / `NULL` | `3827838501` / `3827838501` | Sí | **OK** |
| 17 | `rol` | 2 | 2 | `3` / `3` | `3278483892` / `3278483892` | Sí | **OK** |
| 18 | `rol_permiso` | 30 | **35** *(+5 nuevos)* | `NULL` / `NULL` | `3696663419` / `1094579215` *(esperado)* | Sí (`30` previas) | **OK** |
| 19 | `serie_comprobante` | 2 | 2 | `4` / `4` | `3941749175` / `3941749175` | Sí | **OK** |
| 20 | `sprint2_archivo_compra` | 4 | 4 | `6` / `6` | `3743211542` / `3743211542` | Sí | **OK** |
| 21 | `sprint2_archivo_compra_historial` | 3 | 3 | `4` / `4` | `4187396716` / `4187396716` | Sí | **OK** |
| 22 | `sprint2_archivo_detalle_compra` | 4 | 4 | `16` / `16` | `3428466409` / `3428466409` | Sí | **OK** |
| 23 | `sprint2_archivo_detalle_venta` | 377 | 377 | `741` / `741` | `1097668984` / `1097668984` | Sí | **OK** |
| 24 | `sprint2_archivo_kardex` | 26 | 26 | `28` / `28` | `4178226246` / `4178226246` | Sí | **OK** |
| 25 | `sprint2_archivo_venta` | 98 | 98 | `124` / `124` | `4022598553` / `4022598553` | Sí | **OK** |
| 26 | `sprint2_fase10_control` | 1 | 1 | `NULL` / `NULL` | `1545232442` / `1545232442` | Sí | **OK** |
| 27 | `subcategoria` | 6 | 6 | `7` / `7` | `2869915229` / `2869915229` | Sí | **OK** |
| 28 | `tipo_comprobante` | 2 | 2 | `3` / `3` | `1294789679` / `1294789679` | Sí | **OK** |
| 29 | `tipo_documento` | 4 | 4 | `5` / `5` | `247368920` / `247368920` | Sí | **OK** |
| 30 | `unidad_medida` | 4 | 4 | `5` / `5` | `3064936218` / `3064936218` | Sí | **OK** |
| 31 | `usuario` | 6 | 6 | `10` / `10` | `1537687628` / `1537687628` | Sí | **OK** |
| 32 | `venta` | 250 | 250 | `374` / `374` | `1012757053` / `433646377` *(nueva col `NULL`)* | Sí (`250` filas intactas) | **OK** |

---

## 6. Resultados de las 56 Pruebas de Dominio, Correcciones 1 a 5 y Concurrencia

### 6.1. Batería de 56 pruebas positivas y negativas (`domain_cases_all_passed = true`, `VERIFY_ALL_EXIT = 0`)
Comando reproducible:
```powershell
C:\xampp\php\php.exe database/tercerSprint/tools/verificar_conservacion_y_reglas_sprint3.php --test-db=amazon_market_sprint3_migration_test_20261112_151230 --fail-sim-db=amazon_market_sprint3_fail_sim_20261112_151230
```

| Código | Tipo | Descripción de la prueba ejecutada | Resultado | Evidencia devuelta por MariaDB / Verificador |
| :--- | :-: | :--- | :-: | :--- |
| `A1_TURNO_CREAR_ACTIVOS` | Positiva | Crear turnos activos `Mañana` (`08:00-14:00`) y `Tarde` (`14:00-20:00`) | **PASS** | `idTurnoManana=1, idTurnoTarde=2` |
| `A2_TURNO_HORARIO_INVALIDO` | Negativa | Rechazar turno con `hora_fin <= hora_inicio` (`14:00` a `08:00`) | **PASS** | `4025 CONSTRAINT chk_turno_horario failed` |
| `A3_TURNO_NOMBRE_ACTIVO_DUPLICADO` | Negativa | Rechazar nombre activo duplicado normalizado (`"  mañana "`) | **PASS** | `1062 Duplicate entry 'mañana' for key 'uq_turno_nombre_activo'` |
| `A4_TURNO_DELETE_FISICO_PROHIBIDO` | Negativa | Rechazar `DELETE` físico sobre el catálogo de turnos | **PASS** | `1644 BAJA_LOGICA_REQUERIDA` |
| `A5_TURNO_BAJA_LOGICA_REUTILIZA_NOMBRE` | Positiva | Dar de baja lógica (`estado = 0`) un turno y reutilizar el mismo nombre en un nuevo turno activo | **PASS** | `Turno inactivo id=4 conservado; nuevo turno 'Intermedio' creado con id=5` |
| `B1_PROG_FECHA_NO_LUNES` | Negativa | Rechazar `semana_inicio` que no sea lunes (`2026-11-11` miércoles) | **PASS** | `1644 INVALID_WEEK_START_DATE` |
| `B2_PROG_PRIMERA_SIN_BASE` | Negativa | Rechazar primera programación si es excepción (`alcance = 'semana'`) sin base previa | **PASS** | `1644 PRIMERA_PROGRAMACION_REQUIERE_BASE` |
| `B3_PROG_BASE_MULTIPLES_TURNOS_MISMO_DIA` | Positiva | Guardar primera base permanente y asignar dos turnos (`Mañana` y `Tarde`) el mismo día lunes (`dia_semana = 0`) | **PASS** | `idProgBase1=1, turnos_lunes=2` |
| `B4_PROG_EXCEPCION_PRESERVADA_ANTE_NUEVA_BASE` | Positiva | Crear excepción semanal (`2026-11-23`) y luego nueva base (`desde 2026-11-16`); verificar que la excepción prevalece en su semana y luego rige la nueva base (Sección 13.1) | **PASS** | `Semana 2026-11-23 -> Excepción id=2; Semana 2026-11-30 -> Nueva base id=3` |
| `B5_TURNO_BAJA_CON_VENDEDOR_ASIGNADO` | Negativa | Rechazar baja lógica de turno asignado a vendedor en programación vigente | **PASS** | `1644 SHIFT_HAS_ASSIGNED_SELLERS` |
| `C1_APERTURA_ADMIN_PROHIBIDA` | Negativa | Rechazar apertura de sesión por usuario con rol Administrador (`id_rol = 1`) | **PASS** | `1644 FORBIDDEN_SELLER_ONLY` |
| `C2_PRIMERA_APERTURA_SIN_CIERRE_ANTERIOR` | Positiva | Abrir primera sesión histórica con `tiene_cierre_anterior = 0`, `id_sesion_cierre_anterior = NULL` y `diferencia_apertura = NULL` | **PASS** | `idSesion1=1 ABIERTA con saldo_inicial=100.00, version_caja=1` |
| `C3_SEGUNDA_SESION_ABIERTA_RECHAZADA` | Negativa | Rechazar segunda sesión `ABIERTA` simultánea mediante guardia única | **PASS** | `1062 Duplicate entry '1' for key 'uq_sesion_caja_unica_abierta'` |
| `D1_MOVIMIENTOS_INGRESO_EGRESO` | Positiva | Registrar `INGRESO_MANUAL` (`+50.00`), `INYECCION_ADMIN` (`+30.00`) y `SALIDA_CAJA` (`-20.00`) | **PASS** | `idMovIngreso=1; efectivo_esperado_actual=160.00, version_caja=4` |
| `D2_INYECCION_ADMIN_SIN_ADMIN` | Negativa | Rechazar `INYECCION_ADMIN` sin `id_usuario_admin` | **PASS** | `1644 ADMIN_AUTHORIZATION_REQUIRED` |
| `D3_MOVIMIENTO_UPDATE_PROHIBIDO` | Negativa | Rechazar edición directa (`UPDATE`) sobre `movimiento_caja` original | **PASS** | `1644 MOVIMIENTO_CAJA_INMUTABLE` |
| `E1_CORRECCIONES_ENCADENADAS_SIN_MUTAR_ORIGINAL` | Positiva | Registrar 2 correcciones encadenadas (`50.00 -> 65.00 -> 45.00`) verificando que el original conserva `50.00` y el efecto neto se contabiliza una sola vez | **PASS** | `Original intacto=50.00, current_amount derivado=45.00, efectivo_esperado_actual=155.00, version_caja=6` |
| `F1_ARQUEO_DIFERENCIA_SIN_OBSERVACION` | Negativa | Rechazar arqueo con `diferencia <> 0.00` y `observacion` nula | **PASS** | `4025 CONSTRAINT chk_arqueo_observacion_condicional failed` |
| `CORR2_F_DIFERENCIA_OBSERVACION_SOLO_ESPACIOS` | Negativa | **Corrección 2:** Rechazar arqueo con `diferencia <> 0.00` y observación de solo espacios (`"     "`) | **PASS** | `4025 CONSTRAINT chk_arqueo_observacion_condicional failed` |
| `F2_ARQUEO_REEMPLAZO_Y_UNICO_VIGENTE` | Positiva | Registrar arqueo #1 (`v6`), reemplazarlo por arqueo #2 (`v6`) conservando historial y único `VIGENTE` por sesión | **PASS** | `Arqueo #1 (1) REEMPLAZADO; Arqueo #2 (2) VIGENTE en version_caja_evaluada=6` |
| `G1_CIERRE_ANTICIPADO_SIN_AUTORIZACION` | Negativa | Rechazar cierre anticipado (`> 15 min` antes de salida de referencia) sin `autorizacion_caja` incluso con incremento `6 -> 7` | **PASS** | `1644 EARLY_CLOSE_AUTH_REQUIRED` |
| `G2_CORR1_E_CIERRE_ANTICIPADO_CON_AUTORIZACION_E_INCREMENTO_VERSION` | Positiva | **Corrección 1-E:** Registrar autorización administrativa (`60 min` antes) y cerrar Sesión #1 incrementando `version_caja` de `6` a `7` con arqueo `VIGENTE` evaluado en `v6` | **PASS** | `idAutorizacion=1; Sesión #1 CERRADA en version_caja=7 con arqueo #2 VIGENTE (version_caja_evaluada=6)` |
| `CORR3_A1_SESION_CERRADA_MUTAR_OBSERVACION_CIERRE` | Negativa | **Corrección 3:** Rechazar cambio de `observacion_cierre` (`valor -> otro valor`) en sesión `CERRADA` | **PASS** | `1644 CASH_SESSION_NOT_OPEN` |
| `CORR3_A2_SESION_CERRADA_ANULAR_OBSERVACION_CIERRE` | Negativa | **Corrección 3:** Rechazar cambio NULL-safe de `observacion_cierre` (`valor -> NULL`) en sesión `CERRADA` | **PASS** | `1644 CASH_SESSION_NOT_OPEN` |
| `CORR3_B_SESION_CERRADA_MUTAR_IMPORTES` | Negativa | **Corrección 3:** Rechazar cambio de importes (`efectivo_contado_cierre` / `diferencia_cierre`) en sesión `CERRADA` | **PASS** | `1644 CASH_SESSION_NOT_OPEN` |
| `CORR3_C1_SESION_CERRADA_MUTAR_HORARIO_REFERENCIA` | Negativa | **Corrección 3:** Rechazar cambio de horario/referencia (`fecha_hora_salida_referencia` / `nombre_turno_referencia`) en sesión `CERRADA` | **PASS** | `1644 CASH_SESSION_NOT_OPEN` |
| `CORR3_C2_SESION_CERRADA_MUTAR_CAMPOS_NULL_A_VALOR` | Negativa | **Corrección 3:** Rechazar cambio NULL-safe (`NULL -> valor`) en `diferencia_apertura` en sesión `CERRADA` | **PASS** | `1644 CASH_SESSION_NOT_OPEN` |
| `CORR3_D_SESION_CERRADA_MUTAR_ARQUEO_CIERRE` | Negativa | **Corrección 3:** Rechazar cambio de `id_arqueo_cierre` / `id_ultimo_arqueo` en sesión `CERRADA` | **PASS** | `1644 CASH_SESSION_NOT_OPEN` |
| `CORR3_E_SESION_CERRADA_REABRIR` | Negativa | **Corrección 3:** Rechazar intento de reapertura (`estado = 'ABIERTA'`) de una sesión `CERRADA` | **PASS** | `1644 CASH_SESSION_NOT_OPEN` |
| `CORR3_F_ARQUEO_DE_SESION_CERRADA_INMUTABLE` | Negativa | **Corrección 3:** Rechazar cambio de estado en `arqueo_caja` perteneciente a una sesión ya `CERRADA` | **PASS** | `1644 HISTORIAL_ARQUEO_INMUTABLE` |
| `CORR3_G_VERIFICAR_FILA_SESION_CERRADA_INTACTA` | Positiva | **Corrección 3:** Comprobar mediante hash SHA-256 de las 36 columnas que la fila de `sesion_caja` `CERRADA` permaneció 100 % intacta tras todos los intentos de mutación | **PASS** | `SHA-256 intacto en las 36 columnas: 36492EB413C1D9734A637D99B61D5D2874B230657B9EC5481D71297EEFDBFCB6` |
| `C4_APERTURA_POSTERIOR_SIN_REFERENCIA_CIERRE` | Negativa | Rechazar apertura con `tiene_cierre_anterior = 0` cuando ya existen sesiones cerradas | **PASS** | `1644 APERTURA_REQUIERE_CIERRE_ANTERIOR` |
| `C5_SEGUNDA_APERTURA_CON_DIFERENCIA_Y_RETIRO_OMITIDO` | Positiva | Abrir Sesión #2 referenciando Sesión #1 (`diferencia_apertura = -20.00`), registrar `RETIRO_OMITIDO` (`efecto_saldo = 0.00`) y cerrar dentro de la ventana de 15 min incrementando `version_caja` (`2 -> 3`) | **PASS** | `Sesión #3 abierta con dif=-20.00, RETIRO_OMITIDO=20.00 (efecto=0.00) y cerrada a las 19:50 en version_caja=3` |
| `CORR3_H_SESION2_CERRADA_MUTAR_OBSERVACION_NULL_A_VALOR` | Negativa | **Corrección 3:** Rechazar cambio NULL-safe de `observacion_cierre` (`NULL -> valor`) en Sesión #2 `CERRADA` | **PASS** | `1644 CASH_SESSION_NOT_OPEN` |
| `CORR1_SETUP_SESION3_V4` | Positiva | Abrir Sesión #3, registrar arqueo en `v4`, avanzar sesión a `version_caja = 5` y registrar autorización administrativa | **PASS** | `idSesion3=4 en version_caja=5 con arqueo #5 evaluado en v4` |
| `CORR1_B_ARQUEO_V4_CON_SESION_V5_RECHAZADO` | Negativa | **Corrección 1-B:** Rechazar cierre de sesión en versión `5` cuando el arqueo tiene `version_caja_evaluada = 4` | **PASS** | `1644 CASH_COUNT_OUTDATED` |
| `CORR1_F_AUTORIZACION_NO_PERMITE_ARQUEO_DESACTUALIZADO` | Negativa | **Corrección 1-F:** Comprobar que aun con autorización administrativa válida registrada, se rechaza cerrar con arqueo desactualizado (`v4` en sesión `v5`) | **PASS** | `1644 CASH_COUNT_OUTDATED` |
| `CORR2_A_OBSERVACION_250_CARACTERES` | Positiva | **Corrección 2:** Permitir arqueo en `v5` con observación de exactamente `250` caracteres | **PASS** | `idArqueo=6, CHAR_LENGTH(observacion)=250` |
| `CORR2_B_OBSERVACION_251_CARACTERES` | Positiva | **Corrección 2:** Permitir arqueo en `v5` con observación de `251` caracteres (antes rechazada por límite de 250) | **PASS** | `idArqueo=7, CHAR_LENGTH(observacion)=251` |
| `CORR2_C_OBSERVACION_500_CARACTERES_UNICODE` | Positiva | **Corrección 2:** Permitir arqueo en `v5` con observación de exactamente `500` caracteres Unicode con tildes y eñes (`CHAR_LENGTH = 500`, `OCTET_LENGTH = 550`) | **PASS** | `idArqueo=8, CHAR_LENGTH=500, OCTET_LENGTH=550` |
| `CORR2_D_OBSERVACION_501_CARACTERES_RECHAZADA` | Negativa | **Corrección 2:** Rechazar arqueo con observación de `501` caracteres bajo modo SQL estricto | **PASS** | `1406 Data too long for column 'observacion' at row 1` |
| `CORR1_C_ARQUEO_REEMPLAZADO_O_NO_ULTIMO_RECHAZADO` | Negativa | **Corrección 1-C:** Rechazar cierre cuando se intenta usar un arqueo `REEMPLAZADO` o distinto de `id_ultimo_arqueo` | **PASS** | `1644 CASH_COUNT_REQUIRED` |
| `CORR1_D1_CIERRE_SIN_INCREMENTAR_VERSION_RECHAZADO` | Negativa | **Corrección 1-D (1):** Rechazar cierre cuando `version_caja` no se incrementa (permanece en `5` en lugar de subir a `6`) | **PASS** | `1644 CIERRE_VERSION_INCREMENTO_REQUERIDO` |
| `CORR1_D2_CIERRE_INCREMENTO_SALTO_INVALIDO_RECHAZADO` | Negativa | **Corrección 1-D (2):** Rechazar cierre cuando `version_caja` se incrementa en `+2` (de `5` a `7` en lugar de `6`) | **PASS** | `1644 CIERRE_VERSION_INCREMENTO_REQUERIDO` |
| `CORR2_E_OBSERVACION_CIERRE_501_RECHAZADA` | Negativa | **Corrección 2:** Rechazar `observacion_cierre` de `501` caracteres al cerrar sesión bajo modo estricto | **PASS** | `1406 Data too long for column 'observacion_cierre' at row 1` |
| `CORR1_A_Y_CORR2_COPIA_INTACTA_500_AL_CERRAR` | Positiva | **Corrección 1-A y Corrección 2:** Cerrar Sesión #3 (`version_caja` `5 -> 6` con arqueo `VIGENTE` versión `5`) y verificar copia íntegra sin truncamiento de los `500` caracteres Unicode a `observacion_cierre` | **PASS** | `Sesión #4 cerrada en version_caja=6; arqueo #8 permanece VIGENTE en version_caja_evaluada=5; observacion_cierre copiada íntegra (CHAR_LENGTH=500)` |
| `I1_SNAPSHOT_CIERRES_CAJA_VALIDO` | Positiva | Crear cabecera en `reporte_consulta_snapshot` (`tipo_reporte = 'cierres_caja'`) e ítem congelado en `reporte_caja_snapshot_item` | **PASS** | `idSnapshotCaja=30 con ítem para sesión #1` |
| `I2_SNAPSHOT_CAJA_JSON_INVALIDO` | Negativa | Rechazar ítem en `reporte_caja_snapshot_item` con `proyeccion_json` inválido | **PASS** | `4025 CONSTRAINT chk_reporte_caja_item_orden_y_json failed` |
| `TRIGGERS_34_DEFINICIONES_INTACTAS` | Positiva | Comprobar que tras la limpieza de datos sintéticos permanecen exactamente los mismos `34` triggers y definiciones (`SHA-256` idéntico al estado inicial post-migración) | **PASS** | `count_initial=34, count_final=34, sha256=95E3ECBC8C132F44FCA851E3DD20B955CF4216BA76F2ED29297649C09A63C0A3` |
| `CORR5_D_REEJECUCION_POST_COMPLETADA_RECHAZADA` | Negativa | **Corrección 5-D:** Rechazar re-ejecución de migración sobre una base con estado `COMPLETADA` | **PASS** | `exit=1; 1644 SPRINT3_YA_APLICADA` |
| `CORR4_PROTECCION_AMAZON_MARKET_RECHAZADA` | Negativa | **Corrección 4:** El ejecutor rechaza explícitamente apuntar a `amazon_market` | **PASS** | `exit=2; [ABORTADO] PROTECCION_ACTIVA` |
| `CORR4_C_SIN_BASE_CONFIRMADA_RECHAZADO` | Negativa | **Corrección 4-C:** Rechazar ejecución cuando falta `@SPRINT3_BASE_CONFIRMADA` | **PASS** | `exit=1; 1644 BASE_DESTINO_NO_CONFIRMADA` |
| `CORR4_E_CONCURRENCIA_SIMULTANEA_RECHAZADA` | Negativa | **Corrección 4-E:** Dos ejecuciones concurrentes no pueden correr simultáneamente sobre la misma base (`GET_LOCK` activo en Conexión #1 rechaza a Conexión #2) | **PASS** | `conn1_lock=1, conn2_exit=1; 1644 MIGRACION_EN_CURSO` |
| `CORR4_B_Y_CORR5_A_FALLO_INTERMEDIO_DETIENE_Y_NO_AVANZA_PASO` | Negativa | **Corrección 4-B y 5-A:** Fallo antes de terminar triggers del Paso 02 detiene inmediatamente el ejecutor sin correr pasos `03..06` y mantiene `paso_actual = 01_PREFLIGHT` | **PASS** | `exit=1, estado=EN_PROGRESO, paso_actual=01_PREFLIGHT, triggers_paso02=6/7, tablas_pasos_03_a_05=0` |
| `CORR5_B_SIGUIENTE_SCRIPT_TRAS_FALLO_RECHAZADO` | Negativa | **Corrección 5-B:** Rechazar intento de ejecutar el Paso 03 después de que el Paso 02 falló antes de completar sus triggers | **PASS** | `exit=1; 1644 ORDEN_MIGRACION_INVALIDO` |
| `CORR4_D_PASO_SIN_GET_LOCK_VIGENTE_RECHAZADO` | Negativa | **Corrección 4-D:** Rechazar ejecución de script intermedio (Paso 02) en una conexión que no posee el `GET_LOCK` activo del Paso 01 | **PASS** | `exit=1; 1644 LOCK_MIGRACION_NO_VIGENTE` |

### 6.2. Prueba de concurrencia real en apertura de sesión (`ensayo_concurrencia_apertura_sprint3.php`)
Ejecutada sobre `amazon_market_sprint3_migration_test_20261112_151230` (`CONC_EXIT = 0`):
```json
{
    "test_database": "amazon_market_sprint3_migration_test_20261112_151230",
    "passed": true,
    "open_sessions_after_race": 1,
    "guard_row_after_race": {
        "id_caja_fisica": 1,
        "id_sesion_abierta_actual": 1,
        "id_ultima_sesion_cerrada": null
    },
    "worker_1": {
        "worker": 1,
        "status": "REJECTED",
        "sqlstate": "23000",
        "error": "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'uq_sesion_caja_unica_abierta'",
        "elapsed_ms": 156.67
    },
    "worker_2": {
        "worker": 2,
        "status": "COMMITTED",
        "id_sesion_caja": 1,
        "guard_seen_open_before": null,
        "elapsed_ms": 85.37
    }
}
```

---

## 7. Limitaciones Reales Pendientes para los Pasos de Servicios (Pasos 6 a 12)

1. **`usuario.email IS NULL` en el administrador `id_usuario = 1`:**
   - Las 6 filas de `usuario` permanecen intactas conforme a la regla de conservación del Paso 5. En el Paso 8, solo los administradores activos con correo configurado (como `id_usuario = 9`) o aquellos a quienes se configure su correo por la vía funcional normal podrán autenticarse por `admin_email` al autorizar cierres anticipados.
2. **Exigencia de sesión abierta para nuevas ventas POS (`Paso 9`):**
   - La columna `venta.id_sesion_caja` es `NULL` a nivel de esquema para conservar las 250 ventas históricas sin inventar sesiones ficticias. La exigencia de sesión `ABIERTA` propiedad del vendedor autenticado para toda nueva venta se aplicará en `VentaService::procesarVenta()` durante el Paso 9.
3. **Separación de rutas y bypass general de `id_rol = 1` en `RBACMiddleware` (`Paso 6`):**
   - En `rol_permiso` ya están separados los permisos operativos de Vendedor (`id_rol = 2`) y los administrativos de Administrador (`id_rol = 1`). En el Paso 6 se ajustará `RBACMiddleware` y `ApiController` para que el bypass general del rol `1` no habilite a un Administrador a operar endpoints exclusivos de Vendedor.


## 8. Corrección adicional de HU 18: baja con bases históricas

**Ensayo independiente iniciado el 12 de noviembre de 2026 a las 17:23:21, America/Lima.** Esta sección corresponde a la versión corregida del script 02 y complementa la evidencia anterior; no atribuye a las corridas anteriores una corrección que todavía no tenían.

### 8.1. Defecto y cambio acotado

El trigger `trg_turno_before_update_integridad` contaba cualquier base `desde` con `es_vigente = 1`, aunque ya no rigiera desde el comienzo de otra base más reciente. Eso impedía la baja lógica de turnos usados únicamente en semanas pasadas.

Se reemplazó solo ese cálculo en `02_estructura_turnos_y_programacion_sprint3.sql`: considera las excepciones actuales/futuras y los intervalos de aplicación de cada base, descontando semanas cubiertas por excepciones. No altera cabeceras, detalles, versiones, permisos, estructura monetaria ni las cinco correcciones anteriores. Se conserva el bloqueo por sesión abierta.

### 8.2. Bases y respaldo

| Base nueva | Uso / resultado |
| --- | --- |
| `amazon_market_sprint3_migration_test_hu18_red_20261112_172321` | Restaurada y migrada con la versión ANTERIOR del trigger. Regresión: 9/11 PASS, 2 fallos esperados que reproducen el defecto. Se conserva sin actualizar. |
| `amazon_market_sprint3_migration_test_hu18_green_20261112_172321` | Restaurada y migrada con el script 02 corregido. 11/11 regresiones nuevas PASS, 56/56 casos anteriores PASS y apertura concurrente PASS. |
| `amazon_market_sprint3_fail_sim_hu18_20261112_172321` | Restaurada para los casos de error parcial, lock, orden y detención del ejecutor. Conservada como evidencia. |

Se comprobó inexistencia antes de crear cada base y código de restauración 0. Respaldo externo utilizado: `C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql`, SHA-256 verificado `7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D`. No se eliminó ni modificó una base anterior para reutilizarla.

### 8.3. Reproducción previa y resultados de regresión

Herramienta nueva: `tools/verificar_baja_turnos_programacion_sprint3.php`.

| Caso | Antes | Después |
| --- | --- | --- |
| Base solo histórica, desplazada por base actual distinta | FAIL: bloqueo incorrecto | PASS: baja lógica permitida |
| Base actual con el turno | PASS | PASS: bloqueo conservado |
| Base futura con el turno | PASS | PASS: bloqueo conservado |
| Excepción futura con el turno, conservada al cambiar la base | PASS | PASS: bloqueo conservado |
| Turno únicamente en excepción pasada | PASS | PASS: baja permitida |
| Base antigua todavía aplicable hoy, nueva base empieza después | PASS | PASS: bloqueo conservado |
| Intervalo restante de base totalmente cubierto por excepciones con otro turno | FAIL: bloqueo incorrecto | PASS: baja permitida |
| Intervalo de base con al menos una semana sin excepción | PASS | PASS: bloqueo conservado |
| Última base vuelve a regir tras excepciones puntuales | PASS | PASS: bloqueo conservado |
| Excepción actual con el turno | PASS | PASS: bloqueo conservado |
| Turno sin programación pero con sesión abierta | PASS | PASS: SHIFT_IN_USE |

Código de salida de regresión antes del cambio: 1 (fallo esperado). Después: 0. Todos los escenarios verifican preservación de las programaciones y detalles y revierten sus fixtures mediante ROLLBACK.

### 8.4. Comandos efectivamente ejecutados

```powershell
# RED: se ejecutó antes de modificar el SQL del trigger.
C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_migracion_sprint3.php amazon_market_sprint3_migration_test_hu18_red_20261112_172321
# exit 0, una conexión: 527.
C:\xampp\php\php.exe database/tercerSprint/tools/verificar_baja_turnos_programacion_sprint3.php --test-db=amazon_market_sprint3_migration_test_hu18_red_20261112_172321
# exit 1: 9 PASS / 2 FAIL esperados.

# GREEN: copia restaurada distinta, con el SQL corregido.
C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_migracion_sprint3.php amazon_market_sprint3_migration_test_hu18_green_20261112_172321
# exit 0, misma conexión 530 y lock 530 en scripts 02..06.
C:\xampp\php\php.exe database/tercerSprint/tools/verificar_baja_turnos_programacion_sprint3.php --test-db=amazon_market_sprint3_migration_test_hu18_green_20261112_172321
# exit 0: 11 PASS / 0 FAIL.
C:\xampp\php\php.exe database/tercerSprint/tools/verificar_conservacion_y_reglas_sprint3.php --test-db=amazon_market_sprint3_migration_test_hu18_green_20261112_172321 --fail-sim-db=amazon_market_sprint3_fail_sim_hu18_20261112_172321
# exit 0: 56/56 PASS; conservación y definiciones de triggers correctas.
C:\xampp\php\php.exe database/tercerSprint/tools/ensayo_concurrencia_apertura_sprint3.php --test-db=amazon_market_sprint3_migration_test_hu18_green_20261112_172321
# exit 0: un COMMITTED y un REJECTED, exactamente una ABIERTA durante la carrera.
```

### 8.5. Conservación independiente después de las pruebas

Una comprobación adicional de solo lectura comparó las 32 tablas originales entre `amazon_market`, la copia de referencia `amazon_market_sprint3_verify_20261112_012430` y la copia GREEN: huellas deterministas calculadas sobre todas las columnas anteriores, IDs y valores previos, con exclusión únicamente de las cinco altas de permisos autorizadas. Resultado: **0 diferencias de contenido y 0 diferencias de AUTO_INCREMENT comerciales**.

Se verificaron los 34 triggers instalados: los 5 originales conservan su definición, y los 29 nuevos coinciden con los scripts actuales (normalizando comentarios y espacios). La copia GREEN conserva 43 tablas, 34 triggers y control `COMPLETADA / 06_COMPLETADA`. Stock total 2497 y 250 ventas anteriores con `id_sesion_caja NULL`.

La base original permanece con 32 tablas y 5 triggers; no se migró ni se alteró. Las bases de ensayos anteriores permanecen intactas. No se cambió PHP de aplicación, frontend, configuración, contrato ni documentos Word. No se ejecutaron APIs ni se inició el paso 6.
