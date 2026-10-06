<?php
namespace Libraries\Core;

use PDO;
use PDOException;

class Conexion {

    private static $instance = null;
    protected $conect;

    private function __construct() {
        try {
            $this->conect = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
            $this->conect->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conect->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // No filtrar detalles de la BD al usuario final
            error_log('Error de conexión BD: ' . $e->getMessage());
            http_response_code(500);
            die('No se pudo establecer la conexión con el servidor. Intente más tarde.');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function conect() {
        return $this->conect;
    }
}