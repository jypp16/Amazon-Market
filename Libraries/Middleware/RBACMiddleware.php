<?php

namespace Libraries\Middleware;

use Models\PermisoModel;

class RBACMiddleware extends Middleware {

    private static $permisosCache = null;

    public function handle(): void {
        if (empty($_SESSION['login'])) {
            return;
        }

        $usuario = AuthMiddleware::verificarUsuarioActivoEnSesion();
        if ($usuario === null) {
            if ($this->isApiRequest()) {
                $this->jsonResponse([
                    'status' => false,
                    'code' => 'AUTH_REQUIRED',
                    'message' => 'Su sesión ha expirado o el usuario ya no se encuentra activo.',
                    'data' => null,
                ], 401);
            } else {
                $this->redirect('/Auth');
            }
            return;
        }

        $rol = (int)$usuario['id_rol'];

        $url = $_GET['url'] ?? 'Home';
        $parts = explode('/', $url);
        $controller = strtolower($parts[0] ?? 'Home');
        $method = strtolower($parts[1] ?? 'index');

        // Regla obligatoria Sprint 2: funciones de Inventario (bajas/ajustes), Kardex y Variaciones
        // exigen rol Administrador activo (id_rol = 1) además del permiso RBAC correspondiente.
        if (self::esRutaExclusivaAdminSprint2($controller, $method) && $rol !== 1) {
            $this->denegarAccesoSprint2();
            return;
        }

        $slug = self::mapControllerMethodToSlug($controller, $method);
        if ($slug && !self::tienePermiso($rol, $slug)) {
            if ($this->isApiRequest()) {
                $this->jsonResponse([
                    'status' => false,
                    'code' => $rol !== 1 && self::esSlugExclusivoAdminSprint2($slug) ? 'FORBIDDEN_ADMIN_ONLY' : 'FORBIDDEN',
                    'message' => 'No tienes permisos para acceder a este recurso.',
                    'data' => null,
                ], 403);
            } else {
                $_SESSION['error'] = 'No tienes permisos para acceder a esta sección.';
                $this->redirect('/Home');
            }
        }
    }

    private function denegarAccesoSprint2(): void {
        if ($this->isApiRequest()) {
            $this->jsonResponse([
                'status' => false,
                'code' => 'FORBIDDEN_ADMIN_ONLY',
                'message' => 'Acceso denegado. Esta operación está reservada exclusivamente a usuarios con rol Administrador.',
                'data' => null,
            ], 403);
        } else {
            $_SESSION['error'] = 'Acceso denegado. Esta sección está reservada exclusivamente al Administrador.';
            $this->redirect('/Home');
        }
    }

    public static function esRutaExclusivaAdminSprint2(string $controller, string $method): bool {
        $controller = strtolower($controller);
        $method = strtolower($method);

        if ($controller === 'inventario' && $method === 'ajustes') {
            return true;
        }

        if ($controller === 'reporte' && in_array($method, ['kardex', 'variaciones'], true)) {
            return true;
        }

        return false;
    }

    public static function esSlugExclusivoAdminSprint2(string $slug): bool {
        return in_array($slug, [
            'inventario.ajustes',
            'reportes.kardex',
            'reportes.variaciones',
            'reportes.exportar',
        ], true);
    }

    public static function tienePermiso(int $rol, string $slug): bool {
        if ($rol <= 0) {
            return false;
        }

        // Los permisos exclusivos del Sprint 2 nunca se conceden a un rol distinto de Administrador (1)
        if (self::esSlugExclusivoAdminSprint2($slug) && $rol !== 1) {
            return false;
        }

        $permisoModel = new PermisoModel();
        $permiso = $permisoModel->where(['slug' => $slug, 'estado = 1'])->first();

        if (!$permiso) {
            return $rol === 1;
        }

        $count = $permisoModel->countPermisoByRol($rol, $permiso['id_permiso']);
        if ($count > 0) {
            return true;
        }

        return $rol === 1;
    }

    public static function getPermisosRol(int $rol): array {
        if ($rol == 1) {
            $permisoModel = new PermisoModel();
            return $permisoModel->where(['estado = 1'])->get();
        }

        $permisoModel = new PermisoModel();
        return $permisoModel->obtenerPermisosPorRol($rol);
    }

    public static function tieneAlgunPermiso(int $rol, array $slugs): bool {
        foreach ($slugs as $slug) {
            if (self::tienePermiso($rol, $slug)) {
                return true;
            }
        }
        return false;
    }

    public static function mapControllerMethodToSlug(string $controller, string $method): ?string {
        $controller = strtolower($controller);
        $method = strtolower($method);

        $map = [
            'home' => [
                'index' => 'dashboard.ver',
            ],
            'producto' => [
                'index'      => 'productos.listar',
                'crear'      => 'productos.crear',
                'guardar'    => 'productos.crear',
                'editar'     => 'productos.editar',
                'actualizar' => 'productos.editar',
                'eliminar'   => 'productos.eliminar',
                'detalle'    => 'productos.listar',
            ],
            'inventario' => [
                'ajustes' => 'inventario.ajustes',
            ],
            'cliente' => [
                'index'      => 'clientes.listar',
                'crear'      => 'clientes.crear',
                'guardar'    => 'clientes.crear',
                'editar'     => 'clientes.editar',
                'actualizar' => 'clientes.editar',
                'eliminar'   => 'clientes.eliminar',
            ],
            'proveedor' => [
                'index'      => 'proveedores.listar',
                'crear'      => 'proveedores.crear',
                'guardar'    => 'proveedores.crear',
                'editar'     => 'proveedores.editar',
                'actualizar' => 'proveedores.editar',
                'eliminar'   => 'proveedores.eliminar',
            ],
            'usuario' => [
                'index'      => 'usuarios.listar',
                'crear'      => 'usuarios.crear',
                'guardar'    => 'usuarios.crear',
                'editar'     => 'usuarios.editar',
                'actualizar' => 'usuarios.editar',
                'eliminar'   => 'usuarios.eliminar',
            ],
            'venta' => [
                'index'   => 'ventas.acceder',
                'guardar' => 'ventas.procesar',
            ],
            'compra' => [
                'index'     => 'compras.listar',
                'registrar' => 'compras.crear',
                'detalle'   => 'compras.listar',
                'corregir'  => 'compras.editar',
            ],
            'reporte' => [
                'index'                  => 'reportes.ver',
                'ventas'                 => 'reportes.ver',
                'productosmasvendidos'   => 'reportes.ver',
                'productosmenosvendidos' => 'reportes.ver',
                'inventario'             => 'reportes.ver',
                'kardex'                 => 'reportes.kardex',
                'variaciones'            => 'reportes.variaciones',
                'clientes'               => 'reportes.ver',
                'vendedores'             => 'reportes.ver',
                'categorias'             => 'reportes.ver',
                'comprobantes'           => 'reportes.ver',
                'metodospago'            => 'reportes.ver',
                'resumen'                => 'reportes.ver',
            ],
        ];

        $controllerMap = $map[$controller] ?? null;
        if (!$controllerMap) {
            return null;
        }

        return $controllerMap[$method] ?? ($controllerMap['index'] ?? null);
    }
}
