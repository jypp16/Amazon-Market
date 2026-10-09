-- Script de Actualización para Fase 2: Compras (Presentaciones y Lotes)
-- Objetivo: Añadir soporte para presentaciones (Cajas, Paquetes) y actualizar detalles de compra.

USE amazon_market;

-- 1. Crear tabla de presentaciones si no existe
CREATE TABLE IF NOT EXISTS presentacion (
    id_presentacion INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL, -- Ej: Caja, Paquete, Unidad
    equivalencia INT NOT NULL DEFAULT 1, -- Cantidad de unidades base (Ej: 1 Caja = 24 unidades)
    estado TINYINT(1) DEFAULT 1, -- 1: Activo, 0: Inactivo
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insertar datos por defecto si la tabla está vacía
INSERT INTO presentacion (nombre, equivalencia)
SELECT * FROM (SELECT 'Unidad', 1) AS tmp
WHERE NOT EXISTS (
    SELECT nombre FROM presentacion WHERE nombre = 'Unidad'
) LIMIT 1;

INSERT INTO presentacion (nombre, equivalencia)
SELECT * FROM (SELECT 'Caja x 12', 12) AS tmp
WHERE NOT EXISTS (
    SELECT nombre FROM presentacion WHERE nombre = 'Caja x 12'
) LIMIT 1;

INSERT INTO presentacion (nombre, equivalencia)
SELECT * FROM (SELECT 'Caja x 24', 24) AS tmp
WHERE NOT EXISTS (
    SELECT nombre FROM presentacion WHERE nombre = 'Caja x 24'
) LIMIT 1;

-- 2. Añadir columnas de presentación a detalle_compra
-- Nota: Usamos una técnica segura por si la columna ya existe en futuras ejecuciones
SET @dbname = DATABASE();
SET @tablename = 'detalle_compra';
SET @columnname = 'id_presentacion';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE detalle_compra ADD id_presentacion INT DEFAULT 1 AFTER id_producto;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = 'cantidad_presentaciones';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE detalle_compra ADD cantidad_presentaciones INT NOT NULL DEFAULT 1 AFTER id_presentacion;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = 'equivalencia_aplicada';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE detalle_compra ADD equivalencia_aplicada INT NOT NULL DEFAULT 1 AFTER cantidad_presentaciones;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Si id_presentacion se agregó como INT, añadir Foreign Key (opcional pero recomendado)
-- Lo ignoramos si causa errores de duplicidad en otra ejecución, por eso lo envolvemos si es necesario.
-- Dejaremos que sea un campo lógico en lugar de Foreign Key estricta para simplificar migraciones por ahora.

