-- =============================================================================
-- ARCHIVO: 06_configuracion_permisos_y_cierre_migracion_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 06 de 06 (Requiere 05_ampliacion_idempotencia_y_snapshots_reportes_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB)
--
-- PROPÓSITO:
--   Implementar el dominio K (Permisos y asignaciones RBAC del Sprint 3) y
--   cerrar formalmente la migración verificando la conservación del 100 % de
--   los datos previos:
--     1. Insertar los 5 nuevos permisos del Sprint 3 por `slug` sin asumir
--        IDs fijos (`turnos.movimientos`, `turnos.arqueo`, `turnos.programar`,
--        `turnos.consultar_historico`, `reportes.cierres_caja`).
--     2. Asignar en `rol_permiso` dinámicamente por `slug`:
--          - Vendedor (`id_rol = 2`): `turnos.movimientos`, `turnos.arqueo`
--          - Administrador (`id_rol = 1`): `turnos.programar`,
--            `turnos.consultar_historico`, `reportes.cierres_caja`
--        Conservando intactos los 31 permisos previos y las 30 asignaciones
--        previas en `rol_permiso`.
--     3. Verificar que ningún permiso operativo de vendedor (`turnos.abrir`,
--        `turnos.cerrar`, `turnos.movimientos`, `turnos.arqueo`) esté asignado
--        al Administrador (`id_rol = 1`) y que ningún permiso administrativo
--        (`turnos.programar`, `turnos.consultar_historico`, `reportes.cierres_caja`)
--        esté asignado al Vendedor (`id_rol = 2`).
--     4. Comprobar que los conteos y sumas de todas las tablas comerciales,
--        históricas y de archivo coincidan exactamente con la línea base y
--        marcar `sprint3_migracion_control.estado = 'COMPLETADA'`.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;
    DECLARE v_base_json LONGTEXT DEFAULT NULL;
    DECLARE v_permisos_nuevos_activos INT DEFAULT 0;
    DECLARE v_asignaciones_vendedor INT DEFAULT 0;
    DECLARE v_asignaciones_admin INT DEFAULT 0;
    DECLARE v_cruces_prohibidos INT DEFAULT 0;
    DECLARE v_ventas_con_sesion INT DEFAULT 0;
    DECLARE v_tablas_totales INT DEFAULT 0;
    DECLARE v_triggers_totales INT DEFAULT 0;
    DECLARE v_lock_name VARCHAR(128);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(DATABASE()));
        IF COALESCE(IS_USED_LOCK(v_lock_name), -1) = CONNECTION_ID() THEN
            DO RELEASE_LOCK(v_lock_name);
        END IF;
        RESIGNAL;
    END;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR v_db_actual = 'amazon_market'
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 06.';
    END IF;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 a 05.';
    END IF;

    SELECT estado, paso_actual, conteos_base_json
      INTO v_estado_migracion, v_paso_migracion, v_base_json
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion = 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base.';
    END IF;

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '05_IDEMPOTENCIA_Y_REPORTES' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 06 exige estado EN_PROGRESO y paso_actual = 05_IDEMPOTENCIA_Y_REPORTES.';
    END IF;

    IF COALESCE(IS_USED_LOCK(v_lock_name), -1) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LOCK_MIGRACION_NO_VIGENTE: El paso 06 debe ejecutarse en la misma conexión que adquirió GET_LOCK en el paso 01.';
    END IF;

    START TRANSACTION;

    -- =========================================================================
    -- K.1. INSERCIÓN DE LOS 5 NUEVOS PERMISOS DEL SPRINT 3 POR `slug`
    -- =========================================================================
    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Registrar movimientos y correcciones de caja',
        'turnos.movimientos',
        'Permite al vendedor titular registrar ingresos, egresos y correcciones de caja durante su turno abierto.',
        'Turnos',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'turnos.movimientos'
    );

    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Realizar arqueo de caja',
        'turnos.arqueo',
        'Permite al vendedor titular registrar el conteo físico de efectivo y las observaciones de diferencia antes del cierre.',
        'Turnos',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'turnos.arqueo'
    );

    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Gestionar catálogo y programación de turnos',
        'turnos.programar',
        'Permite al administrador crear, editar o dar de baja turnos y programar horarios semanales de vendedores.',
        'Turnos',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'turnos.programar'
    );

    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Consultar histórico de sesiones de caja',
        'turnos.consultar_historico',
        'Permite al administrador consultar las sesiones de caja cerradas, sus movimientos, arqueos y autorizaciones.',
        'Turnos',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'turnos.consultar_historico'
    );

    INSERT INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`)
    SELECT
        'Consultar reporte de cierres de caja',
        'reportes.cierres_caja',
        'Permite al administrador generar y consultar snapshots paginados del reporte consolidado de cierres de caja.',
        'Reportes',
        1
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `permiso` WHERE `slug` = 'reportes.cierres_caja'
    );

    -- =========================================================================
    -- K.2. ASIGNACIÓN DE PERMISOS A ROLES MEDIANTE RESOLUCIÓN DINÁMICA POR `slug`
    -- =========================================================================

    -- Permisos operativos exclusivos para Vendedor (`id_rol = 2`)
    INSERT INTO `rol_permiso` (`id_rol`, `id_permiso`)
    SELECT 2, p.`id_permiso`
      FROM `permiso` p
     WHERE p.`slug` IN ('turnos.movimientos', 'turnos.arqueo')
       AND p.`estado` = 1
       AND NOT EXISTS (
           SELECT 1
             FROM `rol_permiso` rp
            WHERE rp.`id_rol` = 2
              AND rp.`id_permiso` = p.`id_permiso`
       );

    -- Permisos de gestión y consulta exclusivos para Administrador (`id_rol = 1`)
    INSERT INTO `rol_permiso` (`id_rol`, `id_permiso`)
    SELECT 1, p.`id_permiso`
      FROM `permiso` p
     WHERE p.`slug` IN ('turnos.programar', 'turnos.consultar_historico', 'reportes.cierres_caja')
       AND p.`estado` = 1
       AND NOT EXISTS (
           SELECT 1
             FROM `rol_permiso` rp
            WHERE rp.`id_rol` = 1
              AND rp.`id_permiso` = p.`id_permiso`
       );

    -- =========================================================================
    -- K.3. VALIDACIÓN DE MATRIZ RBAC Y CONSERVACIÓN DE DATOS PREVIOS
    -- =========================================================================
    SELECT COUNT(*)
      INTO v_permisos_nuevos_activos
      FROM `permiso`
     WHERE `slug` IN (
         'turnos.movimientos',
         'turnos.arqueo',
         'turnos.programar',
         'turnos.consultar_historico',
         'reportes.cierres_caja'
     )
       AND `estado` = 1;

    IF v_permisos_nuevos_activos <> 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_PERMISOS_FALLIDA: No se encuentran activos los 5 nuevos permisos del Sprint 3.';
    END IF;

    SELECT COUNT(*)
      INTO v_asignaciones_vendedor
      FROM `rol_permiso` rp
      INNER JOIN `permiso` p ON p.`id_permiso` = rp.`id_permiso`
     WHERE rp.`id_rol` = 2
       AND p.`slug` IN (
           'turnos.acceder',
           'turnos.abrir',
           'turnos.cerrar',
           'ventas.procesar',
           'turnos.movimientos',
           'turnos.arqueo'
       );

    IF v_asignaciones_vendedor <> 6 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_RBAC_VENDEDOR_FALLIDA: El rol Vendedor (2) debe poseer los 6 permisos de turnos, caja y ventas requeridos.';
    END IF;

    SELECT COUNT(*)
      INTO v_asignaciones_admin
      FROM `rol_permiso` rp
      INNER JOIN `permiso` p ON p.`id_permiso` = rp.`id_permiso`
     WHERE rp.`id_rol` = 1
       AND p.`slug` IN (
           'turnos.programar',
           'turnos.consultar_historico',
           'reportes.cierres_caja',
           'reportes.exportar'
       );

    IF v_asignaciones_admin <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_RBAC_ADMIN_FALLIDA: El rol Administrador (1) debe poseer los permisos de programación, histórico y reportes.';
    END IF;

    SELECT COUNT(*)
      INTO v_cruces_prohibidos
      FROM `rol_permiso` rp
      INNER JOIN `permiso` p ON p.`id_permiso` = rp.`id_permiso`
     WHERE (
         rp.`id_rol` = 1
         AND p.`slug` IN ('turnos.abrir', 'turnos.cerrar', 'turnos.movimientos', 'turnos.arqueo')
     )
        OR (
         rp.`id_rol` = 2
         AND p.`slug` IN ('turnos.programar', 'turnos.consultar_historico', 'reportes.cierres_caja')
     );

    IF v_cruces_prohibidos > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'POSTCONDICION_RBAC_SEPARACION_FALLIDA: Se detectaron asignaciones cruzadas prohibidas entre Administrador y Vendedor.';
    END IF;

    SELECT COUNT(*)
      INTO v_ventas_con_sesion
      FROM `venta`
     WHERE `id_sesion_caja` IS NOT NULL;

    IF v_ventas_con_sesion <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CONSERVACION_VENTAS_FALLIDA: Todas las ventas históricas previas deben conservar id_sesion_caja = NULL.';
    END IF;

    IF (SELECT COUNT(*) FROM `usuario`) <> CAST(JSON_VALUE(v_base_json, '$.usuario') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `rol`) <> CAST(JSON_VALUE(v_base_json, '$.rol') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `permiso`) <> (CAST(JSON_VALUE(v_base_json, '$.permiso') AS UNSIGNED) + 5)
       OR (SELECT COUNT(*) FROM `rol_permiso`) <> (CAST(JSON_VALUE(v_base_json, '$.rol_permiso') AS UNSIGNED) + 5)
       OR (SELECT COUNT(*) FROM `producto`) <> CAST(JSON_VALUE(v_base_json, '$.producto') AS UNSIGNED)
       OR (SELECT COALESCE(SUM(`stock_actual`), 0) FROM `producto`) <> CAST(JSON_VALUE(v_base_json, '$.stock_total') AS SIGNED)
       OR (SELECT COUNT(*) FROM `venta`) <> CAST(JSON_VALUE(v_base_json, '$.venta') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `detalle_venta`) <> CAST(JSON_VALUE(v_base_json, '$.detalle_venta') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `compra`) <> CAST(JSON_VALUE(v_base_json, '$.compra') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `detalle_compra`) <> CAST(JSON_VALUE(v_base_json, '$.detalle_compra') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `compra_historial`) <> CAST(JSON_VALUE(v_base_json, '$.compra_historial') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `kardex`) <> CAST(JSON_VALUE(v_base_json, '$.kardex') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `operacion_inventario`) <> CAST(JSON_VALUE(v_base_json, '$.operacion_inventario') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `serie_comprobante`) <> CAST(JSON_VALUE(v_base_json, '$.serie_comprobante') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `control_solicitud_idempotencia`) <> CAST(JSON_VALUE(v_base_json, '$.control_solicitud_idempotencia') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `reporte_consulta_snapshot`) <> CAST(JSON_VALUE(v_base_json, '$.reporte_consulta_snapshot') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `reporte_consulta_snapshot_item`) <> CAST(JSON_VALUE(v_base_json, '$.reporte_consulta_snapshot_item') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_venta`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_venta') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_detalle_venta`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_detalle_venta') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_compra`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_compra') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_detalle_compra`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_detalle_compra') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_compra_historial`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_compra_historial') AS UNSIGNED)
       OR (SELECT COUNT(*) FROM `sprint2_archivo_kardex`) <> CAST(JSON_VALUE(v_base_json, '$.sprint2_archivo_kardex') AS UNSIGNED) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CONSERVACION_DATOS_FALLIDA: Se detectó una discrepancia respecto a la línea base de conteos o stock.';
    END IF;

    SELECT COUNT(*)
      INTO v_tablas_totales
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_TYPE = 'BASE TABLE';

    SELECT COUNT(*)
      INTO v_triggers_totales
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual;

    IF v_tablas_totales <> 43 OR v_triggers_totales <> 34 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO06_INCOMPLETA: El esquema final debe contener exactamente 43 tablas y 34 triggers antes de marcar COMPLETADA.';
    END IF;

    -- =========================================================================
    -- K.4. CIERRE DEL CONTROL DE MIGRACIÓN SPRINT 3
    -- =========================================================================
    UPDATE `sprint3_migracion_control`
       SET `estado` = 'COMPLETADA',
           `paso_actual` = '06_COMPLETADA',
           `conteos_finales_json` = JSON_OBJECT(
               'usuario', (SELECT COUNT(*) FROM `usuario`),
               'rol', (SELECT COUNT(*) FROM `rol`),
               'permiso', (SELECT COUNT(*) FROM `permiso`),
               'rol_permiso', (SELECT COUNT(*) FROM `rol_permiso`),
               'producto', (SELECT COUNT(*) FROM `producto`),
               'stock_total', (SELECT COALESCE(SUM(`stock_actual`), 0) FROM `producto`),
               'venta', (SELECT COUNT(*) FROM `venta`),
               'ventas_con_sesion_nula', (SELECT COUNT(*) FROM `venta` WHERE `id_sesion_caja` IS NULL),
               'detalle_venta', (SELECT COUNT(*) FROM `detalle_venta`),
               'compra', (SELECT COUNT(*) FROM `compra`),
               'detalle_compra', (SELECT COUNT(*) FROM `detalle_compra`),
               'compra_historial', (SELECT COUNT(*) FROM `compra_historial`),
               'kardex', (SELECT COUNT(*) FROM `kardex`),
               'operacion_inventario', (SELECT COUNT(*) FROM `operacion_inventario`),
               'serie_comprobante', (SELECT COUNT(*) FROM `serie_comprobante`),
               'control_solicitud_idempotencia', (SELECT COUNT(*) FROM `control_solicitud_idempotencia`),
               'reporte_consulta_snapshot', (SELECT COUNT(*) FROM `reporte_consulta_snapshot`),
               'reporte_consulta_snapshot_item', (SELECT COUNT(*) FROM `reporte_consulta_snapshot_item`),
               'sprint2_archivo_venta', (SELECT COUNT(*) FROM `sprint2_archivo_venta`),
               'sprint2_archivo_detalle_venta', (SELECT COUNT(*) FROM `sprint2_archivo_detalle_venta`),
               'sprint2_archivo_compra', (SELECT COUNT(*) FROM `sprint2_archivo_compra`),
               'sprint2_archivo_detalle_compra', (SELECT COUNT(*) FROM `sprint2_archivo_detalle_compra`),
               'sprint2_archivo_compra_historial', (SELECT COUNT(*) FROM `sprint2_archivo_compra_historial`),
               'sprint2_archivo_kardex', (SELECT COUNT(*) FROM `sprint2_archivo_kardex`)
           ),
           `completada_en` = NOW()
     WHERE `id_migracion` = 3;

    COMMIT;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));
    DO RELEASE_LOCK(v_lock_name);
END$$

DELIMITER ;
