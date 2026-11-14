-- =============================================================================
-- ARCHIVO: 02_estructura_turnos_y_programacion_sprint3.sql
-- SPRINT: 3 - Paso 5 (Migraciones incrementales con conservación de datos)
-- ORDEN DE EJECUCIÓN: 02 de 06 (Requiere 01_preflight_y_control_sprint3.sql)
-- MOTOR OBJETIVO: MariaDB 10.4.32 (InnoDB, utf8mb4_unicode_ci)
--
-- PROPÓSITO:
--   Implementar los dominios A (Turnos) y B (Programación semanal versionada):
--     1. Tabla `turno` con identidad, nombre, hora_inicio, hora_fin (`fin > inicio`),
--        estado `0/1`, unicidad de nombre activo (`LOWER(TRIM(nombre))`) mediante
--        columna generada persistida compatible con MariaDB 10.4 y baja lógica.
--     2. Tabla `programacion_semanal` versionada por vendedor (`id_rol = 2`),
--        lunes de inicio (`WEEKDAY(semana_inicio) = 0`), alcance (`'desde'` base
--        permanente o `'semana'` excepción puntual), autor (`id_rol = 1`) y
--        preservación de todas las versiones anteriores.
--     3. Tabla `programacion_semanal_detalle` con `id_turno` y `dia_semana` (`0..6`),
--        cuya unicidad es `(id_programacion, id_turno, dia_semana)` para permitir
--        varios turnos en un mismo día (p. ej. Mañana y Tarde el lunes).
--     4. Preservación de excepciones semanales (`alcance = 'semana'`) cuando se
--        guarda una nueva base (`alcance = 'desde'`), conforme a la decisión
--        confirmada en la Sección 13.1 del Contrato API.
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
            SET MESSAGE_TEXT = 'PROTECCION_ACTIVA: Base no autorizada o falta @SPRINT3_BASE_CONFIRMADA en paso 02.';
    END IF;

    SET v_lock_name = CONCAT('am_sprint3_mig_', MD5(v_db_actual));

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME = 'sprint3_migracion_control'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: Ejecute primero 01_preflight_y_control_sprint3.sql.';
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

    IF v_estado_migracion <> 'EN_PROGRESO' OR v_paso_migracion <> '01_PREFLIGHT' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ORDEN_MIGRACION_INVALIDO: El paso 02 exige estado EN_PROGRESO y paso_actual = 01_PREFLIGHT.';
    END IF;

    IF COALESCE(IS_USED_LOCK(v_lock_name), -1) <> CONNECTION_ID() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LOCK_MIGRACION_NO_VIGENTE: El paso 02 debe ejecutarse en la misma conexión que adquirió GET_LOCK en el paso 01.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = v_db_actual
           AND TABLE_NAME IN ('turno', 'programacion_semanal', 'programacion_semanal_detalle')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'OBJETOS_EXISTENTES_PASO02: Las tablas de turnos o programación ya existen en esta base.';
    END IF;

    -- =========================================================================
    -- A. TABLA `turno` (Catálogo de turnos de atención — HU 18 y HU 11)
    -- =========================================================================
    CREATE TABLE `turno` (
      `id_turno` int(11) NOT NULL AUTO_INCREMENT,
      `nombre` varchar(60) NOT NULL,
      `hora_inicio` time NOT NULL,
      `hora_fin` time NOT NULL,
      `estado` tinyint(4) NOT NULL DEFAULT 1,
      `nombre_activo_normalizado` varchar(60) AS (if(`estado` = 1, lower(trim(`nombre`)), NULL)) PERSISTENT,
      `id_usuario_creacion` int(11) DEFAULT NULL,
      `id_solicitud_creacion` int(11) DEFAULT NULL,
      `id_solicitud_ultima_edicion` int(11) DEFAULT NULL,
      `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
      `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      `baja_en` datetime DEFAULT NULL,
      PRIMARY KEY (`id_turno`),
      UNIQUE KEY `uq_turno_nombre_activo` (`nombre_activo_normalizado`),
      KEY `idx_turno_estado_inicio` (`estado`, `hora_inicio`, `id_turno`),
      KEY `fk_turno_usuario_creacion` (`id_usuario_creacion`),
      KEY `fk_turno_solicitud_creacion` (`id_solicitud_creacion`),
      KEY `fk_turno_solicitud_edicion` (`id_solicitud_ultima_edicion`),
      CONSTRAINT `fk_turno_usuario_creacion` FOREIGN KEY (`id_usuario_creacion`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_turno_solicitud_creacion` FOREIGN KEY (`id_solicitud_creacion`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `fk_turno_solicitud_edicion` FOREIGN KEY (`id_solicitud_ultima_edicion`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_turno_nombre` CHECK (char_length(trim(`nombre`)) between 1 and 60),
      CONSTRAINT `chk_turno_horario` CHECK (
        `hora_inicio` >= '00:00:00'
        and `hora_fin` <= '23:59:00'
        and second(`hora_inicio`) = 0
        and second(`hora_fin`) = 0
        and `hora_fin` > `hora_inicio`
      ),
      CONSTRAINT `chk_turno_estado` CHECK (`estado` in (0, 1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- B.1. TABLA `programacion_semanal` (Cabecera versionada — HU 18)
    -- =========================================================================
    CREATE TABLE `programacion_semanal` (
      `id_programacion` int(11) NOT NULL AUTO_INCREMENT,
      `id_usuario_vendedor` int(11) NOT NULL,
      `semana_inicio` date NOT NULL,
      `alcance` enum('desde','semana') NOT NULL,
      `version_programacion` int(11) NOT NULL DEFAULT 1,
      `es_vigente` tinyint(1) NOT NULL DEFAULT 1,
      `vigencia_activa` tinyint(1) DEFAULT 1,
      `id_programacion_previa` int(11) DEFAULT NULL,
      `id_programacion_origen_duplicacion` int(11) DEFAULT NULL,
      `id_usuario_autor` int(11) NOT NULL,
      `id_solicitud_idempotencia` int(11) DEFAULT NULL,
      `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
      `reemplazada_en` datetime DEFAULT NULL,
      PRIMARY KEY (`id_programacion`),
      UNIQUE KEY `uq_prog_vendedor_semana_alcance_vigente` (`id_usuario_vendedor`, `semana_inicio`, `alcance`, `vigencia_activa`),
      UNIQUE KEY `uq_prog_vendedor_semana_alcance_version` (`id_usuario_vendedor`, `semana_inicio`, `alcance`, `version_programacion`),
      KEY `idx_prog_resolucion_vigente` (`id_usuario_vendedor`, `es_vigente`, `alcance`, `semana_inicio`),
      KEY `fk_prog_previa` (`id_programacion_previa`),
      KEY `fk_prog_origen_duplicacion` (`id_programacion_origen_duplicacion`),
      KEY `fk_prog_usuario_autor` (`id_usuario_autor`),
      KEY `fk_prog_solicitud_idemp` (`id_solicitud_idempotencia`),
      CONSTRAINT `fk_prog_usuario_vendedor` FOREIGN KEY (`id_usuario_vendedor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_prog_previa` FOREIGN KEY (`id_programacion_previa`) REFERENCES `programacion_semanal` (`id_programacion`),
      CONSTRAINT `fk_prog_origen_duplicacion` FOREIGN KEY (`id_programacion_origen_duplicacion`) REFERENCES `programacion_semanal` (`id_programacion`),
      CONSTRAINT `fk_prog_usuario_autor` FOREIGN KEY (`id_usuario_autor`) REFERENCES `usuario` (`id_usuario`),
      CONSTRAINT `fk_prog_solicitud_idemp` FOREIGN KEY (`id_solicitud_idempotencia`) REFERENCES `control_solicitud_idempotencia` (`id_solicitud`),
      CONSTRAINT `chk_prog_semana_lunes` CHECK (weekday(`semana_inicio`) = 0),
      CONSTRAINT `chk_prog_version` CHECK (
        `version_programacion` >= 1
        and (
          (`es_vigente` = 1 and `vigencia_activa` = 1)
          or (`es_vigente` = 0 and `vigencia_activa` is null)
        )
      )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- =========================================================================
    -- B.2. TABLA `programacion_semanal_detalle` (Turnos y días 0..6 — HU 18)
    -- =========================================================================
    CREATE TABLE `programacion_semanal_detalle` (
      `id_programacion_detalle` int(11) NOT NULL AUTO_INCREMENT,
      `id_programacion` int(11) NOT NULL,
      `id_turno` int(11) NOT NULL,
      `dia_semana` tinyint(4) NOT NULL,
      PRIMARY KEY (`id_programacion_detalle`),
      UNIQUE KEY `uq_prog_detalle_version_turno_dia` (`id_programacion`, `id_turno`, `dia_semana`),
      KEY `idx_prog_detalle_turno` (`id_turno`, `dia_semana`),
      CONSTRAINT `fk_prog_detalle_cabecera` FOREIGN KEY (`id_programacion`) REFERENCES `programacion_semanal` (`id_programacion`),
      CONSTRAINT `fk_prog_detalle_turno` FOREIGN KEY (`id_turno`) REFERENCES `turno` (`id_turno`),
      CONSTRAINT `chk_prog_detalle_dia` CHECK (`dia_semana` between 0 and 6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

-- =============================================================================
-- TRIGGERS DE INTEGRIDAD PARA `turno`, `programacion_semanal` Y DETALLE
-- =============================================================================

CREATE TRIGGER `trg_turno_before_delete_prohibido`
BEFORE DELETE ON `turno`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'BAJA_LOGICA_REQUERIDA: Los turnos no se eliminan físicamente; actualice estado = 0 para preservar referencias históricas.';
END$$

CREATE TRIGGER `trg_turno_before_update_integridad`
BEFORE UPDATE ON `turno`
FOR EACH ROW
BEGIN
    DECLARE v_asignados INT DEFAULT 0;
    DECLARE v_sesiones_abiertas INT DEFAULT 0;
    DECLARE v_semana_actual DATE;
    DECLARE v_vendedor INT;
    DECLARE v_inicio_base DATE;
    DECLARE v_desde DATE;
    DECLARE v_siguiente_base DATE;
    DECLARE v_semanas INT;
    DECLARE v_excepciones INT;
    DECLARE v_fin INT DEFAULT 0;
    DECLARE c_bases CURSOR FOR
        SELECT DISTINCT ps.id_usuario_vendedor, ps.semana_inicio
          FROM programacion_semanal ps
          JOIN programacion_semanal_detalle psd
            ON psd.id_programacion = ps.id_programacion
         WHERE ps.es_vigente = 1
           AND ps.alcance = 'desde'
           AND psd.id_turno = OLD.id_turno;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;

    IF OLD.estado = 1 AND NEW.estado = 0 THEN
        SET v_semana_actual = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY);

        -- Una excepción exacta actual/futura tiene prioridad sobre cualquier base.
        SELECT COUNT(DISTINCT ps.id_usuario_vendedor)
          INTO v_asignados
          FROM programacion_semanal ps
          INNER JOIN programacion_semanal_detalle psd
             ON psd.id_programacion = ps.id_programacion
         WHERE ps.es_vigente = 1
           AND psd.id_turno = OLD.id_turno
           AND ps.alcance = 'semana'
           AND ps.semana_inicio >= v_semana_actual;

        IF v_asignados = 0 THEN
            -- es_vigente identifica la versión ganadora de una regla, no que una
            -- base histórica siga aplicándose después de otra base más reciente.
            -- Cada base rige [su lunes, siguiente base), excepto semanas puntuales.
            OPEN c_bases;
            bases_vigentes: LOOP
                FETCH c_bases INTO v_vendedor, v_inicio_base;
                IF v_fin = 1 THEN
                    LEAVE bases_vigentes;
                END IF;

                SET v_desde = GREATEST(v_inicio_base, v_semana_actual);
                SELECT MIN(semana_inicio)
                  INTO v_siguiente_base
                  FROM programacion_semanal
                 WHERE id_usuario_vendedor = v_vendedor
                   AND es_vigente = 1
                   AND alcance = 'desde'
                   AND semana_inicio > v_inicio_base;

                IF v_siguiente_base IS NULL THEN
                    -- La última base vuelve a regir tras las excepciones finitas.
                    SET v_asignados = 1;
                    LEAVE bases_vigentes;
                END IF;

                IF v_siguiente_base > v_desde THEN
                    SET v_semanas = DATEDIFF(v_siguiente_base, v_desde) DIV 7;
                    SELECT COUNT(DISTINCT semana_inicio)
                      INTO v_excepciones
                      FROM programacion_semanal
                     WHERE id_usuario_vendedor = v_vendedor
                       AND es_vigente = 1
                       AND alcance = 'semana'
                       AND semana_inicio >= v_desde
                       AND semana_inicio < v_siguiente_base;

                    IF v_excepciones < v_semanas THEN
                        SET v_asignados = 1;
                        LEAVE bases_vigentes;
                    END IF;
                END IF;
            END LOOP bases_vigentes;
            CLOSE c_bases;
        END IF;

        IF v_asignados > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'SHIFT_HAS_ASSIGNED_SELLERS: No se puede dar de baja un turno con vendedores asignados en programación vigente o futura.';
        END IF;

        -- Si ya existe la tabla sesion_caja, verificar que no haya sesión ABIERTA usando este turno
        IF EXISTS (
            SELECT 1
              FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'sesion_caja'
        ) THEN
            SELECT COUNT(*)
              INTO v_sesiones_abiertas
              FROM sesion_caja
             WHERE id_turno = OLD.id_turno
               AND estado = 'ABIERTA';

            IF v_sesiones_abiertas > 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'SHIFT_IN_USE: No se puede dar de baja el turno mientras exista una sesión de caja abierta asociada a él.';
            END IF;
        END IF;

        IF NEW.baja_en IS NULL THEN
            SET NEW.baja_en = NOW();
        END IF;
    END IF;
END$$

CREATE TRIGGER `trg_programacion_semanal_before_insert_integridad`
BEFORE INSERT ON `programacion_semanal`
FOR EACH ROW
BEGIN
    DECLARE v_rol_vendedor INT DEFAULT NULL;
    DECLARE v_estado_vendedor INT DEFAULT NULL;
    DECLARE v_rol_autor INT DEFAULT NULL;
    DECLARE v_estado_autor INT DEFAULT NULL;
    DECLARE v_tiene_base INT DEFAULT 0;

    IF WEEKDAY(NEW.semana_inicio) <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INVALID_WEEK_START_DATE: semana_inicio debe corresponder obligatoriamente a un lunes.';
    END IF;

    SELECT id_rol, estado
      INTO v_rol_vendedor, v_estado_vendedor
      FROM usuario
     WHERE id_usuario = NEW.id_usuario_vendedor
     LIMIT 1;

    IF v_rol_vendedor IS NULL OR v_rol_vendedor <> 2 OR v_estado_vendedor <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'TARGET_NOT_SELLER: La programación semanal solo puede asignarse a un usuario activo con rol Vendedor (id_rol = 2).';
    END IF;

    SELECT id_rol, estado
      INTO v_rol_autor, v_estado_autor
      FROM usuario
     WHERE id_usuario = NEW.id_usuario_autor
     LIMIT 1;

    IF v_rol_autor IS NULL OR v_rol_autor <> 1 OR v_estado_autor <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'FORBIDDEN_ADMIN_ONLY: La programación semanal solo puede ser registrada por un Administrador activo (id_rol = 1).';
    END IF;

    IF NEW.alcance = 'semana' THEN
        SELECT COUNT(*)
          INTO v_tiene_base
          FROM programacion_semanal ps
         WHERE ps.id_usuario_vendedor = NEW.id_usuario_vendedor
           AND ps.alcance = 'desde'
           AND ps.es_vigente = 1
           AND ps.semana_inicio <= NEW.semana_inicio;

        IF v_tiene_base = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'PRIMERA_PROGRAMACION_REQUIERE_BASE: No se puede registrar una excepción semanal sin una programación base vigente aplicable.';
        END IF;
    END IF;

    SET NEW.vigencia_activa = IF(NEW.es_vigente = 1, 1, NULL);
END$$

CREATE TRIGGER `trg_programacion_semanal_before_update_integridad`
BEFORE UPDATE ON `programacion_semanal`
FOR EACH ROW
BEGIN
    IF OLD.id_usuario_vendedor <> NEW.id_usuario_vendedor
       OR OLD.semana_inicio <> NEW.semana_inicio
       OR OLD.alcance <> NEW.alcance
       OR OLD.version_programacion <> NEW.version_programacion
       OR OLD.id_usuario_autor <> NEW.id_usuario_autor THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INMUTABILIDAD_VERSION_PROGRAMACION: No se permite alterar la identidad ni los metadatos de una versión de programación; cree una nueva versión.';
    END IF;

    IF OLD.es_vigente = 0 AND NEW.es_vigente = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INMUTABILIDAD_VERSION_PROGRAMACION: Una versión histórica reemplazada no puede reactivarse; registre una nueva versión.';
    END IF;

    SET NEW.vigencia_activa = IF(NEW.es_vigente = 1, 1, NULL);

    IF OLD.es_vigente = 1 AND NEW.es_vigente = 0 AND NEW.reemplazada_en IS NULL THEN
        SET NEW.reemplazada_en = NOW();
    END IF;
END$$

CREATE TRIGGER `trg_programacion_semanal_before_delete_prohibido`
BEFORE DELETE ON `programacion_semanal`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_PROGRAMACION_INMUTABLE: Las versiones de programación semanal se conservan y no pueden eliminarse.';
END$$

CREATE TRIGGER `trg_prog_detalle_before_insert_integridad`
BEFORE INSERT ON `programacion_semanal_detalle`
FOR EACH ROW
BEGIN
    DECLARE v_estado_turno INT DEFAULT NULL;

    SELECT estado
      INTO v_estado_turno
      FROM turno
     WHERE id_turno = NEW.id_turno
     LIMIT 1;

    IF v_estado_turno IS NULL OR v_estado_turno <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'INVALID_SHIFT: El turno asignado en la programación no existe o se encuentra inactivo.';
    END IF;
END$$

CREATE TRIGGER `trg_prog_detalle_before_delete_prohibido`
BEFORE DELETE ON `programacion_semanal_detalle`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HISTORIAL_PROGRAMACION_INMUTABLE: Los detalles de versiones de programación semanal no pueden eliminarse.';
END$$

-- =============================================================================
-- CIERRE DEL PASO 02: VERIFICACIÓN DE OBJETOS Y TRIGGERS ANTES DE AVANZAR PASO
-- =============================================================================
BEGIN NOT ATOMIC
    DECLARE v_db_actual VARCHAR(128);
    DECLARE v_tablas_ok INT DEFAULT 0;
    DECLARE v_fks_ok INT DEFAULT 0;
    DECLARE v_triggers_ok INT DEFAULT 0;

    SET v_db_actual = DATABASE();

    SELECT COUNT(*)
      INTO v_tablas_ok
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_db_actual
       AND TABLE_NAME IN ('turno', 'programacion_semanal', 'programacion_semanal_detalle');

    SELECT COUNT(*)
      INTO v_fks_ok
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = v_db_actual
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND TABLE_NAME IN ('turno', 'programacion_semanal', 'programacion_semanal_detalle');

    SELECT COUNT(*)
      INTO v_triggers_ok
      FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = v_db_actual
       AND TRIGGER_NAME IN (
           'trg_turno_before_delete_prohibido',
           'trg_turno_before_update_integridad',
           'trg_programacion_semanal_before_insert_integridad',
           'trg_programacion_semanal_before_update_integridad',
           'trg_programacion_semanal_before_delete_prohibido',
           'trg_prog_detalle_before_insert_integridad',
           'trg_prog_detalle_before_delete_prohibido'
       );

    IF v_tablas_ok <> 3 OR v_fks_ok <> 10 OR v_triggers_ok <> 7 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'VERIFICACION_PASO02_INCOMPLETA: No se crearon todas las tablas (3), FKs (10) o triggers (7) del paso 02.';
    END IF;

    UPDATE `sprint3_migracion_control`
       SET `paso_actual` = '02_TURNOS_Y_PROGRAMACION'
     WHERE `id_migracion` = 3
       AND `estado` = 'EN_PROGRESO'
       AND `paso_actual` = '01_PREFLIGHT';
END$$

DELIMITER ;
