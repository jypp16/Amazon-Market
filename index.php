<?php

// Reportar todos los errores pero no mostrarlos al usuario final (information disclosure)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once("Config/Config.php");

require_once("Libraries/Core/Autoload.php");
require_once("Helpers/helpers.php");

// =====================================================
// MIDDLEWARE PIPELINE
// =====================================================

// 1. Sanitización de entradas (siempre primero)
$sanitize = new Libraries\Middleware\SanitizeMiddleware();
$sanitize->handle();

// 2. Generación de token CSRF
$csrf = new Libraries\Middleware\CSRFMiddleware();
$csrf->handle();

// 3. Parseo de URL
$url = $_GET['url'] ?? 'Auth';
$arrUrl = explode("/", $url);

// =====================================================
// DETECCIÓN DE RUTA API
// =====================================================
$isApiRoute = (strtolower($arrUrl[0]) === 'api');

// 4. Excluir rutas públicas del middleware de autenticación
$rutasPublicas = ['Auth'];
$controller = ucwords($arrUrl[0]);
$esRutaPublica = in_array($controller, $rutasPublicas);

if ($isApiRoute) {
    // Ruta API: /api/{recurso} o /api/{recurso}/{id}
    array_shift($arrUrl);

    $resource = strtolower($arrUrl[0] ?? '');
    // El id (si existe) viaja como único parámetro del método index()
    $params = $arrUrl[2] ?? $arrUrl[1] ?? '';

    // Mapeo de recursos a controladores API
    $apiMap = [
        'productos'     => 'ProductoApiController',
        'clientes'      => 'ClienteApiController',
        'usuarios'      => 'UsuarioApiController',
        'ventas'        => 'VentaApiController',
        'categorias'    => 'CategoriaApiController',
        'unidades'      => 'UnidadApiController',
        'comprobantes'  => 'TipoComprobanteApiController',
        'pagos'         => 'MetodoPagoApiController',
        'tipos-documento' => 'TipoDocumentoApiController',
        'roles'         => 'RolApiController',
        'reportes'      => 'ReporteApiController',
        'imagenes'      => 'ImagenApiController',
    ];

    $controller = $apiMap[$resource] ?? ucfirst($resource) . 'ApiController';
    // Dispatch RESTful puro: el verbo HTTP determina el método del controlador
    // (get/post/put/delete).ApiController base provee 405 por defecto.
    $method = strtolower($_SERVER['REQUEST_METHOD']);

} else {
    // Ruta web tradicional: /{controller}/{method}/{params}
    $method = $arrUrl[1] ?? 'index';
    $params = "";

    if (!empty($arrUrl[2])) {
        if ($arrUrl[2] != "") {
            for ($i = 2; $i < count($arrUrl); $i++) {
                $params .= $arrUrl[$i] . ",";
            }
            $params = trim($params, ",");
        }
    }
}

// =====================================================
// MIDDLEWARE: Autenticación y RBAC (solo rutas web)
// =====================================================
if (!$esRutaPublica && !$isApiRoute) {
    // Autenticación
    $auth = new Libraries\Middleware\AuthMiddleware();
    $auth->handle();

    // RBAC - Control de acceso por roles
    $rbac = new Libraries\Middleware\RBACMiddleware();
    $rbac->handle();

    // Validación CSRF para peticiones POST (excepto login)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Libraries\Middleware\CSRFMiddleware::verifyOrFail();
    }
}

// Validación CSRF para peticiones API que modifican estado
// (la autenticación de la API la maneja ApiController::enforceAuthentication)
if ($isApiRoute && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    Libraries\Middleware\CSRFMiddleware::verifyOrFail();
}

// =====================================================
// DISPATCH
// =====================================================
require_once("Libraries/Core/Load.php");
