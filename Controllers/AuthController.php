<?php

namespace Controllers;

use Libraries\Core\Controller;
use Services\AuthService;

class AuthController extends Controller {

    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new AuthService();
    }

    public function index() {
        \Libraries\Middleware\AuthMiddleware::guest();
        $this->views->render($this, "index");
    }

    public function login() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \Libraries\Middleware\CSRFMiddleware::verifyOrFail();

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $rateKey = 'login_attempts_' . md5($ip);
            $attempts = $_SESSION[$rateKey] ?? 0;
            $lastAttempt = $_SESSION[$rateKey . '_time'] ?? 0;

            if ($attempts >= 5 && (time() - $lastAttempt) < 300) {
                $_SESSION['error'] = 'Demasiados intentos. Espere 5 minutos.';
                header("Location: " . BASE_URL . "/Auth");
                exit;
            }

            $resultado = $this->service->login(
                $_POST['username'] ?? '',
                $_POST['password'] ?? ''
            );

            if ($resultado['status']) {
                unset($_SESSION[$rateKey], $_SESSION[$rateKey . '_time']);
                header("Location: " . BASE_URL . "/Home");
            } else {
                $_SESSION[$rateKey] = $attempts + 1;
                $_SESSION[$rateKey . '_time'] = time();
                $_SESSION['error'] = $resultado['message'];
                header("Location: " . BASE_URL . "/Auth");
            }
            exit;
        } else {
            header("Location: " . BASE_URL . "/Auth");
            exit;
        }
    }

    public function logout() {
        $this->service->logout();
        header("Location: " . BASE_URL . "/Auth");
        exit;
    }
}
