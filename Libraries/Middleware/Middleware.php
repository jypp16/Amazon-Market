<?php

namespace Libraries\Middleware;

abstract class Middleware {

    abstract public function handle(): void;

    protected function redirect($url) {
        header("Location: " . BASE_URL . $url);
        exit;
    }

    protected function isAjax() {
        return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    }

    protected function isApiRequest() {
        return isset($_GET['json']) || $this->isAjax() || 
               (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    }

    protected function jsonResponse($data, $statusCode = 200) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }

    protected function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeInput'], $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}
