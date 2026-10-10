USE amazon_market;

-- Insertar los permisos para el módulo de Compras
INSERT INTO permiso (nombre, slug, descripcion, grupo, estado) VALUES 
('Listar Compras', 'compras.listar', 'Ver el listado de compras', 'Compras', 1), 
('Crear Compras', 'compras.crear', 'Registrar nuevas compras', 'Compras', 1), 
('Editar Compras', 'compras.editar', 'Corregir detalles de compras', 'Compras', 1), 
('Anular Compras', 'compras.eliminar', 'Anular compras registradas', 'Compras', 1);

