-- Sprint 3: administrador operador, sin turno ficticio. Ejecutar mediante el runner.
-- No elimina ni modifica filas anteriores. DDL requiere mantenimiento y respaldo verificado.
DELIMITER $$
BEGIN NOT ATOMIC
 IF COALESCE(@CAJA_ADMIN_MIGRACION_AUTORIZADA,'') <> DATABASE() THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Destino de migracion no autorizado';
 END IF;
 IF EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sesion_caja' AND COLUMN_NAME='modalidad') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migracion 12 ya aplicada; no repetir';
 END IF;
END$$
DELIMITER ;
ALTER TABLE sesion_caja
 ADD COLUMN modalidad ENUM('CON_TURNO','SIN_HORARIO') NOT NULL DEFAULT 'CON_TURNO' AFTER id_usuario_vendedor,
 MODIFY id_turno INT(11) NULL,
 MODIFY hora_inicio_referencia TIME NULL,
 MODIFY hora_fin_referencia TIME NULL,
 MODIFY fecha_hora_salida_referencia DATETIME NULL,
 ADD CONSTRAINT chk_sesion_modalidad_horario CHECK (
 (modalidad='CON_TURNO' AND id_turno IS NOT NULL AND hora_inicio_referencia IS NOT NULL AND hora_fin_referencia IS NOT NULL AND fecha_hora_salida_referencia IS NOT NULL)
 OR (modalidad='SIN_HORARIO' AND id_turno IS NULL AND hora_inicio_referencia IS NULL AND hora_fin_referencia IS NULL AND fecha_hora_salida_referencia IS NULL AND es_suplencia=0 AND abierta_fuera_horario=0)
 );
DELIMITER $$
SET SESSION sql_mode='STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'$$
DROP TRIGGER `trg_sesion_caja_before_update_integridad`$$
CREATE TRIGGER `trg_sesion_caja_before_update_integridad` BEFORE UPDATE ON `sesion_caja` FOR EACH ROW
BEGIN
    DECLARE v_arq_sesion INT DEFAULT NULL;
    DECLARE v_arq_estado VARCHAR(20) DEFAULT NULL;
    DECLARE v_arq_version INT DEFAULT NULL;
    DECLARE v_arq_esperado DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_contado DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_diferencia DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_arq_obs VARCHAR(500) DEFAULT NULL;
    DECLARE v_autorizaciones INT DEFAULT 0;

    
    IF OLD.estado = 'CERRADA' THEN
        IF NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
           OR NOT (OLD.id_caja_fisica <=> NEW.id_caja_fisica)
           OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
           OR NOT (OLD.modalidad <=> NEW.modalidad)
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

    
    IF NOT (OLD.id_sesion_caja <=> NEW.id_sesion_caja)
       OR NOT (OLD.id_caja_fisica <=> NEW.id_caja_fisica)
       OR NOT (OLD.id_usuario_vendedor <=> NEW.id_usuario_vendedor)
       OR NOT (OLD.modalidad <=> NEW.modalidad)
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

        
        IF NEW.modalidad = 'CON_TURNO' AND NEW.fecha_hora_cierre < DATE_SUB(NEW.fecha_hora_salida_referencia, INTERVAL 15 MINUTE) THEN
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
SET SESSION sql_mode='STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'$$
DROP TRIGGER `trg_sesion_caja_before_insert_integridad`$$
CREATE TRIGGER `trg_sesion_caja_before_insert_integridad` BEFORE INSERT ON `sesion_caja` FOR EACH ROW
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

    IF v_rol_vendedor IS NULL OR v_rol_vendedor NOT IN (1,2) OR v_estado_vendedor <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'FORBIDDEN_OPERATOR: Se requiere administrador o vendedor activo.';
    END IF;

    IF (v_rol_vendedor=1 AND NEW.modalidad<>'SIN_HORARIO') OR (v_rol_vendedor=2 AND NEW.modalidad<>'CON_TURNO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='INVALID_SESSION_MODE: Modalidad incompatible con el responsable';
    END IF;
    IF NEW.modalidad='CON_TURNO' THEN
        SELECT estado INTO v_estado_turno FROM turno WHERE id_turno=NEW.id_turno LIMIT 1;
        IF v_estado_turno IS NULL OR v_estado_turno<>1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='INVALID_SHIFT: Se requiere turno activo';
        END IF;
    END IF;

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
DELIMITER ;
-- Asignaciones explícitas, conservando las existentes.
INSERT INTO rol_permiso(id_rol,id_permiso)
SELECT 1,p.id_permiso FROM permiso p
WHERE p.slug IN ('turnos.abrir','turnos.movimientos','turnos.arqueo','turnos.cerrar','turnos.acceder','ventas.acceder','ventas.procesar')
 AND NOT EXISTS(SELECT 1 FROM rol_permiso rp WHERE rp.id_rol=1 AND rp.id_permiso=p.id_permiso);
