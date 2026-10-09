USE amazon_market;

CREATE TABLE IF NOT EXISTS `proveedor` (
  `id_proveedor` int(11) NOT NULL AUTO_INCREMENT,
  `razon_social` varchar(150) NOT NULL,
  `id_tipo_documento` int(11) NOT NULL,
  `numero_documento` varchar(20) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `estado` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_proveedor`),
  UNIQUE KEY `numero_documento` (`numero_documento`),
  KEY `fk_proveedor_tipo_doc` (`id_tipo_documento`),
  CONSTRAINT `fk_proveedor_tipo_doc` FOREIGN KEY (`id_tipo_documento`) REFERENCES `tipo_documento` (`id_tipo_documento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compra` (
  `id_compra` int(11) NOT NULL AUTO_INCREMENT,
  `id_proveedor` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_tipo_comprobante` int(11) NOT NULL,
  `serie` varchar(10) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `fecha_emision` date NOT NULL,
  `fecha_recepcion` date NOT NULL,
  `total_documento` decimal(10,2) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'Confirmada',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id_compra`),
  CONSTRAINT `fk_compra_proveedor` FOREIGN KEY (`id_proveedor`) REFERENCES `proveedor` (`id_proveedor`),
  CONSTRAINT `fk_compra_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `detalle_compra` (
  `id_detalle_compra` int(11) NOT NULL AUTO_INCREMENT,
  `id_compra` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `cantidad_base` int(11) NOT NULL,
  `importe_final_linea` decimal(10,2) NOT NULL,
  `numero_lote` varchar(50) DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  PRIMARY KEY (`id_detalle_compra`),
  CONSTRAINT `fk_detalle_compra_compra` FOREIGN KEY (`id_compra`) REFERENCES `compra` (`id_compra`),
  CONSTRAINT `fk_detalle_compra_producto` FOREIGN KEY (`id_producto`) REFERENCES `producto` (`id_producto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kardex` (
  `id_kardex` int(11) NOT NULL AUTO_INCREMENT,
  `id_producto` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `tipo_movimiento` enum('Ingreso','Egreso','Ajuste','Correccion','Anulacion') NOT NULL,
  `cantidad` int(11) NOT NULL,
  `stock_anterior` int(11) NOT NULL,
  `stock_resultante` int(11) NOT NULL,
  `id_accion_compra` int(11) DEFAULT NULL,
  `id_detalle_compra` int(11) DEFAULT NULL,
  `id_accion_venta` int(11) DEFAULT NULL,
  `fecha_movimiento` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id_kardex`),
  CONSTRAINT `fk_kardex_producto` FOREIGN KEY (`id_producto`) REFERENCES `producto` (`id_producto`),
  CONSTRAINT `fk_kardex_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`),
  CONSTRAINT `fk_kardex_compra` FOREIGN KEY (`id_accion_compra`) REFERENCES `compra` (`id_compra`),
  CONSTRAINT `fk_kardex_detalle_compra` FOREIGN KEY (`id_detalle_compra`) REFERENCES `detalle_compra` (`id_detalle_compra`),
  CONSTRAINT `fk_kardex_venta` FOREIGN KEY (`id_accion_venta`) REFERENCES `venta` (`id_venta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

