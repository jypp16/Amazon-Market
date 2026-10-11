-- Script de Actualización para Fase 3: Motivos de Anulación y Corrección de Compras
-- Objetivo: Añadir soporte para auditoría y motivos obligatorios en compras.

USE amazon_market;

-- 1. Añadir columnas a tabla compra de forma segura si no existen
SET @dbname = DATABASE();
SET @tablename = 'compra';

-- motivo_anulacion
SET @columnname = 'motivo_anulacion';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE compra ADD COLUMN motivo_anulacion VARCHAR(255) DEFAULT NULL AFTER estado;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- fecha_anulacion
SET @columnname = 'fecha_anulacion';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE compra ADD COLUMN fecha_anulacion DATETIME DEFAULT NULL AFTER motivo_anulacion;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- motivo_correccion (almacena el último motivo de corrección para consultas rápidas)
SET @columnname = 'motivo_correccion';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE compra ADD COLUMN motivo_correccion VARCHAR(255) DEFAULT NULL AFTER fecha_anulacion;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- fecha_correccion
SET @columnname = 'fecha_correccion';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = @tablename AND table_schema = @dbname AND column_name = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE compra ADD COLUMN fecha_correccion DATETIME DEFAULT NULL AFTER motivo_correccion;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Crear tabla de historial y auditoría para compras (RC01 y RC02)
CREATE TABLE IF NOT EXISTS `compra_historial` (
  `id_historial` int(11) NOT NULL AUTO_INCREMENT,
  `id_compra` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `tipo_accion` enum('Correccion', 'Anulacion') NOT NULL,
  `motivo` varchar(255) NOT NULL,
  `datos_anteriores` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_historial`),
  KEY `fk_historial_compra` (`id_compra`),
  KEY `fk_historial_usuario` (`id_usuario`),
  CONSTRAINT `fk_historial_compra` FOREIGN KEY (`id_compra`) REFERENCES `compra` (`id_compra`) ON DELETE CASCADE,
  CONSTRAINT `fk_historial_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

