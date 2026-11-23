-- =============================================================================
-- ARCHIVO: 05_hu27_venta_consumidor_final.sql
-- SPRINT: 4 - HU-27 (Registrar ventas a clientes no identificados)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Permitir NULL en `venta.id_cliente` para registrar ventas a «Consumidor
--   final» sin crear clientes ficticios, conservando el tipo de columna
--   (`INT(11)`), el índice `FK_VENTA_CLIENTE`, la clave foránea
--   `FK_VENTA_CLIENTE` hacia `cliente(id_cliente)` y todas las ventas
--   históricas con sus asociaciones intactas.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

ALTER TABLE `venta`
    MODIFY COLUMN `id_cliente` INT(11) NULL DEFAULT NULL;
