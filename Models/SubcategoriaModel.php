<?php

namespace Models;

use Libraries\Core\Model;
use PDO;

class SubcategoriaModel extends Model {

    public function __construct() {
        parent::__construct();
        $this->table = 'subcategoria';
        $this->primaryKey = 'id_subcategoria';
    }

    public function obtenerPorCategoria(int $idCategoria, bool $soloActivas = true): array {
        $sql = 'SELECT id_subcategoria, id_categoria, nombre, descripcion, estado
                  FROM subcategoria
                 WHERE id_categoria = :id_categoria';
        if ($soloActivas) {
            $sql .= ' AND estado = 1';
        }
        $sql .= ' ORDER BY (nombre = "General") DESC, nombre ASC';

        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([':id_categoria' => $idCategoria]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerTodasAgrupadasPorCategoria(bool $soloActivas = true): array {
        $sql = 'SELECT id_subcategoria, id_categoria, nombre, descripcion, estado
                  FROM subcategoria';
        if ($soloActivas) {
            $sql .= ' WHERE estado = 1';
        }
        $sql .= ' ORDER BY id_categoria ASC, (nombre = "General") DESC, nombre ASC';

        $stmt = $this->conect()->query($sql);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $agrupadas = [];
        foreach ($filas as $fila) {
            $idCat = (int)$fila['id_categoria'];
            $agrupadas[$idCat][] = [
                'id_subcategoria' => (int)$fila['id_subcategoria'],
                'id_categoria' => $idCat,
                'nombre' => $fila['nombre'],
                'descripcion' => $fila['descripcion'],
                'estado' => (int)$fila['estado'],
            ];
        }
        return $agrupadas;
    }

    public function perteneceACategoria(int $idSubcategoria, int $idCategoria): bool {
        $sql = 'SELECT COUNT(*) FROM subcategoria WHERE id_subcategoria = :id_sub AND id_categoria = :id_cat';
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([
            ':id_sub' => $idSubcategoria,
            ':id_cat' => $idCategoria,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function obtenerSubcategoriaGeneral(int $idCategoria): ?int {
        $sql = 'SELECT id_subcategoria
                  FROM subcategoria
                 WHERE id_categoria = :id_cat
                 ORDER BY (nombre = "General") DESC, id_subcategoria ASC
                 LIMIT 1';
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute([':id_cat' => $idCategoria]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }
}
