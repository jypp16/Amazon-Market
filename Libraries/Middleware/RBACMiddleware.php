<?php

namespace Libraries\Middleware;

use Models\PermisoModel;

class RBACMiddleware extends Middleware {

    private static $permisosCache = null;

    public function handle(): void {
        if (empty($_SESSION['login'])) {
            return;
        }

        $rol = $_SESSION['rol'] ?? 0;

        // Admin tiene acceso total
        if ($rol == 1) {
            return;
        }

        $url = $_GET['url'] ?? 'Home';
        $parts = explode('/', $url);
        $controller = strtolower($parts[0] ?? 'Home');
        $method = strtolower($parts[1] ?? 'index');

        $slug = self::mapControllerMethodToSlug($controller, $method);
        if ($slug && !self::tienePermiso($rol, $slug)) {
            if ($this->isApiRequest()) {
                $this->jsonResponse(['status' => false, 'message' => 'No tienes permisos para acceder a este recurso.'], 403);
            } else {
                $_SESSION['error'] = 'No tienes permisos para acceder a esta sección.';
                $this->redirect("/Home");
            }
        }
    }

    public static function tienePermiso(int $rol, string $slug): bool {
        if ($rol == 1) return true;

        $permisoModel = new PermisoModel();
        $permiso = $permisoModel->where(['slug' => $slug, 'estado = 1'])->first();

        if (!$permiso) return false;

        $count = $permisoModel->countPermisoByRol($rol, $permiso['id_permiso']);
        return $count > 0;
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

    private static function mapControllerMethodToSlug(string $controller, string $method): ?string {
        $map = [
            'home' => [
                'index' => 'dashboard.ver',
            ],
            'producto' => [
                'index'    => 'productos.listar',
                'crear'    => 'productos.crear',
                'guardar'  => 'productos.crear',
                'editar'   => 'productos.editar',
                'actualizar' => 'productos.editar',
                'eliminar' => 'productos.eliminar',
                'detalle'  => 'productos.listar',
            ],
            'cliente' => [
                'index'    => 'clientes.listar',
                'crear'    => 'clientes.crear',
                'guardar'  => 'clientes.crear',
                'editar'   => 'clientes.editar',
                'actualizar' => 'clientes.editar',
                'eliminar' => 'clientes.eliminar',
            ],
            'usuario' => [
                'index'    => 'usuarios.listar',
                'crear'    => 'usuarios.crear',
                'guardar'  => 'usuarios.crear',
                'editar'   => 'usuarios.editar',
                'actualizar' => 'usuarios.editar',
                'eliminar' => 'usuarios.eliminar',
            ],
            'venta' => [
                'index'  => 'ventas.acceder',
                'guardar' => 'ventas.procesar',
            ],
            'reporte' => [
                'index' => 'reportes.ver',
                'ventas' => 'reportes.ver',
                'productosMasVendidos' => 'reportes.ver',
                'productosMenosVendidos' => 'reportes.ver',
                'inventario' => 'reportes.ver',
                'clientes' => 'reportes.ver',
                'vendedores' => 'reportes.ver',
                'categorias' => 'reportes.ver',
                'comprobantes' => 'reportes.ver',
                'metodosPago' => 'reportes.ver',
                'resumen' => 'reportes.ver',
            ],
        ];

        $controllerMap = $map[$controller] ?? null;
        if (!$controllerMap) return null;

        return $controllerMap[$method] ?? ($controllerMap['index'] ?? null);
    }
}
