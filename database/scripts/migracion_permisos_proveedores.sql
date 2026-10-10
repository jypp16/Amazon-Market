USE amazon_market;

-- Insertar los permisos para el módulo de Proveedores
INSERT INTO permiso (nombre, slug, descripcion, grupo, estado) VALUES 
('Listar Proveedores', 'proveedores.listar', 'Ver el listado de proveedores', 'Proveedores', 1), 
('Crear Proveedores', 'proveedores.crear', 'Registrar nuevos proveedores', 'Proveedores', 1), 
('Editar Proveedores', 'proveedores.editar', 'Modificar informacion de proveedores', 'Proveedores', 1), 
('Eliminar Proveedores', 'proveedores.eliminar', 'Dar de baja proveedores', 'Proveedores', 1);

