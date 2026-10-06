<?php

if ($isApiRoute) {
    $controllerClass = "Controllers\\API\\{$controller}";
} else {
    $controllerClass = "Controllers\\{$controller}";
}

if (!class_exists($controllerClass)) {
    $controllerClass = "Controllers\\{$controller}Controller";
}

if (class_exists($controllerClass)) {
    $controllerObject = new $controllerClass();

    // Para rutas API: re-leer el verbo HTTP real (respeta _method override en multipart/form-data)
    if ($isApiRoute) {
        $method = strtolower($controllerObject->requestMethod);
    }

    // Whitelist: sólo enrutar métodos públicos declarados explícitamente por el
    // controlador (no heredados de las clases base Controller/ApiController).
    // Esto evita invocar helpers internos expuestos como públicos.
    $esMetodoPropio = false;
    try {
        $ref = new \ReflectionClass($controllerObject);
        if ($ref->hasMethod($method)) {
            $refMethod = $ref->getMethod($method);
            $esMetodoPropio = (
                $refMethod->isPublic()
                && !$refMethod->isConstructor()
            );
            // Para rutas web: sólo métodos declarados por el propio controlador
            // (evita invocar helpers heredados de Controller base).
            // Para rutas API: también se permiten los verbos HTTP heredados de
            // ApiController (get/post/put/delete), que responden 405 por defecto.
            $declaringClass = $refMethod->getDeclaringClass()->getName();
            if ($isApiRoute ?? false) {
                $esMetodoPropio = $esMetodoPropio && (
                    $declaringClass === $ref->getName()
                    || is_a($declaringClass, \Libraries\Core\ApiController::class, true)
                );
            } else {
                $esMetodoPropio = $esMetodoPropio && $declaringClass === $ref->getName();
            }
        }
    } catch (\ReflectionException $e) {
        $esMetodoPropio = false;
    }

    if ($esMetodoPropio) {
        $controllerObject->$method($params);
    } else {
        if ($isApiRoute ?? false) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);
            echo json_encode(['status' => false, 'message' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $errorObject = new Controllers\ErrorController();
        $errorObject->index();
    }
} else {
    if ($isApiRoute ?? false) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'Endpoint no encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $errorObject = new Controllers\ErrorController();
    $errorObject->index();
}
