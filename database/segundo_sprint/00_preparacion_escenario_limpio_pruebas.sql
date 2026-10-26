-- =============================================================================
-- ARCHIVO: 00_preparacion_escenario_limpio_pruebas.sql
-- SPRINT: 2 - Fase 1 (Definición verificable y preparación del entorno)
-- TIPO: Preparación de datos (Escenario operativo limpio en copia de pruebas)
-- ORDEN DE EJECUCIÓN: 00 (Posterior a restaurar la copia desde el respaldo base)
-- BASE HABITUAL: amazon_market_sprint2_test. En producción solo se admite
--   invocado por 10_archivado_y_preparacion_produccion.sql con su guardia.
--
-- PROPÓSITO:
--   Preparar en la base de pruebas aislada (amazon_market_sprint2_test) un
--   escenario operativo limpio conforme a los acuerdos del Sprint 2:
--     1. Conservar productos e identificadores (id_producto, codigo, nombre,
--        precios, stock_minimo, categoria, unidad, imagen, estado).
--     2. Conservar intactos todos los catálogos y configuraciones (categoria,
--        unidad_medida, presentacion, proveedor, cliente, usuario, rol,
--        permiso, rol_permiso, tipo_comprobante, serie_comprobante,
--        tipo_documento, metodo_pago), sin reiniciar numeraciones ni
--        correlativos de comprobantes.
--     3. Retirar únicamente las operaciones antiguas de prueba en orden
--        estricto de dependencias de claves foráneas:
--          kardex -> compra_historial -> detalle_compra -> compra
--          -> detalle_venta -> venta
--     4. Establecer producto.stock_actual = 0 como inicio del nuevo historial.
--
-- PRECONDICIONES:
--   - Haberse conectado explícitamente a la base `amazon_market_sprint2_test`.
--   - Existencia del esquema base restaurado desde el respaldo de `amazon_market`.
--
-- PROTECCIÓN CONTRA EJECUCIÓN ACCIDENTAL:
--   - El bloque compuesto valida la base de pruebas o, exclusivamente durante
--     Fase 10, la marca de sesión que instala el script 10 en `amazon_market`.
--   - Cualquier otra base activa lanza `SIGNAL SQLSTATE '45000'` y aborta.
-- =============================================================================

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR NOT (
        v_db_actual = 'amazon_market_sprint2_test'
        OR (v_db_actual = 'amazon_market' AND COALESCE(@SPRINT2_FASE10_AUTORIZADA, '') = 'EJECUTAR_FASE10')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: 00 solo admite amazon_market_sprint2_test o la ejecución autorizada desde el script 10 en amazon_market.';
    END IF;

    START TRANSACTION;

    -- 1. Retirar movimientos de kardex antiguos de prueba (hijos de producto, usuario y detalle_compra)
    DELETE FROM kardex;

    -- 2. Retirar historial y detalles de compras antiguas de prueba, luego cabeceras de compra
    DELETE FROM compra_historial;
    DELETE FROM detalle_compra;
    DELETE FROM compra;

    -- 3. Retirar detalles de ventas antiguas de prueba, luego cabeceras de venta
    DELETE FROM detalle_venta;
    DELETE FROM venta;

    -- 4. Establecer stock cero en todo el catálogo conservando intactos los demás atributos
    UPDATE producto
       SET stock_actual = 0;

    COMMIT;
END$$

DELIMITER ;

