-- =============================================================================
-- ARCHIVO: 01_estructura_inventario_sprint2.sql
-- SPRINT: 2 - Fase 2 (Estructura de datos, seguridad e infraestructura de movimientos)
-- TIPO: Migración estructural (DDL y restricciones de integridad)
-- ORDEN DE EJECUCIÓN: 01 (Posterior a 00_preparacion_escenario_limpio_pruebas.sql)
-- BASE DE DATOS DE DESTINO: amazon_market_sprint2_test (EXCLUSIVAMENTE EN FASES 1–9)
--
-- PROPÓSITO:
--   Implementar el esquema de datos y las restricciones de integridad del Sprint 2:
--     1. Tabla `subcategoria` y su relación compuesta con `categoria` y `producto`
--        para garantizar correspondencia estricta entre categoría y subcategoría.
--     2. Conversión segura de `producto.stock_actual` y `producto.stock_minimo`
--        a enteros (`INT(11)`) con restricción de no negatividad (`>= 0`).
--     3. Versionado de `detalle_compra` (`version_detalle`, `es_vigente`,
--        `id_detalle_origen`, `reemplazado_en`) para conservar referencias
--        históricas en correcciones de compras.
--     4. Tabla `operacion_inventario` para el almacenamiento transaccional de
--        bajas (`BAJA_DANO`, `BAJA_VENCIMIENTO`) y ajustes (`AJUSTE_POSITIVO`,
--        `AJUSTE_NEGATIVO`), con motivo/justificación obligatoria, lote opcional
--        y condición registrada (`CON_LOTE`, `SIN_LOTE`, `NO_APLICA`).
--     5. Ampliación de `kardex` con representación inequívoca de origen
--        (`origen_operacion`), sentido (`sentido_movimiento`), referencias a
--        operaciones (`id_accion_compra`, `id_detalle_compra`, `id_accion_venta`,
--        `id_detalle_venta`, `id_operacion_inventario`), instantáneas históricas
--        inmutables (producto, categoría, subcategoría, unidad, responsable) e
--        índices optimizados para búsquedas, fechas, producto y paginación.
--     6. Tabla `control_solicitud_idempotencia` para impedir duplicaciones por
--        reenvío y controlar solicitudes simultáneas por usuario y operación.
--     7. Tablas `reporte_consulta_snapshot` y `reporte_consulta_snapshot_item`
--        para respaldar `referencia_consulta` administrada por el servidor.
--
-- PRECONDICIONES:
--   - Base activa: `amazon_market_sprint2_test`.
--   - Inexistencia de valores decimales o negativos en `producto.stock_actual`
--     y `producto.stock_minimo` (verificado antes de ejecutar DDL).
--
-- NOTA SOBRE DDL EN MARIADB/MYSQL:
--   Las sentencias DDL (`CREATE TABLE`, `ALTER TABLE`, `CREATE TRIGGER`) provocan
--   confirmación implícita (implicit commit) en MariaDB y no admiten rollback
--   transaccional. Por ello, todo el bloque DDL está encapsulado dentro de un
--   bloque compuesto `BEGIN NOT ATOMIC ... END` tras la guardia de base de datos
--   y la verificación de precondiciones.
-- =============================================================================

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_registros_incompatibles INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR v_db_actual <> 'amazon_market_sprint2_test' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: 01_estructura_inventario_sprint2.sql solo puede ejecutarse sobre amazon_market_sprint2_test.';
    END IF;

    SELECT COUNT(*) INTO v_registros_incompatibles
      FROM producto
     WHERE stock_actual <> FLOOR(stock_actual)
        OR stock_minimo <> FLOOR(stock_minimo)
        OR stock_actual < 0
        OR stock_minimo < 0;

    IF v_registros_incompatibles > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PRECONDICION FALLIDA: Existen productos con stock_actual o stock_minimo decimal o negativo.';
    END IF;

    -- =========================================================================
    -- 1. TABLA `subcategoria`
    -- =========================================================================
    CREATE TABLE IF NOT EXISTS `subcategoria` (
      `id_subcategoria` int(11) NOT NULL AUTO_INCREMENT,
      `id_categoria` int(11) NOT NULL,
      `nombre` varchar(60) NOT NULL,
      `descripcion` varchar(150) DEFAULT NULL,
      `estado` tinyint(4) NOT NULL DEFAULT 1,
      PRIMARY KEY (`id_subcategoria`),
      UNIQUE KEY `uq_subcategoria_cat_nombre` (`id_categoria`, `nombre`),
      UNIQUE KEY `uq_subcategoria_id_cat` (`id_subcategoria`, `id_categoria`),
      CONSTRAINT `fk_subcategoria_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categoria` (`id_categoria`),
      CONSTRAINT `chk_subcategoria_nombre` CHECK (char_length(trim(`nombre`)) > 0),
      CONSTRAINT `chk_subcategoria_estado` CHECK (`estado` in (0, 1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- 2. ADECUACIÓN DE TABLA `producto`
    -- =========================================================================
    ALTER TABLE `producto`
      MODIFY COLUMN `stock_actual` int(11) NOT NULL DEFAULT 0,
      MODIFY COLUMN `stock_minimo` int(11) NOT NULL DEFAULT 1,
      ADD COLUMN IF NOT EXISTS `id_subcategoria` int(11) DEFAULT NULL AFTER `id_categoria`,
      ADD INDEX IF NOT EXISTS `idx_producto_cat_subcat_estado` (`id_categoria`, `id_subcategoria`, `estado`),
      ADD INDEX IF NOT EXISTS `idx_producto_nombre` (`nombre`);

    ALTER TABLE `producto`
      DROP CONSTRAINT IF EXISTS `chk_producto_stock_entero_no_negativo`;

    ALTER TABLE `producto`
      ADD CONSTRAINT `chk_producto_stock_entero_no_negativo`
        CHECK (`stock_actual` >= 0 and `stock_minimo` >= 0 and `precio_venta` > 0);

    ALTER TABLE `producto`
      DROP FOREIGN KEY IF EXISTS `fk_producto_subcategoria_categoria`;

    ALTER TABLE `producto`
      ADD CONSTRAINT `fk_producto_subcategoria_categoria`
        FOREIGN KEY (`id_subcategoria`, `id_categoria`)
        REFERENCES `subcategoria` (`id_subcategoria`, `id_categoria`);

    -- =========================================================================
    -- 3. VERSIONADO DE `detalle_compra`
    -- =========================================================================
    ALTER TABLE `detalle_compra`
      ADD COLUMN IF NOT EXISTS `version_detalle` int(11) NOT NULL DEFAULT 1 AFTER `id_compra`,
      ADD COLUMN IF NOT EXISTS `es_vigente` tinyint(1) NOT NULL DEFAULT 1 AFTER `fecha_vencimiento`,
      ADD COLUMN IF NOT EXISTS `id_detalle_origen` int(11) DEFAULT NULL AFTER `es_vigente`,
      ADD COLUMN IF NOT EXISTS `reemplazado_en` datetime DEFAULT NULL AFTER `id_detalle_origen`,
      ADD INDEX IF NOT EXISTS `idx_detalle_compra_vigente` (`id_compra`, `es_vigente`, `version_detalle`),
      ADD INDEX IF NOT EXISTS `idx_detalle_compra_origen` (`id_detalle_origen`);

    ALTER TABLE `detalle_compra`
      DROP FOREIGN KEY IF EXISTS `fk_detalle_compra_origen`;

    ALTER TABLE `detalle_compra`
      ADD CONSTRAINT `fk_detalle_compra_origen`
        FOREIGN KEY (`id_detalle_origen`)
        REFERENCES `detalle_compra` (`id_detalle_compra`);

    ALTER TABLE `detalle_compra`
      DROP CONSTRAINT IF EXISTS `chk_detalle_compra_integridad`;

    ALTER TABLE `detalle_compra`
      ADD CONSTRAINT `chk_detalle_compra_integridad` CHECK (
        `cantidad_presentaciones` >= 1
        and `equivalencia_aplicada` >= 1
        and `cantidad_base` >= 1
        and `cantidad_base` = (`cantidad_presentaciones` * `equivalencia_aplicada`)
        and `version_detalle` >= 1
        and `es_vigente` in (0, 1)
      );

    -- =========================================================================
    -- 4. TABLA `operacion_inventario`
    -- =========================================================================
    CREATE TABLE IF NOT EXISTS `operacion_inventario` (
      `id_operacion` int(11) NOT NULL AUTO_INCREMENT,
      `id_producto` int(11) NOT NULL,
      `id_usuario` int(11) NOT NULL,
      `categoria_operacion` enum('BAJA','AJUSTE') NOT NULL,
      `origen_operacion` enum('BAJA_DANO','BAJA_VENCIMIENTO','AJUSTE_POSITIVO','AJUSTE_NEGATIVO') NOT NULL,
      `tipo_movimiento` varchar(30) NOT NULL,
      `sentido_movimiento` enum('INCREMENTO','DISMINUCION') NOT NULL,
      `cantidad` int(11) NOT NULL,
      `saldo_anterior` int(11) NOT NULL,
      `saldo_anterior_esperado` int(11) DEFAULT NULL,
      `saldo_resultante` int(11) NOT NULL,
      `justificacion` varchar(255) NOT NULL,
      `numero_lote` varchar(50) DEFAULT NULL,
      `condicion_lote` enum('CON_LOTE','SIN_LOTE','NO_APLICA') NOT NULL DEFAULT 'SIN_LOTE',
      `referencia_solicitud` varchar(64) NOT NULL,
      `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id_operacion`),
      UNIQUE KEY `uq_operacion_inv_referencia` (`referencia_solicitud`),
      KEY `idx_op_inv_producto_fecha` (`id_producto`, `fecha_registro`, `id_operacion`),
      KEY `idx_op_inv_origen_sentido_fecha` (`origen_operacion`, `sentido_movimiento`, `fecha_registro`, `id_operacion`),
      KEY `fk_op_inv_usuario` (`id_usuario`),
      CONSTRAINT `fk_op_inv_producto` FOREIGN KEY (`id_producto`) REFERENCES `producto` (`id_producto`),
      CONSTRAINT `fk_op_inv_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `chk_op_inv_integridad` CHECK (
        `cantidad` >= 1
        and `saldo_anterior` >= 0
        and `saldo_resultante` >= 0
        and (`saldo_anterior_esperado` is null or `saldo_anterior_esperado` >= 0)
        and char_length(trim(`justificacion`)) > 0
        and char_length(`referencia_solicitud`) between 16 and 64
        and (
          (
            `sentido_movimiento` = 'INCREMENTO'
            and `categoria_operacion` = 'AJUSTE'
            and `origen_operacion` = 'AJUSTE_POSITIVO'
            and `saldo_resultante` = (`saldo_anterior` + `cantidad`)
          )
          or
          (
            `sentido_movimiento` = 'DISMINUCION'
            and (
              (`categoria_operacion` = 'BAJA' and `origen_operacion` in ('BAJA_DANO', 'BAJA_VENCIMIENTO'))
              or (`categoria_operacion` = 'AJUSTE' and `origen_operacion` = 'AJUSTE_NEGATIVO')
            )
            and `saldo_resultante` = (`saldo_anterior` - `cantidad`)
          )
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- 5. ADECUACIÓN DE TABLA `kardex`
    -- =========================================================================
    ALTER TABLE `kardex`
      DROP FOREIGN KEY IF EXISTS `fk_kardex_detalle_compra`;

    ALTER TABLE `kardex`
      DROP FOREIGN KEY IF EXISTS `fk_kardex_detalle_venta`;

    ALTER TABLE `kardex`
      DROP FOREIGN KEY IF EXISTS `fk_kardex_operacion_inventario`;

    ALTER TABLE `kardex`
      MODIFY COLUMN `tipo_movimiento` varchar(40) NOT NULL,
      ADD COLUMN IF NOT EXISTS `origen_operacion` enum(
        'COMPRA',
        'CORRECCION_COMPRA',
        'ANULACION_COMPRA',
        'VENTA',
        'ANULACION_VENTA',
        'BAJA_DANO',
        'BAJA_VENCIMIENTO',
        'AJUSTE_POSITIVO',
        'AJUSTE_NEGATIVO'
      ) NOT NULL DEFAULT 'COMPRA' AFTER `id_usuario`,
      ADD COLUMN IF NOT EXISTS `sentido_movimiento` enum('INCREMENTO','DISMINUCION') NOT NULL DEFAULT 'INCREMENTO' AFTER `origen_operacion`,
      ADD COLUMN IF NOT EXISTS `id_detalle_venta` int(11) DEFAULT NULL AFTER `id_accion_venta`,
      ADD COLUMN IF NOT EXISTS `id_operacion_inventario` int(11) DEFAULT NULL AFTER `id_detalle_venta`,
      ADD COLUMN IF NOT EXISTS `referencia_operacion` varchar(64) DEFAULT NULL AFTER `id_operacion_inventario`,
      ADD COLUMN IF NOT EXISTS `justificacion` varchar(255) DEFAULT NULL AFTER `referencia_operacion`,
      ADD COLUMN IF NOT EXISTS `numero_lote` varchar(50) DEFAULT NULL AFTER `justificacion`,
      ADD COLUMN IF NOT EXISTS `condicion_lote` enum('CON_LOTE','SIN_LOTE','NO_APLICA') NOT NULL DEFAULT 'NO_APLICA' AFTER `numero_lote`,
      ADD COLUMN IF NOT EXISTS `producto_codigo_snapshot` varchar(50) NOT NULL DEFAULT '' AFTER `condicion_lote`,
      ADD COLUMN IF NOT EXISTS `producto_nombre_snapshot` varchar(150) NOT NULL DEFAULT '' AFTER `producto_codigo_snapshot`,
      ADD COLUMN IF NOT EXISTS `id_categoria_snapshot` int(11) NOT NULL DEFAULT 1 AFTER `producto_nombre_snapshot`,
      ADD COLUMN IF NOT EXISTS `categoria_nombre_snapshot` varchar(60) NOT NULL DEFAULT '' AFTER `id_categoria_snapshot`,
      ADD COLUMN IF NOT EXISTS `id_subcategoria_snapshot` int(11) NOT NULL DEFAULT 1 AFTER `categoria_nombre_snapshot`,
      ADD COLUMN IF NOT EXISTS `subcategoria_nombre_snapshot` varchar(60) NOT NULL DEFAULT '' AFTER `id_subcategoria_snapshot`,
      ADD COLUMN IF NOT EXISTS `id_unidad_snapshot` int(11) NOT NULL DEFAULT 1 AFTER `subcategoria_nombre_snapshot`,
      ADD COLUMN IF NOT EXISTS `unidad_abreviatura_snapshot` varchar(15) NOT NULL DEFAULT '' AFTER `id_unidad_snapshot`,
      ADD COLUMN IF NOT EXISTS `responsable_nombre_snapshot` varchar(100) NOT NULL DEFAULT '' AFTER `unidad_abreviatura_snapshot`,
      ADD COLUMN IF NOT EXISTS `responsable_rol_snapshot` varchar(30) NOT NULL DEFAULT 'Administrador' AFTER `responsable_nombre_snapshot`;

    ALTER TABLE `kardex`
      ADD INDEX IF NOT EXISTS `fk_kardex_detalle_venta` (`id_detalle_venta`),
      ADD INDEX IF NOT EXISTS `fk_kardex_operacion_inventario` (`id_operacion_inventario`),
      ADD INDEX IF NOT EXISTS `idx_kardex_producto_fecha_id` (`id_producto`, `fecha_movimiento`, `id_kardex`),
      ADD INDEX IF NOT EXISTS `idx_kardex_fecha_id` (`fecha_movimiento`, `id_kardex`),
      ADD INDEX IF NOT EXISTS `idx_kardex_origen_sentido_fecha` (`origen_operacion`, `sentido_movimiento`, `fecha_movimiento`, `id_kardex`),
      ADD INDEX IF NOT EXISTS `idx_kardex_cat_subcat_hist` (`id_categoria_snapshot`, `id_subcategoria_snapshot`, `fecha_movimiento`, `id_kardex`);

    ALTER TABLE `kardex`
      ADD CONSTRAINT `fk_kardex_detalle_compra`
        FOREIGN KEY (`id_detalle_compra`) REFERENCES `detalle_compra` (`id_detalle_compra`) ON DELETE RESTRICT,
      ADD CONSTRAINT `fk_kardex_detalle_venta`
        FOREIGN KEY (`id_detalle_venta`) REFERENCES `detalle_venta` (`id_detalle_venta`) ON DELETE RESTRICT,
      ADD CONSTRAINT `fk_kardex_operacion_inventario`
        FOREIGN KEY (`id_operacion_inventario`) REFERENCES `operacion_inventario` (`id_operacion`) ON DELETE RESTRICT;

    ALTER TABLE `kardex`
      DROP CONSTRAINT IF EXISTS `chk_kardex_integridad`;

    ALTER TABLE `kardex`
      ADD CONSTRAINT `chk_kardex_integridad` CHECK (
        `cantidad` >= 1
        and `stock_anterior` >= 0
        and `stock_resultante` >= 0
        and (
          (`sentido_movimiento` = 'INCREMENTO' and `stock_resultante` = (`stock_anterior` + `cantidad`))
          or (`sentido_movimiento` = 'DISMINUCION' and `stock_resultante` = (`stock_anterior` - `cantidad`))
        )
        and (
          (`origen_operacion` in ('COMPRA', 'ANULACION_VENTA', 'AJUSTE_POSITIVO') and `sentido_movimiento` = 'INCREMENTO')
          or (`origen_operacion` in ('ANULACION_COMPRA', 'VENTA', 'BAJA_DANO', 'BAJA_VENCIMIENTO', 'AJUSTE_NEGATIVO') and `sentido_movimiento` = 'DISMINUCION')
          or (`origen_operacion` = 'CORRECCION_COMPRA' and `sentido_movimiento` in ('INCREMENTO', 'DISMINUCION'))
        )
      );

    -- =========================================================================
    -- 6. TABLA `control_solicitud_idempotencia`
    -- =========================================================================
    CREATE TABLE IF NOT EXISTS `control_solicitud_idempotencia` (
      `id_solicitud` int(11) NOT NULL AUTO_INCREMENT,
      `id_usuario` int(11) NOT NULL,
      `tipo_operacion` enum(
        'BAJA',
        'AJUSTE',
        'COMPRA',
        'CORRECCION_COMPRA',
        'ANULACION_COMPRA',
        'VENTA',
        'ANULACION_VENTA'
      ) NOT NULL,
      `referencia_solicitud` varchar(64) NOT NULL,
      `hash_payload` char(64) NOT NULL,
      `estado_solicitud` enum('EN_PROCESO','CONFIRMADA','FALLIDA') NOT NULL DEFAULT 'EN_PROCESO',
      `id_registro_recurso` int(11) DEFAULT NULL,
      `codigo_http` int(11) DEFAULT NULL,
      `respuesta_json` longtext DEFAULT NULL,
      `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
      `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id_solicitud`),
      UNIQUE KEY `uq_solicitud_usuario_tipo_ref` (`id_usuario`, `tipo_operacion`, `referencia_solicitud`),
      KEY `idx_solicitud_estado_fecha` (`estado_solicitud`, `creado_en`),
      CONSTRAINT `fk_solicitud_idemp_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `chk_solicitud_idemp_formato` CHECK (
        char_length(`referencia_solicitud`) between 16 and 64
        and `referencia_solicitud` regexp '^[A-Za-z0-9_-]{16,64}$'
        and char_length(`hash_payload`) = 64
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- 7. TABLAS `reporte_consulta_snapshot` Y `reporte_consulta_snapshot_item`
    -- =========================================================================
    CREATE TABLE IF NOT EXISTS `reporte_consulta_snapshot` (
      `id_snapshot` int(11) NOT NULL AUTO_INCREMENT,
      `referencia_consulta` varchar(64) NOT NULL,
      `id_usuario` int(11) NOT NULL,
      `tipo_reporte` enum('kardex','variaciones') NOT NULL,
      `hash_filtros` char(64) NOT NULL,
      `filtros_json` longtext NOT NULL,
      `resumen_json` longtext NOT NULL,
      `total_registros` int(11) NOT NULL DEFAULT 0,
      `exportable` tinyint(1) NOT NULL DEFAULT 0,
      `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
      `expira_en` datetime NOT NULL,
      PRIMARY KEY (`id_snapshot`),
      UNIQUE KEY `uq_reporte_snapshot_ref` (`referencia_consulta`),
      KEY `idx_reporte_snapshot_usuario_expira` (`id_usuario`, `tipo_reporte`, `expira_en`),
      CONSTRAINT `fk_reporte_snapshot_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `chk_reporte_snapshot_totales` CHECK (`total_registros` >= 0 and `exportable` in (0, 1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS `reporte_consulta_snapshot_item` (
      `id_snapshot` int(11) NOT NULL,
      `orden_posicion` int(11) NOT NULL,
      `id_kardex` int(11) NOT NULL,
      PRIMARY KEY (`id_snapshot`, `orden_posicion`),
      KEY `idx_snapshot_item_kardex` (`id_kardex`),
      CONSTRAINT `fk_snapshot_item_cabecera` FOREIGN KEY (`id_snapshot`) REFERENCES `reporte_consulta_snapshot` (`id_snapshot`) ON DELETE CASCADE,
      CONSTRAINT `fk_snapshot_item_kardex` FOREIGN KEY (`id_kardex`) REFERENCES `kardex` (`id_kardex`) ON DELETE RESTRICT,
      CONSTRAINT `chk_snapshot_item_orden` CHECK (`orden_posicion` >= 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

-- =============================================================================
-- 8. TRIGGERS DE INTEGRIDAD E INMUTABILIDAD EN `kardex`
-- =============================================================================
DROP TRIGGER IF EXISTS `trg_kardex_before_insert_integridad`$$
DROP TRIGGER IF EXISTS `trg_kardex_before_update_inmutable`$$

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

    IF CHAR_LENGTH(TRIM(NEW.producto_codigo_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.producto_nombre_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.categoria_nombre_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.subcategoria_nombre_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.unidad_abreviatura_snapshot)) = 0
       OR CHAR_LENGTH(TRIM(NEW.responsable_nombre_snapshot)) = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INTEGRIDAD KARDEX: Todo movimiento debe incluir los snapshots históricos completos del producto y responsable.';
    END IF;
END$$

CREATE TRIGGER `trg_kardex_before_update_inmutable`
BEFORE UPDATE ON `kardex`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'INMUTABILIDAD KARDEX: Los movimientos registrados en kardex son inmutables y no pueden modificarse.';
END$$

DELIMITER ;
