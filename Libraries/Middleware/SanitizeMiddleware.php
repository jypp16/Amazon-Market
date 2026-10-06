<?php

namespace Libraries\Middleware;

class SanitizeMiddleware extends Middleware {

    public function handle(): void {
        // Sanear variables GET
        if (!empty($_GET)) {
            $_GET = $this->sanitizeArray($_GET);
        }

        // Sanear variables POST (excepto passwords)
        if (!empty($_POST)) {
            foreach ($_POST as $key => $value) {
                if (is_string($value) && $key !== 'password' && $key !== 'password_hash') {
                    $_POST[$key] = $this->sanitizeString($value);
                }
            }
        }

        // Sanear SERVER variables commonly used
        $serverKeys = ['HTTP_REFERER', 'HTTP_USER_AGENT'];
        foreach ($serverKeys as $key) {
            if (isset($_SERVER[$key])) {
                $_SERVER[$key] = $this->sanitizeString($_SERVER[$key]);
            }
        }
    }

    private function sanitizeArray(array $data): array {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $cleanKey = $this->sanitizeString($key);
            if (is_array($value)) {
                $sanitized[$cleanKey] = $this->sanitizeArray($value);
            } else {
                $sanitized[$cleanKey] = $this->sanitizeString($value);
            }
        }
        return $sanitized;
    }

    private function sanitizeString(string $value): string {
        $value = trim($value);
        $value = stripslashes($value);
        return $value;
    }

    public static function clean($data) {
        if (is_array($data)) {
            return array_map([self::class, 'clean'], $data);
        }
        if (!is_string($data)) {
            return $data;
        }
        return htmlspecialchars(trim(stripslashes($data)), ENT_QUOTES, 'UTF-8');
    }
}
