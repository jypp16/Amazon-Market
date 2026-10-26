-- Sprint 2: actualización de una base ANTERIOR conservando operaciones y stock.
-- Archivo autónomo para MariaDB 10.4+; no ejecutar 00/01/02/10 antes ni después.
-- Aplicación detenida, respaldo propio verificado y mysql --abort-source-on-error.
-- En la misma conexión declarar @SPRINT2_11_BASE_CONFIRMADA, @SPRINT2_11_RESPALDO,
-- @SPRINT2_11_SHA256 y @SPRINT2_11_MANTENIMIENTO='APLICACION_DETENIDA'.
-- Opcional: @SPRINT2_11_ID_ADMIN (debe existir, estar activo y tener id_rol=1).
-- No reconstruye el Kardex antiguo: lo conserva como kardex_legado_sprint2.
-- El nuevo Kardex parte del stock EXISTENTE, identificado como saldo de migración.
-- No elimina operaciones/productos ni pone existencias en cero. Las asignaciones
-- de permisos sí se ajustan para reservar los reportes al Administrador.
-- DDL tiene commits implícitos: ante un error detener el despliegue y revisar.

DELIMITER $$
BEGIN NOT ATOMIC
    DECLARE v_db VARCHAR(64);
    DECLARE v_invalidos INT DEFAULT 0;
    SET v_db=DATABASE();
    IF v_db IS NULL OR v_db IN ('mysql','information_schema','performance_schema','sys')
       OR v_db NOT REGEXP '^[A-Za-z0-9_]+$'
       OR BINARY COALESCE(@SPRINT2_11_BASE_CONFIRMADA,'')<>BINARY v_db THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='BASE_NO_CONFIRMADA: seleccione y confirme explícitamente la base de sus propios datos.';
    END IF;
    IF COALESCE(@SPRINT2_11_RESPALDO,'')='' OR CHAR_LENGTH(@SPRINT2_11_RESPALDO)>512
       OR UPPER(COALESCE(@SPRINT2_11_SHA256,'')) NOT REGEXP '^[0-9A-F]{64}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='RESPALDO_REQUERIDO: cree y verifique su respaldo externo; declare ruta y SHA-256 reales.';
    END IF;
    IF COALESCE(@SPRINT2_11_MANTENIMIENTO,'')<>'APLICACION_DETENIDA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MANTENIMIENTO_REQUERIDO: detenga Apache/aplicación para impedir escrituras durante la migración.';
    END IF;
    IF VERSION() NOT LIKE '%MariaDB%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MOTOR_NO_COMPATIBLE: este archivo está preparado y probado en MariaDB de XAMPP.';
    END IF;
    IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=v_db AND table_name IN ('sprint2_migracion11_control','kardex_legado_sprint2')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIGRACION11_YA_REGISTRADA: no repetir; si quedó incompleta revise el respaldo y el estado antes de continuar.';
    END IF;
    IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=v_db AND ((table_name='kardex' AND column_name='origen_operacion') OR (table_name='producto' AND column_name='id_subcategoria') OR (table_name='detalle_compra' AND column_name='version_detalle')))
       OR EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=v_db AND table_name IN ('sprint2_fase10_control','operacion_inventario','subcategoria','control_solicitud_idempotencia','reporte_consulta_snapshot','reporte_consulta_snapshot_item')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SPRINT2_YA_INSTALADO: no aplicar sobre bases de Fase 10, ya migradas o parcialmente modificadas.';
    END IF;
    SELECT COUNT(*) INTO v_invalidos FROM information_schema.tables WHERE table_schema=v_db AND table_type='BASE TABLE'
        AND table_name IN ('producto','categoria','unidad_medida','usuario','rol','permiso','rol_permiso','compra','detalle_compra','compra_historial','venta','detalle_venta','kardex','presentacion','cliente','proveedor','serie_comprobante','tipo_comprobante','tipo_documento','metodo_pago');
    IF v_invalidos<>20 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ESQUEMA_NO_COMPATIBLE: faltan tablas del esquema anterior. No use este script como instalador de una base vacía.';
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=v_db AND table_name='kardex')<>11
       OR (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=v_db AND table_name='kardex' AND column_name IN ('id_kardex','id_producto','id_usuario','tipo_movimiento','cantidad','stock_anterior','stock_resultante','id_accion_compra','id_detalle_compra','id_accion_venta','fecha_movimiento'))<>11 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='KARDEX_NO_COMPATIBLE: la estructura anterior difiere de la prevista; requiere revisión antes de migrar.';
    END IF;
    IF EXISTS(SELECT 1 FROM information_schema.referential_constraints WHERE constraint_schema=v_db AND referenced_table_name='kardex')
       OR EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema=v_db AND event_object_table IN ('producto','categoria','kardex')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ESQUEMA_PERSONALIZADO: existen dependencias o triggers anteriores que requieren revisión.';
    END IF;
    SELECT COUNT(*) INTO v_invalidos FROM producto WHERE stock_actual IS NULL OR stock_minimo IS NULL OR precio_venta IS NULL
       OR stock_actual<0 OR stock_actual<>FLOOR(stock_actual)
       OR stock_actual>2147483647 OR stock_minimo<0 OR stock_minimo<>FLOOR(stock_minimo)
       OR stock_minimo>2147483647 OR precio_venta<=0 OR CHAR_LENGTH(TRIM(nombre))=0 OR CHAR_LENGTH(TRIM(codigo_barra))=0;
    IF v_invalidos>0 THEN
        SELECT id_producto,codigo_barra,stock_actual,stock_minimo,precio_venta FROM producto
        WHERE stock_actual IS NULL OR stock_minimo IS NULL OR precio_venta IS NULL
           OR stock_actual<0 OR stock_actual<>FLOOR(stock_actual) OR stock_actual>2147483647
           OR stock_minimo<0 OR stock_minimo<>FLOOR(stock_minimo) OR stock_minimo>2147483647
           OR precio_venta<=0 OR CHAR_LENGTH(TRIM(nombre))=0 OR CHAR_LENGTH(TRIM(codigo_barra))=0;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DATOS_INCOMPATIBLES: revise los productos listados. No se redondearon ni cambiaron existencias, mínimos o precios.';
    END IF;
    IF EXISTS(SELECT 1 FROM producto p LEFT JOIN categoria c ON c.id_categoria=p.id_categoria LEFT JOIN unidad_medida u ON u.id_unidad=p.id_unidad
              WHERE c.id_categoria IS NULL OR u.id_unidad IS NULL OR CHAR_LENGTH(TRIM(c.nombre))=0 OR CHAR_LENGTH(TRIM(u.abreviatura))=0)
       OR EXISTS(SELECT 1 FROM detalle_compra WHERE cantidad_presentaciones<1 OR equivalencia_aplicada<1 OR cantidad_base<1
                 OR cantidad_presentaciones<>FLOOR(cantidad_presentaciones) OR equivalencia_aplicada<>FLOOR(equivalencia_aplicada)
                 OR cantidad_base<>FLOOR(cantidad_base) OR cantidad_base<>cantidad_presentaciones*equivalencia_aplicada) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DATOS_INCOMPATIBLES: hay referencias de catálogo o cantidades de compra que no admiten las reglas del Sprint 2; se conservan sin cambios.';
    END IF;
    IF EXISTS(SELECT 1 FROM categoria WHERE estado IS NULL OR estado NOT IN (0,1)) THEN
        SELECT id_categoria,nombre,estado FROM categoria WHERE estado IS NULL OR estado NOT IN (0,1);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CATEGORIA_INCOMPATIBLE: revise los estados listados antes de crear subcategorías. No se modificaron datos.';
    END IF;
    IF @SPRINT2_11_ID_ADMIN IS NOT NULL AND CAST(@SPRINT2_11_ID_ADMIN AS CHAR) NOT REGEXP '^[1-9][0-9]*$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ADMIN_INVALIDO: indique un identificador válido de Administrador activo.';
    END IF;
    SELECT MIN(u.id_usuario) INTO @SPRINT2_11_ADMIN FROM usuario u JOIN rol r ON r.id_rol=u.id_rol
       WHERE u.id_rol=1 AND u.estado=1 AND CHAR_LENGTH(TRIM(u.nombre))>0
         AND (@SPRINT2_11_ID_ADMIN IS NULL OR u.id_usuario=@SPRINT2_11_ID_ADMIN);
    IF @SPRINT2_11_ADMIN IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ADMIN_INVALIDO: no existe un Administrador activo para identificar la migración.';
    END IF;
    SET @SPRINT2_11_LOCK=CONCAT('sprint2_m11_',MD5(v_db));
    IF COALESCE(GET_LOCK(@SPRINT2_11_LOCK,0),0)<>1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MIGRACION_EN_CURSO: otra conexión está actualizando esta base.';
    END IF;
    SET @SPRINT2_11_AUTORIZADA=v_db;
END$$
DELIMITER ;

CREATE TABLE sprint2_migracion11_control (
    id_migracion TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    estado ENUM('EN_ESTRUCTURA','COMPLETADA') NOT NULL,
    fecha_corte DATETIME NOT NULL,
    id_administrador INT NOT NULL,
    respaldo_archivo VARCHAR(512) NOT NULL,
    respaldo_sha256 CHAR(64) NOT NULL,
    conteos_originales LONGTEXT NOT NULL,
    siguiente_kardex BIGINT UNSIGNED NOT NULL,
    completada_en DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO sprint2_migracion11_control
SELECT 11,'EN_ESTRUCTURA',NOW(),@SPRINT2_11_ADMIN,@SPRINT2_11_RESPALDO,UPPER(@SPRINT2_11_SHA256),
       JSON_OBJECT('producto',(SELECT COUNT(*) FROM producto),'venta',(SELECT COUNT(*) FROM venta),
          'detalle_venta',(SELECT COUNT(*) FROM detalle_venta),'compra',(SELECT COUNT(*) FROM compra),
          'detalle_compra',(SELECT COUNT(*) FROM detalle_compra),'compra_historial',(SELECT COUNT(*) FROM compra_historial),
          'kardex',(SELECT COUNT(*) FROM kardex)),
       COALESCE((SELECT auto_increment FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='kardex'),1),NULL;

CREATE TABLE sprint2_migracion11_saldos (
    id_producto INT NOT NULL PRIMARY KEY,
    stock_original INT NOT NULL,
    stock_minimo_original INT NOT NULL,
    CONSTRAINT fk_migracion11_producto FOREIGN KEY(id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO sprint2_migracion11_saldos SELECT id_producto,stock_actual,stock_minimo FROM producto;

-- Se conserva la tabla anterior completa; no se eliminan sus filas.
RENAME TABLE kardex TO kardex_legado_sprint2;
CREATE TABLE kardex LIKE kardex_legado_sprint2;
DELIMITER $$
BEGIN NOT ATOMIC
    -- Los nombres de FK son globales en MariaDB: liberar los del legado para el
    -- nuevo Kardex. Esto cambia restricciones, no datos ni identificadores.
    SELECT GROUP_CONCAT(CONCAT('DROP FOREIGN KEY `',constraint_name,'`') SEPARATOR ', ')
      INTO @SPRINT2_11_FKS FROM information_schema.referential_constraints
      WHERE constraint_schema=DATABASE() AND table_name='kardex_legado_sprint2';
    IF @SPRINT2_11_FKS IS NOT NULL THEN
        SET @SPRINT2_11_DDL=CONCAT('ALTER TABLE kardex_legado_sprint2 ',@SPRINT2_11_FKS);
        PREPARE s11 FROM @SPRINT2_11_DDL; EXECUTE s11; DEALLOCATE PREPARE s11;
    END IF;
    SELECT siguiente_kardex INTO @SPRINT2_11_NEXT FROM sprint2_migracion11_control WHERE id_migracion=11;
    SET @SPRINT2_11_DDL=CONCAT('ALTER TABLE kardex AUTO_INCREMENT=',@SPRINT2_11_NEXT);
    PREPARE s11 FROM @SPRINT2_11_DDL; EXECUTE s11; DEALLOCATE PREPARE s11;
END$$
DELIMITER ;

-- INICIO_ESTRUCTURA_AUTONOMA_11

-- Bloque autónomo equivalente a 01_estructura_inventario_sprint2.sql; no ejecuta el archivo original.
DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_registros_incompatibles INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    IF COALESCE(@SPRINT2_11_AUTORIZADA, '') <> DATABASE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: bloque exclusivo de la migracion 11 autorizada.';
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
        'SALDO_INICIAL_MIGRACION',
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
          (`origen_operacion` in ('SALDO_INICIAL_MIGRACION', 'COMPRA', 'ANULACION_VENTA', 'AJUSTE_POSITIVO') and `sentido_movimiento` = 'INCREMENTO')
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
    IF NEW.origen_operacion = 'SALDO_INICIAL_MIGRACION' THEN
        IF COALESCE(@SPRINT2_11_AUTORIZADA,'') <> DATABASE()
           OR NOT EXISTS(SELECT 1 FROM sprint2_migracion11_control WHERE id_migracion=11 AND estado='EN_ESTRUCTURA') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SALDO_MIGRACION_CERRADO: solo se permite en la instalación inicial de la migración 11.';
        END IF;
        IF NEW.id_accion_compra IS NOT NULL OR NEW.id_detalle_compra IS NOT NULL
           OR NEW.id_accion_venta IS NOT NULL OR NEW.id_detalle_venta IS NOT NULL
           OR NEW.id_operacion_inventario IS NOT NULL
           OR NEW.stock_anterior <> 0
           OR NOT EXISTS(SELECT 1 FROM sprint2_migracion11_saldos s
                         WHERE s.id_producto=NEW.id_producto AND s.stock_original=NEW.cantidad AND s.stock_original=NEW.stock_resultante)
           OR NEW.id_usuario <> (SELECT id_administrador FROM sprint2_migracion11_control WHERE id_migracion=11)
           OR NEW.fecha_movimiento <> (SELECT fecha_corte FROM sprint2_migracion11_control WHERE id_migracion=11)
           OR NEW.referencia_operacion <> CONCAT('MIGRACION11:',NEW.id_producto) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SALDO_MIGRACION_INVALIDO: debe corresponder al saldo conservado sin referencias comerciales ficticias.';
        END IF;
    END IF;
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



-- Bloque autónomo equivalente a 02_configuracion_permisos_sprint2.sql; no ejecuta el archivo original.
DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    SET v_db_actual = DATABASE();

    IF COALESCE(@SPRINT2_11_AUTORIZADA, '') <> DATABASE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION ACTIVA: bloque exclusivo de la migracion 11 autorizada.';
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


-- FIN_ESTRUCTURA_AUTONOMA_11

ALTER TABLE kardex
    ADD CONSTRAINT fk_kardex11_producto FOREIGN KEY(id_producto) REFERENCES producto(id_producto) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_kardex11_usuario FOREIGN KEY(id_usuario) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_kardex11_compra FOREIGN KEY(id_accion_compra) REFERENCES compra(id_compra) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_kardex11_venta FOREIGN KEY(id_accion_venta) REFERENCES venta(id_venta) ON DELETE RESTRICT,
    ADD COLUMN producto_saldo_migracion INT GENERATED ALWAYS AS (CASE WHEN origen_operacion='SALDO_INICIAL_MIGRACION' THEN id_producto ELSE NULL END) STORED,
    ADD UNIQUE KEY uq_kardex11_saldo_inicial(producto_saldo_migracion);

DELIMITER $$
CREATE TRIGGER trg_kardex_legado_sprint2_insert BEFORE INSERT ON kardex_legado_sprint2 FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='LEGADO_INMUTABLE: el Kardex anterior se conserva exclusivamente como historial.'; END$$
CREATE TRIGGER trg_kardex_legado_sprint2_update BEFORE UPDATE ON kardex_legado_sprint2 FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='LEGADO_INMUTABLE: el Kardex anterior se conserva exclusivamente como historial.'; END$$
CREATE TRIGGER trg_kardex_legado_sprint2_delete BEFORE DELETE ON kardex_legado_sprint2 FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='LEGADO_INMUTABLE: el Kardex anterior se conserva exclusivamente como historial.'; END$$
DELIMITER ;

-- La entrada registra el saldo existente; NO actualiza producto.stock_actual.
START TRANSACTION;
INSERT INTO kardex (
    id_producto,id_usuario,origen_operacion,sentido_movimiento,tipo_movimiento,
    cantidad,stock_anterior,stock_resultante,referencia_operacion,justificacion,
    condicion_lote,producto_codigo_snapshot,producto_nombre_snapshot,
    id_categoria_snapshot,categoria_nombre_snapshot,id_subcategoria_snapshot,subcategoria_nombre_snapshot,
    id_unidad_snapshot,unidad_abreviatura_snapshot,responsable_nombre_snapshot,responsable_rol_snapshot,fecha_movimiento
)
SELECT p.id_producto,m.id_administrador,'SALDO_INICIAL_MIGRACION','INCREMENTO','Saldo inicial de migración',
    s.stock_original,0,s.stock_original,CONCAT('MIGRACION11:',p.id_producto),
    'Existencias conservadas al migrar. Clasificación vigente en la migración; historial anterior en kardex_legado_sprint2.',
    'NO_APLICA',p.codigo_barra,p.nombre,p.id_categoria,c.nombre,p.id_subcategoria,sc.nombre,
    p.id_unidad,um.abreviatura,u.nombre,r.nombre,m.fecha_corte
FROM sprint2_migracion11_saldos s JOIN producto p ON p.id_producto=s.id_producto
JOIN categoria c ON c.id_categoria=p.id_categoria JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
JOIN unidad_medida um ON um.id_unidad=p.id_unidad
JOIN sprint2_migracion11_control m ON m.id_migracion=11
JOIN usuario u ON u.id_usuario=m.id_administrador JOIN rol r ON r.id_rol=u.id_rol
WHERE s.stock_original>0;
COMMIT;

DELIMITER $$
BEGIN NOT ATOMIC
    DECLARE v_conteos LONGTEXT;
    SELECT conteos_originales INTO v_conteos FROM sprint2_migracion11_control WHERE id_migracion=11;
    IF (SELECT COUNT(*) FROM producto)<>JSON_VALUE(v_conteos,'$.producto')
       OR (SELECT COUNT(*) FROM venta)<>JSON_VALUE(v_conteos,'$.venta')
       OR (SELECT COUNT(*) FROM detalle_venta)<>JSON_VALUE(v_conteos,'$.detalle_venta')
       OR (SELECT COUNT(*) FROM compra)<>JSON_VALUE(v_conteos,'$.compra')
       OR (SELECT COUNT(*) FROM detalle_compra)<>JSON_VALUE(v_conteos,'$.detalle_compra')
       OR (SELECT COUNT(*) FROM compra_historial)<>JSON_VALUE(v_conteos,'$.compra_historial')
       OR (SELECT COUNT(*) FROM kardex_legado_sprint2)<>JSON_VALUE(v_conteos,'$.kardex')
       OR EXISTS(SELECT 1 FROM sprint2_migracion11_saldos s LEFT JOIN producto p ON p.id_producto=s.id_producto
                 WHERE p.id_producto IS NULL OR p.stock_actual<>s.stock_original OR p.stock_minimo<>s.stock_minimo_original)
       OR EXISTS(SELECT 1 FROM producto WHERE id_subcategoria IS NULL)
       OR (SELECT COUNT(*) FROM kardex WHERE origen_operacion='SALDO_INICIAL_MIGRACION')<>(SELECT COUNT(*) FROM sprint2_migracion11_saldos WHERE stock_original>0)
       OR EXISTS(SELECT 1 FROM kardex k JOIN sprint2_migracion11_saldos s ON s.id_producto=k.id_producto
                 WHERE k.origen_operacion='SALDO_INICIAL_MIGRACION' AND (k.cantidad<>s.stock_original OR k.stock_resultante<>s.stock_original OR k.stock_anterior<>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='VERIFICACION11_FALLIDA: detener despliegue y revisar el respaldo. No repetir automáticamente.';
    END IF;
    UPDATE sprint2_migracion11_control SET estado='COMPLETADA',completada_en=NOW() WHERE id_migracion=11;
    SET @SPRINT2_11_AUTORIZADA=NULL;
    DO RELEASE_LOCK(@SPRINT2_11_LOCK);
END$$
DELIMITER ;

SELECT estado,fecha_corte,respaldo_archivo,conteos_originales FROM sprint2_migracion11_control WHERE id_migracion=11;
