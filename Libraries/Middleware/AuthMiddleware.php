<?php

namespace Libraries\Middleware;

class AuthMiddleware extends Middleware {

    public function handle(): void {
        if (empty($_SESSION['login'])) {
            if ($this->isApiRequest()) {
                $this->jsonResponse(['status' => false, 'message' => 'No autenticado.'], 401);
            } else {
                $this->redirect("/Auth");
            }
        }
    }

    public static function check() {
        if (empty($_SESSION['login'])) {
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode(['status' => false, 'message' => 'No autenticado.']);
                exit;
            }
            header("Location: " . BASE_URL . "/Auth");
            exit;
        }
    }

    public static function guest() {
        if (!empty($_SESSION['login'])) {
            header("Location: " . BASE_URL . "/Home");
            exit;
        }
    }
}
