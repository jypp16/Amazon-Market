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
       OR v_db_actual IN ('amazon_market', 'amazon_market_sprint3_verify_20261112_012430')
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
