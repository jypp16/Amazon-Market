-- =============================================================================
-- ARCHIVO: 01_hu20_codigo_barra_opcional_y_kardex.sql
-- SPRINT: 4 - Paso 3 (HU-20: Asociar código de barras a un producto)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   1. Permitir NULL en `producto.codigo_barra` (opcional), conservando el
--      índice único `uq_codigo_barra` (MariaDB permite múltiples NULL) y
--      preservando intactos todos los códigos existentes (incluidos 6 dígitos).
--   2. Conservar el tipo actual de `kardex.producto_codigo_snapshot`
--      (`VARCHAR(50) NOT NULL DEFAULT ''`) y actualizar el trigger
--      `trg_kardex_before_insert_integridad` para permitir `''` cuando el
--      producto no tiene código de barras, preservando todas las demás
--      validaciones y la inmutabilidad de los asientos históricos.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- 1. Hacer nullable producto.codigo_barra conservando uq_codigo_barra y los datos actuales
ALTER TABLE `producto`
    MODIFY COLUMN `codigo_barra` VARCHAR(50) NULL DEFAULT NULL;

-- 2. Actualizar trigger de inserción en kardex para permitir producto_codigo_snapshot = ''
DROP TRIGGER IF EXISTS `trg_kardex_before_insert_integridad`;

DELIMITER $$

CREATE TRIGGER `trg_kardex_before_insert_integridad`
BEFORE INSERT ON `kardex`
FOR EACH ROW
BEGIN
    IF NEW.origen_operacion IN ('COMPRA', 'CORRECCION_COMPRA', 'ANULACION_COMPRA') THEN
        IF NEW.id_accion_compra IS NULL OR NEW.id_detalle_compra IS NULL
           OR NEW.id_accion_venta IS NOT NULL OR NEW.id_operacion_inventario IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INTEGRIDAD KARDEX: Los movimientos de compra requieren id_accion_compra e id_detalle_compra exclusivos.';
        END IF;
    ELSEIF NEW.origen_operacion IN ('VENTA', 'ANULACION_VENTA') THEN
        IF NEW.id_accion_venta IS NULL
           OR NEW.id_accion_compra IS NOT NULL OR NEW.id_operacion_inventario IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INTEGRIDAD KARDEX: Los movimientos de venta requieren id_accion_venta exclusivo.';
        END IF;
    ELSEIF NEW.origen_operacion IN ('BAJA_DANO', 'BAJA_VENCIMIENTO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO') THEN
        IF NEW.id_operacion_inventario IS NULL
           OR NEW.id_accion_compra IS NOT NULL OR NEW.id_accion_venta IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INTEGRIDAD KARDEX: Las bajas y ajustes requieren id_operacion_inventario exclusivo.';
        END IF;
        IF NEW.justificacion IS NULL OR CHAR_LENGTH(TRIM(NEW.justificacion)) = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INTEGRIDAD KARDEX: Las bajas y ajustes requieren justificación obligatoria.';
        END IF;
    END IF;

    IF CHAR_LENGTH(TRIM(NEW.producto_nombre_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.categoria_nombre_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.subcategoria_nombre_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.unidad_abreviatura_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.responsable_nombre_snapshot)) = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INTEGRIDAD KARDEX: Todo movimiento debe incluir los snapshots históricos completos del producto y responsable.';
    END IF;
END$$

DELIMITER ;
