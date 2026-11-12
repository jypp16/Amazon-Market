# Migraciones Incrementales de Base de Datos — Sprint 3 (Paso 5)

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

Los scripts deben ejecutarse **estrictamente en orden numérico secuencial (`01` al `06`)**. Cada script comprueba en `sprint3_migracion_control` que el paso inmediato anterior haya finalizado correctamente antes de ejecutar cualquier sentencia DDL o DML:

| Orden | Archivo | Propósito y objetos afectados |
| :-: | :--- | :--- |
| **01** | [01_preflight_y_control_sprint3.sql](01_preflight_y_control_sprint3.sql) | **Preflight y control de migración:** Valida la base destino, rechaza `amazon_market` y copias reservadas, comprueba las 32 tablas base `InnoDB`, los 5 triggers del Sprint 2, los roles activos (`1`, `2`), los 5 permisos reutilizados, los 3 métodos de pago y los valores actuales de `ENUM`. Adquiere bloqueo `GET_LOCK`, crea la tabla `sprint3_migracion_control` y registra la foto base (`conteos_base_json`). |
| **02** | [02_estructura_turnos_y_programacion_sprint3.sql](02_estructura_turnos_y_programacion_sprint3.sql) | **Dominios A y B (Turnos y programación semanal versionada):** Crea `turno` (con columna generada persistida `nombre_activo_normalizado` e índice `uq_turno_nombre_activo`), `programacion_semanal` y `programacion_semanal_detalle` (con unicidad `(id_programacion, id_turno, dia_semana)` que permite múltiples turnos el mismo día y preserva excepciones semanales ante nuevas bases permanentes conforme a la Sección 13.1 del contrato), más 7 triggers de integridad e inmutabilidad histórica. |
| **03** | [03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql](03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql) | **Dominios C, F y G (Sesiones de caja, arqueos y autorizaciones):** Crea `caja_fisica_guardia` (fila única `id_caja_fisica = 1` para serialización con `SELECT ... FOR UPDATE`), `sesion_caja` (con columna generada persistida `guardia_abierta_unica` e índice `uq_sesion_caja_unica_abierta`), `arqueo_caja` (con unicidad de arqueo `VIGENTE` por sesión y observación condicional cuando `diferencia <> 0.00`) y `autorizacion_caja` (cierre anticipado sin columnas de contraseñas ni secretos), más 12 triggers. |
| **04** | [04_estructura_movimientos_correcciones_y_ventas_sprint3.sql](04_estructura_movimientos_correcciones_y_ventas_sprint3.sql) | **Dominios H, D y E (Ventas, movimientos y correcciones encadenadas):** Añade `id_sesion_caja INT(11) DEFAULT NULL` e índice/FK en `venta` (conservando las 250 ventas históricas en `NULL`), crea `movimiento_caja` con los 7 tipos exactos del contrato y crea `correccion_movimiento_caja` enlazada al movimiento original, al movimiento compensatorio (`UNIQUE`) y al eslabón previo (`UNIQUE`) sin FK circulares, más 6 triggers de validación e inmutabilidad. |
| **05** | [05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql](05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql) | **Dominios J e I (Idempotencia y snapshots del reporte de cierres de caja):** Amplía `control_solicitud_idempotencia.tipo_operacion` con los 10 nuevos códigos del Sprint 3 (conservando los 7 previos y las 405 filas históricas), amplía `reporte_consulta_snapshot.tipo_reporte` añadiendo `'cierres_caja'` y crea `reporte_caja_snapshot_item` separada de `reporte_consulta_snapshot_item`, más 4 triggers. |
| **06** | [06_configuracion_permisos_y_cierre_migracion_sprint3.sql](06_configuracion_permisos_y_cierre_migracion_sprint3.sql) | **Dominio K y cierre transaccional:** Inserta por `slug` los 5 nuevos permisos del Sprint 3 (`turnos.movimientos`, `turnos.arqueo`, `turnos.programar`, `turnos.consultar_historico`, `reportes.cierres_caja`), asigna en `rol_permiso` por `slug` (2 a Vendedor `id_rol = 2` y 3 a Administrador `id_rol = 1`), valida la conservación del 100 % de los conteos y sumas de la línea base, marca `sprint3_migracion_control.estado = 'COMPLETADA'` y libera el lock de migración. |

### Herramientas auxiliares (`database/tercerSprint/tools/`)
- [verificar_conservacion_y_reglas_sprint3.php](tools/verificar_conservacion_y_reglas_sprint3.php): Compara conteos, `AUTO_INCREMENT`, `CHECKSUM TABLE ... EXTENDED` y hashes SHA-256 canónicos de las 32 tablas previas contra `amazon_market` y `amazon_market_sprint3_verify_20261112_012430`, y ejecuta 25 pruebas positivas y negativas sobre todos los dominios del Sprint 3.
- [ensayo_concurrencia_apertura_sprint3.php](tools/ensayo_concurrencia_apertura_sprint3.php) y [worker_apertura_concurrente.php](tools/worker_apertura_concurrente.php): Orquestan 2 procesos PHP/MySQL paralelos sincronizados por barrera temporal para demostrar en tiempo real que solo una sesión de caja puede quedar `ABIERTA`.

---

## 2. Parámetros de Sesión Obligatorios y Comprobación Previa del Destino

Ningún script se ejecutará si no se declaran explícitamente en la sesión SQL las variables de confirmación de destino y trazabilidad del respaldo:

| Variable de sesión | Valor requerido en el ensayo del Paso 5 | Descripción |
| :--- | :--- | :--- |
| `@SPRINT3_BASE_CONFIRMADA` | Nombre exacto de la base de ensayo (p. ej. `'amazon_market_sprint3_migration_test_20261112_140630'`) | Debe coincidir carácter por carácter (`BINARY`) con `DATABASE()`. Si difiere o si `DATABASE()` es `amazon_market`, el script aborta con `SQLSTATE '45000'`. |
| `@SPRINT3_RESPALDO_ARCHIVO` | `'C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql'` | Ruta del respaldo verificado desde el cual se restauró la base de ensayo. |
| `@SPRINT3_RESPALDO_SHA256` | `'7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D'` | Hash SHA-256 de 64 caracteres hexadecimales del respaldo verificado. |

### Procedimiento paso a paso para comprobar el destino y ejecutar en una base de ensayo

1. **Verificar que la nueva base de ensayo NO existe antes de crearla:**
   ```powershell
   $testDb = "amazon_market_sprint3_migration_test_20261112_140630"
   C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$testDb';"
   ```
2. **Crear la base de ensayo y restaurar el respaldo verificado sin `--force`:**
   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "CREATE DATABASE ``$testDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 --abort-source-on-error $testDb -e "SOURCE C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql;"
   ```
3. **Ejecutar los 6 scripts en orden verificando el código de salida (`$LASTEXITCODE -eq 0`):**
   ```powershell
   $dir = "c:/xampp/htdocs/Amazon-Market/database/tercerSprint"
   $bkp = "C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql"
   $sha = "7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D"

   foreach ($s in @(
       "01_preflight_y_control_sprint3.sql",
       "02_estructura_turnos_y_programacion_sprint3.sql",
       "03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql",
       "04_estructura_movimientos_correcciones_y_ventas_sprint3.sql",
       "05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql",
       "06_configuracion_permisos_y_cierre_migracion_sprint3.sql"
   )) {
       C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 --abort-source-on-error $testDb -e "SET @SPRINT3_BASE_CONFIRMADA='$testDb'; SET @SPRINT3_RESPALDO_ARCHIVO='$bkp'; SET @SPRINT3_RESPALDO_SHA256='$sha'; SOURCE $dir/$s;"
   }
   ```

---

## 3. Detección de Reejecución y Recuperación ante Error Parcial

1. **Detección de migración ya aplicada (`SPRINT3_YA_APLICADA`):**
   - Si `sprint3_migracion_control` tiene `estado = 'COMPLETADA'`, cualquiera de los scripts `01` a `06` aborta de inmediato con `SQLSTATE '45000'` (`SPRINT3_YA_APLICADA`) antes de ejecutar DDL o DML. No se duplican permisos en `permiso` ni asignaciones en `rol_permiso`.
2. **Comportamiento transaccional en MariaDB y qué hacer ante un error parcial:**
   - En MariaDB 10.4, las sentencias DDL (`CREATE TABLE`, `ALTER TABLE`, `CREATE TRIGGER`) provocan confirmación implícita (`implicit commit`) y **no** pueden revertirse mediante `ROLLBACK`.
   - En cambio, el script `06_configuracion_permisos_y_cierre_migracion_sprint3.sql` contiene exclusivamente operaciones DML (`INSERT` / `UPDATE`) dentro de un bloque `START TRANSACTION` con `EXIT HANDLER FOR SQLEXCEPTION` que ejecuta `ROLLBACK` y libera el bloqueo `GET_LOCK`.
   - **Regla operativa ante un fallo intermedio en los pasos `01` a `05`:** No parches manualmente ni reejecutes a ciegas sobre una base de ensayo con estado parcial (`SPRINT3_ESTADO_PARCIAL_INCOMPATIBLE`). Crea una nueva base de ensayo con nombre único, restaura en ella el respaldo verificado desde cero y ejecuta nuevamente la secuencia `01` a `06`.

---

## 4. Delimitación de Responsabilidades: Base de Datos vs. Capa de Servicios (Pasos 6 a 12)

Una clave foránea (`FOREIGN KEY`) o restricción `CHECK` garantiza integridad estructural y relacional, pero **no sustituye** los controles de negocio, autenticación o autorización del backend:

| Regla / Invariante | Garantizado en Base de Datos (Paso 5) | Garantizado en Servicios / Middleware PHP (Pasos 6 a 12) |
| :--- | :--- | :--- |
| **Unicidad de sesión abierta en la única caja física** | Tabla `caja_fisica_guardia` (`id_caja_fisica = 1`) + columna generada persistida `sesion_caja.guardia_abierta_unica` (`IF(estado = 'ABIERTA', 1, NULL)`) con índice `UNIQUE uq_sesion_caja_unica_abierta`. | Servicio de apertura inicia transacción, ejecuta `SELECT ... FOR UPDATE` sobre `caja_fisica_guardia WHERE id_caja_fisica = 1`, valida estado y traduce colisiones a `409 CASH_SESSION_ALREADY_OPEN`. |
| **Unicidad de nombre activo de turno y horario `fin > inicio`** | Columna generada `turno.nombre_activo_normalizado` con `UNIQUE uq_turno_nombre_activo`, `CHECK (hora_fin > hora_inicio)` y trigger que impide `DELETE` físico o baja con vendedores asignados. | Servicio valida formato `HH:mm`, recorta espacios, devuelve códigos semánticos (`409 SHIFT_NAME_ALREADY_EXISTS`, `422 INVALID_SHIFT_HOURS`) y conserva el snapshot en sesiones ya abiertas o cerradas. |
| **Múltiples turnos por día y preservación de excepciones semanales (Sección 13.1)** | `uq_prog_detalle_version_turno_dia (id_programacion, id_turno, dia_semana)` permite varios turnos el mismo día; `uq_prog_vendedor_semana_alcance_vigente` separa `'desde'` y `'semana'` sin invalidar excepciones futuras. | Servicio de programación crea una nueva versión de cabecera, marca `es_vigente = 0` únicamente en la versión anterior reemplazada según alcance y resuelve para cada semana consultada si rige una excepción `'semana'` o la base `'desde'` más reciente. |
| **Inmutabilidad de movimientos originales y cadena de correcciones** | Triggers `BEFORE UPDATE` / `BEFORE DELETE` en `movimiento_caja` y `correccion_movimiento_caja` + `UNIQUE (id_movimiento_correccion)`, `UNIQUE (id_correccion_previa)` y `UNIQUE (id_movimiento_original, numero_eslabon)`. | Servicio verifica la propiedad de la sesión y del movimiento original (`$this->authenticatedUserId`), inserta primero el movimiento `CORRECCION` y luego el eslabón en `correccion_movimiento_caja`, actualiza acumulados e invalida arqueos vigentes (`DESACTUALIZADO`) en una sola transacción. |
| **Cierre con arqueo `VIGENTE` y autorización administrativa antes de `salida - 15 min`** | Trigger `trg_sesion_caja_before_update_integridad` exige `id_arqueo_cierre` con `estado = 'VIGENTE'`, `version_caja_evaluada = version_caja` y presencia de fila en `autorizacion_caja` cuando `fecha_hora_cierre < fecha_hora_salida_referencia - 15 min`. | `AuthService::verificarCredencialesAdministradorPorEmail()` valida `email`, `password_verify()` y `id_rol = 1` activo **sin tocar `$_SESSION`** y sin guardar contraseñas en `autorizacion_caja` ni en `control_solicitud_idempotencia`. |
| **Exigencia de sesión abierta para nuevas ventas POS (HU 12)** | `venta.id_sesion_caja INT(11) DEFAULT NULL` con `fk_venta_sesion_caja` para conservar las 250 ventas históricas con `NULL`. | En el Paso 9, `VentaService::procesarVenta()` exigirá que el vendedor autenticado sea titular de la sesión `ABIERTA` actual, asociará `id_sesion_caja`, registrará `VENTA_EFECTIVO` si `id_metodo_pago = 1`, actualizará acumulados e invalidará el arqueo vigente si existía. |
| **Autenticación, CSRF, RBAC y anti-IDOR / BOLA** | Triggers validan el rol (`id_rol = 1` o `2`) y estado activo (`estado = 1`) del usuario referenciado en columnas FK. | `AuthMiddleware`, `CSRFMiddleware`, `RBACMiddleware` y `ApiController` verifican la identidad real de la sesión HTTP (`$this->authenticatedUserId`), permisos RBAC y titularidad del recurso antes de llegar a la BD. |
