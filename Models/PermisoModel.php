<?php

namespace Models;

use Libraries\Core\Model;

class PermisoModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'permiso';
        $this->primaryKey = 'id_permiso';
    }

    public function obtenerPermisosPorRol(int $idRol): array {
        return $this->select(['permiso.*'])
            ->join('rol_permiso', 'permiso.id_permiso = rol_permiso.id_permiso', 'INNER')
            ->where(['rol_permiso.id_rol' => $idRol, 'permiso.estado = 1'])
            ->orderBy('permiso.grupo', 'ASC')
            ->get();
    }

    public function countPermisoByRol(int $idRol, int $idPermiso): int {
        $sql = "SELECT COUNT(*) FROM rol_permiso WHERE id_rol = :rol AND id_permiso = :permiso";
        $stmt = $this->conect()->prepare($sql);
        $stmt->bindParam(':rol', $idRol, \PDO::PARAM_INT);
        $stmt->bindParam(':permiso', $idPermiso, \PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function obtenerPermisosAgrupados(int $idRol): array {
        $permisos = $this->obtenerPermisosPorRol($idRol);
        $agrupados = [];
        foreach ($permisos as $p) {
            $agrupados[$p['slug']] = $p;
        }
        return $agrupados;
    }

    public function asignarPermisos(int $idRol, array $permisos): bool {
        $pdo = $this->conect();
        $pdo->beginTransaction();
        
        try {
            $delete = $pdo->prepare("DELETE FROM rol_permiso WHERE id_rol = :rol");
            $delete->execute([':rol' => $idRol]);
            
            $insert = $pdo->prepare("INSERT INTO rol_permiso (id_rol, id_permiso) VALUES (:rol, :permiso)");
            foreach ($permisos as $idPermiso) {
                $insert->execute([':rol' => $idRol, ':permiso' => $idPermiso]);
            }
            
            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }
}
