<?php

spl_autoload_register(function ($class) {
    $file = str_replace(['\\', '_'], DIRECTORY_SEPARATOR, $class) . '.php';
    
    $baseDir = __DIR__ . '/../../';
    
    $paths = [
        $baseDir,
        $baseDir . 'Libraries/',
        $baseDir . 'Libraries/Core/',
        $baseDir . 'Libraries/Middleware/',
        $baseDir . 'Libraries/Mailer/',
        $baseDir . 'Libraries/Excel/',
        $baseDir . 'Controllers/',
        $baseDir . 'Models/',
        $baseDir . 'Services/',
        $baseDir . 'Helpers/',
    ];
    
    foreach ($paths as $path) {
        $fullPath = $path . $file;
        if (file_exists($fullPath)) {
            require_once $fullPath;
            return;
        }
    }
});
