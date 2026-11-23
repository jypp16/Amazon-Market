-- ============================================================================
-- Migración 03 - Sprint 4 - HU-24: Inmutabilidad del historial de correcciones
-- Protege correccion_operacion_inventario contra UPDATE y DELETE mediante
-- triggers BEFORE UPDATE y BEFORE DELETE, preservando 100% los registros
-- existentes y sus vínculos con operacion_inventario y kardex.
-- ============================================================================

DROP TRIGGER IF EXISTS `trg_corr_op_inv_before_update_inmutable`;
DROP TRIGGER IF EXISTS `trg_corr_op_inv_before_delete_inmutable`;

DELIMITER $$

CREATE TRIGGER `trg_corr_op_inv_before_update_inmutable`
BEFORE UPDATE ON `correccion_operacion_inventario`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'INMUTABILIDAD HISTORIAL: Las correcciones de movimientos de inventario son inmutables y no pueden modificarse.';
END$$

CREATE TRIGGER `trg_corr_op_inv_before_delete_inmutable`
BEFORE DELETE ON `correccion_operacion_inventario`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'INMUTABILIDAD HISTORIAL: Las correcciones de movimientos de inventario son inmutables y no pueden eliminarse.';
END$$

DELIMITER ;
