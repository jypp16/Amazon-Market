-- =============================================================================
-- ARCHIVO: 03_estructura_sesiones_arqueos_y_autorizaciones_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 03 de 06 (Requiere 02_estructura_turnos_y_programacion_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Implementar los dominios C (Sesiones de caja y guardia única), F (Arqueos)
--   y G (Autorizaciones administrativas):
--     1. Tabla `caja_fisica_guardia` con fila única (`id_caja_fisica = 1`) para
--        serializar aperturas concurrentes mediante `SELECT ... FOR UPDATE` sobre
--        una fila existente incluso cuando `sesion_caja` aún tiene cero filas.
--     2. Tabla `sesion_caja` con dueño real (`id_usuario_vendedor`), snapshot del
--        turno y horario, `fecha_hora_salida_referencia`, referencia nullable al
--        cierre anterior (`id_sesion_cierre_anterior`), conteo inicial y diferencia
--        de apertura nullable, acumulados por medio de pago, versión y cierre
--        congelado. Garantiza una sola sesión ABIERTA mediante columna generada
--        persistida `guardia_abierta_unica` e índice `UNIQUE`.
--     3. Tabla `arqueo_caja` con historial conservado, versión de caja evaluada,
--        observación obligatoria cuando `diferencia <> 0.00` y a lo sumo un
--        arqueo `VIGENTE` por sesión.
--     4. Tabla `autorizacion_caja` para cierres anticipados (> 15 min antes de
--        la hora de salida de referencia), sin almacenar contraseñas ni secretos.
-- =============================================================================

SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_lock_name VARCHAR(64);
    DECLARE v_estado_migracion VARCHAR(32) DEFAULT NULL;
    DECLARE v_paso_migracion VARCHAR(64) DEFAULT NULL;

    SET v_db_actual = DATABASE();

    IF v_db_actual IS NULL OR v_db_actual = 'amazon_market'
       OR BINARY COALESCE(@SPRINT3_BASE_CONFIRMADA, '') <> BINARY v_db_actual THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 03.';
    END IF;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero los pasos 01 y 02.';
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

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '02_TURNOS_Y_PROGRAMACION' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 03 exige estado EN_PROGRESO y paso_actual = 02_TURNOS_Y_PROGRAMACION.';
    END IF;

    IF COALESCE(IS_USED_LOCK(v_lock_name), -1) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LOCK_MIGRACION_NO_VIGENTE: El paso 03 debe ejecutarse en la misma conexión que adquirió GET_LOCK en el paso 01.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME IN ('caja_fisica_guardia', 'sesion_caja', 'arqueo_caja', 'autorizacion_caja')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'OBJETOS_EXISTENTES_PASO03: Alguna tabla de sesiones, arqueos o autorizaciones ya existe en esta base.';
    END IF;

    -- =========================================================================
    -- C.1. TABLA `caja_fisica_guardia` (Fila ancla para serialización — Sección 11.2)
    -- =========================================================================
    CREATE TABLE `caja_fisica_guardia` (
      `id_caja_fisica` tinyint(4) NOT NULL DEFAULT 1,
      `codigo_caja` varchar(40) NOT NULL DEFAULT 'CAJA_UNICA_PRINCIPAL',
      `id_sesion_abierta_actual` int(11) DEFAULT NULL,
      `id_ultima_sesion_cerrada` int(11) DEFAULT NULL,
      `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id_caja_fisica`),
      UNIQUE KEY `uq_caja_fisica_codigo` (`codigo_caja`),
      CONSTRAINT `chk_caja_fisica_unica` CHECK (`id_caja_fisica` = 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    INSERT INTO `caja_fisica_guardia` (
        `id_caja_fisica`,
        `codigo_caja`,
        `id_sesion_abierta_actual`,
        `id_ultima_sesion_cerrada`
    ) VALUES (
        1,
        'CAJA_UNICA_PRINCIPAL',
        NULL,
        NULL
    );

    -- =========================================================================
    -- C.2. TABLA `sesion_caja` (Sesión operativa de caja — HU 11..HU 17)
    -- =========================================================================
    CREATE TABLE `sesion_caja` (
      `id_sesion_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_caja_fisica` tinyint(4) NOT NULL DEFAULT 1,
      `id_usuario_vendedor` int(11) NOT NULL,
      `id_turno` int(11) NOT NULL,
      `nombre_turno_referencia` varchar(60) NOT NULL,
      `hora_inicio_referencia` time NOT NULL,
      `hora_fin_referencia` time NOT NULL,
      `fecha_hora_salida_referencia` datetime NOT NULL,
      `es_suplencia` tinyint(1) NOT NULL DEFAULT 0,
      `abierta_fuera_horario` tinyint(1) NOT NULL DEFAULT 0,
      `fecha_hora_apertura` datetime NOT NULL DEFAULT current_timestamp(),
      `tiene_cierre_anterior` tinyint(1) NOT NULL DEFAULT 0,
      `id_sesion_cierre_anterior` int(11) DEFAULT NULL,
      `monto_referencia_cierre_anterior` decimal(10,2) DEFAULT NULL,
      `fecha_hora_cierre_anterior_ref` datetime DEFAULT NULL,
      `nombre_turno_cierre_anterior_ref` varchar(60) DEFAULT NULL,
      `saldo_inicial` decimal(10,2) NOT NULL,
      `diferencia_apertura` decimal(10,2) DEFAULT NULL,
      `total_ventas_efectivo` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_ventas_tarjeta` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_ventas_yape_plin` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_ingresos` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_egresos` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_retiros_omitidos_declarados` decimal(10,2) NOT NULL DEFAULT 0.00,
      `efectivo_esperado_actual` decimal(10,2) NOT NULL DEFAULT 0.00,
      `version_caja` int(11) NOT NULL DEFAULT 1,
      `estado` enum('ABIERTA','CERRADA') NOT NULL DEFAULT 'ABIERTA',
      `id_ultimo_arqueo` int(11) DEFAULT NULL,
      `id_arqueo_cierre` int(11) DEFAULT NULL,
      `fecha_hora_cierre` datetime DEFAULT NULL,
      `efectivo_esperado_cierre` decimal(10,2) DEFAULT NULL,
      `efectivo_contado_cierre` decimal(10,2) DEFAULT NULL,
      `diferencia_cierre` decimal(10,2) DEFAULT NULL,
      `observacion_cierre` varchar(500) DEFAULT NULL,
      `id_solicitud_apertura` int(11) DEFAULT NULL,
      `id_solicitud_cierre` int(11) DEFAULT NULL,
      `guardia_abierta_unica` tinyint(4) AS (if(`estado` = 'ABIERTA', 1, NULL)) PERSISTENT,
      PRIMARY KEY (`id_sesion_caja`),
      UNIQUE KEY `uq_sesion_caja_unica_abierta` (`guardia_abierta_unica`),
      UNIQUE KEY `uq_sesion_caja_arqueo_cierre` (`id_arqueo_cierre`),
      UNIQUE KEY `uq_sesion_caja_solicitud_apertura` (`id_solicitud_apertura`),
      UNIQUE KEY `uq_sesion_caja_solicitud_cierre` (`id_solicitud_cierre`),
      KEY `idx_sesion_estado_cierre_id` (`estado`, `fecha_hora_cierre`, `id_sesion_caja`),
      KEY `idx_sesion_vendedor_apertura` (`id_usuario_vendedor`, `fecha_hora_apertura`, `id_sesion_caja`),
      KEY `idx_sesion_turno_cierre` (`id_turno`, `fecha_hora_cierre`, `id_sesion_caja`),
      KEY `fk_sesion_caja_fisica` (`id_caja_fisica`),
      KEY `fk_sesion_caja_cierre_anterior` (`id_sesion_cierre_anterior`),
      KEY `fk_sesion_caja_ultimo_arqueo` (`id_ultimo_arqueo`),
      CONSTRAINT `fk_sesion_caja_fisica` FOREIGN KEY (`id_caja_fisica`) REFERENCES `caja_fisica_guardia` (`id_caja_fisica`),
      CONSTRAINT `fk_sesion_caja_vendedor` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_sesion_caja_turno` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`),
      CONSTRAINT `fk_sesion_caja_cierre_anterior` FOREIGN KEY (`id_sesion_cierre_anterior`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_sesion_caja_solicitud_apertura` FOREIGN KEY (`id_solicitud_apertura`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `fk_sesion_caja_solicitud_cierre` FOREIGN KEY (`id_solicitud_cierre`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_sesion_importes_no_negativos` CHECK (
        `saldo_inicial` >= 0.00
        and `total_ventas_efectivo` >= 0.00
        and `total_ventas_tarjeta` >= 0.00
        and `total_ventas_yape_plin` >= 0.00
        and `total_ingresos` >= 0.00
        and `total_egresos` >= 0.00
        and `total_retiros_omitidos_declarados` >= 0.00
        and `version_caja` >= 1
      ),
      CONSTRAINT `chk_sesion_formula_esperado_actual` CHECK (
        `efectivo_esperado_actual` = round(`saldo_inicial` + `total_ventas_efectivo` + `total_ingresos` - `total_egresos`, 2)
      ),
      CONSTRAINT `chk_sesion_cierre_anterior_montos` CHECK (
        (
          `tiene_cierre_anterior` = 0
          and `monto_referencia_cierre_anterior` is null
          and `fecha_hora_cierre_anterior_ref` is null
          and `nombre_turno_cierre_anterior_ref` is null
          and `diferencia_apertura` is null
        )
        or (
          `tiene_cierre_anterior` = 1
          and `monto_referencia_cierre_anterior` is not null
          and `monto_referencia_cierre_anterior` >= 0.00
          and `fecha_hora_cierre_anterior_ref` is not null
          and `nombre_turno_cierre_anterior_ref` is not null
          and `diferencia_apertura` = round(`saldo_inicial` - `monto_referencia_cierre_anterior`, 2)
        )
      ),
      CONSTRAINT `chk_sesion_estado_cierre_montos` CHECK (
        (
          `estado` = 'ABIERTA'
          and `fecha_hora_cierre` is null
          and `efectivo_esperado_cierre` is null
          and `efectivo_contado_cierre` is null
          and `diferencia_cierre` is null
          and `observacion_cierre` is null
        )
        or (
          `estado` = 'CERRADA'
          and `fecha_hora_cierre` is not null
          and `efectivo_esperado_cierre` is not null
          and `efectivo_contado_cierre` is not null
          and `efectivo_contado_cierre` >= 0.00
          and `diferencia_cierre` = round(`efectivo_contado_cierre` - `efectivo_esperado_cierre`, 2)
          and (`observacion_cierre` is null or char_length(trim(`observacion_cierre`)) <= 500)
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- F. TABLA `arqueo_caja` (Conteos físicos y versión evaluada — HU 15 y HU 16)
    -- =========================================================================
    CREATE TABLE `arqueo_caja` (
      `id_arqueo_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_sesion_caja` int(11) NOT NULL,
      `id_usuario_vendedor` int(11) NOT NULL,
      `version_caja_evaluada` int(11) NOT NULL,
      `monto_esperado` decimal(10,2) NOT NULL,
      `monto_contado` decimal(10,2) NOT NULL,
      `diferencia` decimal(10,2) NOT NULL,
      `observacion` varchar(500) DEFAULT NULL,
      `estado` enum('VIGENTE','REEMPLAZADO','DESACTUALIZADO') NOT NULL DEFAULT 'VIGENTE',
      `vigencia_activa` tinyint(1) DEFAULT 1,
      `causa_cambio_estado` enum('NUEVO_ARQUEO','OPERACION_CAJA_POSTERIOR') DEFAULT NULL,
      `fecha_hora_arqueo` datetime NOT NULL DEFAULT current_timestamp(),
      `fecha_hora_cambio_estado` datetime DEFAULT NULL,
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      PRIMARY KEY (`id_arqueo_caja`),
      UNIQUE KEY `uq_arqueo_caja_unico_vigente_sesion` (`id_sesion_caja`, `vigencia_activa`),
      UNIQUE KEY `uq_arqueo_caja_solicitud` (`id_solicitud_idempotencia`),
      KEY `idx_arqueo_sesion_estado_fecha` (`id_sesion_caja`, `estado`, `fecha_hora_arqueo`, `id_arqueo_caja`),
      KEY `fk_arqueo_caja_vendedor` (`id_usuario_vendedor`),
      CONSTRAINT `fk_arqueo_caja_sesion` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_arqueo_caja_vendedor` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_arqueo_caja_solicitud` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_arqueo_montos_y_diferencia` CHECK (
        `version_caja_evaluada` >= 1
        and `monto_contado` >= 0.00
        and `diferencia` = round(`monto_contado` - `monto_esperado`, 2)
      ),
      CONSTRAINT `chk_arqueo_observacion_condicional` CHECK (
        (
          `diferencia` = 0.00
          and (`observacion` is null or char_length(trim(`observacion`)) <= 500)
        )
        or (
          `diferencia` <> 0.00
          and `observacion` is not null
          and char_length(trim(`observacion`)) between 1 and 500
        )
      ),
      CONSTRAINT `chk_arqueo_estado_causa` CHECK (
        (
          `estado` = 'VIGENTE'
          and `vigencia_activa` = 1
          and `causa_cambio_estado` is null
          and `fecha_hora_cambio_estado` is null
        )
        or (
          `estado` = 'REEMPLAZADO'
          and `vigencia_activa` is null
          and `causa_cambio_estado` = 'NUEVO_ARQUEO'
          and `fecha_hora_cambio_estado` is not null
        )
        or (
          `estado` = 'DESACTUALIZADO'
          and `vigencia_activa` is null
          and `causa_cambio_estado` = 'OPERACION_CAJA_POSTERIOR'
          and `fecha_hora_cambio_estado` is not null
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Enlazar claves foráneas cruzadas entre `sesion_caja`, `arqueo_caja` y `caja_fisica_guardia`
    ALTER TABLE `sesion_caja`
      ADD CONSTRAINT `fk_sesion_caja_ultimo_arqueo` FOREIGN KEY (`id_ultimo_arqueo`) REFERENCES `arqueo_caja` (`id_arqueo_caja`),
      ADD CONSTRAINT `fk_sesion_caja_arqueo_cierre` FOREIGN KEY (`id_arqueo_cierre`) REFERENCES `arqueo_caja` (`id_arqueo_caja`);

    ALTER TABLE `caja_fisica_guardia`
      ADD CONSTRAINT `fk_guardia_sesion_abierta` FOREIGN KEY (`id_sesion_abierta_actual`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      ADD CONSTRAINT `fk_guardia_ultima_cerrada` FOREIGN KEY (`id_ultima_sesion_cerrada`) REFERENCES `sesion_caja` (`id_sesion_caja`);

    -- =========================================================================
    -- G. TABLA `autorizacion_caja` (Autorización administrativa de cierre — HU 17)
    -- =========================================================================
    CREATE TABLE `autorizacion_caja` (
      `id_autorizacion_caja` int(11) NOT NULL AUTO_INCREMENT,
      `id_sesion_caja` int(11) NOT NULL,
      `tipo_operacion` enum('CIERRE_ANTICIPADO') NOT NULL DEFAULT 'CIERRE_ANTICIPADO',
      `id_usuario_vendedor` int(11) NOT NULL,
      `id_usuario_admin` int(11) NOT NULL,
      `motivo` varchar(250) NOT NULL,
      `hora_salida_referencia` time NOT NULL,
      `fecha_hora_salida_referencia` datetime NOT NULL,
      `hora_cierre_real` datetime NOT NULL,
      `minutos_anticipacion` int(11) NOT NULL,
      `fecha_hora_autorizacion` datetime NOT NULL DEFAULT current_timestamp(),
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      PRIMARY KEY (`id_autorizacion_caja`),
      UNIQUE KEY `uq_autorizacion_sesion_unica` (`id_sesion_caja`),
      UNIQUE KEY `uq_autorizacion_sesion_operacion` (`id_sesion_caja`, `tipo_operacion`),
      UNIQUE KEY `uq_autorizacion_solicitud` (`id_solicitud_idempotencia`),
      KEY `idx_autorizacion_admin_fecha` (`id_usuario_admin`, `fecha_hora_autorizacion`),
      KEY `fk_autorizacion_vendedor` (`id_usuario_vendedor`),
      CONSTRAINT `fk_autorizacion_sesion` FOREIGN KEY (`id_sesion_caja`) REFERENCES `sesion_caja` (`id_sesion_caja`),
      CONSTRAINT `fk_autorizacion_vendedor` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_autorizacion_admin` FOREIGN KEY (`id_usuario_admin`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_autorizacion_solicitud` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_autorizacion_motivo_y_anticipacion` CHECK (
        char_length(trim(`motivo`)) between 1 and 250
        and `minutos_anticipacion` > 15
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

-- =============================================================================
-- TRIGGERS DE INTEGRIDAD PARA `caja_fisica_guardia`, `sesion_caja`,
-- `arqueo_caja` Y `autorizacion_caja`
-- =============================================================================

CREATE TRIGGER `trg_caja_fisica_guardia_before_delete_prohibido`
BEFORE DELETE ON `caja_fisica_guardia`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'GUARDIA_CAJA_INMUTABLE: La fila única de caja_fisica_guardia no puede eliminarse.';
END$$

CREATE TRIGGER `trg_sesion_caja_before_insert_integridad`
BEFORE INSERT ON `sesion_caja`
FOR EACH ROW
BEGIN
    DECLARE v_rol_vendedor INT DEFAULT NULL;
    DECLARE v_estado_vendedor INT DEFAULT NULL;
    DECLARE v_estado_turno INT DEFAULT NULL;
    DECLARE v_sesiones_previas INT DEFAULT 0;
    DECLARE v_prev_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_prev_contado DECIMAL(10,2) DEFAULT NULL;

    IF NEW.id_caja_fisica <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CAJA_FISICA_UNICA: id_caja_fisica debe ser 1.';
    END IF;

    IF NEW.estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'APERTURA_ESTADO_INVALIDO: Toda sesión de caja debe insertarse inicialmente con estado = ABIERTA.';
    END IF;

    SELECT id_rol, estado
      INTO v_rol_vendedor, v_estado_vendedor
      FROM usuario
     WHERE id_usuario = NEW.id_usuario_vendedor
     LIMIT 1;

    IF v_rol_vendedor IS NULL OR v_rol_vendedor <> 2 OR v_estado_vendedor <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'FORBIDDEN_SELLER_ONLY: La apertura de caja solo puede registrarse para un usuario activo con rol Vendedor (id_rol = 2).';
    END IF;

    SELECT estado
      INTO v_estado_turno
      FROM turno
     WHERE id_turno = NEW.id_turno
     LIMIT 1;

    IF v_estado_turno IS NULL OR v_estado_turno <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INVALID_SHIFT: El turno seleccionado para abrir caja no existe o está inactivo.';
    END IF;

    -- Validación de referencia al cierre anterior vs primera apertura histórica
    SELECT COUNT(*)
      INTO v_sesiones_previas
      FROM sesion_caja
     WHERE estado = 'CERRADA';

    IF NEW.tiene_cierre_anterior = 0 THEN
        IF NEW.id_sesion_cierre_anterior IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_SIN_CIERRE_INCONSISTENTE: Cuando tiene_cierre_anterior = 0, id_sesion_cierre_anterior debe ser NULL.';
        END IF;

        IF v_sesiones_previas > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_REQUIERE_CIERRE_ANTERIOR: Ya existen sesiones cerradas; la nueva apertura debe referenciar el último cierre.';
        END IF;
    ELSE
        IF NEW.id_sesion_cierre_anterior IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_CIERRE_ANTERIOR_REQUERIDO: Cuando tiene_cierre_anterior = 1, debe informar id_sesion_cierre_anterior.';
        END IF;

        SELECT estado, efectivo_contado_cierre
          INTO v_prev_estado, v_prev_contado
          FROM sesion_caja
         WHERE id_sesion_caja = NEW.id_sesion_cierre_anterior
         LIMIT 1;

        IF v_prev_estado IS NULL OR v_prev_estado <> 'CERRADA' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_CIERRE_ANTERIOR_INVALIDO: La sesión referenciada en id_sesion_cierre_anterior debe estar CERRADA.';
        END IF;

        IF NEW.monto_referencia_cierre_anterior <> v_prev_contado THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'APERTURA_MONTO_REFERENCIA_INCONSISTENTE: monto_referencia_cierre_anterior debe coincidir con efectivo_contado_cierre de la sesión anterior.';
        END IF;
    END IF;

    IF NEW.id_ultimo_arqueo IS NOT NULL OR NEW.id_arqueo_cierre IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'APERTURA_ARQUEO_INICIAL_INVALIDO: Una sesión recién abierta no puede tener arqueos asociados en el INSERT inicial.';
    END IF;
END$$

CREATE TRIGGER `trg_sesion_caja_after_insert_guardia`
AFTER INSERT ON `sesion_caja`
FOR EACH ROW
BEGIN
    IF NEW.estado = 'ABIERTA' THEN
        UPDATE caja_fisica_guardia
           SET id_sesion_abierta_actual = NEW.id_sesion_caja
         WHERE id_caja_fisica = 1;
    END IF;
END$$

CREATE TRIGGER `trg_sesion_caja_before_update_integridad`
BEFORE UPDATE ON `sesion_caja`
FOR EACH ROW
BEGIN
    DECLARE v_arq_sesion INT DEFAULT NULL;
    DECLARE v_arq_estado VARCHAR(20) DEFAULT NULL;
    DECLARE v_arq_version INT DEFAULT NULL;
    DECLARE v_arq_esperado DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_contado DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_diferencia DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_obs VARCHAR(500) DEFAULT NULL;
    DECLARE v_autorizaciones INT DEFAULT 0;

    -- 1. Una sesión CERRADA está completamente congelada: se protegen las 36 columnas con comparación NULL-safe (<=>)
    IF OLD.estado = 'CERRADA' THEN
        IF NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
           OR NOT (OLD.id_caja_fisica <=> NEW.id_caja_fisica)
           OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
           OR NOT (OLD.id_turno <=> NEW.id_turno)
           OR NOT (OLD.nombre_turno_referencia <=> NEW.nombre_turno_referencia)
           OR NOT (OLD.hora_inicio_referencia <=> NEW.hora_inicio_referencia)
           OR NOT (OLD.hora_fin_referencia <=> NEW.hora_fin_referencia)
           OR NOT (OLD.fecha_hora_salida_referencia <=> NEW.fecha_hora_salida_referencia)
           OR NOT (OLD.es_suplencia <=> NEW.es_suplencia)
           OR NOT (OLD.abierta_fuera_horario <=> NEW.abierta_fuera_horario)
           OR NOT (OLD.fecha_hora_apertura <=> NEW.fecha_hora_apertura)
           OR NOT (OLD.tiene_cierre_anterior <=> NEW.tiene_cierre_anterior)
           OR NOT (OLD.id_sesion_cierre_anterior <=> NEW.id_sesion_cierre_anterior)
           OR NOT (OLD.monto_referencia_cierre_anterior <=> NEW.monto_referencia_cierre_anterior)
           OR NOT (OLD.fecha_hora_cierre_anterior_ref <=> NEW.fecha_hora_cierre_anterior_ref)
           OR NOT (OLD.nombre_turno_cierre_anterior_ref <=> NEW.nombre_turno_cierre_anterior_ref)
           OR NOT (OLD.saldo_inicial <=> NEW.saldo_inicial)
           OR NOT (OLD.diferencia_apertura <=> NEW.diferencia_apertura)
           OR NOT (OLD.total_ventas_efectivo <=> NEW.total_ventas_efectivo)
           OR NOT (OLD.total_ventas_tarjeta <=> NEW.total_ventas_tarjeta)
           OR NOT (OLD.total_ventas_yape_plin <=> NEW.total_ventas_yape_plin)
           OR NOT (OLD.total_ingresos <=> NEW.total_ingresos)
           OR NOT (OLD.total_egresos <=> NEW.total_egresos)
           OR NOT (OLD.total_retiros_omitidos_declarados <=> NEW.total_retiros_omitidos_declarados)
           OR NOT (OLD.efectivo_esperado_actual <=> NEW.efectivo_esperado_actual)
           OR NOT (OLD.version_caja <=> NEW.version_caja)
           OR NOT (OLD.estado <=> NEW.estado)
           OR NOT (OLD.id_ultimo_arqueo <=> NEW.id_ultimo_arqueo)
           OR NOT (OLD.id_arqueo_cierre <=> NEW.id_arqueo_cierre)
           OR NOT (OLD.fecha_hora_cierre <=> NEW.fecha_hora_cierre)
           OR NOT (OLD.efectivo_esperado_cierre <=> NEW.efectivo_esperado_cierre)
           OR NOT (OLD.efectivo_contado_cierre <=> NEW.efectivo_contado_cierre)
           OR NOT (OLD.diferencia_cierre <=> NEW.diferencia_cierre)
           OR NOT (OLD.observacion_cierre <=> NEW.observacion_cierre)
           OR NOT (OLD.id_solicitud_apertura <=> NEW.id_solicitud_apertura)
           OR NOT (OLD.id_solicitud_cierre <=> NEW.id_solicitud_cierre)
           OR OLD.estado = 'CERRADA' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Una sesión de caja CERRADA está completamente congelada y no admite reapertura ni modificaciones.';
        END IF;
    END IF;

    -- 2. Las 19 columnas de identidad, horario de referencia, cierre anterior y apertura son inmutables siempre (comparación NULL-safe <=>)
    IF NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
       OR NOT (OLD.id_caja_fisica <=> NEW.id_caja_fisica)
       OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
       OR NOT (OLD.id_turno <=> NEW.id_turno)
       OR NOT (OLD.nombre_turno_referencia <=> NEW.nombre_turno_referencia)
       OR NOT (OLD.hora_inicio_referencia <=> NEW.hora_inicio_referencia)
       OR NOT (OLD.hora_fin_referencia <=> NEW.hora_fin_referencia)
       OR NOT (OLD.fecha_hora_salida_referencia <=> NEW.fecha_hora_salida_referencia)
       OR NOT (OLD.es_suplencia <=> NEW.es_suplencia)
       OR NOT (OLD.abierta_fuera_horario <=> NEW.abierta_fuera_horario)
       OR NOT (OLD.fecha_hora_apertura <=> NEW.fecha_hora_apertura)
       OR NOT (OLD.tiene_cierre_anterior <=> NEW.tiene_cierre_anterior)
       OR NOT (OLD.id_sesion_cierre_anterior <=> NEW.id_sesion_cierre_anterior)
       OR NOT (OLD.monto_referencia_cierre_anterior <=> NEW.monto_referencia_cierre_anterior)
       OR NOT (OLD.fecha_hora_cierre_anterior_ref <=> NEW.fecha_hora_cierre_anterior_ref)
       OR NOT (OLD.nombre_turno_cierre_anterior_ref <=> NEW.nombre_turno_cierre_anterior_ref)
       OR NOT (OLD.saldo_inicial <=> NEW.saldo_inicial)
       OR NOT (OLD.diferencia_apertura <=> NEW.diferencia_apertura)
       OR NOT (OLD.id_solicitud_apertura <=> NEW.id_solicitud_apertura) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'SESION_APERTURA_INMUTABLE: Los datos de identidad, turno, referencia anterior y apertura de la sesión no pueden modificarse.';
    END IF;

    IF NEW.version_caja < OLD.version_caja THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERSION_CAJA_REGRESIVA: version_caja no puede disminuir.';
    END IF;

    -- 3. Mientras está ABIERTA, todos los campos exclusivos de cierre deben permanecer NULL
    IF NEW.estado = 'ABIERTA' AND (
        NEW.id_arqueo_cierre IS NOT NULL
        OR NEW.fecha_hora_cierre IS NOT NULL
        OR NEW.efectivo_esperado_cierre IS NOT NULL
        OR NEW.efectivo_contado_cierre IS NOT NULL
        OR NEW.diferencia_cierre IS NOT NULL
        OR NEW.observacion_cierre IS NOT NULL
        OR NEW.id_solicitud_cierre IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CIERRE_INCOMPLETO: Los campos de cierre solo pueden asignarse al cerrar la sesión (estado = CERRADA).';
    END IF;

    -- 4. Al pasar de ABIERTA a CERRADA (EP-20):
    --    - No alterar acumulados operativos en la sentencia de cierre
    --    - Validar arqueo VIGENTE contra OLD.version_caja (versión previa al cierre)
    --    - Exigir incremento exacto NEW.version_caja = OLD.version_caja + 1
    --    - Congelar importes, observación y validar tolerancia de 15 minutos
    IF OLD.estado = 'ABIERTA' AND NEW.estado = 'CERRADA' THEN
        IF NOT (OLD.total_ventas_efectivo <=> NEW.total_ventas_efectivo)
           OR NOT (OLD.total_ventas_tarjeta <=> NEW.total_ventas_tarjeta)
           OR NOT (OLD.total_ventas_yape_plin <=> NEW.total_ventas_yape_plin)
           OR NOT (OLD.total_ingresos <=> NEW.total_ingresos)
           OR NOT (OLD.total_egresos <=> NEW.total_egresos)
           OR NOT (OLD.total_retiros_omitidos_declarados <=> NEW.total_retiros_omitidos_declarados)
           OR NOT (OLD.efectivo_esperado_actual <=> NEW.efectivo_esperado_actual) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CIERRE_ACUMULADOS_INMUTABLES: La operación de cierre no puede alterar los acumulados operativos de la sesión.';
        END IF;

        IF NEW.id_arqueo_cierre IS NULL OR NEW.id_ultimo_arqueo IS NULL
           OR NOT (NEW.id_arqueo_cierre <=> OLD.id_ultimo_arqueo)
           OR NOT (NEW.id_ultimo_arqueo <=> OLD.id_ultimo_arqueo) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CASH_COUNT_REQUIRED: Para cerrar la caja debe existir un último arqueo válido de la sesión y coincidir con id_arqueo_cierre.';
        END IF;

        SELECT id_sesion_caja, estado, version_caja_evaluada, monto_esperado, monto_contado, diferencia, observacion
          INTO v_arq_sesion, v_arq_estado, v_arq_version, v_arq_esperado, v_arq_contado, v_arq_diferencia, v_arq_obs
          FROM arqueo_caja
         WHERE id_arqueo_caja = NEW.id_arqueo_cierre
         LIMIT 1;

        IF v_arq_sesion IS NULL OR v_arq_sesion <> NEW.id_sesion_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CASH_COUNT_REQUIRED: El arqueo de cierre no pertenece a esta sesión de caja.';
        END IF;

        IF v_arq_estado <> 'VIGENTE' OR v_arq_version <> OLD.version_caja THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CASH_COUNT_OUTDATED: El arqueo referenciado ya no está VIGENTE o su version_caja_evaluada no coincide con la versión de la sesión antes del cierre.';
        END IF;

        IF NEW.version_caja <> OLD.version_caja + 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CIERRE_VERSION_INCREMENTO_REQUERIDO: El cierre de caja debe incrementar exactamente en +1 la version_caja de la sesión (EP-20).';
        END IF;

        IF NOT (NEW.efectivo_esperado_cierre <=> NEW.efectivo_esperado_actual)
           OR NOT (NEW.efectivo_esperado_cierre <=> v_arq_esperado)
           OR NOT (NEW.efectivo_contado_cierre <=> v_arq_contado)
           OR NOT (NEW.diferencia_cierre <=> v_arq_diferencia) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CIERRE_MONTOS_NO_CONGELADOS: Los montos congelados de cierre deben coincidir exactamente con el arqueo VIGENTE y el efectivo esperado actual.';
        END IF;

        IF NEW.observacion_cierre IS NULL AND v_arq_obs IS NOT NULL THEN
            SET NEW.observacion_cierre = v_arq_obs;
        END IF;

        IF NOT (NEW.observacion_cierre <=> v_arq_obs) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'CIERRE_OBSERVACION_INCONSISTENTE: observacion_cierre debe coincidir exactamente con la observación del arqueo de cierre.';
        END IF;

        -- Verificar tolerancia de 15 minutos antes de fecha_hora_salida_referencia (HU 17)
        IF NEW.fecha_hora_cierre < DATE_SUB(NEW.fecha_hora_salida_referencia, INTERVAL 15 MINUTE) THEN
            SELECT COUNT(*)
              INTO v_autorizaciones
              FROM autorizacion_caja
             WHERE id_sesion_caja = NEW.id_sesion_caja
               AND tipo_operacion = 'CIERRE_ANTICIPADO';

            IF v_autorizaciones = 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'EARLY_CLOSE_AUTH_REQUIRED: El cierre ocurre más de 15 minutos antes de la salida de referencia y requiere autorización administrativa registrada.';
            END IF;
        END IF;
    END IF;
END$$

CREATE TRIGGER `trg_sesion_caja_after_update_guardia`
AFTER UPDATE ON `sesion_caja`
FOR EACH ROW
BEGIN
    IF OLD.estado = 'ABIERTA' AND NEW.estado = 'CERRADA' THEN
        UPDATE caja_fisica_guardia
           SET id_sesion_abierta_actual = NULL,
               id_ultima_sesion_cerrada = NEW.id_sesion_caja
         WHERE id_caja_fisica = 1;
    END IF;
END$$

CREATE TRIGGER `trg_sesion_caja_before_delete_prohibido`
BEFORE DELETE ON `sesion_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_SESION_INMUTABLE: Las sesiones de caja no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_arqueo_caja_before_insert_integridad`
BEFORE INSERT ON `arqueo_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_sesion_version INT DEFAULT NULL;
    DECLARE v_sesion_esperado DECIMAL(10,2) DEFAULT NULL;

    SELECT estado, id_usuario_vendedor, version_caja, efectivo_esperado_actual
      INTO v_sesion_estado, v_sesion_vendedor, v_sesion_version, v_sesion_esperado
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: Solo se puede registrar un arqueo sobre una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_vendedor <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: Solo el vendedor titular de la sesión abierta puede registrar un arqueo.';
    END IF;

    IF NEW.estado <> 'VIGENTE' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ARQUEO_ESTADO_INICIAL_INVALIDO: Todo nuevo arqueo debe insertarse con estado = VIGENTE.';
    END IF;

    IF NEW.version_caja_evaluada <> v_sesion_version THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ARQUEO_VERSION_INCONSISTENTE: version_caja_evaluada debe coincidir con version_caja actual de la sesión.';
    END IF;

    IF NEW.monto_esperado <> v_sesion_esperado THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ARQUEO_MONTO_ESPERADO_INCONSISTENTE: monto_esperado debe coincidir con efectivo_esperado_actual de la sesión.';
    END IF;

    SET NEW.diferencia = ROUND(NEW.monto_contado - NEW.monto_esperado, 2);
    SET NEW.vigencia_activa = 1;
    SET NEW.causa_cambio_estado = NULL;
    SET NEW.fecha_hora_cambio_estado = NULL;
END$$

CREATE TRIGGER `trg_arqueo_caja_before_update_integridad`
BEFORE UPDATE ON `arqueo_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;

    SELECT estado
      INTO v_sesion_estado
      FROM sesion_caja
     WHERE id_sesion_caja = OLD.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado = 'CERRADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'HISTORIAL_ARQUEO_INMUTABLE: Los arqueos de una sesión de caja CERRADA están congelados y no admiten cambios.';
    END IF;

    IF NOT (OLD.id_arqueo_caja <=> NEW.id_arqueo_caja)
       OR NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
       OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
       OR NOT (OLD.version_caja_evaluada <=> NEW.version_caja_evaluada)
       OR NOT (OLD.monto_esperado <=> NEW.monto_esperado)
       OR NOT (OLD.monto_contado <=> NEW.monto_contado)
       OR NOT (OLD.diferencia <=> NEW.diferencia)
       OR NOT (OLD.observacion <=> NEW.observacion)
       OR NOT (OLD.fecha_hora_arqueo <=> NEW.fecha_hora_arqueo)
       OR NOT (OLD.id_solicitud_idempotencia <=> NEW.id_solicitud_idempotencia) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'HISTORIAL_ARQUEO_INMUTABLE: Los importes, versión, observación y marcas de tiempo de un arqueo no pueden editarse.';
    END IF;

    IF OLD.estado <> 'VIGENTE' AND NEW.estado <> OLD.estado THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'HISTORIAL_ARQUEO_INMUTABLE: Un arqueo REEMPLAZADO o DESACTUALIZADO no puede volver a cambiar de estado.';
    END IF;

    IF OLD.estado = 'VIGENTE' AND NEW.estado IN ('REEMPLAZADO', 'DESACTUALIZADO') THEN
        SET NEW.vigencia_activa = NULL;
        IF NEW.fecha_hora_cambio_estado IS NULL THEN
            SET NEW.fecha_hora_cambio_estado = NOW();
        END IF;
        IF NEW.causa_cambio_estado IS NULL THEN
            SET NEW.causa_cambio_estado = IF(NEW.estado = 'REEMPLAZADO', 'NUEVO_ARQUEO', 'OPERACION_CAJA_POSTERIOR');
        END IF;
    ELSE
        SET NEW.vigencia_activa = IF(NEW.estado = 'VIGENTE', 1, NULL);
    END IF;
END$$

CREATE TRIGGER `trg_arqueo_caja_before_delete_prohibido`
BEFORE DELETE ON `arqueo_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_ARQUEO_INMUTABLE: Los arqueos de caja se conservan en el historial y no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_autorizacion_caja_before_insert_integridad`
BEFORE INSERT ON `autorizacion_caja`
FOR EACH ROW
BEGIN
    DECLARE v_sesion_estado VARCHAR(16) DEFAULT NULL;
    DECLARE v_sesion_vendedor INT DEFAULT NULL;
    DECLARE v_sesion_hora_salida TIME DEFAULT NULL;
    DECLARE v_sesion_fecha_salida DATETIME DEFAULT NULL;
    DECLARE v_rol_admin INT DEFAULT NULL;
    DECLARE v_estado_admin INT DEFAULT NULL;

    SELECT estado, id_usuario_vendedor, hora_fin_referencia, fecha_hora_salida_referencia
      INTO v_sesion_estado, v_sesion_vendedor, v_sesion_hora_salida, v_sesion_fecha_salida
      FROM sesion_caja
     WHERE id_sesion_caja = NEW.id_sesion_caja
     LIMIT 1;

    IF v_sesion_estado IS NULL OR v_sesion_estado <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CASH_SESSION_NOT_OPEN: La autorización de cierre anticipado requiere una sesión de caja ABIERTA.';
    END IF;

    IF NEW.id_usuario_vendedor <> v_sesion_vendedor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NOT_CASH_SESSION_OWNER: id_usuario_vendedor en autorizacion_caja debe coincidir con el titular de la sesión.';
    END IF;

    SELECT id_rol, estado
      INTO v_rol_admin, v_estado_admin
      FROM usuario
     WHERE id_usuario = NEW.id_usuario_admin
     LIMIT 1;

    IF v_rol_admin IS NULL OR v_rol_admin <> 1 OR v_estado_admin <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INVALID_ADMIN_AUTHORIZATION: El autorizador debe ser un usuario activo con rol Administrador (id_rol = 1).';
    END IF;

    IF NEW.fecha_hora_salida_referencia <> v_sesion_fecha_salida
       OR NEW.hora_salida_referencia <> v_sesion_hora_salida THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'AUTORIZACION_SALIDA_INCONSISTENTE: La hora/fecha de salida de referencia debe coincidir con el snapshot de la sesión.';
    END IF;
END$$

CREATE TRIGGER `trg_autorizacion_caja_before_update_inmutable`
BEFORE UPDATE ON `autorizacion_caja`
FOR EACH ROW
BEGIN
    IF NOT (OLD.id_autorizacion_caja <=> NEW.id_autorizacion_caja)
       OR NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
       OR NOT (OLD.tipo_operacion <=> NEW.tipo_operacion)
       OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
       OR NOT (OLD.id_usuario_admin <=> NEW.id_usuario_admin)
       OR NOT (OLD.motivo <=> NEW.motivo)
       OR NOT (OLD.hora_salida_referencia <=> NEW.hora_salida_referencia)
       OR NOT (OLD.fecha_hora_salida_referencia <=> NEW.fecha_hora_salida_referencia)
       OR NOT (OLD.hora_cierre_real <=> NEW.hora_cierre_real)
       OR NOT (OLD.minutos_anticipacion <=> NEW.minutos_anticipacion)
       OR NOT (OLD.fecha_hora_autorizacion <=> NEW.fecha_hora_autorizacion)
       OR NOT (OLD.id_solicitud_idempotencia <=> NEW.id_solicitud_idempotencia) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'AUTORIZACION_INMUTABLE: Los registros de autorizacion_caja son evidencia de auditoría y no pueden modificarse.';
    END IF;
END$$

CREATE TRIGGER `trg_autorizacion_caja_before_delete_prohibido`
BEFORE DELETE ON `autorizacion_caja`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'AUTORIZACION_INMUTABLE: Los registros de autorizacion_caja no pueden eliminarse.';
END$$

-- =============================================================================
-- CIERRE DEL PASO 03: VERIFICACIÓN DE OBJETOS Y TRIGGERS ANTES DE AVANZAR PASO
-- =============================================================================
BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_tablas_ok INT DEFAULT 0;
    DECLARE v_guardia_ok INT DEFAULT 0;
    DECLARE v_fks_ok INT DEFAULT 0;
    DECLARE v_triggers_ok INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    SELECT COUNT(*)
      INTO v_tablas_ok
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME IN ('caja_fisica_guardia', 'sesion_caja', 'arqueo_caja', 'autorizacion_caja');

    SELECT COUNT(*)
      INTO v_guardia_ok
      FROM `caja_fisica_guardia`
     WHERE `id_caja_fisica` = 1;

    SELECT COUNT(*)
      INTO v_fks_ok
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = v_db_actual
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND TABLE_NAME IN ('caja_fisica_guardia', 'sesion_caja', 'arqueo_caja', 'autorizacion_caja');

    SELECT COUNT(*)
      INTO v_triggers_ok
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual
       AND TRIGGER_NAME IN (
           'trg_caja_fisica_guardia_before_delete_prohibido',
           'trg_sesion_caja_before_insert_integridad',
           'trg_sesion_caja_after_insert_guardia',
           'trg_sesion_caja_before_update_integridad',
           'trg_sesion_caja_after_update_guardia',
           'trg_sesion_caja_before_delete_prohibido',
           'trg_arqueo_caja_before_insert_integridad',
           'trg_arqueo_caja_before_update_integridad',
           'trg_arqueo_caja_before_delete_prohibido',
           'trg_autorizacion_caja_before_insert_integridad',
           'trg_autorizacion_caja_before_update_inmutable',
           'trg_autorizacion_caja_before_delete_prohibido'
       );

    IF v_tablas_ok <> 4 OR v_guardia_ok <> 1 OR v_fks_ok <> 17 OR v_triggers_ok <> 12 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO03_INCOMPLETA: No se crearon todas las tablas (4), fila guardia (1), FKs (17) o triggers (12) del paso 03.';
    END IF;

    UPDATE `sprint3_migracion_control`
       SET `paso_actual` = '03_SESIONES_ARQUEOS_AUTORIZACIONES'
     WHERE `id_migracion` = 3
       AND `estado` = 'EN_PROGRESO'
       AND `paso_actual` = '02_TURNOS_Y_PROGRAMACION';
END$$

DELIMITER ;
