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

    IF v_db_actual = 'amazon_market' THEN
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
        '01_PREFLIGHT',
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
END$$

DELIMITER ;
