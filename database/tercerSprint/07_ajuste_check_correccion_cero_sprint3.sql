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
       OR v_db_actual IN ('amazon_market', 'amazon_market_sprint3_verify_20261112_012430')
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
