# Migraciones Incrementales de Base de Datos — Sprint 3 (Paso 5)

La corrección adicional de HU 18 y su último ensayo están documentados en la sección 8 de [VALIDACION.md](VALIDACION.md). Las bases de ensayos anteriores se conservan como evidencia y no se actualizan en sitio.

**Proyecto:** Amazon Market (`C:\xampp\htdocs\Amazon-Market`)  
**Motor objetivo:** MariaDB `10.4.32-MariaDB` (`InnoDB`, `utf8mb4_unicode_ci`)  
**Documentos rectores:**
- [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](../../ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md)
- [MATRIZ_PERMISOS_SPRINT_3.md](../../MATRIZ_PERMISOS_SPRINT_3.md)
- [INSPECCION_Y_RESPALDO_BD_SPRINT_3.md](../../INSPECCION_Y_RESPALDO_BD_SPRINT_3.md)
- [CONTRATO_API_SPRINT_3.md](../../CONTRATO_API_SPRINT_3.md) (Secciones 5, 7, 8, 11 y 13)

---

> [!IMPORTANT]
> **En este Paso 5 NO se migra la base de datos original `amazon_market`.**  
> Todos los scripts SQL y herramientas auxiliares de esta carpeta incluyen guardias activas que rechazan explícitamente ejecutarse sobre `amazon_market` y sobre la copia de verificación del Paso 3 (`amazon_market_sprint3_verify_20261112_012430`). El ensayo y la validación del Paso 5 se ejecutan exclusivamente sobre una base de datos de ensayo separada restaurada desde el respaldo verificado.

---

## 1. Orden Exacto de Ejecución y Contenido de la Carpeta

Los 6 scripts deben ejecutarse **estrictamente en orden numérico secuencial (`01` al `06`) dentro de una única sesión de conexión** mediante el ejecutor oficial [ejecutar_migracion_sprint3.php](tools/ejecutar_migracion_sprint3.php).

Cada script:
1. Activa modo SQL estricto (`STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`).
2. Comprueba que la misma conexión mantiene vigente el candado de sesión `GET_LOCK` adquirido en el paso `01` (`COALESCE(IS_USED_LOCK(v_lock_name), -1) = CONNECTION_ID()`).
3. Verifica en `sprint3_migracion_control` que el paso inmediato anterior finalizó por completo.
4. Avanza `sprint3_migracion_control.paso_actual` **únicamente al final del archivo**, después de crear y verificar en `information_schema` todas las tablas, columnas, claves foráneas y triggers de ese paso.

| Orden | Archivo | Propósito y objetos afectados |
| :-: | :--- | :--- |
| **01** | [01_preflight_y_control_sprint3.sql](01_preflight_y_control_sprint3.sql) | **Preflight y control de migración:** Valida la base destino, rechaza `amazon_market` y copias reservadas, comprueba las 32 tablas base `InnoDB`, los 5 triggers del Sprint 2, los roles activos (`1`, `2`), los 5 permisos reutilizados, los 3 métodos de pago y los valores actuales de `ENUM`. Adquiere bloqueo `GET_LOCK` en la sesión activa, crea `sprint3_migracion_control` en `00_INICIANDO`, registra la foto base (`conteos_base_json`) y, tras verificar la tabla de control, avanza `paso_actual = '01_PREFLIGHT'`. |
| **02** | [02_estructura_turnos_y_programacion_sprint3.sql](02_estructura_turnos_y_programacion_sprint3.sql) | **Dominios A y B (Turnos y programación semanal versionada):** Verifica titularidad de `GET_LOCK` y `paso_actual = '01_PREFLIGHT'`. Crea `turno` (con columna generada persistida `nombre_activo_normalizado` e índice `uq_turno_nombre_activo`), `programacion_semanal` y `programacion_semanal_detalle` (con unicidad `(id_programacion, id_turno, dia_semana)` que permite múltiples turnos el mismo día y preserva excepciones semanales ante nuevas bases permanentes conforme a la Sección 13.1 del contrato), más 7 triggers. Verifica en `information_schema` las 3 tablas, 10 FKs y 7 triggers antes de avanzar `paso_actual = '02_TURNOS_Y_PROGRAMACION'`. |
| **03** | [03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql](03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql) | **Dominios C, F y G (Sesiones de caja, arqueos y autorizaciones):** Verifica titularidad de `GET_LOCK` y `paso_actual = '02_TURNOS_Y_PROGRAMACION'`. Crea `caja_fisica_guardia` (fila única `id_caja_fisica = 1`), `sesion_caja` (con `observacion_cierre VARCHAR(500)`, `guardia_abierta_unica` e índice `uq_sesion_caja_unica_abierta`), `arqueo_caja` (con `observacion VARCHAR(500)`, unicidad de arqueo `VIGENTE` por sesión y observación obligatoria de `1..500` caracteres cuando `diferencia <> 0.00`) y `autorizacion_caja` (`motivo VARCHAR(250)`, sin almacenar secretos), más 12 triggers. Valida en cierre que el arqueo `VIGENTE` coincida con `OLD.version_caja` y que `NEW.version_caja = OLD.version_caja + 1` (`EP-20`), congela las 36 columnas de `sesion_caja` al estar `CERRADA` mediante comparaciones NULL-safe (`<=>`), y verifica las 4 tablas, 17 FKs y 12 triggers antes de avanzar `paso_actual = '03_SESIONES_ARQUEOS_AUTORIZACIONES'`. |
| **04** | [04_estructura_movimientos_correcciones_y_ventas_sprint3.sql](04_estructura_movimientos_correcciones_y_ventas_sprint3.sql) | **Dominios H, D y E (Ventas, movimientos y correcciones encadenadas):** Verifica titularidad de `GET_LOCK` y `paso_actual = '03_SESIONES_ARQUEOS_AUTORIZACIONES'`. Añade `id_sesion_caja INT(11) DEFAULT NULL` e índice/FK en `venta` (conservando las 250 ventas históricas en `NULL`), crea `movimiento_caja` con los 7 tipos exactos del contrato (`motivo VARCHAR(250)`) y crea `correccion_movimiento_caja` enlazada al movimiento original, al movimiento compensatorio (`UNIQUE`) y al eslabón previo (`UNIQUE`) sin FK circulares, más 6 triggers. Verifica la columna, 2 tablas, 14 FKs y 6 triggers antes de avanzar `paso_actual = '04_MOVIMIENTOS_CORRECCIONES_VENTAS'`. |
| **05** | [05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql](05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql) | **Dominios J e I (Idempotencia y snapshots del reporte de cierres de caja):** Verifica titularidad de `GET_LOCK` y `paso_actual = '04_MOVIMIENTOS_CORRECCIONES_VENTAS'`. Amplía `control_solicitud_idempotencia.tipo_operacion` con los 10 nuevos códigos del Sprint 3 (conservando los 7 previos y las 405 filas históricas), amplía `reporte_consulta_snapshot.tipo_reporte` añadiendo `'cierres_caja'` y crea `reporte_caja_snapshot_item` separada de `reporte_consulta_snapshot_item`, más 4 triggers. Verifica la tabla, 2 FKs, 2 ENUMs y 4 triggers antes de avanzar `paso_actual = '05_IDEMPOTENCIA_Y_REPORTES'`. |
| **06** | [06_configuracion_permisos_y_cierre_migracion_sprint3.sql](06_configuracion_permisos_y_cierre_migracion_sprint3.sql) | **Dominio K y cierre transaccional:** Verifica titularidad de `GET_LOCK` y `paso_actual = '05_IDEMPOTENCIA_Y_REPORTES'`. Inserta por `slug` los 5 nuevos permisos del Sprint 3 (`turnos.movimientos`, `turnos.arqueo`, `turnos.programar`, `turnos.consultar_historico`, `reportes.cierres_caja`), asigna en `rol_permiso` por `slug` (2 a Vendedor `id_rol = 2` y 3 a Administrador `id_rol = 1`), valida la conservación del 100 % de los conteos y sumas de la línea base, verifica que existan exactamente 43 tablas y 34 triggers, marca `sprint3_migracion_control.estado = 'COMPLETADA'` (`paso_actual = '06_COMPLETADA'`) y libera el lock `GET_LOCK` de la sesión. |

### Herramientas oficiales (`database/tercerSprint/tools/`)
- [verificar_baja_turnos_programacion_sprint3.php](tools/verificar_baja_turnos_programacion_sprint3.php): 11 regresiones adicionales de HU 18. Cada escenario usa fixtures nuevos en una transacción que revierte; no borra datos ni desactiva triggers. Ejecutar sobre una copia nueva, sin programación ni sesión abierta de prueba.
- [ejecutar_migracion_sprint3.php](tools/ejecutar_migracion_sprint3.php): **Ejecutor oficial de sesión única.** Ejecuta los scripts `01` a `06` dentro de un único proceso y conexión `mysql.exe --abort-source-on-error`, manteniendo activo el mismo `CONNECTION_ID()` y el bloqueo `GET_LOCK` durante toda la corrida, y deteniendo de inmediato la ejecución ante el primer error sin ejecutar los scripts siguientes.
- [verificar_conservacion_y_reglas_sprint3.php](tools/verificar_conservacion_y_reglas_sprint3.php): Compara conteos, `AUTO_INCREMENT`, `CHECKSUM TABLE ... EXTENDED` y hashes SHA-256 canónicos de las 32 tablas previas contra `amazon_market` y `amazon_market_sprint3_verify_20261112_012430`, verifica la huella SHA-256 de los 34 triggers antes y después de limpiar fixtures, y ejecuta 56 pruebas positivas y negativas (incluyendo las pruebas obligatorias de las Correcciones 1, 2, 3, 4 y 5).
- [ensayo_concurrencia_apertura_sprint3.php](tools/ensayo_concurrencia_apertura_sprint3.php) y [worker_apertura_concurrente.php](tools/worker_apertura_concurrente.php): Orquestan 2 procesos PHP/MySQL paralelos sincronizados por barrera temporal para demostrar en tiempo real que solo una sesión de caja puede quedar `ABIERTA`.

---

## 2. Parámetros de Sesión Obligatorios y Procedimiento Único de Ejecución

Ningún script se ejecutará si no se declaran explícitamente en la sesión SQL las variables de confirmación de destino y trazabilidad del respaldo, ni si los scripts `02` a `06` se invocan fuera de la conexión que adquirió `GET_LOCK` en el paso `01`:

| Variable de sesión / Candado | Valor requerido en el ensayo del Paso 5 | Descripción |
| :--- | :--- | :--- |
| `@SPRINT3_BASE_CONFIRMADA` | Nombre exacto de la base de ensayo (p. ej. `'amazon_market_sprint3_migration_test_20261112_151230'`) | Debe coincidir carácter por carácter (`BINARY`) con `DATABASE()`. Si difiere o si `DATABASE()` es `amazon_market`, el script aborta con `SQLSTATE '45000'`. |
| `@SPRINT3_RESPALDO_ARCHIVO` | `'C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql'` | Ruta del respaldo verificado desde el cual se restauró la base de ensayo. |
| `@SPRINT3_RESPALDO_SHA256` | `'7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D'` | Hash SHA-256 de 64 caracteres hexadecimales del respaldo verificado. |
| `GET_LOCK('am_sprint3_mig_' + MD5(DATABASE()), 0)` | Adquirido en `01` y verificado con `IS_USED_LOCK(...) = CONNECTION_ID()` en `02`..`06` | Garantiza que toda la secuencia `01`..`06` corre en una única conexión y bloquea ejecuciones concurrentes (`MIGRACION_EN_CURSO` / `LOCK_MIGRACION_NO_VIGENTE`). |

### Procedimiento paso a paso para crear la base de ensayo y ejecutar la migración en una sola conexión

1. **Verificar que la nueva base de ensayo NO existe antes de crearla:**
   ```powershell
   $testDb = "amazon_market_sprint3_migration_test_20261112_151230"
   C:\xampp\mysql\bin\mysql.exe -u root -h 127.0.0.1 -P 3306 --default-character-set=utf8mb4 -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$testDb';"
   ```
2. **Crear la base de ensayo y restaurar el respaldo verificado sin `--force`:**
   ```powershell
   $backup = "C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql"
   C:\xampp\mysql\bin\mysql.exe -u root -h 127.0.0.1 -P 3306 --default-character-set=utf8mb4 -e "CREATE DATABASE ``$testDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   cmd.exe /c "C:\xampp\mysql\bin\mysql.exe -u root -h 127.0.0.1 -P 3306 --default-character-set=utf8mb4 $testDb < `"$backup`""
   ```
3. **Ejecutar los 6 scripts en una única sesión con detención automática al primer error:**
   ```powershell
   C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_migracion_sprint3.php $testDb
   ```
4. **Ejecutar la batería integral de verificación de conservación y reglas de dominio:**
   ```powershell
   C:\xampp\php\php.exe database/tercerSprint/tools/verificar_conservacion_y_reglas_sprint3.php --test-db=$testDb
   ```

> [!WARNING]
> **No ejecutes los scripts `01` a `06` en invocaciones separadas de `mysql.exe`.**  
> Al cerrar el proceso cliente del paso `01`, MariaDB libera el bloqueo de sesión `GET_LOCK`, por lo que los pasos `02` a `06` rechazarán ejecutarse con `LOCK_MIGRACION_NO_VIGENTE`. Usa siempre `ejecutar_migracion_sprint3.php` (o un único proceso `mysql.exe --abort-source-on-error` que encadene los `SOURCE` en la misma conexión).

---

## 3. Detección de Reejecución y Recuperación ante Error Parcial

1. **Detección de migración ya aplicada (`SPRINT3_YA_APLICADA`):**
   - Si `sprint3_migracion_control` tiene `estado = 'COMPLETADA'`, cualquiera de los scripts `01` a `06` aborta de inmediato con `SQLSTATE '45000'` (`SPRINT3_YA_APLICADA`) antes de ejecutar DDL o DML. No se duplican permisos en `permiso` ni asignaciones en `rol_permiso`.
2. **Actualización de `paso_actual` al final de cada archivo y detención inmediata:**
   - En los scripts `01` a `05`, `sprint3_migracion_control.paso_actual` solo se actualiza en el bloque final del archivo después de crear todos los triggers y comprobar en `information_schema` que las tablas, FKs y triggers del paso existen.
   - Si ocurre un fallo antes de finalizar los triggers de un paso, `ejecutar_migracion_sprint3.php` aborta en el acto (`--abort-source-on-error`), no ejecuta los scripts siguientes y `paso_actual` permanece en el paso anterior.
3. **Comportamiento transaccional en MariaDB y qué hacer ante un error parcial:**
   - En MariaDB 10.4, las sentencias DDL (`CREATE TABLE`, `ALTER TABLE`, `CREATE TRIGGER`) provocan confirmación implícita (`implicit commit`) y **no** pueden revertirse mediante `ROLLBACK`.
   - En cambio, el script `06_configuracion_permisos_y_cierre_migracion_sprint3.sql` contiene exclusivamente operaciones DML (`INSERT` / `UPDATE`) dentro de un bloque `START TRANSACTION` con `EXIT HANDLER FOR SQLEXCEPTION` que ejecuta `ROLLBACK` y libera el bloqueo `GET_LOCK` si pertenece a la conexión actual.
   - **Regla operativa ante un fallo intermedio en los pasos `01` a `05`:** No parches manualmente ni reejecutes a ciegas sobre una base de ensayo con estado parcial. Crea una nueva base de ensayo con nombre único, restaura en ella el respaldo verificado desde cero y ejecuta nuevamente `ejecutar_migracion_sprint3.php`.

---

## 4. Delimitación de Responsabilidades: Base de Datos vs. Capa de Servicios (Pasos 6 a 12)

Una clave foránea (`FOREIGN KEY`) o restricción `CHECK` garantiza integridad estructural y relacional, pero **no sustituye** los controles de negocio, autenticación o autorización del backend:

| Regla / Invariante | Garantizado en Base de Datos (Paso 5) | Garantizado en Servicios / Middleware PHP (Pasos 6 a 12) |
| :--- | :--- | :--- |
| **Unicidad de sesión abierta en la única caja física** | Tabla `caja_fisica_guardia` (`id_caja_fisica = 1`) + columna generada persistida `sesion_caja.guardia_abierta_unica` (`IF(estado = 'ABIERTA', 1, NULL)`) con índice `UNIQUE uq_sesion_caja_unica_abierta`. | Servicio de apertura inicia transacción, ejecuta `SELECT ... FOR UPDATE` sobre `caja_fisica_guardia WHERE id_caja_fisica = 1`, valida estado y traduce colisiones a `409 CASH_SESSION_ALREADY_OPEN`. |
| **Unicidad de nombre activo de turno y horario `fin > inicio`** | Columna generada `turno.nombre_activo_normalizado` con `UNIQUE uq_turno_nombre_activo`, `CHECK (hora_fin > hora_inicio)` y trigger que impide `DELETE` físico o baja con vendedores asignados. | Servicio valida formato `HH:mm`, recorta espacios, devuelve códigos semánticos (`409 SHIFT_NAME_ALREADY_EXISTS`, `422 INVALID_SHIFT_HOURS`) y conserva el snapshot en sesiones ya abiertas o cerradas. |
| **Múltiples turnos por día y preservación de excepciones semanales (Sección 13.1)** | `uq_prog_detalle_version_turno_dia (id_programacion, id_turno, dia_semana)` permite varios turnos el mismo día; `uq_prog_vendedor_semana_alcance_vigente` separa `'desde'` y `'semana'` sin invalidar excepciones futuras. | Servicio de programación crea una nueva versión de cabecera, marca `es_vigente = 0` únicamente en la versión anterior reemplazada según alcance y resuelve para cada semana consultada si rige una excepción `'semana'` o la base `'desde'` más reciente. |
| **Inmutabilidad de movimientos originales y cadena de correcciones** | Triggers `BEFORE UPDATE` / `BEFORE DELETE` en `movimiento_caja` y `correccion_movimiento_caja` + `UNIQUE (id_movimiento_correccion)`, `UNIQUE (id_correccion_previa)` y `UNIQUE (id_movimiento_original, numero_eslabon)`. | Servicio verifica la propiedad de la sesión y del movimiento original (`$this->authenticatedUserId`), inserta primero el movimiento `CORRECCION` y luego el eslabón en `correccion_movimiento_caja`, actualiza acumulados e invalida arqueos vigentes (`DESACTUALIZADO`) en una sola transacción. |
| **Cierre con arqueo `VIGENTE` (`OLD.version_caja`), incremento `NEW.version_caja = OLD.version_caja + 1` y congelamiento total NULL-safe** | Trigger `trg_sesion_caja_before_update_integridad` exige `id_arqueo_cierre = OLD.id_ultimo_arqueo` con `estado = 'VIGENTE'`, `version_caja_evaluada = OLD.version_caja`, `NEW.version_caja = OLD.version_caja + 1`, copia íntegra de `observacion` (`VARCHAR(500)`) a `observacion_cierre` (`VARCHAR(500)`), autorización en `autorizacion_caja` cuando `fecha_hora_cierre < fecha_hora_salida_referencia - 15 min`, y congelamiento NULL-safe (`<=>`) de las 36 columnas una vez `CERRADA`. | `AuthService::verificarCredencialesAdministradorPorEmail()` valida `email`, `password_verify()` y `id_rol = 1` activo **sin tocar `$_SESSION`** y sin guardar contraseñas en `autorizacion_caja` ni en `control_solicitud_idempotencia`. |
| **Exigencia de sesión abierta para nuevas ventas POS (HU 12)** | `venta.id_sesion_caja INT(11) DEFAULT NULL` con `fk_venta_sesion_caja` para conservar las 250 ventas históricas con `NULL`. | En el Paso 9, `VentaService::procesarVenta()` exigirá que el vendedor autenticado sea titular de la sesión `ABIERTA` actual, asociará `id_sesion_caja`, registrará `VENTA_EFECTIVO` si `id_metodo_pago = 1`, actualizará acumulados e invalidará el arqueo vigente si existía. |
| **Autenticación, CSRF, RBAC y anti-IDOR / BOLA** | Triggers validan el rol (`id_rol = 1` o `2`) y estado activo (`estado = 1`) del usuario referenciado en columnas FK. | `AuthMiddleware`, `CSRFMiddleware`, `RBACMiddleware` y `ApiController` verifican la identidad real de la sesión HTTP (`$this->authenticatedUserId`), permisos RBAC y titularidad del recurso antes de llegar a la BD. |


## 5. Baja de turnos y programación efectiva (corrección adicional HU 18)

`es_vigente = 1` identifica la versión no reemplazada de una regla; no significa que una base antigua siga rigiendo después de otra base posterior. El trigger de baja aplica la misma precedencia semanal del contrato:

1. Una excepción `semana` actual/futura que contiene el turno impide darlo de baja.
2. Para cada base `desde` que contiene el turno, su intervalo termina al comenzar la siguiente base del mismo vendedor. Solo se considera la parte del intervalo desde la semana actual.
3. Si cada semana de ese intervalo finito tiene una excepción, la base no se aplica en ninguna de ellas; si queda una semana sin excepción, la baja se bloquea. La última base sigue aplicándose tras las excepciones finitas y bloquea la baja.
4. Una sesión `ABIERTA` asociada sigue bloqueando la baja independientemente de la programación.

No se desactivan bases antiguas para resolver este cálculo. Todas sus cabeceras y detalles permanecen disponibles para consultar semanas pasadas. La baja es lógica y no elimina el turno ni sus referencias. Los futuros servicios de catálogo/programación del paso 6 deberán respetar esta resolución al calcular vendedores_asignados; una consulta histórica no debe descartar un turno por su estado actual inactivo.

Después de migrar una copia NUEVA mediante el ejecutor oficial, ejecutar ambas baterías y comprobar sus códigos de salida:

```powershell
# Sustituir por los nombres de las copias NUEVAS creadas y restauradas para el ensayo.
C:\xampp\php\php.exe database/tercerSprint/tools/verificar_conservacion_y_reglas_sprint3.php --test-db=<base_ensayo_nueva> --fail-sim-db=<base_fallos_nueva>
if ($LASTEXITCODE -ne 0) { throw 'Falló la batería general del Sprint 3' }

C:\xampp\php\php.exe database/tercerSprint/tools/verificar_baja_turnos_programacion_sprint3.php --test-db=<base_ensayo_nueva>
if ($LASTEXITCODE -ne 0) { throw 'Falló la regresión adicional de HU 18' }
```

La batería adicional toma el lunes actual del reloj de MariaDB con zona -05:00 y fechas relativas; no depende del día fijo del primer ensayo. Incluye bases históricas, actuales y futuras, excepciones pasadas/actuales/futuras, intervalos totalmente/parcialmente cubiertos, regreso a la última base y sesión abierta. Verifica también que las programaciones y detalles mantengan la misma huella antes/después de intentar la baja.

Cada escenario termina en ROLLBACK, incluso si falla. No limpia datos existentes, no elimina triggers y no reinicia contadores. Los AUTO_INCREMENT de las tablas NUEVAS pueden avanzar por los INSERT de prueba revertidos; no se alteran correlativos comerciales ni tablas anteriores. La batería requiere una copia nueva sin programación previa del vendedor elegido ni sesión abierta, y rechaza el escenario si encuentra esas condiciones, sin borrar información para hacerlo pasar.

---

## 6. Migración Incremental Adicional — Paso 8 (`07_ajuste_check_correccion_cero_sprint3.sql`)

Para habilitar `corrected_amount >= 0.00` en correcciones vinculadas (`EP-16`) sin reescribir los scripts `01`..`06` ni sus checksums históricos, se incorpora el script incremental **07**:

- **Archivo:** [07_ajuste_check_correccion_cero_sprint3.sql](07_ajuste_check_correccion_cero_sprint3.sql)
- **Ejecutor:** [ejecutar_ajuste_paso8_sprint3.php](tools/ejecutar_ajuste_paso8_sprint3.php)
- **Orden de ejecución:**
  1. En una base nueva restaurada desde el respaldo, ejecutar primero `01`..`06` con `ejecutar_migracion_sprint3.php <base_ensayo>` (deja `sprint3_migracion_control.estado = 'COMPLETADA'`).
  2. Luego (o sobre una copia que ya tenía aplicados `01`..`06`), ejecutar:
     ```powershell
     C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_ajuste_paso8_sprint3.php <base_ensayo>
     ```
- **Efecto:** Reemplaza únicamente `chk_correccion_importes_y_motivo` en `correccion_movimiento_caja` para permitir `monto_vigente_previo >= 0.00` y `monto_corregido >= 0.00`, conservando `monto_original > 0.00`, `monto_corregido <> monto_vigente_previo`, `efecto_saldo <> 0.00` y todos los triggers de inmutabilidad. No aplica sobre `amazon_market`.

---

## 7. Migración Incremental Adicional — Paso 8 (`08_ampliar_referencia_visible_movimiento_caja_sprint3.sql`)

Para conservar íntegras las referencias visibles generadas por `EP-13` (`admin_injection`) y `EP-14` (`admin_withdrawal`) cuando se combinan un motivo máximo de `250` caracteres y un nombre de administrador de `100` caracteres (`362` caracteres totales con separador y etiqueta), sin truncamiento en modo permisivo ni error `1406` en modo SQL estricto, se incorpora el script incremental **08**:

- **Archivo:** [08_ampliar_referencia_visible_movimiento_caja_sprint3.sql](08_ampliar_referencia_visible_movimiento_caja_sprint3.sql)
- **Ejecutor:** [ejecutar_ajuste_referencia_paso8_sprint3.php](tools/ejecutar_ajuste_referencia_paso8_sprint3.php)
- **Orden de ejecución:**
  - **Sobre una copia que ya tenía `01..07`:** ejecutar directamente:
    ```powershell
    C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_ajuste_referencia_paso8_sprint3.php <base_ensayo>
    ```
  - **Sobre una copia nueva preparada desde el respaldo:** ejecutar en secuencia `01..06`, luego `07`, y finalmente `08`:
    ```powershell
    C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_migracion_sprint3.php <base_ensayo>
    C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_ajuste_paso8_sprint3.php <base_ensayo>
    C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_ajuste_referencia_paso8_sprint3.php <base_ensayo>
    ```
- **Verificación SQL:**
  ```sql
  SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = '<base_ensayo>'
     AND TABLE_NAME = 'movimiento_caja'
     AND COLUMN_NAME = 'referencia_visible';
  -- Esperado: varchar(500), CHARACTER_MAXIMUM_LENGTH = 500
  ```
- **Efecto:** Amplía únicamente `movimiento_caja.referencia_visible` a `VARCHAR(500)` conservando todos los registros, restricciones y triggers existentes. No aplica sobre `amazon_market`.

---

## 8. Migración Incremental Adicional — Paso 9 (`09_ajuste_trigger_venta_efectivo_sprint3.sql`)

Para habilitar el registro de movimientos `VENTA_EFECTIVO` vinculados a ventas confirmadas (`venta.estado = 1`, `tinyint(4)`) sin error `MOVIMIENTO_VENTA_METODO_O_MONTO_INVALIDO`, se incorpora el script incremental **09**:

- **Archivo:** [09_ajuste_trigger_venta_efectivo_sprint3.sql](09_ajuste_trigger_venta_efectivo_sprint3.sql)
- **Ejecutor:** [ejecutar_ajuste_venta_paso9_sprint3.php](tools/ejecutar_ajuste_venta_paso9_sprint3.php)
- **Orden de ejecución (después de `01..06`, `07` y `08`):**
  ```powershell
  C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_ajuste_venta_paso9_sprint3.php <base_ensayo>
  ```
- **Efecto:** Reemplaza `trg_movimiento_caja_before_insert_integridad` alineando la comprobación de `venta.estado` a `1` (`tinyint`), conservando todas las demás validaciones de `movimiento_caja`. No aplica sobre `amazon_market`.


## 9. Despliegue autorizado en XAMPP — Paso 15

El despliegue local ya se realizó sobre `amazon_market`. Véase [DESPLIEGUE_LOCAL.md](DESPLIEGUE_LOCAL.md). El ejecutor autorizado usa **11 (contexto) antes de 10 (paquete 01..09)**; los ejecutores históricos continúan siendo exclusivos de ensayo. No repetir la migración sobre la base completada.
