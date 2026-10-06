<?php

namespace Libraries\Core;

class Views {
    public function render($controller, $view, $data = []) {
        $className = get_class($controller);
        $controllerName = str_replace('Controllers\\', '', $className);
        $controllerName = str_replace('Controller', '', $controllerName);
        $viewPath = "Views/{$controllerName}/{$view}.php";
        if(file_exists($viewPath)) {
            extract($data);
            require $viewPath;
        }
    }
}