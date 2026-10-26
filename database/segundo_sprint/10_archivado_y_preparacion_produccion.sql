-- =============================================================================
-- ARCHIVO: 10_archivado_y_preparacion_produccion.sql
-- SPRINT: 2 - Fase 10 (Verificación integral y actualización controlada)
-- DESTINO: amazon_market
-- PRUEBA CONTROLADA: amazon_market_sprint2_test, solo con la variable explícita
--   @SPRINT2_FASE10_MODO_PRUEBA = 'VALIDAR_FASE10'.
--
-- EJECUCIÓN: MySQL/MariaDB CLI con --abort-source-on-error, después de crear,
-- restaurar y verificar el respaldo indicado en las variables de sesión.
-- Este script archiva las tablas de operaciones antiguas, limpia las tablas
-- operativas conservando catálogo/IDs/correlativos, aplica 00/01/02 y verifica
-- el estado final. No cambia .env.
-- =============================================================================

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_estado VARCHAR(20);
    DECLARE v_backup_archivo VARCHAR(512);
    DECLARE v_backup_sha256 CHAR(64);

    SET v_db_actual = DATABASE();
    SET v_backup_archivo = NULLIF(TRIM(COALESCE(@SPRINT2_FASE10_BACKUP_ARCHIVO, '')), '');
    SET v_backup_sha256 = UPPER(TRIM(COALESCE(@SPRINT2_FASE10_BACKUP_SHA256, '')));

    IF v_db_actual = 'amazon_market_sprint2_test' THEN
        IF COALESCE(@SPRINT2_FASE10_MODO_PRUEBA, '') <> 'VALIDAR_FASE10' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'PROTECCION ACTIVA: para probar Fase 10 en amazon_market_sprint2_test declare @SPRINT2_FASE10_MODO_PRUEBA = VALIDAR_FASE10.';
        END IF;
    ELSEIF v_db_actual <> 'amazon_market' OR v_db_actual IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: Fase 10 solo admite amazon_market o amazon_market_sprint2_test en modo de prueba.';
    ELSEIF COALESCE(@SPRINT2_FASE10_MODO_PRUEBA, '') <> '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: la variable de modo de prueba no puede usarse sobre amazon_market.';
    END IF;

    IF v_backup_archivo IS NULL OR CHAR_LENGTH(v_backup_archivo) > 512
       OR v_backup_sha256 NOT REGEXP '^[0-9A-F]{64}$' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION FALLIDA: declare el archivo de respaldo y su SHA-256 de 64 caracteres antes de ejecutar Fase 10.';
    END IF;

    CREATE TABLE IF NOT EXISTS sprint2_fase10_control (
        id_fase TINYINT UNSIGNED NOT NULL,
        estado ENUM('INICIADA','ARCHIVADA','COMPLETADA') NOT NULL,
        respaldo_archivo VARCHAR(512) NOT NULL,
        respaldo_sha256 CHAR(64) NOT NULL,
        productos_origen INT UNSIGNED NOT NULL DEFAULT 0,
        ventas_origen INT UNSIGNED NOT NULL DEFAULT 0,
        detalles_venta_origen INT UNSIGNED NOT NULL DEFAULT 0,
        compras_origen INT UNSIGNED NOT NULL DEFAULT 0,
        detalles_compra_origen INT UNSIGNED NOT NULL DEFAULT 0,
        historial_compra_origen INT UNSIGNED NOT NULL DEFAULT 0,
        kardex_origen INT UNSIGNED NOT NULL DEFAULT 0,
        iniciada_en DATETIME NOT NULL,
        completada_en DATETIME DEFAULT NULL,
        PRIMARY KEY (id_fase)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    SET v_estado = COALESCE(
        (SELECT estado FROM sprint2_fase10_control WHERE id_fase = 10 LIMIT 1),
        'NUNCA'
    );

    IF v_estado = 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Fase 10 ya figura COMPLETADA; se bloquea una segunda limpieza para preservar nuevas operaciones.';
    END IF;

    IF v_estado = 'NUNCA' THEN
        INSERT INTO sprint2_fase10_control (
            id_fase, estado, respaldo_archivo, respaldo_sha256,
            productos_origen, ventas_origen, detalles_venta_origen,
            compras_origen, detalles_compra_origen, historial_compra_origen,
            kardex_origen, iniciada_en
        )
        SELECT 10, 'INICIADA', v_backup_archivo, v_backup_sha256,
               (SELECT COUNT(*) FROM producto),
               (SELECT COUNT(*) FROM venta),
               (SELECT COUNT(*) FROM detalle_venta),
               (SELECT COUNT(*) FROM compra),
               (SELECT COUNT(*) FROM detalle_compra),
               (SELECT COUNT(*) FROM compra_historial),
               (SELECT COUNT(*) FROM kardex),
               NOW();
    ELSE
        UPDATE sprint2_fase10_control
           SET estado = 'INICIADA',
               respaldo_archivo = v_backup_archivo,
               respaldo_sha256 = v_backup_sha256,
               iniciada_en = NOW(),
               completada_en = NULL
         WHERE id_fase = 10;
    END IF;

    SET @SPRINT2_FASE10_AUTORIZADA = 'EJECUTAR_FASE10';
END$$

DELIMITER ;

-- Copias con estructura y tipos originales; CREATE TABLE LIKE no copia claves
-- foráneas, así el archivo conserva IDs y referencias sin bloquear el retiro.
CREATE TABLE IF NOT EXISTS sprint2_archivo_kardex LIKE kardex;
CREATE TABLE IF NOT EXISTS sprint2_archivo_compra_historial LIKE compra_historial;
CREATE TABLE IF NOT EXISTS sprint2_archivo_detalle_compra LIKE detalle_compra;
CREATE TABLE IF NOT EXISTS sprint2_archivo_compra LIKE compra;
CREATE TABLE IF NOT EXISTS sprint2_archivo_detalle_venta LIKE detalle_venta;
CREATE TABLE IF NOT EXISTS sprint2_archivo_venta LIKE venta;

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_registros_sin_archivar INT DEFAULT 0;

    IF DATABASE() <> 'amazon_market'
       AND NOT (DATABASE() = 'amazon_market_sprint2_test' AND COALESCE(@SPRINT2_FASE10_MODO_PRUEBA, '') = 'VALIDAR_FASE10') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: el archivado solo se permite en el destino autorizado de Fase 10.';
    END IF;

    START TRANSACTION;

    REPLACE INTO sprint2_archivo_kardex SELECT * FROM kardex;
    REPLACE INTO sprint2_archivo_compra_historial SELECT * FROM compra_historial;
    REPLACE INTO sprint2_archivo_detalle_compra SELECT * FROM detalle_compra;
    REPLACE INTO sprint2_archivo_compra SELECT * FROM compra;
    REPLACE INTO sprint2_archivo_detalle_venta SELECT * FROM detalle_venta;
    REPLACE INTO sprint2_archivo_venta SELECT * FROM venta;

    SELECT
        (SELECT COUNT(*) FROM kardex s LEFT JOIN sprint2_archivo_kardex a ON a.id_kardex = s.id_kardex WHERE a.id_kardex IS NULL)
      + (SELECT COUNT(*) FROM compra_historial s LEFT JOIN sprint2_archivo_compra_historial a ON a.id_historial = s.id_historial WHERE a.id_historial IS NULL)
      + (SELECT COUNT(*) FROM detalle_compra s LEFT JOIN sprint2_archivo_detalle_compra a ON a.id_detalle_compra = s.id_detalle_compra WHERE a.id_detalle_compra IS NULL)
      + (SELECT COUNT(*) FROM compra s LEFT JOIN sprint2_archivo_compra a ON a.id_compra = s.id_compra WHERE a.id_compra IS NULL)
      + (SELECT COUNT(*) FROM detalle_venta s LEFT JOIN sprint2_archivo_detalle_venta a ON a.id_detalle_venta = s.id_detalle_venta WHERE a.id_detalle_venta IS NULL)
      + (SELECT COUNT(*) FROM venta s LEFT JOIN sprint2_archivo_venta a ON a.id_venta = s.id_venta WHERE a.id_venta IS NULL)
      INTO v_registros_sin_archivar;

    IF v_registros_sin_archivar > 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ARCHIVADO INCOMPLETO: hay filas de operaciones sin copia; no se elimina el historial activo.';
    END IF;

    -- Se conserva el orden de dependencias y no se reinician IDs/correlativos.
    DELETE FROM kardex;
    DELETE FROM compra_historial;
    DELETE FROM detalle_compra;
    DELETE FROM compra;
    DELETE FROM detalle_venta;
    DELETE FROM venta;
    UPDATE producto SET stock_actual = 0;

    UPDATE sprint2_fase10_control
       SET estado = 'ARCHIVADA'
     WHERE id_fase = 10;

    COMMIT;
END$$

DELIMITER ;

-- El escenario limpio (00) verifica su protección y repite la limpieza de forma
-- segura; 01 y 02 aplican estructura, permisos y subcategorías para la Fase 10.
SOURCE C:/xampp/htdocs/Amazon-Market/database/segundo_sprint/00_preparacion_escenario_limpio_pruebas.sql
SOURCE C:/xampp/htdocs/Amazon-Market/database/segundo_sprint/01_estructura_inventario_sprint2.sql
SOURCE C:/xampp/htdocs/Amazon-Market/database/segundo_sprint/02_configuracion_permisos_sprint2.sql

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_estado VARCHAR(20);
    DECLARE v_productos_origen INT UNSIGNED DEFAULT 0;
    DECLARE v_filas_activas INT DEFAULT 0;
    DECLARE v_stock_no_cero INT DEFAULT 0;
    DECLARE v_producto_sin_subcategoria INT DEFAULT 0;
    DECLARE v_archivo_incompleto INT DEFAULT 0;

    SET v_db_actual = DATABASE();
    IF v_db_actual <> 'amazon_market'
       AND NOT (v_db_actual = 'amazon_market_sprint2_test' AND COALESCE(@SPRINT2_FASE10_MODO_PRUEBA, '') = 'VALIDAR_FASE10') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: la verificación final solo se permite en el destino autorizado de Fase 10.';
    END IF;

    SELECT estado, productos_origen
      INTO v_estado, v_productos_origen
      FROM sprint2_fase10_control
     WHERE id_fase = 10;

    SELECT (SELECT COUNT(*) FROM venta)
         + (SELECT COUNT(*) FROM detalle_venta)
         + (SELECT COUNT(*) FROM compra)
         + (SELECT COUNT(*) FROM detalle_compra)
         + (SELECT COUNT(*) FROM compra_historial)
         + (SELECT COUNT(*) FROM kardex)
      INTO v_filas_activas;

    SELECT COUNT(*) INTO v_stock_no_cero FROM producto WHERE stock_actual <> 0;
    SELECT COUNT(*) INTO v_producto_sin_subcategoria FROM producto WHERE id_subcategoria IS NULL;

    SELECT
        GREATEST(0, v_productos_origen - (SELECT COUNT(*) FROM producto))
      + GREATEST(0, (SELECT ventas_origen FROM sprint2_fase10_control WHERE id_fase=10) - (SELECT COUNT(*) FROM sprint2_archivo_venta))
      + GREATEST(0, (SELECT detalles_venta_origen FROM sprint2_fase10_control WHERE id_fase=10) - (SELECT COUNT(*) FROM sprint2_archivo_detalle_venta))
      + GREATEST(0, (SELECT compras_origen FROM sprint2_fase10_control WHERE id_fase=10) - (SELECT COUNT(*) FROM sprint2_archivo_compra))
      + GREATEST(0, (SELECT detalles_compra_origen FROM sprint2_fase10_control WHERE id_fase=10) - (SELECT COUNT(*) FROM sprint2_archivo_detalle_compra))
      + GREATEST(0, (SELECT historial_compra_origen FROM sprint2_fase10_control WHERE id_fase=10) - (SELECT COUNT(*) FROM sprint2_archivo_compra_historial))
      + GREATEST(0, (SELECT kardex_origen FROM sprint2_fase10_control WHERE id_fase=10) - (SELECT COUNT(*) FROM sprint2_archivo_kardex))
      INTO v_archivo_incompleto;

    IF v_estado <> 'ARCHIVADA' OR v_filas_activas <> 0
       OR (SELECT COUNT(*) FROM producto) <> v_productos_origen
       OR v_stock_no_cero <> 0 OR v_producto_sin_subcategoria <> 0
       OR v_archivo_incompleto <> 0
       OR (SELECT COUNT(*) FROM subcategoria) = 0
       OR (SELECT COUNT(*) FROM permiso WHERE slug IN ('inventario.ajustes','reportes.kardex','reportes.variaciones','reportes.exportar')) <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION FINAL FALLIDA: revise catálogo, stock, archivo, operaciones activas y configuración antes de cerrar Fase 10.';
    END IF;

    UPDATE sprint2_fase10_control
       SET estado = 'COMPLETADA',
           completada_en = NOW()
     WHERE id_fase = 10;

    SET @SPRINT2_FASE10_AUTORIZADA = NULL;
END$$

DELIMITER ;

-- =============================================================================
-- Fin. Ejecute con el cliente `mysql --abort-source-on-error`; valide el
-- registro `sprint2_fase10_control` y el hash del respaldo antes de habilitar
-- la aplicación sobre esta base.
-- =============================================================================
