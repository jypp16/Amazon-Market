<?php

namespace Libraries\Core;

use PDO;

class Validation {

    protected $errors = [];
    protected $data = [];

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function required($fields) {
        foreach ($fields as $field) {
            $value = $this->data[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && empty($value))) {
                $this->errors[$field] = "El campo {$field} es obligatorio.";
            }
        }
        return $this;
    }

    public function minLength($field, $min) {
        if (isset($this->data[$field]) && is_string($this->data[$field]) && strlen(trim($this->data[$field])) < $min) {
            $this->errors[$field] = "El campo {$field} debe tener al menos {$min} caracteres.";
        }
        return $this;
    }

    public function maxLength($field, $max) {
        if (isset($this->data[$field]) && is_string($this->data[$field]) && strlen(trim($this->data[$field])) > $max) {
            $this->errors[$field] = "El campo {$field} no debe exceder los {$max} caracteres.";
        }
        return $this;
    }

    public function email($field) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!filter_var(trim($this->data[$field]), FILTER_VALIDATE_EMAIL)) {
                $this->errors[$field] = "El campo {$field} debe ser un correo electrónico válido.";
            }
        }
        return $this;
    }

    public function numeric($field) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!is_numeric($this->data[$field])) {
                $this->errors[$field] = "El campo {$field} debe ser un valor numérico.";
            }
        }
        return $this;
    }

    public function positiveNumber($field) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!is_numeric($this->data[$field]) || floatval($this->data[$field]) <= 0) {
                $this->errors[$field] = "El campo {$field} debe ser un número mayor a cero.";
            }
        }
        return $this;
    }

    public function nonNegativeNumber($field) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!is_numeric($this->data[$field]) || floatval($this->data[$field]) < 0) {
                $this->errors[$field] = "El campo {$field} no puede ser negativo.";
            }
        }
        return $this;
    }

    public function integer($field) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!filter_var($this->data[$field], FILTER_VALIDATE_INT)) {
                $this->errors[$field] = "El campo {$field} debe ser un número entero.";
            }
        }
        return $this;
    }

    public function phone($field) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            $cleaned = preg_replace('/[\s\-\(\)\.]/', '', $this->data[$field]);
            if (!preg_match('/^\+?\d{7,12}$/', $cleaned)) {
                $this->errors[$field] = "El campo {$field} debe ser un número de teléfono válido (7-12 dígitos).";
            }
        }
        return $this;
    }

    public function regex($field, $pattern, $message) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!preg_match($pattern, trim($this->data[$field]))) {
                $this->errors[$field] = $message;
            }
        }
        return $this;
    }

    public function unique($field, $model, $excludeId = null) {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            $value = trim($this->data[$field]);
            $table = $model->getTable();
            $pk = $model->getPrimaryKey();
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$field}` = :value";
            if ($excludeId !== null) {
                $sql .= " AND `{$pk}` != :excludeId";
            }
            $stmt = $model->conect()->prepare($sql);
            $stmt->bindValue(':value', $value);
            if ($excludeId !== null) {
                $stmt->bindValue(':excludeId', $excludeId, PDO::PARAM_INT);
            }
            $stmt->execute();
            if ($stmt->fetchColumn() > 0) {
                $this->errors[$field] = "El valor de {$field} ya está registrado.";
            }
        }
        return $this;
    }

    public function exists($field, $table, $column = 'id') {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value";
            $pdo = Conexion::getInstance()->conect();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':value', $this->data[$field]);
            $stmt->execute();
            if ($stmt->fetchColumn() == 0) {
                $this->errors[$field] = "El valor seleccionado para {$field} no existe.";
            }
        }
        return $this;
    }

    public function fails() {
        return !empty($this->errors);
    }

    public function passes() {
        return empty($this->errors);
    }

    public function errors() {
        return $this->errors;
    }

    public function firstError() {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    public function addError(string $field, string $message): self {
        $this->errors[$field] = $message;
        return $this;
    }
}
