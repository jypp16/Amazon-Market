-- ============================================================================
-- Migración 02 - Sprint 4 - HU-24: Editar movimiento de inventario
-- Preserva 100% los registros originales de operacion_inventario, kardex y stock.
-- Agrega columnas de estado vigente y versionamiento en operacion_inventario,
-- crea tabla auditable correccion_operacion_inventario, y habilita el origen
-- CORRECCION_INVENTARIO en kardex y control_solicitud_idempotencia.
-- ============================================================================

-- 1. Agregar columnas de estado vigente y versionamiento en operacion_inventario
ALTER TABLE `operacion_inventario`
    ADD COLUMN `categoria_vigente` ENUM('BAJA','AJUSTE') NULL AFTER `fecha_registro`,
    ADD COLUMN `origen_vigente` ENUM('BAJA_DANO','BAJA_VENCIMIENTO','AJUSTE_POSITIVO','AJUSTE_NEGATIVO') NULL AFTER `categoria_vigente`,
    ADD COLUMN `tipo_movimiento_vigente` VARCHAR(30) NULL AFTER `origen_vigente`,
    ADD COLUMN `sentido_vigente` ENUM('INCREMENTO','DISMINUCION') NULL AFTER `tipo_movimiento_vigente`,
    ADD COLUMN `cantidad_vigente` INT(11) NULL AFTER `sentido_vigente`,
    ADD COLUMN `justificacion_vigente` VARCHAR(255) NULL AFTER `cantidad_vigente`,
    ADD COLUMN `numero_lote_vigente` VARCHAR(50) DEFAULT NULL AFTER `justificacion_vigente`,
    ADD COLUMN `condicion_lote_vigente` ENUM('CON_LOTE','SIN_LOTE','NO_APLICA') NOT NULL DEFAULT 'SIN_LOTE' AFTER `numero_lote_vigente`,
    ADD COLUMN `version` INT(11) NOT NULL DEFAULT 1 AFTER `condicion_lote_vigente`,
    ADD COLUMN `total_correcciones` INT(11) NOT NULL DEFAULT 0 AFTER `version`,
    ADD COLUMN `ultima_correccion_en` DATETIME DEFAULT NULL AFTER `total_correcciones`,
    ADD COLUMN `ultima_correccion_por` INT(11) DEFAULT NULL AFTER `ultima_correccion_en`;

-- 2. Poblar estado vigente a partir de los datos originales existentes (sin alterar originales)
UPDATE `operacion_inventario`
   SET `categoria_vigente`       = `categoria_operacion`,
       `origen_vigente`          = `origen_operacion`,
       `tipo_movimiento_vigente` = `tipo_movimiento`,
       `sentido_vigente`         = `sentido_movimiento`,
       `cantidad_vigente`        = `cantidad`,
       `justificacion_vigente`   = `justificacion`,
       `numero_lote_vigente`     = `numero_lote`,
       `condicion_lote_vigente`  = `condicion_lote`,
       `version`                 = 1,
       `total_correcciones`      = 0
 WHERE `origen_vigente` IS NULL;

-- 3. Endurecer NOT NULL, índices, FK y CHECK de estado vigente
ALTER TABLE `operacion_inventario`
    MODIFY COLUMN `categoria_vigente` ENUM('BAJA','AJUSTE') NOT NULL,
    MODIFY COLUMN `origen_vigente` ENUM('BAJA_DANO','BAJA_VENCIMIENTO','AJUSTE_POSITIVO','AJUSTE_NEGATIVO') NOT NULL,
    MODIFY COLUMN `tipo_movimiento_vigente` VARCHAR(30) NOT NULL,
    MODIFY COLUMN `sentido_vigente` ENUM('INCREMENTO','DISMINUCION') NOT NULL,
    MODIFY COLUMN `cantidad_vigente` INT(11) NOT NULL,
    MODIFY COLUMN `justificacion_vigente` VARCHAR(255) NOT NULL,
    ADD KEY `idx_op_inv_origen_vigente_fecha` (`origen_vigente`, `fecha_registro`, `id_operacion`),
    ADD KEY `fk_op_inv_ultima_correccion_por` (`ultima_correccion_por`),
    ADD CONSTRAINT `fk_op_inv_ultima_correccion_por`
        FOREIGN KEY (`ultima_correccion_por`) REFERENCES `usuario` (`id_usuario`),
    ADD CONSTRAINT `chk_op_inv_vigente_integridad` CHECK (
        `cantidad_vigente` >= 1
        AND `version` >= 1
        AND `total_correcciones` >= 0
        AND CHAR_LENGTH(TRIM(`justificacion_vigente`)) > 0
        AND (
            (`sentido_vigente` = 'INCREMENTO' AND `categoria_vigente` = 'AJUSTE' AND `origen_vigente` = 'AJUSTE_POSITIVO')
            OR (
                `sentido_vigente` = 'DISMINUCION'
                AND (
                    (`categoria_vigente` = 'BAJA' AND `origen_vigente` IN ('BAJA_DANO','BAJA_VENCIMIENTO'))
                    OR (`categoria_vigente` = 'AJUSTE' AND `origen_vigente` = 'AJUSTE_NEGATIVO')
                )
            )
        )
    );

-- 4. Habilitar CORRECCION_INVENTARIO en kardex (ENUM, CHECK y trigger)
ALTER TABLE `kardex`
    DROP CONSTRAINT `chk_kardex_integridad`;

ALTER TABLE `kardex`
    MODIFY COLUMN `origen_operacion` ENUM(
        'COMPRA',
        'CORRECCION_COMPRA',
        'ANULACION_COMPRA',
        'VENTA',
        'ANULACION_VENTA',
        'BAJA_DANO',
        'BAJA_VENCIMIENTO',
        'AJUSTE_POSITIVO',
        'AJUSTE_NEGATIVO',
        'CORRECCION_INVENTARIO'
    ) NOT NULL DEFAULT 'COMPRA',
    ADD CONSTRAINT `chk_kardex_integridad` CHECK (
        `cantidad` >= 1
        AND `stock_anterior` >= 0
        AND `stock_resultante` >= 0
        AND (
            (`sentido_movimiento` = 'INCREMENTO' AND `stock_resultante` = `stock_anterior` + `cantidad`)
            OR (`sentido_movimiento` = 'DISMINUCION' AND `stock_resultante` = `stock_anterior` - `cantidad`)
        )
        AND (
            (`origen_operacion` IN ('COMPRA','ANULACION_VENTA','AJUSTE_POSITIVO') AND `sentido_movimiento` = 'INCREMENTO')
            OR (`origen_operacion` IN ('ANULACION_COMPRA','VENTA','BAJA_DANO','BAJA_VENCIMIENTO','AJUSTE_NEGATIVO') AND `sentido_movimiento` = 'DISMINUCION')
            OR (`origen_operacion` IN ('CORRECCION_COMPRA','CORRECCION_INVENTARIO') AND `sentido_movimiento` IN ('INCREMENTO','DISMINUCION'))
        )
    );

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
    ELSEIF NEW.origen_operacion IN ('BAJA_DANO', 'BAJA_VENCIMIENTO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO', 'CORRECCION_INVENTARIO') THEN
        IF NEW.id_operacion_inventario IS NULL
           OR NEW.id_accion_compra IS NOT NULL OR NEW.id_accion_venta IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INTEGRIDAD KARDEX: Las bajas, ajustes y correcciones de inventario requieren id_operacion_inventario exclusivo.';
        END IF;
        IF NEW.justificacion IS NULL OR CHAR_LENGTH(TRIM(NEW.justificacion)) = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INTEGRIDAD KARDEX: Las bajas, ajustes y correcciones de inventario requieren justificación obligatoria.';
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

-- 5. Habilitar CORRECCION_INVENTARIO en control_solicitud_idempotencia
ALTER TABLE `control_solicitud_idempotencia`
    MODIFY COLUMN `tipo_operacion` ENUM(
        'BAJA',
        'AJUSTE',
        'CORRECCION_INVENTARIO',
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

-- 6. Crear tabla auditable de historial de correcciones de movimientos de inventario
CREATE TABLE `correccion_operacion_inventario` (
    `id_correccion` INT(11) NOT NULL AUTO_INCREMENT,
    `id_operacion` INT(11) NOT NULL,
    `numero_correccion` INT(11) NOT NULL,
    `version_anterior` INT(11) NOT NULL,
    `version_nueva` INT(11) NOT NULL,
    `id_usuario` INT(11) NOT NULL,
    `motivo` VARCHAR(250) NOT NULL,
    `origen_anterior` ENUM('BAJA_DANO','BAJA_VENCIMIENTO','AJUSTE_POSITIVO','AJUSTE_NEGATIVO') NOT NULL,
    `origen_nuevo` ENUM('BAJA_DANO','BAJA_VENCIMIENTO','AJUSTE_POSITIVO','AJUSTE_NEGATIVO') NOT NULL,
    `cantidad_anterior` INT(11) NOT NULL,
    `cantidad_nueva` INT(11) NOT NULL,
    `justificacion_anterior` VARCHAR(255) NOT NULL,
    `justificacion_nueva` VARCHAR(255) NOT NULL,
    `lote_anterior` VARCHAR(50) DEFAULT NULL,
    `lote_nuevo` VARCHAR(50) DEFAULT NULL,
    `efecto_anterior` INT(11) NOT NULL,
    `efecto_nuevo` INT(11) NOT NULL,
    `delta_stock` INT(11) NOT NULL,
    `stock_anterior` INT(11) NOT NULL,
    `stock_resultante` INT(11) NOT NULL,
    `id_kardex` INT(11) DEFAULT NULL,
    `cambios_json` LONGTEXT NOT NULL,
    `referencia_solicitud` VARCHAR(64) NOT NULL,
    `fecha_correccion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_correccion`),
    UNIQUE KEY `uq_corr_op_numero` (`id_operacion`, `numero_correccion`),
    UNIQUE KEY `uq_corr_op_referencia` (`referencia_solicitud`),
    KEY `idx_corr_op_fecha` (`id_operacion`, `fecha_correccion`, `id_correccion`),
    KEY `fk_corr_op_usuario` (`id_usuario`),
    KEY `fk_corr_op_kardex` (`id_kardex`),
    CONSTRAINT `fk_corr_op_operacion` FOREIGN KEY (`id_operacion`) REFERENCES `operacion_inventario` (`id_operacion`),
    CONSTRAINT `fk_corr_op_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`),
    CONSTRAINT `fk_corr_op_kardex` FOREIGN KEY (`id_kardex`) REFERENCES `kardex` (`id_kardex`),
    CONSTRAINT `chk_corr_op_integridad` CHECK (
        `numero_correccion` >= 1
        AND `version_anterior` >= 1
        AND `version_nueva` = `version_anterior` + 1
        AND `cantidad_anterior` >= 1
        AND `cantidad_nueva` >= 1
        AND `stock_anterior` >= 0
        AND `stock_resultante` >= 0
        AND `stock_resultante` = `stock_anterior` + `delta_stock`
        AND `delta_stock` = `efecto_nuevo` - `efecto_anterior`
        AND CHAR_LENGTH(TRIM(`motivo`)) BETWEEN 1 AND 250
        AND CHAR_LENGTH(`referencia_solicitud`) BETWEEN 16 AND 64
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
