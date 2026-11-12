-- =============================================================================
-- ARCHIVO: 04_estructura_movimientos_correcciones_y_ventas_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 04 de 06 (Requiere 03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Implementar los dominios H (Vinculación de ventas con sesión de caja),
--   D (Movimientos de caja inmutables) y E (Cadena de correcciones sin mutar el original):
--     1. Ampliar `venta` con `id_sesion_caja INT(11) DEFAULT NULL` e índice/FK hacia
--        `sesion_caja(id_sesion_caja)`, preservando intactas las 250 ventas
--        históricas con `id_sesion_caja = NULL` sin crear sesiones ficticias.
--     2. Crear `movimiento_caja` con los 7 tipos exactos del contrato:
--        `VENTA_EFECTIVO`, `INGRESO_MANUAL`, `INYECCION_ADMIN`, `SALIDA_CAJA`,
--        `RETIRO_ADMIN`, `RETIRO_OMITIDO` y `CORRECCION`.
--     3. Crear `correccion_movimiento_caja` vinculada inequívocamente tanto al
--        movimiento original (`id_movimiento_original`) como al movimiento
--        compensatorio (`id_movimiento_correccion` UNIQUE) y al eslabón previo
--        (`id_correccion_previa` UNIQUE), sin dependencias circulares de FK ni
--        necesidad de desactivar `FOREIGN_KEY_CHECKS`.
-- =============================================================================

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR v_db_actual = 'amazon_market'
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 04.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 a 03.';
    END IF;

    SELECT estado, paso_actual
      INTO v_estado_migracion, v_paso_migracion
      FROM sprint3_migracion_control
     WHERE id_migracion = 3
     LIMIT 1;

    IF v_estado_migracion = 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SPRINT3_YA_APLICADA: La migración del Sprint 3 ya está COMPLETADA en esta base.';
    END IF;

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '03_SESIONES_ARQUEOS_AUTORIZACIONES' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 04 exige estado EN_PROGRESO y paso_actual = 03_SESIONES_ARQUEOS_AUTORIZACIONES.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'venta'
           AND COLUMN_NAME = 'id_sesion_caja'
    ) OR EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME IN ('movimiento_caja', 'correccion_movimiento_caja')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'OBJETOS_EXISTENTES_PASO04: La columna venta.id_sesion_caja o las tablas de movimientos ya existen.';
    END IF;

    -- =========================================================================
    -- H. AMPLIACIÓN DE `venta` CON `id_sesion_caja` NULLABLE (HU 12 / Sección 7.7)
    -- =========================================================================
    ALTER TABLE `venta`
      ADD COLUMN `id_sesion_caja` int(11) DEFAULT NULL AFTER `id_usuario`,
      ADD KEY `idx_venta_sesion_caja` (`id_sesion_caja`),
      ADD CONSTRAINT `fk_venta_sesion_caja` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`);

    -- =========================================================================
    -- D. TABLA `movimiento_caja` (Movimientos inmutables — HU 12, HU 13 y HU 14)
    -- =========================================================================
    CREATE TABLE `movimiento_caja` (
      `id_movimiento_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_sesion_caja` int(11) NOT NULL,
      `tipo_movimiento` enum(
        'VENTA_EFECTIVO',
        'INGRESO_MANUAL',
        'INYECCION_ADMIN',
        'SALIDA_CAJA',
        'RETIRO_ADMIN',
        'RETIRO_OMITIDO',
        'CORRECCION'
      ) NOT NULL,
      `monto` decimal(10,2) NOT NULL,
      `efecto_saldo` decimal(10,2) NOT NULL,
      `motivo` varchar(250) NOT NULL,
      `referencia_visible` varchar(300) DEFAULT NULL,
      `id_usuario_registro` int(11) NOT NULL,
      `id_usuario_admin` int(11) DEFAULT NULL,
      `id_venta` int(11) DEFAULT NULL,
      `id_sesion_origen_omitido` int(11) DEFAULT NULL,
      `declarado_ocurrio_en_sesion_origen_abierta` tinyint(1) DEFAULT NULL,
      `confirmado_reflejado_en_apertura` tinyint(1) DEFAULT NULL,
      `id_movimiento_original` int(11) DEFAULT NULL,
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      `fecha_hora_registro` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id_movimiento_caja`),
      UNIQUE KEY `uq_movimiento_caja_venta` (`id_venta`),
      UNIQUE KEY `uq_movimiento_caja_solicitud` (`id_solicitud_idempotencia`),
      KEY `idx_mov_sesion_fecha_id` (`id_sesion_caja`, `fecha_hora_registro`, `id_movimiento_caja`),
      KEY `idx_mov_sesion_tipo` (`id_sesion_caja`, `tipo_movimiento`),
      KEY `idx_mov_original` (`id_movimiento_original`),
      KEY `idx_mov_sesion_origen_omitido` (`id_sesion_origen_omitido`),
      KEY `fk_mov_usuario_registro` (`id_usuario_registro`),
      KEY `fk_mov_usuario_admin` (`id_usuario_admin`),
      CONSTRAINT `fk_mov_sesion_caja` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_mov_usuario_registro` FOREIGN KEY (`id_usuario_registro`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_mov_usuario_admin` FOREIGN KEY (`id_usuario_admin`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_mov_venta` FOREIGN KEY (`id_venta`) REFERENCES `venta` (`id_venta`),
      CONSTRAINT `fk_mov_sesion_origen_omitido` FOREIGN KEY (`id_sesion_origen_omitido`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_mov_movimiento_original` FOREIGN KEY (`id_movimiento_original`) REFERENCES `movimiento_caja` (`id_movimiento_caja`),
      CONSTRAINT `fk_mov_solicitud_idemp` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_mov_monto_y_motivo` CHECK (
        `monto` > 0.00
        and char_length(trim(`motivo`)) between 1 and 250
      ),
      CONSTRAINT `chk_mov_efecto_y_declaraciones` CHECK (
        (
          `tipo_movimiento` in ('VENTA_EFECTIVO', 'INGRESO_MANUAL', 'INYECCION_ADMIN')
          and `efecto_saldo` = `monto`
          and `declarado_ocurrio_en_sesion_origen_abierta` is null
          and `confirmado_reflejado_en_apertura` is null
        )
        or (
          `tipo_movimiento` in ('SALIDA_CAJA', 'RETIRO_ADMIN')
          and `efecto_saldo` = -`monto`
          and `declarado_ocurrio_en_sesion_origen_abierta` is null
          and `confirmado_reflejado_en_apertura` is null
        )
        or (
          `tipo_movimiento` = 'RETIRO_OMITIDO'
          and `efecto_saldo` = 0.00
          and `declarado_ocurrio_en_sesion_origen_abierta` = 1
          and `confirmado_reflejado_en_apertura` = 1
        )
        or (
          `tipo_movimiento` = 'CORRECCION'
          and `efecto_saldo` <> 0.00
          and abs(`efecto_saldo`) = `monto`
          and `declarado_ocurrio_en_sesion_origen_abierta` is null
          and `confirmado_reflejado_en_apertura` is null
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- E. TABLA `correccion_movimiento_caja` (Historial encadenado — HU 13 y HU 14)
    -- =========================================================================
    CREATE TABLE `correccion_movimiento_caja` (
      `id_correccion_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_sesion_caja` int(11) NOT NULL,
      `id_movimiento_original` int(11) NOT NULL,
      `id_movimiento_correccion` int(11) NOT NULL,
      `id_correccion_previa` int(11) DEFAULT NULL,
      `numero_eslabon` int(11) NOT NULL DEFAULT 1,
      `monto_original` decimal(10,2) NOT NULL,
      `monto_vigente_previo` decimal(10,2) NOT NULL,
      `monto_corregido` decimal(10,2) NOT NULL,
      `efecto_saldo` decimal(10,2) NOT NULL,
      `motivo` varchar(250) NOT NULL,
      `id_usuario_registro` int(11) NOT NULL,
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      `fecha_hora_registro` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id_correccion_caja`),
      UNIQUE KEY `uq_correccion_movimiento_ajuste` (`id_movimiento_correccion`),
      UNIQUE KEY `uq_correccion_previa_unica` (`id_correccion_previa`),
      UNIQUE KEY `uq_correccion_original_eslabon` (`id_movimiento_original`, `numero_eslabon`),
      UNIQUE KEY `uq_correccion_solicitud` (`id_solicitud_idempotencia`),
      KEY `idx_correccion_sesion_original` (`id_sesion_caja`, `id_movimiento_original`, `numero_eslabon`),
      KEY `fk_correccion_usuario_registro` (`id_usuario_registro`),
      CONSTRAINT `fk_correccion_sesion_caja` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_correccion_movimiento_original` FOREIGN KEY (`id_movimiento_original`) REFERENCES `movimiento_caja` (`id_movimiento_caja`),
      CONSTRAINT `fk_correccion_movimiento_ajuste` FOREIGN KEY (`id_movimiento_correccion`) REFERENCES `movimiento_caja` (`id_movimiento_caja`),
      CONSTRAINT `fk_correccion_eslabon_previo` FOREIGN KEY (`id_correccion_previa`) REFERENCES `correccion_movimiento_caja` (`id_correccion_caja`),
      CONSTRAINT `fk_correccion_usuario_registro` FOREIGN KEY (`id_usuario_registro`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_correccion_solicitud_idemp` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_correccion_importes_y_motivo` CHECK (
        `numero_eslabon` >= 1
        and `monto_original` > 0.00
        and `monto_vigente_previo` > 0.00
        and `monto_corregido` > 0.00
        and `monto_corregido` <> `monto_vigente_previo`
        and `efecto_saldo` <> 0.00
        and abs(`efecto_saldo`) = abs(round(`monto_corregido` - `monto_vigente_previo`, 2))
        and char_length(trim(`motivo`)) between 1 and 250
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    UPDATE `sprint3_migracion_control`
       SET `paso_actual` = '04_MOVIMIENTOS_CORRECCIONES_VENTAS'
     WHERE `id_migracion` = 3;
END$$

-- =============================================================================
-- TRIGGERS DE INTEGRIDAD PARA `movimiento_caja` Y `correccion_movimiento_caja`
-- =============================================================================

CREATE TRIGGER `trg_movimiento_caja_before_insert_integridad`
BEFORE INSERT ON `movimiento_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_rol_admin INT DEFAULT NULL;
    DECLARE v_estado_admin INT DEFAULT NULL;
    DECLARE v_venta_sesion INT DEFAULT NULL;
    DECLARE v_venta_metodo INT DEFAULT NULL;
    DECLARE v_venta_estado VARCHAR(20) DEFAULT NULL;
    DECLARE v_venta_total DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_origen_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_orig_sesion INT DEFAULT NULL;
    DECLARE v_orig_tipo VARCHAR(32) DEFAULT NULL;

    -- 1. La sesión destino debe existir, estar ABIERTA y pertenecer al vendedor que registra
    SELECT estado, id_usuario_vendedor
      INTO v_sesion_estado, v_sesion_vendedor
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Solo se pueden registrar movimientos o correcciones sobre una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_registro <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: Solo el vendedor titular de la sesión abierta puede registrar movimientos en ella.';
    END IF;

    -- 2. Reglas específicas según tipo_movimiento
    IF NEW.tipo_movimiento = 'VENTA_EFECTIVO' THEN
        IF NEW.id_venta IS NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_INVALIDO: VENTA_EFECTIVO exige id_venta no nulo y prohíbe campos de admin, sesión origen o corrección.';
        END IF;

        SELECT id_sesion_caja, id_metodo_pago, estado, total
          INTO v_venta_sesion, v_venta_metodo, v_venta_estado, v_venta_total
          FROM venta
         WHERE id_venta = NEW.id_venta
         LIMIT 1;

        IF v_venta_sesion IS NULL OR v_venta_sesion <> NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_SESION_INCONSISTENTE: La venta vinculada debe pertenecer a la misma sesión de caja.';
        END IF;

        IF v_venta_metodo <> 1 OR v_venta_estado <> 'COMPLETADA' OR v_venta_total <> NEW.monto THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_VENTA_METODO_O_MONTO_INVALIDO: VENTA_EFECTIVO requiere una venta COMPLETADA con método Efectivo (1) y monto idéntico al total.';
        END IF;

    ELSEIF NEW.tipo_movimiento IN ('INGRESO_MANUAL', 'SALIDA_CAJA') THEN
        IF NEW.id_venta IS NOT NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVIMIENTO_OPERATIVO_INVALIDO: INGRESO_MANUAL y SALIDA_CAJA no admiten id_venta, id_usuario_admin, sesión origen ni movimiento original.';
        END IF;

    ELSEIF NEW.tipo_movimiento IN ('INYECCION_ADMIN', 'RETIRO_ADMIN') THEN
        IF NEW.id_usuario_admin IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'ADMIN_AUTHORIZATION_REQUIRED: INYECCION_ADMIN y RETIRO_ADMIN exigen id_usuario_admin y no admiten venta, sesión origen ni corrección.';
        END IF;

        SELECT id_rol, estado
          INTO v_rol_admin, v_estado_admin
          FROM usuario
         WHERE id_usuario = NEW.id_usuario_admin
         LIMIT 1;

        IF v_rol_admin IS NULL OR v_rol_admin <> 1 OR v_estado_admin <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_ADMIN_USER: id_usuario_admin debe corresponder a un Administrador activo (id_rol = 1).';
        END IF;

    ELSEIF NEW.tipo_movimiento = 'RETIRO_OMITIDO' THEN
        IF NEW.id_sesion_origen_omitido IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_movimiento_original IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: RETIRO_OMITIDO exige id_sesion_origen_omitido y las dos declaraciones obligatorias.';
        END IF;

        IF NEW.id_sesion_origen_omitido = NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: La sesión de origen del retiro omitido debe ser distinta de la sesión actual.';
        END IF;

        SELECT estado
          INTO v_origen_estado
          FROM sesion_caja
         WHERE id_sesion_caja = NEW.id_sesion_origen_omitido
         LIMIT 1;

        IF v_origen_estado IS NULL OR v_origen_estado <> 'CERRADA' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'INVALID_OMITTED_WITHDRAWAL_ORIGIN: La sesión de origen de un retiro omitido debe existir y estar CERRADA.';
        END IF;

        IF NEW.id_usuario_admin IS NOT NULL THEN
            SELECT id_rol, estado
              INTO v_rol_admin, v_estado_admin
              FROM usuario
             WHERE id_usuario = NEW.id_usuario_admin
             LIMIT 1;

            IF v_rol_admin IS NULL OR v_rol_admin <> 1 OR v_estado_admin <> 1 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'INVALID_ADMIN_USER: Cuando se informa id_usuario_admin en RETIRO_OMITIDO, debe ser un Administrador activo (id_rol = 1).';
            END IF;
        END IF;

    ELSEIF NEW.tipo_movimiento = 'CORRECCION' THEN
        IF NEW.id_movimiento_original IS NULL
           OR NEW.id_venta IS NOT NULL
           OR NEW.id_usuario_admin IS NOT NULL
           OR NEW.id_sesion_origen_omitido IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: Un movimiento de tipo CORRECCION exige id_movimiento_original.';
        END IF;

        SELECT id_sesion_caja, tipo_movimiento
          INTO v_orig_sesion, v_orig_tipo
          FROM movimiento_caja
         WHERE id_movimiento_caja = NEW.id_movimiento_original
         LIMIT 1;

        IF v_orig_sesion IS NULL OR v_orig_sesion <> NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: El movimiento original a corregir debe pertenecer a la misma sesión de caja abierta.';
        END IF;

        IF v_orig_tipo NOT IN ('INGRESO_MANUAL', 'INYECCION_ADMIN', 'SALIDA_CAJA', 'RETIRO_ADMIN') THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: Solo se admiten correcciones sobre INGRESO_MANUAL, INYECCION_ADMIN, SALIDA_CAJA o RETIRO_ADMIN.';
        END IF;
    END IF;
END$$

CREATE TRIGGER `trg_movimiento_caja_before_update_inmutable`
BEFORE UPDATE ON `movimiento_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'MOVIMIENTO_CAJA_INMUTABLE: Los movimientos de caja no pueden editarse; registre una corrección encadenada.';
END$$

CREATE TRIGGER `trg_movimiento_caja_before_delete_prohibido`
BEFORE DELETE ON `movimiento_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'MOVIMIENTO_CAJA_INMUTABLE: Los movimientos de caja no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_correccion_movimiento_caja_before_insert_integridad`
BEFORE INSERT ON `correccion_movimiento_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_orig_sesion INT DEFAULT NULL;
    DECLARE v_orig_tipo VARCHAR(32) DEFAULT NULL;
    DECLARE v_orig_monto DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_corr_sesion INT DEFAULT NULL;
    DECLARE v_corr_tipo VARCHAR(32) DEFAULT NULL;
    DECLARE v_corr_orig_ref INT DEFAULT NULL;
    DECLARE v_corr_monto DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_corr_efecto DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_ult_id_correccion INT DEFAULT NULL;
    DECLARE v_ult_eslabon INT DEFAULT 0;
    DECLARE v_ult_monto_corregido DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_efecto_esperado DECIMAL(10,2) DEFAULT NULL;

    IF NEW.id_movimiento_original = NEW.id_movimiento_correccion THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CORRECCION_REFERENCIA_CIRCULAR: id_movimiento_original e id_movimiento_correccion deben ser distintos.';
    END IF;

    SELECT estado, id_usuario_vendedor
      INTO v_sesion_estado, v_sesion_vendedor
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Solo se pueden registrar correcciones sobre una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_registro <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: Solo el vendedor titular de la sesión abierta puede registrar correcciones.';
    END IF;

    -- Validar el movimiento original
    SELECT id_sesion_caja, tipo_movimiento, monto
      INTO v_orig_sesion, v_orig_tipo, v_orig_monto
      FROM movimiento_caja
     WHERE id_movimiento_caja = NEW.id_movimiento_original
     LIMIT 1;

    IF v_orig_sesion IS NULL OR v_orig_sesion <> NEW.id_sesion_caja THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: El movimiento original no pertenece a esta sesión de caja.';
    END IF;

    IF v_orig_tipo NOT IN ('INGRESO_MANUAL', 'INYECCION_ADMIN', 'SALIDA_CAJA', 'RETIRO_ADMIN') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MOVEMENT_NOT_EDITABLE: El tipo del movimiento original no admite corrección.';
    END IF;

    IF NEW.monto_original <> v_orig_monto THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CORRECCION_MONTO_ORIGINAL_INCONSISTENTE: monto_original debe coincidir con el importe inmutable del movimiento original.';
    END IF;

    -- Validar el movimiento compensatorio (tipo CORRECCION)
    SELECT id_sesion_caja, tipo_movimiento, id_movimiento_original, monto, efecto_saldo
      INTO v_corr_sesion, v_corr_tipo, v_corr_orig_ref, v_corr_monto, v_corr_efecto
      FROM movimiento_caja
     WHERE id_movimiento_caja = NEW.id_movimiento_correccion
     LIMIT 1;

    IF v_corr_sesion IS NULL OR v_corr_sesion <> NEW.id_sesion_caja
       OR v_corr_tipo <> 'CORRECCION'
       OR COALESCE(v_corr_orig_ref, 0) <> NEW.id_movimiento_original THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CORRECCION_MOVIMIENTO_AJUSTE_INVALIDO: id_movimiento_correccion debe apuntar al movimiento tipo CORRECCION vinculado al mismo original.';
    END IF;

    -- Validar cadena de eslabones previos sobre el mismo movimiento original
    SELECT id_correccion_caja, numero_eslabon, monto_corregido
      INTO v_ult_id_correccion, v_ult_eslabon, v_ult_monto_corregido
      FROM correccion_movimiento_caja
     WHERE id_movimiento_original = NEW.id_movimiento_original
     ORDER BY numero_eslabon DESC
     LIMIT 1;

    IF v_ult_id_correccion IS NULL THEN
        IF NEW.id_correccion_previa IS NOT NULL OR NEW.numero_eslabon <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CORRECCION_CADENA_INVALIDA: La primera corrección debe tener id_correccion_previa = NULL y numero_eslabon = 1.';
        END IF;

        IF NEW.monto_vigente_previo <> v_orig_monto THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CORRECCION_MONTO_PREVIO_INCONSISTENTE: En el primer eslabón, monto_vigente_previo debe igualar a monto_original.';
        END IF;
    ELSE
        IF COALESCE(NEW.id_correccion_previa, 0) <> v_ult_id_correccion
           OR NEW.numero_eslabon <> (v_ult_eslabon + 1) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CORRECCION_CADENA_INVALIDA: Toda corrección posterior debe enlazar al último eslabón previo e incrementar numero_eslabon.';
        END IF;

        IF NEW.monto_vigente_previo <> v_ult_monto_corregido THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CORRECCION_MONTO_PREVIO_INCONSISTENTE: monto_vigente_previo debe coincidir con monto_corregido del eslabón inmediato anterior.';
        END IF;
    END IF;

    -- Calcular y verificar el signo exacto de efecto_saldo según si el original fue ingreso o egreso
    IF v_orig_tipo IN ('INGRESO_MANUAL', 'INYECCION_ADMIN') THEN
        SET v_efecto_esperado = ROUND(NEW.monto_corregido - NEW.monto_vigente_previo, 2);
    ELSE
        SET v_efecto_esperado = ROUND(NEW.monto_vigente_previo - NEW.monto_corregido, 2);
    END IF;

    IF NEW.efecto_saldo <> v_efecto_esperado
       OR v_corr_efecto <> v_efecto_esperado
       OR v_corr_monto <> ABS(v_efecto_esperado) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CORRECCION_EFECTO_SALDO_INCONSISTENTE: efecto_saldo no coincide con la diferencia firmada respecto al último importe vigente.';
    END IF;
END$$

CREATE TRIGGER `trg_correccion_movimiento_caja_before_update_inmutable`
BEFORE UPDATE ON `correccion_movimiento_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_CORRECCION_INMUTABLE: Los eslabones de correccion_movimiento_caja no pueden modificarse.';
END$$

CREATE TRIGGER `trg_correccion_movimiento_caja_before_delete_prohibido`
BEFORE DELETE ON `correccion_movimiento_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_CORRECCION_INMUTABLE: Los eslabones de correccion_movimiento_caja no pueden eliminarse.';
END$$

DELIMITER ;
