-- =============================================================================
-- ARCHIVO: 02_configuracion_permisos_sprint2.sql
-- SPRINT: 2 - Fase 2 (Estructura de datos, seguridad e infraestructura de movimientos)
-- TIPO: Configuración y preparación de catálogos base (DML y triggers de subcategoría)
-- ORDEN DE EJECUCIÓN: 02 (Posterior a 01_estructura_inventario_sprint2.sql)
-- BASE DE DATOS DE DESTINO: amazon_market_sprint2_test (EXCLUSIVAMENTE EN FASES 1–9)
--
-- PROPÓSITO:
--   1. Crear la subcategoría base "General" para cada categoría existente en
--      `categoria` y vincular los productos existentes que aún tengan
--      `id_subcategoria IS NULL` a la subcategoría "General" de su categoría.
--   2. Activar los triggers de `categoria` y `producto` que asignan la
--      subcategoría "General" de la categoría cuando un módulo antiguo no envíe
--      `id_subcategoria`, y exigen que `id_subcategoria` nunca quede nulo.
--   3. Registrar los permisos RBAC específicos del Sprint 2 (`inventario.ajustes`,
--      `reportes.kardex`, `reportes.variaciones`, `reportes.exportar`) y
--      asignarlos exclusivamente al rol Administrador (`id_rol = 1`).
--
-- PRECONDICIONES:
--   - Base activa: `amazon_market_sprint2_test`.
--   - Haberse ejecutado previamente `01_estructura_inventario_sprint2.sql`.
-- =============================================================================

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR v_db_actual <> 'amazon_market_sprint2_test' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: 02_configuracion_permisos_sprint2.sql solo puede ejecutarse sobre amazon_market_sprint2_test.';
    END IF;

    START TRANSACTION;

    -- 1. Crear subcategoría "General" por cada categoría existente si no existe
    INSERT INTO subcategoria (id_categoria, nombre, descripcion, estado)
    SELECT c.id_categoria,
           'General',
           CONCAT('Subcategoría general de ', c.nombre),
           c.estado
      FROM categoria c
     WHERE NOT EXISTS (
           SELECT 1
             FROM subcategoria s
            WHERE s.id_categoria = c.id_categoria
              AND s.nombre = 'General'
     );

    -- 2. Vincular productos existentes sin subcategoría a la subcategoría "General" de su categoría
    UPDATE producto p
     INNER JOIN subcategoria s
        ON s.id_categoria = p.id_categoria
       AND s.nombre = 'General'
       SET p.id_subcategoria = s.id_subcategoria
     WHERE p.id_subcategoria IS NULL;

    -- 3. Registrar permisos del Sprint 2 en tabla `permiso`
    INSERT INTO permiso (nombre, slug, descripcion, grupo, estado)
    SELECT 'Ajustes y Bajas de Inventario',
           'inventario.ajustes',
           'Registrar bajas por daño/vencimiento y ajustes por conteo físico',
           'Inventario',
           1
     WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE slug = 'inventario.ajustes');

    INSERT INTO permiso (nombre, slug, descripcion, grupo, estado)
    SELECT 'Consultar Kardex de Inventario',
           'reportes.kardex',
           'Consultar y auditar el Kardex de movimientos de inventario',
           'Reportes',
           1
     WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE slug = 'reportes.kardex');

    INSERT INTO permiso (nombre, slug, descripcion, grupo, estado)
    SELECT 'Consultar Reporte de Variaciones',
           'reportes.variaciones',
           'Consultar el reporte de bajas y ajustes de inventario',
           'Reportes',
           1
     WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE slug = 'reportes.variaciones');

    INSERT INTO permiso (nombre, slug, descripcion, grupo, estado)
    SELECT 'Exportar Reportes PDF y Excel',
           'reportes.exportar',
           'Descargar reportes del sistema en formatos PDF y Excel',
           'Reportes',
           1
     WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE slug = 'reportes.exportar');

    -- 4. Asignar permisos del Sprint 2 exclusivamente al rol Administrador (id_rol = 1)
    INSERT INTO rol_permiso (id_rol, id_permiso)
    SELECT 1, p.id_permiso
      FROM permiso p
     WHERE p.slug IN ('inventario.ajustes', 'reportes.kardex', 'reportes.variaciones', 'reportes.exportar')
       AND NOT EXISTS (
           SELECT 1
             FROM rol_permiso rp
            WHERE rp.id_rol = 1
              AND rp.id_permiso = p.id_permiso
       );

    -- Asegurar que el rol Vendedor (id_rol = 2) no tenga asignados permisos del Sprint 2
    DELETE rp
      FROM rol_permiso rp
     INNER JOIN permiso p ON p.id_permiso = rp.id_permiso
     WHERE rp.id_rol <> 1
       AND p.slug IN ('inventario.ajustes', 'reportes.kardex', 'reportes.variaciones', 'reportes.exportar', 'reportes.ver');

    COMMIT;
END$$

-- =============================================================================
-- 5. TRIGGERS EN `categoria` Y `producto` PARA GARANTIZAR SUBCATEGORÍA VÁLIDA
-- =============================================================================
DROP TRIGGER IF EXISTS `trg_categoria_after_insert_subcategoria_general`$$
DROP TRIGGER IF EXISTS `trg_producto_before_insert_subcategoria`$$
DROP TRIGGER IF EXISTS `trg_producto_before_update_subcategoria`$$

CREATE TRIGGER `trg_categoria_after_insert_subcategoria_general`
AFTER INSERT ON `categoria`
FOR EACH ROW
BEGIN
    INSERT INTO subcategoria (id_categoria, nombre, descripcion, estado)
    VALUES (NEW.id_categoria, 'General', CONCAT('Subcategoría general de ', NEW.nombre), NEW.estado);
END$$

CREATE TRIGGER `trg_producto_before_insert_subcategoria`
BEFORE INSERT ON `producto`
FOR EACH ROW
BEGIN
    DECLARE v_id_subcat INT DEFAULT NULL;

    IF NEW.id_subcategoria IS NULL THEN
        SELECT s.id_subcategoria INTO v_id_subcat
          FROM subcategoria s
         WHERE s.id_categoria = NEW.id_categoria
         ORDER BY (s.nombre = 'General') DESC, s.id_subcategoria ASC
         LIMIT 1;
        SET NEW.id_subcategoria = v_id_subcat;
    END IF;

    IF NEW.id_subcategoria IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INTEGRIDAD PRODUCTO: La categoría seleccionada no posee una subcategoría válida asociada.';
    END IF;
END$$

CREATE TRIGGER `trg_producto_before_update_subcategoria`
BEFORE UPDATE ON `producto`
FOR EACH ROW
BEGIN
    DECLARE v_id_subcat INT DEFAULT NULL;

    IF NEW.id_subcategoria IS NULL OR (NEW.id_categoria <> OLD.id_categoria AND NEW.id_subcategoria = OLD.id_subcategoria) THEN
        -- Verificar si la subcategoría actual pertenece a la nueva categoría
        SELECT s.id_subcategoria INTO v_id_subcat
          FROM subcategoria s
         WHERE s.id_subcategoria = NEW.id_subcategoria
           AND s.id_categoria = NEW.id_categoria
         LIMIT 1;

        IF v_id_subcat IS NULL THEN
            SELECT s.id_subcategoria INTO v_id_subcat
              FROM subcategoria s
             WHERE s.id_categoria = NEW.id_categoria
             ORDER BY (s.nombre = 'General') DESC, s.id_subcategoria ASC
             LIMIT 1;
            SET NEW.id_subcategoria = v_id_subcat;
        END IF;
    END IF;

    IF NEW.id_subcategoria IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INTEGRIDAD PRODUCTO: La categoría seleccionada no posee una subcategoría válida asociada.';
    END IF;
END$$

DELIMITER ;

