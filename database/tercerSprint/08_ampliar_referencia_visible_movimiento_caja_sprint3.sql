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
       OR v_db_actual IN ('amazon_market', 'amazon_market_sprint3_verify_20261112_012430')
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
