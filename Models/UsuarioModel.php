<?php

namespace Models;

use Libraries\Core\Model;

class UsuarioModel extends Model {
    public function __construct() {
        parent::__construct();
        $this->table = 'usuario';
        $this->primaryKey = 'id_usuario';
    }

    public function obtenerUsuarioPorUsername(string $username) {
        return $this->where(['username' => $username, 'estado = 1'])->first();
    }

    public function obtenerUsuarios() {
        return $this->select(['usuario.id_usuario', 'usuario.username', 'usuario.nombre', 'usuario.dni', 'usuario.telefono', 'usuario.direccion', 'usuario.email', 'rol.nombre as rol'])
            ->join('rol', 'usuario.id_rol = rol.id_rol', 'INNER')
            ->where(['usuario.estado = 1'])
            ->orderBy('usuario.id_usuario', 'ASC')
            ->get();
    }

    public function obtenerUsuarioPorId(int $id) {
        return $this->select(['usuario.id_usuario', 'usuario.id_rol', 'usuario.username', 'usuario.nombre', 'usuario.dni', 'usuario.telefono', 'usuario.direccion', 'usuario.email', 'usuario.estado'])
            ->where([$this->primaryKey => $id, 'estado = 1'])
            ->first();
    }
}
