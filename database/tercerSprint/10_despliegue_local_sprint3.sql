-- Despliegue local autorizado del Sprint 3. No sustituye los scripts de ensayo.
-- Ensamblado desde 01..09 conservados; solo se condicionan sus guardias de destino.
-- Usar exclusivamente ejecutar_despliegue_local_sprint3.php y su manifest verificado.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
DELIMITER $$
BEGIN NOT ATOMIC
 IF BINARY COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO,'') <> BINARY 'amazon_market'
    OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA,'') <> BINARY DATABASE()
    OR (DATABASE() <> 'amazon_market' AND DATABASE() NOT REGEXP '^amazon_market_sprint3_deploy_[A-Za-z0-9_]+$')
    OR COALESCE(@SPRINT3_VENTANA_MANTENIMIENTO,0) <> 1 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DESPLIEGUE_LOCAL_NO_AUTORIZADO';
 END IF;
 IF COALESCE(GET_LOCK('am_sprint3_despliegue_local',0),0) <> 1 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DESPLIEGUE_LOCAL_EN_CURSO';
 END IF;
END$$
DELIMITER ;

-- ORIGEN 01_preflight_y_control_sprint3.sql SHA256 725252486befb85e3fdbc5b638970b6677113bb17e00e0401d9f3fe90a163895
-- =============================================================================
-- ARCHIVO: 01_preflight_y_control_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 01 de 06
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   1. Verificar que la conexión NO apunte a la base original `amazon_market`
--      ni a la copia de verificación del Paso 3 (`amazon_market_sprint3_verify_20261112_012430`),
--      exigiendo confirmación explícita de la base de ensayo mediante @SPRINT3_BASE_CONFIRMADA.
--   2. Detectar de forma segura si la migración del Sprint 3 ya fue completada
--      (`SPRINT3_YA_APLICADA`) o si existe un estado parcial incompatible.
--   3. Validar las precondiciones estructurales y de datos (32 tablas InnoDB,
--      5 triggers del Sprint 2, roles, permisos base reutilizados, métodos de pago,
--      valores actuales de ENUM en idempotencia y snapshots).
--   4. Crear la tabla de trazabilidad `sprint3_migracion_control` y registrar
--      la línea base antes de ejecutar DDL sobre objetos de negocio.
--
-- NOTA TÉCNICA SOBRE DDL EN MARIADB:
--   Las sentencias DDL (`CREATE TABLE`, `ALTER TABLE`, `CREATE TRIGGER`) producen
--   confirmación implícita (`implicit commit`) en MariaDB y no se revierten con
--   `ROLLBACK`. Por ello todas las precondiciones se evalúan ANTES de ejecutar DDL.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_tablas_base INT DEFAULT 0;
    DECLARE v_tablas_no_innodb INT DEFAULT 0;
    DECLARE v_triggers_base INT DEFAULT 0;
    DECLARE v_roles_activos INT DEFAULT 0;
    DECLARE v_permisos_reutilizados INT DEFAULT 0;
    DECLARE v_metodos_pago INT DEFAULT 0;
    DECLARE v_objetos_parciales INT DEFAULT 0;
    DECLARE v_enum_idemp_invalido INT DEFAULT 0;
    DECLARE v_enum_snap_invalido INT DEFAULT 0;
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;
    DECLARE v_lock_ok INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    -- 1. Guardia estricta de base de datos y protección de `amazon_market`
    IF v_db_actual IS NULL OR CHAR_LENGTH(TRIM(v_db_actual)) = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BASE_NO_SELECCIONADA: Debe seleccionar explícitamente una base de datos de ensayo.';
    END IF;

    IF v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_BASE_ORIGINAL: Prohibido ejecutar migraciones del Paso 5 sobre la base original amazon_market.';
    END IF;

    IF v_db_actual IN (
        'amazon_market_sprint3_verify_20261112_012430',
        'amazon_market_sprint3_verify_20261112_012312',
        'amazon_market_sprint2_test',
        'amazon_market_restore_verify_20261027_113040',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'phpmyadmin'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_COPIA_RESERVADA: Prohibido modificar la copia de verificación del Paso 3 o esquemas del sistema.';
    END IF;

    IF BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BASE_DESTINO_NO_CONFIRMADA: Declare @SPRINT3_BASE_CONFIRMADA igual al nombre exacto de la base de ensayo.';
    END IF;

    IF COALESCE(TRIM(@SPRINT3_RESPALDO_ARCHIVO), '') = ''
       OR CHAR_LENGTH(@SPRINT3_RESPALDO_ARCHIVO) > 512
       OR UPPER(COALESCE(@SPRINT3_RESPALDO_SHA256, '')) NOT REGEXP '^[0-9A-F]{64}$' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RESPALDO_NO_DECLARADO: Declare @SPRINT3_RESPALDO_ARCHIVO y @SPRINT3_RESPALDO_SHA256 (64 hex) antes de migrar.';
    END IF;

    IF VERSION() NOT LIKE '%MariaDB%' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MOTOR_NO_COMPATIBLE: Las migraciones del Sprint 3 requieren MariaDB 10.4+.';
    END IF;

    -- 2. Detección de ejecución previa o estado parcial
    IF EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SELECT estado, paso_actual
          INTO v_estado_migracion, v_paso_migracion
          FROM sprint3_migracion_control
         WHERE id_migracion = 3
         LIMIT 1;

        IF v_estado_migracion = 'COMPLETADA' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base. No se modificaron datos ni permisos.';
        ELSE
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'SPRINT3_ESTADO_PARCIAL_INCOMPATIBLE: Existe una ejecución previa incompleta. No reejecute a ciegas; restaure en una base nueva.';
        END IF;
    END IF;

    SELECT COUNT(*)
      INTO v_objetos_parciales
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME IN (
           'turno',
           'programacion_semanal',
           'programacion_semanal_detalle',
           'caja_fisica_guardia',
           'sesion_caja',
           'movimiento_caja',
           'correccion_movimiento_caja',
           'arqueo_caja',
           'autorizacion_caja',
           'reporte_caja_snapshot_item'
       );

    IF v_objetos_parciales > 0 OR EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'venta'
           AND COLUMN_NAME = 'id_sesion_caja'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SPRINT3_OBJETOS_PARCIALES_DETECTADOS: Existen tablas o columnas del Sprint 3 sin registro de control.';
    END IF;

    -- 3. Verificación del esquema base de 32 tablas InnoDB y 5 triggers del Sprint 2
    SELECT COUNT(*)
      INTO v_tablas_base
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_TYPE = 'BASE TABLE'
       AND TABLE_NAME IN (
           'categoria', 'cliente', 'compra', 'compra_historial',
           'control_solicitud_idempotencia', 'detalle_compra', 'detalle_venta',
           'kardex', 'metodo_pago', 'operacion_inventario', 'permiso',
           'presentacion', 'producto', 'proveedor', 'reporte_consulta_snapshot',
           'reporte_consulta_snapshot_item', 'rol', 'rol_permiso',
           'serie_comprobante', 'sprint2_archivo_compra',
           'sprint2_archivo_compra_historial', 'sprint2_archivo_detalle_compra',
           'sprint2_archivo_detalle_venta', 'sprint2_archivo_kardex',
           'sprint2_archivo_venta', 'sprint2_fase10_control', 'subcategoria',
           'tipo_comprobante', 'tipo_documento', 'unidad_medida', 'usuario', 'venta'
       );

    IF v_tablas_base <> 32 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION_ESQUEMA_FALLIDA: No se encontraron las 32 tablas base esperadas del esquema previo.';
    END IF;

    SELECT COUNT(*)
      INTO v_tablas_no_innodb
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_TYPE = 'BASE TABLE'
       AND COALESCE(ENGINE, '') <> 'InnoDB';

    IF v_tablas_no_innodb > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION_MOTOR_FALLIDA: Todas las tablas existentes deben utilizar el motor transaccional InnoDB.';
    END IF;

    SELECT COUNT(*)
      INTO v_triggers_base
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual
       AND TRIGGER_NAME IN (
           'trg_categoria_after_insert_subcategoria_general',
           'trg_kardex_before_insert_integridad',
           'trg_kardex_before_update_inmutable',
           'trg_producto_before_insert_subcategoria',
           'trg_producto_before_update_subcategoria'
       );

    IF v_triggers_base <> 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION_TRIGGERS_FALLIDA: Faltan triggers obligatorios del Sprint 2 en el esquema restaurado.';
    END IF;

    -- 4. Verificación de roles, permisos reutilizados, métodos de pago y ENUMs
    SELECT COUNT(*)
      INTO v_roles_activos
      FROM rol
     WHERE (id_rol = 1 AND estado = 1)
        OR (id_rol = 2 AND estado = 1);

    IF v_roles_activos <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION_ROLES_FALLIDA: Deben existir activos los roles 1 (Administrador) y 2 (Vendedor).';
    END IF;

    SELECT COUNT(*)
      INTO v_permisos_reutilizados
      FROM permiso
     WHERE slug IN ('turnos.acceder', 'turnos.abrir', 'turnos.cerrar', 'ventas.procesar', 'reportes.exportar')
       AND estado = 1;

    IF v_permisos_reutilizados <> 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION_PERMISOS_FALLIDA: Faltan permisos base activos requeridos para reutilización en el Sprint 3.';
    END IF;

    SELECT COUNT(*)
      INTO v_metodos_pago
      FROM metodo_pago
     WHERE id_metodo_pago IN (1, 2, 3)
       AND estado = 1;

    IF v_metodos_pago <> 3 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION_METODOS_PAGO_FALLIDA: Deben existir activos los métodos de pago 1 (Efectivo), 2 (Tarjeta) y 3 (Yape / Plin).';
    END IF;

    SELECT COUNT(*)
      INTO v_enum_idemp_invalido
      FROM control_solicitud_idempotencia
     WHERE tipo_operacion NOT IN (
               'BAJA', 'AJUSTE', 'COMPRA', 'CORRECCION_COMPRA',
               'ANULACION_COMPRA', 'VENTA', 'ANULACION_VENTA'
           )
        OR estado_solicitud NOT IN ('EN_PROCESO', 'CONFIRMADA', 'FALLIDA');

    IF v_enum_idemp_invalido > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION_IDEMPOTENCIA_FALLIDA: Existen valores inesperados en control_solicitud_idempotencia.';
    END IF;

    SELECT COUNT(*)
      INTO v_enum_snap_invalido
      FROM reporte_consulta_snapshot
     WHERE tipo_reporte NOT IN ('kardex', 'variaciones');

    IF v_enum_snap_invalido > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION_SNAPSHOT_FALLIDA: Existen valores inesperados en reporte_consulta_snapshot.tipo_reporte.';
    END IF;

    -- 5. Bloqueo de exclusión mutua para la migración sobre esta base
    SET @SPRINT3_MIG_LOCK = CONCAT('am_sprint3_mig_', MD5(v_db_actual));
    SELECT COALESCE(GET_LOCK(@SPRINT3_MIG_LOCK, 0), 0) INTO v_lock_ok;

    IF v_lock_ok <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MIGRACION_EN_CURSO: Otra conexión mantiene bloqueada la migración de esta base.';
    END IF;

    -- 6. Creación de tabla de control y registro de línea base
    CREATE TABLE `sprint3_migracion_control` (
      `id_migracion` tinyint(3) unsigned NOT NULL,
      `base_datos` varchar(128) NOT NULL,
      `estado` enum('EN_PROGRESO','COMPLETADA','FALLIDA') NOT NULL DEFAULT 'EN_PROGRESO',
      `paso_actual` varchar(64) NOT NULL,
      `version_motor` varchar(64) NOT NULL,
      `respaldo_archivo` varchar(512) NOT NULL,
      `respaldo_sha256` char(64) NOT NULL,
      `conteos_base_json` longtext NOT NULL,
      `conteos_finales_json` longtext DEFAULT NULL,
      `iniciada_en` datetime NOT NULL DEFAULT current_timestamp(),
      `actualizada_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      `completada_en` datetime DEFAULT NULL,
      PRIMARY KEY (`id_migracion`),
      CONSTRAINT `chk_sprint3_mig_id` CHECK (`id_migracion` = 3),
      CONSTRAINT `chk_sprint3_mig_sha256` CHECK (`respaldo_sha256` regexp '^[0-9A-F]{64}$'),
      CONSTRAINT `chk_sprint3_mig_json_base` CHECK (json_valid(`conteos_base_json`)),
      CONSTRAINT `chk_sprint3_mig_json_fin` CHECK (`conteos_finales_json` is null or json_valid(`conteos_finales_json`))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    INSERT INTO `sprint3_migracion_control` (
        `id_migracion`,
        `base_datos`,
        `estado`,
        `paso_actual`,
        `version_motor`,
        `respaldo_archivo`,
        `respaldo_sha256`,
        `conteos_base_json`
    )
    SELECT
        3,
        v_db_actual,
        'EN_PROGRESO',
        '00_INICIANDO',
        VERSION(),
        TRIM(@SPRINT3_RESPALDO_ARCHIVO),
        UPPER(TRIM(@SPRINT3_RESPALDO_SHA256)),
        JSON_OBJECT(
            'usuario', (SELECT COUNT(*) FROM usuario),
            'rol', (SELECT COUNT(*) FROM rol),
            'permiso', (SELECT COUNT(*) FROM permiso),
            'rol_permiso', (SELECT COUNT(*) FROM rol_permiso),
            'producto', (SELECT COUNT(*) FROM producto),
            'stock_total', (SELECT COALESCE(SUM(stock_actual), 0) FROM producto),
            'venta', (SELECT COUNT(*) FROM venta),
            'detalle_venta', (SELECT COUNT(*) FROM detalle_venta),
            'compra', (SELECT COUNT(*) FROM compra),
            'detalle_compra', (SELECT COUNT(*) FROM detalle_compra),
            'compra_historial', (SELECT COUNT(*) FROM compra_historial),
            'kardex', (SELECT COUNT(*) FROM kardex),
            'operacion_inventario', (SELECT COUNT(*) FROM operacion_inventario),
            'serie_comprobante', (SELECT COUNT(*) FROM serie_comprobante),
            'control_solicitud_idempotencia', (SELECT COUNT(*) FROM control_solicitud_idempotencia),
            'reporte_consulta_snapshot', (SELECT COUNT(*) FROM reporte_consulta_snapshot),
            'reporte_consulta_snapshot_item', (SELECT COUNT(*) FROM reporte_consulta_snapshot_item),
            'sprint2_archivo_venta', (SELECT COUNT(*) FROM sprint2_archivo_venta),
            'sprint2_archivo_detalle_venta', (SELECT COUNT(*) FROM sprint2_archivo_detalle_venta),
            'sprint2_archivo_compra', (SELECT COUNT(*) FROM sprint2_archivo_compra),
            'sprint2_archivo_detalle_compra', (SELECT COUNT(*) FROM sprint2_archivo_detalle_compra),
            'sprint2_archivo_compra_historial', (SELECT COUNT(*) FROM sprint2_archivo_compra_historial),
            'sprint2_archivo_kardex', (SELECT COUNT(*) FROM sprint2_archivo_kardex)
        );

    -- 7. Verificación de postcondición del paso 01 antes de marcar 01_PREFLIGHT
    IF COALESCE(IS_USED_LOCK(@SPRINT3_MIG_LOCK), -1) <> CONNECTION_ID()
       OR (SELECT COUNT(*) FROM sprint3_migracion_control WHERE id_migracion = 3 AND base_datos = v_db_actual) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_PASO01_FALLIDA: No se pudo verificar el bloqueo o el registro de control del paso 01.';
    END IF;

    UPDATE `sprint3_migracion_control`
       SET `paso_actual` = '01_PREFLIGHT'
     WHERE `id_migracion` = 3
       AND `estado` = 'EN_PROGRESO';
END$$

DELIMITER ;

-- STEP15_AFTER_01

-- ORIGEN 02_estructura_turnos_y_programacion_sprint3.sql SHA256 558d4151d02dfe48420d4b72d00735e669e1884811bea3426b224c0b6219aa0e
-- =============================================================================
-- ARCHIVO: 02_estructura_turnos_y_programacion_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 02 de 06 (Requiere 01_preflight_y_control_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Implementar los dominios A (Turnos) y B (Programación semanal versionada):
--     1. Tabla `turno` con identidad, nombre, hora_inicio, hora_fin (`fin > inicio`),
--        estado `0/1`, unicidad de nombre activo (`LOWER(TRIM(nombre))`) mediante
--        columna generada persistida compatible con MariaDB 10.4 y baja lógica.
--     2. Tabla `programacion_semanal` versionada por vendedor (`id_rol = 2`),
--        lunes de inicio (`WEEKDAY(semana_inicio) = 0`), alcance (`'desde'` base
--        permanente o `'semana'` excepción puntual), autor (`id_rol = 1`) y
--        preservación de todas las versiones anteriores.
--     3. Tabla `programacion_semanal_detalle` con `id_turno` y `dia_semana` (`0..6`),
--        cuya unicidad es `(id_programacion, id_turno, dia_semana)` para permitir
--        varios turnos en un mismo día (p. ej. Mañana y Tarde el lunes).
--     4. Preservación de excepciones semanales (`alcance = 'semana'`) cuando se
--        guarda una nueva base (`alcance = 'desde'`), conforme a la decisión
--        confirmada en la Sección 13.1 del Contrato API.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_lock_name VARCHAR(64);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market')
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 02.';
    END IF;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero 01_preflight_y_control_sprint3.sql.';
    END IF;

    SELECT estado, paso_actual
      INTO v_estado_migracion, v_paso_migracion
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion = 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base.';
    END IF;

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '01_PREFLIGHT' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 02 exige estado EN_PROGRESO y paso_actual = 01_PREFLIGHT.';
    END IF;

    IF COALESCE(IS_USED_LOCK(v_lock_name), -1) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LOCK_MIGRACION_NO_VIGENTE: El paso 02 debe ejecutarse en la misma conexión que adquirió GET_LOCK en el paso 01.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME IN ('turno', 'programacion_semanal', 'programacion_semanal_detalle')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'OBJETOS_EXISTENTES_PASO02: Las tablas de turnos o programación ya existen en esta base.';
    END IF;

    -- =========================================================================
    -- A. TABLA `turno` (Catálogo de turnos de atención — HU 18 y HU 11)
    -- =========================================================================
    CREATE TABLE `turno` (
      `id_turno` int(11) NOT NULL AUTO_INCREMENT,
      `nombre` varchar(60) NOT NULL,
      `hora_inicio` time NOT NULL,
      `hora_fin` time NOT NULL,
      `estado` tinyint(4) NOT NULL DEFAULT 1,
      `nombre_activo_normalizado` varchar(60) AS (if(`estado` = 1, lower(trim(`nombre`)), NULL)) PERSISTENT,
      `id_usuario_creacion` int(11) DEFAULT NULL,
      `id_solicitud_creacion` int(11) DEFAULT NULL,
      `id_solicitud_ultima_edicion` int(11) DEFAULT NULL,
      `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
      `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      `baja_en` datetime DEFAULT NULL,
      PRIMARY KEY (`id_turno`),
      UNIQUE KEY `uq_turno_nombre_activo` (`nombre_activo_normalizado`),
      KEY `idx_turno_estado_inicio` (`estado`, `hora_inicio`, `id_turno`),
      KEY `fk_turno_usuario_creacion` (`id_usuario_creacion`),
      KEY `fk_turno_solicitud_creacion` (`id_solicitud_creacion`),
      KEY `fk_turno_solicitud_edicion` (`id_solicitud_ultima_edicion`),
      CONSTRAINT `fk_turno_usuario_creacion` FOREIGN KEY (`id_usuario_creacion`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_turno_solicitud_creacion` FOREIGN KEY (`id_solicitud_creacion`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `fk_turno_solicitud_edicion` FOREIGN KEY (`id_solicitud_ultima_edicion`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_turno_nombre` CHECK (char_length(trim(`nombre`)) between 1 and 60),
      CONSTRAINT `chk_turno_horario` CHECK (
        `hora_inicio` >= '00:00:00'
        and `hora_fin` <= '23:59:00'
        and second(`hora_inicio`) = 0
        and second(`hora_fin`) = 0
        and `hora_fin` > `hora_inicio`
      ),
      CONSTRAINT `chk_turno_estado` CHECK (`estado` in (0, 1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- B.1. TABLA `programacion_semanal` (Cabecera versionada — HU 18)
    -- =========================================================================
    CREATE TABLE `programacion_semanal` (
      `id_programacion` int(11) NOT NULL AUTO_INCREMENT,
      `id_usuario_vendedor` int(11) NOT NULL,
      `semana_inicio` date NOT NULL,
      `alcance` enum('desde','semana') NOT NULL,
      `version_programacion` int(11) NOT NULL DEFAULT 1,
      `es_vigente` tinyint(1) NOT NULL DEFAULT 1,
      `vigencia_activa` tinyint(1) DEFAULT 1,
      `id_programacion_previa` int(11) DEFAULT NULL,
      `id_programacion_origen_duplicacion` int(11) DEFAULT NULL,
      `id_usuario_autor` int(11) NOT NULL,
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
      `reemplazada_en` datetime DEFAULT NULL,
      PRIMARY KEY (`id_programacion`),
      UNIQUE KEY `uq_prog_vendedor_semana_alcance_vigente` (`id_usuario_vendedor`, `semana_inicio`, `alcance`, `vigencia_activa`),
      UNIQUE KEY `uq_prog_vendedor_semana_alcance_version` (`id_usuario_vendedor`, `semana_inicio`, `alcance`, `version_programacion`),
      KEY `idx_prog_resolucion_vigente` (`id_usuario_vendedor`, `es_vigente`, `alcance`, `semana_inicio`),
      KEY `fk_prog_previa` (`id_programacion_previa`),
      KEY `fk_prog_origen_duplicacion` (`id_programacion_origen_duplicacion`),
      KEY `fk_prog_usuario_autor` (`id_usuario_autor`),
      KEY `fk_prog_solicitud_idemp` (`id_solicitud_idempotencia`),
      CONSTRAINT `fk_prog_usuario_vendedor` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_prog_previa` FOREIGN KEY (`id_programacion_previa`) REFERENCES `programacion_semanal` (`id_programacion`),
      CONSTRAINT `fk_prog_origen_duplicacion` FOREIGN KEY (`id_programacion_origen_duplicacion`) REFERENCES `programacion_semanal` (`id_programacion`),
      CONSTRAINT `fk_prog_usuario_autor` FOREIGN KEY (`id_usuario_autor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_prog_solicitud_idemp` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_prog_semana_lunes` CHECK (weekday(`semana_inicio`) = 0),
      CONSTRAINT `chk_prog_version` CHECK (
        `version_programacion` >= 1
        and (
          (`es_vigente` = 1 and `vigencia_activa` = 1)
          or (`es_vigente` = 0 and `vigencia_activa` is null)
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- B.2. TABLA `programacion_semanal_detalle` (Turnos y días 0..6 — HU 18)
    -- =========================================================================
    CREATE TABLE `programacion_semanal_detalle` (
      `id_programacion_detalle` int(11) NOT NULL AUTO_INCREMENT,
      `id_programacion` int(11) NOT NULL,
      `id_turno` int(11) NOT NULL,
      `dia_semana` tinyint(4) NOT NULL,
      PRIMARY KEY (`id_programacion_detalle`),
      UNIQUE KEY `uq_prog_detalle_version_turno_dia` (`id_programacion`, `id_turno`, `dia_semana`),
      KEY `idx_prog_detalle_turno` (`id_turno`, `dia_semana`),
      CONSTRAINT `fk_prog_detalle_cabecera` FOREIGN KEY (`id_programacion`) REFERENCES `programacion_semanal` (`id_programacion`),
      CONSTRAINT `fk_prog_detalle_turno` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`),
      CONSTRAINT `chk_prog_detalle_dia` CHECK (`dia_semana` between 0 and 6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

-- =============================================================================
-- TRIGGERS DE INTEGRIDAD PARA `turno`, `programacion_semanal` Y DETALLE
-- =============================================================================

CREATE TRIGGER `trg_turno_before_delete_prohibido`
BEFORE DELETE ON `turno`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'BAJA_LOGICA_REQUERIDA: Los turnos no se eliminan físicamente; actualice estado = 0 para preservar referencias históricas.';
END$$

CREATE TRIGGER `trg_turno_before_update_integridad`
BEFORE UPDATE ON `turno`
FOR EACH ROW
BEGIN
    DECLARE v_asignados INT DEFAULT 0;
    DECLARE v_sesiones_abiertas INT DEFAULT 0;
    DECLARE v_semana_actual DATE;
    DECLARE v_vendedor INT;
    DECLARE v_inicio_base DATE;
    DECLARE v_desde DATE;
    DECLARE v_siguiente_base DATE;
    DECLARE v_semanas INT;
    DECLARE v_excepciones INT;
    DECLARE v_fin INT DEFAULT 0;
    DECLARE c_bases CURSOR FOR
        SELECT DISTINCT ps.id_usuario_vendedor, ps.semana_inicio
          FROM programacion_semanal ps
          JOIN programacion_semanal_detalle psd
            ON psd.id_programacion = ps.id_programacion
         WHERE ps.es_vigente = 1
           AND ps.alcance = 'desde'
           AND psd.id_turno = OLD.id_turno;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;

    IF OLD.estado = 1 AND NEW.estado = 0 THEN
        SET v_semana_actual = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY);

        -- Una excepción exacta actual/futura tiene prioridad sobre cualquier base.
        SELECT COUNT(DISTINCT ps.id_usuario_vendedor)
          INTO v_asignados
          FROM programacion_semanal ps
          INNER JOIN programacion_semanal_detalle psd
             ON psd.id_programacion = ps.id_programacion
         WHERE ps.es_vigente = 1
           AND psd.id_turno = OLD.id_turno
           AND ps.alcance = 'semana'
           AND ps.semana_inicio >= v_semana_actual;

        IF v_asignados = 0 THEN
            -- es_vigente identifica la versión ganadora de una regla, no que una
            -- base histórica siga aplicándose después de otra base más reciente.
            -- Cada base rige [su lunes, siguiente base), excepto semanas puntuales.
            OPEN c_bases;
            bases_vigentes: LOOP
                FETCH c_bases INTO v_vendedor, v_inicio_base;
                IF v_fin = 1 THEN
                    LEAVE bases_vigentes;
                END IF;

                SET v_desde = GREATEST(v_inicio_base, v_semana_actual);
                SELECT MIN(semana_inicio)
                  INTO v_siguiente_base
                  FROM programacion_semanal
                 WHERE id_usuario_vendedor = v_vendedor
                   AND es_vigente = 1
                   AND alcance = 'desde'
                   AND semana_inicio > v_inicio_base;

                IF v_siguiente_base IS NULL THEN
                    -- La última base vuelve a regir tras las excepciones finitas.
                    SET v_asignados = 1;
                    LEAVE bases_vigentes;
                END IF;

                IF v_siguiente_base > v_desde THEN
                    SET v_semanas = DATEDIFF(v_siguiente_base, v_desde) DIV 7;
                    SELECT COUNT(DISTINCT semana_inicio)
                      INTO v_excepciones
                      FROM programacion_semanal
                     WHERE id_usuario_vendedor = v_vendedor
                       AND es_vigente = 1
                       AND alcance = 'semana'
                       AND semana_inicio >= v_desde
                       AND semana_inicio < v_siguiente_base;

                    IF v_excepciones < v_semanas THEN
                        SET v_asignados = 1;
                        LEAVE bases_vigentes;
                    END IF;
                END IF;
            END LOOP bases_vigentes;
            CLOSE c_bases;
        END IF;

        IF v_asignados > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'SHIFT_HAS_ASSIGNED_SELLERS: No se puede dar de baja un turno con vendedores asignados en programación vigente o futura.';
        END IF;

        -- Si ya existe la tabla sesion_caja, verificar que no haya sesión ABIERTA usando este turno
        IF EXISTS (
            SELECT 1
              FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'sesion_caja'
        ) THEN
            SELECT COUNT(*)
              INTO v_sesiones_abiertas
              FROM sesion_caja
             WHERE id_turno = OLD.id_turno
               AND estado = 'ABIERTA';

            IF v_sesiones_abiertas > 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'SHIFT_IN_USE: No se puede dar de baja el turno mientras exista una sesión de caja abierta asociada a él.';
            END IF;
        END IF;

        IF NEW.baja_en IS NULL THEN
            SET NEW.baja_en = NOW();
        END IF;
    END IF;
END$$

CREATE TRIGGER `trg_programacion_semanal_before_insert_integridad`
BEFORE INSERT ON `programacion_semanal`
FOR EACH ROW
BEGIN
    DECLARE v_rol_vendedor INT DEFAULT NULL;
    DECLARE v_estado_vendedor INT DEFAULT NULL;
    DECLARE v_rol_autor INT DEFAULT NULL;
    DECLARE v_estado_autor INT DEFAULT NULL;
    DECLARE v_tiene_base INT DEFAULT 0;

    IF WEEKDAY(NEW.semana_inicio) <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INVALID_WEEK_START_DATE: semana_inicio debe corresponder obligatoriamente a un lunes.';
    END IF;

    SELECT id_rol, estado
      INTO v_rol_vendedor, v_estado_vendedor
      FROM usuario
     WHERE id_usuario = NEW.id_usuario_vendedor
     LIMIT 1;

    IF v_rol_vendedor IS NULL OR v_rol_vendedor <> 2 OR v_estado_vendedor <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'TARGET_NOT_SELLER: La programación semanal solo puede asignarse a un usuario activo con rol Vendedor (id_rol = 2).';
    END IF;

    SELECT id_rol, estado
      INTO v_rol_autor, v_estado_autor
      FROM usuario
     WHERE id_usuario = NEW.id_usuario_autor
     LIMIT 1;

    IF v_rol_autor IS NULL OR v_rol_autor <> 1 OR v_estado_autor <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'FORBIDDEN_ADMIN_ONLY: La programación semanal solo puede ser registrada por un Administrador activo (id_rol = 1).';
    END IF;

    IF NEW.alcance = 'semana' THEN
        SELECT COUNT(*)
          INTO v_tiene_base
          FROM programacion_semanal ps
         WHERE ps.id_usuario_vendedor = NEW.id_usuario_vendedor
           AND ps.alcance = 'desde'
           AND ps.es_vigente = 1
           AND ps.semana_inicio <= NEW.semana_inicio;

        IF v_tiene_base = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'PRIMERA_PROGRAMACION_REQUIERE_BASE: No se puede registrar una excepción semanal sin una programación base vigente aplicable.';
        END IF;
    END IF;

    SET NEW.vigencia_activa = IF(NEW.es_vigente = 1, 1, NULL);
END$$

CREATE TRIGGER `trg_programacion_semanal_before_update_integridad`
BEFORE UPDATE ON `programacion_semanal`
FOR EACH ROW
BEGIN
    IF OLD.id_usuario_vendedor <> NEW.id_usuario_vendedor
       OR OLD.semana_inicio <> NEW.semana_inicio
       OR OLD.alcance <> NEW.alcance
       OR OLD.version_programacion <> NEW.version_programacion
       OR OLD.id_usuario_autor <> NEW.id_usuario_autor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INMUTABILIDAD_VERSION_PROGRAMACION: No se permite alterar la identidad ni los metadatos de una versión de programación; cree una nueva versión.';
    END IF;

    IF OLD.es_vigente = 0 AND NEW.es_vigente = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INMUTABILIDAD_VERSION_PROGRAMACION: Una versión histórica reemplazada no puede reactivarse; registre una nueva versión.';
    END IF;

    SET NEW.vigencia_activa = IF(NEW.es_vigente = 1, 1, NULL);

    IF OLD.es_vigente = 1 AND NEW.es_vigente = 0 AND NEW.reemplazada_en IS NULL THEN
        SET NEW.reemplazada_en = NOW();
    END IF;
END$$

CREATE TRIGGER `trg_programacion_semanal_before_delete_prohibido`
BEFORE DELETE ON `programacion_semanal`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_PROGRAMACION_INMUTABLE: Las versiones de programación semanal se conservan y no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_prog_detalle_before_insert_integridad`
BEFORE INSERT ON `programacion_semanal_detalle`
FOR EACH ROW
BEGIN
    DECLARE v_estado_turno INT DEFAULT NULL;

    SELECT estado
      INTO v_estado_turno
      FROM turno
     WHERE id_turno = NEW.id_turno
     LIMIT 1;

    IF v_estado_turno IS NULL OR v_estado_turno <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INVALID_SHIFT: El turno asignado en la programación no existe o se encuentra inactivo.';
    END IF;
END$$

CREATE TRIGGER `trg_prog_detalle_before_delete_prohibido`
BEFORE DELETE ON `programacion_semanal_detalle`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_PROGRAMACION_INMUTABLE: Los detalles de versiones de programación semanal no pueden eliminarse.';
END$$

-- =============================================================================
-- CIERRE DEL PASO 02: VERIFICACIÓN DE OBJETOS Y TRIGGERS ANTES DE AVANZAR PASO
-- =============================================================================
BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_tablas_ok INT DEFAULT 0;
    DECLARE v_fks_ok INT DEFAULT 0;
    DECLARE v_triggers_ok INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    SELECT COUNT(*)
      INTO v_tablas_ok
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME IN ('turno', 'programacion_semanal', 'programacion_semanal_detalle');

    SELECT COUNT(*)
      INTO v_fks_ok
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = v_db_actual
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND TABLE_NAME IN ('turno', 'programacion_semanal', 'programacion_semanal_detalle');

    SELECT COUNT(*)
      INTO v_triggers_ok
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual
       AND TRIGGER_NAME IN (
           'trg_turno_before_delete_prohibido',
           'trg_turno_before_update_integridad',
           'trg_programacion_semanal_before_insert_integridad',
           'trg_programacion_semanal_before_update_integridad',
           'trg_programacion_semanal_before_delete_prohibido',
           'trg_prog_detalle_before_insert_integridad',
           'trg_prog_detalle_before_delete_prohibido'
       );

    IF v_tablas_ok <> 3 OR v_fks_ok <> 10 OR v_triggers_ok <> 7 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO02_INCOMPLETA: No se crearon todas las tablas (3), FKs (10) o triggers (7) del paso 02.';
    END IF;

    UPDATE `sprint3_migracion_control`
       SET `paso_actual` = '02_TURNOS_Y_PROGRAMACION'
     WHERE `id_migracion` = 3
       AND `estado` = 'EN_PROGRESO'
       AND `paso_actual` = '01_PREFLIGHT';
END$$

DELIMITER ;

-- STEP15_AFTER_02

-- ORIGEN 03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql SHA256 42624195aab500de704dcf53f432709e5fe1ab95d328cca035172687bf48efbe
-- =============================================================================
-- ARCHIVO: 03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 03 de 06 (Requiere 02_estructura_turnos_y_programacion_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Implementar los dominios C (Sesiones de caja y guardia única), F (Arqueos)
--   y G (Autorizaciones administrativas):
--     1. Tabla `caja_fisica_guardia` con fila única (`id_caja_fisica = 1`) para
--        serializar aperturas concurrentes mediante `SELECT ... FOR UPDATE` sobre
--        una fila existente incluso cuando `sesion_caja` aún tiene cero filas.
--     2. Tabla `sesion_caja` con dueño real (`id_usuario_vendedor`), snapshot del
--        turno y horario, `fecha_hora_salida_referencia`, referencia nullable al
--        cierre anterior (`id_sesion_cierre_anterior`), conteo inicial y diferencia
--        de apertura nullable, acumulados por medio de pago, versión y cierre
--        congelado. Garantiza una sola sesión ABIERTA mediante columna generada
--        persistida `guardia_abierta_unica` e índice `UNIQUE`.
--     3. Tabla `arqueo_caja` con historial conservado, versión de caja evaluada,
--        observación obligatoria cuando `diferencia <> 0.00` y a lo sumo un
--        arqueo `VIGENTE` por sesión.
--     4. Tabla `autorizacion_caja` para cierres anticipados (> 15 min antes de
--        la hora de salida de referencia), sin almacenar contraseñas ni secretos.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_lock_name VARCHAR(64);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market')
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 03.';
    END IF;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 y 02.';
    END IF;

    SELECT estado, paso_actual
      INTO v_estado_migracion, v_paso_migracion
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion = 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base.';
    END IF;

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '02_TURNOS_Y_PROGRAMACION' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 03 exige estado EN_PROGRESO y paso_actual = 02_TURNOS_Y_PROGRAMACION.';
    END IF;

    IF COALESCE(IS_USED_LOCK(v_lock_name), -1) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LOCK_MIGRACION_NO_VIGENTE: El paso 03 debe ejecutarse en la misma conexión que adquirió GET_LOCK en el paso 01.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME IN ('caja_fisica_guardia', 'sesion_caja', 'arqueo_caja', 'autorizacion_caja')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'OBJETOS_EXISTENTES_PASO03: Alguna tabla de sesiones, arqueos o autorizaciones ya existe en esta base.';
    END IF;

    -- =========================================================================
    -- C.1. TABLA `caja_fisica_guardia` (Fila ancla para serialización — Sección 11.2)
    -- =========================================================================
    CREATE TABLE `caja_fisica_guardia` (
      `id_caja_fisica` tinyint(4) NOT NULL DEFAULT 1,
      `codigo_caja` varchar(40) NOT NULL DEFAULT 'CAJA_UNICA_PRINCIPAL',
      `id_sesion_abierta_actual` int(11) DEFAULT NULL,
      `id_ultima_sesion_cerrada` int(11) DEFAULT NULL,
      `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id_caja_fisica`),
      UNIQUE KEY `uq_caja_fisica_codigo` (`codigo_caja`),
      CONSTRAINT `chk_caja_fisica_unica` CHECK (`id_caja_fisica` = 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    INSERT INTO `caja_fisica_guardia` (
        `id_caja_fisica`,
        `codigo_caja`,
        `id_sesion_abierta_actual`,
        `id_ultima_sesion_cerrada`
    ) VALUES (
        1,
        'CAJA_UNICA_PRINCIPAL',
        NULL,
        NULL
    );

    -- =========================================================================
    -- C.2. TABLA `sesion_caja` (Sesión operativa de caja — HU 11..HU 17)
    -- =========================================================================
    CREATE TABLE `sesion_caja` (
      `id_sesion_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_caja_fisica` tinyint(4) NOT NULL DEFAULT 1,
      `id_usuario_vendedor` int(11) NOT NULL,
      `id_turno` int(11) NOT NULL,
      `nombre_turno_referencia` varchar(60) NOT NULL,
      `hora_inicio_referencia` time NOT NULL,
      `hora_fin_referencia` time NOT NULL,
      `fecha_hora_salida_referencia` datetime NOT NULL,
      `es_suplencia` tinyint(1) NOT NULL DEFAULT 0,
      `abierta_fuera_horario` tinyint(1) NOT NULL DEFAULT 0,
      `fecha_hora_apertura` datetime NOT NULL DEFAULT current_timestamp(),
      `tiene_cierre_anterior` tinyint(1) NOT NULL DEFAULT 0,
      `id_sesion_cierre_anterior` int(11) DEFAULT NULL,
      `monto_referencia_cierre_anterior` decimal(10,2) DEFAULT NULL,
      `fecha_hora_cierre_anterior_ref` datetime DEFAULT NULL,
      `nombre_turno_cierre_anterior_ref` varchar(60) DEFAULT NULL,
      `saldo_inicial` decimal(10,2) NOT NULL,
      `diferencia_apertura` decimal(10,2) DEFAULT NULL,
      `total_ventas_efectivo` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_ventas_tarjeta` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_ventas_yape_plin` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_ingresos` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_egresos` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_retiros_omitidos_declarados` decimal(10,2) NOT NULL DEFAULT 0.00,
      `efectivo_esperado_actual` decimal(10,2) NOT NULL DEFAULT 0.00,
      `version_caja` int(11) NOT NULL DEFAULT 1,
      `estado` enum('ABIERTA','CERRADA') NOT NULL DEFAULT 'ABIERTA',
      `id_ultimo_arqueo` int(11) DEFAULT NULL,
      `id_arqueo_cierre` int(11) DEFAULT NULL,
      `fecha_hora_cierre` datetime DEFAULT NULL,
      `efectivo_esperado_cierre` decimal(10,2) DEFAULT NULL,
      `efectivo_contado_cierre` decimal(10,2) DEFAULT NULL,
      `diferencia_cierre` decimal(10,2) DEFAULT NULL,
      `observacion_cierre` varchar(500) DEFAULT NULL,
      `id_solicitud_apertura` int(11) DEFAULT NULL,
      `id_solicitud_cierre` int(11) DEFAULT NULL,
      `guardia_abierta_unica` tinyint(4) AS (if(`estado` = 'ABIERTA', 1, NULL)) PERSISTENT,
      PRIMARY KEY (`id_sesion_caja`),
      UNIQUE KEY `uq_sesion_caja_unica_abierta` (`guardia_abierta_unica`),
      UNIQUE KEY `uq_sesion_caja_arqueo_cierre` (`id_arqueo_cierre`),
      UNIQUE KEY `uq_sesion_caja_solicitud_apertura` (`id_solicitud_apertura`),
      UNIQUE KEY `uq_sesion_caja_solicitud_cierre` (`id_solicitud_cierre`),
      KEY `idx_sesion_estado_cierre_id` (`estado`, `fecha_hora_cierre`, `id_sesion_caja`),
      KEY `idx_sesion_vendedor_apertura` (`id_usuario_vendedor`, `fecha_hora_apertura`, `id_sesion_caja`),
      KEY `idx_sesion_turno_cierre` (`id_turno`, `fecha_hora_cierre`, `id_sesion_caja`),
      KEY `fk_sesion_caja_fisica` (`id_caja_fisica`),
      KEY `fk_sesion_caja_cierre_anterior` (`id_sesion_cierre_anterior`),
      KEY `fk_sesion_caja_ultimo_arqueo` (`id_ultimo_arqueo`),
      CONSTRAINT `fk_sesion_caja_fisica` FOREIGN KEY (`id_caja_fisica`) REFERENCES `caja_fisica_guardia` (`id_caja_fisica`),
      CONSTRAINT `fk_sesion_caja_vendedor` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_sesion_caja_turno` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`),
      CONSTRAINT `fk_sesion_caja_cierre_anterior` FOREIGN KEY (`id_sesion_cierre_anterior`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_sesion_caja_solicitud_apertura` FOREIGN KEY (`id_solicitud_apertura`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `fk_sesion_caja_solicitud_cierre` FOREIGN KEY (`id_solicitud_cierre`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_sesion_importes_no_negativos` CHECK (
        `saldo_inicial` >= 0.00
        and `total_ventas_efectivo` >= 0.00
        and `total_ventas_tarjeta` >= 0.00
        and `total_ventas_yape_plin` >= 0.00
        and `total_ingresos` >= 0.00
        and `total_egresos` >= 0.00
        and `total_retiros_omitidos_declarados` >= 0.00
        and `version_caja` >= 1
      ),
      CONSTRAINT `chk_sesion_formula_esperado_actual` CHECK (
        `efectivo_esperado_actual` = round(`saldo_inicial` + `total_ventas_efectivo` + `total_ingresos` - `total_egresos`, 2)
      ),
      CONSTRAINT `chk_sesion_cierre_anterior_montos` CHECK (
        (
          `tiene_cierre_anterior` = 0
          and `monto_referencia_cierre_anterior` is null
          and `fecha_hora_cierre_anterior_ref` is null
          and `nombre_turno_cierre_anterior_ref` is null
          and `diferencia_apertura` is null
        )
        or (
          `tiene_cierre_anterior` = 1
          and `monto_referencia_cierre_anterior` is not null
          and `monto_referencia_cierre_anterior` >= 0.00
          and `fecha_hora_cierre_anterior_ref` is not null
          and `nombre_turno_cierre_anterior_ref` is not null
          and `diferencia_apertura` = round(`saldo_inicial` - `monto_referencia_cierre_anterior`, 2)
        )
      ),
      CONSTRAINT `chk_sesion_estado_cierre_montos` CHECK (
        (
          `estado` = 'ABIERTA'
          and `fecha_hora_cierre` is null
          and `efectivo_esperado_cierre` is null
          and `efectivo_contado_cierre` is null
          and `diferencia_cierre` is null
          and `observacion_cierre` is null
        )
        or (
          `estado` = 'CERRADA'
          and `fecha_hora_cierre` is not null
          and `efectivo_esperado_cierre` is not null
          and `efectivo_contado_cierre` is not null
          and `efectivo_contado_cierre` >= 0.00
          and `diferencia_cierre` = round(`efectivo_contado_cierre` - `efectivo_esperado_cierre`, 2)
          and (`observacion_cierre` is null or char_length(trim(`observacion_cierre`)) <= 500)
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- F. TABLA `arqueo_caja` (Conteos físicos y versión evaluada — HU 15 y HU 16)
    -- =========================================================================
    CREATE TABLE `arqueo_caja` (
      `id_arqueo_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_sesion_caja` int(11) NOT NULL,
      `id_usuario_vendedor` int(11) NOT NULL,
      `version_caja_evaluada` int(11) NOT NULL,
      `monto_esperado` decimal(10,2) NOT NULL,
      `monto_contado` decimal(10,2) NOT NULL,
      `diferencia` decimal(10,2) NOT NULL,
      `observacion` varchar(500) DEFAULT NULL,
      `estado` enum('VIGENTE','REEMPLAZADO','DESACTUALIZADO') NOT NULL DEFAULT 'VIGENTE',
      `vigencia_activa` tinyint(1) DEFAULT 1,
      `causa_cambio_estado` enum('NUEVO_ARQUEO','OPERACION_CAJA_POSTERIOR') DEFAULT NULL,
      `fecha_hora_arqueo` datetime NOT NULL DEFAULT current_timestamp(),
      `fecha_hora_cambio_estado` datetime DEFAULT NULL,
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      PRIMARY KEY (`id_arqueo_caja`),
      UNIQUE KEY `uq_arqueo_caja_unico_vigente_sesion` (`id_sesion_caja`, `vigencia_activa`),
      UNIQUE KEY `uq_arqueo_caja_solicitud` (`id_solicitud_idempotencia`),
      KEY `idx_arqueo_sesion_estado_fecha` (`id_sesion_caja`, `estado`, `fecha_hora_arqueo`, `id_arqueo_caja`),
      KEY `fk_arqueo_caja_vendedor` (`id_usuario_vendedor`),
      CONSTRAINT `fk_arqueo_caja_sesion` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_arqueo_caja_vendedor` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_arqueo_caja_solicitud` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_arqueo_montos_y_diferencia` CHECK (
        `version_caja_evaluada` >= 1
        and `monto_contado` >= 0.00
        and `diferencia` = round(`monto_contado` - `monto_esperado`, 2)
      ),
      CONSTRAINT `chk_arqueo_observacion_condicional` CHECK (
        (
          `diferencia` = 0.00
          and (`observacion` is null or char_length(trim(`observacion`)) <= 500)
        )
        or (
          `diferencia` <> 0.00
          and `observacion` is not null
          and char_length(trim(`observacion`)) between 1 and 500
        )
      ),
      CONSTRAINT `chk_arqueo_estado_causa` CHECK (
        (
          `estado` = 'VIGENTE'
          and `vigencia_activa` = 1
          and `causa_cambio_estado` is null
          and `fecha_hora_cambio_estado` is null
        )
        or (
          `estado` = 'REEMPLAZADO'
          and `vigencia_activa` is null
          and `causa_cambio_estado` = 'NUEVO_ARQUEO'
          and `fecha_hora_cambio_estado` is not null
        )
        or (
          `estado` = 'DESACTUALIZADO'
          and `vigencia_activa` is null
          and `causa_cambio_estado` = 'OPERACION_CAJA_POSTERIOR'
          and `fecha_hora_cambio_estado` is not null
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Enlazar claves foráneas cruzadas entre `sesion_caja`, `arqueo_caja` y `caja_fisica_guardia`
    ALTER TABLE `sesion_caja`
      ADD CONSTRAINT `fk_sesion_caja_ultimo_arqueo` FOREIGN KEY (`id_ultimo_arqueo`) REFERENCES `arqueo_caja` (`id_arqueo_caja`),
      ADD CONSTRAINT `fk_sesion_caja_arqueo_cierre` FOREIGN KEY (`id_arqueo_cierre`) REFERENCES `arqueo_caja` (`id_arqueo_caja`);

    ALTER TABLE `caja_fisica_guardia`
      ADD CONSTRAINT `fk_guardia_sesion_abierta` FOREIGN KEY (`id_sesion_abierta_actual`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      ADD CONSTRAINT `fk_guardia_ultima_cerrada` FOREIGN KEY (`id_ultima_sesion_cerrada`) REFERENCES `sesion_caja` (`id_sesion_caja`);

    -- =========================================================================
    -- G. TABLA `autorizacion_caja` (Autorización administrativa de cierre — HU 17)
    -- =========================================================================
    CREATE TABLE `autorizacion_caja` (
      `id_autorizacion_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_sesion_caja` int(11) NOT NULL,
      `tipo_operacion` enum('CIERRE_ANTICIPADO') NOT NULL DEFAULT 'CIERRE_ANTICIPADO',
      `id_usuario_vendedor` int(11) NOT NULL,
      `id_usuario_admin` int(11) NOT NULL,
      `motivo` varchar(250) NOT NULL,
      `hora_salida_referencia` time NOT NULL,
      `fecha_hora_salida_referencia` datetime NOT NULL,
      `hora_cierre_real` datetime NOT NULL,
      `minutos_anticipacion` int(11) NOT NULL,
      `fecha_hora_autorizacion` datetime NOT NULL DEFAULT current_timestamp(),
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      PRIMARY KEY (`id_autorizacion_caja`),
      UNIQUE KEY `uq_autorizacion_sesion_unica` (`id_sesion_caja`),
      UNIQUE KEY `uq_autorizacion_sesion_operacion` (`id_sesion_caja`, `tipo_operacion`),
      UNIQUE KEY `uq_autorizacion_solicitud` (`id_solicitud_idempotencia`),
      KEY `idx_autorizacion_admin_fecha` (`id_usuario_admin`, `fecha_hora_autorizacion`),
      KEY `fk_autorizacion_vendedor` (`id_usuario_vendedor`),
      CONSTRAINT `fk_autorizacion_sesion` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_autorizacion_vendedor` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_autorizacion_admin` FOREIGN KEY (`id_usuario_admin`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_autorizacion_solicitud` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_autorizacion_motivo_y_anticipacion` CHECK (
        char_length(trim(`motivo`)) between 1 and 250
        and `minutos_anticipacion` > 15
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

-- =============================================================================
-- TRIGGERS DE INTEGRIDAD PARA `caja_fisica_guardia`, `sesion_caja`,
-- `arqueo_caja` Y `autorizacion_caja`
-- =============================================================================

CREATE TRIGGER `trg_caja_fisica_guardia_before_delete_prohibido`
BEFORE DELETE ON `caja_fisica_guardia`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'GUARDIA_CAJA_INMUTABLE: La fila única de caja_fisica_guardia no puede eliminarse.';
END$$

CREATE TRIGGER `trg_sesion_caja_before_insert_integridad`
BEFORE INSERT ON `sesion_caja`
FOR EACH ROW
BEGIN
    DECLARE v_rol_vendedor INT DEFAULT NULL;
    DECLARE v_estado_vendedor INT DEFAULT NULL;
    DECLARE v_estado_turno INT DEFAULT NULL;
    DECLARE v_sesiones_previas INT DEFAULT 0;
    DECLARE v_prev_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_prev_contado DECIMAL(10,2) DEFAULT NULL;

    IF NEW.id_caja_fisica <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CAJA_FISICA_UNICA: id_caja_fisica debe ser 1.';
    END IF;

    IF NEW.estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'APERTURA_ESTADO_INVALIDO: Toda sesión de caja debe insertarse inicialmente con estado = ABIERTA.';
    END IF;

    SELECT id_rol, estado
      INTO v_rol_vendedor, v_estado_vendedor
      FROM usuario
     WHERE id_usuario = NEW.id_usuario_vendedor
     LIMIT 1;

    IF v_rol_vendedor IS NULL OR v_rol_vendedor <> 2 OR v_estado_vendedor <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'FORBIDDEN_SELLER_ONLY: La apertura de caja solo puede registrarse para un usuario activo con rol Vendedor (id_rol = 2).';
    END IF;

    SELECT estado
      INTO v_estado_turno
      FROM turno
     WHERE id_turno = NEW.id_turno
     LIMIT 1;

    IF v_estado_turno IS NULL OR v_estado_turno <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INVALID_SHIFT: El turno seleccionado para abrir caja no existe o está inactivo.';
    END IF;

    -- Validación de referencia al cierre anterior vs primera apertura histórica
    SELECT COUNT(*)
      INTO v_sesiones_previas
      FROM sesion_caja
     WHERE estado = 'CERRADA';

    IF NEW.tiene_cierre_anterior = 0 THEN
        IF NEW.id_sesion_cierre_anterior IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_SIN_CIERRE_INCONSISTENTE: Cuando tiene_cierre_anterior = 0, id_sesion_cierre_anterior debe ser NULL.';
        END IF;

        IF v_sesiones_previas > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_REQUIERE_CIERRE_ANTERIOR: Ya existen sesiones cerradas; la nueva apertura debe referenciar el último cierre.';
        END IF;
    ELSE
        IF NEW.id_sesion_cierre_anterior IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_CIERRE_ANTERIOR_REQUERIDO: Cuando tiene_cierre_anterior = 1, debe informar id_sesion_cierre_anterior.';
        END IF;

        SELECT estado, efectivo_contado_cierre
          INTO v_prev_estado, v_prev_contado
          FROM sesion_caja
         WHERE id_sesion_caja = NEW.id_sesion_cierre_anterior
         LIMIT 1;

        IF v_prev_estado IS NULL OR v_prev_estado <> 'CERRADA' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_CIERRE_ANTERIOR_INVALIDO: La sesión referenciada en id_sesion_cierre_anterior debe estar CERRADA.';
        END IF;

        IF NEW.monto_referencia_cierre_anterior <> v_prev_contado THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_MONTO_REFERENCIA_INCONSISTENTE: monto_referencia_cierre_anterior debe coincidir con efectivo_contado_cierre de la sesión anterior.';
        END IF;
    END IF;

    IF NEW.id_ultimo_arqueo IS NOT NULL OR NEW.id_arqueo_cierre IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'APERTURA_ARQUEO_INICIAL_INVALIDO: Una sesión recién abierta no puede tener arqueos asociados en el INSERT inicial.';
    END IF;
END$$

CREATE TRIGGER `trg_sesion_caja_after_insert_guardia`
AFTER INSERT ON `sesion_caja`
FOR EACH ROW
BEGIN
    IF NEW.estado = 'ABIERTA' THEN
        UPDATE caja_fisica_guardia
           SET id_sesion_abierta_actual = NEW.id_sesion_caja
         WHERE id_caja_fisica = 1;
    END IF;
END$$

CREATE TRIGGER `trg_sesion_caja_before_update_integridad`
BEFORE UPDATE ON `sesion_caja`
FOR EACH ROW
BEGIN
    DECLARE v_arq_sesion INT DEFAULT NULL;
    DECLARE v_arq_estado VARCHAR(20) DEFAULT NULL;
    DECLARE v_arq_version INT DEFAULT NULL;
    DECLARE v_arq_esperado DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_contado DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_diferencia DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_obs VARCHAR(500) DEFAULT NULL;
    DECLARE v_autorizaciones INT DEFAULT 0;

    -- 1. Una sesión CERRADA está completamente congelada: se protegen las 36 columnas con comparación NULL-safe (<=>)
    IF OLD.estado = 'CERRADA' THEN
        IF NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
           OR NOT (OLD.id_caja_fisica <=> NEW.id_caja_fisica)
           OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
           OR NOT (OLD.id_turno <=> NEW.id_turno)
           OR NOT (OLD.nombre_turno_referencia <=> NEW.nombre_turno_referencia)
           OR NOT (OLD.hora_inicio_referencia <=> NEW.hora_inicio_referencia)
           OR NOT (OLD.hora_fin_referencia <=> NEW.hora_fin_referencia)
           OR NOT (OLD.fecha_hora_salida_referencia <=> NEW.fecha_hora_salida_referencia)
           OR NOT (OLD.es_suplencia <=> NEW.es_suplencia)
           OR NOT (OLD.abierta_fuera_horario <=> NEW.abierta_fuera_horario)
           OR NOT (OLD.fecha_hora_apertura <=> NEW.fecha_hora_apertura)
           OR NOT (OLD.tiene_cierre_anterior <=> NEW.tiene_cierre_anterior)
           OR NOT (OLD.id_sesion_cierre_anterior <=> NEW.id_sesion_cierre_anterior)
           OR NOT (OLD.monto_referencia_cierre_anterior <=> NEW.monto_referencia_cierre_anterior)
           OR NOT (OLD.fecha_hora_cierre_anterior_ref <=> NEW.fecha_hora_cierre_anterior_ref)
           OR NOT (OLD.nombre_turno_cierre_anterior_ref <=> NEW.nombre_turno_cierre_anterior_ref)
           OR NOT (OLD.saldo_inicial <=> NEW.saldo_inicial)
           OR NOT (OLD.diferencia_apertura <=> NEW.diferencia_apertura)
           OR NOT (OLD.total_ventas_efectivo <=> NEW.total_ventas_efectivo)
           OR NOT (OLD.total_ventas_tarjeta <=> NEW.total_ventas_tarjeta)
           OR NOT (OLD.total_ventas_yape_plin <=> NEW.total_ventas_yape_plin)
           OR NOT (OLD.total_ingresos <=> NEW.total_ingresos)
           OR NOT (OLD.total_egresos <=> NEW.total_egresos)
           OR NOT (OLD.total_retiros_omitidos_declarados <=> NEW.total_retiros_omitidos_declarados)
           OR NOT (OLD.efectivo_esperado_actual <=> NEW.efectivo_esperado_actual)
           OR NOT (OLD.version_caja <=> NEW.version_caja)
           OR NOT (OLD.estado <=> NEW.estado)
           OR NOT (OLD.id_ultimo_arqueo <=> NEW.id_ultimo_arqueo)
           OR NOT (OLD.id_arqueo_cierre <=> NEW.id_arqueo_cierre)
           OR NOT (OLD.fecha_hora_cierre <=> NEW.fecha_hora_cierre)
           OR NOT (OLD.efectivo_esperado_cierre <=> NEW.efectivo_esperado_cierre)
           OR NOT (OLD.efectivo_contado_cierre <=> NEW.efectivo_contado_cierre)
           OR NOT (OLD.diferencia_cierre <=> NEW.diferencia_cierre)
           OR NOT (OLD.observacion_cierre <=> NEW.observacion_cierre)
           OR NOT (OLD.id_solicitud_apertura <=> NEW.id_solicitud_apertura)
           OR NOT (OLD.id_solicitud_cierre <=> NEW.id_solicitud_cierre)
           OR OLD.estado = 'CERRADA' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Una sesión de caja CERRADA está completamente congelada y no admite reapertura ni modificaciones.';
        END IF;
    END IF;

    -- 2. Las 19 columnas de identidad, horario de referencia, cierre anterior y apertura son inmutables siempre (comparación NULL-safe <=>)
    IF NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
       OR NOT (OLD.id_caja_fisica <=> NEW.id_caja_fisica)
       OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
       OR NOT (OLD.id_turno <=> NEW.id_turno)
       OR NOT (OLD.nombre_turno_referencia <=> NEW.nombre_turno_referencia)
       OR NOT (OLD.hora_inicio_referencia <=> NEW.hora_inicio_referencia)
       OR NOT (OLD.hora_fin_referencia <=> NEW.hora_fin_referencia)
       OR NOT (OLD.fecha_hora_salida_referencia <=> NEW.fecha_hora_salida_referencia)
       OR NOT (OLD.es_suplencia <=> NEW.es_suplencia)
       OR NOT (OLD.abierta_fuera_horario <=> NEW.abierta_fuera_horario)
       OR NOT (OLD.fecha_hora_apertura <=> NEW.fecha_hora_apertura)
       OR NOT (OLD.tiene_cierre_anterior <=> NEW.tiene_cierre_anterior)
       OR NOT (OLD.id_sesion_cierre_anterior <=> NEW.id_sesion_cierre_anterior)
       OR NOT (OLD.monto_referencia_cierre_anterior <=> NEW.monto_referencia_cierre_anterior)
       OR NOT (OLD.fecha_hora_cierre_anterior_ref <=> NEW.fecha_hora_cierre_anterior_ref)
       OR NOT (OLD.nombre_turno_cierre_anterior_ref <=> NEW.nombre_turno_cierre_anterior_ref)
       OR NOT (OLD.saldo_inicial <=> NEW.saldo_inicial)
       OR NOT (OLD.diferencia_apertura <=> NEW.diferencia_apertura)
       OR NOT (OLD.id_solicitud_apertura <=> NEW.id_solicitud_apertura) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SESION_APERTURA_INMUTABLE: Los datos de identidad, turno, referencia anterior y apertura de la sesión no pueden modificarse.';
    END IF;

    IF NEW.version_caja < OLD.version_caja THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERSION_CAJA_REGRESIVA: version_caja no puede disminuir.';
    END IF;

    -- 3. Mientras está ABIERTA, todos los campos exclusivos de cierre deben permanecer NULL
    IF NEW.estado = 'ABIERTA' AND (
        NEW.id_arqueo_cierre IS NOT NULL
        OR NEW.fecha_hora_cierre IS NOT NULL
        OR NEW.efectivo_esperado_cierre IS NOT NULL
        OR NEW.efectivo_contado_cierre IS NOT NULL
        OR NEW.diferencia_cierre IS NOT NULL
        OR NEW.observacion_cierre IS NOT NULL
        OR NEW.id_solicitud_cierre IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CIERRE_INCOMPLETO: Los campos de cierre solo pueden asignarse al cerrar la sesión (estado = CERRADA).';
    END IF;

    -- 4. Al pasar de ABIERTA a CERRADA (EP-20):
    --    - No alterar acumulados operativos en la sentencia de cierre
    --    - Validar arqueo VIGENTE contra OLD.version_caja (versión previa al cierre)
    --    - Exigir incremento exacto NEW.version_caja = OLD.version_caja + 1
    --    - Congelar importes, observación y validar tolerancia de 15 minutos
    IF OLD.estado = 'ABIERTA' AND NEW.estado = 'CERRADA' THEN
        IF NOT (OLD.total_ventas_efectivo <=> NEW.total_ventas_efectivo)
           OR NOT (OLD.total_ventas_tarjeta <=> NEW.total_ventas_tarjeta)
           OR NOT (OLD.total_ventas_yape_plin <=> NEW.total_ventas_yape_plin)
           OR NOT (OLD.total_ingresos <=> NEW.total_ingresos)
           OR NOT (OLD.total_egresos <=> NEW.total_egresos)
           OR NOT (OLD.total_retiros_omitidos_declarados <=> NEW.total_retiros_omitidos_declarados)
           OR NOT (OLD.efectivo_esperado_actual <=> NEW.efectivo_esperado_actual) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CIERRE_ACUMULADOS_INMUTABLES: La operación de cierre no puede alterar los acumulados operativos de la sesión.';
        END IF;

        IF NEW.id_arqueo_cierre IS NULL OR NEW.id_ultimo_arqueo IS NULL
           OR NOT (NEW.id_arqueo_cierre <=> OLD.id_ultimo_arqueo)
           OR NOT (NEW.id_ultimo_arqueo <=> OLD.id_ultimo_arqueo) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CASH_COUNT_REQUIRED: Para cerrar la caja debe existir un último arqueo válido de la sesión y coincidir con id_arqueo_cierre.';
        END IF;

        SELECT id_sesion_caja, estado, version_caja_evaluada, monto_esperado, monto_contado, diferencia, observacion
          INTO v_arq_sesion, v_arq_estado, v_arq_version, v_arq_esperado, v_arq_contado, v_arq_diferencia, v_arq_obs
          FROM arqueo_caja
         WHERE id_arqueo_caja = NEW.id_arqueo_cierre
         LIMIT 1;

        IF v_arq_sesion IS NULL OR v_arq_sesion <> NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CASH_COUNT_REQUIRED: El arqueo de cierre no pertenece a esta sesión de caja.';
        END IF;

        IF v_arq_estado <> 'VIGENTE' OR v_arq_version <> OLD.version_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CASH_COUNT_OUTDATED: El arqueo referenciado ya no está VIGENTE o su version_caja_evaluada no coincide con la versión de la sesión antes del cierre.';
        END IF;

        IF NEW.version_caja <> OLD.version_caja + 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CIERRE_VERSION_INCREMENTO_REQUERIDO: El cierre de caja debe incrementar exactamente en +1 la version_caja de la sesión (EP-20).';
        END IF;

        IF NOT (NEW.efectivo_esperado_cierre <=> NEW.efectivo_esperado_actual)
           OR NOT (NEW.efectivo_esperado_cierre <=> v_arq_esperado)
           OR NOT (NEW.efectivo_contado_cierre <=> v_arq_contado)
           OR NOT (NEW.diferencia_cierre <=> v_arq_diferencia) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CIERRE_MONTOS_NO_CONGELADOS: Los montos congelados de cierre deben coincidir exactamente con el arqueo VIGENTE y el efectivo esperado actual.';
        END IF;

        IF NEW.observacion_cierre IS NULL AND v_arq_obs IS NOT NULL THEN
            SET NEW.observacion_cierre = v_arq_obs;
        END IF;

        IF NOT (NEW.observacion_cierre <=> v_arq_obs) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CIERRE_OBSERVACION_INCONSISTENTE: observacion_cierre debe coincidir exactamente con la observación del arqueo de cierre.';
        END IF;

        -- Verificar tolerancia de 15 minutos antes de fecha_hora_salida_referencia (HU 17)
        IF NEW.fecha_hora_cierre < DATE_SUB(NEW.fecha_hora_salida_referencia, INTERVAL 15 MINUTE) THEN
            SELECT COUNT(*)
              INTO v_autorizaciones
              FROM autorizacion_caja
             WHERE id_sesion_caja = NEW.id_sesion_caja
               AND tipo_operacion = 'CIERRE_ANTICIPADO';

            IF v_autorizaciones = 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'EARLY_CLOSE_AUTH_REQUIRED: El cierre ocurre más de 15 minutos antes de la salida de referencia y requiere autorización administrativa registrada.';
            END IF;
        END IF;
    END IF;
END$$

CREATE TRIGGER `trg_sesion_caja_after_update_guardia`
AFTER UPDATE ON `sesion_caja`
FOR EACH ROW
BEGIN
    IF OLD.estado = 'ABIERTA' AND NEW.estado = 'CERRADA' THEN
        UPDATE caja_fisica_guardia
           SET id_sesion_abierta_actual = NULL,
               id_ultima_sesion_cerrada = NEW.id_sesion_caja
         WHERE id_caja_fisica = 1;
    END IF;
END$$

CREATE TRIGGER `trg_sesion_caja_before_delete_prohibido`
BEFORE DELETE ON `sesion_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_SESION_INMUTABLE: Las sesiones de caja no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_arqueo_caja_before_insert_integridad`
BEFORE INSERT ON `arqueo_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_sesion_version INT DEFAULT NULL;
    DECLARE v_sesion_esperado DECIMAL(10,2) DEFAULT NULL;

    SELECT estado, id_usuario_vendedor, version_caja, efectivo_esperado_actual
      INTO v_sesion_estado, v_sesion_vendedor, v_sesion_version, v_sesion_esperado
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Solo se puede registrar un arqueo sobre una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_vendedor <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: Solo el vendedor titular de la sesión abierta puede registrar un arqueo.';
    END IF;

    IF NEW.estado <> 'VIGENTE' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ARQUEO_ESTADO_INICIAL_INVALIDO: Todo nuevo arqueo debe insertarse con estado = VIGENTE.';
    END IF;

    IF NEW.version_caja_evaluada <> v_sesion_version THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ARQUEO_VERSION_INCONSISTENTE: version_caja_evaluada debe coincidir con version_caja actual de la sesión.';
    END IF;

    IF NEW.monto_esperado <> v_sesion_esperado THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ARQUEO_MONTO_ESPERADO_INCONSISTENTE: monto_esperado debe coincidir con efectivo_esperado_actual de la sesión.';
    END IF;

    SET NEW.diferencia = ROUND(NEW.monto_contado - NEW.monto_esperado, 2);
    SET NEW.vigencia_activa = 1;
    SET NEW.causa_cambio_estado = NULL;
    SET NEW.fecha_hora_cambio_estado = NULL;
END$$

CREATE TRIGGER `trg_arqueo_caja_before_update_integridad`
BEFORE UPDATE ON `arqueo_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;

    SELECT estado
      INTO v_sesion_estado
      FROM sesion_caja
     WHERE id_sesion_caja = OLD.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado = 'CERRADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'HISTORIAL_ARQUEO_INMUTABLE: Los arqueos de una sesión de caja CERRADA están congelados y no admiten cambios.';
    END IF;

    IF NOT (OLD.id_arqueo_caja <=> NEW.id_arqueo_caja)
       OR NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
       OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
       OR NOT (OLD.version_caja_evaluada <=> NEW.version_caja_evaluada)
       OR NOT (OLD.monto_esperado <=> NEW.monto_esperado)
       OR NOT (OLD.monto_contado <=> NEW.monto_contado)
       OR NOT (OLD.diferencia <=> NEW.diferencia)
       OR NOT (OLD.observacion <=> NEW.observacion)
       OR NOT (OLD.fecha_hora_arqueo <=> NEW.fecha_hora_arqueo)
       OR NOT (OLD.id_solicitud_idempotencia <=> NEW.id_solicitud_idempotencia) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'HISTORIAL_ARQUEO_INMUTABLE: Los importes, versión, observación y marcas de tiempo de un arqueo no pueden editarse.';
    END IF;

    IF OLD.estado <> 'VIGENTE' AND NEW.estado <> OLD.estado THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'HISTORIAL_ARQUEO_INMUTABLE: Un arqueo REEMPLAZADO o DESACTUALIZADO no puede volver a cambiar de estado.';
    END IF;

    IF OLD.estado = 'VIGENTE' AND NEW.estado IN ('REEMPLAZADO', 'DESACTUALIZADO') THEN
        SET NEW.vigencia_activa = NULL;
        IF NEW.fecha_hora_cambio_estado IS NULL THEN
            SET NEW.fecha_hora_cambio_estado = NOW();
        END IF;
        IF NEW.causa_cambio_estado IS NULL THEN
            SET NEW.causa_cambio_estado = IF(NEW.estado = 'REEMPLAZADO', 'NUEVO_ARQUEO', 'OPERACION_CAJA_POSTERIOR');
        END IF;
    ELSE
        SET NEW.vigencia_activa = IF(NEW.estado = 'VIGENTE', 1, NULL);
    END IF;
END$$

CREATE TRIGGER `trg_arqueo_caja_before_delete_prohibido`
BEFORE DELETE ON `arqueo_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_ARQUEO_INMUTABLE: Los arqueos de caja se conservan en el historial y no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_autorizacion_caja_before_insert_integridad`
BEFORE INSERT ON `autorizacion_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_sesion_hora_salida TIME DEFAULT NULL;
    DECLARE v_sesion_fecha_salida DATETIME DEFAULT NULL;
    DECLARE v_rol_admin INT DEFAULT NULL;
    DECLARE v_estado_admin INT DEFAULT NULL;

    SELECT estado, id_usuario_vendedor, hora_fin_referencia, fecha_hora_salida_referencia
      INTO v_sesion_estado, v_sesion_vendedor, v_sesion_hora_salida, v_sesion_fecha_salida
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: La autorización de cierre anticipado requiere una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_vendedor <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: id_usuario_vendedor en autorizacion_caja debe coincidir con el titular de la sesión.';
    END IF;

    SELECT id_rol, estado
      INTO v_rol_admin, v_estado_admin
      FROM usuario
     WHERE id_usuario = NEW.id_usuario_admin
     LIMIT 1;

    IF v_rol_admin IS NULL OR v_rol_admin <> 1 OR v_estado_admin <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INVALID_ADMIN_AUTHORIZATION: El autorizador debe ser un usuario activo con rol Administrador (id_rol = 1).';
    END IF;

    IF NEW.fecha_hora_salida_referencia <> v_sesion_fecha_salida
       OR NEW.hora_salida_referencia <> v_sesion_hora_salida THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'AUTORIZACION_SALIDA_INCONSISTENTE: La hora/fecha de salida de referencia debe coincidir con el snapshot de la sesión.';
    END IF;
END$$

CREATE TRIGGER `trg_autorizacion_caja_before_update_inmutable`
BEFORE UPDATE ON `autorizacion_caja`
FOR EACH ROW
BEGIN
    IF NOT (OLD.id_autorizacion_caja <=> NEW.id_autorizacion_caja)
       OR NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
       OR NOT (OLD.tipo_operacion <=> NEW.tipo_operacion)
       OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
       OR NOT (OLD.id_usuario_admin <=> NEW.id_usuario_admin)
       OR NOT (OLD.motivo <=> NEW.motivo)
       OR NOT (OLD.hora_salida_referencia <=> NEW.hora_salida_referencia)
       OR NOT (OLD.fecha_hora_salida_referencia <=> NEW.fecha_hora_salida_referencia)
       OR NOT (OLD.hora_cierre_real <=> NEW.hora_cierre_real)
       OR NOT (OLD.minutos_anticipacion <=> NEW.minutos_anticipacion)
       OR NOT (OLD.fecha_hora_autorizacion <=> NEW.fecha_hora_autorizacion)
       OR NOT (OLD.id_solicitud_idempotencia <=> NEW.id_solicitud_idempotencia) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'AUTORIZACION_INMUTABLE: Los registros de autorizacion_caja son evidencia de auditoría y no pueden modificarse.';
    END IF;
END$$

CREATE TRIGGER `trg_autorizacion_caja_before_delete_prohibido`
BEFORE DELETE ON `autorizacion_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'AUTORIZACION_INMUTABLE: Los registros de autorizacion_caja no pueden eliminarse.';
END$$

-- =============================================================================
-- CIERRE DEL PASO 03: VERIFICACIÓN DE OBJETOS Y TRIGGERS ANTES DE AVANZAR PASO
-- =============================================================================
BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_tablas_ok INT DEFAULT 0;
    DECLARE v_guardia_ok INT DEFAULT 0;
    DECLARE v_fks_ok INT DEFAULT 0;
    DECLARE v_triggers_ok INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    SELECT COUNT(*)
      INTO v_tablas_ok
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME IN ('caja_fisica_guardia', 'sesion_caja', 'arqueo_caja', 'autorizacion_caja');

    SELECT COUNT(*)
      INTO v_guardia_ok
      FROM `caja_fisica_guardia`
     WHERE `id_caja_fisica` = 1;

    SELECT COUNT(*)
      INTO v_fks_ok
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = v_db_actual
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND TABLE_NAME IN ('caja_fisica_guardia', 'sesion_caja', 'arqueo_caja', 'autorizacion_caja');

    SELECT COUNT(*)
      INTO v_triggers_ok
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual
       AND TRIGGER_NAME IN (
           'trg_caja_fisica_guardia_before_delete_prohibido',
           'trg_sesion_caja_before_insert_integridad',
           'trg_sesion_caja_after_insert_guardia',
           'trg_sesion_caja_before_update_integridad',
           'trg_sesion_caja_after_update_guardia',
           'trg_sesion_caja_before_delete_prohibido',
           'trg_arqueo_caja_before_insert_integridad',
           'trg_arqueo_caja_before_update_integridad',
           'trg_arqueo_caja_before_delete_prohibido',
           'trg_autorizacion_caja_before_insert_integridad',
           'trg_autorizacion_caja_before_update_inmutable',
           'trg_autorizacion_caja_before_delete_prohibido'
       );

    IF v_tablas_ok <> 4 OR v_guardia_ok <> 1 OR v_fks_ok <> 17 OR v_triggers_ok <> 12 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO03_INCOMPLETA: No se crearon todas las tablas (4), fila guardia (1), FKs (17) o triggers (12) del paso 03.';
    END IF;

    UPDATE `sprint3_migracion_control`
       SET `paso_actual` = '03_SESIONES_ARQUEOS_AUTORIZACIONES'
     WHERE `id_migracion` = 3
       AND `estado` = 'EN_PROGRESO'
       AND `paso_actual` = '02_TURNOS_Y_PROGRAMACION';
END$$

DELIMITER ;

-- STEP15_AFTER_03

-- ORIGEN 04_estructura_movimientos_correcciones_y_ventas_sprint3.sql SHA256 70f2bc4d87a2e80aae848f8b83246627f6625e0a18b650f1792b436ff526a38e
-- =============================================================================
-- ARCHIVO: 04_estructura_movimientos_correcciones_y_ventas_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 04 de 06 (Requiere 03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Implementar los dominios H (Vinculación de ventas con sesión de caja),
--   D (Movimientos de caja inmutables) y E (Cadena de correcciones sin mutar el original):
--     1. Ampliar `venta` con `id_sesion_caja INT(11) DEFAULT NULL` e índice/FK hacia
--        `sesion_caja(id_sesion_caja)`, preservando intactas las 250 ventas
--        históricas con `id_sesion_caja = NULL` sin crear sesiones ficticias.
--     2. Crear `movimiento_caja` con los 7 tipos exactos del contrato:
--        `VENTA_EFECTIVO`, `INGRESO_MANUAL`, `INYECCION_ADMIN`, `SALIDA_CAJA`,
--        `RETIRO_ADMIN`, `RETIRO_OMITIDO` y `CORRECCION`.
--     3. Crear `correccion_movimiento_caja` vinculada inequívocamente tanto al
--        movimiento original (`id_movimiento_original`) como al movimiento
--        compensatorio (`id_movimiento_correccion` UNIQUE) y al eslabón previo
--        (`id_correccion_previa` UNIQUE), sin dependencias circulares de FK ni
--        necesidad de desactivar `FOREIGN_KEY_CHECKS`.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_lock_name VARCHAR(64);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market')
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 04.';
    END IF;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 a 03.';
    END IF;

    SELECT estado, paso_actual
      INTO v_estado_migracion, v_paso_migracion
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion = 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base.';
    END IF;

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '03_SESIONES_ARQUEOS_AUTORIZACIONES' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 04 exige estado EN_PROGRESO y paso_actual = 03_SESIONES_ARQUEOS_AUTORIZACIONES.';
    END IF;

    IF COALESCE(IS_USED_LOCK(v_lock_name), -1) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LOCK_MIGRACION_NO_VIGENTE: El paso 04 debe ejecutarse en la misma conexión que adquirió GET_LOCK en el paso 01.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'venta'
           AND COLUMN_NAME = 'id_sesion_caja'
    ) OR EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME IN ('movimiento_caja', 'correccion_movimiento_caja')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'OBJETOS_EXISTENTES_PASO04: La columna venta.id_sesion_caja o las tablas de movimientos ya existen.';
    END IF;

    -- =========================================================================
    -- H. AMPLIACIÓN DE `venta` CON `id_sesion_caja` NULLABLE (HU 12 / Sección 7.7)
    -- =========================================================================
    ALTER TABLE `venta`
      ADD COLUMN `id_sesion_caja` int(11) DEFAULT NULL AFTER `id_usuario`,
      ADD KEY `idx_venta_sesion_caja` (`id_sesion_caja`),
      ADD CONSTRAINT `fk_venta_sesion_caja` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`);

    -- =========================================================================
    -- D. TABLA `movimiento_caja` (Movimientos inmutables — HU 12, HU 13 y HU 14)
    -- =========================================================================
    CREATE TABLE `movimiento_caja` (
      `id_movimiento_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_sesion_caja` int(11) NOT NULL,
      `tipo_movimiento` enum(
        'VENTA_EFECTIVO',
        'INGRESO_MANUAL',
        'INYECCION_ADMIN',
        'SALIDA_CAJA',
        'RETIRO_ADMIN',
        'RETIRO_OMITIDO',
        'CORRECCION'
      ) NOT NULL,
      `monto` decimal(10,2) NOT NULL,
      `efecto_saldo` decimal(10,2) NOT NULL,
      `motivo` varchar(250) NOT NULL,
      `referencia_visible` varchar(300) DEFAULT NULL,
      `id_usuario_registro` int(11) NOT NULL,
      `id_usuario_admin` int(11) DEFAULT NULL,
      `id_venta` int(11) DEFAULT NULL,
      `id_sesion_origen_omitido` int(11) DEFAULT NULL,
      `declarado_ocurrio_en_sesion_origen_abierta` tinyint(1) DEFAULT NULL,
      `confirmado_reflejado_en_apertura` tinyint(1) DEFAULT NULL,
      `id_movimiento_original` int(11) DEFAULT NULL,
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      `fecha_hora_registro` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id_movimiento_caja`),
      UNIQUE KEY `uq_movimiento_caja_venta` (`id_venta`),
      UNIQUE KEY `uq_movimiento_caja_solicitud` (`id_solicitud_idempotencia`),
      KEY `idx_mov_sesion_fecha_id` (`id_sesion_caja`, `fecha_hora_registro`, `id_movimiento_caja`),
      KEY `idx_mov_sesion_tipo` (`id_sesion_caja`, `tipo_movimiento`),
      KEY `idx_mov_original` (`id_movimiento_original`),
      KEY `idx_mov_sesion_origen_omitido` (`id_sesion_origen_omitido`),
      KEY `fk_mov_usuario_registro` (`id_usuario_registro`),
      KEY `fk_mov_usuario_admin` (`id_usuario_admin`),
      CONSTRAINT `fk_mov_sesion_caja` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_mov_usuario_registro` FOREIGN KEY (`id_usuario_registro`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_mov_usuario_admin` FOREIGN KEY (`id_usuario_admin`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_mov_venta` FOREIGN KEY (`id_venta`) REFERENCES `venta` (`id_venta`),
      CONSTRAINT `fk_mov_sesion_origen_omitido` FOREIGN KEY (`id_sesion_origen_omitido`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_mov_movimiento_original` FOREIGN KEY (`id_movimiento_original`) REFERENCES `movimiento_caja` (`id_movimiento_caja`),
      CONSTRAINT `fk_mov_solicitud_idemp` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_mov_monto_y_motivo` CHECK (
        `monto` > 0.00
        and char_length(trim(`motivo`)) between 1 and 250
      ),
      CONSTRAINT `chk_mov_efecto_y_declaraciones` CHECK (
        (
          `tipo_movimiento` in ('VENTA_EFECTIVO', 'INGRESO_MANUAL', 'INYECCION_ADMIN')
          and `efecto_saldo` = `monto`
          and `declarado_ocurrio_en_sesion_origen_abierta` is null
          and `confirmado_reflejado_en_apertura` is null
        )
        or (
          `tipo_movimiento` in ('SALIDA_CAJA', 'RETIRO_ADMIN')
          and `efecto_saldo` = -`monto`
          and `declarado_ocurrio_en_sesion_origen_abierta` is null
          and `confirmado_reflejado_en_apertura` is null
        )
        or (
          `tipo_movimiento` = 'RETIRO_OMITIDO'
          and `efecto_saldo` = 0.00
          and `declarado_ocurrio_en_sesion_origen_abierta` = 1
          and `confirmado_reflejado_en_apertura` = 1
        )
        or (
          `tipo_movimiento` = 'CORRECCION'
          and `efecto_saldo` <> 0.00
          and abs(`efecto_saldo`) = `monto`
          and `declarado_ocurrio_en_sesion_origen_abierta` is null
          and `confirmado_reflejado_en_apertura` is null
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- E. TABLA `correccion_movimiento_caja` (Historial encadenado — HU 13 y HU 14)
    -- =========================================================================
    CREATE TABLE `correccion_movimiento_caja` (
      `id_correccion_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_sesion_caja` int(11) NOT NULL,
      `id_movimiento_original` int(11) NOT NULL,
      `id_movimiento_correccion` int(11) NOT NULL,
      `id_correccion_previa` int(11) DEFAULT NULL,
      `numero_eslabon` int(11) NOT NULL DEFAULT 1,
      `monto_original` decimal(10,2) NOT NULL,
      `monto_vigente_previo` decimal(10,2) NOT NULL,
      `monto_corregido` decimal(10,2) NOT NULL,
      `efecto_saldo` decimal(10,2) NOT NULL,
      `motivo` varchar(250) NOT NULL,
      `id_usuario_registro` int(11) NOT NULL,
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      `fecha_hora_registro` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id_correccion_caja`),
      UNIQUE KEY `uq_correccion_movimiento_ajuste` (`id_movimiento_correccion`),
      UNIQUE KEY `uq_correccion_previa_unica` (`id_correccion_previa`),
      UNIQUE KEY `uq_correccion_original_eslabon` (`id_movimiento_original`, `numero_eslabon`),
      UNIQUE KEY `uq_correccion_solicitud` (`id_solicitud_idempotencia`),
      KEY `idx_correccion_sesion_original` (`id_sesion_caja`, `id_movimiento_original`, `numero_eslabon`),
      KEY `fk_correccion_usuario_registro` (`id_usuario_registro`),
      CONSTRAINT `fk_correccion_sesion_caja` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_correccion_movimiento_original` FOREIGN KEY (`id_movimiento_original`) REFERENCES `movimiento_caja` (`id_movimiento_caja`),
      CONSTRAINT `fk_correccion_movimiento_ajuste` FOREIGN KEY (`id_movimiento_correccion`) REFERENCES `movimiento_caja` (`id_movimiento_caja`),
      CONSTRAINT `fk_correccion_eslabon_previo` FOREIGN KEY (`id_correccion_previa`) REFERENCES `correccion_movimiento_caja` (`id_correccion_caja`),
      CONSTRAINT `fk_correccion_usuario_registro` FOREIGN KEY (`id_usuario_registro`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_correccion_solicitud_idemp` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_correccion_importes_y_motivo` CHECK (
        `numero_eslabon` >= 1
        and `monto_original` > 0.00
        and `monto_vigente_previo` > 0.00
        and `monto_corregido` > 0.00
        and `monto_corregido` <> `monto_vigente_previo`
        and `efecto_saldo` <> 0.00
        and abs(`efecto_saldo`) = abs(round(`monto_corregido` - `monto_vigente_previo`, 2))
        and char_length(trim(`motivo`)) between 1 and 250
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

-- =============================================================================
-- TRIGGERS DE INTEGRIDAD PARA `movimiento_caja` Y `correccion_movimiento_caja`
-- =============================================================================

CREATE TRIGGER `trg_movimiento_caja_before_insert_integridad`
BEFORE INSERT ON `movimiento_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_rol_admin INT DEFAULT NULL;
    DECLARE v_estado_admin INT DEFAULT NULL;
    DECLARE v_venta_sesion INT DEFAULT NULL;
    DECLARE v_venta_metodo INT DEFAULT NULL;
    DECLARE v_venta_estado VARCHAR(20) DEFAULT NULL;
    DECLARE v_venta_total DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_origen_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_orig_sesion INT DEFAULT NULL;
    DECLARE v_orig_tipo VARCHAR(32) DEFAULT NULL;

    -- 1. La sesión destino debe existir, estar ABIERTA y pertenecer al vendedor que registra
    SELECT estado, id_usuario_vendedor
      INTO v_sesion_estado, v_sesion_vendedor
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Solo se pueden registrar movimientos o correcciones sobre una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_registro <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: Solo el vendedor titular de la sesión abierta puede registrar movimientos en ella.';
    END IF;

    -- 2. Reglas específicas según tipo_movimiento
    IF NEW.tipo_movimiento = 'VENTA_EFECTIVO' THEN
        IF NEW.id_venta IS NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_INVALIDO: VENTA_EFECTIVO exige id_venta no nulo y prohíbe campos de admin, sesión origen o corrección.';
        END IF;

        SELECT id_sesion_caja, id_metodo_pago, estado, total
          INTO v_venta_sesion, v_venta_metodo, v_venta_estado, v_venta_total
          FROM venta
         WHERE id_venta = NEW.id_venta
         LIMIT 1;

        IF v_venta_sesion IS NULL OR v_venta_sesion <> NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_SESION_INCONSISTENTE: La venta vinculada debe pertenecer a la misma sesión de caja.';
        END IF;

        IF v_venta_metodo <> 1 OR v_venta_estado <> 'COMPLETADA' OR v_venta_total <> NEW.monto THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_METODO_O_MONTO_INVALIDO: VENTA_EFECTIVO requiere una venta COMPLETADA con método Efectivo (1) y monto idéntico al total.';
        END IF;

    ELSEIF NEW.tipo_movimiento IN ('INGRESO_MANUAL', 'SALIDA_CAJA') THEN
        IF NEW.id_venta IS NOT NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_OPERATIVO_INVALIDO: INGRESO_MANUAL y SALIDA_CAJA no admiten id_venta, id_usuario_admin, sesión origen ni movimiento original.';
        END IF;

    ELSEIF NEW.tipo_movimiento IN ('INYECCION_ADMIN', 'RETIRO_ADMIN') THEN
        IF NEW.id_usuario_admin IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'ADMIN_AUTHORIZATION_REQUIRED: INYECCION_ADMIN y RETIRO_ADMIN exigen id_usuario_admin y no admiten venta, sesión origen ni corrección.';
        END IF;

        SELECT id_rol, estado
          INTO v_rol_admin, v_estado_admin
          FROM usuario
         WHERE id_usuario = NEW.id_usuario_admin
         LIMIT 1;

        IF v_rol_admin IS NULL OR v_rol_admin <> 1 OR v_estado_admin <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_ADMIN_USER: id_usuario_admin debe corresponder a un Administrador activo (id_rol = 1).';
        END IF;

    ELSEIF NEW.tipo_movimiento = 'RETIRO_OMITIDO' THEN
        IF NEW.id_sesion_origen_omitido IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: RETIRO_OMITIDO exige id_sesion_origen_omitido y las dos declaraciones obligatorias.';
        END IF;

        IF NEW.id_sesion_origen_omitido = NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: La sesión de origen del retiro omitido debe ser distinta de la sesión actual.';
        END IF;

        SELECT estado
          INTO v_origen_estado
          FROM sesion_caja
         WHERE id_sesion_caja = NEW.id_sesion_origen_omitido
         LIMIT 1;

        IF v_origen_estado IS NULL OR v_origen_estado <> 'CERRADA' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: La sesión de origen de un retiro omitido debe existir y estar CERRADA.';
        END IF;

        IF NEW.id_usuario_admin IS NOT NULL THEN
            SELECT id_rol, estado
              INTO v_rol_admin, v_estado_admin
              FROM usuario
             WHERE id_usuario = NEW.id_usuario_admin
             LIMIT 1;

            IF v_rol_admin IS NULL OR v_rol_admin <> 1 OR v_estado_admin <> 1 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'INVALID_ADMIN_USER: Cuando se informa id_usuario_admin en RETIRO_OMITIDO, debe ser un Administrador activo (id_rol = 1).';
            END IF;
        END IF;

    ELSEIF NEW.tipo_movimiento = 'CORRECCION' THEN
        IF NEW.id_movimiento_original IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: Un movimiento de tipo CORRECCION exige id_movimiento_original.';
        END IF;

        SELECT id_sesion_caja, tipo_movimiento
          INTO v_orig_sesion, v_orig_tipo
          FROM movimiento_caja
         WHERE id_movimiento_caja = NEW.id_movimiento_original
         LIMIT 1;

        IF v_orig_sesion IS NULL OR v_orig_sesion <> NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: El movimiento original a corregir debe pertenecer a la misma sesión de caja abierta.';
        END IF;

        IF v_orig_tipo NOT IN ('INGRESO_MANUAL', 'INYECCION_ADMIN', 'SALIDA_CAJA', 'RETIRO_ADMIN') THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: Solo se admiten correcciones sobre INGRESO_MANUAL, INYECCION_ADMIN, SALIDA_CAJA o RETIRO_ADMIN.';
        END IF;
    END IF;
END$$

CREATE TRIGGER `trg_movimiento_caja_before_update_inmutable`
BEFORE UPDATE ON `movimiento_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'MOVIMIENTO_CAJA_INMUTABLE: Los movimientos de caja no pueden editarse; registre una corrección encadenada.';
END$$

CREATE TRIGGER `trg_movimiento_caja_before_delete_prohibido`
BEFORE DELETE ON `movimiento_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'MOVIMIENTO_CAJA_INMUTABLE: Los movimientos de caja no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_correccion_movimiento_caja_before_insert_integridad`
BEFORE INSERT ON `correccion_movimiento_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_orig_sesion INT DEFAULT NULL;
    DECLARE v_orig_tipo VARCHAR(32) DEFAULT NULL;
    DECLARE v_orig_monto DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_corr_sesion INT DEFAULT NULL;
    DECLARE v_corr_tipo VARCHAR(32) DEFAULT NULL;
    DECLARE v_corr_orig_ref INT DEFAULT NULL;
    DECLARE v_corr_monto DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_corr_efecto DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_ult_id_correccion INT DEFAULT NULL;
    DECLARE v_ult_eslabon INT DEFAULT 0;
    DECLARE v_ult_monto_corregido DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_efecto_esperado DECIMAL(10,2) DEFAULT NULL;

    IF NEW.id_movimiento_original = NEW.id_movimiento_correccion THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CORRECCION_REFERENCIA_CIRCULAR: id_movimiento_original e id_movimiento_correccion deben ser distintos.';
    END IF;

    SELECT estado, id_usuario_vendedor
      INTO v_sesion_estado, v_sesion_vendedor
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Solo se pueden registrar correcciones sobre una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_registro <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: Solo el vendedor titular de la sesión abierta puede registrar correcciones.';
    END IF;

    -- Validar el movimiento original
    SELECT id_sesion_caja, tipo_movimiento, monto
      INTO v_orig_sesion, v_orig_tipo, v_orig_monto
      FROM movimiento_caja
     WHERE id_movimiento_caja = NEW.id_movimiento_original
     LIMIT 1;

    IF v_orig_sesion IS NULL OR v_orig_sesion <> NEW.id_sesion_caja THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: El movimiento original no pertenece a esta sesión de caja.';
    END IF;

    IF v_orig_tipo NOT IN ('INGRESO_MANUAL', 'INYECCION_ADMIN', 'SALIDA_CAJA', 'RETIRO_ADMIN') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: El tipo del movimiento original no admite corrección.';
    END IF;

    IF NEW.monto_original <> v_orig_monto THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CORRECCION_MONTO_ORIGINAL_INCONSISTENTE: monto_original debe coincidir con el importe inmutable del movimiento original.';
    END IF;

    -- Validar el movimiento compensatorio (tipo CORRECCION)
    SELECT id_sesion_caja, tipo_movimiento, id_movimiento_original, monto, efecto_saldo
      INTO v_corr_sesion, v_corr_tipo, v_corr_orig_ref, v_corr_monto, v_corr_efecto
      FROM movimiento_caja
     WHERE id_movimiento_caja = NEW.id_movimiento_correccion
     LIMIT 1;

    IF v_corr_sesion IS NULL OR v_corr_sesion <> NEW.id_sesion_caja
       OR v_corr_tipo <> 'CORRECCION'
       OR COALESCE(v_corr_orig_ref, 0) <> NEW.id_movimiento_original THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CORRECCION_MOVIMIENTO_AJUSTE_INVALIDO: id_movimiento_correccion debe apuntar al movimiento tipo CORRECCION vinculado al mismo original.';
    END IF;

    -- Validar cadena de eslabones previos sobre el mismo movimiento original
    SELECT id_correccion_caja, numero_eslabon, monto_corregido
      INTO v_ult_id_correccion, v_ult_eslabon, v_ult_monto_corregido
      FROM correccion_movimiento_caja
     WHERE id_movimiento_original = NEW.id_movimiento_original
     ORDER BY numero_eslabon DESC
     LIMIT 1;

    IF v_ult_id_correccion IS NULL THEN
        IF NEW.id_correccion_previa IS NOT NULL OR NEW.numero_eslabon <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CORRECCION_CADENA_INVALIDA: La primera corrección debe tener id_correccion_previa = NULL y numero_eslabon = 1.';
        END IF;

        IF NEW.monto_vigente_previo <> v_orig_monto THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CORRECCION_MONTO_PREVIO_INCONSISTENTE: En el primer eslabón, monto_vigente_previo debe igualar a monto_original.';
        END IF;
    ELSE
        IF COALESCE(NEW.id_correccion_previa, 0) <> v_ult_id_correccion
           OR NEW.numero_eslabon <> (v_ult_eslabon + 1) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CORRECCION_CADENA_INVALIDA: Toda corrección posterior debe enlazar al último eslabón previo e incrementar numero_eslabon.';
        END IF;

        IF NEW.monto_vigente_previo <> v_ult_monto_corregido THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CORRECCION_MONTO_PREVIO_INCONSISTENTE: monto_vigente_previo debe coincidir con monto_corregido del eslabón inmediato anterior.';
        END IF;
    END IF;

    -- Calcular y verificar el signo exacto de efecto_saldo según si el original fue ingreso o egreso
    IF v_orig_tipo IN ('INGRESO_MANUAL', 'INYECCION_ADMIN') THEN
        SET v_efecto_esperado = ROUND(NEW.monto_corregido - NEW.monto_vigente_previo, 2);
    ELSE
        SET v_efecto_esperado = ROUND(NEW.monto_vigente_previo - NEW.monto_corregido, 2);
    END IF;

    IF NEW.efecto_saldo <> v_efecto_esperado
       OR v_corr_efecto <> v_efecto_esperado
       OR v_corr_monto <> ABS(v_efecto_esperado) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CORRECCION_EFECTO_SALDO_INCONSISTENTE: efecto_saldo no coincide con la diferencia firmada respecto al último importe vigente.';
    END IF;
END$$

CREATE TRIGGER `trg_correccion_movimiento_caja_before_update_inmutable`
BEFORE UPDATE ON `correccion_movimiento_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_CORRECCION_INMUTABLE: Los eslabones de correccion_movimiento_caja no pueden modificarse.';
END$$

CREATE TRIGGER `trg_correccion_movimiento_caja_before_delete_prohibido`
BEFORE DELETE ON `correccion_movimiento_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_CORRECCION_INMUTABLE: Los eslabones de correccion_movimiento_caja no pueden eliminarse.';
END$$

-- =============================================================================
-- CIERRE DEL PASO 04: VERIFICACIÓN DE OBJETOS Y TRIGGERS ANTES DE AVANZAR PASO
-- =============================================================================
BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_col_venta_ok INT DEFAULT 0;
    DECLARE v_tablas_ok INT DEFAULT 0;
    DECLARE v_fks_ok INT DEFAULT 0;
    DECLARE v_triggers_ok INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    SELECT COUNT(*)
      INTO v_col_venta_ok
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME = 'venta'
       AND COLUMN_NAME = 'id_sesion_caja';

    SELECT COUNT(*)
      INTO v_tablas_ok
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME IN ('movimiento_caja', 'correccion_movimiento_caja');

    SELECT COUNT(*)
      INTO v_fks_ok
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = v_db_actual
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND (
           TABLE_NAME IN ('movimiento_caja', 'correccion_movimiento_caja')
           OR (TABLE_NAME = 'venta' AND CONSTRAINT_NAME = 'fk_venta_sesion_caja')
       );

    SELECT COUNT(*)
      INTO v_triggers_ok
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual
       AND TRIGGER_NAME IN (
           'trg_movimiento_caja_before_insert_integridad',
           'trg_movimiento_caja_before_update_inmutable',
           'trg_movimiento_caja_before_delete_prohibido',
           'trg_correccion_movimiento_caja_before_insert_integridad',
           'trg_correccion_movimiento_caja_before_update_inmutable',
           'trg_correccion_movimiento_caja_before_delete_prohibido'
       );

    IF v_col_venta_ok <> 1 OR v_tablas_ok <> 2 OR v_fks_ok <> 14 OR v_triggers_ok <> 6 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO04_INCOMPLETA: No se crearon todas las columnas (1), tablas (2), FKs (14) o triggers (6) del paso 04.';
    END IF;

    UPDATE `sprint3_migracion_control`
       SET `paso_actual` = '04_MOVIMIENTOS_CORRECCIONES_VENTAS'
     WHERE `id_migracion` = 3
       AND `estado` = 'EN_PROGRESO'
       AND `paso_actual` = '03_SESIONES_ARQUEOS_AUTORIZACIONES';
END$$

DELIMITER ;

-- STEP15_AFTER_04

-- ORIGEN 05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql SHA256 f8883f63769db8a94110a2dbeb3a36e7f20e59a062986db16f8123ac1a2fe8a6
-- =============================================================================
-- ARCHIVO: 05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 05 de 06 (Requiere 04_estructura_movimientos_correcciones_y_ventas_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Implementar los dominios J (Idempotencia del Sprint 3) e I (Snapshots e ítems
--   del reporte de cierres de caja):
--     1. Ampliar `control_solicitud_idempotencia.tipo_operacion` conservando los
--        7 tipos existentes de los sprints previos y las 405 filas registradas,
--        agregando los 10 tipos de operación del Sprint 3 definidos en la
--        Sección 8 de CONTRATO_API_SPRINT_3.md.
--     2. Reutilizar `reporte_consulta_snapshot` como cabecera añadiendo únicamente
--        el valor `'cierres_caja'` a `tipo_reporte` y conservando intactos los
--        snapshots previos y los 150 registros de `reporte_consulta_snapshot_item`.
--     3. Crear `reporte_caja_snapshot_item` separada de `reporte_consulta_snapshot_item`,
--        con clave primaria `(id_snapshot, orden_posicion)`, unicidad por sesión
--        `(id_snapshot, id_sesion_caja)`, validación `JSON_VALID(proyeccion_json)`
--        y claves foráneas con `ON DELETE RESTRICT`.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_lock_name VARCHAR(64);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market')
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 05.';
    END IF;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 a 04.';
    END IF;

    SELECT estado, paso_actual
      INTO v_estado_migracion, v_paso_migracion
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion = 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base.';
    END IF;

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '04_MOVIMIENTOS_CORRECCIONES_VENTAS' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 05 exige estado EN_PROGRESO y paso_actual = 04_MOVIMIENTOS_CORRECCIONES_VENTAS.';
    END IF;

    IF COALESCE(IS_USED_LOCK(v_lock_name), -1) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LOCK_MIGRACION_NO_VIGENTE: El paso 05 debe ejecutarse en la misma conexión que adquirió GET_LOCK en el paso 01.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'reporte_caja_snapshot_item'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'OBJETOS_EXISTENTES_PASO05: La tabla reporte_caja_snapshot_item ya existe en esta base.';
    END IF;

    -- =========================================================================
    -- J. AMPLIACIÓN DE `control_solicitud_idempotencia.tipo_operacion` (Sección 8)
    -- =========================================================================
    ALTER TABLE `control_solicitud_idempotencia`
      MODIFY COLUMN `tipo_operacion` enum(
        'BAJA',
        'AJUSTE',
        'COMPRA',
        'CORRECCION_COMPRA',
        'ANULACION_COMPRA',
        'VENTA',
        'ANULACION_VENTA',
        'TURNO_CREAR',
        'TURNO_EDITAR',
        'TURNO_BAJA',
        'PROGRAMACION_HORARIO',
        'CAJA_ABRIR',
        'CAJA_INGRESO',
        'CAJA_EGRESO',
        'CAJA_CORRECCION',
        'CAJA_ARQUEO',
        'CAJA_CERRAR'
      ) NOT NULL;

    -- =========================================================================
    -- I.1. AMPLIACIÓN DE `reporte_consulta_snapshot.tipo_reporte` (Sección 7.8)
    -- =========================================================================
    ALTER TABLE `reporte_consulta_snapshot`
      MODIFY COLUMN `tipo_reporte` enum(
        'kardex',
        'variaciones',
        'cierres_caja'
      ) NOT NULL;

    -- =========================================================================
    -- I.2. TABLA `reporte_caja_snapshot_item` (Ítems congelados de cierres_caja)
    -- =========================================================================
    CREATE TABLE `reporte_caja_snapshot_item` (
      `id_snapshot` int(11) NOT NULL,
      `orden_posicion` int(11) NOT NULL,
      `id_sesion_caja` int(11) NOT NULL,
      `proyeccion_json` longtext NOT NULL,
      PRIMARY KEY (`id_snapshot`, `orden_posicion`),
      UNIQUE KEY `uq_reporte_caja_snapshot_sesion` (`id_snapshot`, `id_sesion_caja`),
      KEY `idx_reporte_caja_snapshot_sesion` (`id_sesion_caja`),
      CONSTRAINT `fk_reporte_caja_item_snapshot` FOREIGN KEY (`id_snapshot`) REFERENCES `reporte_consulta_snapshot` (`id_snapshot`) ON DELETE RESTRICT,
      CONSTRAINT `fk_reporte_caja_item_sesion` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`) ON DELETE RESTRICT,
      CONSTRAINT `chk_reporte_caja_item_orden_y_json` CHECK (
        `orden_posicion` >= 1
        and json_valid(`proyeccion_json`)
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

-- =============================================================================
-- TRIGGERS DE INTEGRIDAD PARA SNAPSHOTS DE REPORTES DE CAJA Y PRODUCTO
-- =============================================================================

CREATE TRIGGER `trg_reporte_caja_snapshot_item_before_insert_integridad`
BEFORE INSERT ON `reporte_caja_snapshot_item`
FOR EACH ROW
BEGIN
    DECLARE v_tipo_reporte VARCHAR(32) DEFAULT NULL;
    DECLARE v_estado_sesion VARCHAR(16) DEFAULT NULL;

    SELECT tipo_reporte
      INTO v_tipo_reporte
      FROM reporte_consulta_snapshot
     WHERE id_snapshot = NEW.id_snapshot
     LIMIT 1;

    IF v_tipo_reporte IS NULL OR v_tipo_reporte <> 'cierres_caja' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SNAPSHOT_REPORT_MISMATCH: reporte_caja_snapshot_item solo admite cabeceras con tipo_reporte = cierres_caja.';
    END IF;

    SELECT estado
      INTO v_estado_sesion
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_estado_sesion IS NULL OR v_estado_sesion <> 'CERRADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SNAPSHOT_SESSION_NOT_CLOSED: El reporte de cierres de caja solo puede congelar sesiones con estado = CERRADA.';
    END IF;
END$$

CREATE TRIGGER `trg_reporte_caja_snapshot_item_before_update_inmutable`
BEFORE UPDATE ON `reporte_caja_snapshot_item`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'SNAPSHOT_CAJA_INMUTABLE: Los ítems congelados en reporte_caja_snapshot_item no pueden modificarse.';
END$$

CREATE TRIGGER `trg_reporte_caja_snapshot_item_before_delete_prohibido`
BEFORE DELETE ON `reporte_caja_snapshot_item`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'SNAPSHOT_CAJA_INMUTABLE: Los ítems congelados en reporte_caja_snapshot_item no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_reporte_consulta_snapshot_item_before_insert_tipo`
BEFORE INSERT ON `reporte_consulta_snapshot_item`
FOR EACH ROW
BEGIN
    DECLARE v_tipo_reporte VARCHAR(32) DEFAULT NULL;

    SELECT tipo_reporte
      INTO v_tipo_reporte
      FROM reporte_consulta_snapshot
     WHERE id_snapshot = NEW.id_snapshot
     LIMIT 1;

    IF v_tipo_reporte IS NULL OR v_tipo_reporte NOT IN ('kardex', 'variaciones') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SNAPSHOT_REPORT_MISMATCH: reporte_consulta_snapshot_item solo admite cabeceras de tipo kardex o variaciones.';
    END IF;
END$$

-- =============================================================================
-- CIERRE DEL PASO 05: VERIFICACIÓN DE OBJETOS Y TRIGGERS ANTES DE AVANZAR PASO
-- =============================================================================
BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_tabla_ok INT DEFAULT 0;
    DECLARE v_fks_ok INT DEFAULT 0;
    DECLARE v_enums_ok INT DEFAULT 0;
    DECLARE v_triggers_ok INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    SELECT COUNT(*)
      INTO v_tabla_ok
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME = 'reporte_caja_snapshot_item';

    SELECT COUNT(*)
      INTO v_fks_ok
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = v_db_actual
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND TABLE_NAME = 'reporte_caja_snapshot_item';

    SELECT COUNT(*)
      INTO v_enums_ok
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = v_db_actual
       AND (
           (TABLE_NAME = 'control_solicitud_idempotencia' AND COLUMN_NAME = 'tipo_operacion' AND COLUMN_TYPE LIKE '%CAJA_CERRAR%')
           OR (TABLE_NAME = 'reporte_consulta_snapshot' AND COLUMN_NAME = 'tipo_reporte' AND COLUMN_TYPE LIKE '%cierres_caja%')
       );

    SELECT COUNT(*)
      INTO v_triggers_ok
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual
       AND TRIGGER_NAME IN (
           'trg_reporte_caja_snapshot_item_before_insert_integridad',
           'trg_reporte_caja_snapshot_item_before_update_inmutable',
           'trg_reporte_caja_snapshot_item_before_delete_prohibido',
           'trg_reporte_consulta_snapshot_item_before_insert_tipo'
       );

    IF v_tabla_ok <> 1 OR v_fks_ok <> 2 OR v_enums_ok <> 2 OR v_triggers_ok <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO05_INCOMPLETA: No se crearon todas las tablas (1), FKs (2), ENUMs (2) o triggers (4) del paso 05.';
    END IF;

    UPDATE `sprint3_migracion_control`
       SET `paso_actual` = '05_IDEMPOTENCIA_Y_REPORTES'
     WHERE `id_migracion` = 3
       AND `estado` = 'EN_PROGRESO'
       AND `paso_actual` = '04_MOVIMIENTOS_CORRECCIONES_VENTAS';
END$$

DELIMITER ;

-- STEP15_AFTER_05

-- ORIGEN 06_configuracion_permisos_y_cierre_migracion_sprint3.sql SHA256 ed8aa4cbdafa4cb5e184e968780b4b01f1f2d04cbd888b2d0c2f2b176634ec2a
-- =============================================================================
-- ARCHIVO: 06_configuracion_permisos_y_cierre_migracion_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 06 de 06 (Requiere 05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB)
--
-- PROPÓSITO:
--   Implementar el dominio K (Permisos y asignaciones RBAC del Sprint 3) y
--   cerrar formalmente la migración verificando la conservación del 100 % de
--   los datos previos:
--     1. Insertar los 5 nuevos permisos del Sprint 3 por `slug` sin asumir
--        IDs fijos (`turnos.movimientos`, `turnos.arqueo`, `turnos.programar`,
--        `turnos.consultar_historico`, `reportes.cierres_caja`).
--     2. Asignar en `rol_permiso` dinámicamente por `slug`:
--          - Vendedor (`id_rol = 2`): `turnos.movimientos`, `turnos.arqueo`
--          - Administrador (`id_rol = 1`): `turnos.programar`,
--            `turnos.consultar_historico`, `reportes.cierres_caja`
--        Conservando intactos los 31 permisos previos y las 30 asignaciones
--        previas en `rol_permiso`.
--     3. Verificar que ningún permiso operativo de vendedor (`turnos.abrir`,
--        `turnos.cerrar`, `turnos.movimientos`, `turnos.arqueo`) esté asignado
--        al Administrador (`id_rol = 1`) y que ningún permiso administrativo
--        (`turnos.programar`, `turnos.consultar_historico`, `reportes.cierres_caja`)
--        esté asignado al Vendedor (`id_rol = 2`).
--     4. Comprobar que los conteos y sumas de todas las tablas comerciales,
--        históricas y de archivo coincidan exactamente con la línea base y
--        marcar `sprint3_migracion_control.estado = 'COMPLETADA'`.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;
    DECLARE v_base_json LONGTEXT DEFAULT NULL;
    DECLARE v_permisos_nuevos_activos INT DEFAULT 0;
    DECLARE v_asignaciones_vendedor INT DEFAULT 0;
    DECLARE v_asignaciones_admin INT DEFAULT 0;
    DECLARE v_cruces_prohibidos INT DEFAULT 0;
    DECLARE v_ventas_con_sesion INT DEFAULT 0;
    DECLARE v_tablas_totales INT DEFAULT 0;
    DECLARE v_triggers_totales INT DEFAULT 0;
    DECLARE v_lock_name VARCHAR(128);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(DATABASE()));
        IF COALESCE(IS_USED_LOCK(v_lock_name), -1) = CONNECTION_ID() THEN
            DO RELEASE_LOCK(v_lock_name);
        END IF;
        RESIGNAL;
    END;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market')
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 06.';
    END IF;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 a 05.';
    END IF;

    SELECT estado, paso_actual, conteos_base_json
      INTO v_estado_migracion, v_paso_migracion, v_base_json
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion = 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base.';
    END IF;

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '05_IDEMPOTENCIA_Y_REPORTES' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 06 exige estado EN_PROGRESO y paso_actual = 05_IDEMPOTENCIA_Y_REPORTES.';
    END IF;

    IF COALESCE(IS_USED_LOCK(v_lock_name), -1) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LOCK_MIGRACION_NO_VIGENTE: El paso 06 debe ejecutarse en la misma conexión que adquirió GET_LOCK en el paso 01.';
    END IF;

    START TRANSACTION;

    -- =========================================================================
    -- K.1. INSERCIÓN DE LOS 5 NUEVOS PERMISOS DEL SPRINT 3 POR `slug`
    -- =========================================================================
    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Registrar movimientos y correcciones de caja',
        'turnos.movimientos',
        'Permite al vendedor titular registrar ingresos, egresos y correcciones de caja durante su turno abierto.',
        'Turnos',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'turnos.movimientos'
    );

    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Realizar arqueo de caja',
        'turnos.arqueo',
        'Permite al vendedor titular registrar el conteo físico de efectivo y las observaciones de diferencia antes del cierre.',
        'Turnos',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'turnos.arqueo'
    );

    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Gestionar catálogo y programación de turnos',
        'turnos.programar',
        'Permite al administrador crear, editar o dar de baja turnos y programar horarios semanales de vendedores.',
        'Turnos',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'turnos.programar'
    );

    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Consultar histórico de sesiones de caja',
        'turnos.consultar_historico',
        'Permite al administrador consultar las sesiones de caja cerradas, sus movimientos, arqueos y autorizaciones.',
        'Turnos',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'turnos.consultar_historico'
    );

    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Consultar reporte de cierres de caja',
        'reportes.cierres_caja',
        'Permite al administrador generar y consultar snapshots paginados del reporte consolidado de cierres de caja.',
        'Reportes',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'reportes.cierres_caja'
    );

    -- =========================================================================
    -- K.2. ASIGNACIÓN DE PERMISOS A ROLES MEDIANTE RESOLUCIÓN DINÁMICA POR `slug`
    -- =========================================================================

    -- Permisos operativos exclusivos para Vendedor (`id_rol = 2`)
    INSERT INTO `rol_permiso` (`id_rol`, `id_permiso`)
    SELECT 2, p.`id_permiso`
      FROM `permiso` p
     WHERE p.`slug` IN ('turnos.movimientos', 'turnos.arqueo')
       AND p.`estado` = 1
       AND NOT EXISTS (
           SELECT 1
             FROM `rol_permiso` rp
            WHERE rp.`id_rol` = 2
              AND rp.`id_permiso` = p.`id_permiso`
       );

    -- Permisos de gestión y consulta exclusivos para Administrador (`id_rol = 1`)
    INSERT INTO `rol_permiso` (`id_rol`, `id_permiso`)
    SELECT 1, p.`id_permiso`
      FROM `permiso` p
     WHERE p.`slug` IN ('turnos.programar', 'turnos.consultar_historico', 'reportes.cierres_caja')
       AND p.`estado` = 1
       AND NOT EXISTS (
           SELECT 1
             FROM `rol_permiso` rp
            WHERE rp.`id_rol` = 1
              AND rp.`id_permiso` = p.`id_permiso`
       );

    -- =========================================================================
    -- K.3. VALIDACIÓN DE MATRIZ RBAC Y CONSERVACIÓN DE DATOS PREVIOS
    -- =========================================================================
    SELECT COUNT(*)
      INTO v_permisos_nuevos_activos
      FROM `permiso`
     WHERE `slug` IN (
         'turnos.movimientos',
         'turnos.arqueo',
         'turnos.programar',
         'turnos.consultar_historico',
         'reportes.cierres_caja'
     )
       AND `estado` = 1;

    IF v_permisos_nuevos_activos <> 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_PERMISOS_FALLIDA: No se encuentran activos los 5 nuevos permisos del Sprint 3.';
    END IF;

    SELECT COUNT(*)
      INTO v_asignaciones_vendedor
      FROM `rol_permiso` rp
      INNER JOIN `permiso` p ON p.`id_permiso` = rp.`id_permiso`
     WHERE rp.`id_rol` = 2
       AND p.`slug` IN (
           'turnos.acceder',
           'turnos.abrir',
           'turnos.cerrar',
           'ventas.procesar',
           'turnos.movimientos',
           'turnos.arqueo'
       );

    IF v_asignaciones_vendedor <> 6 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_RBAC_VENDEDOR_FALLIDA: El rol Vendedor (2) debe poseer los 6 permisos de turnos, caja y ventas requeridos.';
    END IF;

    SELECT COUNT(*)
      INTO v_asignaciones_admin
      FROM `rol_permiso` rp
      INNER JOIN `permiso` p ON p.`id_permiso` = rp.`id_permiso`
     WHERE rp.`id_rol` = 1
       AND p.`slug` IN (
           'turnos.programar',
           'turnos.consultar_historico',
           'reportes.cierres_caja',
           'reportes.exportar'
       );

    IF v_asignaciones_admin <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_RBAC_ADMIN_FALLIDA: El rol Administrador (1) debe poseer los permisos de programación, histórico y reportes.';
    END IF;

    SELECT COUNT(*)
      INTO v_cruces_prohibidos
      FROM `rol_permiso` rp
      INNER JOIN `permiso` p ON p.`id_permiso` = rp.`id_permiso`
     WHERE (
         rp.`id_rol` = 1
         AND p.`slug` IN ('turnos.abrir', 'turnos.cerrar', 'turnos.movimientos', 'turnos.arqueo')
     )
        OR (
         rp.`id_rol` = 2
         AND p.`slug` IN ('turnos.programar', 'turnos.consultar_historico', 'reportes.cierres_caja')
     );

    IF v_cruces_prohibidos > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_RBAC_SEPARACION_FALLIDA: Se detectaron asignaciones cruzadas prohibidas entre Administrador y Vendedor.';
    END IF;

    SELECT COUNT(*)
      INTO v_ventas_con_sesion
      FROM `venta`
     WHERE `id_sesion_caja` IS NOT NULL;

    IF v_ventas_con_sesion <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CONSERVACION_VENTAS_FALLIDA: Todas las ventas históricas previas deben conservar id_sesion_caja = NULL.';
    END IF;

    IF (SELECT COUNT(*) FROM `usuario`) <> CAST(JSON_VALUE(v_base_json, '$.usuario') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `rol`) <> CAST(JSON_VALUE(v_base_json, '$.rol') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `permiso`) <> (CAST(JSON_VALUE(v_base_json, '$.permiso') AS UNSIGNED) + 5)
       OR (SELECT COUNT(*) FROM `rol_permiso`) <> (CAST(JSON_VALUE(v_base_json, '$.rol_permiso') AS UNSIGNED) + 5)
       OR (SELECT COUNT(*) FROM `producto`) <> CAST(JSON_VALUE(v_base_json, '$.producto') AS UNSIGNED)
       OR (SELECT COALESCE(SUM(`stock_actual`), 0) FROM `producto`) <> CAST(JSON_VALUE(v_base_json, '$.stock_total') AS SIGNED)
       OR (SELECT COUNT(*) FROM `venta`) <> CAST(JSON_VALUE(v_base_json, '$.venta') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `detalle_venta`) <> CAST(JSON_VALUE(v_base_json, '$.detalle_venta') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `compra`) <> CAST(JSON_VALUE(v_base_json, '$.compra') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `detalle_compra`) <> CAST(JSON_VALUE(v_base_json, '$.detalle_compra') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `compra_historial`) <> CAST(JSON_VALUE(v_base_json, '$.compra_historial') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `kardex`) <> CAST(JSON_VALUE(v_base_json, '$.kardex') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `operacion_inventario`) <> CAST(JSON_VALUE(v_base_json, '$.operacion_inventario') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `serie_comprobante`) <> CAST(JSON_VALUE(v_base_json, '$.serie_comprobante') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `control_solicitud_idempotencia`) <> CAST(JSON_VALUE(v_base_json, '$.control_solicitud_idempotencia') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `reporte_consulta_snapshot`) <> CAST(JSON_VALUE(v_base_json, '$.reporte_consulta_snapshot') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `reporte_consulta_snapshot_item`) <> CAST(JSON_VALUE(v_base_json, '$.reporte_consulta_snapshot_item') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_venta`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_venta') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_detalle_venta`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_detalle_venta') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_compra`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_compra') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_detalle_compra`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_detalle_compra') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_compra_historial`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_compra_historial') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_kardex`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_kardex') AS UNSIGNED) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CONSERVACION_DATOS_FALLIDA: Se detectó una discrepancia respecto a la línea base de conteos o stock.';
    END IF;

    SELECT COUNT(*)
      INTO v_tablas_totales
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_TYPE = 'BASE TABLE';

    SELECT COUNT(*)
      INTO v_triggers_totales
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual;

    IF v_tablas_totales <> 43 OR v_triggers_totales <> 34 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO06_INCOMPLETA: El esquema final debe contener exactamente 43 tablas y 34 triggers antes de marcar COMPLETADA.';
    END IF;

    -- =========================================================================
    -- K.4. CIERRE DEL CONTROL DE MIGRACIÓN SPRINT 3
    -- =========================================================================
    UPDATE `sprint3_migracion_control`
       SET `estado` = 'COMPLETADA',
           `paso_actual` = '06_COMPLETADA',
           `conteos_finales_json` = JSON_OBJECT(
               'usuario', (SELECT COUNT(*) FROM `usuario`),
               'rol', (SELECT COUNT(*) FROM `rol`),
               'permiso', (SELECT COUNT(*) FROM `permiso`),
               'rol_permiso', (SELECT COUNT(*) FROM `rol_permiso`),
               'producto', (SELECT COUNT(*) FROM `producto`),
               'stock_total', (SELECT COALESCE(SUM(`stock_actual`), 0) FROM `producto`),
               'venta', (SELECT COUNT(*) FROM `venta`),
               'ventas_con_sesion_nula', (SELECT COUNT(*) FROM `venta` WHERE `id_sesion_caja` IS NULL),
               'detalle_venta', (SELECT COUNT(*) FROM `detalle_venta`),
               'compra', (SELECT COUNT(*) FROM `compra`),
               'detalle_compra', (SELECT COUNT(*) FROM `detalle_compra`),
               'compra_historial', (SELECT COUNT(*) FROM `compra_historial`),
               'kardex', (SELECT COUNT(*) FROM `kardex`),
               'operacion_inventario', (SELECT COUNT(*) FROM `operacion_inventario`),
               'serie_comprobante', (SELECT COUNT(*) FROM `serie_comprobante`),
               'control_solicitud_idempotencia', (SELECT COUNT(*) FROM `control_solicitud_idempotencia`),
               'reporte_consulta_snapshot', (SELECT COUNT(*) FROM `reporte_consulta_snapshot`),
               'reporte_consulta_snapshot_item', (SELECT COUNT(*) FROM `reporte_consulta_snapshot_item`),
               'sprint2_archivo_venta', (SELECT COUNT(*) FROM `sprint2_archivo_venta`),
               'sprint2_archivo_detalle_venta', (SELECT COUNT(*) FROM `sprint2_archivo_detalle_venta`),
               'sprint2_archivo_compra', (SELECT COUNT(*) FROM `sprint2_archivo_compra`),
               'sprint2_archivo_detalle_compra', (SELECT COUNT(*) FROM `sprint2_archivo_detalle_compra`),
               'sprint2_archivo_compra_historial', (SELECT COUNT(*) FROM `sprint2_archivo_compra_historial`),
               'sprint2_archivo_kardex', (SELECT COUNT(*) FROM `sprint2_archivo_kardex`)
           ),
           `completada_en` = NOW()
     WHERE `id_migracion` = 3;

    COMMIT;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));
    DO RELEASE_LOCK(v_lock_name);
END$$

DELIMITER ;

-- STEP15_AFTER_06

-- ORIGEN 07_ajuste_check_correccion_cero_sprint3.sql SHA256 0bd1e39141c5714c107487fd446e7f1225c2e26530de832593b5239b9bfcae34
-- =============================================================================
-- ARCHIVO: 07_ajuste_check_correccion_cero_sprint3.sql
-- SPRINT: 3 - Paso 8 (Migración incremental posterior a 01..06)
-- ORDEN DE EJECUCIÓN: 07 (Requiere haber completado 01..06)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Alinear la restricción CHECK `chk_correccion_importes_y_motivo` de
--   `correccion_movimiento_caja` con la regla de `corrected_amount >= 0.00`
--   del contrato (EP-16), permitiendo:
--     1. Corregir un importe positivo a `0.00` (`monto_corregido >= 0.00`).
--     2. Corregir posteriormente un importe vigente `0.00` a otro valor válido
--        (`monto_vigente_previo >= 0.00`).
--   Conserva intactas todas las demás restricciones: `monto_original > 0.00`,
--   `monto_corregido <> monto_vigente_previo`, `efecto_saldo <> 0.00`,
--   consistencia de signos/efectos, triggers de inmutabilidad y datos previos.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_chk_existe INT DEFAULT 0;
    DECLARE v_check_clause LONGTEXT DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL
       OR v_db_actual = 'amazon_market_sprint3_verify_20261112_012430' OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market')
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 07.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 a 06.';
    END IF;

    SELECT estado
      INTO v_estado_migracion
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 07 requiere que la migración base (01..06) esté COMPLETADA.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'correccion_movimiento_caja'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'TABLA_FALTANTE_PASO07: No existe correccion_movimiento_caja en la base destino.';
    END IF;

    SELECT COUNT(*)
      INTO v_chk_existe
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = v_db_actual
       AND TABLE_NAME = 'correccion_movimiento_caja'
       AND CONSTRAINT_NAME = 'chk_correccion_importes_y_motivo'
       AND CONSTRAINT_TYPE = 'CHECK';

    IF v_chk_existe > 0 THEN
        ALTER TABLE `correccion_movimiento_caja`
            DROP CONSTRAINT `chk_correccion_importes_y_motivo`;
    END IF;

    ALTER TABLE `correccion_movimiento_caja`
        ADD CONSTRAINT `chk_correccion_importes_y_motivo` CHECK (
            `numero_eslabon` >= 1
            AND `monto_original` > 0.00
            AND `monto_vigente_previo` >= 0.00
            AND `monto_corregido` >= 0.00
            AND `monto_corregido` <> `monto_vigente_previo`
            AND `efecto_saldo` <> 0.00
            AND ABS(`efecto_saldo`) = ABS(ROUND(`monto_corregido` - `monto_vigente_previo`, 2))
            AND CHAR_LENGTH(TRIM(`motivo`)) BETWEEN 1 AND 250
        );

    SELECT CHECK_CLAUSE
      INTO v_check_clause
      FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = v_db_actual
       AND TABLE_NAME = 'correccion_movimiento_caja'
       AND CONSTRAINT_NAME = 'chk_correccion_importes_y_motivo'
     LIMIT 1;

    IF v_check_clause IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO07_INCOMPLETA: No se pudo verificar chk_correccion_importes_y_motivo tras la migración.';
    END IF;
END$$

DELIMITER ;

-- STEP15_AFTER_07

-- ORIGEN 08_ampliar_referencia_visible_movimiento_caja_sprint3.sql SHA256 b1303a8865a7cbc407ebf03d4c00ab0af7a589b6e04f9063c48357c06681bae3
-- =============================================================================
-- ARCHIVO: 08_ampliar_referencia_visible_movimiento_caja_sprint3.sql
-- SPRINT: 3 - Paso 8 (Migración incremental posterior a 01..07)
-- ORDEN DE EJECUCIÓN: 08 (Requiere haber completado 01..06 y recomendado 07)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Ampliar `movimiento_caja.referencia_visible` de VARCHAR(300) a VARCHAR(500)
--   para conservar íntegramente las referencias generadas por el backend cuando
--   se combinan:
--     - Motivo máximo contractual: 250 caracteres (`motivo` VARCHAR(250))
--     - Nombre máximo de administrador: 100 caracteres (`usuario.nombre` VARCHAR(100))
--     - Separador y etiqueta (` · Entregó: ` / ` · Recibió: `, 12 caracteres)
--     => Máximo actual en `admin_injection` / `admin_withdrawal`: 362 caracteres,
--        más margen suficiente para `omitted_withdrawal` y `correction` con IDs
--        multi-dígito sin truncamiento en modo permisivo ni error 1406 en modo
--        SQL estricto.
--   Conserva intactos todos los registros, restricciones y triggers existentes.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_max_len BIGINT DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL
       OR v_db_actual = 'amazon_market_sprint3_verify_20261112_012430' OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market')
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 08.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 a 07.';
    END IF;

    SELECT estado
      INTO v_estado_migracion
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 08 requiere que la migración base (01..06) esté COMPLETADA.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'movimiento_caja'
           AND COLUMN_NAME = 'referencia_visible'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'COLUMNA_FALTANTE_PASO08: No existe movimiento_caja.referencia_visible en la base destino.';
    END IF;

    ALTER TABLE `movimiento_caja`
        MODIFY COLUMN `referencia_visible` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

    SELECT CHARACTER_MAXIMUM_LENGTH
      INTO v_max_len
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME = 'movimiento_caja'
       AND COLUMN_NAME = 'referencia_visible'
     LIMIT 1;

    IF v_max_len IS NULL OR v_max_len < 500 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO08_FALLIDA: referencia_visible no quedó ampliada a VARCHAR(500).';
    END IF;
END$$

DELIMITER ;

-- STEP15_AFTER_08

-- ORIGEN 09_ajuste_trigger_venta_efectivo_sprint3.sql SHA256 a5decda5be68d84dd610d306ef6a38da82ed4cf6bfaa1e6480acc2900585e390
-- =============================================================================
-- ARCHIVO: 09_ajuste_trigger_venta_efectivo_sprint3.sql
-- SPRINT: 3 - Paso 9 (Migración incremental posterior a 01..08)
-- ORDEN DE EJECUCIÓN: 09 (Requiere haber completado 01..06 y recomendado 07..08)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Alinear `trg_movimiento_caja_before_insert_integridad` con la definición
--   real de `venta.estado` (`tinyint(4) NOT NULL DEFAULT 1`, donde `1` indica
--   venta confirmada y `0` anulada), reemplazando la comparación previa
--   `v_venta_estado <> 'COMPLETADA'` por `v_venta_estado IS NULL OR v_venta_estado <> 1`.
--   Conserva íntegras todas las demás validaciones de `movimiento_caja`.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL
       OR v_db_actual = 'amazon_market_sprint3_verify_20261112_012430' OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO, '') <> 'amazon_market')
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 09.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 a 08.';
    END IF;

    SELECT estado
      INTO v_estado_migracion
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 09 requiere que la migración base (01..06) esté COMPLETADA.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'movimiento_caja'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'TABLA_FALTANTE_PASO09: No existe movimiento_caja en la base destino.';
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_movimiento_caja_before_insert_integridad`$$

CREATE TRIGGER `trg_movimiento_caja_before_insert_integridad`
BEFORE INSERT ON `movimiento_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_rol_admin INT DEFAULT NULL;
    DECLARE v_estado_admin INT DEFAULT NULL;
    DECLARE v_venta_sesion INT DEFAULT NULL;
    DECLARE v_venta_metodo INT DEFAULT NULL;
    DECLARE v_venta_estado TINYINT DEFAULT NULL;
    DECLARE v_venta_total DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_origen_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_orig_sesion INT DEFAULT NULL;
    DECLARE v_orig_tipo VARCHAR(32) DEFAULT NULL;

    -- 1. La sesión destino debe existir, estar ABIERTA y pertenecer al vendedor que registra
    SELECT estado, id_usuario_vendedor
      INTO v_sesion_estado, v_sesion_vendedor
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Solo se pueden registrar movimientos o correcciones sobre una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_registro <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: Solo el vendedor titular de la sesión abierta puede registrar movimientos en ella.';
    END IF;

    -- 2. Reglas específicas según tipo_movimiento
    IF NEW.tipo_movimiento = 'VENTA_EFECTIVO' THEN
        IF NEW.id_venta IS NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_INVALIDO: VENTA_EFECTIVO exige id_venta no nulo y prohíbe campos de admin, sesión origen o corrección.';
        END IF;

        SELECT id_sesion_caja, id_metodo_pago, estado, total
          INTO v_venta_sesion, v_venta_metodo, v_venta_estado, v_venta_total
          FROM venta
         WHERE id_venta = NEW.id_venta
         LIMIT 1;

        IF v_venta_sesion IS NULL OR v_venta_sesion <> NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_SESION_INCONSISTENTE: La venta vinculada debe pertenecer a la misma sesión de caja.';
        END IF;

        IF v_venta_metodo <> 1 OR v_venta_estado IS NULL OR v_venta_estado <> 1 OR v_venta_total <> NEW.monto THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_METODO_O_MONTO_INVALIDO: VENTA_EFECTIVO requiere una venta confirmada (estado=1) con método Efectivo (1) y monto idéntico al total.';
        END IF;

    ELSEIF NEW.tipo_movimiento IN ('INGRESO_MANUAL', 'SALIDA_CAJA') THEN
        IF NEW.id_venta IS NOT NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_OPERATIVO_INVALIDO: INGRESO_MANUAL y SALIDA_CAJA no admiten id_venta, id_usuario_admin, sesión origen ni movimiento original.';
        END IF;

    ELSEIF NEW.tipo_movimiento IN ('INYECCION_ADMIN', 'RETIRO_ADMIN') THEN
        IF NEW.id_usuario_admin IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'ADMIN_AUTHORIZATION_REQUIRED: INYECCION_ADMIN y RETIRO_ADMIN exigen id_usuario_admin y no admiten venta, sesión origen ni corrección.';
        END IF;

        SELECT id_rol, estado
          INTO v_rol_admin, v_estado_admin
          FROM usuario
         WHERE id_usuario = NEW.id_usuario_admin
         LIMIT 1;

        IF v_rol_admin IS NULL OR v_rol_admin <> 1 OR v_estado_admin <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_ADMIN_USER: id_usuario_admin debe corresponder a un Administrador activo (id_rol = 1).';
        END IF;

    ELSEIF NEW.tipo_movimiento = 'RETIRO_OMITIDO' THEN
        IF NEW.id_sesion_origen_omitido IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: RETIRO_OMITIDO exige id_sesion_origen_omitido y las dos declaraciones obligatorias.';
        END IF;

        IF NEW.id_sesion_origen_omitido = NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: La sesión de origen del retiro omitido debe ser distinta de la sesión actual.';
        END IF;

        SELECT estado
          INTO v_origen_estado
          FROM sesion_caja
         WHERE id_sesion_caja = NEW.id_sesion_origen_omitido
         LIMIT 1;

        IF v_origen_estado IS NULL OR v_origen_estado <> 'CERRADA' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: La sesión de origen de un retiro omitido debe existir y estar CERRADA.';
        END IF;

        IF NEW.id_usuario_admin IS NOT NULL THEN
            SELECT id_rol, estado
              INTO v_rol_admin, v_estado_admin
              FROM usuario
             WHERE id_usuario = NEW.id_usuario_admin
             LIMIT 1;

            IF v_rol_admin IS NULL OR v_rol_admin <> 1 OR v_estado_admin <> 1 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'INVALID_ADMIN_USER: Cuando se informa id_usuario_admin en RETIRO_OMITIDO, debe ser un Administrador activo (id_rol = 1).';
            END IF;
        END IF;

    ELSEIF NEW.tipo_movimiento = 'CORRECCION' THEN
        IF NEW.id_movimiento_original IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: Un movimiento de tipo CORRECCION exige id_movimiento_original.';
        END IF;

        SELECT id_sesion_caja, tipo_movimiento
          INTO v_orig_sesion, v_orig_tipo
          FROM movimiento_caja
         WHERE id_movimiento_caja = NEW.id_movimiento_original
         LIMIT 1;

        IF v_orig_sesion IS NULL OR v_orig_sesion <> NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: El movimiento original a corregir debe pertenecer a la misma sesión de caja abierta.';
        END IF;

        IF v_orig_tipo NOT IN ('INGRESO_MANUAL', 'INYECCION_ADMIN', 'SALIDA_CAJA', 'RETIRO_ADMIN') THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: Solo se admiten correcciones sobre INGRESO_MANUAL, INYECCION_ADMIN, SALIDA_CAJA o RETIRO_ADMIN.';
        END IF;
    END IF;
END$$

DELIMITER ;

-- STEP15_AFTER_09

SELECT RELEASE_LOCK('am_sprint3_despliegue_local') AS lock_liberado;
