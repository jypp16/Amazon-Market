# Informe de Validación y Evidencia Reproducible — Migraciones Sprint 3 (Paso 5)

**Proyecto:** Amazon Market (`C:\xampp\htdocs\Amazon-Market`)  
**Etapa del plan:** Paso 5 — Preparar y ensayar las migraciones incrementales del Sprint 3 conservando todos los datos anteriores  
**Fecha y hora del ensayo (`America/Lima`, UTC-05:00):** `2026-11-12 13:50:00` – `2026-11-12 14:12:26`  
**Motor y versión:** `10.4.32-MariaDB` (`mariadb.org binary distribution`, `Win64`, motor `InnoDB`)  
**Intérprete PHP CLI:** `PHP 8.2.12 (cli)` (`C:\xampp\php\php.exe`)

---

## 1. Bases de Datos Involucradas y Protección de la Base Original

| Rol de la base de datos | Nombre exacto del esquema | Operaciones ejecutadas en el Paso 5 | Estado final verificado |
| :--- | :--- | :--- | :--- |
| **Base original de la aplicación** | `amazon_market` | **Solo lectura** (`SET SESSION TRANSACTION READ ONLY`) para comparar línea base y conservación. **0 DDL, 0 DML.** | **Intacta** (`32` tablas base, `5` triggers del Sprint 2, checksums idénticos al Paso 3). |
| **Copia de verificación del Paso 3** | `amazon_market_sprint3_verify_20261112_012430` | **Solo lectura** + prueba de rechazo de guardia (`PROTECCION_COPIA_RESERVADA`). **0 DDL, 0 DML.** | **Intacta** (`32` tablas base, `5` triggers del Sprint 2, checksums idénticos). |
| **Primer entorno de ensayo (histórico conservado sin borrar)** | `amazon_market_sprint3_migration_test_20261112_140017` | Creada y restaurada desde el respaldo verificado; permitió detectar en el paso `06` el nombre real de la columna `permiso.grupo` y el conteo de `rol_permiso` para `id_rol = 1`. No se borró ni se parcheó silenciosamente. | Conservada para auditoría. |
| **Base de ensayo definitiva validada de punta a punta** | `amazon_market_sprint3_migration_test_20261112_140630` | Creada desde cero tras verificar inexistencia previa, restaurada sin errores desde el respaldo verificado y migrada limpiamente de `01` a `06` en una sola pasada (`EXIT_CODE = 0` en todos los pasos). | **Completada y validada** (`43` tablas, `34` triggers, `100 %` de datos previos conservados). |

---

## 2. Verificación del Respaldo y Vigencia de la Línea Base

### 2.1. Comprobación del archivo de respaldo del Paso 3
- **Ruta:** `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql`
- **Existencia:** `True` (`BACKUP_EXISTS=True`)
- **Tamaño:** `467692` bytes (coincide exactamente con los `467,692` bytes registrados en el Paso 3)
- **Hash SHA-256 calculado:** `7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D` (coincide bit a bit con el hash registrado en el Paso 3)
- **Inspección previa a la restauración:**
  - Directivas `USE` o `CREATE DATABASE` en el archivo: **`0`** (`USE_OR_CREATEDB_MATCHES=0`)
  - Referencias calificadas a `amazon_market.` en tablas o triggers: **`0`** (`QUALIFIED_DB_MATCHES=0`)

### 2.2. Comprobación de vigencia de `amazon_market` respecto al respaldo del Paso 3
Antes de iniciar el ensayo se compararon las `32` tablas de `amazon_market` frente a `amazon_market_sprint3_verify_20261112_012430` mediante `COUNT(*)`, `AUTO_INCREMENT` y `CHECKSUM TABLE ... EXTENDED`:
- **Resultado:** **`0` diferencias** en las 32 tablas (`TABLE_DIFFS_COUNT=0`). No hubo operaciones nuevas desde el Paso 3, por lo que el respaldo verificado `amazon_market_sprint3_pre_step3_20261112_012312.sql` representa con exactitud el estado actual de `amazon_market`.

---

## 3. Comandos Ejecutados y Códigos de Salida

### 3.1. Creación y restauración limpia de `amazon_market_sprint3_migration_test_20261112_140630`
```powershell
$testDb = "amazon_market_sprint3_migration_test_20261112_140630"
$backupPath = "C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql"

C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$testDb';"
# Salida: 0 | Código de salida: 0

C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "CREATE DATABASE ``$testDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# Código de salida: 0

C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 --abort-source-on-error $testDb -e "SOURCE $backupPath;"
# Código de salida: 0 (32 tablas base y 5 triggers restaurados sin errores)
```

### 3.2. Ejecución ordenada de las migraciones (`01` a `06`)
```powershell
$testDb = "amazon_market_sprint3_migration_test_20261112_140630"
$dir = "c:/xampp/htdocs/Amazon-Market/database/tercerSprint"
$bkpFile = "C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql"
$bkpSha = "7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D"
```

| Paso | Script ejecutado | Código de salida | Estado registrado en `sprint3_migracion_control` |
| :-: | :--- | :-: | :--- |
| **01** | [01_preflight_y_control_sprint3.sql](01_preflight_y_control_sprint3.sql) | `0` | `EN_PROGRESO \| 01_PREFLIGHT \| EN_CURSO` |
| **02** | [02_estructura_turnos_y_programacion_sprint3.sql](02_estructura_turnos_y_programacion_sprint3.sql) | `0` | `EN_PROGRESO \| 02_TURNOS_Y_PROGRAMACION \| EN_CURSO` |
| **03** | [03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql](03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql) | `0` | `EN_PROGRESO \| 03_SESIONES_ARQUEOS_AUTORIZACIONES \| EN_CURSO` |
| **04** | [04_estructura_movimientos_correcciones_y_ventas_sprint3.sql](04_estructura_movimientos_correcciones_y_ventas_sprint3.sql) | `0` | `EN_PROGRESO \| 04_MOVIMIENTOS_CORRECCIONES_VENTAS \| EN_CURSO` |
| **05** | [05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql](05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql) | `0` | `EN_PROGRESO \| 05_IDEMPOTENCIA_Y_REPORTES \| EN_CURSO` |
| **06** | [06_configuracion_permisos_y_cierre_migracion_sprint3.sql](06_configuracion_permisos_y_cierre_migracion_sprint3.sql) | `0` | `COMPLETADA \| 06_COMPLETADA \| 2026-11-12 14:06:15` |

### 3.3. Prueba de reejecución controlada (`01` a `06` sobre base ya migrada)
Al volver a invocar cada uno de los 6 scripts sobre `amazon_market_sprint3_migration_test_20261112_140630`:
- Todos los scripts (`01` a `06`) terminaron con código de salida `1` y mensaje controlado:
  `ERROR 1644 (45000): SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base.`
- Conteos tras el intento de reejecución: `permiso = 36` (sin duplicados), `rol_permiso = 35` (sin duplicados).

---

## 4. Resumen de Objetos Creados y Modificados en la Base de Ensayo

### 4.1. Tablas nuevas creadas (`11` tablas, todas `InnoDB`, `utf8mb4_unicode_ci`)
1. `sprint3_migracion_control`: Registro de auditoría y bloqueo de la migración del Sprint 3 (`id_migracion = 3`).
2. `turno`: Catálogo de turnos con columna generada persistida `nombre_activo_normalizado` e índice `UNIQUE uq_turno_nombre_activo`, `chk_turno_horario` (`hora_fin > hora_inicio`, sin segundos) y baja lógica (`estado IN (0, 1)`).
3. `programacion_semanal`: Cabecera versionada por vendedor (`id_usuario_vendedor`), lunes (`WEEKDAY(semana_inicio) = 0`), alcance (`'desde'` / `'semana'`), versión e indicador de vigencia (`uq_prog_vendedor_semana_alcance_vigente`).
4. `programacion_semanal_detalle`: Detalle de turnos asignados por día (`dia_semana BETWEEN 0 AND 6`) con clave única `uq_prog_detalle_version_turno_dia (id_programacion, id_turno, dia_semana)`, que permite asignar varios turnos distintos al mismo vendedor en un mismo día.
5. `caja_fisica_guardia`: Tabla ancla de fila única (`id_caja_fisica = 1`, `codigo_caja = 'CAJA_UNICA_PRINCIPAL'`) que permite serializar aperturas concurrentes mediante `SELECT ... FOR UPDATE` incluso cuando `sesion_caja` aún está vacía.
6. `sesion_caja`: Sesión operativa con dueño (`id_usuario_vendedor`), snapshot de turno y horario, `fecha_hora_salida_referencia`, referencia nullable al cierre anterior (`id_sesion_cierre_anterior`), `saldo_inicial`, `diferencia_apertura` nullable, acumulados por medio de pago, `version_caja`, `id_ultimo_arqueo`, `id_arqueo_cierre`, importes congelados de cierre y columna generada persistida `guardia_abierta_unica TINYINT(4) AS (IF(estado = 'ABIERTA', 1, NULL)) PERSISTENT` con `UNIQUE KEY uq_sesion_caja_unica_abierta`.
7. `arqueo_caja`: Historial de conteos físicos con `version_caja_evaluada`, `monto_esperado`, `monto_contado`, `diferencia`, `observacion` condicional (`chk_arqueo_observacion_condicional`), `estado ENUM('VIGENTE','REEMPLAZADO','DESACTUALIZADO')`, `causa_cambio_estado` e índice `uq_arqueo_caja_unico_vigente_sesion (id_sesion_caja, vigencia_activa)`.
8. `autorizacion_caja`: Registro de autorización administrativa de cierre anticipado (`tipo_operacion = 'CIERRE_ANTICIPADO'`, `minutos_anticipacion > 15`, `uq_autorizacion_sesion_unica`), sin almacenar contraseñas ni hashes secretos.
9. `movimiento_caja`: Registro inmutable de movimientos con los 7 tipos exactos del contrato (`VENTA_EFECTIVO`, `INGRESO_MANUAL`, `INYECCION_ADMIN`, `SALIDA_CAJA`, `RETIRO_ADMIN`, `RETIRO_OMITIDO`, `CORRECCION`), `uq_movimiento_caja_venta (id_venta)` y las dos declaraciones obligatorias para `RETIRO_OMITIDO`.
10. `correccion_movimiento_caja`: Historial encadenado de correcciones vinculado a `id_movimiento_original`, a `id_movimiento_correccion` (`UNIQUE`) y a `id_correccion_previa` (`UNIQUE`), con `uq_correccion_original_eslabon (id_movimiento_original, numero_eslabon)`.
11. `reporte_caja_snapshot_item`: Ítems congelados de snapshots del reporte `'cierres_caja'` (`PRIMARY KEY (id_snapshot, orden_posicion)`, `UNIQUE (id_snapshot, id_sesion_caja)`, `CHECK (json_valid(proyeccion_json))`).

### 4.2. Tablas existentes ampliadas sin pérdida de datos (`3` tablas DDL + `2` tablas DML de permisos)
- `venta`: Se añadió `id_sesion_caja INT(11) DEFAULT NULL` después de `id_usuario`, índice `idx_venta_sesion_caja` y FK `fk_venta_sesion_caja -> sesion_caja(id_sesion_caja)`. Las `250` ventas históricas permanecen con `id_sesion_caja = NULL`.
- `control_solicitud_idempotencia`: Se amplió `tipo_operacion` de `7` a `17` valores `ENUM`, conservando intactas las `405` filas existentes.
- `reporte_consulta_snapshot`: Se amplió `tipo_reporte` añadiendo `'cierres_caja'` (`ENUM('kardex','variaciones','cierres_caja')`), conservando intactas las `2` cabeceras existentes y los `150` ítems de `reporte_consulta_snapshot_item`.
- `permiso` y `rol_permiso`: Se insertaron los `5` nuevos permisos (`id_permiso` `32..36`) y sus `5` asignaciones en `rol_permiso` (`30 -> 35`), conservando la colación `utf8_general_ci` y todas las filas previas.

---

## 5. Comparación Antes / Después de las 32 Tablas Existentes (Conservación del 100 % de los Datos)

La herramienta [verificar_conservacion_y_reglas_sprint3.php](tools/verificar_conservacion_y_reglas_sprint3.php) comprobó en `amazon_market_sprint3_migration_test_20261112_140630` frente a `amazon_market` y `amazon_market_sprint3_verify_20261112_012430` el conteo de filas, el valor `AUTO_INCREMENT`, el `CHECKSUM TABLE ... EXTENDED` y la huella `SHA-256` canónica de todas las filas previas (`conservation_all_passed = true`):

| # | Tabla | Filas antes (`amazon_market`) | Filas después (`test_db`) | `AUTO_INCREMENT` antes / después | `CHECKSUM EXTENDED` antes / después | SHA-256 filas previas idéntico | Estado |
| --: | :--- | --: | --: | :---: | :---: | :-: | :-: |
| 1 | `categoria` | 6 | 6 | `7` / `7` | `2040417811` / `2040417811` | Sí | **OK** |
| 2 | `cliente` | 14 | 14 | `17` / `17` | `1536613691` / `1536613691` | Sí | **OK** |
| 3 | `compra` | 80 | 80 | `86` / `86` | `3958917168` / `3958917168` | Sí | **OK** |
| 4 | `compra_historial` | 0 | 0 | `4` / `4` | `0` / `0` | Sí | **OK** |
| 5 | `control_solicitud_idempotencia` | 405 | 405 | `406` / `406` | `3288811157` / `3288811157` | Sí | **OK** |
| 6 | `detalle_compra` | 100 | 100 | `116` / `116` | `3707943484` / `3707943484` | Sí | **OK** |
| 7 | `detalle_venta` | 295 | 295 | `1036` / `1036` | `3129771996` / `3129771996` | Sí | **OK** |
| 8 | `kardex` | 476 | 476 | `503` / `503` | `3838626397` / `3838626397` | Sí | **OK** |
| 9 | `metodo_pago` | 3 | 3 | `4` / `4` | `597059173` / `597059173` | Sí | **OK** |
| 10 | `operacion_inventario` | 75 | 75 | `76` / `76` | `677274208` / `677274208` | Sí | **OK** |
| 11 | `permiso` | 31 | **36** *(+5 nuevos)* | `32` / **`37`** | `4239660521` / `460866006` *(esperado)* | Sí (`id <= 31`) | **OK** |
| 12 | `presentacion` | 3 | 3 | `4` / `4` | `2040548292` / `2040548292` | Sí | **OK** |
| 13 | `producto` | 108 | 108 | `109` / `109` | `1063322974` / `1063322974` | Sí | **OK** |
| 14 | `proveedor` | 3 | 3 | `4` / `4` | `3513999074` / `3513999074` | Sí | **OK** |
| 15 | `reporte_consulta_snapshot` | 2 | 2 | `30` / `30` | `2588450539` / `2588450539` | Sí | **OK** |
| 16 | `reporte_consulta_snapshot_item` | 150 | 150 | `NULL` / `NULL` | `1758665564` / `1758665564` | Sí | **OK** |
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

### Verificaciones específicas adicionales de conservación
- **Existencias de productos (`producto`):** `108` productos intactos, `SUM(stock_actual) = 2497` antes y después (`CHECKSUM EXTENDED = 1063322974` idéntico).
- **Correlativos (`serie_comprobante`):** `id_serie = 1` (`B001`, `correlativo_actual = 318`, `estado = 1`) e `id_serie = 2` (`F001`, `correlativo_actual = 30`, `estado = 1`) idénticos.
- **Ventas históricas (`venta`):** `250` ventas con `id_sesion_caja IS NULL` (`0` ventas con sesión inventada; `SUM(total) = 4094.43` intacto).
- **Permisos nuevos insertados en `permiso` (`id_permiso` `32..36`):**
  - `32`: `turnos.movimientos` (`grupo = 'Turnos'`, `estado = 1`) -> asignado a `id_rol = 2` (Vendedor)
  - `33`: `turnos.arqueo` (`grupo = 'Turnos'`, `estado = 1`) -> asignado a `id_rol = 2` (Vendedor)
  - `34`: `turnos.programar` (`grupo = 'Turnos'`, `estado = 1`) -> asignado a `id_rol = 1` (Administrador)
  - `35`: `turnos.consultar_historico` (`grupo = 'Turnos'`, `estado = 1`) -> asignado a `id_rol = 1` (Administrador)
  - `36`: `reportes.cierres_caja` (`grupo = 'Reportes'`, `estado = 1`) -> asignado a `id_rol = 1` (Administrador)

---

## 6. Resultados de las Pruebas Positivas, Negativas y de Concurrencia

### 6.1. Batería de 25 pruebas positivas y negativas por dominio (`DOMAIN_CASES_ALL_PASSED = True`)

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
| `C2_PRIMERA_APERTURA_SIN_CIERRE_ANTERIOR` | Positiva | Abrir primera sesión histórica con `tiene_cierre_anterior = 0`, `id_sesion_cierre_anterior = NULL` y `diferencia_apertura = NULL` | **PASS** | `idSesion1=1 ABIERTA con saldo_inicial=100.00` |
| `C3_SEGUNDA_SESION_ABIERTA_RECHAZADA` | Negativa | Rechazar segunda sesión `ABIERTA` simultánea mediante guardia única | **PASS** | `1062 Duplicate entry '1' for key 'uq_sesion_caja_unica_abierta'` |
| `D1_MOVIMIENTOS_INGRESO_EGRESO` | Positiva | Registrar `INGRESO_MANUAL` (`+50.00`), `INYECCION_ADMIN` (`+30.00`) y `SALIDA_CAJA` (`-20.00`) | **PASS** | `idMovIngreso=1; efectivo_esperado_actual=160.00` |
| `D2_INYECCION_ADMIN_SIN_ADMIN` | Negativa | Rechazar `INYECCION_ADMIN` sin `id_usuario_admin` | **PASS** | `1644 ADMIN_AUTHORIZATION_REQUIRED` |
| `D3_MOVIMIENTO_UPDATE_PROHIBIDO` | Negativa | Rechazar edición directa (`UPDATE`) sobre `movimiento_caja` original | **PASS** | `1644 MOVIMIENTO_CAJA_INMUTABLE` |
| `E1_CORRECCIONES_ENCADENADAS_SIN_MUTAR_ORIGINAL` | Positiva | Registrar 2 correcciones encadenadas (`50.00 -> 65.00 -> 45.00`) verificando que el original conserva `50.00` y el efecto neto se contabiliza una sola vez | **PASS** | `Original intacto=50.00, current_amount derivado=45.00, efectivo_esperado_actual=155.00` |
| `F1_ARQUEO_DIFERENCIA_SIN_OBSERVACION` | Negativa | Rechazar arqueo con `diferencia <> 0.00` y `observacion` nula | **PASS** | `4025 CONSTRAINT chk_arqueo_observacion_condicional failed` |
| `F2_ARQUEO_REEMPLAZO_Y_UNICO_VIGENTE` | Positiva | Registrar arqueo #1, reemplazarlo por arqueo #2 conservando historial y único `VIGENTE` por sesión | **PASS** | `Arqueo #1 (1) REEMPLAZADO; Arqueo #2 (2) VIGENTE` |
| `G1_CIERRE_ANTICIPADO_SIN_AUTORIZACION` | Negativa | Rechazar cierre anticipado (`> 15 min` antes de salida de referencia) sin `autorizacion_caja` | **PASS** | `1644 EARLY_CLOSE_AUTH_REQUIRED` |
| `G2_CIERRE_ANTICIPADO_CON_AUTORIZACION_ADMIN` | Positiva | Registrar autorización administrativa (`60 min` antes) y cerrar Sesión #1 congelando importes | **PASS** | `idAutorizacion=1; Sesión #1 CERRADA con contado=145.00` |
| `C4_APERTURA_POSTERIOR_SIN_REFERENCIA_CIERRE` | Negativa | Rechazar apertura con `tiene_cierre_anterior = 0` cuando ya existen sesiones cerradas | **PASS** | `1644 APERTURA_REQUIERE_CIERRE_ANTERIOR` |
| `C5_SEGUNDA_APERTURA_CON_DIFERENCIA_Y_RETIRO_OMITIDO` | Positiva | Abrir Sesión #2 referenciando Sesión #1 (`diferencia_apertura = -20.00`), registrar `RETIRO_OMITIDO` (`efecto_saldo = 0.00`) y cerrar dentro de la ventana de 15 min (`19:50` vs `20:00`) sin autorización | **PASS** | `Sesión #3 abierta con dif=-20.00, RETIRO_OMITIDO=20.00 (efecto=0.00) y cerrada a las 19:50 sin exigir autorización` |
| `I1_SNAPSHOT_CIERRES_CAJA_VALIDO` | Positiva | Crear cabecera en `reporte_consulta_snapshot` (`tipo_reporte = 'cierres_caja'`) e ítem congelado en `reporte_caja_snapshot_item` | **PASS** | `idSnapshotCaja=30 con ítem para sesión #1` |
| `I2_SNAPSHOT_CAJA_JSON_INVALIDO` | Negativa | Rechazar ítem en `reporte_caja_snapshot_item` con `proyeccion_json` inválido | **PASS** | `4025 CONSTRAINT chk_reporte_caja_item_orden_y_json failed` |

### 6.2. Prueba de concurrencia real (2 procesos paralelos intentando abrir sesión simultáneamente)
Ejecutada mediante [ensayo_concurrencia_apertura_sprint3.php](tools/ensayo_concurrencia_apertura_sprint3.php) (`--test-db=amazon_market_sprint3_migration_test_20261112_140630`, código de salida `0`):
```json
{
    "test_database": "amazon_market_sprint3_migration_test_20261112_140630",
    "passed": true,
    "open_sessions_after_race": 1,
    "guard_row_after_race": {
        "id_caja_fisica": 1,
        "id_sesion_abierta_actual": 1,
        "id_ultima_sesion_cerrada": null
    },
    "worker_1": {
        "worker": 1,
        "status": "COMMITTED",
        "id_sesion_caja": 1,
        "guard_seen_open_before": null,
        "elapsed_ms": 76.79
    },
    "worker_2": {
        "worker": 2,
        "status": "REJECTED",
        "sqlstate": "23000",
        "error": "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'uq_sesion_caja_unica_abierta'",
        "elapsed_ms": 150.14
    }
}
```

---

## 7. Incidencias Encontradas Durante el Ensayo y Resolución Aplicada

1. **Columna `grupo` (no `modulo`) en `permiso` y conteo de `rol_permiso` para `id_rol = 1`:**
   - Durante el primer ensayo sobre `amazon_market_sprint3_migration_test_20261112_140017`, los scripts `01` a `05` finalizaron con código `0`, pero el paso `06` hizo `ROLLBACK` transaccional automático primero porque la tabla `permiso` nombra a la columna clasificadora `grupo` (no `modulo`) y luego porque en la línea base `turnos.acceder` (`id_permiso = 17`) está asignado en `rol_permiso` únicamente a `id_rol = 2` (mientras que `id_rol = 1` recibe los 3 nuevos permisos administrativos además de `reportes.exportar`).
   - **Resolución:** Se corrigió `06_configuracion_permisos_y_cierre_migracion_sprint3.sql` para insertar en `permiso.grupo` y validar las 6 asignaciones de `id_rol = 2` y las 4 asignaciones específicas de `id_rol = 1`. Conforme a las reglas del Paso 5, **no se borró ni se ocultó** `amazon_market_sprint3_migration_test_20261112_140017`: se creó una nueva base limpia `amazon_market_sprint3_migration_test_20261112_140630`, se restauró en ella el respaldo verificado desde cero y se ejecutó la secuencia completa `01` a `06` sin ningún error intermedio.
2. **Restricción de MariaDB 10.4.32 sobre columnas con `FOREIGN KEY` en expresiones `CHECK` o `GENERATED ALWAYS`:**
   - En MariaDB 10.4 (`InnoDB`), referenciar una columna con `FOREIGN KEY` dentro de un `CHECK (...)` o una columna virtual/persistida produce `errno: 150` o error `1901`.
   - **Resolución:** Todas las columnas generadas (`nombre_activo_normalizado`, `guardia_abierta_unica`) y `CHECK` de tabla referencian exclusivamente columnas no foráneas (`nombre`, `estado`, `es_vigente`, `vigencia_activa`, importes y fechas), mientras que las invariantes relacionales sobre claves foráneas (`id_sesion_cierre_anterior`, `id_ultimo_arqueo`, `id_arqueo_cierre`, `id_venta`, `id_sesion_origen_omitido`, `id_movimiento_original`, `id_correccion_previa`) se validan en triggers `BEFORE INSERT` y `BEFORE UPDATE`.

---

## 8. Limitaciones Reales Pendientes para los Pasos de Servicios (Pasos 6 a 12)

1. **`usuario.email IS NULL` en el administrador `id_usuario = 1`:**
   - Tal como se documentó en el Paso 3 y prohíbe el Paso 5 ("No completar correos administrativos ni cambiar contraseñas"), las 6 filas de `usuario` permanecen intactas. En consecuencia, mientras `id_usuario = 1` mantenga `email = NULL`, solo los administradores activos con correo configurado (como `id_usuario = 9`) o aquellos a quienes un administrador configure su correo por la vía funcional normal podrán autenticarse por `admin_email` al autorizar cierres anticipados en el Paso 8.
2. **Exigencia de sesión abierta para nuevas ventas POS (`Paso 9`):**
   - La columna `venta.id_sesion_caja` es `NULL` a nivel de esquema para conservar las 250 ventas históricas sin inventar sesiones ficticias. La exigencia de sesión `ABIERTA` propiedad del vendedor autenticado para toda nueva venta se aplicará en `VentaService::procesarVenta()` durante el Paso 9.
3. **Separación de rutas y bypass general de `id_rol = 1` en `RBACMiddleware` (`Paso 6`):**
   - En `rol_permiso` ya están separados los permisos operativos de Vendedor (`id_rol = 2`) y los administrativos de Administrador (`id_rol = 1`). En el Paso 6 se ajustará `RBACMiddleware` y `ApiController` para que el bypass general del rol `1` no habilite a un Administrador a operar endpoints exclusivos de Vendedor.
