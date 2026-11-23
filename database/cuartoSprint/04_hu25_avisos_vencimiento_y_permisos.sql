-- ============================================================================
-- Migración 04 - Sprint 4 - HU-25: Consultar avisos de vencimiento y permisos
-- Preserva compras, detalle_compra, kardex, producto y correcciones existentes.
-- 1) Agrega id_linea_compra en detalle_compra para identidad estable por línea.
-- 2) Crea aviso_vencimiento y revision_aviso_vencimiento.
-- 3) Habilita REVISION_AVISO_VENCIMIENTO en control_solicitud_idempotencia.
-- 4) Crea y asigna permisos inventario.vencimientos e inventario.bajas_vencimiento.
-- ============================================================================

-- 1. Identidad estable de línea en detalle_compra
ALTER TABLE `detalle_compra`
    ADD COLUMN `id_linea_compra` INT(11) NULL AFTER `id_detalle_compra`;

-- Inicializar raíces (líneas sin origen apuntan a sí mismas)
UPDATE `detalle_compra`
   SET `id_linea_compra` = `id_detalle_compra`
 WHERE `id_detalle_origen` IS NULL
   AND `id_linea_compra` IS NULL;

-- Propagar raíz a versiones corregidas (hasta 5 niveles por seguridad)
UPDATE `detalle_compra` d
  JOIN `detalle_compra` p ON p.`id_detalle_compra` = d.`id_detalle_origen`
   SET d.`id_linea_compra` = COALESCE(p.`id_linea_compra`, p.`id_detalle_compra`)
 WHERE d.`id_linea_compra` IS NULL;

UPDATE `detalle_compra` d
  JOIN `detalle_compra` p ON p.`id_detalle_compra` = d.`id_detalle_origen`
   SET d.`id_linea_compra` = COALESCE(p.`id_linea_compra`, p.`id_detalle_compra`)
 WHERE d.`id_linea_compra` IS NULL;

UPDATE `detalle_compra`
   SET `id_linea_compra` = `id_detalle_compra`
 WHERE `id_linea_compra` IS NULL;

ALTER TABLE `detalle_compra`
    MODIFY COLUMN `id_linea_compra` INT(11) NOT NULL,
    ADD KEY `idx_detalle_compra_linea_vigente` (`id_linea_compra`, `es_vigente`),
    ADD KEY `idx_detalle_compra_vencimiento` (`es_vigente`, `fecha_vencimiento`);

-- 2. Tabla de avisos de vencimiento (1 por línea lógica de compra)
CREATE TABLE `aviso_vencimiento` (
    `id_aviso` INT(11) NOT NULL AUTO_INCREMENT,
    `id_linea_compra` INT(11) NOT NULL,
    `id_compra` INT(11) NOT NULL,
    `id_detalle_compra_vigente` INT(11) NOT NULL,
    `fecha_vencimiento_vigente` DATE NOT NULL,
    `estado` ENUM('ACTIVO','CERRADO') NOT NULL DEFAULT 'ACTIVO',
    `cerrado_en` DATETIME DEFAULT NULL,
    `cerrado_por` INT(11) DEFAULT NULL,
    `ultima_revision_en` DATETIME DEFAULT NULL,
    `ultima_revision_por` INT(11) DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_aviso`),
    UNIQUE KEY `uq_aviso_linea_compra` (`id_linea_compra`),
    KEY `idx_aviso_estado_fecha` (`estado`, `fecha_vencimiento_vigente`, `id_aviso`),
    KEY `fk_aviso_compra` (`id_compra`),
    KEY `fk_aviso_detalle_vigente` (`id_detalle_compra_vigente`),
    KEY `fk_aviso_cerrado_por` (`cerrado_por`),
    KEY `fk_aviso_ultima_rev_por` (`ultima_revision_por`),
    CONSTRAINT `fk_aviso_compra` FOREIGN KEY (`id_compra`) REFERENCES `compra` (`id_compra`),
    CONSTRAINT `fk_aviso_detalle_vigente` FOREIGN KEY (`id_detalle_compra_vigente`) REFERENCES `detalle_compra` (`id_detalle_compra`),
    CONSTRAINT `fk_aviso_cerrado_por` FOREIGN KEY (`cerrado_por`) REFERENCES `usuario` (`id_usuario`),
    CONSTRAINT `fk_aviso_ultima_rev_por` FOREIGN KEY (`ultima_revision_por`) REFERENCES `usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Poblar avisos activos para todas las líneas vigentes existentes con fecha de vencimiento
INSERT INTO `aviso_vencimiento` (
    `id_linea_compra`,
    `id_compra`,
    `id_detalle_compra_vigente`,
    `fecha_vencimiento_vigente`,
    `estado`
)
SELECT
    dc.`id_linea_compra`,
    dc.`id_compra`,
    dc.`id_detalle_compra`,
    dc.`fecha_vencimiento`,
    'ACTIVO'
FROM `detalle_compra` dc
INNER JOIN `compra` co ON co.`id_compra` = dc.`id_compra`
WHERE dc.`es_vigente` = 1
  AND dc.`fecha_vencimiento` IS NOT NULL
  AND co.`estado` <> 'Anulada';

-- 3. Tabla de revisiones físicas de avisos de vencimiento (auditoría cronológica)
CREATE TABLE `revision_aviso_vencimiento` (
    `id_revision` INT(11) NOT NULL AUTO_INCREMENT,
    `id_aviso` INT(11) NOT NULL,
    `id_detalle_compra` INT(11) NOT NULL,
    `id_usuario` INT(11) NOT NULL,
    `respuesta` ENUM('SI_QUEDAN','NO_QUEDAN') NOT NULL,
    `fecha_vencimiento_revisada` DATE NOT NULL,
    `estado_resultante` ENUM('ACTIVO','CERRADO') NOT NULL,
    `referencia_solicitud` VARCHAR(64) NOT NULL,
    `fecha_revision` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_revision`),
    UNIQUE KEY `uq_rev_aviso_referencia` (`referencia_solicitud`),
    KEY `idx_rev_aviso_fecha` (`id_aviso`, `fecha_revision`, `id_revision`),
    KEY `fk_rev_aviso_detalle` (`id_detalle_compra`),
    KEY `fk_rev_aviso_usuario` (`id_usuario`),
    CONSTRAINT `fk_rev_aviso_aviso` FOREIGN KEY (`id_aviso`) REFERENCES `aviso_vencimiento` (`id_aviso`),
    CONSTRAINT `fk_rev_aviso_detalle` FOREIGN KEY (`id_detalle_compra`) REFERENCES `detalle_compra` (`id_detalle_compra`),
    CONSTRAINT `fk_rev_aviso_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`),
    CONSTRAINT `chk_rev_aviso_ref` CHECK (CHAR_LENGTH(`referencia_solicitud`) BETWEEN 16 AND 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Habilitar REVISION_AVISO_VENCIMIENTO en control_solicitud_idempotencia
ALTER TABLE `control_solicitud_idempotencia`
    MODIFY COLUMN `tipo_operacion` ENUM(
        'BAJA',
        'AJUSTE',
        'CORRECCION_INVENTARIO',
        'REVISION_AVISO_VENCIMIENTO',
        'COMPRA',
        'CORRECCION_COMPRA',
        'ANULACION_COMPRA',
        'VENTA',
        'ANULACION_VENTA',
        'TURNO_CREAR',
        'TURNO_EDITAR',
        'TURNO_BAJA',
        'PROGRAMACION_HORARIO',
        'CAJA_ABRIR',
        'CAJA_INGRESO',
        'CAJA_EGRESO',
        'CAJA_CORRECCION',
        'CAJA_ARQUEO',
        'CAJA_CERRAR'
    ) NOT NULL;

-- 5. Permisos RBAC para HU-25 (consulta/confirmación de avisos y baja manual por vencimiento)
INSERT IGNORE INTO `permiso` (`nombre`, `slug`, `descripcion`, `grupo`, `estado`) VALUES
('Avisos de vencimiento', 'inventario.vencimientos', 'Consultar y confirmar avisos de vencimiento', 'Inventario', 1),
('Baja manual por vencimiento', 'inventario.bajas_vencimiento', 'Registrar bajas manuales de inventario únicamente por motivo de vencimiento', 'Inventario', 1);

INSERT IGNORE INTO `rol_permiso` (`id_rol`, `id_permiso`)
SELECT r.`id_rol`, p.`id_permiso`
FROM `rol` r
INNER JOIN `permiso` p ON p.`slug` IN ('inventario.vencimientos', 'inventario.bajas_vencimiento')
WHERE r.`id_rol` IN (1, 2);
