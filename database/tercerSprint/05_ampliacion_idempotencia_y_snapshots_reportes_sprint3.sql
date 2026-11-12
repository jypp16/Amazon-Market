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

    IF v_db_actual IS NULL OR v_db_actual = 'amazon_market'
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
